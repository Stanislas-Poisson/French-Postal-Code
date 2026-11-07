#!/usr/bin/env python3
"""Compares the CSV exports of two versions of the dataset.

Usage: compare_exports.py OLD_DIR NEW_DIR [SUMMARY_FILE]

It prints a summary in Markdown (rows added, rows removed, rows modified and the columns that changed, per file),
writes it to SUMMARY_FILE when given, and sets in GITHUB_OUTPUT:

- `changed` (true or false): every column counts, the points of the BAN are part of the dataset;
- `kind`: `none`, `patch`, `minor` or `major`, from what changed (see docs/release.md):
  - `patch`: only the points of the BAN moved (`address_count`, `latitude`, `longitude` of cities.csv, and
    `coordinate_source` going from `nominatim` to `ban` when a city gets its first point of the BAN);
  - `minor`: anything else in the rows (a row added or removed, another column modified);
  - `major`: the structure changed (the columns of a file, a file added or removed);
- `review` (true or false) and `review_reason`: a major change, or more than REMOVAL_THRESHOLD percent (1 by default) of
  the rows of a file removed, must be checked by the maintainer before it is published.

Rows are matched by their `id` column. A file without `id` is compared as a set of rows.
"""
import csv
import os
import sys
from collections import Counter
from pathlib import Path


# The columns that move with the points of the BAN, without any file of INSEE or La Poste changing.
BAN_COLUMNS = {'cities.csv': {'address_count', 'latitude', 'longitude'}}
# A city that had no point of the BAN and gets one: its source of coordinates goes from Nominatim to the BAN.
BAN_SWITCHES = {('cities.csv', 'coordinate_source', 'nominatim', 'ban')}
DEFAULT_REMOVAL_THRESHOLD = 1.0


def read(path):
    with open(path, newline='', encoding='utf-8') as handle:
        reader = csv.reader(handle)
        header = next(reader, [])
        return header, list(reader)


def moves_with_ban(name, column, old_value, new_value):
    """True when a changed cell is a move of the points of the BAN (a patch change)."""
    return column in BAN_COLUMNS.get(name, set()) or (name, column, old_value, new_value) in BAN_SWITCHES


def compare(name, old_path, new_path):
    """Returns (added, removed, modified, columns, blocking, header_changed, old_rows) for one file.

    `columns` counts every modified column; `blocking` counts only those that are not a move of the BAN.
    """
    old_header, old_rows = read(old_path)
    new_header, new_rows = read(new_path)
    header_changed = old_header != new_header

    if 'id' in old_header and 'id' in new_header and not header_changed:
        position = old_header.index('id')
        old = {row[position]: row for row in old_rows}
        new = {row[position]: row for row in new_rows}
        added = len(new.keys() - old.keys())
        removed = len(old.keys() - new.keys())
        columns = Counter()
        blocking = Counter()
        modified = 0
        for key in old.keys() & new.keys():
            changes = [(column, a, b) for column, a, b in zip(old_header, old[key], new[key]) if a != b]
            if changes:
                modified += 1
                columns.update(column for column, _, _ in changes)
                blocking.update(column for column, a, b in changes if not moves_with_ban(name, column, a, b))
        return added, removed, modified, columns, blocking, header_changed, len(old_rows)

    old_set = Counter(map(tuple, old_rows))
    new_set = Counter(map(tuple, new_rows))
    return sum((new_set - old_set).values()), sum((old_set - new_set).values()), 0, Counter(), Counter(), header_changed, len(old_rows)


def classify(added, removed, modified, blocking, header_changed):
    """The kind of change of one file: none, patch, minor or major."""
    if header_changed:
        return 'major'
    if added or removed:
        return 'minor'
    if modified:
        return 'minor' if blocking else 'patch'
    return 'none'


def main():
    old_dir, new_dir = Path(sys.argv[1]), Path(sys.argv[2])
    names = sorted({p.name for p in old_dir.glob('*.csv')} | {p.name for p in new_dir.glob('*.csv')})
    threshold = float(os.environ.get('REMOVAL_THRESHOLD') or DEFAULT_REMOVAL_THRESHOLD)
    order = ['none', 'patch', 'minor', 'major']

    lines = ['| File | Added | Removed | Modified | Columns modified |', '| ---- | ----: | ------: | -------: | ---------------- |']
    changed = False
    kind = 'none'
    reasons = []
    for name in names:
        old, new = old_dir / name, new_dir / name
        if not old.exists() or not new.exists():
            lines.append(f"| {name} | {'file added' if not old.exists() else ''} | {'file removed' if not new.exists() else ''} | | |")
            changed = True
            kind = 'major'
            reasons.append(f"{name}: file {'added' if not old.exists() else 'removed'}")
            continue
        added, removed, modified, columns, blocking, header_changed, old_count = compare(name, old, new)
        if added or removed or modified or header_changed:
            changed = True
        file_kind = classify(added, removed, modified, blocking, header_changed)
        kind = max(kind, file_kind, key=order.index)
        if header_changed:
            reasons.append(f'{name}: the columns changed')
        if not header_changed and old_count and removed * 100 / old_count > threshold:
            reasons.append(f'{name}: {removed} of {old_count} rows removed (more than {threshold:g} percent)')
        detail = ', '.join(f'{column} ({count})' for column, count in sorted(columns.items()))
        if header_changed:
            detail = 'the columns of the file changed'
        lines.append(f'| {name} | {added} | {removed} | {modified} | {detail} |')

    review = kind == 'major' or bool(reasons)
    if kind == 'major' and not reasons:
        reasons.append('the structure of the files changed')

    summary = '\n'.join(lines)
    summary += f"\n\nKind of change: **{kind}**."
    if reasons:
        summary += ' To review: ' + '; '.join(reasons) + '.'
    print(summary)
    if len(sys.argv) > 3:
        Path(sys.argv[3]).write_text(summary + '\n', encoding='utf-8')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as out:
            out.write(f"changed={'true' if changed else 'false'}\n")
            out.write(f'kind={kind}\n')
            out.write(f"review={'true' if review else 'false'}\n")
            out.write(f"review_reason={'; '.join(reasons)}\n")


if __name__ == '__main__':
    main()

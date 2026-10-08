#!/usr/bin/env python3
"""Compares the CSV exports of two versions of the dataset.

Usage: compare_exports.py OLD_DIR NEW_DIR [SUMMARY_FILE]

It prints a summary in Markdown (rows added, rows removed, rows modified and the columns that changed, per file),
writes it to SUMMARY_FILE when given, and sets `changed` (true or false) in GITHUB_OUTPUT. Every column counts:
the points of the BAN are part of the dataset.

Rows are matched by their `id` column. A file without `id` is compared as a set of rows.
"""
import csv
import os
import sys
from collections import Counter
from pathlib import Path


def read(path):
    with open(path, newline='', encoding='utf-8') as handle:
        reader = csv.reader(handle)
        header = next(reader, [])
        return header, list(reader)


def compare(old_path, new_path):
    """Returns (added, removed, modified, columns, header_changed) for one file."""
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
        modified = 0
        for key in old.keys() & new.keys():
            different = [name for name, a, b in zip(old_header, old[key], new[key]) if a != b]
            if different:
                modified += 1
                columns.update(different)
        return added, removed, modified, columns, header_changed

    old_set = Counter(map(tuple, old_rows))
    new_set = Counter(map(tuple, new_rows))
    return sum((new_set - old_set).values()), sum((old_set - new_set).values()), 0, Counter(), header_changed


def main():
    old_dir, new_dir = Path(sys.argv[1]), Path(sys.argv[2])
    names = sorted({p.name for p in old_dir.glob('*.csv')} | {p.name for p in new_dir.glob('*.csv')})

    lines = ['| File | Added | Removed | Modified | Columns modified |', '| ---- | ----: | ------: | -------: | ---------------- |']
    changed = False
    for name in names:
        old, new = old_dir / name, new_dir / name
        if not old.exists() or not new.exists():
            lines.append(f"| {name} | {'file added' if not old.exists() else ''} | {'file removed' if not new.exists() else ''} | | |")
            changed = True
            continue
        added, removed, modified, columns, header_changed = compare(old, new)
        if added or removed or modified or header_changed:
            changed = True
        detail = ', '.join(f'{column} ({count})' for column, count in sorted(columns.items()))
        if header_changed:
            detail = 'the columns of the file changed'
        lines.append(f'| {name} | {added} | {removed} | {modified} | {detail} |')

    summary = '\n'.join(lines)
    print(summary)
    if len(sys.argv) > 3:
        Path(sys.argv[3]).write_text(summary + '\n', encoding='utf-8')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as out:
            out.write(f"changed={'true' if changed else 'false'}\n")


if __name__ == '__main__':
    main()

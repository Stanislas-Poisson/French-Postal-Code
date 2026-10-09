#!/usr/bin/env python3
"""Tells whether a source of the dataset is newer than the last generation.

The last generation is the time written in `statistics.json` (`built_at`), attached to the latest release. The sources are
compared to it with their own public dates, without downloading them:

- INSEE COG: a newer vintage ("Millésime 2027") or a resource of the latest vintage changed after the generation,
  read on the dataset of data.gouv.fr (INSEE sends no ETag nor Last-Modified header);
- La Poste: the `Last-Modified` header of the file of postal codes.

Environment:
    GH_REPO        owner/name of the repository (default: GITHUB_REPOSITORY)
    GH_TOKEN       token that can read the releases (github.token is enough)
    SINCE          a date (YYYY-MM-DD) to use instead of the date of the last generation, to try the check
    GITHUB_OUTPUT  file where `changed`, `reasons` and `last_generated` are written (set by GitHub Actions)

Prints a JSON object, and exits with 0 whether a source changed or not: only an error makes it fail.
"""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime

REPO = os.environ.get("GH_REPO") or os.environ.get("GITHUB_REPOSITORY", "Stanislas-Poisson/French-Postal-Code")
TOKEN = os.environ.get("GH_TOKEN", "")
COG_DATASET = "https://www.data.gouv.fr/api/1/datasets/58c984b088ee386cdb1261f3/"
LA_POSTE_FILE = "https://data.laposte.fr/data-fair/api/v1/datasets/laposte-hexasmal/raw"


def request(url: str, *, method: str = "GET", auth: bool = False):
    headers = {"User-Agent": "french-postal-code-watch", "Accept": "application/json"}
    if auth and TOKEN:
        headers["Authorization"] = f"Bearer {TOKEN}"
    return urllib.request.urlopen(urllib.request.Request(url, method=method, headers=headers), timeout=60)


def read_json(url: str, *, auth: bool = False):
    with request(url, auth=auth) as response:
        return json.loads(response.read())


def parse_date(value: str) -> datetime:
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)


def last_generation() -> tuple[datetime, str | None, str | None]:
    """The date, the COG vintage and the La Poste version of the latest release."""
    release = read_json(f"https://api.github.com/repos/{REPO}/releases/latest", auth=True)
    for asset in release.get("assets", []):
        if asset["name"] == "statistics.json":
            stats = read_json(asset["browser_download_url"])
            # `generated_at` is only a day (midnight): a source changed earlier the same day, and already in the build,
            # would look newer. Use the exact time of the build, or the publication of the release for older statistics.
            generated = parse_date(stats["built_at"]) if stats.get("built_at") else parse_date(release["published_at"])
            return generated, stats.get("cog_vintage"), stats.get("laposte_version")
    return parse_date(release["published_at"]), None, None


def cog_changes(generated: datetime, vintage: str | None) -> list[str]:
    resources = read_json(COG_DATASET).get("resources", [])
    years: dict[int, list[datetime]] = {}
    for resource in resources:
        match = re.match(r"^Millésime (\d{4})\s*:", resource.get("title", ""))
        if match and resource.get("last_modified"):
            years.setdefault(int(match.group(1)), []).append(parse_date(resource["last_modified"]))
    if not years:
        raise RuntimeError("No COG vintage found in the dataset of data.gouv.fr.")
    latest = max(years)
    if vintage and latest > int(vintage):
        return [f"INSEE COG: the vintage {latest} is published (the dataset has the vintage {vintage})"]
    changed = max(years[latest])
    if changed > generated:
        return [f"INSEE COG {latest}: a file changed on {changed:%Y-%m-%d}, after the generation of {generated:%Y-%m-%d}"]
    return []


def la_poste_changes(generated: datetime) -> list[str]:
    with request(LA_POSTE_FILE, method="HEAD") as response:
        header = response.headers.get("Last-Modified")
    if not header:
        raise RuntimeError("The file of La Poste has no Last-Modified header.")
    modified = parsedate_to_datetime(header)
    if modified > generated:
        return [f"La Poste: the file changed on {modified:%Y-%m-%d}, after the generation of {generated:%Y-%m-%d}"]
    return []


def main() -> int:
    generated, vintage, laposte = last_generation()
    if os.environ.get("SINCE"):
        generated = parse_date(os.environ["SINCE"])
    reasons = [*cog_changes(generated, vintage), *la_poste_changes(generated)]
    result = {
        "changed": bool(reasons),
        "reasons": reasons,
        "last_generated": generated.strftime("%Y-%m-%d"),
        "cog_vintage": vintage,
        "laposte_version": laposte,
    }
    print(json.dumps(result, indent=2, ensure_ascii=False))
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
            output.write(f"changed={'true' if reasons else 'false'}\n")
            output.write(f"last_generated={result['last_generated']}\n")
            output.write("reasons<<EOF\n" + "\n".join(f"- {reason}" for reason in reasons) + "\nEOF\n")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (urllib.error.URLError, RuntimeError, KeyError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        sys.exit(1)

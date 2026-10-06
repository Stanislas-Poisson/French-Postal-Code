#!/usr/bin/env python3
"""Publishes a release of the dataset on data.gouv.fr.

The files of a GitHub release are mapped to the resources of the dataset by `.github/data-gouv/resources.json`
(see docs/data-gouv.md):

- the archives (CSV, JSON and SQL), `statistics.json` and `SHA256SUMS` are links to the assets of the release:
  the link, the title, the checksum and the description of their resource are updated;
- `cities.csv`, `communes.csv` and `commune_successions.csv` are files hosted by data.gouv.fr: they are taken from
  the CSV archive and sent again, then their description is updated.

The script only prints what it would do, unless `--apply` is given.

Usage:
    publish_data_gouv.py TAG [--apply]

Environment:
    DATAGOUV_API_KEY  API key of the account that owns the dataset (required with --apply)
    GH_REPO           owner/name of the repository (default: GITHUB_REPOSITORY)
    GH_TOKEN          GitHub token that can read the releases
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
import re
import sys
import urllib.error
import urllib.request
import uuid
import zipfile
from pathlib import Path

REPO = os.environ.get("GH_REPO") or os.environ.get("GITHUB_REPOSITORY", "Stanislas-Poisson/French-Postal-Code")
API = "https://www.data.gouv.fr/api/1"
SPECS = Path(__file__).resolve().parent.parent / "data-gouv" / "resources.json"


def call(url: str, *, method: str = "GET", body: bytes | None = None, headers: dict | None = None, key: bool = False):
    request_headers = {"User-Agent": "french-postal-code-publish", **(headers or {})}
    if key:
        request_headers["X-API-KEY"] = os.environ["DATAGOUV_API_KEY"]
    request = urllib.request.Request(url, data=body, method=method, headers=request_headers)
    with urllib.request.urlopen(request, timeout=300) as response:
        return response.read()


def github(path: str):
    headers = {"Accept": "application/vnd.github+json"}
    if os.environ.get("GH_TOKEN"):
        headers["Authorization"] = f"Bearer {os.environ['GH_TOKEN']}"
    return json.loads(call(f"https://api.github.com{path}", headers=headers))


def thousands(value: int) -> str:
    return f"{value:,}".replace(",", " ")


def rows(content: bytes) -> int:
    return sum(1 for _ in csv.reader(io.StringIO(content.decode("utf-8")))) - 1


def multipart(name: str, content: bytes) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    head = f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: text/csv\r\n\r\n'
    return head.encode() + content + f"\r\n--{boundary}--\r\n".encode(), f"multipart/form-data; boundary={boundary}"


def collect(tag: str) -> dict:
    """Downloads what the release offers and computes the numbers of the descriptions."""
    release = github(f"/repos/{REPO}/releases/tags/{tag}")
    assets = {asset["name"]: asset["browser_download_url"] for asset in release["assets"]}
    needed = [f"french-postal-code-{tag}-csv.zip", f"french-postal-code-{tag}-json.zip", f"french-postal-code-{tag}-sql.zip", "statistics.json", "SHA256SUMS"]
    missing = [name for name in needed if name not in assets]
    if missing:
        raise SystemExit(f"The release {tag} has no {', '.join(missing)}.")

    files = {name: call(assets[name]) for name in needed}
    sums = dict(line.split()[::-1] for line in files["SHA256SUMS"].decode().splitlines() if line.strip())
    digests = {name: hashlib.sha256(content).hexdigest() for name, content in files.items()}
    for name in needed:
        if name in sums and sums[name] != digests[name]:
            raise SystemExit(f"{name} does not match SHA256SUMS: the release is damaged.")

    stats = json.loads(files["statistics.json"])
    with zipfile.ZipFile(io.BytesIO(files[needed[0]])) as archive:
        csvs = {name: archive.read(name) for name in ("cities.csv", "communes.csv", "commune_successions.csv")}
    numbers = {
        "version": tag,
        **{key: thousands(stats[key]) for key in ("regions", "departments", "communes", "cities", "successions")},
        "cities_rows": thousands(rows(csvs["cities.csv"])),
        "communes_rows": thousands(rows(csvs["communes.csv"])),
        "successions_rows": thousands(rows(csvs["commune_successions.csv"])),
    }
    return {"assets": assets, "digests": digests, "csvs": csvs, "numbers": numbers, "generated_at": stats["generated_at"]}


def plan(tag: str, data: dict, resources: list[dict]) -> list[dict]:
    """One entry per resource: the current resource, the new fields and the file to send, if any."""
    specs = json.loads(SPECS.read_text())["resources"]
    steps = []
    for spec in specs:
        current = [r for r in resources if re.search(spec["match"], r["title"])]
        if len(current) != 1:
            raise SystemExit(f"{len(current)} resources of the dataset match {spec['match']!r}, one is expected.")
        fields = {
            "title": spec["title"].format(**data["numbers"]),
            "description": spec["description"].format(**data["numbers"]),
            "format": spec["format"],
            "mime": spec["mime"],
            "type": spec["type"],
        }
        upload = None
        if spec["kind"] == "remote":
            asset = spec["asset"].format(version=tag)
            fields |= {"url": data["assets"][asset], "filetype": "remote"}
            if spec["checksum"]:
                fields["checksum"] = {"type": spec["checksum"], "value": data["digests"][asset]}
        else:
            upload = (spec["source"], data["csvs"][spec["source"]])
            fields["schema"] = {"url": spec["schema"]}
        steps.append({"key": spec["key"], "id": current[0]["id"], "current": current[0], "fields": fields, "upload": upload})
    return steps


def apply(dataset: str, steps: list[dict], generated_at: str) -> None:
    for step in steps:
        base = f"{API}/datasets/{dataset}/resources/{step['id']}"
        if step["upload"]:
            name, content = step["upload"]
            body, content_type = multipart(name, content)
            call(f"{base}/upload/", method="POST", body=body, headers={"Content-Type": content_type}, key=True)
            print(f"  uploaded {name}")
        call(f"{base}/", method="PUT", body=json.dumps(step["fields"]).encode(), headers={"Content-Type": "application/json"}, key=True)
        print(f"  updated {step['key']}")
    current = json.loads(call(f"{API}/datasets/{dataset}/"))
    coverage = {**current["temporal_coverage"], "end": generated_at}
    call(f"{API}/datasets/{dataset}/", method="PUT", body=json.dumps({"temporal_coverage": coverage}).encode(), headers={"Content-Type": "application/json"}, key=True)
    print(f"  temporal coverage ends on {generated_at}")


def main() -> int:
    arguments = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(arguments) != 1:
        print(__doc__)
        return 2
    tag, do_apply = arguments[0], "--apply" in sys.argv
    if do_apply and not os.environ.get("DATAGOUV_API_KEY"):
        raise SystemExit("DATAGOUV_API_KEY is required with --apply.")

    dataset = json.loads(SPECS.read_text())["dataset"]
    resources = json.loads(call(f"{API}/datasets/{dataset}/"))["resources"]
    data = collect(tag)
    steps = plan(tag, data, resources)

    for step in steps:
        print(f"{step['key']} -> resource {step['id']}")
        for field, value in step["fields"].items():
            old = step["current"].get(field)
            if old != value:
                print(f"  {field}: {str(old)[:70]!r} -> {str(value)[:70]!r}")
        if step["upload"]:
            print(f"  sends {step['upload'][0]} ({len(step['upload'][1])} bytes)")
    print(f"temporal coverage will end on {data['generated_at']}")

    if not do_apply:
        print("\nDry run: nothing was changed. Add --apply to publish.")
        return 0
    apply(dataset, steps, data["generated_at"])
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except urllib.error.HTTPError as error:
        print(f"error: {error.code} {error.reason} on {error.url}\n{error.read().decode()[:500]}", file=sys.stderr)
        sys.exit(1)

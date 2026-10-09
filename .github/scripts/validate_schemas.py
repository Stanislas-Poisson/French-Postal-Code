#!/usr/bin/env python3
"""Checks the CSV files of an export against their Table Schemas.

Usage: validate_schemas.py SCHEMAS_DIR CSV_DIR

For every `NAME.schema.json` of SCHEMAS_DIR that has a `NAME.csv` in CSV_DIR, it checks that the columns are the
fields of the schema, in the same order, and that every row respects the types and the constraints (required,
minimum, maximum, pattern, enum) and the primary key. It prints the first violations of each file and exits with 1
when there is any: the schemas are read by data.gouv.fr, so a file that does not respect them must not be published.

The types and the constraints of the schemas of this project are the only ones understood: integer, number, string and
date, required, minimum, maximum, pattern and enum. Anything else is reported, so that a new one is not ignored.
"""
import csv
import json
import re
import sys
from datetime import date
from pathlib import Path

MAX_REPORTED = 10
TYPES = {"integer", "number", "string", "date"}
CONSTRAINTS = {"required", "minimum", "maximum", "pattern", "enum"}


def convert(value, kind):
    """The value as its type, or raises ValueError."""
    if kind == "integer":
        return int(value)
    if kind == "number":
        return float(value)
    if kind == "date":
        return date.fromisoformat(value)
    return value


def violations(schema, header, rows):
    fields = schema["fields"]
    names = [field["name"] for field in fields]
    if header != names:
        yield f"the columns are {header}, the schema expects {names}"
        return

    missing = set(schema.get("missingValues", [""]))
    primary = schema.get("primaryKey", [])
    primary = [primary] if isinstance(primary, str) else primary
    seen = set()

    for number, row in enumerate(rows, start=2):
        if len(row) != len(names):
            yield f"line {number}: {len(row)} values for {len(names)} columns"
            continue

        for field, value in zip(fields, row):
            name, kind, constraints = field["name"], field["type"], field.get("constraints", {})
            if kind not in TYPES:
                yield f"{name}: the type {kind} is not understood by this check"
                return
            unknown = set(constraints) - CONSTRAINTS
            if unknown:
                yield f"{name}: the constraints {sorted(unknown)} are not understood by this check"
                return

            if value in missing:
                if constraints.get("required"):
                    yield f"line {number}: {name} is required"
                continue

            try:
                converted = convert(value, kind)
            except ValueError:
                yield f"line {number}: {name} {value!r} is not a valid {kind}"
                continue

            if "minimum" in constraints and converted < convert(str(constraints["minimum"]), kind):
                yield f"line {number}: {name} {value} is below {constraints['minimum']}"
            if "maximum" in constraints and converted > convert(str(constraints["maximum"]), kind):
                yield f"line {number}: {name} {value} is above {constraints['maximum']}"
            if "pattern" in constraints and not re.search(constraints["pattern"], value):
                yield f"line {number}: {name} {value!r} does not match {constraints['pattern']}"
            if "enum" in constraints and value not in constraints["enum"]:
                yield f"line {number}: {name} {value!r} is not one of {constraints['enum']}"

        if primary:
            key = tuple(row[names.index(name)] for name in primary)
            if key in seen:
                yield f"line {number}: the primary key {key} is not unique"
            seen.add(key)


def main():
    schemas_dir, csv_dir = Path(sys.argv[1]), Path(sys.argv[2])
    failed = False
    checked = 0

    for schema_path in sorted(schemas_dir.glob("*.schema.json")):
        name = schema_path.name.removesuffix(".schema.json")
        csv_path = csv_dir / f"{name}.csv"
        if not csv_path.exists():
            continue

        schema = json.loads(schema_path.read_text(encoding="utf-8"))
        with open(csv_path, newline="", encoding="utf-8") as handle:
            reader = csv.reader(handle)
            header = next(reader, [])
            problems = []
            total = 0
            for problem in violations(schema, header, reader):
                total += 1
                if len(problems) < MAX_REPORTED:
                    problems.append(problem)

        checked += 1
        if total:
            failed = True
            print(f"{name}.csv: {total} violations of the schema")
            for problem in problems:
                print(f"  {problem}")
        else:
            print(f"{name}.csv: conforms to the schema")

    if checked == 0:
        print("No file to check: the schemas and the CSV files have no name in common.")
        return 1
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

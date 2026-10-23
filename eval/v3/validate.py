#!/usr/bin/env python3
"""Validates the documents saved by eval/v3/assert.sh (see its $OUT).

    validate.py <dir>

Prints PASS/FAIL lines like assert.sh, then "validate: N passed, M failed";
exits non-zero when an assertion failed. No network: the JSON:API schema is
vendored (eval/v3/schemas/jsonapi.json).
"""
import json
import os
import sys

from jsonschema import Draft202012Validator
from openapi_spec_validator import validate as validate_openapi

SCHEMA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "schemas", "jsonapi.json")
DOCUMENTS = [
    ("collection.json", "a collection (GET /books)"),
    ("item.json", "an item (GET /books/4)"),
    ("compound.json", "a compound document (GET /books/4?include=author)"),
    ("error.json", "an error document (404)"),
    ("created.json", "the purchase created by POST /purchases (201)"),
]

passed = 0
failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print(f"PASS  {name}")
    else:
        failed += 1
        print(f"FAIL  {name}" + (f"  ({detail[:300]})" if detail else ""))


def load(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def fix_empty_patterns(node):
    # The schema uses patternProperties {"": true} to mean "any member"; jsonschema treats an empty
    # pattern as no pattern at all and then rejects every member under additionalProperties: false.
    if isinstance(node, dict):
        pp = node.get("patternProperties")
        if isinstance(pp, dict) and "" in pp:
            pp["^"] = pp.pop("")
        for v in node.values():
            fix_empty_patterns(v)
    elif isinstance(node, list):
        for v in node:
            fix_empty_patterns(v)
    return node


def add_jsonapi_1_1(schema):
    # The vendored schema is 1.0: allow the 1.1 members so a valid 1.1 document never fails.
    defs = schema["definitions"]
    uris = {"type": "array", "items": {"type": "string", "format": "uri"}}
    defs["jsonapi"]["properties"].update({"ext": uris, "profile": uris})
    describedby = {"$ref": "#/definitions/link"}
    for name in ("topLevelLinks", "resourceLinks", "relationshipLinks"):
        defs[name]["properties"]["describedby"] = describedby
    defs["errorLinks"]["properties"]["type"] = {"$ref": "#/definitions/link"}
    strings = {"oneOf": [{"type": "string"}, {"type": "array", "items": {"type": "string"}}]}
    defs["linkObject"]["properties"].update(
        {"rel": {"type": "string"}, "title": {"type": "string"}, "type": {"type": "string"}, "hreflang": strings, "describedby": describedby}
    )
    for name in ("resource", "resourceIdentifier", "resourceIdentificationNew"):
        defs[name]["properties"]["lid"] = {"type": "string"}
    return schema


def main():
    directory = sys.argv[1]

    name = "openapi: valid OpenAPI document (3.0 or 3.1)"
    path = os.path.join(directory, "openapi.json")
    try:
        validate_openapi(load(path))
        check(name, True)
    except FileNotFoundError:
        check(name, False, "document not saved by assert.sh")
    except Exception as e:
        check(name, False, f"{type(e).__name__}: {e}".replace("\n", " "))

    validator = Draft202012Validator(add_jsonapi_1_1(fix_empty_patterns(load(SCHEMA))))
    for filename, label in DOCUMENTS:
        name = f"jsonapi: {label} is valid against the JSON:API schema"
        path = os.path.join(directory, filename)
        if not os.path.isfile(path):
            check(name, False, "document not saved by assert.sh")
            continue
        try:
            errors = sorted(validator.iter_errors(load(path)), key=lambda e: list(map(str, e.absolute_path)))
        except Exception as e:
            check(name, False, f"{type(e).__name__}: {e}")
            continue
        detail = "; ".join(f"/{'/'.join(map(str, e.absolute_path))}: {e.message}"[:120] for e in errors[:3])
        check(name, not errors, detail)

    print(f"validate: {passed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

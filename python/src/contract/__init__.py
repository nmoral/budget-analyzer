"""Contrat de données : validation des CSV contre les schémas Frictionless."""

from __future__ import annotations

import json
import os
from pathlib import Path

from frictionless import Report, Resource, Schema

_DATA_DIR = Path(
    os.environ.get("BUDGET_DATA_DIR", Path(__file__).resolve().parents[3] / "data")
)


def validate_csv(csv_path: Path, schema_name: str) -> Report:
    descriptor = json.loads(_read(f"schemas/{schema_name}.json"))
    schema = Schema.from_descriptor(descriptor)
    resource = Resource(
        path=csv_path.name, basepath=str(csv_path.parent), schema=schema
    )
    return resource.validate()


def contract_version() -> str:
    return _read("contract_version.txt").strip()


def _read(relative: str) -> str:
    return (_DATA_DIR / relative).read_text(encoding="utf-8")

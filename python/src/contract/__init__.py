"""Contrat de données : validation des CSV contre les schémas Frictionless."""

from __future__ import annotations

import json
import os
from pathlib import Path

from frictionless import Report, Resource, Schema


def data_dir() -> Path:
    default = Path(__file__).resolve().parents[3] / "data"
    return Path(os.environ.get("BUDGET_DATA_DIR", str(default)))


def validate_csv(csv_path: Path, schema_name: str) -> Report:
    descriptor = json.loads(_read(f"schemas/{schema_name}.json"))
    schema = Schema.from_descriptor(descriptor)
    resource = Resource(
        path=csv_path.name, basepath=str(csv_path.parent), schema=schema
    )
    return resource.validate()


def contract_version() -> str:
    return _read("contract_version.txt").strip()


def schema_fields(schema_name: str) -> list[str]:
    descriptor = json.loads(_read(f"schemas/{schema_name}.json"))
    return [str(field["name"]) for field in descriptor["fields"]]


def _read(relative: str) -> str:
    return (data_dir() / relative).read_text(encoding="utf-8")

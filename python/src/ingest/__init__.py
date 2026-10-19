"""Socle d'ingestion : CSV normalisé validé + brut archivé et manifeste."""

from __future__ import annotations

import csv
import hashlib
import io
import json
import os
from pathlib import Path

from contract import data_dir, schema_fields, validate_csv


class IngestionError(Exception):
    """Les lignes à ingérer ne respectent pas le schéma du contrat."""


def ingest(
    *,
    rows: list[dict[str, str]],
    schema_name: str,
    name: str,
    raw_path: Path,
    source: str,
    url: str,
    retrieved_at: str,
    script: str,
    script_version: str,
) -> Path:
    columns = schema_fields(schema_name)
    content = _to_csv(rows, columns)

    root = data_dir()
    final = root / "data" / f"{name}.csv"
    tmp = root / "data" / f".{name}.tmp.csv"
    tmp.write_bytes(content)

    report = validate_csv(tmp, schema_name)
    if not report.valid:
        tmp.unlink()
        raise IngestionError(
            f"Invalid CSV for schema '{schema_name}': {report.flatten(['type'])}"
        )

    raw_bytes = raw_path.read_bytes()
    archived = f"{name}{raw_path.suffix}"
    (root / "raw" / archived).write_bytes(raw_bytes)
    _write_manifest(
        root / "manifests" / f"{name}.json",
        {
            "source": source,
            "url": url,
            "retrieved_at": retrieved_at,
            "sha256": hashlib.sha256(raw_bytes).hexdigest(),
            "script": script,
            "script_version": script_version,
            "raw": archived,
        },
    )

    os.replace(tmp, final)
    return final


def verify_manifest(name: str) -> bool:
    root = data_dir()
    manifest = json.loads((root / "manifests" / f"{name}.json").read_text("utf-8"))
    raw_bytes = (root / "raw" / manifest["raw"]).read_bytes()
    return hashlib.sha256(raw_bytes).hexdigest() == str(manifest["sha256"])


def _to_csv(rows: list[dict[str, str]], columns: list[str]) -> bytes:
    buffer = io.StringIO()
    writer = csv.writer(buffer, lineterminator="\n")
    writer.writerow(columns)
    for row in rows:
        writer.writerow([row.get(column, "") for column in columns])
    return buffer.getvalue().encode("utf-8")


def _write_manifest(path: Path, manifest: dict[str, str]) -> None:
    text = json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n"
    path.write_text(text, encoding="utf-8")

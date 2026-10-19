"""Socle d'ingestion : écriture CSV normalisée et validée contre le contrat."""

from __future__ import annotations

import csv
import io
import os
from pathlib import Path

from contract import data_dir, schema_fields, validate_csv


class IngestionError(Exception):
    """Les lignes à ingérer ne respectent pas le schéma du contrat."""


def ingest(*, rows: list[dict[str, str]], schema_name: str, name: str) -> Path:
    columns = schema_fields(schema_name)
    content = _to_csv(rows, columns)

    out_dir = data_dir() / "data"
    final = out_dir / f"{name}.csv"
    tmp = out_dir / f".{name}.tmp.csv"
    tmp.write_bytes(content)

    report = validate_csv(tmp, schema_name)
    if not report.valid:
        tmp.unlink()
        raise IngestionError(
            f"Invalid CSV for schema '{schema_name}': {report.flatten(['type'])}"
        )

    os.replace(tmp, final)
    return final


def _to_csv(rows: list[dict[str, str]], columns: list[str]) -> bytes:
    buffer = io.StringIO()
    writer = csv.writer(buffer, lineterminator="\n")
    writer.writerow(columns)
    for row in rows:
        writer.writerow([row.get(column, "") for column in columns])
    return buffer.getvalue().encode("utf-8")

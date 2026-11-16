"""Ingestion du budget de référence daté par financeur (programme 140 LFI / ONDAM LFSS)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = (
    "Budget de référence par financeur — PAP « Enseignement scolaire » "
    "(programme 140, LFI) et Cour des comptes, RALFSS (ONDAM voté, LFSS)"
)
URL = (
    "https://www2.assemblee-nationale.fr/static/17/Annexes-DL/PLF-2025/"
    "Enseignement_scolaire.pdf"
)
RETRIEVED_AT = "2026-11-01"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "budget_financeur.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="budget_financeur",
        name="budget_financeur",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.budget_financeur",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

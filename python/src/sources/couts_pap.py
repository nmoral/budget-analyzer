"""Ingestion des couts moyens charges PAP par categorie d'emploi (saisie sourcee)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "PAP 2025 — annexe « Enseignement scolaire » (programme 140)"
URL = (
    "https://www2.assemblee-nationale.fr/static/17/Annexes-DL/PLF-2025/"
    "Enseignement_scolaire.pdf"
)
RETRIEVED_AT = "2026-10-21"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "couts_pap.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="couts_pap",
        name="couts_pap",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.couts_pap",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

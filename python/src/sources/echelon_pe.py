"""Ingestion des échelons du professeur des écoles (classe normale)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "Décret n° 2017-789 du 4 mai 2017"
URL = "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000034601473"
RETRIEVED_AT = "2026-10-21"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "echelon_pe.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="echelon",
        name="echelon_pe",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.echelon_pe",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

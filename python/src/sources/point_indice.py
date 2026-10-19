"""Ingestion de la valeur du point d'indice (paramètre daté)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "Décret n° 2023-519 du 28 juin 2023"
URL = "https://www.legifrance.gouv.fr/eli/decret/2023/6/28/2023-519/jo/texte"
RETRIEVED_AT = "2026-10-19"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "point_indice.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="point_indice",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.point_indice",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

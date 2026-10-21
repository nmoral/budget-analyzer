"""Ingestion de la grille indiciaire de l'infirmier en soins généraux (grade 1)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = (
    "Arrêté du 19 mai 2016 (échelonnement indiciaire, corps ISGS — décret n° 2010-1139)"
)
URL = "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000032551975"
RETRIEVED_AT = "2026-10-21"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "grille_isg.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="grille",
        name="grille_isg",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.grille_isg",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

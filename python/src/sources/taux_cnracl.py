"""Ingestion du taux de cotisation employeur CNRACL (paramètre daté, trajectoire 2026→2028)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "Décret n° 2025-86 du 30 janvier 2025"
URL = (
    "https://blog.landot-avocats.net/2025/01/31/"
    "augmentation-du-taux-de-cotisation-employeurs-a-la-cnracl/"
)
RETRIEVED_AT = "2026-10-19"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_cnracl.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="taux_cnracl",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_cnracl",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

"""Ingestion du taux de cotisation employeur RAFP (paramètre daté)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "RAFP — taux de cotisation employeur 2026 (décret n° 2004-569)"
URL = "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000607091"
RETRIEVED_AT = "2026-10-30"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_rafp.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="taux_rafp",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_rafp",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

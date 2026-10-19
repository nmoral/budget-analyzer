"""Ingestion du taux de cotisation employeur CAS Pensions, militaire (paramètre daté)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "Assemblée nationale — rapport CAS Pensions (RAPPANR5L17B1996)"
URL = "https://www.assemblee-nationale.fr/dyn/opendata/RAPPANR5L17B1996-tIII-a37.html"
RETRIEVED_AT = "2026-10-19"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_cas_militaire.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="taux_cas_militaire",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_cas_militaire",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

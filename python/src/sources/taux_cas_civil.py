"""Ingestion du taux de cotisation employeur CAS Pensions, civil (paramètre daté)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = "Retraites de l'État — taux de cotisation 2026"
URL = (
    "https://retraitesdeletat.gouv.fr/professionnels/le-versement-des-cotisations/"
    "les-entites-privees-associations-entreprises-gip-etc"
)
RETRIEVED_AT = "2026-10-19"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_cas_civil.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="taux_cas_civil",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_cas_civil",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

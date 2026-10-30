"""Ingestion du taux employeur agrégé hors retraite — FPE/État (paramètre daté)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = (
    "Cotisations employeur hors retraite — FPE/État 2026 "
    "(URSSAF secteur public / CDG67)"
)
URL = "https://www.urssaf.fr/accueil/employeur/secteur-public.html"
RETRIEVED_AT = "2026-10-30"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_autres_cotisations_fpe.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="parametre_date",
        name="taux_autres_cotisations_fpe",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_autres_cotisations_fpe",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

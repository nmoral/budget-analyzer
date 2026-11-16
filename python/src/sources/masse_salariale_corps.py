"""Ingestion de la masse salariale datée par corps (coût chargé HCAS, PAP).

Masse salariale d'un corps = ETPT de sa catégorie d'emploi × coût moyen chargé
HCAS global de cette catégorie, les deux valeurs étant lues dans le même PAP.
Le brut versionné porte les deux facteurs ; le produit est calculé ici.
"""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = (
    "Masse salariale par corps — PAP « Enseignement scolaire » (programme 140) : "
    "ETPT de la catégorie d'emploi × coût moyen chargé HCAS global"
)
URL = (
    "https://www2.assemblee-nationale.fr/static/17/Annexes-DL/PLF-2025/"
    "Enseignement_scolaire.pdf"
)
RETRIEVED_AT = "2026-11-16"
SCRIPT_VERSION = "1"

_PASSTHROUGH = ("body_code", "unit", "start_date", "end_date", "source")


def payroll_rows(raw_rows: list[dict[str, str]]) -> list[dict[str, str]]:
    """Calcule ``value`` = ``etpt`` × ``average_loaded_cost`` (euros entiers)."""
    return [
        {
            **{key: raw[key] for key in _PASSTHROUGH},
            "value": str(int(raw["etpt"]) * int(raw["average_loaded_cost"])),
        }
        for raw in raw_rows
    ]


def run() -> Path:
    source_path = data_dir() / "sources" / "masse_salariale_corps.json"
    raw_rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=payroll_rows(raw_rows),
        schema_name="masse_salariale_corps",
        name="masse_salariale_corps",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.masse_salariale_corps",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

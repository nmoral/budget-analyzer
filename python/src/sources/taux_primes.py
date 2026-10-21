"""Ingestion des taux de primes moyens par corps (profils V1 : PE, ISG)."""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir
from ingest import ingest

SOURCE = (
    "DGAFP, rapport annuel sur l'etat de la fonction publique, edition 2025 "
    "(PE, part des primes des enseignants, donnees 2023) ; Insee Premiere n 2067 "
    "(ISG, repli sur la part des primes categorie A FPH, donnees 2023)"
)
URL = (
    "https://www.fonction-publique.gouv.fr/toutes-les-publications/"
    "rapport-annuel-sur-letat-de-la-fonction-publique-edition-2025"
)
RETRIEVED_AT = "2026-10-21"
SCRIPT_VERSION = "1"


def run() -> Path:
    source_path = data_dir() / "sources" / "taux_primes.json"
    rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=rows,
        schema_name="taux_primes",
        name="taux_primes",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.taux_primes",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

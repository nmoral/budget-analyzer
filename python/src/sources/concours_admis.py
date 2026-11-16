"""Ingestion des admis aux concours par corps, session et voie.

Professeurs des écoles (``PE``) : totaux nationaux des CRPE de l'enseignement
public (postes offerts, candidats inscrits, présents aux épreuves écrites,
admis), transcrits des pages statistiques de devenirenseignant.gouv.fr, une page
par session. Le brut versionné garde, pour traçabilité, le libellé exact du bloc
source (``block``), qui n'entre pas dans le CSV, et, quand la Polynésie française
(corps distinct) a été retranchée du total publié (externe 2021 : ligne académie ;
externe 2025 : bloc séparé), ce total (``published_total``) et l'élément retranché
(``excluded``).
"""

from __future__ import annotations

import json
from pathlib import Path

from contract import data_dir, schema_fields
from ingest import ingest

SOURCE = (
    "Admis aux concours de recrutement de professeurs des écoles (CRPE, "
    "enseignement public) — totaux nationaux par voie, sessions 2021 à 2025"
)
URL = "https://www.devenirenseignant.gouv.fr/statistiques"
RETRIEVED_AT = "2026-11-16"
SCRIPT_VERSION = "1"
SCHEMA = "concours_admis"


def concours_rows(raw_rows: list[dict[str, str]]) -> list[dict[str, str]]:
    """Ne garde que les colonnes du schéma (le libellé ``block`` est écarté)."""
    columns = schema_fields(SCHEMA)
    return [{column: raw[column] for column in columns} for raw in raw_rows]


def run() -> Path:
    source_path = data_dir() / "sources" / "concours_admis.json"
    raw_rows = json.loads(source_path.read_text("utf-8"))["rows"]
    return ingest(
        rows=concours_rows(raw_rows),
        schema_name=SCHEMA,
        name="concours_admis",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.concours_admis",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

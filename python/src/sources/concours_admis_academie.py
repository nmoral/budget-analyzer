"""Ingestion des admis aux concours par corps, session, voie et académie.

Professeurs des écoles (``PE``) : détail par académie des CRPE de l'enseignement
public, sessions 2021 à 2025, transcrit des pages statistiques archivées de
devenirenseignant.gouv.fr (tableaux « Résultats par académies » des blocs
publics, bloc « … corps de l'Etat créé pour la Polynésie française », bloc sans
tableau nommant son académie). Le brut garde le libellé publié de l'académie
(``academie_label``) ; il est résolu en code officiel par le référentiel
``academies.json``, sans tenir compte de la casse, des accents ni de la
ponctuation. Un libellé inconnu ou absent bloque la génération.
"""

from __future__ import annotations

import json
import re
import unicodedata
from pathlib import Path
from typing import Any

from contract import data_dir, schema_fields
from ingest import ingest

SOURCE = (
    "Admis aux concours de recrutement de professeurs des écoles (CRPE, "
    "enseignement public) — détail par académie, sessions 2021 à 2025"
)
URL = "https://www.devenirenseignant.gouv.fr/statistiques"
RETRIEVED_AT = "2026-11-16"
SCRIPT_VERSION = "1"
SCHEMA = "concours_admis_academie"


class UnknownAcademieError(ValueError):
    """Libellé d'académie publié absent du référentiel ``academies.json``."""


def normalize_label(label: str) -> str:
    """Forme de comparaison : sans accents, minuscules, ponctuation en espaces."""
    decomposed = unicodedata.normalize("NFKD", label)
    plain = "".join(char for char in decomposed if not unicodedata.combining(char))
    return " ".join(re.sub(r"[^0-9a-z]+", " ", plain.casefold()).split())


def label_index(referential: dict[str, dict[str, Any]]) -> dict[str, str]:
    """Libellé normalisé (libellé officiel et alias) → code d'académie."""
    index: dict[str, str] = {}
    for code, entry in referential.items():
        for label in (entry["label"], *entry.get("aliases", [])):
            index[normalize_label(label)] = code
    return index


def resolve_academie(label: str, index: dict[str, str]) -> str:
    """Code officiel d'un libellé publié ; ``UnknownAcademieError`` sinon."""
    code = index.get(normalize_label(label))
    if code is None:
        raise UnknownAcademieError(f"académie inconnue : « {label} »")
    return code


def academie_rows(
    raw_rows: list[dict[str, str]], index: dict[str, str]
) -> list[dict[str, str]]:
    """Colonnes du schéma, l'académie publiée remplacée par son code officiel."""
    columns = schema_fields(SCHEMA)
    rows: list[dict[str, str]] = []
    for raw in raw_rows:
        resolved = {**raw, "academie": resolve_academie(raw["academie_label"], index)}
        rows.append({column: resolved[column] for column in columns})
    return rows


def run() -> Path:
    root = data_dir()
    source_path = root / "sources" / "concours_admis_academie.json"
    raw_rows = json.loads(source_path.read_text("utf-8"))["rows"]
    referential = json.loads((root / "academies.json").read_text("utf-8"))
    return ingest(
        rows=academie_rows(raw_rows, label_index(referential)),
        schema_name=SCHEMA,
        name="concours_admis_academie",
        raw_path=source_path,
        source=SOURCE,
        url=URL,
        retrieved_at=RETRIEVED_AT,
        script="sources.concours_admis_academie",
        script_version=SCRIPT_VERSION,
    )


def main() -> None:
    print(run())


if __name__ == "__main__":
    main()

"""Contrôles de cohérence métier sur les CSV normalisés de ``data/data/``.

Commande unique (``python -m checks``) : chaque CSV présent est relié à son
schéma par un mapping explicite, les invariants pertinents sont appliqués, et
**toutes** les anomalies sont rapportées (une ligne par violation + un
récapitulatif). Sortie 0 si aucune anomalie, 1 sinon.

Les invariants sont des fonctions prenant la liste des fichiers chargés et
renvoyant des violations ; de nouveaux invariants s'enregistrent simplement dans
``INVARIANTS`` (C1/C2/C3 aux jalons suivants) sans toucher à l'agrégation.
"""

from __future__ import annotations

import csv
import json
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from datetime import date
from itertools import combinations, pairwise
from pathlib import Path

from contract import data_dir

# Mapping explicite nom de fichier (sans extension) → schéma. Plusieurs fichiers
# peuvent viser un même schéma : ce n'est pas une simple égalité nom = schéma.
FILE_TO_SCHEMA: dict[str, str] = {
    "point_indice": "parametre_date",
    "taux_cas_civil": "parametre_date",
    "taux_cas_militaire": "parametre_date",
    "taux_cnracl": "parametre_date",
    "echelon_pe": "echelon",
    "echelon_isg": "echelon",
    "grille_pe": "grille",
    "grille_isg": "grille",
    "taux_primes": "taux_primes",
    "couts_pap": "couts_pap",
}


@dataclass(frozen=True)
class LoadedFile:
    """Un CSV présent dans ``data/data/`` relié à son schéma."""

    name: str
    schema: str
    has_source: bool
    rows: list[tuple[int, dict[str, str]]]

    @property
    def filename(self) -> str:
        return f"{self.name}.csv"


@dataclass(frozen=True)
class Violation:
    """Une anomalie détectée par un invariant, localisée fichier + ligne."""

    invariant: str
    file: str
    line: int
    message: str

    def as_line(self) -> str:
        return f"{self.file}:{self.line} [{self.invariant}] {self.message}"


Invariant = Callable[[Sequence[LoadedFile]], list[Violation]]


def check_source_not_blank(files: Sequence[LoadedFile]) -> list[Violation]:
    """C4 — toute ligne d'un schéma à colonne ``source`` a une source non blanche."""
    violations: list[Violation] = []
    for loaded in files:
        if not loaded.has_source:
            continue
        for line, row in loaded.rows:
            if not row.get("source", "").strip():
                violations.append(
                    Violation(
                        invariant="source",
                        file=loaded.filename,
                        line=line,
                        message="source blanche ou absente",
                    )
                )
    return violations


def check_indices_strictly_increasing(files: Sequence[LoadedFile]) -> list[Violation]:
    """C1 — dans chaque grille ``echelon``, ``gross_index`` et ``increased_index``

    sont strictement croissants par ``rank`` croissant.
    """
    violations: list[Violation] = []
    for loaded in files:
        if loaded.schema != "echelon":
            continue
        violations.extend(_echelon_index_violations(loaded))
    return violations


def _echelon_index_violations(loaded: LoadedFile) -> list[Violation]:
    grilles: dict[str, list[tuple[int, dict[str, str]]]] = {}
    for line, row in loaded.rows:
        grilles.setdefault(row["grille"], []).append((line, row))

    violations: list[Violation] = []
    for grille, entries in grilles.items():
        ordered = sorted(entries, key=lambda entry: int(entry[1]["rank"]))
        for (_, previous), (line, row) in pairwise(ordered):
            for column in ("gross_index", "increased_index"):
                current_value = int(row[column])
                previous_value = int(previous[column])
                if current_value <= previous_value:
                    violations.append(
                        Violation(
                            invariant="indices",
                            file=loaded.filename,
                            line=line,
                            message=(
                                f"grille {grille} rang {row['rank']} : "
                                f"{column} non strictement croissant "
                                f"({current_value} <= {previous_value})"
                            ),
                        )
                    )
    return violations


@dataclass(frozen=True)
class _Period:
    """Une période d'effet ``[start ; end]`` bornes incluses (``end`` ∞ si ouverte)."""

    line: int
    start: date
    end: date
    start_raw: str
    end_raw: str

    def render(self) -> str:
        return f"{self.start_raw}→{self.end_raw or '∞'}"


def _parametre_key(row: dict[str, str]) -> str:
    return row["key"]


def _grille_key(row: dict[str, str]) -> str:
    return f"{row['body_code']}/{row['grade']}"


def check_periods_no_overlap(files: Sequence[LoadedFile]) -> list[Violation]:
    """C2 — les périodes d'effet d'une même clé ne se chevauchent pas.

    ``parametre_date`` est groupé par ``key``, ``grille`` par ``body_code`` +
    ``grade``. Bornes incluses, ``end_date`` vide = période ouverte ; l'adjacence
    (fin le jour J, début le jour J+1) est autorisée.
    """
    violations: list[Violation] = []
    for loaded in files:
        if loaded.schema == "parametre_date":
            violations.extend(_period_violations(loaded, _parametre_key))
        elif loaded.schema == "grille":
            violations.extend(_period_violations(loaded, _grille_key))
    return violations


def _period_violations(
    loaded: LoadedFile, key_of: Callable[[dict[str, str]], str]
) -> list[Violation]:
    groups: dict[str, list[_Period]] = {}
    for line, row in loaded.rows:
        end_raw = row["end_date"]
        period = _Period(
            line=line,
            start=date.fromisoformat(row["start_date"]),
            end=date.fromisoformat(end_raw) if end_raw.strip() else date.max,
            start_raw=row["start_date"],
            end_raw=end_raw,
        )
        groups.setdefault(key_of(row), []).append(period)

    violations: list[Violation] = []
    for label, periods in groups.items():
        ordered = sorted(periods, key=lambda period: (period.start, period.line))
        for first, second in combinations(ordered, 2):
            if first.start <= second.end and second.start <= first.end:
                violations.append(
                    Violation(
                        invariant="periodes",
                        file=loaded.filename,
                        line=second.line,
                        message=(
                            f"clé {label} : périodes "
                            f"{first.render()} et {second.render()} se chevauchent"
                        ),
                    )
                )
    return violations


# Registre des invariants appliqués. C3 s'y ajoutera au jalon J4.
INVARIANTS: tuple[Invariant, ...] = (
    check_source_not_blank,
    check_indices_strictly_increasing,
    check_periods_no_overlap,
)


def read_rows(path: Path) -> list[tuple[int, dict[str, str]]]:
    """Lit un CSV en lignes numérotées (l'en-tête est la ligne 1)."""
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle, restval="")
        return [
            (line, {str(key): str(value) for key, value in row.items()})
            for line, row in enumerate(reader, start=2)
        ]


def schema_has_source(root: Path, schema: str) -> bool:
    """Vrai si le schéma (tel que livré sous ``root/schemas``) a une colonne ``source``."""
    descriptor = json.loads((root / "schemas" / f"{schema}.json").read_text("utf-8"))
    return any(field.get("name") == "source" for field in descriptor["fields"])


def collect_files(root: Path) -> list[LoadedFile]:
    """Charge les CSV présents dans ``root/data`` selon le mapping (absents ignorés)."""
    data = root / "data"
    loaded: list[LoadedFile] = []
    for name, schema in FILE_TO_SCHEMA.items():
        path = data / f"{name}.csv"
        if not path.exists():
            continue
        loaded.append(
            LoadedFile(
                name=name,
                schema=schema,
                has_source=schema_has_source(root, schema),
                rows=read_rows(path),
            )
        )
    return loaded


def format_report(violations: Sequence[Violation]) -> str:
    """Rapport texte : une ligne par violation + récapitulatif."""
    if not violations:
        return "Contrôles de cohérence : aucune anomalie."
    lines = [violation.as_line() for violation in violations]
    lines.append(f"Récapitulatif : {len(violations)} anomalie(s) détectée(s).")
    return "\n".join(lines)


def run(root: Path) -> tuple[int, str]:
    """Applique tous les invariants sur ``root`` et renvoie ``(code, rapport)``."""
    files = collect_files(root)
    violations: list[Violation] = []
    for invariant in INVARIANTS:
        violations.extend(invariant(files))
    code = 1 if violations else 0
    return code, format_report(violations)


def main() -> int:
    """Point d'entrée CLI : imprime le rapport, renvoie le code de sortie."""
    code, report = run(data_dir())
    print(report)
    return code

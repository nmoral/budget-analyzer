"""Invariant ``[format]`` de ``python -m checks`` (issue #79).

Une valeur mal formée (entier, décimal, date) d'une colonne lue par un
invariant est signalée en ``[format]`` ; les autres invariants ignorent la ligne.
"""

import os
import runpy
import shutil
from pathlib import Path

import pytest

from checks import run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."

NATIONAL_2025_EXTERNE = "PE,2025,externe,8102,43276,18387,7561,menj-crpe-2025"
NATIONAL_2024_EXTERNE = "PE,2024,externe,7917,42145,18385,6961,menj-crpe-2024"
GUADELOUPE_2025 = "PE,2025,externe,32,26,442,165,26,menj-crpe-2025-academies"
NORMANDIE_2024 = "PE,2024,externe,70,318,1904,948,318,menj-crpe-2024-academies"
ECHELON_PE_RANK_2 = "PE_classe_normale_2024,2,513,446,12"
ECHELON_ISG_RANK_2 = "ISG_grade_1_2024,2,484,424,18"
GRILLE_PE = "PE,classe_normale,2024,2024-01-01,,decret-2017-789"
POINT_INDICE = "point_indice,59.0734,EUR_PER_YEAR,2023-07-01,,decret-2023-519"


@pytest.fixture
def real_copy(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Copie intégrale des données réelles (CSV, schémas, référentiels, bornes)."""
    for sub in ("schemas", "data"):
        shutil.copytree(REAL_DATA / sub, tmp_path / sub)
    for name in ("sources.json", "academies.json", "coherence.toml"):
        shutil.copy(REAL_DATA / name, tmp_path / name)
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _replace(root: Path, name: str, old: str, new: str) -> int:
    """Remplace la ligne ``old`` de ``data/<name>.csv`` ; renvoie son numéro."""
    path = root / "data" / f"{name}.csv"
    lines = path.read_text("utf-8").split("\n")
    assert lines.count(old) == 1, old
    index = lines.index(old)
    lines[index] = new
    path.write_text("\n".join(lines), "utf-8")
    return index + 1


def _anomalies(root: Path) -> tuple[int, list[str]]:
    """Code de sortie et lignes d'anomalie (récapitulatif retiré)."""
    code, report = run(root)
    return code, [line for line in report.splitlines() if "[" in line]


def _of(lines: list[str], invariant: str) -> list[str]:
    return [line for line in lines if f"[{invariant}]" in line]


# --- C1.1 : nominal ----------------------------------------------------------


def test_c1_1_real_data_exit_zero(real_copy: Path) -> None:
    assert run(real_copy) == (0, NO_ANOMALY)


# --- C1.2 / C1.3 / C2.2 : entier invalide ou vide (national) -----------------


@pytest.mark.parametrize("raw", ["abc", ""])
def test_c1_2_c1_3_c2_2_malformed_postes_only_format(real_copy: Path, raw: str) -> None:
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        f"PE,2025,externe,{raw},43276,18387,7561,menj-crpe-2025",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        f"concours_admis.csv:{line} [format] postes : « {raw} » n'est pas un entier"
    ]


def test_c1_2_cli_exit_one_without_traceback(
    real_copy: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,abc,43276,18387,7561,menj-crpe-2025",
    )
    with pytest.raises(SystemExit) as exc:
        runpy.run_module("checks", run_name="__main__")
    assert exc.value.code == 1
    assert "postes : « abc » n'est pas un entier" in capsys.readouterr().out


# --- C1.4 : entier strict ----------------------------------------------------


@pytest.mark.parametrize("raw", ["12.0", " 12", "+12", "1e2"])
def test_c1_4_integer_is_strict(real_copy: Path, raw: str) -> None:
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        f"PE,2025,externe,8102,{raw},18387,7561,menj-crpe-2025",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        f"concours_admis.csv:{line} [format] inscrits : « {raw} » n'est pas un entier"
    ]


# --- C1.5 : négatif ----------------------------------------------------------


def test_c1_5_negative_is_well_formed_and_reported_by_concours(
    real_copy: Path,
) -> None:
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,-3,43276,18387,7561,menj-crpe-2025",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert _of(lines, "format") == []
    assert (
        f"concours_admis.csv:{line} [concours] PE/2025/externe : postes -3 < 0"
    ) in lines


# --- C1.6 / C1.7 : indices ---------------------------------------------------


def test_c1_6_malformed_gross_index_other_grilles_checked(real_copy: Path) -> None:
    line = _replace(
        real_copy, "echelon_pe", ECHELON_PE_RANK_2, "PE_classe_normale_2024,2,x,446,12"
    )
    isg_line = _replace(
        real_copy, "echelon_isg", ECHELON_ISG_RANK_2, "ISG_grade_1_2024,2,400,424,18"
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        f"echelon_pe.csv:{line} [format] gross_index : « x » n'est pas un entier",
        (
            f"echelon_isg.csv:{isg_line} [indices] grille ISG_grade_1_2024 rang 2 : "
            "gross_index non strictement croissant (400 <= 444)"
        ),
    ]


def test_c1_7_blank_rank(real_copy: Path) -> None:
    line = _replace(
        real_copy, "echelon_pe", ECHELON_PE_RANK_2, "PE_classe_normale_2024,,513,446,12"
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [f"echelon_pe.csv:{line} [format] rank : «  » n'est pas un entier"]


def test_malformed_increased_index_reported(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "echelon_pe",
        ECHELON_PE_RANK_2,
        "PE_classe_normale_2024,2,513,1.5,12",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"echelon_pe.csv:{line} [format] increased_index : « 1.5 » n'est pas un entier"
    ]


def test_malformed_echelon_row_ignored_rest_of_grille_compared(
    real_copy: Path,
) -> None:
    """La ligne ignorée sort de la comparaison ; ses voisines restent comparées."""
    path = real_copy / "data" / "echelon_pe.csv"
    content = path.read_text("utf-8")
    content = content.replace(
        "PE_classe_normale_2024,3,523,453,24", "PE_classe_normale_2024,3,400,453,24"
    )
    path.write_text(content, "utf-8")
    line = _replace(
        real_copy, "echelon_pe", ECHELON_PE_RANK_2, "PE_classe_normale_2024,2,x,446,12"
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"echelon_pe.csv:{line} [format] gross_index : « x » n'est pas un entier",
        (
            f"echelon_pe.csv:{line + 1} [indices] grille PE_classe_normale_2024 "
            "rang 3 : gross_index non strictement croissant (400 <= 444)"
        ),
    ]


# --- C1.8 / C1.9 : décimal ---------------------------------------------------


def test_c1_8_malformed_decimal(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "point_indice",
        POINT_INDICE,
        "point_indice,abc,EUR_PER_YEAR,2023-07-01,,decret-2023-519",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        f"point_indice.csv:{line} [format] value : « abc » n'est pas un décimal"
    ]


@pytest.mark.parametrize("raw", ["nan", "inf", "1e3", "1,5", ".5", ""])
def test_c1_9_decimal_refused(real_copy: Path, raw: str) -> None:
    field = f'"{raw}"' if "," in raw else raw
    line = _replace(
        real_copy,
        "point_indice",
        POINT_INDICE,
        f"point_indice,{field},EUR_PER_YEAR,2023-07-01,,decret-2023-519",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"point_indice.csv:{line} [format] value : « {raw} » n'est pas un décimal"
    ]


@pytest.mark.parametrize("raw", ["59.0734", "-1", "12"])
def test_c1_9_decimal_accepted(real_copy: Path, raw: str) -> None:
    _replace(
        real_copy,
        "point_indice",
        POINT_INDICE,
        f"point_indice,{raw},EUR_PER_YEAR,2023-07-01,,decret-2023-519",
    )
    _, lines = _anomalies(real_copy)
    assert _of(lines, "format") == []


def test_c1_9_negative_decimal_still_bounded(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "point_indice",
        POINT_INDICE,
        "point_indice,-1,EUR_PER_YEAR,2023-07-01,,decret-2023-519",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        (
            f"point_indice.csv:{line} [bornes] clé point_indice : valeur -1 "
            "sous la borne min 0"
        )
    ]


@pytest.mark.parametrize(
    ("name", "old", "new"),
    [
        (
            "taux_primes",
            "PE,0.21,2023,dgafp-ra-2025,false",
            "PE,x,2023,dgafp-ra-2025,false",
        ),
        (
            "budget_financeur",
            "ETAT_P140,26842958249,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
            "ETAT_P140,x,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
        ),
        (
            "masse_salariale_corps",
            "PE,15590703468,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106",
            "PE,x,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106",
        ),
    ],
)
def test_value_format_checked_on_every_bounded_schema(
    real_copy: Path, name: str, old: str, new: str
) -> None:
    line = _replace(real_copy, name, old, new)
    _, lines = _anomalies(real_copy)
    assert lines == [f"{name}.csv:{line} [format] value : « x » n'est pas un décimal"]


def test_value_format_checked_without_bound(real_copy: Path) -> None:
    """Décision : format de ``value`` contrôlé même sans borne (ici, aucun toml)."""
    (real_copy / "coherence.toml").unlink()
    line = _replace(
        real_copy,
        "point_indice",
        POINT_INDICE,
        "point_indice,abc,EUR_PER_YEAR,2023-07-01,,decret-2023-519",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"point_indice.csv:{line} [format] value : « abc » n'est pas un décimal"
    ]


# --- C1.10 / C1.11 : dates ---------------------------------------------------


@pytest.mark.parametrize("raw", ["2024-13-01", "20240101", "2024-02-30", ""])
def test_c1_10_malformed_start_date(real_copy: Path, raw: str) -> None:
    line = _replace(
        real_copy,
        "grille_pe",
        GRILLE_PE,
        f"PE,classe_normale,2024,{raw},,decret-2017-789",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        (
            f"grille_pe.csv:{line} [format] start_date : « {raw} » "
            "n'est pas une date AAAA-MM-JJ"
        )
    ]


@pytest.mark.parametrize("raw", ["2024-13-01", "2024-1-01", "x"])
def test_malformed_end_date(real_copy: Path, raw: str) -> None:
    line = _replace(
        real_copy,
        "grille_pe",
        GRILLE_PE,
        f"PE,classe_normale,2024,2024-01-01,{raw},decret-2017-789",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        (
            f"grille_pe.csv:{line} [format] end_date : « {raw} » "
            "n'est pas une date AAAA-MM-JJ"
        )
    ]


def test_c1_11_blank_end_date_accepted(real_copy: Path) -> None:
    """``grille_pe`` réel : ``end_date`` vide (sans fin) → aucune anomalie."""
    assert GRILLE_PE.split(",")[4] == ""
    _replace(real_copy, "grille_pe", GRILLE_PE, GRILLE_PE)
    assert run(real_copy) == (0, NO_ANOMALY)


def test_malformed_period_row_ignored_by_periodes(real_copy: Path) -> None:
    """Une période mal formée ne chevauche rien ; les autres restent comparées."""
    path = real_copy / "data" / "grille_pe.csv"
    path.write_text(
        path.read_text("utf-8")
        + "PE,classe_normale,2025,2024-13-01,,decret-2017-789\n"
        + "PE,classe_normale,2026,2025-01-01,,decret-2017-789\n",
        "utf-8",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        (
            "grille_pe.csv:3 [format] start_date : « 2024-13-01 » "
            "n'est pas une date AAAA-MM-JJ"
        ),
        (
            "grille_pe.csv:4 [periodes] clé PE/classe_normale : périodes "
            "2024-01-01→∞ et 2025-01-01→∞ se chevauchent"
        ),
    ]


# --- C1.12 : plusieurs cellules ---------------------------------------------


def test_c1_12_two_malformed_cells_two_anomalies(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,abc,43276,18387,x,menj-crpe-2025",
    )
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        f"concours_admis.csv:{line} [format] postes : « abc » n'est pas un entier",
        f"concours_admis.csv:{line} [format] admis : « x » n'est pas un entier",
    ]


# --- C2.1 : recoupement, valeur mal formée côté académies -------------------


def test_c2_1_malformed_academy_skips_its_key_only(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "concours_admis_academie",
        GUADELOUPE_2025,
        "PE,2025,externe,32,26,442,165,abc,menj-crpe-2025-academies",
    )
    _replace(
        real_copy,
        "concours_admis_academie",
        NORMANDIE_2024,
        "PE,2024,externe,70,319,1904,948,318,menj-crpe-2024-academies",
    )
    national_2024 = (real_copy / "data" / "concours_admis.csv").read_text(
        "utf-8"
    ).split("\n").index(NATIONAL_2024_EXTERNE) + 1
    code, lines = _anomalies(real_copy)
    assert code == 1
    assert lines == [
        (
            f"concours_admis_academie.csv:{line} [format] admis : "
            "« abc » n'est pas un entier"
        ),
        (
            f"concours_admis.csv:{national_2024} [recoupement] PE/2024/externe : "
            "postes : somme des académies hors Polynésie 7918 ≠ national 7917"
        ),
    ]


def test_c2_2_malformed_national_skips_its_key_only(real_copy: Path) -> None:
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,abc,43276,18387,7561,menj-crpe-2025",
    )
    national_2024 = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2024_EXTERNE,
        "PE,2024,externe,7917,42145,18385,6960,menj-crpe-2024",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"concours_admis.csv:{line} [format] postes : « abc » n'est pas un entier",
        (
            f"concours_admis.csv:{national_2024} [recoupement] PE/2024/externe : "
            "admis : somme des académies hors Polynésie 6961 ≠ national 6960"
        ),
    ]


# --- C2.3 : ordre des effectifs ----------------------------------------------


def test_c2_3_malformed_row_ignored_by_concours(real_copy: Path) -> None:
    """présents > inscrits sur la ligne : non signalé, la ligne est ignorée."""
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,8102,43276,50000,abc,menj-crpe-2025",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"concours_admis.csv:{line} [format] admis : « abc » n'est pas un entier"
    ]


def test_rows_after_a_malformed_one_still_checked(real_copy: Path) -> None:
    """La ligne mal formée (2024) précède une ligne fautive (2025), contrôlée."""
    line = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2024_EXTERNE,
        "PE,2024,externe,abc,42145,18385,6961,menj-crpe-2024",
    )
    faulty = _replace(
        real_copy,
        "concours_admis",
        NATIONAL_2025_EXTERNE,
        "PE,2025,externe,8102,43276,50000,7561,menj-crpe-2025",
    )
    _, lines = _anomalies(real_copy)
    assert lines == [
        f"concours_admis.csv:{line} [format] postes : « abc » n'est pas un entier",
        (
            f"concours_admis.csv:{faulty} [concours] PE/2025/externe : "
            "présents 50000 > inscrits 43276"
        ),
        (
            f"concours_admis.csv:{faulty} [recoupement] PE/2025/externe : "
            "presents : somme des académies hors Polynésie 18387 ≠ national 50000"
        ),
    ]

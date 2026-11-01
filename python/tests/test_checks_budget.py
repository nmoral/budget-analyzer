"""Cohérence ``python -m checks`` du dataset ``budget_financeur`` (critère C3)."""

import os
import shutil
from pathlib import Path

import pytest

from checks import collect_files, load_bounds, run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."

BUDGET_HEADER = "funder_code,value,unit,start_date,end_date,source"
COHERENCE = "[budget_financeur]\nvalue = { min = 0 }\n"
SOURCES_JSON = (
    '{"pap-2024-ens-scolaire": {"label": "x"}, '
    '"pap-2025-ens-scolaire": {"label": "x"}, '
    '"ondam-2024-ralfss": {"label": "x"}, '
    '"ondam-2025-ralfss": {"label": "x"}}'
)


@pytest.fixture
def data_root(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Data-dir isolé : schémas réels + dossier ``data`` vide à peupler."""
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    (tmp_path / "data").mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write(root: Path, name: str, *lines: str) -> None:
    (root / "data" / f"{name}.csv").write_text("\n".join(lines) + "\n", "utf-8")


def _write_config(root: Path, content: str = COHERENCE) -> None:
    (root / "coherence.toml").write_text(content, "utf-8")


def _write_sources(root: Path, content: str = SOURCES_JSON) -> None:
    (root / "sources.json").write_text(content, "utf-8")


# --- C3.1 : le dataset budget livré est valide -----------------------------


def test_real_budget_financeur_has_no_anomaly() -> None:  # C3.1
    code, report = run(REAL_DATA)
    assert code == 0
    assert report == NO_ANOMALY


def test_valid_budget_trajectory_passes(data_root: Path) -> None:  # C3.1
    _write_config(data_root)
    _write_sources(data_root)
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,26842958249,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
        "ETAT_P140,27490907364,EUR,2025-01-01,,pap-2025-ens-scolaire",
        "ONDAM,254900000000,EUR,2024-01-01,2024-12-31,ondam-2024-ralfss",
        "ONDAM,265900000000,EUR,2025-01-01,,ondam-2025-ralfss",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_collect_files_maps_budget_financeur(data_root: Path) -> None:
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,1,EUR,2024-01-01,,pap-2024-ens-scolaire",
    )
    by_name = {loaded.name: loaded for loaded in collect_files(data_root)}
    assert by_name["budget_financeur"].schema == "budget_financeur"
    assert by_name["budget_financeur"].has_source is True


# --- C3.2 : chevauchement de périodes du MÊME financeur --------------------


def test_overlapping_periods_same_funder_reported(data_root: Path) -> None:  # C3.2
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,100,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
        "ETAT_P140,200,EUR,2024-06-01,,pap-2025-ens-scolaire",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "budget_financeur.csv:3 [periodes] clé ETAT_P140 : périodes "
        "2024-01-01→2024-12-31 et 2024-06-01→∞ se chevauchent\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_periods_grouped_by_funder(data_root: Path) -> None:
    # Deux financeurs distincts aux dates recouvrantes : pas de chevauchement
    # inter-financeurs (le contrôle groupe par funder_code).
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,100,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
        "ONDAM,200,EUR,2024-06-01,2025-12-31,ondam-2024-ralfss",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


# --- C3.3 : valeur négative hors borne min --------------------------------


def test_negative_value_is_reported(data_root: Path) -> None:  # C3.3
    _write_config(data_root)
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,-5,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "budget_financeur.csv:2 [bornes] clé budget_financeur.value : "
        "valeur -5 sous la borne min 0\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_zero_value_is_within_bounds(data_root: Path) -> None:
    # Borne incluse : 0 est admis (min = 0).
    _write_config(data_root)
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,0,EUR,2024-01-01,,pap-2024-ens-scolaire",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_large_value_allowed_without_max(data_root: Path) -> None:
    # min seul (0) : aucun plafond, un gros montant passe, un négatif est rejeté.
    _write_config(data_root)
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,999999999999,EUR,2024-01-01,,pap-2024-ens-scolaire",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_budget_value_not_bounded_without_section(data_root: Path) -> None:
    # Liste blanche : sans section [budget_financeur], value n'est pas bornée.
    _write_config(data_root, "[parametre_date.key]\ntaux_cnracl = { min = 0 }\n")
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,-5,EUR,2024-01-01,,pap-2024-ens-scolaire",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_real_coherence_bound_for_budget_financeur() -> None:
    bound = load_bounds(REAL_DATA).budget_financeur_value
    assert bound is not None
    assert bound.minimum == 0
    assert bound.maximum == float("inf")


# --- C3.4 : source blanche -------------------------------------------------


def test_blank_source_is_reported(data_root: Path) -> None:  # C3.4
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,100,EUR,2024-01-01,2024-12-31,   ",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "budget_financeur.csv:2 [source] source blanche ou absente\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_unknown_source_is_reported(data_root: Path) -> None:
    _write_sources(data_root)
    _write(
        data_root,
        "budget_financeur",
        BUDGET_HEADER,
        "ETAT_P140,100,EUR,2024-01-01,2024-12-31,source-fantome",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "budget_financeur.csv:2 [source_ref] "
        "source « source-fantome » absente de sources.json\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )

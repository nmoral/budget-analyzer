"""Cohérence ``python -m checks`` du dataset ``masse_salariale_corps`` (critère C3)."""

import os
import shutil
from pathlib import Path

import pytest

from checks import collect_files, load_bounds, main, run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."

HEADER = "body_code,value,unit,start_date,end_date,source"
COHERENCE = "[masse_salariale_corps]\nvalue = { min = 0 }\n"
SOURCES_JSON = (
    '{"pap-2024-ens-scolaire-masse-1106": {"label": "x"}, '
    '"pap-2025-ens-scolaire-masse-1106": {"label": "x"}}'
)
LINE_2024 = "PE,15590703468,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106"
LINE_2025 = "PE,14994713610,EUR,2025-01-01,,pap-2025-ens-scolaire-masse-1106"


@pytest.fixture
def data_root(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Data-dir isolé : schémas réels + dossier ``data`` vide à peupler."""
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    (tmp_path / "data").mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write(root: Path, *lines: str) -> None:
    path = root / "data" / "masse_salariale_corps.csv"
    path.write_text("\n".join(lines) + "\n", "utf-8")


def _write_config(root: Path, content: str = COHERENCE) -> None:
    (root / "coherence.toml").write_text(content, "utf-8")


def _write_sources(root: Path) -> None:
    (root / "sources.json").write_text(SOURCES_JSON, "utf-8")


# --- C3.1 / C2.3 : le dataset livré (PE seul, sans ISG) est valide ----------


def test_real_data_has_no_anomaly(capsys: pytest.CaptureFixture[str]) -> None:
    """C3.1 + C2.3 — CSV réel (aucune ligne ISG) : `python -m checks` exit 0."""
    real_csv = REAL_DATA / "data" / "masse_salariale_corps.csv"
    assert real_csv.read_text("utf-8").splitlines() == [HEADER, LINE_2024, LINE_2025]
    assert main() == 0
    assert capsys.readouterr().out.strip() == NO_ANOMALY


def test_collect_files_maps_masse_salariale_corps(data_root: Path) -> None:
    _write(data_root, HEADER, LINE_2024)
    by_name = {loaded.name: loaded for loaded in collect_files(data_root)}
    assert by_name["masse_salariale_corps"].schema == "masse_salariale_corps"
    assert by_name["masse_salariale_corps"].has_source is True


def test_valid_trajectory_without_isg_passes(data_root: Path) -> None:
    """C2.3 — trajectoire PE contiguë, aucun ISG : pas d'anomalie."""
    _write_config(data_root)
    _write_sources(data_root)
    _write(data_root, HEADER, LINE_2024, LINE_2025)
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.2 : chevauchement de périodes du MÊME corps --------------------------


def test_overlapping_periods_same_body_reported(data_root: Path) -> None:  # C3.2
    _write(
        data_root,
        HEADER,
        "PE,100,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106",
        "PE,200,EUR,2024-06-01,,pap-2025-ens-scolaire-masse-1106",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "masse_salariale_corps.csv:3 [periodes] clé PE : périodes "
        "2024-01-01→2024-12-31 et 2024-06-01→∞ se chevauchent\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_periods_grouped_by_body_code(data_root: Path) -> None:
    # Deux corps distincts aux dates recouvrantes : pas de chevauchement inter-corps.
    _write(
        data_root,
        HEADER,
        "PE,100,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106",
        "ISG,200,EUR,2024-06-01,,pap-2025-ens-scolaire-masse-1106",
    )
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.3 : valeur négative hors borne min -----------------------------------


def test_negative_value_is_reported(data_root: Path) -> None:  # C3.3
    _write_config(data_root)
    _write(
        data_root,
        HEADER,
        "PE,-5,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "masse_salariale_corps.csv:2 [bornes] clé masse_salariale_corps.value : "
        "valeur -5 sous la borne min 0\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_negative_value_reported_after_other_files(data_root: Path) -> None:
    # Les fichiers contrôlés avant (parametre_date, schéma non borné) n'arrêtent
    # pas le contrôle des bornes des fichiers suivants.
    _write_config(data_root)
    (data_root / "data" / "point_indice.csv").write_text(
        "key,value,unit,start_date,end_date,source\n"
        "point_indice,59.0734,EUR_PER_YEAR,2023-07-01,,decret-2023-519\n",
        "utf-8",
    )
    (data_root / "data" / "taux_primes.csv").write_text(
        "body_code,value,observation_year,source,fallback\n"
        "PE,0.21,2023,dgafp-ra-2025,false\n",
        "utf-8",
    )
    _write(data_root, HEADER, "PE,-5,EUR,2024-01-01,,pap-2024-ens-scolaire-masse-1106")
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "masse_salariale_corps.csv:2 [bornes] clé masse_salariale_corps.value : "
        "valeur -5 sous la borne min 0\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_zero_value_is_within_bounds(data_root: Path) -> None:
    _write_config(data_root)
    _write(data_root, HEADER, "PE,0,EUR,2024-01-01,,pap-2024-ens-scolaire-masse-1106")
    assert run(data_root) == (0, NO_ANOMALY)


def test_value_not_bounded_without_section(data_root: Path) -> None:
    # Liste blanche : sans section [masse_salariale_corps], value n'est pas bornée.
    _write_config(data_root, "[budget_financeur]\nvalue = { min = 0 }\n")
    _write(data_root, HEADER, "PE,-5,EUR,2024-01-01,,pap-2024-ens-scolaire-masse-1106")
    assert run(data_root) == (0, NO_ANOMALY)


def test_real_coherence_bound_for_masse_salariale_corps() -> None:
    bound = load_bounds(REAL_DATA).masse_salariale_corps_value
    assert bound is not None
    assert bound.minimum == 0
    assert bound.maximum == float("inf")


# --- C3.4 : source blanche ---------------------------------------------------


def test_blank_source_is_reported(data_root: Path) -> None:  # C3.4
    _write(data_root, HEADER, "PE,100,EUR,2024-01-01,2024-12-31,")
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "masse_salariale_corps.csv:2 [source] source blanche ou absente\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )

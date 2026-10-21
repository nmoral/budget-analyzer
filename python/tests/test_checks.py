"""Contrôles de cohérence métier (``python -m checks``) : squelette + C4 (source)."""

import os
import runpy
import shutil
from pathlib import Path

import pytest

from checks import collect_files, main, run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."

PARAMETRE_HEADER = "key,value,unit,start_date,end_date,source"
TAUX_PRIMES_HEADER = "body_code,value,observation_year,source,fallback"
ECHELON_HEADER = "grille,rank,gross_index,increased_index,duration_months"


@pytest.fixture
def data_root(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Data-dir isolé : schémas réels + dossier ``data`` vide à peupler."""
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    (tmp_path / "data").mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write(root: Path, name: str, *lines: str) -> None:
    (root / "data" / f"{name}.csv").write_text("\n".join(lines) + "\n", "utf-8")


# --- C5.1 : jeu valide (données réelles) -----------------------------------


def test_real_data_has_no_anomaly(data_root: Path) -> None:
    code, report = run(REAL_DATA)
    assert code == 0
    assert report == NO_ANOMALY


def test_cli_entrypoint_exit_zero_on_real_data(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setenv("BUDGET_DATA_DIR", str(REAL_DATA))
    with pytest.raises(SystemExit) as exc:
        runpy.run_module("checks", run_name="__main__")
    assert exc.value.code == 0
    assert NO_ANOMALY in capsys.readouterr().out


# --- C4.1 : source renseignée → aucune violation ---------------------------


def test_filled_sources_pass(data_root: Path) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


# --- C4.2 : source blanche → violation (fichier, ligne) --------------------


def test_blank_source_is_reported(data_root: Path) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,   ",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_cas_civil.csv:2 [source] source blanche ou absente\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_source_column_absent_schema_is_not_checked(data_root: Path) -> None:
    # echelon n'a pas de colonne source : aucune violation même si on la cherchait.
    _write(
        data_root,
        "echelon_pe",
        "grille,rank,gross_index,increased_index,duration_months",
        "PE_classe_normale_2024,1,444,395,12",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


# --- C5.2 : un seul CSV fautif → exit 1, violation listée ------------------


def test_single_faulty_file_exits_one(data_root: Path) -> None:
    _write(
        data_root,
        "taux_primes",
        TAUX_PRIMES_HEADER,
        "PE,0.21,2023, ,false",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_primes.csv:2 [source] source blanche ou absente\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


# --- C5.3 : deux CSV fautifs → exit 1, les deux violations listées ---------


def test_all_violations_reported_without_stopping(data_root: Path) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,   ",
    )
    _write(
        data_root,
        "taux_cnracl",
        PARAMETRE_HEADER,
        "taux_cnracl,37.65,PCT_TIB,2026-01-01,2026-12-31,",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_cas_civil.csv:2 [source] source blanche ou absente\n"
        "taux_cnracl.csv:2 [source] source blanche ou absente\n"
        "Récapitulatif : 2 anomalie(s) détectée(s)."
    )


def test_violation_found_after_a_source_less_file(data_root: Path) -> None:
    # echelon_pe (sans colonne source) précède grille_pe fautif : la boucle
    # doit poursuivre au-delà du fichier ignoré, pas s'arrêter.
    _write(
        data_root,
        "echelon_pe",
        "grille,rank,gross_index,increased_index,duration_months",
        "PE_classe_normale_2024,1,444,395,12",
    )
    _write(
        data_root,
        "grille_pe",
        "body_code,grade,version,start_date,end_date,source",
        "PE,classe_normale,2024,2024-01-01,,   ",
    )
    code, report = run(data_root)
    assert code == 1
    assert "grille_pe.csv:2 [source] source blanche ou absente" in report


def test_collect_files_maps_filenames_to_schemas(data_root: Path) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
    )
    _write(
        data_root,
        "grille_pe",
        "body_code,grade,version,start_date,end_date,source",
        "PE,classe_normale,2024,2024-01-01,,decret-2017-789",
    )
    by_name = {loaded.name: loaded for loaded in collect_files(data_root)}
    assert by_name["taux_cas_civil"].schema == "parametre_date"
    assert by_name["grille_pe"].schema == "grille"


def test_missing_csv_schemas_are_ignored(data_root: Path) -> None:
    # Seul couts_pap présent : les autres schémas mappés sont ignorés sans erreur.
    _write(
        data_root,
        "couts_pap",
        "program,employment_category,average_loaded_cost,year,document,page,url",
        "140,enseignant,46139,2025,PAP,49,https://example.org",
    )
    code, report = run(data_root)
    assert code == 0
    assert "aucune anomalie" in report


# --- main() : point d'entrée imprime le rapport et renvoie le code ---------


def test_main_prints_report_and_returns_code(
    data_root: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,   ",
    )
    code = main()
    printed = capsys.readouterr().out
    assert code == 1
    assert "taux_cas_civil.csv" in printed


def test_main_returns_zero_when_clean(
    data_root: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
    )
    code = main()
    printed = capsys.readouterr().out
    assert code == 0
    assert "aucune anomalie" in printed


def test_cli_entrypoint_exit_one_on_blank_source(
    data_root: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,   ",
    )
    with pytest.raises(SystemExit) as exc:
        runpy.run_module("checks", run_name="__main__")
    assert exc.value.code == 1
    assert "taux_cas_civil.csv" in capsys.readouterr().out


# --- C1 : indices strictement croissants par rang (schéma echelon) ---------


def test_echelon_strictly_increasing_passes(data_root: Path) -> None:  # C1.1
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,444,395,12",
        "PE_cn_2024,2,513,446,12",
        "PE_cn_2024,3,523,453,24",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_increased_index_equal_is_reported(data_root: Path) -> None:  # C1.2
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,100,50,12",
        "PE_cn_2024,2,110,50,12",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "echelon_pe.csv:3 [indices] grille PE_cn_2024 rang 2 : "
        "increased_index non strictement croissant (50 <= 50)\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_gross_index_decrease_is_reported(data_root: Path) -> None:  # C1.3
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,100,50,12",
        "PE_cn_2024,2,90,60,12",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "echelon_pe.csv:3 [indices] grille PE_cn_2024 rang 2 : "
        "gross_index non strictement croissant (90 <= 100)\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_echelon_rows_ordered_by_rank_not_file_order(data_root: Path) -> None:
    # Lignes en désordre dans le fichier mais croissantes par rang : aucune
    # violation (le contrôle ordonne par rang, pas par position dans le CSV).
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,3,523,453,24",
        "PE_cn_2024,1,444,395,12",
        "PE_cn_2024,2,513,446,12",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_each_grille_checked_independently(data_root: Path) -> None:
    # Deux grilles dans un même fichier : seule la fautive est rapportée.
    _write(
        data_root,
        "echelon_isg",
        ECHELON_HEADER,
        "ISG_g1_2024,1,444,395,12",
        "ISG_g1_2024,2,484,424,18",
        "ISG_g2_2024,1,500,450,12",
        "ISG_g2_2024,2,480,470,12",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "echelon_isg.csv:5 [indices] grille ISG_g2_2024 rang 2 : "
        "gross_index non strictement croissant (480 <= 500)\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_all_echelon_violations_reported(data_root: Path) -> None:
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,100,50,12",
        "PE_cn_2024,2,100,60,12",
        "PE_cn_2024,3,110,60,12",
    )
    code, report = run(data_root)
    assert code == 1
    assert "PE_cn_2024 rang 2 : gross_index non strictement croissant (100 <= 100)" in (
        report
    )
    assert (
        "PE_cn_2024 rang 3 : increased_index non strictement croissant (60 <= 60)"
        in report
    )
    assert "Récapitulatif : 2 anomalie(s) détectée(s)." in report


def test_echelon_checked_even_after_a_non_echelon_file(data_root: Path) -> None:
    # Un fichier non-echelon précède l'echelon fautif dans l'ordre de parcours :
    # le contrôle doit poursuivre jusqu'à l'echelon, pas s'arrêter avant.
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
    )
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,100,50,12",
        "PE_cn_2024,2,90,60,12",
    )
    code, report = run(data_root)
    assert code == 1
    assert "echelon_pe.csv:3 [indices] grille PE_cn_2024 rang 2 : gross_index" in report

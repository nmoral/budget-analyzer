"""Contrôles de cohérence métier (``python -m checks``) : squelette + C4 (source)."""

import os
import runpy
import shutil
from pathlib import Path

import pytest

from checks import collect_files, load_bounds, main, run

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


# --- C2 : non-chevauchement des périodes d'effet d'une même clé ------------

GRILLE_HEADER = "body_code,grade,version,start_date,end_date,source"


def test_adjacent_periods_do_not_overlap(data_root: Path) -> None:  # C2.1
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
        "taux_cas_civil,82.28,PCT_TIB,2026-01-01,,retraites-etat-2026",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_overlapping_periods_same_key_reported(data_root: Path) -> None:  # C2.2
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026",
        "taux_cas_civil,82.28,PCT_TIB,2025-06-01,,retraites-etat-2026",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_cas_civil.csv:3 [periodes] clé taux_cas_civil : périodes "
        "2025-01-01→2025-12-31 et 2025-06-01→∞ se chevauchent\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_overlapping_grille_versions_reported(data_root: Path) -> None:  # C2.3
    _write(
        data_root,
        "grille_pe",
        GRILLE_HEADER,
        "PE,classe_normale,2024,2024-01-01,2024-12-31,decret-2017-789",
        "PE,classe_normale,2025,2024-06-01,,decret-2017-789",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "grille_pe.csv:3 [periodes] clé PE/classe_normale : périodes "
        "2024-01-01→2024-12-31 et 2024-06-01→∞ se chevauchent\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_same_day_boundary_is_one_day_overlap(data_root: Path) -> None:
    # Fin le jour J et début le même jour J : un jour commun => chevauchement.
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,src",
        "taux_cas_civil,82.28,PCT_TIB,2025-12-31,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert "2025-01-01→2025-12-31 et 2025-12-31→∞ se chevauchent" in report


def test_open_ended_earlier_period_overlaps_later(data_root: Path) -> None:
    # Période ouverte (end_date vide) couvrant une période ultérieure bornée.
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,,src",
        "taux_cas_civil,82.28,PCT_TIB,2026-01-01,2026-12-31,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert "2025-01-01→∞ et 2026-01-01→2026-12-31 se chevauchent" in report


def test_periods_grouped_by_key(data_root: Path) -> None:
    # Deux clés distinctes aux dates recouvrantes : pas de chevauchement
    # inter-clés (le contrôle groupe par key).
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "key_a,1,PCT_TIB,2025-01-01,2025-12-31,src",
        "key_b,2,PCT_TIB,2025-06-01,2026-12-31,src",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_grille_periods_grouped_by_body_and_grade(data_root: Path) -> None:
    # Grades différents du même corps : pas de chevauchement inter-grades.
    _write(
        data_root,
        "grille_pe",
        GRILLE_HEADER,
        "PE,classe_normale,2024,2024-01-01,2024-12-31,src",
        "PE,hors_classe,2024,2024-06-01,2025-12-31,src",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_all_period_overlaps_reported(data_root: Path) -> None:
    _write(
        data_root,
        "taux_cnracl",
        PARAMETRE_HEADER,
        "taux_cnracl,1,PCT_TIB,2025-01-01,2025-12-31,src",
        "taux_cnracl,2,PCT_TIB,2025-06-01,2026-12-31,src",
        "taux_cnracl,3,PCT_TIB,2026-06-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert "2025-01-01→2025-12-31 et 2025-06-01→2026-12-31 se chevauchent" in report
    assert "2025-06-01→2026-12-31 et 2026-06-01→∞ se chevauchent" in report
    assert "Récapitulatif : 2 anomalie(s) détectée(s)." in report


def test_period_ending_on_another_start_day_overlaps(data_root: Path) -> None:
    # Une période d'un seul jour (J→J) partageant le jour de début d'une autre
    # période : un jour commun => chevauchement (bornes incluses).
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,src",
        "taux_cas_civil,82.28,PCT_TIB,2025-01-01,2025-01-01,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert "2025-01-01→2025-12-31 et 2025-01-01→2025-01-01 se chevauchent" in report


# --- C3 : bornes min/max (liste blanche data/coherence.toml) ----------------

DEFAULT_COHERENCE = (
    "[parametre_date.key]\n"
    "taux_cas_civil = { min = 0, max = 200 }\n"
    "taux_cas_militaire = { min = 0, max = 200 }\n"
    "taux_cnracl = { min = 0, max = 200 }\n"
    "point_indice = { min = 0 }\n"
    "[taux_primes]\n"
    "value = { min = 0, max = 1 }\n"
)


def _write_config(root: Path, content: str = DEFAULT_COHERENCE) -> None:
    (root / "coherence.toml").write_text(content, "utf-8")


def test_rate_above_100_percent_within_bounds(data_root: Path) -> None:  # C3.1
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_militaire",
        PARAMETRE_HEADER,
        "taux_cas_militaire,126.07,PCT_TIB,2025-01-01,,an-rapport-cas-pensions",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_rate_above_max_is_reported(data_root: Path) -> None:  # C3.2
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,250,PCT_TIB,2025-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_cas_civil.csv:2 [bornes] clé taux_cas_civil : "
        "valeur 250 au-dessus de la borne max 200\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_rate_below_min_is_reported(data_root: Path) -> None:  # C3.3
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,-5,PCT_TIB,2025-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_cas_civil.csv:2 [bornes] clé taux_cas_civil : "
        "valeur -5 sous la borne min 0\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_taux_primes_ratio_above_one_is_reported(data_root: Path) -> None:  # C3.4
    _write_config(data_root)
    _write(
        data_root,
        "taux_primes",
        TAUX_PRIMES_HEADER,
        "PE,1.5,2023,dgafp,false",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_primes.csv:2 [bornes] clé taux_primes.value : "
        "valeur 1.5 au-dessus de la borne max 1\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_taux_primes_ratio_within_bounds_passes(data_root: Path) -> None:
    _write_config(data_root)
    _write(
        data_root,
        "taux_primes",
        TAUX_PRIMES_HEADER,
        "PE,0.21,2023,dgafp,false",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_bounds_are_inclusive(data_root: Path) -> None:
    # min et max sont admis (bornes incluses) : 0 et 200 ne violent pas [0 ; 200].
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,0,PCT_TIB,2024-01-01,2024-12-31,src",
        "taux_cas_civil,200,PCT_TIB,2025-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_unlisted_key_is_not_bounded(data_root: Path) -> None:
    # Liste blanche : une clé parametre_date absente de la config n'est pas bornée,
    # même avec une valeur qui dépasserait des bornes usuelles.
    _write_config(data_root)
    _write(
        data_root,
        "point_indice",  # fichier parametre_date hébergeant une clé non listée
        PARAMETRE_HEADER,
        "clé_hors_liste,9999,EUR_PER_YEAR,2023-07-01,,src",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_min_only_bound_allows_large_value_but_rejects_negative(
    data_root: Path,
) -> None:
    # point_indice n'a qu'une borne min (0) : une grande valeur passe, une valeur
    # négative viole la borne min.
    _write_config(data_root)
    _write(
        data_root,
        "point_indice",
        PARAMETRE_HEADER,
        "point_indice,99999,EUR_PER_YEAR,2020-01-01,2022-12-31,src",
        "point_indice,-1,EUR_PER_YEAR,2023-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "point_indice.csv:3 [bornes] clé point_indice : "
        "valeur -1 sous la borne min 0\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_max_only_bound_rejects_above_without_min(data_root: Path) -> None:
    # Config où une clé n'a qu'une borne max : pas de plancher, plafond respecté.
    _write_config(
        data_root,
        "[parametre_date.key]\nplafond = { max = 10 }\n",
    )
    _write(
        data_root,
        "point_indice",
        PARAMETRE_HEADER,
        "plafond,-100,EUR_PER_YEAR,2020-01-01,2022-12-31,src",
        "plafond,20,EUR_PER_YEAR,2023-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "point_indice.csv:3 [bornes] clé plafond : "
        "valeur 20 au-dessus de la borne max 10\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_taux_primes_not_configured_is_skipped(data_root: Path) -> None:
    # Config sans section taux_primes : la colonne value n'est pas bornée.
    _write_config(data_root, "[parametre_date.key]\ntaux_cnracl = { min = 0 }\n")
    _write(
        data_root,
        "taux_primes",
        TAUX_PRIMES_HEADER,
        "PE,1.5,2023,dgafp,false",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_missing_config_bounds_nothing(data_root: Path) -> None:
    # Aucun coherence.toml : liste blanche vide, rien n'est borné.
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,250,PCT_TIB,2025-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_c3_bounds_loaded_from_run_root_not_env(
    data_root: Path,
    tmp_path_factory: pytest.TempPathFactory,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # R1 : les bornes C3 proviennent de la racine passée à run(), pas de
    # BUDGET_DATA_DIR. On fait pointer l'env sur une racine SANS coherence.toml :
    # la violation doit tout de même être détectée (bornes lues depuis data_root).
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,250,PCT_TIB,2025-01-01,,src",
    )
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path_factory.mktemp("no_config")))
    code, report = run(data_root)
    assert code == 1
    assert "valeur 250 au-dessus de la borne max 200" in report


def test_all_bound_violations_reported(data_root: Path) -> None:
    _write_config(data_root)
    _write(
        data_root,
        "taux_cas_civil",
        PARAMETRE_HEADER,
        "taux_cas_civil,250,PCT_TIB,2025-01-01,2025-12-31,src",
        "taux_cas_civil,-5,PCT_TIB,2026-01-01,,src",
    )
    code, report = run(data_root)
    assert code == 1
    assert "valeur 250 au-dessus de la borne max 200" in report
    assert "valeur -5 sous la borne min 0" in report
    assert "Récapitulatif : 2 anomalie(s) détectée(s)." in report


# --- #49 C2.2 : nouvelles clés bornées min 0 / max 100 ----------------------

AUTRES_COHERENCE = (
    "[parametre_date.key]\n"
    "taux_autres_cotisations_fpe = { min = 0, max = 100 }\n"
    "taux_autres_cotisations_fph = { min = 0, max = 100 }\n"
    "taux_rafp = { min = 0, max = 100 }\n"
)


def test_autres_cotisations_value_above_max_is_reported(data_root: Path) -> None:
    # C2.2 : une valeur à 150 (> max 100) sur une clé #49 => exit 1 nommant la clé.
    _write_config(data_root, AUTRES_COHERENCE)
    _write(
        data_root,
        "taux_autres_cotisations_fph",
        PARAMETRE_HEADER,
        "taux_autres_cotisations_fph,150,PCT_TIB,2026-01-01,,cotisations-fph-2026",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_autres_cotisations_fph.csv:2 [bornes] clé taux_autres_cotisations_fph : "
        "valeur 150 au-dessus de la borne max 100\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_real_coherence_bounds_for_new_keys(data_root: Path) -> None:
    # Le coherence.toml réel borne les 3 clés #49 à [0 ; 100].
    bounds = load_bounds(REAL_DATA).parametre_date_by_key
    for key in (
        "taux_autres_cotisations_fpe",
        "taux_autres_cotisations_fph",
        "taux_rafp",
    ):
        assert bounds[key].minimum == 0
        assert bounds[key].maximum == 100


# --- #49 C2.3 : code source inconnu => le contrôle refuse -------------------

SOURCES_JSON = '{"cotisations-fpe-2026": {"label": "x", "fileHash": "deadbeef"}}'


def _write_sources(root: Path, content: str = SOURCES_JSON) -> None:
    (root / "sources.json").write_text(content, "utf-8")


def test_unknown_source_code_is_reported(data_root: Path) -> None:
    # C2.3 : un code source absent de sources.json => violation nommant le code.
    _write_sources(data_root)
    _write(
        data_root,
        "taux_autres_cotisations_fpe",
        PARAMETRE_HEADER,
        "taux_autres_cotisations_fpe,16.07,PCT_TIB,2026-01-01,,code-fantome",
    )
    code, report = run(data_root)
    assert code == 1
    assert report == (
        "taux_autres_cotisations_fpe.csv:2 [source_ref] "
        "source « code-fantome » absente de sources.json\n"
        "Récapitulatif : 1 anomalie(s) détectée(s)."
    )


def test_declared_source_code_passes(data_root: Path) -> None:
    _write_sources(data_root)
    _write(
        data_root,
        "taux_autres_cotisations_fpe",
        PARAMETRE_HEADER,
        "taux_autres_cotisations_fpe,16.07,PCT_TIB,2026-01-01,,cotisations-fpe-2026",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_source_declaration_skipped_without_registry(data_root: Path) -> None:
    # Sans sources.json : la référence de source n'est pas contrôlée (no-op).
    _write(
        data_root,
        "taux_autres_cotisations_fpe",
        PARAMETRE_HEADER,
        "taux_autres_cotisations_fpe,16.07,PCT_TIB,2026-01-01,,code-fantome",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY


def test_blank_source_not_double_reported_by_source_ref(data_root: Path) -> None:
    # Une source blanche est signalée par [source], pas par [source_ref].
    _write_sources(data_root)
    _write(
        data_root,
        "taux_autres_cotisations_fpe",
        PARAMETRE_HEADER,
        "taux_autres_cotisations_fpe,16.07,PCT_TIB,2026-01-01,,   ",
    )
    code, report = run(data_root)
    assert code == 1
    assert "[source] source blanche ou absente" in report
    assert "[source_ref]" not in report
    assert "Récapitulatif : 1 anomalie(s) détectée(s)." in report


def test_source_ref_skips_schema_without_source_column(data_root: Path) -> None:
    # Un schéma sans colonne source (echelon) n'est pas contrôlé par source_ref.
    _write_sources(data_root)
    _write(
        data_root,
        "echelon_pe",
        ECHELON_HEADER,
        "PE_cn_2024,1,444,395,12",
    )
    code, report = run(data_root)
    assert code == 0
    assert report == NO_ANOMALY

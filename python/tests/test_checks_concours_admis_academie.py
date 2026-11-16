"""Cohérence ``python -m checks`` du dataset ``concours_admis_academie`` (C3)."""

import json
import os
import shutil
from pathlib import Path

import pytest

from checks import (
    academie_codes,
    collect_files,
    load_academie_entries,
    main,
    run,
)

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."
ONE_ANOMALY = "Récapitulatif : 1 anomalie(s) détectée(s)."

HEADER = "body_code,session,voie,academie,postes,inscrits,presents,admis,source"
NATIONAL_HEADER = "body_code,session,voie,postes,inscrits,presents,admis,source"
SOURCE = "menj-crpe-2025-academies"
SOURCES_JSON = json.dumps({SOURCE: {"label": "x"}, "menj-crpe-2025": {"label": "x"}})
ACADEMIES_JSON = json.dumps(
    {
        "32": {"label": "Guadeloupe", "statut": "drom", "source": "x"},
        "41": {"label": "Polynésie Française", "statut": "com", "source": "x"},
        "70": {"label": "Normandie", "statut": "metropole", "source": "x"},
    }
)
GUADELOUPE = f"PE,2025,externe,32,26,442,165,26,{SOURCE}"
NORMANDIE = f"PE,2025,externe,70,100,1000,400,90,{SOURCE}"
POLYNESIE = f"PE,2025,externe,41,15,249,137,15,{SOURCE}"
REAL_GUADELOUPE_2025 = "PE,2025,externe,32,26,442,165,26,menj-crpe-2025-academies"


@pytest.fixture
def data_root(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Data-dir isolé : schémas réels, référentiels réduits, ``data`` vide."""
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    (tmp_path / "data").mkdir()
    (tmp_path / "sources.json").write_text(SOURCES_JSON, "utf-8")
    (tmp_path / "academies.json").write_text(ACADEMIES_JSON, "utf-8")
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


@pytest.fixture
def real_copy(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Copie intégrale des données réelles (CSV, schémas, référentiels)."""
    for sub in ("schemas", "data"):
        shutil.copytree(REAL_DATA / sub, tmp_path / sub)
    for name in ("sources.json", "academies.json", "coherence.toml"):
        shutil.copy(REAL_DATA / name, tmp_path / name)
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write(root: Path, *lines: str) -> None:
    path = root / "data" / "concours_admis_academie.csv"
    path.write_text("\n".join((HEADER, *lines)) + "\n", "utf-8")


def _write_national(root: Path, *lines: str) -> None:
    path = root / "data" / "concours_admis.csv"
    path.write_text("\n".join((NATIONAL_HEADER, *lines)) + "\n", "utf-8")


def _report_of(root: Path) -> tuple[int, list[str]]:
    code, report = run(root)
    return code, report.splitlines()


def _replace(path: Path, old: str, new: str) -> None:
    content = path.read_text("utf-8")
    assert content.count(old) == 1, old
    path.write_text(content.replace(old, new), "utf-8")


# --- C3.1 : données réelles --------------------------------------------------


def test_c3_1_real_data_has_no_anomaly(capsys: pytest.CaptureFixture[str]) -> None:
    lines = (REAL_DATA / "data" / "concours_admis_academie.csv").read_text("utf-8")
    assert REAL_GUADELOUPE_2025 in lines.splitlines()
    assert main() == 0
    assert capsys.readouterr().out.strip() == NO_ANOMALY


def test_collect_files_maps_concours_admis_academie(data_root: Path) -> None:
    _write(data_root, GUADELOUPE)
    by_name = {loaded.name: loaded for loaded in collect_files(data_root)}
    loaded = by_name["concours_admis_academie"]
    assert loaded.schema == "concours_admis_academie"
    assert loaded.has_source is True


def test_valid_rows_pass(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, NORMANDIE, POLYNESIE)
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.2 : recoupement avec la ligne nationale --------------------------------


def test_c3_2_real_academy_plus_one_admis_breaks_cross_check(real_copy: Path) -> None:
    """C3.2 — une académie 2025 externe +1 admis : exit 1, clé citée."""
    _replace(
        real_copy / "data" / "concours_admis_academie.csv",
        REAL_GUADELOUPE_2025,
        "PE,2025,externe,32,26,442,165,27,menj-crpe-2025-academies",
    )
    code, lines = _report_of(real_copy)
    assert code == 1
    national = (real_copy / "data" / "concours_admis.csv").read_text("utf-8")
    line = national.splitlines().index(
        "PE,2025,externe,8102,43276,18387,7561,menj-crpe-2025"
    )
    assert lines == [
        (
            f"concours_admis.csv:{line + 1} [recoupement] PE/2025/externe : "
            "admis : somme des académies hors Polynésie 7562 ≠ national 7561"
        ),
        ONE_ANOMALY,
    ]


def test_cross_check_excludes_polynesie(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, NORMANDIE, POLYNESIE)
    _write_national(data_root, "PE,2025,externe,126,1442,565,116,menj-crpe-2025")
    assert run(data_root) == (0, NO_ANOMALY)


def test_cross_check_reports_every_differing_count(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, NORMANDIE)
    _write_national(data_root, "PE,2025,externe,127,1443,566,116,menj-crpe-2025")
    code, lines = _report_of(data_root)
    assert code == 1
    prefix = "concours_admis.csv:2 [recoupement] PE/2025/externe : "
    assert lines == [
        f"{prefix}postes : somme des académies hors Polynésie 126 ≠ national 127",
        f"{prefix}inscrits : somme des académies hors Polynésie 1442 ≠ national 1443",
        f"{prefix}presents : somme des académies hors Polynésie 565 ≠ national 566",
        "Récapitulatif : 3 anomalie(s) détectée(s).",
    ]


def test_national_key_without_academy_rows_is_reported(data_root: Path) -> None:
    _write(data_root, GUADELOUPE)
    _write_national(
        data_root,
        "PE,2025,externe,26,442,165,26,menj-crpe-2025",
        "PE,2025,troisieme,1,1,1,1,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    prefix = "concours_admis.csv:3 [recoupement] PE/2025/troisieme : "
    assert lines[0] == (
        f"{prefix}postes : somme des académies hors Polynésie 0 ≠ national 1"
    )
    assert len(lines) == 5


def test_cross_check_skipped_without_academy_file(data_root: Path) -> None:
    _write_national(data_root, "PE,2025,externe,26,442,165,26,menj-crpe-2025")
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.3 : académie inconnue -------------------------------------------------


def test_c3_3_unknown_academie_reported_with_line(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, f"PE,2025,externe,ZZ,1,1,1,1,{SOURCE}")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis_academie.csv:3 [academie] PE/2025/externe/ZZ : "
            "académie « ZZ » absente de academies.json"
        ),
        ONE_ANOMALY,
    ]


def test_unknown_academie_reported_alongside_national_file(data_root: Path) -> None:
    """Le fichier national, chargé avant, n'interrompt pas le contrôle."""
    _write(data_root, GUADELOUPE, f"PE,2025,troisieme,ZZ,0,0,0,0,{SOURCE}")
    _write_national(
        data_root,
        "PE,2025,externe,26,442,165,26,menj-crpe-2025",
        "PE,2025,troisieme,0,0,0,0,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis_academie.csv:3 [academie] PE/2025/troisieme/ZZ : "
            "académie « ZZ » absente de academies.json"
        ),
        ONE_ANOMALY,
    ]


def test_academie_not_checked_without_referential(data_root: Path) -> None:
    (data_root / "academies.json").unlink()
    _write(data_root, f"PE,2025,externe,ZZ,1,1,1,1,{SOURCE}")
    assert run(data_root) == (0, NO_ANOMALY)


def test_academie_codes_reads_referential_codes(data_root: Path) -> None:
    entries = load_academie_entries(data_root)
    assert academie_codes(entries) == frozenset({"32", "41", "70"})
    (data_root / "academies.json").unlink()
    assert academie_codes(load_academie_entries(data_root)) is None


# --- C3.4 : doublon / incohérence / négatif / source blanche -------------------


def test_c3_4_duplicate_key_reported_on_second_line(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, NORMANDIE, GUADELOUPE)
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis_academie.csv:4 [unicite] PE/2025/externe/32 : "
            "doublon de la ligne 2"
        ),
        ONE_ANOMALY,
    ]


def test_same_academy_other_voie_is_not_a_duplicate(data_root: Path) -> None:
    _write(data_root, GUADELOUPE, f"PE,2025,troisieme,32,26,442,165,26,{SOURCE}")
    assert run(data_root) == (0, NO_ANOMALY)


def test_c3_4_admis_above_presents_reported(data_root: Path) -> None:
    _write(data_root, f"PE,2025,externe,32,26,442,165,166,{SOURCE}")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis_academie.csv:2 [concours] PE/2025/externe/32 : "
            "admis 166 > présents 165"
        ),
        ONE_ANOMALY,
    ]


def test_c3_4_presents_above_inscrits_reported(data_root: Path) -> None:
    _write(data_root, f"PE,2025,externe,32,26,442,443,26,{SOURCE}")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis_academie.csv:2 [concours] PE/2025/externe/32 : "
            "présents 443 > inscrits 442"
        ),
        ONE_ANOMALY,
    ]


def test_c3_4_negative_count_reported(data_root: Path) -> None:
    _write(data_root, f"PE,2025,externe,32,-1,442,165,26,{SOURCE}")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        "concours_admis_academie.csv:2 [concours] PE/2025/externe/32 : postes -1 < 0",
        ONE_ANOMALY,
    ]


def test_c3_4_blank_source_reported(data_root: Path) -> None:
    _write(data_root, "PE,2025,externe,32,26,442,165,26,  ")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        "concours_admis_academie.csv:2 [source] source blanche ou absente",
        ONE_ANOMALY,
    ]


def test_admis_above_postes_accepted(data_root: Path) -> None:
    _write(data_root, f"PE,2021,externe,70,115,2125,604,116,{SOURCE}")
    assert run(data_root) == (0, NO_ANOMALY)

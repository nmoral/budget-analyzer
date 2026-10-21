import json
import os
import shutil
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


# --- C1.1 : grille PE nominale ---------------------------------------------
def test_grille_pe_holds_entry_grade(data_dir: Path) -> None:
    from sources.grille_pe import run

    out = run()
    content = out.read_text("utf-8")
    assert "PE,classe_normale,2024,2024-01-01,,decret-2017-789" in content
    assert validate_csv(out, "grille").valid


# --- C2.1 : échelon PE, 1er rang -------------------------------------------
def test_echelon_pe_first_rank(data_dir: Path) -> None:
    from sources.echelon_pe import run

    out = run()
    content = out.read_text("utf-8")
    assert "PE_classe_normale_2024,1,444,395,12" in content
    assert validate_csv(out, "echelon").valid


# --- C2.2 : échelon PE, dernier rang (durée vide), 11 rangs contigus --------
def test_echelon_pe_last_rank_and_contiguous(data_dir: Path) -> None:
    from sources.echelon_pe import run

    out = run()
    lines = out.read_text("utf-8").splitlines()
    assert "PE_classe_normale_2024,11,821,678," in lines
    data_lines = lines[1:]  # sans l'en-tête
    assert len(data_lines) == 11
    ranks = [int(line.split(",")[1]) for line in data_lines]
    assert ranks == list(range(1, 12))
    assert validate_csv(out, "echelon").valid


# --- C3.1 : les deux CSV PE présents, grade classe_normale -----------------
def test_pe_csv_files_present_with_grade(data_dir: Path) -> None:
    from sources.echelon_pe import run as run_echelon
    from sources.grille_pe import run as run_grille

    grille_out = run_grille()
    echelon_out = run_echelon()
    assert (data_dir / "data" / "grille_pe.csv").exists()
    assert (data_dir / "data" / "echelon_pe.csv").exists()
    assert "classe_normale" in grille_out.read_text("utf-8")
    assert "PE_classe_normale_2024" in echelon_out.read_text("utf-8")


# --- C4.1 : manifestes vérifiables, source décret 2017-789 -----------------
def test_grille_pe_manifest_is_verifiable(data_dir: Path) -> None:
    from sources.grille_pe import run

    run()
    assert verify_manifest("grille_pe") is True
    manifest = json.loads((data_dir / "manifests" / "grille_pe.json").read_text())
    assert manifest["source"] == "Décret n° 2017-789 du 4 mai 2017"
    assert manifest["url"] == (
        "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000034601473"
    )
    assert manifest["retrieved_at"] == "2026-10-21"
    assert manifest["script"] == "sources.grille_pe"
    assert manifest["script_version"] == "1"


def test_echelon_pe_manifest_is_verifiable(data_dir: Path) -> None:
    from sources.echelon_pe import run

    run()
    assert verify_manifest("echelon_pe") is True
    manifest = json.loads((data_dir / "manifests" / "echelon_pe.json").read_text())
    assert manifest["source"] == "Décret n° 2017-789 du 4 mai 2017"
    assert manifest["url"] == (
        "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000034601473"
    )
    assert manifest["retrieved_at"] == "2026-10-21"
    assert manifest["script"] == "sources.echelon_pe"
    assert manifest["script_version"] == "1"


# --- C4.2 : idempotence -----------------------------------------------------
def test_grille_pe_is_idempotent(data_dir: Path) -> None:
    from sources.grille_pe import run

    first = run().read_bytes()
    assert run().read_bytes() == first


def test_echelon_pe_is_idempotent(data_dir: Path) -> None:
    from sources.echelon_pe import run

    first = run().read_bytes()
    assert run().read_bytes() == first


# --- main() imprime le chemin du CSV ---------------------------------------
def test_grille_pe_main_prints_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    from sources.grille_pe import main

    main()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "grille_pe.csv")


def test_echelon_pe_main_prints_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    from sources.echelon_pe import main

    main()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "echelon_pe.csv")

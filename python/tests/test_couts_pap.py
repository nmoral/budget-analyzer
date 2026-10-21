import json
import os
import shutil
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest
from sources.couts_pap import SOURCE, URL
from sources.couts_pap import main as main_couts_pap
from sources.couts_pap import run as run_couts_pap

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
RETRIEVED_AT = "2026-10-21"

HEADER = "program,employment_category,average_loaded_cost,year,document,page,url"
ROW_140 = (
    "140,enseignant_1er_degre,46139,2025,PAP2025-ENSSCOL-P140,49,"
    "https://www2.assemblee-nationale.fr/static/17/Annexes-DL/PLF-2025/"
    "Enseignement_scolaire.pdf"
)


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write_csv(path: Path, line: str) -> Path:
    path.write_text(f"{HEADER}\n{line}\n", encoding="utf-8")
    return path


def test_valid_row_passes_schema(data_dir: Path) -> None:
    # C1.1 : en-tete + ligne 140 valide contre le schema couts_pap.
    csv_path = _write_csv(data_dir / "data" / "couts_pap.csv", ROW_140)
    assert validate_csv(csv_path, "couts_pap").valid


def test_missing_document_is_rejected(data_dir: Path) -> None:
    # C1.2 : colonne document vide -> rejet (required sur document).
    bad = ROW_140.replace(",PAP2025-ENSSCOL-P140,", ",,")
    csv_path = _write_csv(data_dir / "data" / "couts_pap.csv", bad)
    report = validate_csv(csv_path, "couts_pap")
    assert not report.valid
    assert "document" in report.flatten(["fieldName"]).__str__()


def test_missing_page_is_rejected(data_dir: Path) -> None:
    # C1.3 : colonne page vide -> rejet (required sur page).
    bad = ROW_140.replace(",49,", ",,")
    csv_path = _write_csv(data_dir / "data" / "couts_pap.csv", bad)
    report = validate_csv(csv_path, "couts_pap")
    assert not report.valid
    assert "page" in report.flatten(["fieldName"]).__str__()


def test_run_regenerates_valid_csv(data_dir: Path) -> None:
    # C2.1 : run() ecrit data/couts_pap.csv (en-tete + ligne 140), valide.
    out = run_couts_pap()
    assert out == data_dir / "data" / "couts_pap.csv"
    lines = out.read_text("utf-8").splitlines()
    assert lines[0] == HEADER
    assert validate_csv(out, "couts_pap").valid


def test_manifest_is_verifiable(data_dir: Path) -> None:
    # C2.2 : manifeste verifiable et conforme.
    run_couts_pap()
    assert verify_manifest("couts_pap") is True
    manifest = json.loads(
        (data_dir / "manifests" / "couts_pap.json").read_text("utf-8")
    )
    assert manifest["source"] == SOURCE
    assert manifest["url"] == URL
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == "sources.couts_pap"
    assert manifest["script_version"] == "1"


def test_ingestion_is_idempotent(data_dir: Path) -> None:
    # C2.3 : deux run() -> octets identiques.
    first = run_couts_pap().read_bytes()
    assert run_couts_pap().read_bytes() == first


def test_main_prints_written_csv_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    # C2.4 : main() imprime exactement le chemin du CSV.
    main_couts_pap()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "couts_pap.csv")


def test_generated_csv_holds_sourced_row(data_dir: Path) -> None:
    # C3.1 : le CSV genere contient la ligne 140 sourcee exacte.
    out = run_couts_pap()
    assert ROW_140 in out.read_text("utf-8").splitlines()

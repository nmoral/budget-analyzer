import json
import os
import shutil
from pathlib import Path

import pytest

from contract import schema_fields, validate_csv
from ingest import verify_manifest
from sources.budget_financeur import SCRIPT_VERSION, SOURCE, URL
from sources.budget_financeur import main as main_budget
from sources.budget_financeur import run as run_budget

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
RETRIEVED_AT = "2026-11-01"


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_schema_has_funder_columns() -> None:
    """C1.1 — schéma `budget_financeur` : colonnes dédiées au financeur."""
    assert schema_fields("budget_financeur") == [
        "funder_code",
        "value",
        "unit",
        "start_date",
        "end_date",
        "source",
    ]


def test_nominal_line_is_accepted_by_schema(data_dir: Path) -> None:
    """C2.1 — ligne nominale `ETAT_P140` 2025 acceptée par la validation de schéma."""
    out = run_budget()
    content = out.read_text("utf-8")
    assert "ETAT_P140,27490907364,EUR,2025-01-01,,pap-2025-ens-scolaire" in content
    assert validate_csv(out, "budget_financeur").valid


def test_dated_trajectory_per_funder(data_dir: Path) -> None:
    """C2.2 — 4 lignes datées, périodes contiguës, dernière à fin ouverte."""
    out = run_budget()
    lines = out.read_text("utf-8").splitlines()
    assert lines[0] == "funder_code,value,unit,start_date,end_date,source"
    body = lines[1:]
    assert body == [
        "ETAT_P140,26842958249,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire",
        "ETAT_P140,27490907364,EUR,2025-01-01,,pap-2025-ens-scolaire",
        "ONDAM,254900000000,EUR,2024-01-01,2024-12-31,ondam-2024-ralfss",
        "ONDAM,265900000000,EUR,2025-01-01,,ondam-2025-ralfss",
    ]
    # Trajectoire par financeur : 2 millésimes, dernier à fin ouverte.
    for funder in ("ETAT_P140", "ONDAM"):
        periods = [row.split(",") for row in body if row.startswith(f"{funder},")]
        assert len(periods) == 2
        assert periods[0][4] == f"{periods[0][3][:4]}-12-31"
        assert periods[1][4] == ""  # dernière période à fin ouverte
    assert validate_csv(out, "budget_financeur").valid


def test_each_source_is_a_known_slug() -> None:
    """C2.3 (précondition) — chaque `source` du CSV existe dans sources.json."""
    rows = json.loads(
        (REAL_DATA / "sources" / "budget_financeur.json").read_text("utf-8")
    )["rows"]
    known = set(json.loads((REAL_DATA / "sources.json").read_text("utf-8")))
    for row in rows:
        assert row["source"] in known, row["source"]


def test_manifest_is_verifiable(data_dir: Path) -> None:
    run_budget()
    assert verify_manifest("budget_financeur") is True
    manifest = json.loads(
        (data_dir / "manifests" / "budget_financeur.json").read_text("utf-8")
    )
    assert manifest["source"] == SOURCE
    assert manifest["url"] == URL
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == "sources.budget_financeur"
    assert manifest["script_version"] == SCRIPT_VERSION


def test_ingestion_is_idempotent(data_dir: Path) -> None:
    first = run_budget().read_bytes()
    assert run_budget().read_bytes() == first


def test_main_prints_written_csv_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    main_budget()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "budget_financeur.csv")

import json
import os
import shutil
import subprocess
from pathlib import Path

import pytest

from contract import schema_fields, validate_csv
from ingest import verify_manifest
from sources.masse_salariale_corps import SCRIPT_VERSION, SOURCE, URL, payroll_rows
from sources.masse_salariale_corps import main as main_payroll
from sources.masse_salariale_corps import run as run_payroll

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
SCHEMA = REAL_DATA / "schemas" / "masse_salariale_corps.json"
RETRIEVED_AT = "2026-11-16"
HEADER = "body_code,value,unit,start_date,end_date,source"
LINE_2024 = "PE,15590703468,EUR,2024-01-01,2024-12-31,pap-2024-ens-scolaire-masse-1106"
LINE_2025 = "PE,14994713610,EUR,2025-01-01,,pap-2025-ens-scolaire-masse-1106"


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_schema_is_valid_frictionless_schema() -> None:
    """C1.1 — `masse_salariale_corps.json` est un schéma Frictionless valide."""
    result = subprocess.run(
        ["frictionless", "validate", "--trusted", "--type", "schema", str(SCHEMA)],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout


def test_schema_has_body_amount_columns() -> None:
    """C1.1 — colonnes `body_code, value, unit, start_date, end_date, source`."""
    assert schema_fields("masse_salariale_corps") == HEADER.split(",")
    fields = json.loads(SCHEMA.read_text("utf-8"))["fields"]
    required = {
        field["name"]
        for field in fields
        if field.get("constraints", {}).get("required") is True
    }
    assert required == {"body_code", "value", "unit", "start_date", "source"}


def test_payroll_rows_multiply_etpt_by_average_loaded_cost() -> None:
    """C2 — value = ETPT × coût moyen chargé HCAS global, en euros entiers."""
    raw = [
        {
            "body_code": "PE",
            "etpt": "327302",
            "average_loaded_cost": "47634",
            "unit": "EUR",
            "start_date": "2024-01-01",
            "end_date": "2024-12-31",
            "source": "pap-2024-ens-scolaire-masse-1106",
        }
    ]
    assert payroll_rows(raw) == [
        {
            "body_code": "PE",
            "value": "15590703468",
            "unit": "EUR",
            "start_date": "2024-01-01",
            "end_date": "2024-12-31",
            "source": "pap-2024-ens-scolaire-masse-1106",
        }
    ]


def test_vintage_2024_line_is_accepted(data_dir: Path) -> None:
    """C2.1 — millésime 2024 (327 302 ETPT × 47 634 €, PAP 2024) accepté."""
    out = run_payroll()
    assert LINE_2024 in out.read_text("utf-8").splitlines()
    assert validate_csv(out, "masse_salariale_corps").valid


def test_vintage_2025_line_is_accepted_and_contiguous(data_dir: Path) -> None:
    """C2.2 — millésime 2025 (324 990 × 46 139 €) accepté, ouvert, contigu à 2024."""
    out = run_payroll()
    lines = out.read_text("utf-8").splitlines()
    assert lines == [HEADER, LINE_2024, LINE_2025]
    first, second = (line.split(",") for line in lines[1:])
    assert first[4] == "2024-12-31"
    assert second[3] == "2025-01-01"  # lendemain de la fin 2024 : contigu
    assert second[4] == ""  # période ouverte
    assert validate_csv(out, "masse_salariale_corps").valid


def test_no_isg_line(data_dir: Path) -> None:
    """C2.3 — ISG hors périmètre : aucune ligne `ISG`."""
    body = run_payroll().read_text("utf-8").splitlines()[1:]
    assert body
    assert not [line for line in body if line.split(",")[0] == "ISG"]


def test_committed_csv_matches_generation(data_dir: Path) -> None:
    """C2 — `make generate` régénère un CSV identique au CSV versionné."""
    committed = (REAL_DATA / "data" / "masse_salariale_corps.csv").read_bytes()
    assert run_payroll().read_bytes() == committed


@pytest.mark.parametrize(
    ("slug", "etpt", "cost", "amount", "pages"),
    [
        (
            "pap-2024-ens-scolaire-masse-1106",
            "327 302",
            "47 634",
            "15 590 703 468",
            ("p. 47", "p. 53"),
        ),
        (
            "pap-2025-ens-scolaire-masse-1106",
            "324 990",
            "46 139",
            "14 994 713 610",
            ("p. 47", "p. 52"),
        ),
    ],
)
def test_source_slug_cites_formula_pages_and_values(
    slug: str, etpt: str, cost: str, amount: str, pages: tuple[str, str]
) -> None:
    """C2 — chaque slug est déclaré ; `locator` = formule + pages + deux valeurs."""
    registry = json.loads((REAL_DATA / "sources.json").read_text("utf-8"))
    entry = registry[slug]
    locator = entry["locator"]
    assert "1106" in locator
    assert "HCAS" in locator
    assert "×" in locator
    for fragment in (etpt, cost, amount, *pages):
        assert fragment in locator, fragment
    pdf = slug.removesuffix("-masse-1106")
    assert entry["url"] == registry[pdf]["url"]
    assert entry["fileHash"] == registry[pdf]["fileHash"]


def test_each_csv_source_is_a_known_slug() -> None:
    """C2 — chaque `source` du brut existe dans sources.json."""
    rows = json.loads(
        (REAL_DATA / "sources" / "masse_salariale_corps.json").read_text("utf-8")
    )["rows"]
    known = set(json.loads((REAL_DATA / "sources.json").read_text("utf-8")))
    assert rows
    for row in rows:
        assert row["source"] in known, row["source"]


def test_manifest_is_verifiable(data_dir: Path) -> None:
    run_payroll()
    assert verify_manifest("masse_salariale_corps") is True
    manifest = json.loads(
        (data_dir / "manifests" / "masse_salariale_corps.json").read_text("utf-8")
    )
    assert manifest["source"] == SOURCE
    assert manifest["url"] == URL
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == "sources.masse_salariale_corps"
    assert manifest["script_version"] == SCRIPT_VERSION
    assert manifest["raw"] == "masse_salariale_corps.json"


def test_committed_manifest_matches_generation(data_dir: Path) -> None:
    """C2 — manifeste versionné cohérent avec la génération."""
    run_payroll()
    generated = (data_dir / "manifests" / "masse_salariale_corps.json").read_bytes()
    committed = (REAL_DATA / "manifests" / "masse_salariale_corps.json").read_bytes()
    assert generated == committed


def test_ingestion_is_idempotent(data_dir: Path) -> None:
    first = run_payroll().read_bytes()
    assert run_payroll().read_bytes() == first


def test_main_prints_written_csv_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    main_payroll()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "masse_salariale_corps.csv")

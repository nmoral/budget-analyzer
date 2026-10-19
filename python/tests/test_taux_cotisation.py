import json
import os
import shutil
from collections.abc import Callable
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest
from sources.taux_cas_civil import run as run_cas_civil
from sources.taux_cas_militaire import run as run_cas_militaire
from sources.taux_cnracl import run as run_cnracl

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])

Runner = Callable[[], Path]

SOURCES: list[tuple[str, Runner, str]] = [
    ("taux_cas_civil", run_cas_civil, "Retraites de l'État — taux de cotisation 2026"),
    (
        "taux_cas_militaire",
        run_cas_militaire,
        "Assemblée nationale — rapport CAS Pensions (RAPPANR5L17B1996)",
    ),
    ("taux_cnracl", run_cnracl, "Décret n° 2025-86 du 30 janvier 2025"),
]


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_taux_cas_civil_holds_dated_values(data_dir: Path) -> None:
    out = run_cas_civil()
    content = out.read_text("utf-8")
    assert "taux_cas_civil,78.28,PCT_TIB,2025-01-01,2025-12-31,retraites-etat-2026" in content
    assert "taux_cas_civil,82.28,PCT_TIB,2026-01-01,,retraites-etat-2026" in content
    assert validate_csv(out, "parametre_date").valid


def test_taux_cas_militaire_holds_dated_value(data_dir: Path) -> None:
    out = run_cas_militaire()
    content = out.read_text("utf-8")
    assert "taux_cas_militaire,126.07,PCT_TIB,2025-01-01,,an-rapport-cas-pensions" in content
    assert validate_csv(out, "parametre_date").valid


def test_taux_cnracl_holds_trajectory(data_dir: Path) -> None:
    out = run_cnracl()
    lines = out.read_text("utf-8").splitlines()
    assert "taux_cnracl,37.65,PCT_TIB,2026-01-01,2026-12-31,decret-2025-86" in lines
    assert "taux_cnracl,40.65,PCT_TIB,2027-01-01,2027-12-31,decret-2025-86" in lines
    assert "taux_cnracl,43.65,PCT_TIB,2028-01-01,,decret-2025-86" in lines
    assert validate_csv(out, "parametre_date").valid


@pytest.mark.parametrize("name,run,source", SOURCES)
def test_manifests_are_verifiable(
    data_dir: Path, name: str, run: Runner, source: str
) -> None:
    run()
    assert verify_manifest(name) is True
    manifest = json.loads((data_dir / "manifests" / f"{name}.json").read_text("utf-8"))
    for field in ("source", "url", "retrieved_at", "script", "script_version"):
        assert manifest.get(field)
    assert manifest["script"] == f"sources.{name}"
    assert manifest["source"] == source


@pytest.mark.parametrize("name,run,source", SOURCES)
def test_ingestion_is_idempotent(
    data_dir: Path, name: str, run: Runner, source: str
) -> None:
    first = run().read_bytes()
    assert run().read_bytes() == first

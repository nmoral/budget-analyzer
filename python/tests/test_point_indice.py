import json
import os
import shutil
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest
from sources.point_indice import run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])

EXPECTED_ROW = "point_indice,59.0734,EUR_PER_YEAR,2023-07-01,,decret-2023-519"


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_point_indice_csv_holds_current_value(data_dir: Path) -> None:
    out = run()
    content = out.read_text("utf-8")
    assert EXPECTED_ROW in content
    assert validate_csv(out, "parametre_date").valid


def test_point_indice_manifest_is_verifiable(data_dir: Path) -> None:
    run()
    assert verify_manifest("point_indice") is True
    manifest = json.loads((data_dir / "manifests" / "point_indice.json").read_text())
    assert manifest["source"] == "Décret n° 2023-519 du 28 juin 2023"
    assert manifest["url"] == (
        "https://www.legifrance.gouv.fr/eli/decret/2023/6/28/2023-519/jo/texte"
    )
    assert manifest["retrieved_at"] == "2026-10-19"
    assert manifest["script"] == "sources.point_indice"
    assert manifest["script_version"] == "1"


def test_point_indice_is_idempotent(data_dir: Path) -> None:
    first = run().read_bytes()
    assert run().read_bytes() == first

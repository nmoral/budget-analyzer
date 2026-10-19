import os
import shutil
from pathlib import Path

import pytest

from ingest import IngestionError, ingest

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])

VALID_ROWS = [
    {
        "key": "point_indice",
        "value": "59.0734",
        "unit": "EUR_PER_YEAR",
        "start_date": "2023-07-01",
        "end_date": "",
        "source": "decret-2023",
    }
]


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_ingest_writes_valid_csv(data_dir: Path) -> None:
    out = ingest(rows=VALID_ROWS, schema_name="parametre_date", name="point_indice")
    assert out == data_dir / "data" / "point_indice.csv"
    assert out.exists()


def test_ingest_uses_lf_line_endings(data_dir: Path) -> None:
    out = ingest(rows=VALID_ROWS, schema_name="parametre_date", name="point_indice")
    content = out.read_bytes()
    assert b"\r\n" not in content
    assert content.endswith(b"\n")


def test_ingest_is_idempotent(data_dir: Path) -> None:
    out = ingest(rows=VALID_ROWS, schema_name="parametre_date", name="point_indice")
    first = out.read_bytes()
    ingest(rows=VALID_ROWS, schema_name="parametre_date", name="point_indice")
    assert out.read_bytes() == first


def test_ingest_rejects_invalid_rows_without_writing(data_dir: Path) -> None:
    bad_rows = [{**VALID_ROWS[0], "value": "not-a-number"}]
    with pytest.raises(IngestionError) as exc:
        ingest(rows=bad_rows, schema_name="parametre_date", name="point_indice")
    assert "parametre_date" in str(exc.value)
    assert "type-error" in str(exc.value)
    assert not (data_dir / "data" / "point_indice.csv").exists()

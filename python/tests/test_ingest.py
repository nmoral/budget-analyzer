import hashlib
import json
import os
import shutil
from pathlib import Path

import pytest

from ingest import IngestionError, ingest, verify_manifest

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

MANIFEST_FIELDS = {
    "source",
    "url",
    "retrieved_at",
    "sha256",
    "script",
    "script_version",
}


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _ingest(data_dir: Path, **overrides: object) -> Path:
    raw = data_dir / "source.raw"
    raw.write_bytes(b"raw source bytes")
    kwargs: dict[str, object] = {
        "rows": VALID_ROWS,
        "schema_name": "parametre_date",
        "name": "point_indice",
        "raw_path": raw,
        "source": "DGFiP",
        "url": "https://example.gouv.fr/point",
        "retrieved_at": "2026-10-19",
        "script": "ingest_point_indice",
        "script_version": "1",
    }
    kwargs.update(overrides)
    return ingest(**kwargs)  # type: ignore[arg-type]


def test_ingest_writes_valid_csv(data_dir: Path) -> None:
    out = _ingest(data_dir)
    assert out == data_dir / "data" / "point_indice.csv"
    assert out.exists()


def test_ingest_uses_lf_line_endings(data_dir: Path) -> None:
    content = _ingest(data_dir).read_bytes()
    assert b"\r\n" not in content
    assert content.endswith(b"\n")


def test_ingest_is_idempotent(data_dir: Path) -> None:
    first = _ingest(data_dir).read_bytes()
    assert _ingest(data_dir).read_bytes() == first


def test_ingest_rejects_invalid_rows_without_writing(data_dir: Path) -> None:
    bad_rows = [{**VALID_ROWS[0], "value": "not-a-number"}]
    with pytest.raises(IngestionError) as exc:
        _ingest(data_dir, rows=bad_rows)
    assert "parametre_date" in str(exc.value)
    assert "type-error" in str(exc.value)
    assert not (data_dir / "data" / "point_indice.csv").exists()


def test_ingest_writes_complete_manifest(data_dir: Path) -> None:
    _ingest(data_dir)
    manifest = json.loads((data_dir / "manifests" / "point_indice.json").read_text())
    assert MANIFEST_FIELDS <= manifest.keys()
    assert manifest["source"] == "DGFiP"
    assert manifest["url"] == "https://example.gouv.fr/point"
    assert manifest["retrieved_at"] == "2026-10-19"
    assert manifest["script"] == "ingest_point_indice"
    assert manifest["script_version"] == "1"
    assert manifest["sha256"] == hashlib.sha256(b"raw source bytes").hexdigest()
    assert verify_manifest("point_indice") is True


def test_verify_detects_tampered_raw(data_dir: Path) -> None:
    _ingest(data_dir)
    archived = next((data_dir / "raw").iterdir())
    archived.write_bytes(b"tampered")
    assert verify_manifest("point_indice") is False


def test_failure_leaves_no_residue(data_dir: Path) -> None:
    with pytest.raises(FileNotFoundError):
        _ingest(data_dir, raw_path=data_dir / "absent.raw")
    assert list((data_dir / "data").iterdir()) == []
    assert list((data_dir / "raw").iterdir()) == []
    assert list((data_dir / "manifests").iterdir()) == []

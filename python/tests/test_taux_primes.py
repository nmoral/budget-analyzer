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


def _data_lines(csv_path: Path) -> list[str]:
    return csv_path.read_text("utf-8").splitlines()[1:]


# --- C1.1 : PE nominal (observation directe) -------------------------------
def test_taux_primes_pe_row(data_dir: Path) -> None:
    from sources.taux_primes import run

    out = run()
    content = out.read_text("utf-8")
    assert "PE,0.21,2023,dgafp-ra-2025,false" in content
    assert validate_csv(out, "taux_primes").valid


# --- C1.2 : ISG nominal (repli versant x categorie) ------------------------
def test_taux_primes_isg_row(data_dir: Path) -> None:
    from sources.taux_primes import run

    out = run()
    content = out.read_text("utf-8")
    assert "ISG,0.33,2023,insee-ip-2067,true" in content
    assert validate_csv(out, "taux_primes").valid


# --- C1 : une ligne par corps des deux profils V1 --------------------------
def test_taux_primes_one_row_per_body(data_dir: Path) -> None:
    from sources.taux_primes import run

    out = run()
    lines = _data_lines(out)
    assert len(lines) == 2
    body_codes = {line.split(",")[0] for line in lines}
    assert body_codes == {"PE", "ISG"}


# --- C1.3 : idempotence -----------------------------------------------------
def test_taux_primes_is_idempotent(data_dir: Path) -> None:
    from sources.taux_primes import run

    first = run().read_bytes()
    assert run().read_bytes() == first
    assert not list((data_dir / "data").glob(".*.tmp.csv"))


# --- C2.1 : distinction repli (fallback) -----------------------------------
def test_taux_primes_fallback_distinction(data_dir: Path) -> None:
    from sources.taux_primes import run

    out = run()
    fallback_by_body = {
        line.split(",")[0]: line.split(",")[4] for line in _data_lines(out)
    }
    assert fallback_by_body["PE"] == "false"
    assert fallback_by_body["ISG"] == "true"


# --- C3.3 : manifeste verifiable -------------------------------------------
def test_taux_primes_manifest_is_verifiable(data_dir: Path) -> None:
    from sources.taux_primes import run

    run()
    assert verify_manifest("taux_primes") is True
    manifest = json.loads((data_dir / "manifests" / "taux_primes.json").read_text())
    for key in ("source", "url", "sha256", "script", "script_version"):
        assert manifest[key]
    assert manifest["script"] == "sources.taux_primes"


# --- main() imprime le chemin du CSV ---------------------------------------
def test_taux_primes_main_prints_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    from sources.taux_primes import main

    main()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "taux_primes.csv")


# --- C4.1 : catalogue coherent avec le CSV ---------------------------------
def test_catalogue_primes_is_coherent_with_csv(data_dir: Path) -> None:
    from sources.taux_primes import run

    out = run()
    catalogue = (REAL_DATA / "catalogue_primes.md").read_text("utf-8")
    lines = _data_lines(out)
    for line in lines:
        body_code, _value, _year, source, fallback = line.split(",")
        assert body_code in catalogue
        assert source in catalogue
        repli = "oui" if fallback == "true" else "non"
        # chaque corps documente son statut repli (oui/non)
        assert repli in catalogue.lower()
    # repli documente dans les deux sens
    assert "oui" in catalogue.lower()
    assert "non" in catalogue.lower()

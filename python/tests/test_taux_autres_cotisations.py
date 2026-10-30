"""Ingestion des taux employeur agrégés hors retraite (FPE, FPH) et RAFP (#49)."""

import json
import os
import shutil
from collections.abc import Callable
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest
from sources.taux_autres_cotisations_fpe import main as main_fpe
from sources.taux_autres_cotisations_fpe import run as run_fpe
from sources.taux_autres_cotisations_fph import main as main_fph
from sources.taux_autres_cotisations_fph import run as run_fph
from sources.taux_rafp import main as main_rafp
from sources.taux_rafp import run as run_rafp

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])

Runner = Callable[[], Path]
Printer = Callable[[], None]

RETRIEVED_AT = "2026-10-30"


class SourceCase:
    def __init__(
        self,
        name: str,
        run: Runner,
        main: Printer,
        source: str,
        url: str,
        script_version: str,
    ) -> None:
        self.name = name
        self.run = run
        self.main = main
        self.source = source
        self.url = url
        self.script_version = script_version


SOURCES: list[SourceCase] = [
    SourceCase(
        "taux_autres_cotisations_fpe",
        run_fpe,
        main_fpe,
        "Cotisations employeur hors retraite — FPE/État 2026 "
        "(URSSAF secteur public / CDG67)",
        "https://www.urssaf.fr/accueil/employeur/secteur-public.html",
        "1",
    ),
    SourceCase(
        "taux_autres_cotisations_fph",
        run_fph,
        main_fph,
        "Cotisations employeur hors retraite — FPH/CNRACL 2026 "
        "(URSSAF secteur public / CDG67)",
        "https://www.urssaf.fr/accueil/employeur/secteur-public.html",
        "1",
    ),
    SourceCase(
        "taux_rafp",
        run_rafp,
        main_rafp,
        "RAFP — taux de cotisation employeur 2026 (décret n° 2004-569)",
        "https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000607091",
        "1",
    ),
]

IDS = [case.name for case in SOURCES]


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(REAL_DATA / "sources", tmp_path / "sources")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def test_taux_autres_cotisations_fpe_line(data_dir: Path) -> None:  # C1.1
    out = run_fpe()
    content = out.read_text("utf-8")
    assert (
        "taux_autres_cotisations_fpe,16.07,PCT_TIB,2026-01-01,,cotisations-fpe-2026"
        in content
    )
    assert validate_csv(out, "parametre_date").valid


def test_taux_autres_cotisations_fph_line(data_dir: Path) -> None:  # C1.2
    out = run_fph()
    content = out.read_text("utf-8")
    assert (
        "taux_autres_cotisations_fph,17.13,PCT_TIB,2026-01-01,,cotisations-fph-2026"
        in content
    )
    assert validate_csv(out, "parametre_date").valid


def test_taux_rafp_line(data_dir: Path) -> None:  # C1.3
    out = run_rafp()
    content = out.read_text("utf-8")
    assert "taux_rafp,5.00,PCT_TIB,2026-01-01,,rafp-2026" in content
    assert validate_csv(out, "parametre_date").valid


@pytest.mark.parametrize("case", SOURCES, ids=IDS)
def test_manifests_are_verifiable(data_dir: Path, case: SourceCase) -> None:
    case.run()
    assert verify_manifest(case.name) is True
    manifest = json.loads(
        (data_dir / "manifests" / f"{case.name}.json").read_text("utf-8")
    )
    assert manifest["source"] == case.source
    assert manifest["url"] == case.url
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == f"sources.{case.name}"
    assert manifest["script_version"] == case.script_version


@pytest.mark.parametrize("case", SOURCES, ids=IDS)
def test_ingestion_is_idempotent(data_dir: Path, case: SourceCase) -> None:
    first = case.run().read_bytes()
    assert case.run().read_bytes() == first


@pytest.mark.parametrize("case", SOURCES, ids=IDS)
def test_main_prints_written_csv_path(
    data_dir: Path, case: SourceCase, capsys: pytest.CaptureFixture[str]
) -> None:
    case.main()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / f"{case.name}.csv")


@pytest.mark.parametrize("case", SOURCES, ids=IDS)
def test_manifest_sha256_matches_declared_source(
    data_dir: Path, case: SourceCase
) -> None:
    # Le fileHash déclaré dans sources.json doit valoir le sha256 du raw : c'est
    # ce qu'imposera ManifestVerifier (J2) ; on le garantit dès le sourcing.
    case.run()
    manifest = json.loads(
        (data_dir / "manifests" / f"{case.name}.json").read_text("utf-8")
    )
    raw_row = json.loads(
        (data_dir / "sources" / f"{case.name}.json").read_text("utf-8")
    )["rows"][0]
    registry = json.loads((REAL_DATA / "sources.json").read_text("utf-8"))
    code = raw_row["source"]
    assert code in registry
    assert registry[code]["fileHash"] == manifest["sha256"]

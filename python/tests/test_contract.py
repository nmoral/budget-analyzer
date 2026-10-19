from pathlib import Path

import pytest

import contract
from contract import contract_version, validate_csv

FIXTURES = Path(__file__).parent / "fixtures"


def test_valid_parametre_date_passes() -> None:
    report = validate_csv(FIXTURES / "parametre_date_valid.csv", "parametre_date")
    assert report.valid


@pytest.mark.parametrize(
    "fixture",
    [
        "parametre_date_missing_field.csv",
        "parametre_date_bad_date.csv",
        "parametre_date_comma_decimal.csv",
    ],
)
def test_non_conforming_parametre_date_is_rejected(fixture: str) -> None:
    report = validate_csv(FIXTURES / fixture, "parametre_date")
    assert not report.valid


@pytest.mark.parametrize(
    "schema",
    ["parametre_date", "grille", "echelon", "taux_primes", "couts_pap"],
)
def test_each_schema_validates_its_valid_fixture(schema: str) -> None:
    report = validate_csv(FIXTURES / f"{schema}_valid.csv", schema)
    assert report.valid


def test_data_dir_defaults_relative_to_package(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.delenv("BUDGET_DATA_DIR", raising=False)
    expected = Path(contract.__file__).resolve().parents[3] / "data"
    assert contract.data_dir() == expected


def test_contract_version_is_semver() -> None:
    version = contract_version()
    assert isinstance(version, str)
    parts = version.split(".")
    assert len(parts) == 3
    assert all(part.isdigit() for part in parts)

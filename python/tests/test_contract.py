from pathlib import Path

import pytest

import contract
from contract import contract_version, schema_fields, validate_csv

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


def test_contract_version_bumped_to_major_two() -> None:
    # C3 : l'ajout du champ requis `fallback` est un changement incompatible.
    assert contract_version() == "2.0.0"


def test_taux_primes_schema_requires_fallback_field() -> None:
    # C3 : le schéma étendu expose un champ `fallback`.
    assert "fallback" in schema_fields("taux_primes")


def test_taux_primes_valid_fixture_passes() -> None:
    # C3.1 : la fixture valide (avec `fallback`) valide contre le schéma étendu.
    report = validate_csv(FIXTURES / "taux_primes_valid.csv", "taux_primes")
    assert report.valid


def test_taux_primes_missing_fallback_is_rejected() -> None:
    # C3.2 : un CSV sans la colonne `fallback` est rejeté (champ requis).
    report = validate_csv(FIXTURES / "taux_primes_missing_fallback.csv", "taux_primes")
    assert not report.valid

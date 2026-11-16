"""``python -m checks`` : statut des académies de ``academies.json`` (#82, C1)."""

import json
import os
import shutil
from pathlib import Path
from typing import Any

import pytest

from checks import AcademieEntry, load_academie_entries, main, run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."


@pytest.fixture
def real_copy(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Copie intégrale des données réelles (CSV, schémas, référentiels)."""
    for sub in ("schemas", "data"):
        shutil.copytree(REAL_DATA / sub, tmp_path / sub)
    for name in ("sources.json", "academies.json", "coherence.toml"):
        shutil.copy(REAL_DATA / name, tmp_path / name)
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _alter(root: Path, change: dict[str, str | None]) -> None:
    """Réécrit ``academies.json`` : statut remplacé (``None`` = clé supprimée)."""
    path = root / "academies.json"
    referential: dict[str, dict[str, Any]] = json.loads(path.read_text("utf-8"))
    for code, statut in change.items():
        if statut is None:
            del referential[code]["statut"]
        else:
            referential[code]["statut"] = statut
    path.write_text(json.dumps(referential, ensure_ascii=False, indent=2), "utf-8")


def _line_of(root: Path, code: str) -> int:
    """Ligne de la clé ``"<code>": {`` dans ``academies.json``."""
    lines = (root / "academies.json").read_text("utf-8").splitlines()
    return next(
        number
        for number, line in enumerate(lines, start=1)
        if line.strip() == f'"{code}": {{'
    )


def _expected(root: Path, code: str, message: str) -> str:
    """Ligne attendue d'une anomalie de statut sur l'entrée ``code``."""
    return f"academies.json:{_line_of(root, code)} [academie_statut] {code} : {message}"


def _statut_lines(root: Path) -> tuple[int, list[str]]:
    code, report = run(root)
    return code, [line for line in report.splitlines() if "[academie_statut]" in line]


def test_c1_1_real_data_has_no_anomaly(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setenv("BUDGET_DATA_DIR", str(REAL_DATA))
    assert main() == 0
    assert capsys.readouterr().out.strip() == NO_ANOMALY


def test_c1_2_statut_outside_list_reported_on_entry_line(real_copy: Path) -> None:
    _alter(real_copy, {"32": "outremer"})

    code, report = run(real_copy)

    assert code == 1
    assert report.splitlines() == [
        _expected(real_copy, "32", "statut « outremer » hors metropole/drom/com"),
        "Récapitulatif : 1 anomalie(s) détectée(s).",
    ]


def test_c1_3_missing_statut_reported_on_key_line(real_copy: Path) -> None:
    _alter(real_copy, {"41": None})

    assert _statut_lines(real_copy) == (
        1,
        [_expected(real_copy, "41", "statut absent")],
    )


def test_c1_4_empty_statut_reported(real_copy: Path) -> None:
    _alter(real_copy, {"28": ""})

    assert _statut_lines(real_copy) == (
        1,
        [_expected(real_copy, "28", "statut «  » hors metropole/drom/com")],
    )


def test_c1_5_case_and_spaces_are_strict(real_copy: Path) -> None:
    _alter(real_copy, {"32": "DROM", "43": " drom "})

    assert _statut_lines(real_copy) == (
        1,
        [
            _expected(real_copy, "32", "statut « DROM » hors metropole/drom/com"),
            _expected(real_copy, "43", "statut «  drom  » hors metropole/drom/com"),
        ],
    )


def test_c1_6_every_invalid_statut_reported(real_copy: Path) -> None:
    _alter(real_copy, {"28": "outremer", "41": None})

    code, report = run(real_copy)

    assert code == 1
    assert report.splitlines() == [
        _expected(real_copy, "28", "statut « outremer » hors metropole/drom/com"),
        _expected(real_copy, "41", "statut absent"),
        "Récapitulatif : 2 anomalie(s) détectée(s).",
    ]


def test_c1_7_check_neutralised_without_referential(real_copy: Path) -> None:
    (real_copy / "academies.json").unlink()
    assert run(real_copy) == (0, NO_ANOMALY)


@pytest.mark.parametrize("statut", ["metropole", "drom", "com"])
def test_each_allowed_statut_accepted(real_copy: Path, statut: str) -> None:
    _alter(real_copy, {"32": statut})
    assert run(real_copy) == (0, NO_ANOMALY)


def test_load_academie_entries_locates_each_key(tmp_path: Path) -> None:
    (tmp_path / "academies.json").write_text(
        '{\n  "01": {\n    "statut": "metropole"\n  },\n  "41": {\n  }\n}\n',
        "utf-8",
    )
    assert load_academie_entries(tmp_path) == [
        AcademieEntry(code="01", line=2, statut="metropole"),
        AcademieEntry(code="41", line=5, statut=None),
    ]


def test_load_academie_entries_compact_json_on_first_line(tmp_path: Path) -> None:
    (tmp_path / "academies.json").write_text(
        '{"01": {"statut": "metropole"}, "02": {"statut": "x"}}', "utf-8"
    )
    assert load_academie_entries(tmp_path) == [
        AcademieEntry(code="01", line=1, statut="metropole"),
        AcademieEntry(code="02", line=1, statut="x"),
    ]


def test_load_academie_entries_none_without_referential(tmp_path: Path) -> None:
    assert load_academie_entries(tmp_path) is None


def test_null_statut_reported_as_absent(real_copy: Path) -> None:
    path = real_copy / "academies.json"
    referential: dict[str, dict[str, Any]] = json.loads(path.read_text("utf-8"))
    referential["41"]["statut"] = None
    path.write_text(json.dumps(referential, ensure_ascii=False, indent=2), "utf-8")

    assert _statut_lines(real_copy) == (
        1,
        [_expected(real_copy, "41", "statut absent")],
    )

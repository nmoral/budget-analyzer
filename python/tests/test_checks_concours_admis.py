"""Cohérence ``python -m checks`` du dataset ``concours_admis`` (critère C3)."""

import os
import shutil
from pathlib import Path

import pytest

from checks import collect_files, main, run

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
NO_ANOMALY = "Contrôles de cohérence : aucune anomalie."
ONE_ANOMALY = "Récapitulatif : 1 anomalie(s) détectée(s)."

HEADER = "body_code,session,voie,postes,inscrits,presents,admis,source"
SOURCES_JSON = '{"menj-crpe-2024": {"label": "x"}, "menj-crpe-2025": {"label": "x"}}'
LINE_2025_EXTERNE = "PE,2025,externe,8102,43276,18387,7561,menj-crpe-2025"
LINE_2025_TROISIEME = "PE,2025,troisieme,1027,13274,3728,991,menj-crpe-2025"
LINE_2024_EXTERNE = "PE,2024,externe,7917,42145,18385,6961,menj-crpe-2024"


@pytest.fixture
def data_root(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    """Data-dir isolé : schémas réels + référentiel de sources + ``data`` vide."""
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    (tmp_path / "data").mkdir()
    (tmp_path / "sources.json").write_text(SOURCES_JSON, "utf-8")
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _write(root: Path, *lines: str) -> None:
    path = root / "data" / "concours_admis.csv"
    path.write_text("\n".join(lines) + "\n", "utf-8")


def _report_of(root: Path) -> tuple[int, list[str]]:
    code, report = run(root)
    return code, report.splitlines()


# --- C3.1 / C2.4 : le CSV réel est cohérent ---------------------------------


def test_real_data_has_no_anomaly(capsys: pytest.CaptureFixture[str]) -> None:
    """C3.1 + C2.4 — CSV réel (PE seul, aucune ligne ISG) : exit 0."""
    real_csv = REAL_DATA / "data" / "concours_admis.csv"
    lines = real_csv.read_text("utf-8").splitlines()
    assert lines[0] == HEADER
    assert LINE_2025_EXTERNE in lines
    assert not [line for line in lines[1:] if line.startswith("ISG,")]
    assert main() == 0
    assert capsys.readouterr().out.strip() == NO_ANOMALY


def test_collect_files_maps_concours_admis(data_root: Path) -> None:
    _write(data_root, HEADER, LINE_2025_EXTERNE)
    by_name = {loaded.name: loaded for loaded in collect_files(data_root)}
    assert by_name["concours_admis"].schema == "concours_admis"
    assert by_name["concours_admis"].has_source is True


def test_valid_sessions_without_isg_pass(data_root: Path) -> None:
    """C2.4 — PE sur deux sessions, même voie, aucun ISG : pas d'anomalie."""
    _write(data_root, HEADER, LINE_2024_EXTERNE, LINE_2025_EXTERNE, LINE_2025_TROISIEME)
    assert run(data_root) == (0, NO_ANOMALY)


def test_funnel_equalities_and_zero_are_accepted(data_root: Path) -> None:
    """Bornes incluses : admis = présents = inscrits, et zéros, sont acceptés."""
    _write(
        data_root,
        HEADER,
        "PE,2025,externe,0,0,0,0,menj-crpe-2025",
        "PE,2025,troisieme,5,7,7,7,menj-crpe-2025",
    )
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.2 : entonnoir admis ≤ présents ≤ inscrits ---------------------------


def test_admis_above_presents_reported_with_line(data_root: Path) -> None:
    """C3.2 — `admis` > `presents` : exit 1, ligne localisée."""
    _write(
        data_root,
        HEADER,
        LINE_2025_EXTERNE,
        "PE,2025,troisieme,1027,13274,3728,3729,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis.csv:3 [concours] PE/2025/troisieme : "
            "admis 3729 > présents 3728"
        ),
        ONE_ANOMALY,
    ]


def test_presents_above_inscrits_reported_with_line(data_root: Path) -> None:
    """C3.2 — `presents` > `inscrits` : exit 1, ligne localisée."""
    _write(data_root, HEADER, "PE,2025,externe,8117,18523,18524,7576,menj-crpe-2025")
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis.csv:2 [concours] PE/2025/externe : "
            "présents 18524 > inscrits 18523"
        ),
        ONE_ANOMALY,
    ]


# --- C3.3 : unicité (body_code, session, voie) ------------------------------


def test_duplicate_key_reported_on_second_line(data_root: Path) -> None:
    """C3.3 — deux lignes `PE,2025,externe` : exit 1, ligne du doublon localisée."""
    _write(
        data_root,
        HEADER,
        LINE_2025_EXTERNE,
        LINE_2025_TROISIEME,
        "PE,2025,externe,8000,43000,18000,7000,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        "concours_admis.csv:4 [unicite] PE/2025/externe : doublon de la ligne 2",
        ONE_ANOMALY,
    ]


def test_same_voie_other_session_or_body_is_not_duplicate(data_root: Path) -> None:
    """C3.3 — la clé est le triplet : même voie, autre session ou corps → OK."""
    _write(
        data_root,
        HEADER,
        LINE_2025_EXTERNE,
        LINE_2024_EXTERNE,
        "ISG,2025,externe,1,1,1,1,menj-crpe-2025",
    )
    assert run(data_root) == (0, NO_ANOMALY)


# --- C3.4 : valeurs négatives et source blanche -----------------------------


def test_negative_postes_reported_with_line(data_root: Path) -> None:
    """C3.4 — `postes=-1` : exit 1, ligne localisée."""
    _write(
        data_root,
        HEADER,
        LINE_2025_EXTERNE,
        "PE,2025,troisieme,-1,10,5,2,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        "concours_admis.csv:3 [concours] PE/2025/troisieme : postes -1 < 0",
        ONE_ANOMALY,
    ]


@pytest.mark.parametrize("column", ["postes", "inscrits", "presents", "admis"])
def test_every_count_column_must_be_non_negative(data_root: Path, column: str) -> None:
    """C3.4 — chaque effectif (postes, inscrits, présents, admis) est ≥ 0."""
    values = {"postes": "0", "inscrits": "0", "presents": "0", "admis": "0"}
    values[column] = "-1"
    _write(
        data_root,
        HEADER,
        f"PE,2025,externe,{values['postes']},{values['inscrits']},"
        f"{values['presents']},{values['admis']},menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert f"concours_admis.csv:2 [concours] PE/2025/externe : {column} -1 < 0" in (
        lines
    )


def test_blank_source_reported_with_line(data_root: Path) -> None:
    """C3.4 — `source` vide : exit 1, ligne localisée."""
    _write(
        data_root, HEADER, LINE_2025_EXTERNE, "PE,2025,troisieme,1027,13274,3728,991,"
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        "concours_admis.csv:3 [source] source blanche ou absente",
        ONE_ANOMALY,
    ]


# --- C3.5 : admis > postes accepté ------------------------------------------


def test_admis_above_postes_is_accepted(data_root: Path) -> None:
    """C3.5 — `postes=100, admis=110` : accepté (report des emplois non pourvus)."""
    _write(data_root, HEADER, "PE,2025,externe,100,500,200,110,menj-crpe-2025")
    assert run(data_root) == (0, NO_ANOMALY)


def test_concours_checked_alongside_other_datasets(data_root: Path) -> None:
    """Les autres CSV présents ne court-circuitent pas les contrôles de concours."""
    (data_root / "data" / "masse_salariale_corps.csv").write_text(
        "body_code,value,unit,start_date,end_date,source\n"
        "PE,1,EUR,2025-01-01,,menj-crpe-2025\n",
        "utf-8",
    )
    _write(
        data_root,
        HEADER,
        "PE,2025,externe,8117,43525,18524,18525,menj-crpe-2025",
        "PE,2025,externe,8117,43525,18524,7576,menj-crpe-2025",
    )
    code, lines = _report_of(data_root)
    assert code == 1
    assert lines == [
        (
            "concours_admis.csv:2 [concours] PE/2025/externe : "
            "admis 18525 > présents 18524"
        ),
        "concours_admis.csv:3 [unicite] PE/2025/externe : doublon de la ligne 2",
        "Récapitulatif : 2 anomalie(s) détectée(s).",
    ]

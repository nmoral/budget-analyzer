"""Dataset ``concours_admis`` : schéma (C1) et données CRPE sourcées (C2)."""

import csv
import hashlib
import json
import os
import re
import shutil
import subprocess
from html.parser import HTMLParser
from pathlib import Path

import pytest

from contract import schema_fields, validate_csv
from ingest import verify_manifest
from sources.concours_admis import SCRIPT_VERSION, SOURCE, URL, concours_rows
from sources.concours_admis import main as main_concours
from sources.concours_admis import run as run_concours

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
SCHEMA = REAL_DATA / "schemas" / "concours_admis.json"
PUBLICATIONS = REAL_DATA / "sources" / "publications"
RETRIEVED_AT = "2026-11-16"
HEADER = "body_code,session,voie,postes,inscrits,presents,admis,source"
COUNT_COLUMNS = ("session", "postes", "inscrits", "presents", "admis")
LINE_2025_EXTERNE = "PE,2025,externe,8117,43525,18524,7576,menj-crpe-2025"
SESSIONS = ("2021", "2022", "2023", "2024", "2025")
VOIES_2025 = [
    "externe",
    "externe_supplementaire",
    "externe_special",
    "troisieme",
    "second_interne",
    "second_interne_special",
    "interne_exceptionnel",
]
PUBLIC_SECTION = "Données chiffrées des concours de l'enseignement public"
PRIVATE_SECTION = "Données chiffrées des concours de l'enseignement privé"
POLYNESIE = "Polynésie française"


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(
        REAL_DATA / "sources",
        tmp_path / "sources",
        ignore=shutil.ignore_patterns("publications"),
    )
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _generated_rows(data_dir: Path) -> list[dict[str, str]]:
    with run_concours().open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def _raw_rows() -> list[dict[str, str]]:
    raw = (REAL_DATA / "sources" / "concours_admis.json").read_text("utf-8")
    rows: list[dict[str, str]] = json.loads(raw)["rows"]
    return rows


def _registry() -> dict[str, dict[str, str]]:
    registry: dict[str, dict[str, str]] = json.loads(
        (REAL_DATA / "sources.json").read_text("utf-8")
    )
    return registry


class _TextLines(HTMLParser):
    """Texte visible d'une page HTML, une ligne par bloc (hors script/style)."""

    _BREAKS = frozenset({"p", "li", "h1", "h2", "h3", "h4", "tr", "div", "table"})

    def __init__(self) -> None:
        super().__init__()
        self._parts: list[str] = []
        self._skip = 0

    def handle_starttag(self, tag: str, attrs: object) -> None:
        if tag in ("script", "style"):
            self._skip += 1
        if tag in self._BREAKS:
            self._parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in ("script", "style"):
            self._skip -= 1

    def handle_data(self, data: str) -> None:
        if not self._skip:
            self._parts.append(data.replace("\xa0", " ").strip())

    def lines(self) -> list[str]:
        text = "".join(self._parts)
        return [line for line in text.split("\n") if line.strip()]


def _public_section(session: str) -> list[str]:
    parser = _TextLines()
    parser.feed((PUBLICATIONS / f"crpe-session-{session}.html").read_text("utf-8"))
    lines = parser.lines()
    return lines[lines.index(PUBLIC_SECTION) : lines.index(PRIVATE_SECTION)]


def _block_figures(section: list[str], block: str) -> dict[str, str]:
    """Totaux nationaux (postes, inscrits, présents, admis) d'un bloc publié."""
    start = section.index(block)
    labels = {
        "postes offerts": "postes",
        "candidats inscrits": "inscrits",
        "candidats présents aux épreuves écrites": "presents",
        "candidats admis": "admis",
    }
    figures: dict[str, str] = {}
    for line in section[start + 1 : start + 9]:
        match = re.fullmatch(r"([\d ]+)(.+)", line)
        if match and match.group(2) in labels:
            figures.setdefault(labels[match.group(2)], match.group(1).replace(" ", ""))
    return figures


# --- C1.1 : schéma ----------------------------------------------------------


def test_schema_is_valid_frictionless_schema() -> None:
    """C1.1 — `concours_admis.json` est un schéma Frictionless valide."""
    result = subprocess.run(
        ["frictionless", "validate", "--trusted", "--type", "schema", str(SCHEMA)],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout


def test_schema_columns_integer_counts_all_required() -> None:
    """C1.1 — colonnes attendues ; session et effectifs entiers ; tout requis."""
    assert schema_fields("concours_admis") == HEADER.split(",")
    fields = {
        field["name"]: field
        for field in json.loads(SCHEMA.read_text("utf-8"))["fields"]
    }
    for column in COUNT_COLUMNS:
        assert fields[column]["type"] == "integer", column
    for column in ("body_code", "voie", "source"):
        assert fields[column]["type"] == "string", column
    for name, field in fields.items():
        assert field["constraints"]["required"] is True, name


# --- C2.1 : ligne nominale 2025 ---------------------------------------------


def test_nominal_2025_externe_line_is_accepted(data_dir: Path) -> None:
    """C2.1 — `PE,2025,externe,8117,43525,18524,7576,menj-crpe-2025` acceptée."""
    out = run_concours()
    assert LINE_2025_EXTERNE in out.read_text("utf-8").splitlines()
    assert validate_csv(out, "concours_admis").valid


def test_concours_rows_keep_schema_columns_only() -> None:
    """Le libellé du bloc source (traçabilité du brut) n'entre pas dans le CSV."""
    raw = [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "block": "Concours externe de recrutement de professeurs des écoles",
            "postes": "8117",
            "inscrits": "43525",
            "presents": "18524",
            "admis": "7576",
            "source": "menj-crpe-2025",
        }
    ]
    assert concours_rows(raw) == [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "postes": "8117",
            "inscrits": "43525",
            "presents": "18524",
            "admis": "7576",
            "source": "menj-crpe-2025",
        }
    ]


# --- C2.2 : une ligne par voie publiée --------------------------------------


def test_session_2025_has_one_line_per_published_voie(data_dir: Path) -> None:
    """C2.2 — session 2025 : une ligne par voie publiée, dans l'ordre de la page."""
    rows = _generated_rows(data_dir)
    voies = [row["voie"] for row in rows if row["session"] == "2025"]
    assert voies == VOIES_2025


def test_voie_absent_a_session_has_no_line(data_dir: Path) -> None:
    """C2.2 — concours interne exceptionnel absent en 2021 et 2022 : pas de ligne."""
    rows = _generated_rows(data_dir)
    by_session = {
        session: {row["voie"] for row in rows if row["session"] == session}
        for session in SESSIONS
    }
    assert by_session["2021"] == set(VOIES_2025) - {"interne_exceptionnel"}
    assert by_session["2022"] == set(VOIES_2025) - {"interne_exceptionnel"}
    for session in ("2023", "2024", "2025"):
        assert by_session[session] == set(VOIES_2025), session


def test_sessions_2021_to_2025_and_pe_only(data_dir: Path) -> None:
    """C2 / C2.4 — sessions 2021→2025, seul le corps PE (aucune ligne ISG)."""
    rows = _generated_rows(data_dir)
    assert sorted({row["session"] for row in rows}) == list(SESSIONS)
    assert {row["body_code"] for row in rows} == {"PE"}
    assert not [row for row in rows if row["body_code"] == "ISG"]


# --- C2.3 : exclusions Polynésie et privé -----------------------------------


def test_polynesie_and_private_blocks_excluded() -> None:
    """C2.3 — blocs Polynésie française et privé (CAFEP) : aucune ligne."""
    blocks = [row["block"] for row in _raw_rows()]
    assert not [block for block in blocks if POLYNESIE in block]
    assert not [block for block in blocks if "certificat d'aptitude" in block]
    for row in _raw_rows():
        section = _public_section(row["session"])
        assert row["block"] in section, row["block"]


def test_polynesie_figures_not_retained(data_dir: Path) -> None:
    """C2.3 — 2025 : le bloc Polynésie (15 postes, 249 inscrits) n'est pas repris."""
    rows = _generated_rows(data_dir)
    assert not [
        row for row in rows if (row["postes"], row["inscrits"]) == ("15", "249")
    ]


# --- C2 : données réelles transcrites des pages archivées -------------------


def test_every_row_matches_its_archived_public_block() -> None:
    """C2 — chaque ligne reprend les totaux nationaux de son bloc sur la page."""
    rows = _raw_rows()
    assert len(rows) == 33
    for row in rows:
        figures = _block_figures(_public_section(row["session"]), row["block"])
        expected = {
            key: row[key] for key in ("postes", "inscrits", "presents", "admis")
        }
        assert figures == expected, (row["session"], row["voie"])


def test_every_public_block_of_each_session_is_transcribed() -> None:
    """C2.2 — aucun bloc public hors Polynésie n'est oublié."""
    for session in SESSIONS:
        section = _public_section(session)
        published = {
            line
            for line in section
            if "recrutement de professeurs des écoles" in line and POLYNESIE not in line
        }
        transcribed = {row["block"] for row in _raw_rows() if row["session"] == session}
        assert transcribed == published, session


def test_publications_archived_match_registry_hash() -> None:
    """C2 — pages archivées, `fileHash` = sha256 de la copie archivée."""
    registry = _registry()
    for session in SESSIONS:
        entry = registry[f"menj-crpe-{session}"]
        page = (PUBLICATIONS / f"crpe-session-{session}.html").read_bytes()
        assert hashlib.sha256(page).hexdigest() == entry["fileHash"], session
        assert entry["url"].startswith("https://www.devenirenseignant.gouv.fr/")


def test_source_locator_cites_page_and_block() -> None:
    """C2 — `locator` cite la page (titre de session) et la section/les blocs."""
    registry = _registry()
    for session in SESSIONS:
        locator = registry[f"menj-crpe-{session}"]["locator"]
        title = (
            "Les données statistiques des concours de recrutement de "
            f"professeurs des écoles de la session {session}"
        )
        assert title in locator, session
        assert PUBLIC_SECTION in locator, session
        blocks = {row["block"] for row in _raw_rows() if row["session"] == session}
        for block in blocks:
            assert block in locator, (session, block)


def test_each_source_is_a_known_slug_of_its_session() -> None:
    """C2 — chaque `source` est le slug `menj-crpe-<session>` déclaré."""
    known = set(_registry())
    for row in _raw_rows():
        assert row["source"] == f"menj-crpe-{row['session']}"
        assert row["source"] in known


# --- C2 : génération reproductible et manifeste -----------------------------


def test_committed_csv_matches_generation(data_dir: Path) -> None:
    """C2 — `make generate KEY=concours_admis` régénère un CSV identique."""
    committed = (REAL_DATA / "data" / "concours_admis.csv").read_bytes()
    assert run_concours().read_bytes() == committed


def test_manifest_is_verifiable(data_dir: Path) -> None:
    run_concours()
    assert verify_manifest("concours_admis") is True
    manifest = json.loads(
        (data_dir / "manifests" / "concours_admis.json").read_text("utf-8")
    )
    assert manifest["source"] == SOURCE
    assert manifest["url"] == URL
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == "sources.concours_admis"
    assert manifest["script_version"] == SCRIPT_VERSION
    assert manifest["raw"] == "concours_admis.json"


def test_committed_manifest_matches_generation(data_dir: Path) -> None:
    """C2 — manifeste versionné cohérent avec la génération."""
    run_concours()
    generated = (data_dir / "manifests" / "concours_admis.json").read_bytes()
    committed = (REAL_DATA / "manifests" / "concours_admis.json").read_bytes()
    assert generated == committed


def test_main_prints_written_csv_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    main_concours()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "concours_admis.csv")

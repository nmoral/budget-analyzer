"""Référentiel ``academies`` (C1) et dataset ``concours_admis_academie`` (C2)."""

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
from sources.concours_admis_academie import (
    SCRIPT_VERSION,
    SOURCE,
    URL,
    UnknownAcademieError,
    academie_rows,
    label_index,
    normalize_label,
    resolve_academie,
)
from sources.concours_admis_academie import main as main_academie
from sources.concours_admis_academie import run as run_academie

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])
SCHEMA = REAL_DATA / "schemas" / "concours_admis_academie.json"
PUBLICATIONS = REAL_DATA / "sources" / "publications"
RETRIEVED_AT = "2026-11-16"
HEADER = "body_code,session,voie,academie,postes,inscrits,presents,admis,source"
INTEGER_COLUMNS = ("session", "postes", "inscrits", "presents", "admis")
FIGURES = ("postes", "inscrits", "presents", "admis")
SESSIONS = ("2021", "2022", "2023", "2024", "2025")
PUBLIC_SECTION = "Données chiffrées des concours de l'enseignement public"
PRIVATE_SECTION = "Données chiffrées des concours de l'enseignement privé"
POLYNESIE_BLOCK = (
    "Concours externe de recrutement de professeurs des écoles du corps de "
    "l’Etat créé pour la Polynésie française"
)
STATUTS = {"metropole", "drom", "com"}

GUADELOUPE = "32"
MAYOTTE = "43"
REUNION = "28"
POLYNESIE = "41"
NOUVELLE_CALEDONIE = "40"
SAINT_PIERRE = "44"
CAEN = "05"
ROUEN = "21"
NORMANDIE = "70"
CRETEIL = "24"


def _referential() -> dict[str, dict[str, object]]:
    path = REAL_DATA / "academies.json"
    referential: dict[str, dict[str, object]] = json.loads(path.read_text("utf-8"))
    return referential


def _registry() -> dict[str, dict[str, str]]:
    registry: dict[str, dict[str, str]] = json.loads(
        (REAL_DATA / "sources.json").read_text("utf-8")
    )
    return registry


def _raw_rows() -> list[dict[str, str]]:
    raw = (REAL_DATA / "sources" / "concours_admis_academie.json").read_text("utf-8")
    rows: list[dict[str, str]] = json.loads(raw)["rows"]
    return rows


def _committed_lines() -> list[str]:
    path = REAL_DATA / "data" / "concours_admis_academie.csv"
    return path.read_text("utf-8").splitlines()


@pytest.fixture
def data_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    shutil.copytree(REAL_DATA / "schemas", tmp_path / "schemas")
    shutil.copytree(
        REAL_DATA / "sources",
        tmp_path / "sources",
        ignore=shutil.ignore_patterns("publications"),
    )
    shutil.copy(REAL_DATA / "academies.json", tmp_path / "academies.json")
    for sub in ("data", "raw", "manifests"):
        (tmp_path / sub).mkdir()
    monkeypatch.setenv("BUDGET_DATA_DIR", str(tmp_path))
    return tmp_path


def _generated_lines(data_dir: Path) -> list[str]:
    return run_academie().read_text("utf-8").splitlines()


# --- Lecture indépendante des pages archivées --------------------------------


class _Page(HTMLParser):
    """Intitulés ``h2``/``h3``, puces ``li`` et lignes de tableau d'une page."""

    def __init__(self) -> None:
        super().__init__()
        self.events: list[tuple[str, object]] = []
        self._text: str | None = None
        self._row: list[str] | None = None
        self._cell: str | None = None

    def handle_starttag(self, tag: str, attrs: object) -> None:
        if tag in ("h2", "h3", "li"):
            self._text = ""
        elif tag == "tr":
            self._row = []
        elif tag in ("td", "th") and self._row is not None:
            self._cell = ""

    def handle_endtag(self, tag: str) -> None:
        if tag in ("h2", "h3", "li") and self._text is not None:
            self.events.append((tag, _clean(self._text)))
            self._text = None
        elif tag in ("td", "th") and self._row is not None and self._cell is not None:
            self._row.append(_clean(self._cell))
            self._cell = None
        elif tag == "tr" and self._row is not None:
            self.events.append(("tr", self._row))
            self._row = None

    def handle_data(self, data: str) -> None:
        if self._cell is not None:
            self._cell += data
        if self._text is not None:
            self._text += data


def _clean(text: str) -> str:
    return " ".join(text.replace("\xa0", " ").replace("\u200b", "").split())


def _events(session: str) -> list[tuple[str, object]]:
    page = _Page()
    page.feed((PUBLICATIONS / f"crpe-session-{session}.html").read_text("utf-8"))
    return page.events


def _blocks(session: str, section: str) -> dict[str, dict[str, object]]:
    """Blocs ``h3`` d'une section : puces (totaux) et lignes de tableau."""
    events = _events(session)
    start = events.index(("h2", section))
    end = next(
        index for index in range(start + 1, len(events)) if events[index][0] == "h2"
    )
    blocks: dict[str, dict[str, object]] = {}
    current: dict[str, object] = {}
    for kind, value in events[start + 1 : end]:
        if kind == "h3":
            current = {"items": [], "table": []}
            blocks[str(value)] = current
        elif kind == "li" and current:
            items = current["items"]
            assert isinstance(items, list)
            items.append(value)
        elif kind == "tr" and current:
            table = current["table"]
            assert isinstance(table, list)
            table.append(value)
    return blocks


def _bullet_figures(items: list[str]) -> dict[str, str]:
    labels = {
        "postes offerts": "postes",
        "candidats inscrits": "inscrits",
        "candidats présents aux épreuves écrites": "presents",
        "candidats admis": "admis",
    }
    figures: dict[str, str] = {}
    for item in items:
        match = re.fullmatch(r"(\d[\d ]*?) ?([^\d ].+)", item)
        if match and match.group(2).strip() in labels:
            key = labels[match.group(2).strip()]
            figures.setdefault(key, match.group(1).replace(" ", ""))
    return figures


def _table_rows(table: list[list[str]]) -> dict[str, dict[str, str]]:
    """Académie → (postes, inscrits, présents, admis), colonnes lues par en-tête."""
    header = [cell.lower() for cell in table[0]]
    columns = {
        "postes": header.index("postes"),
        "inscrits": header.index("inscrits"),
        "presents": header.index("présents"),
        "admis": header.index("admis"),
    }
    return {
        row[0]: {key: row[index].replace(" ", "") for key, index in columns.items()}
        for row in table[1:]
    }


def _all_published_labels() -> set[str]:
    """Tous les libellés d'académie publiés 2021-2025 (public et privé)."""
    labels: set[str] = set()
    for session in SESSIONS:
        for section in (PUBLIC_SECTION, PRIVATE_SECTION):
            for block in _blocks(session, section).values():
                table = block["table"]
                assert isinstance(table, list)
                labels.update(row[0] for row in table[1:])
    return labels


# --- C1 : référentiel des académies -----------------------------------------


def test_c1_1_overseas_drom_one_entry_each_and_reunion_labels_same_code() -> None:
    """C1.1 — Guadeloupe, Mayotte, La Réunion : `drom` ; « RÉUNION » → même code."""
    referential = _referential()
    index = label_index(referential)
    for label, code in (
        ("Guadeloupe", GUADELOUPE),
        ("Mayotte", MAYOTTE),
        ("La Réunion", REUNION),
    ):
        assert resolve_academie(label, index) == code
        assert referential[code]["statut"] == "drom", label
    assert resolve_academie("RÉUNION", index) == REUNION
    assert resolve_academie("Réunion", index) == REUNION
    labels = [str(entry["label"]) for entry in referential.values()]
    for label in ("Guadeloupe", "Mayotte", "La Réunion"):
        assert labels.count(label) == 1, label


def test_c1_2_com_statut() -> None:
    """C1.2 — Polynésie française, Nouvelle-Calédonie, Saint-Pierre-et-Miquelon."""
    referential = _referential()
    index = label_index(referential)
    for label, code in (
        ("Polynésie française", POLYNESIE),
        ("Nouvelle-Calédonie", NOUVELLE_CALEDONIE),
        ("Saint-Pierre-et-Miquelon", SAINT_PIERRE),
    ):
        assert resolve_academie(label, index) == code, label
        assert referential[code]["statut"] == "com", label


def test_c1_3_caen_rouen_normandie_distinct_official_codes() -> None:
    """C1.3 — Caen et Rouen (2021), Normandie (2025) : trois entrées distinctes.

    Codes officiels relus dans les extraits data.education.gouv.fr archivés.
    """
    referential = _referential()
    index = label_index(referential)
    assert resolve_academie("Caen", index) == CAEN
    assert resolve_academie("Rouen", index) == ROUEN
    assert resolve_academie("NORMANDIE", index) == NORMANDIE
    assert len({CAEN, ROUEN, NORMANDIE}) == 3
    for code in (CAEN, ROUEN, NORMANDIE):
        assert referential[code]["statut"] == "metropole"
    lines_2021 = _committed_lines()
    assert any(line.startswith(f"PE,2021,externe,{CAEN},") for line in lines_2021)
    assert any(line.startswith(f"PE,2021,externe,{ROUEN},") for line in lines_2021)
    assert any(line.startswith(f"PE,2025,externe,{NORMANDIE},") for line in lines_2021)
    assert not any(
        line.startswith(f"PE,2021,externe,{NORMANDIE},") for line in lines_2021
    )


def test_c1_every_code_is_the_official_one_of_its_source() -> None:
    """C1 — chaque code = `code_academie` de l'extrait officiel qui le source."""
    registry = _registry()
    index_by_source: dict[str, dict[str, str]] = {}
    for code, entry in _referential().items():
        source = str(entry["source"])
        if source not in index_by_source:
            extract = json.loads((PUBLICATIONS / f"{source}.json").read_text("utf-8"))
            index_by_source[source] = {
                normalize_label(record["libelle_academie"]): record["code_academie"]
                for record in extract["results"]
            }
        assert index_by_source[source][normalize_label(str(entry["label"]))] == code
        assert registry[source]["url"].startswith("https://data.education.gouv.fr/")


def test_c1_referential_sources_archived_with_registry_hash() -> None:
    registry = _registry()
    for source in {str(entry["source"]) for entry in _referential().values()}:
        archived = (PUBLICATIONS / f"{source}.json").read_bytes()
        assert hashlib.sha256(archived).hexdigest() == registry[source]["fileHash"]


def test_c1_statut_and_label_of_every_entry() -> None:
    for code, entry in _referential().items():
        assert re.fullmatch(r"\d{2}", code), code
        assert entry["statut"] in STATUTS, code
        assert str(entry["label"]).strip(), code


def test_c1_every_published_label_is_covered() -> None:
    """C1 — chaque libellé publié 2021-2025 (public et privé) est résolu."""
    index = label_index(_referential())
    labels = _all_published_labels()
    assert "SAINT-PIERRE-ET MIQUELON" in labels
    assert "NOUVELLE CALÉDONIE" in labels
    for label in labels:
        resolve_academie(label, index)


def test_normalize_label_ignores_case_accents_and_punctuation() -> None:
    assert normalize_label("ORLÉANS-TOURS") == "orleans tours"
    assert normalize_label("AIX MARSEILLE") == normalize_label("Aix-Marseille")
    assert normalize_label("  Besançon ") == "besancon"


def test_unknown_label_is_refused() -> None:
    index = label_index(_referential())
    with pytest.raises(UnknownAcademieError, match="Atlantide"):
        resolve_academie("Atlantide", index)


def test_label_index_includes_aliases() -> None:
    index = label_index(
        {"99": {"label": "La Terre", "statut": "com", "aliases": ["Terre"]}}
    )
    assert index == {"la terre": "99", "terre": "99"}


# --- C2 : schéma ---------------------------------------------------------------


def test_schema_is_valid_frictionless_schema() -> None:
    result = subprocess.run(
        ["frictionless", "validate", "--trusted", "--type", "schema", str(SCHEMA)],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout


def test_schema_columns_types_and_required() -> None:
    assert schema_fields("concours_admis_academie") == HEADER.split(",")
    fields = {
        field["name"]: field
        for field in json.loads(SCHEMA.read_text("utf-8"))["fields"]
    }
    for column in INTEGER_COLUMNS:
        assert fields[column]["type"] == "integer", column
    for column in ("body_code", "voie", "academie", "source"):
        assert fields[column]["type"] == "string", column
    for name, field in fields.items():
        assert field["constraints"]["required"] is True, name


# --- C2 : exemples validés ---------------------------------------------------


def test_c2_1_nominal_2025_externe_guadeloupe(data_dir: Path) -> None:
    line = f"PE,2025,externe,{GUADELOUPE},26,442,165,26,menj-crpe-2025-academies"
    assert line in _generated_lines(data_dir)
    assert validate_csv(run_academie(), "concours_admis_academie").valid


def test_c2_2_metropole_2021_externe_caen_admis_above_postes(data_dir: Path) -> None:
    line = f"PE,2021,externe,{CAEN},115,2125,604,116,menj-crpe-2021-academies"
    assert line in _generated_lines(data_dir)


def test_c2_3_polynesie_2021_from_academy_row(data_dir: Path) -> None:
    line = f"PE,2021,externe,{POLYNESIE},10,395,120,10,menj-crpe-2021-academies"
    assert line in _generated_lines(data_dir)
    raw = [
        row
        for row in _raw_rows()
        if row["session"] == "2021" and row["academie_label"] == "Polynésie Française"
    ]
    assert [row["origin"] for row in raw] == ["tableau"]


def test_c2_4_polynesie_2022_to_2025_from_block_externe(data_dir: Path) -> None:
    lines = _generated_lines(data_dir)
    expected = {
        "2022": "12,208,91,12",
        "2023": "15,219,115,15",
        "2024": "15,257,125,15",
        "2025": "15,249,137,15",
    }
    for session, figures in expected.items():
        line = (
            f"PE,{session},externe,{POLYNESIE},{figures},menj-crpe-{session}-academies"
        )
        assert line in lines, session
    blocks = [row for row in _raw_rows() if row["block"] == POLYNESIE_BLOCK]
    assert sorted(row["session"] for row in blocks) == list(expected)
    assert {row["voie"] for row in blocks} == {"externe"}
    assert {row["origin"] for row in blocks} == {"bloc"}


def test_c2_5_polynesie_2024_duplicate_kept_once_from_block(data_dir: Path) -> None:
    lines = _generated_lines(data_dir)
    polynesie_2024 = [
        line for line in lines if line.startswith(f"PE,2024,externe,{POLYNESIE},")
    ]
    assert polynesie_2024 == [
        f"PE,2024,externe,{POLYNESIE},15,257,125,15,menj-crpe-2024-academies"
    ]
    table = _blocks("2024", PUBLIC_SECTION)[
        "Concours externe de recrutement de professeurs des écoles"
    ]["table"]
    assert isinstance(table, list)
    assert _table_rows(table)["POLYNÉSIE FRANCAISE"] == {
        "postes": "15",
        "inscrits": "257",
        "presents": "125",
        "admis": "15",
    }
    raw = [
        row
        for row in _raw_rows()
        if row["session"] == "2024" and "olyn" in row["academie_label"]
    ]
    assert len(raw) == 1
    assert raw[0]["origin"] == "bloc"
    assert "POLYNÉSIE FRANCAISE" in raw[0]["note"]


def test_c2_6_block_without_table_naming_creteil(data_dir: Path) -> None:
    lines = _generated_lines(data_dir)
    rows_2021 = [
        line for line in lines if line.startswith("PE,2021,externe_supplementaire,")
    ]
    assert rows_2021 == [
        (
            f"PE,2021,externe_supplementaire,{CRETEIL},300,5579,3137,300,"
            "menj-crpe-2021-academies"
        )
    ]


def test_c2_6_block_without_named_academy_is_refused() -> None:
    """C2.6 — bloc sans tableau ni académie nommée : génération bloquée."""
    raw = [
        {
            "body_code": "PE",
            "session": "2021",
            "voie": "externe_supplementaire",
            "block": "Concours externe supplémentaire",
            "origin": "bloc",
            "academie_label": "",
            "postes": "300",
            "inscrits": "5579",
            "presents": "3137",
            "admis": "300",
            "source": "menj-crpe-2021-academies",
        }
    ]
    with pytest.raises(UnknownAcademieError):
        academie_rows(raw, label_index(_referential()))


def test_c2_7_private_tables_and_admissibles_lc_absent(data_dir: Path) -> None:
    for row in _raw_rows():
        public = _blocks(row["session"], PUBLIC_SECTION)
        assert row["block"] in public, row["block"]
        assert "certificat d'aptitude" not in row["block"]
    header = _generated_lines(data_dir)[0]
    assert header == HEADER
    for absent in ("admissibles", "liste", "complementaire"):
        assert absent not in header.lower()


def test_academie_rows_map_label_to_code_and_keep_schema_columns() -> None:
    raw = [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "block": "Concours externe",
            "origin": "tableau",
            "academie_label": "GUADELOUPE",
            "note": "x",
            "postes": "26",
            "inscrits": "442",
            "presents": "165",
            "admis": "26",
            "source": "menj-crpe-2025-academies",
        }
    ]
    assert academie_rows(raw, label_index(_referential())) == [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "academie": GUADELOUPE,
            "postes": "26",
            "inscrits": "442",
            "presents": "165",
            "admis": "26",
            "source": "menj-crpe-2025-academies",
        }
    ]


# --- C2 : transcription fidèle des pages archivées ----------------------------


def test_every_raw_row_matches_its_archived_block() -> None:
    """Chaque ligne = ligne du tableau par académies, ou totaux du bloc nommé."""
    for row in _raw_rows():
        block = _blocks(row["session"], PUBLIC_SECTION)[row["block"]]
        table = block["table"]
        items = block["items"]
        assert isinstance(table, list)
        assert isinstance(items, list)
        figures = {key: row[key] for key in FIGURES}
        if row["origin"] == "tableau":
            assert _table_rows(table)[row["academie_label"]] == figures, row
        else:
            assert row["origin"] == "bloc"
            assert _bullet_figures(items) == figures, row
            assert not table or row["block"] == POLYNESIE_BLOCK, row["block"]


def test_every_table_row_of_every_public_block_is_transcribed() -> None:
    """Aucune ligne académie publique oubliée (hors doublon Polynésie 2024)."""
    transcribed = {
        (row["session"], row["block"], row["academie_label"])
        for row in _raw_rows()
        if row["origin"] == "tableau"
    }
    published: set[tuple[str, str, str]] = set()
    for session in SESSIONS:
        for title, block in _blocks(session, PUBLIC_SECTION).items():
            table = block["table"]
            assert isinstance(table, list)
            if table:
                published.update((session, title, name) for name in _table_rows(table))
    missing = published - transcribed
    assert missing == {
        (
            "2024",
            "Concours externe de recrutement de professeurs des écoles",
            "POLYNÉSIE FRANCAISE",
        )
    }
    assert transcribed <= published


def test_sources_are_session_academies_slugs_of_archived_pages() -> None:
    registry = _registry()
    for row in _raw_rows():
        slug = f"menj-crpe-{row['session']}-academies"
        assert row["source"] == slug
    for session in SESSIONS:
        entry = registry[f"menj-crpe-{session}-academies"]
        national = registry[f"menj-crpe-{session}"]
        assert entry["url"] == national["url"]
        assert entry["fileHash"] == national["fileHash"]
        assert "Résultats par académies" in entry["locator"]
        assert len(entry["locator"]) <= 255


# --- C2 : génération reproductible et manifeste -------------------------------


def test_committed_csv_matches_generation(data_dir: Path) -> None:
    committed = (REAL_DATA / "data" / "concours_admis_academie.csv").read_bytes()
    assert run_academie().read_bytes() == committed


def test_manifest_is_verifiable_and_committed(data_dir: Path) -> None:
    run_academie()
    assert verify_manifest("concours_admis_academie") is True
    path = data_dir / "manifests" / "concours_admis_academie.json"
    manifest = json.loads(path.read_text("utf-8"))
    assert manifest["source"] == SOURCE
    assert manifest["url"] == URL
    assert manifest["retrieved_at"] == RETRIEVED_AT
    assert manifest["script"] == "sources.concours_admis_academie"
    assert manifest["script_version"] == SCRIPT_VERSION
    assert manifest["raw"] == "concours_admis_academie.json"
    committed = REAL_DATA / "manifests" / "concours_admis_academie.json"
    assert path.read_bytes() == committed.read_bytes()


def test_main_prints_written_csv_path(
    data_dir: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    main_academie()
    printed = capsys.readouterr().out.strip()
    assert printed == str(data_dir / "data" / "concours_admis_academie.csv")


def test_generated_rows_are_unique_per_key(data_dir: Path) -> None:
    with run_academie().open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    keys = [
        (row["body_code"], row["session"], row["voie"], row["academie"]) for row in rows
    ]
    assert len(keys) == len(set(keys)) == 533

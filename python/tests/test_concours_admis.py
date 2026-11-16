"""Dataset ``concours_admis`` : schéma (C1) et données CRPE sourcées (C2)."""

import csv
import hashlib
import json
import os
import re
import shutil
import subprocess
import unicodedata
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
LINE_2025_EXTERNE = "PE,2025,externe,8102,43276,18387,7561,menj-crpe-2025"
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
FIGURES = ("postes", "inscrits", "presents", "admis")
CELL = "|"
LINE_2021_EXTERNE = "PE,2021,externe,8684,83736,26537,8284,menj-crpe-2021"
EXTERNE = "Concours externe de recrutement de professeurs des écoles"
POLYNESIE_BLOCK = (
    "Concours externe de recrutement de professeurs des écoles du corps de "
    "l’Etat créé pour la Polynésie française"
)


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
    """Texte visible d'une page HTML, une ligne par bloc (hors script/style).

    Chaque cellule ``<td>`` est précédée de ``CELL`` : une ligne de tableau par
    académie se lit ``|Académie|postes|inscrits|…``.
    """

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
        if tag == "td":
            self._parts.append(CELL)

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


def _block_lines(section: list[str], block: str) -> list[str]:
    """Lignes d'un bloc publié, de son intitulé (exclu) au bloc suivant."""
    start = section.index(block) + 1
    end = next(
        (
            index
            for index in range(start, len(section))
            if "recrutement de professeurs des écoles" in section[index]
        ),
        len(section),
    )
    return section[start:end]


def _academy_rows(section: list[str], block: str) -> dict[str, dict[str, str]]:
    """Lignes « Résultats par académies » d'un bloc : académie → effectifs."""
    rows: dict[str, dict[str, str]] = {}
    for line in _block_lines(section, block):
        if not line.startswith(CELL):
            continue
        name, *counts = line.split(CELL)[1:]
        postes, inscrits, presents, _admissibles, admis = (
            count.replace(" ", "") for count in counts[:5]
        )
        rows[name] = dict(
            zip(FIGURES, (postes, inscrits, presents, admis), strict=True)
        )
    return rows


def _is_polynesie(name: str) -> bool:
    """« Polynésie Française », « POLYNÉSIE FRANCAISE »… (casse et accents)."""
    decomposed = unicodedata.normalize("NFKD", name)
    plain = "".join(char for char in decomposed if not unicodedata.combining(char))
    return "polynesie" in plain.lower()


def _sum(parts: list[dict[str, str]]) -> dict[str, str]:
    return {key: str(sum(int(part[key]) for part in parts)) for key in FIGURES}


def _minus(total: dict[str, str], parts: list[dict[str, str]]) -> dict[str, str]:
    return {
        key: str(int(total[key]) - sum(int(part[key]) for part in parts))
        for key in FIGURES
    }


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
    """C2.1 — `PE,2025,externe,8102,43276,18387,7561,menj-crpe-2025` acceptée."""
    out = run_concours()
    assert LINE_2025_EXTERNE in out.read_text("utf-8").splitlines()
    assert validate_csv(out, "concours_admis").valid


def test_concours_rows_keep_schema_columns_only() -> None:
    """Bloc source et retranchements (traçabilité du brut) hors du CSV."""
    raw = [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "block": "Concours externe de recrutement de professeurs des écoles",
            "published_total": {"postes": "8117"},
            "excluded": [{"kind": "bloc", "postes": "15"}],
            "note": "retranchement",
            "postes": "8102",
            "inscrits": "43276",
            "presents": "18387",
            "admis": "7561",
            "source": "menj-crpe-2025",
        }
    ]
    assert concours_rows(raw) == [
        {
            "body_code": "PE",
            "session": "2025",
            "voie": "externe",
            "postes": "8102",
            "inscrits": "43276",
            "presents": "18387",
            "admis": "7561",
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


def test_2025_externe_total_excludes_polynesie_block(data_dir: Path) -> None:
    """C2.1 / C2.3 — 2025 externe : le bloc Polynésie est retranché du total.

    Recalculé depuis la page archivée : total national publié moins le bloc
    séparé « … corps de l'Etat créé pour la Polynésie française » (15 / 249 /
    137 / 15), compté dans ce total ; égal à la somme des académies.
    """
    section = _public_section("2025")
    published = _block_figures(section, EXTERNE)
    polynesie = _block_figures(section, POLYNESIE_BLOCK)
    assert polynesie == {
        "postes": "15",
        "inscrits": "249",
        "presents": "137",
        "admis": "15",
    }
    expected = _minus(published, [polynesie])
    assert expected == _sum(list(_academy_rows(section, EXTERNE).values()))
    line = ",".join(["PE", "2025", "externe", *expected.values(), "menj-crpe-2025"])
    assert line == LINE_2025_EXTERNE
    rows = _generated_rows(data_dir)
    assert not [
        row
        for row in rows
        if (row["postes"], row["inscrits"]) in (("15", "249"), ("8117", "43525"))
    ]


def test_2021_externe_total_excludes_polynesie_academy_row(data_dir: Path) -> None:
    """C2.3 — 2021 externe : la ligne académie Polynésie est retranchée du total.

    Recalculé depuis la page archivée : total national publié moins la ligne
    « Polynésie Française » du tableau « Résultats par académies ».
    """
    section = _public_section("2021")
    published = _block_figures(section, EXTERNE)
    polynesie = _academy_rows(section, EXTERNE)["Polynésie Française"]
    assert polynesie == {
        "postes": "10",
        "inscrits": "395",
        "presents": "120",
        "admis": "10",
    }
    expected = _minus(published, [polynesie])
    line = ",".join(["PE", "2021", "externe", *expected.values(), "menj-crpe-2021"])
    assert line == LINE_2021_EXTERNE
    csv_lines = (REAL_DATA / "data" / "concours_admis.csv").read_text("utf-8")
    assert LINE_2021_EXTERNE in csv_lines.splitlines()
    assert LINE_2021_EXTERNE in run_concours().read_text("utf-8").splitlines()


def test_retranchements_documented_in_raw() -> None:
    """C2.3 — le brut garde le total publié et l'élément Polynésie retranché."""
    by_key = {(raw["session"], raw["voie"]): raw for raw in _raw_rows()}
    assert {key for key, raw in by_key.items() if "excluded" in raw} == {
        ("2021", "externe"),
        ("2025", "externe"),
    }
    assert by_key[("2021", "externe")]["published_total"] == {
        "postes": "8694",
        "inscrits": "84131",
        "presents": "26657",
        "admis": "8294",
    }
    assert by_key[("2021", "externe")]["excluded"] == [
        {
            "kind": "academie",
            "label": "Polynésie Française",
            "postes": "10",
            "inscrits": "395",
            "presents": "120",
            "admis": "10",
        }
    ]
    assert by_key[("2025", "externe")]["published_total"] == {
        "postes": "8117",
        "inscrits": "43525",
        "presents": "18524",
        "admis": "7576",
    }
    assert by_key[("2025", "externe")]["excluded"] == [
        {
            "kind": "bloc",
            "label": POLYNESIE_BLOCK,
            "postes": "15",
            "inscrits": "249",
            "presents": "137",
            "admis": "15",
        }
    ]
    for raw in by_key.values():
        assert ("published_total" in raw) == ("excluded" in raw) == ("note" in raw)


def test_no_polynesie_academy_row_counted_in_retained_blocks() -> None:
    """C2.3 — toutes sessions : aucune ligne académie Polynésie n'est comptée.

    Pour chaque bloc public repris doté d'un tableau « Résultats par
    académies », la valeur retenue est la somme des académies hors toute ligne
    Polynésie (casse et accents indifférents). 2024 externe : la ligne figure
    au tableau sans être comptée dans le total publié, rien n'est retranché.
    """
    tables_with_polynesie = []
    for row in _raw_rows():
        academies = _academy_rows(_public_section(row["session"]), row["block"])
        if not academies:
            continue
        kept = [
            figures for name, figures in academies.items() if not _is_polynesie(name)
        ]
        retained = {key: row[key] for key in FIGURES}
        assert _sum(kept) == retained, (row["session"], row["voie"])
        if len(kept) < len(academies):
            tables_with_polynesie.append((row["session"], row["voie"]))
    assert tables_with_polynesie == [("2021", "externe"), ("2024", "externe")]


def test_polynesie_name_matched_whatever_case_and_accents() -> None:
    assert _is_polynesie("Polynésie Française")
    assert _is_polynesie("POLYNÉSIE FRANCAISE")
    assert _is_polynesie("polynesie")
    assert not _is_polynesie("Poitiers")


def test_academy_rows_parsed_from_each_retained_table() -> None:
    """Le tableau par académies de chaque bloc doté d'un tableau est bien lu."""
    section = _public_section("2021")
    rows = _academy_rows(
        section, "Concours externe de recrutement de professeurs des écoles"
    )
    assert len(rows) == 32
    assert rows["Créteil"] == {
        "postes": "1420",
        "inscrits": "4735",
        "presents": "1640",
        "admis": "1056",
    }
    assert "Bordeaux" not in _academy_rows(
        section,
        "Concours externe supplémentaire de recrutement de professeurs des écoles "
        "(académie de Créteil)",
    )


# --- C2 : données réelles transcrites des pages archivées -------------------


def test_every_row_matches_its_archived_public_block() -> None:
    """C2 — chaque ligne = total publié de son bloc − retranchements Polynésie.

    Le total publié et chaque élément retranché (ligne académie ou bloc) sont
    relus sur la page archivée.
    """
    rows = _raw_rows()
    assert len(rows) == 33
    for row in rows:
        section = _public_section(row["session"])
        figures = _block_figures(section, row["block"])
        retained = {key: row[key] for key in FIGURES}
        excluded = row.get("excluded", [])
        published = row.get("published_total", retained)
        assert figures == published, (row["session"], row["voie"])
        assert _minus(figures, excluded) == retained, (row["session"], row["voie"])
        for entry in excluded:
            assert _is_polynesie(entry["label"]), entry["label"]
            on_page = (
                _academy_rows(section, row["block"])[entry["label"]]
                if entry["kind"] == "academie"
                else _block_figures(section, entry["label"])
            )
            assert on_page == {key: entry[key] for key in FIGURES}, entry["label"]


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
    """C2 — `locator` cite la page (titre de session) et la section publique.

    Le libellé exact de chaque bloc est porté ligne à ligne par le brut (`block`).
    """
    registry = _registry()
    for session in SESSIONS:
        locator = registry[f"menj-crpe-{session}"]["locator"]
        title = (
            "Les données statistiques des concours de recrutement de "
            f"professeurs des écoles de la session {session}"
        )
        assert title in locator, session
        assert PUBLIC_SECTION in locator, session
        assert "totaux nationaux par concours" in locator, session


def test_every_registry_locator_fits_source_column() -> None:
    """Chaque `locator` de sources.json tient dans `source.locator` (VARCHAR 255)."""
    for slug, entry in _registry().items():
        assert len(entry["locator"]) <= 255, slug


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

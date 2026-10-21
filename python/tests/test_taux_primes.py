import hashlib
import json
import os
import shutil
from pathlib import Path

import pytest

from contract import validate_csv
from ingest import verify_manifest

REAL_DATA = Path(os.environ["BUDGET_DATA_DIR"])

PERMALINK_DGAFP = (
    "https://www.fonction-publique.gouv.fr/toutes-les-publications/"
    "rapport-annuel-sur-letat-de-la-fonction-publique-edition-2025"
)
PERMALINK_INSEE = "https://www.insee.fr/fr/statistiques/8612479"
PUBLICATIONS = {
    "dgafp-ra-2025.pdf": (
        "1e85e8b295b482848d613f5a31d91c171906cb09c510534ea972bca3eaf12a48"
    ),
    "insee-ip-2067.pdf": (
        "d35011dd12452888a03e877519811a0fadcab390e7d71e42318b2633d9676754"
    ),
}


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


def _catalogue_sections(markdown: str) -> dict[str, str]:
    """Decoupe le catalogue en sections par corps (en-tete `## <CODE> ...`)."""
    sections: dict[str, str] = {}
    code: str | None = None
    buffer: list[str] = []
    for line in markdown.splitlines():
        if line.startswith("## "):
            if code is not None:
                sections[code] = "\n".join(buffer)
            code = line[3:].split()[0]
            buffer = [line]
        elif code is not None:
            buffer.append(line)
    if code is not None:
        sections[code] = "\n".join(buffer)
    return sections


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


# --- C1.1 / C1.2 : url reelle dans le manifeste ----------------------------
def test_taux_primes_manifest_url_is_dgafp_permalink(data_dir: Path) -> None:
    from sources.taux_primes import run

    run()
    manifest = json.loads((data_dir / "manifests" / "taux_primes.json").read_text())
    assert manifest["url"] == PERMALINK_DGAFP  # C1.1
    assert manifest["url"] != "https://www.insee.fr/fr/statistiques"  # C1.2


# --- C2.1 / C2.2 : publications sources archivees --------------------------
def test_publications_archived_with_expected_sha256() -> None:
    publications = REAL_DATA / "sources" / "publications"
    for name, expected_sha in PUBLICATIONS.items():
        path = publications / name
        assert path.exists(), f"publication manquante : {name}"
        actual_sha = hashlib.sha256(path.read_bytes()).hexdigest()
        assert actual_sha == expected_sha, f"sha256 inattendu pour {name}"


# --- C3.1 / C3.2 : catalogue cite permalien + chemin archive ---------------
def test_catalogue_cites_permalink_and_archived_path() -> None:
    catalogue = (REAL_DATA / "catalogue_primes.md").read_text("utf-8")
    sections = _catalogue_sections(catalogue)
    pe = sections["PE"]
    assert PERMALINK_DGAFP in pe  # C3.1
    assert "data/sources/publications/dgafp-ra-2025.pdf" in pe  # C3.1
    isg = sections["ISG"]
    assert PERMALINK_INSEE in isg  # C3.2
    assert "data/sources/publications/insee-ip-2067.pdf" in isg  # C3.2


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
    sections = _catalogue_sections(catalogue)
    lines = _data_lines(out)
    body_codes = {line.split(",")[0] for line in lines}
    # une entree par corps, ni manquante ni en trop
    assert set(sections) == body_codes
    for line in lines:
        body_code, _value, _year, source, fallback = line.split(",")
        section = sections[body_code].lower()
        # la source de CE corps est documentee dans SA section
        assert source in section
        # le statut repli propre a CE corps (echoue si on inverse oui/non)
        repli = "oui" if fallback == "true" else "non"
        assert f"**repli** : {repli}" in section
        # la raison du choix est tracee dans la section (C4.1)
        assert "raison du choix" in section

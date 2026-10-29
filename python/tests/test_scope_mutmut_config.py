"""Vérifie l'injection d'`only_mutate` dans pyproject.toml (scoping mutmut PR, C4).

Régression R5 (revue J4) : les chemins injectés proviennent de noms de fichiers d'une
PR non validée ; un chemin contenant un guillemet corromprait le TOML. On exige donc
soit un TOML valide, soit un rejet explicite sans écriture.
"""

from __future__ import annotations

import importlib.util
import tomllib
from pathlib import Path
from types import ModuleType

# Ancre sur le vrai dossier `python/` : sous mutmut, les tests tournent depuis une copie
# `python/mutants/tests/` où `scripts/` n'est pas recopié.
_PYTHON_DIR = next(p for p in Path(__file__).resolve().parents if p.name == "python")
SCRIPT = _PYTHON_DIR / "scripts" / "scope_mutmut_config.py"


def _load_module() -> ModuleType:
    spec = importlib.util.spec_from_file_location("scope_mutmut_config", SCRIPT)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _pyproject(tmp_path: Path) -> Path:
    content = '[tool.mutmut]\nsource_paths = ["src/sources"]\n'
    path = tmp_path / "pyproject.toml"
    path.write_text(content, encoding="utf-8")
    return path


def test_valid_paths_produce_valid_toml(tmp_path: Path, monkeypatch) -> None:
    mod = _load_module()
    pyproject = _pyproject(tmp_path)
    monkeypatch.chdir(tmp_path)

    rc = mod.main(["src/sources/point_indice.py", "src/checks/__main__.py"])

    assert rc == 0
    parsed = tomllib.loads(pyproject.read_text(encoding="utf-8"))
    assert parsed["tool"]["mutmut"]["only_mutate"] == [
        "src/sources/point_indice.py",
        "src/checks/__main__.py",
    ]


def test_hostile_path_is_rejected_and_file_untouched(
    tmp_path: Path, monkeypatch
) -> None:
    mod = _load_module()
    pyproject = _pyproject(tmp_path)
    before = pyproject.read_text(encoding="utf-8")
    monkeypatch.chdir(tmp_path)

    # Un chemin contenant un guillemet matche .*\.py$ mais casserait le TOML.
    rc = mod.main(['src/sources/evil".py'])

    assert rc == 1
    # Aucune écriture : le pyproject reste strictement inchangé.
    assert pyproject.read_text(encoding="utf-8") == before
    assert "only_mutate" not in pyproject.read_text(encoding="utf-8")

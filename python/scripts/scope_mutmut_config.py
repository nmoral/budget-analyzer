"""Restreint le périmètre de mutation de mutmut (CI, PR uniquement).

Injecte un ``only_mutate = [...]`` dans la section ``[tool.mutmut]`` de
``pyproject.toml`` à partir des fichiers passés en argument (le cœur modifié par la
PR). mutmut ne mute alors que ces fichiers ; ``export-cicd-stats`` et donc le MSI
(``scripts/check_msi.py``) portent sur ce seul périmètre, qui reste **bloquant**.

Ne touche ni ``source_paths`` ni aucun seuil. N'est jamais appelé en local : la
chaîne ``make qa`` / ``make qa-python`` garde mutmut complet. La modification du
``pyproject.toml`` est transitoire (checkout CI éphémère) et n'est pas commitée.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

MARKER = "[tool.mutmut]"

# Les chemins proviennent de noms de fichiers d'une PR non validée (via git diff).
# On restreint aux caractères attendus d'un chemin de module Python pour qu'un chemin
# hostile (p. ex. contenant un guillemet) ne puisse pas corrompre le TOML ni y injecter
# autre chose. Tout chemin hors de ce jeu est rejeté explicitement.
SAFE_PATH = re.compile(r"^[A-Za-z0-9_./-]+$")


def build_only_mutate(files: list[str]) -> str:
    for f in files:
        if not SAFE_PATH.match(f):
            raise ValueError(f"chemin refusé (caractères non autorisés) : {f!r}")
    patterns = ", ".join(f'"{f}"' for f in files)
    return f"only_mutate = [{patterns}]"


def main(files: list[str]) -> int:
    if not files:
        print("scope_mutmut_config : aucun fichier fourni", file=sys.stderr)
        return 1
    pyproject = Path("pyproject.toml")
    if not pyproject.exists():
        print("scope_mutmut_config : pyproject.toml introuvable", file=sys.stderr)
        return 1

    try:
        only_mutate = build_only_mutate(files)
    except ValueError as err:
        print(f"scope_mutmut_config : {err}", file=sys.stderr)
        return 1
    out: list[str] = []
    injected = False
    for line in pyproject.read_text(encoding="utf-8").splitlines():
        out.append(line)
        if line.strip() == MARKER and not injected:
            out.append(only_mutate)
            injected = True

    if not injected:
        print(f"scope_mutmut_config : section {MARKER} introuvable", file=sys.stderr)
        return 1

    pyproject.write_text("\n".join(out) + "\n", encoding="utf-8")
    print(f"scope_mutmut_config : {only_mutate}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

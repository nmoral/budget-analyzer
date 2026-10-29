#!/usr/bin/env bash
# mutmut scopé au cœur modifié par une PR (C4).
#
# Ne mute que les fichiers src/{contract,ingest,sources,checks}/*.py modifiés depuis
# le merge-base avec la branche de base ; le MSI (scripts/check_msi.py) reste bloquant
# sur ce périmètre réduit. No-op vert si aucun fichier cœur n'est modifié, ou si le
# périmètre scopé ne produit aucun mutant (total=0 : rien à tuer, MSI non calculable).
#
# N'est utilisé qu'en CI sur `pull_request`. En local et sur push main, mutmut reste
# complet via `make qa-python` (ce script n'est pas appelé).
#
# Variables :
#   MUTMUT_BASE             ref de base pour le diff (défaut : origin/main)
#   MUTMUT_PRINT_SCOPE_ONLY si "1", affiche le périmètre calculé puis sort (tests)
set -euo pipefail

BASE="${MUTMUT_BASE:-origin/main}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

merge_base="$(git merge-base "$BASE" HEAD)"
# --diff-filter=ACMR : seulement ajoutés/copiés/modifiés/renommés (nouveau chemin).
# On exclut ainsi les fichiers supprimés et l'ANCIEN chemin d'un renommage, qui
# n'existent plus sur HEAD et casseraient only_mutate (mutmut sur un fichier absent).
mapfile -t changed < <(
  git diff --name-only --diff-filter=ACMR "$merge_base" HEAD \
    | grep -E '^python/src/(contract|ingest|sources|checks)/.*\.py$' || true
)

if [ "${#changed[@]}" -eq 0 ]; then
  echo "mutmut (PR) : aucun fichier cœur modifié depuis $BASE -> no-op vert."
  exit 0
fi

# Chemins relatifs à python/ (src/...), utilisables tels quels comme motifs only_mutate.
scope=()
for f in "${changed[@]}"; do
  scope+=("${f#python/}")
done
echo "mutmut (PR) : périmètre = ${scope[*]}"

if [ "${MUTMUT_PRINT_SCOPE_ONLY:-}" = "1" ]; then
  exit 0
fi

cd "$ROOT/python"
python3 scripts/scope_mutmut_config.py "${scope[@]}"
BUDGET_DATA_DIR="$ROOT/data" uv run mutmut run
uv run mutmut export-cicd-stats

# Un fichier cœur peut être modifié sans produire le moindre mutant (p. ex. un module
# réduit à un docstring ou à des imports). Dans ce cas export-cicd-stats donne total=0,
# et check_msi.py calculerait MSI=0 % -> exit 1 -> PR bloquée à tort. Aucun mutant à
# tuer ⇒ MSI non calculable ⇒ no-op vert (symétrique du cas « aucun cœur modifié »).
# Ce court-circuit ne vaut que pour le chemin scopé (PR) ; le run full (local/push main)
# ne passe jamais ici (total > 0) et garde check_msi.py bloquant à l'identique.
total="$(
  python3 -c 'import json, pathlib; print(json.loads(pathlib.Path("mutants/mutmut-cicd-stats.json").read_text(encoding="utf-8")).get("total", 0))'
)"
if [ "$total" -eq 0 ]; then
  echo "mutmut (PR) : périmètre sans mutant (total=0) -> no-op vert (MSI non calculable)."
  exit 0
fi

uv run python scripts/check_msi.py

#!/usr/bin/env bash
# mutmut scopé au cœur modifié par une PR (C4).
#
# Ne mute que les fichiers src/{contract,ingest,sources,checks}/*.py modifiés depuis
# le merge-base avec la branche de base ; le MSI (scripts/check_msi.py) reste bloquant
# sur ce périmètre réduit. Aucun fichier cœur modifié -> étape no-op verte.
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
mapfile -t changed < <(
  git diff --name-only "$merge_base" HEAD \
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
uv run python scripts/check_msi.py

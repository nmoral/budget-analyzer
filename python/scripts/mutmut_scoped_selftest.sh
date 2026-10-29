#!/usr/bin/env bash
# Auto-test de mutmut_scoped.sh (revue J4 : R2 no-op total=0, R4 diff-filter).
#
# N'est PAS collecté par pytest (hors tests/) : il monte un dépôt git bac à sable et
# un faux `uv` (pour ne PAS lancer un vrai mutmut), puis vérifie les deux corrections.
# Volontairement hors de la suite pytest : il spawn des sous-processus git/bash et
# serait rejoué une fois par mutant pendant le `mutmut run` complet de `make qa`.
#
# Usage : bash python/scripts/mutmut_scoped_selftest.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SB="$(mktemp -d)"
trap 'rm -rf "$SB"' EXIT

mkdir -p "$SB/python/scripts" "$SB/bin"
cp "$HERE/mutmut_scoped.sh" "$HERE/scope_mutmut_config.py" "$HERE/check_msi.py" \
  "$SB/python/scripts/"
printf '[tool.mutmut]\nsource_paths = ["src/sources"]\n' > "$SB/python/pyproject.toml"

# Faux `uv` : `uv run mutmut run` = no-op ; `uv run mutmut export-cicd-stats` recopie
# le JSON de stats choisi ; `uv run python ...` délègue au vrai python3.
cat > "$SB/bin/uv" <<'UV'
#!/usr/bin/env bash
case "$2" in
  mutmut)
    if [ "$3" = "export-cicd-stats" ]; then
      mkdir -p mutants
      cp "$FAKE_STATS" mutants/mutmut-cicd-stats.json
    fi
    exit 0
    ;;
  python)
    shift 2
    exec python3 "$@"
    ;;
esac
exit 0
UV
chmod +x "$SB/bin/uv"
export PATH="$SB/bin:$PATH"

cd "$SB"
git init -q
git config user.email selftest@example.com
git config user.name selftest

mkdir -p python/src/sources python/src/checks
echo "x = 1" > python/src/sources/foo.py
echo "y = 2" > python/src/checks/gone.py
git add -A && git commit -qm base
BASE="$(git rev-parse HEAD)"

# HEAD : modifie un fichier cœur ET supprime un autre fichier cœur (renommage/suppression).
echo "x = 2" > python/src/sources/foo.py
git rm -q python/src/checks/gone.py
git add -A && git commit -qm head

fail=0
check() { if [ "$1" = "$2" ]; then echo "PASS: $3"; else echo "FAIL: $3 (attendu $2, obtenu $1)"; fail=1; fi; }

# R4 : le fichier supprimé ne doit pas entrer dans le périmètre (diff-filter ACMR).
scope_out="$(MUTMUT_BASE="$BASE" MUTMUT_PRINT_SCOPE_ONLY=1 \
  bash python/scripts/mutmut_scoped.sh)"
case "$scope_out" in *"src/sources/foo.py"*) r=0;; *) r=1;; esac
check "$r" 0 "R4 : fichier coeur modifie present dans le perimetre"
case "$scope_out" in *gone.py*) r=1;; *) r=0;; esac
check "$r" 0 "R4 : fichier coeur supprime absent du perimetre"

# R2a : périmètre non vide mais total=0 -> no-op vert (exit 0), SANS bloquer.
printf '{"total": 0, "killed": 0, "timeout": 0, "skipped": 0}' > "$SB/stats0.json"
rc=0
FAKE_STATS="$SB/stats0.json" MUTMUT_BASE="$BASE" \
  bash python/scripts/mutmut_scoped.sh > "$SB/out2a.txt" 2>&1 || rc=$?
check "$rc" 0 "R2a : total=0 -> exit 0 (no-op vert)"
case "$(cat "$SB/out2a.txt")" in *"no-op vert"*) r=0;; *) r=1;; esac
check "$r" 0 "R2a : message no-op affiche"

# R2b : total>0 sous le seuil -> check_msi bloque toujours (exit 1).
printf '{"total": 10, "killed": 1, "timeout": 0, "skipped": 0}' > "$SB/statslow.json"
rc=0
FAKE_STATS="$SB/statslow.json" MUTMUT_BASE="$BASE" \
  bash python/scripts/mutmut_scoped.sh > /dev/null 2>&1 || rc=$?
check "$rc" 1 "R2b : total>0 sous seuil -> exit 1 (MSI bloquant inchange)"

# R2c : total>0 au-dessus du seuil -> vert.
printf '{"total": 10, "killed": 10, "timeout": 0, "skipped": 0}' > "$SB/statsok.json"
rc=0
FAKE_STATS="$SB/statsok.json" MUTMUT_BASE="$BASE" \
  bash python/scripts/mutmut_scoped.sh > /dev/null 2>&1 || rc=$?
check "$rc" 0 "R2c : total>0 au-dessus du seuil -> exit 0"

if [ "$fail" -ne 0 ]; then echo "SELFTEST KO"; exit 1; fi
echo "SELFTEST OK"

"""Porte de mutation : lit les stats CI/CD de mutmut et bloque si le MSI est trop bas.

Le score (Mutation Score Indicator) est calculé comme mutmut le fait pour son badge :
``(killed + timeout) / (total - skipped) * 100``. Sortie 0 si le MSI atteint le
seuil, 1 sinon (et si aucune donnée n'est disponible).
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

THRESHOLD = 85.0
STATS_PATH = Path("mutants/mutmut-cicd-stats.json")


def mutation_score(stats: dict[str, int]) -> float:
    tested = int(stats.get("total", 0)) - int(stats.get("skipped", 0))
    if tested <= 0:
        return 0.0
    killed = int(stats.get("killed", 0)) + int(stats.get("timeout", 0))
    return killed / tested * 100


def main() -> int:
    if not STATS_PATH.exists():
        print(f"MSI: stats introuvables ({STATS_PATH}); lancez 'mutmut run' d'abord.")
        return 1
    stats = json.loads(STATS_PATH.read_text(encoding="utf-8"))
    score = mutation_score(stats)
    if score < THRESHOLD:
        print(f"MSI {score:.2f}% < seuil {THRESHOLD:.0f}% : echec mutation.")
        return 1
    print(f"MSI {score:.2f}% >= seuil {THRESHOLD:.0f}% : OK.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

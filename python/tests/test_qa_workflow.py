"""Vérifie que le gate agrégateur `qa` du workflow CI bloque le merge sur échec amont.

Régression R1 (revue J4) : un check requis *skipped* est traité comme PASSANT par la
protection de branche. Sans `if: always()` + inspection de `needs.*.result`, le job
`qa` serait skipped quand un amont échoue et ne bloquerait donc pas le merge (inverse
de C3.2). Ce test ne peut pas lancer de runner GitHub : il valide la structure du YAML
qui garantit ce comportement.
"""

from __future__ import annotations

from pathlib import Path

import yaml

# Ancre sur le vrai dossier `python/` : sous mutmut, les tests tournent depuis une copie
# `python/mutants/tests/`, où `parents[2]` ne pointerait pas vers la racine du dépôt.
_PYTHON_DIR = next(p for p in Path(__file__).resolve().parents if p.name == "python")
WORKFLOW = _PYTHON_DIR.parent / ".github" / "workflows" / "qa.yml"


def _load() -> dict:
    return yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))


def test_qa_yaml_is_valid() -> None:
    workflow = _load()
    assert "jobs" in workflow
    assert set(workflow["jobs"]) == {"qa-python", "qa-symfony", "qa"}


def test_gate_runs_even_when_an_upstream_fails() -> None:
    qa = _load()["jobs"]["qa"]
    assert qa["needs"] == ["qa-python", "qa-symfony"]
    # `if: always()` : le gate doit tourner même si un amont échoue/est annulé,
    # sinon il est skipped => considéré passant => merge non bloqué.
    assert "always()" in str(qa["if"])


def test_gate_fails_unless_both_chains_succeed() -> None:
    qa = _load()["jobs"]["qa"]
    gate = qa["steps"][0]
    # Les deux résultats amont sont exposés au script.
    env_values = " ".join(str(v) for v in gate.get("env", {}).values())
    assert "needs.qa-python.result" in env_values
    assert "needs.qa-symfony.result" in env_values
    # Et l'étape échoue explicitement si l'un des deux n'est pas `success`.
    script = gate["run"]
    assert '!= "success"' in script or "!= 'success'" in script
    assert "exit 1" in script

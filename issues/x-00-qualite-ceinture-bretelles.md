# Qualité — ceinture et bretelles (garde-fous de tout le projet)

**Milestone:** Transverse (fondation)
**Labels:** type:infra, type:quality, priority:critical
**Dépend de:** —

## Contexte

Un résultat n'a de valeur dans le débat public que s'il est vérifiable (spec §Contexte). Le projet exige donc le maximum de garde-fous automatiques : rien ne se merge sans que tests, mutation, style, analyse statique et validation de données soient verts. Ces garde-fous s'appliquent à **toutes** les issues (voir la Definition of Done du [README](README.md)), sur les deux briques (Python, Symfony).

## Objectif

Mettre en place la chaîne qualité complète des deux briques et la rendre bloquante en CI et en pre-commit, dès les premières lignes de code.

## Seuils (fixés, bloquants, versionnés)

| Métrique | Seuil global | Cœur métier |
| --- | --- | --- |
| Couverture de lignes | ≥ **90 %** | **100 %** (moteur de calcul PHP ; scripts d'ingestion + contrôles Python) |
| Mutation — MSI | ≥ **85 %** | — |
| Mutation — MSI couvert (covered code) | ≥ **90 %** | — |
| Analyse statique | PHPStan **level max** / mypy **strict**, zéro erreur | idem |

Ces seuils valent pour les deux briques. Les faire baisser exige une décision explicite tracée dans ce fichier.

## Garde-fous exigés

### Brique Symfony / PHP (`budget-analyser/`)

- [ ] **Tests unitaires** : PHPUnit (ou Pest), domaine du moteur testé sans Doctrine ni base.
- [ ] **Tests de mutation** : Infection, MSI ≥ 85 % et MSI couvert ≥ 90 %, bloquant.
- [ ] **Analyse statique** : PHPStan au niveau maximum (level max), sans baseline permissive.
- [ ] **Frontières d'architecture** : Deptrac, bloquant — la couche Domain (moteur, #12) ne dépend ni de `Doctrine\*` ni de l'infra ; les règles enfichables ne dépendent pas entre elles. Garantit automatiquement l'invariant EX-1 (#24).
- [ ] **Style / linter** : PHP-CS-Fixer en PSR-12 (règles CLAUDE.md), `declare(strict_types=1)` partout, en mode `--dry-run` bloquant.
- [ ] **Couverture** : ≥ 90 % global, 100 % sur le moteur de calcul (pas le glue code), bloquant.

### Brique agrégation (Python, dépôt de données)

- [ ] **Tests unitaires** : pytest sur les scripts d'ingestion et contrôles.
- [ ] **Tests de mutation** : mutmut, score ≥ 85 % (≥ 90 % sur le code couvert), bloquant.
- [ ] **Analyse statique** : mypy en mode strict, zéro erreur.
- [ ] **Style / linter** : ruff (lint + format), bloquant.
- [ ] **Validation de données** : schémas Frictionless (#01) + contrôles de cohérence (#08) exécutés en CI.

### Transverse

- [ ] **CI** : un pipeline qui exécute tous les garde-fous ci-dessus ; un merge est impossible si l'un échoue.
- [ ] **Pre-commit** : hooks lançant au minimum lint + analyse statique + tests rapides avant commit.
- [ ] **Commandes uniformes** : une entrée unique par brique (`make qa` / script) qui lance toute la chaîne localement, identique à la CI, **dans Docker** (voir #27).
- [ ] **Seuils** : ceux du tableau ci-dessus, consignés et versionnés ; toute baisse tracée ici.

## Critères d'acceptation

- `make qa` (ou équivalent) passe tous les garde-fous des deux briques en une commande.
- La CI reproduit exactement cette chaîne et bloque le merge au moindre échec (test, mutation sous le seuil, erreur statique, écart de style, données invalides).
- Un test volontairement cassé, une mutation survivante sous le seuil, une erreur PHPStan/mypy et un écart de style sont chacun détectés et font échouer la chaîne.

## Références

Spec §Contexte et objectifs · CLAUDE.md §Standards PHP / §TDD / §SOLID · issues #01, #08, #12, #24

# Issues — Simulateur du coût des recrutements publics

Décomposition des [spécifications](../Simulateur%20du%20co%C3%BBt%20des%20recrutements%20publics%20%E2%80%94%20Sp%C3%A9cifications.md) en issues façon GitHub. Chaque fichier = une issue.

Numérotation globale, préfixe de milestone pour la lisibilité. Les dépendances sont indiquées par `#N` dans chaque issue.

## Structure du monorepo (voir #27)

Projet **conteneurisé et autonome** : un clone + `docker compose up`, rien installé sur l'hôte hors Docker.

```
budget-analyser/
├── python/   # agrégation des sources (brique V0)
├── symfony/  # moteur de calcul + API + front (brique V1)
├── infra/    # déploiement : images prod, compose, CI/CD (#28)
└── data/     # dépôt de données, seul point de passage entre briques (#01)
```

## Definition of Done (vaut pour TOUTES les issues)

Le projet est en **ceinture et bretelles** : aucune issue n'est « done » sans que tous les garde-fous de [#00 — Qualité](x-00-qualite-ceinture-bretelles.md) soient verts sur le code touché. Pour rappel, sur les deux briques :

- **Tests unitaires** (PHPUnit/Pest côté Symfony, pytest côté Python), écrits en premier (TDD, cf. CLAUDE.md).
- **Tests de mutation** : MSI ≥ **85 %**, MSI couvert ≥ **90 %** (Infection / mutmut).
- **Analyse statique** au niveau max (PHPStan level max / mypy strict), zéro erreur.
- **Frontières d'architecture** (Deptrac) : domaine sans Doctrine ni infra, invariant EX-1 vérifié automatiquement.
- **Linter et style** bloquants (PHP-CS-Fixer PSR-12 / ruff).
- **Couverture** : ≥ **90 %** global, **100 %** sur le cœur métier.
- **Validation de données** (schémas Frictionless + contrôles de cohérence) pour tout ce qui touche au dépôt de données.
- **CI verte et bloquante** : pas de merge si un garde-fou échoue.

## Milestone V0 — Socle de données (Python)

| # | Titre | Dépend de |
| --- | --- | --- |
| [01](v0-01-depot-donnees-contrat-schema.md) | Dépôt de données et contrat de schéma (Frictionless) | — |
| [02](v0-02-manifeste-ingestion-idempotente.md) | Manifeste et squelette d'ingestion idempotente | #01 |
| [03](v0-03-ingestion-point-indice.md) | Ingestion du point d'indice | #02 |
| [04](v0-04-ingestion-taux-cotisation.md) | Ingestion des taux de cotisation employeur (CAS civil, CNRACL) | #02 |
| [05](v0-05-ingestion-grilles-indiciaires.md) | Ingestion des grilles indiciaires des deux profils V1 | #02 |
| [06](v0-06-taux-primes-par-corps.md) | Taux de primes moyen par corps | #02 |
| [07](v0-07-couts-moyens-pap-saisie.md) | Saisie manuelle sourcée des coûts moyens PAP | #01 |
| [08](v0-08-controles-coherence.md) | Contrôles de cohérence avant commit | #01 |

## Milestone V1 — Simulateur deux profils (Symfony)

| # | Titre | Dépend de |
| --- | --- | --- |
| [09](v1-09-scaffolding-symfony-postgres.md) | Squelette de l'application Symfony (`symfony/`) | #27 |
| [10](v1-10-modele-donnees-doctrine.md) | Modèle de données Doctrine (valeurs datées) | #09 |
| [11](v1-11-commande-import.md) | Commande d'import et jeu de paramètres versionné | #01, #10 |
| [12](v1-12-moteur-domaine-regles-enfichables.md) | Moteur de domaine PHP + règles enfichables par tag (EX-1) | #10 |
| [13](v1-13-regimes-retraite.md) | Règles de régime de retraite (CAS civil, CNRACL) | #12 |
| [14](v1-14-formule-cout-annuel.md) | Formule de coût annuel d'un agent | #12, #13 |
| [15](v1-15-horizon-annee-1.md) | Horizon année 1 (prorata + année pleine) | #14 |
| [16](v1-16-horizon-regime-permanent.md) | Horizon régime permanent (population stationnaire) | #14 |
| [17](v1-17-horizon-cohorte.md) | Horizon cohorte (année par année, avancement) | #14 |
| [18](v1-18-vues-de-cout.md) | Vues de coût enfichables (4 méthodes retraite) | #14 |
| [19](v1-19-api-json-cache.md) | API JSON scénarios/résultats + cache par version | #12, #18 |
| [20](v1-20-fiche-resultat-transparence.md) | Fiche de résultat et règles de transparence | #19 |
| [21](v1-21-mise-en-regard-faisabilite.md) | Mise en regard budgétaire et indicateur de faisabilité | #19 |
| [22](v1-22-calibrage-pap-p140.md) | Calibrage contre les coûts moyens du PAP programme 140 | #16, #07 |

## Transverse

| # | Titre | Dépend de |
| --- | --- | --- |
| [00](x-00-qualite-ceinture-bretelles.md) | **Qualité — ceinture et bretelles** (garde-fous de tout le projet) | — |
| [27](x-27-monorepo-docker.md) | **Monorepo + Docker** (python / symfony / infra), autonome | — |
| [28](x-28-infra-deploiement.md) | Sous-projet `infra/` — déploiement | #27 |
| [23](x-23-licences-methodologie.md) | Licences (AGPL-3.0 / Licence Ouverte 2.0) et méthodologie | — |
| [24](x-24-revue-architecture-ex1.md) | Revue d'architecture EX-1 (profils tests) | #12, #13, #18 |

## Épics futurs

| # | Titre | Dépend de |
| --- | --- | --- |
| [25](epic-25-v1x-extension-profils.md) | [EPIC V1.x] Extension des profils | #24 |
| [26](epic-26-v2-programmes-candidats.md) | [EPIC V2] Programmes des candidats | #19 |

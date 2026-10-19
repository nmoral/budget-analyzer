# symfony/ — moteur de calcul, API et front (brique V1)

Charge le dépôt `../data/` dans Postgres à l'import, puis calcule et affiche. Le moteur est un domaine PHP sans dépendance à Doctrine ; les règles (régime de retraite, régime indemnitaire, financeur, vue de coût) sont des services taggés (EX-1).

Non encore scaffoldé : voir issues #09 (squelette), #10 (modèle Doctrine), #11 (import), #12 (moteur + règles enfichables) et suivantes.

Qualité (#00) : PHPUnit, Infection (MSI ≥ 85 %), PHPStan level max, PHP-CS-Fixer PSR-12, Deptrac (frontières d'archi) — lancés via `make qa` depuis la racine.

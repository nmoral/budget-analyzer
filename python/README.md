# python/ — agrégation des sources (brique V0)

Un script par source, idempotent, rejouable depuis le brut archivé. Produit les CSV normalisés et leurs manifestes dans `../data/`.

Non encore scaffoldé : voir issues #02 (socle d'ingestion), #03–#07 (une source chacune), #08 (contrôles de cohérence).

Qualité (#00) : pytest, mutmut, mypy strict, ruff — lancés via `make qa` depuis la racine.

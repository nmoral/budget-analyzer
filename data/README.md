# data/ — dépôt de données

Seul point de passage entre les briques (spec §Architecture). Python y écrit, Symfony y lit à l'import. Aucune brique ne lit les sources de l'autre.

```
data/
├── raw/        # fichiers bruts archivés (non suivis par défaut, retrouvables via manifeste)
├── sources/    # bruts versionnés par source (un JSON par source, rejouables)
│   └── publications/  # publications sources archivées (PDF), retrouvables même si les URL disparaissent
├── data/        # CSV normalisés : UTF-8, dates ISO 8601, point décimal, une ligne par valeur datée
├── schemas/    # Frictionless Table Schema, un par type de CSV
└── manifests/  # un manifeste par fichier : source, URL, date de récupération, SHA-256, script+version
```

`sources/publications/` conserve, suivie par git, une copie des publications dont dérivent les CSV, pour garantir la traçabilité même si les URL d'origine disparaissent. Chaque corps du catalogue `catalogue_primes.md` cite le permalien de sa publication **et** le chemin du PDF archivé correspondant.

Contrat de données versionné ; un changement incompatible incrémente la version majeure. Voir issues #01 (contrat), #02 (manifeste), #08 (contrôles de cohérence).

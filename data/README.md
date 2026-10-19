# data/ — dépôt de données

Seul point de passage entre les briques (spec §Architecture). Python y écrit, Symfony y lit à l'import. Aucune brique ne lit les sources de l'autre.

```
data/
├── raw/        # fichiers bruts archivés (non suivis par défaut, retrouvables via manifeste)
├── data/        # CSV normalisés : UTF-8, dates ISO 8601, point décimal, une ligne par valeur datée
├── schemas/    # Frictionless Table Schema, un par type de CSV
└── manifests/  # un manifeste par fichier : source, URL, date de récupération, SHA-256, script+version
```

Contrat de données versionné ; un changement incompatible incrémente la version majeure. Voir issues #01 (contrat), #02 (manifeste), #08 (contrôles de cohérence).

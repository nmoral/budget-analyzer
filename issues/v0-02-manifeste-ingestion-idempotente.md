# Manifeste et squelette d'ingestion idempotente

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature, priority:high
**Dépend de:** #01

## Contexte

Un script par source, idempotent, rejouable à partir du fichier brut archivé. Chaque fichier produit porte un manifeste pour la traçabilité (spec §Agrégation).

## Objectif

Fournir le socle commun aux scripts d'ingestion : archivage du brut, génération du manifeste, écriture CSV validée contre le schéma.

## Tâches

- [ ] Fonction/CLI commune : prend une source, archive le brut dans `raw/`, calcule l'empreinte SHA-256.
- [ ] Génération du manifeste par fichier : source, URL, date de récupération, SHA-256 du brut, nom et version du script.
- [ ] Écriture du CSV validée contre le schéma Frictionless (#01) avant commit.
- [ ] Idempotence : rejouer un script sur le même brut produit un CSV identique (hors date de récupération).

## Critères d'acceptation

- Rejouer le script deux fois sur le même brut donne un CSV identique.
- Un CSV qui ne respecte pas son schéma fait échouer l'écriture avec un message explicite.
- Le manifeste permet de retrouver le brut exact (SHA-256 vérifiable).

## Références

Spec §Agrégation (Python)

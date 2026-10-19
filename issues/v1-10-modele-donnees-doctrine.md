# Modèle de données Doctrine (valeurs datées)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature, priority:high
**Dépend de:** #09

## Contexte

Toute valeur réglementaire est datée (début d'effet, fin éventuelle, source). Le moteur demande toujours « la valeur en vigueur à la date D » (spec §Modèle de données).

## Objectif

Créer les entités Doctrine et migrations du modèle de données.

## Entités (spec §Modèle de données)

- Source (id, intitulé, éditeur, URL, date de récupération, empreinte).
- Paramètre daté (clé, valeur, unité, début, fin, source).
- Corps (code, libellé, versant, catégorie A/B/C).
- Grille (corps, grade, version, début, fin, source) + Échelon (grille, rang, indice brut, indice majoré, durée en mois).
- Taux de primes (corps, valeur, année d'observation, source).
- Régime de retraite (code `CAS_CIVIL`/`CAS_MILITAIRE`/`CNRACL`/`IRCANTEC`, paramètres).
- Financeur (code `ETAT_P140`/`ONDAM`…, libellé, budget de référence).
- Profil (corps, grade/échelon d'entrée, régime de retraite, régime indemnitaire, financeur, statut titulaire/contractuel, calendrier civil/scolaire).
- Scénario (titre, provenance, montant annoncé optionnel, version des paramètres) + Ligne de scénario (scénario, profil, volume, date de recrutement, étalement sur N années).
- Jeu de paramètres (identifiant de version = commit du dépôt, date d'import).

## Tâches

- [ ] Entités + relations + migrations.
- [ ] Repository exposant « valeur en vigueur à la date D » pour les paramètres datés et les grilles.
- [ ] Le Scénario existe dès la V1 (une simulation simple = scénario d'une ligne) — EX-2.

## Critères d'acceptation

- `valeur en vigueur à D` renvoie la bonne ligne aux bornes des périodes (début inclus, fin exclue à vérifier) et rien si aucune période ne couvre D.
- Un scénario d'une seule ligne est représentable.

## Références

Spec §Modèle de données · EX-2

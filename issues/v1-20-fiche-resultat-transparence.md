# Fiche de résultat et règles de transparence

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:frontend, type:feature, priority:high
**Dépend de:** #19

## Contexte

Une simulation produit une fiche lisible par un non-spécialiste, dont **chaque nombre se déplie jusqu'à sa source**. Les règles de transparence (spec §Règles de transparence) sont non négociables.

## Objectif

Afficher la fiche de résultat consommant l'API, avec dépliage intégral jusqu'aux sources et partage par URL stable.

## Contenu de la fiche

- [ ] Coût année 1, régime permanent, cumulé de cohorte, dans la/les vues choisies (budgétaire par défaut).
- [ ] Courbe du coût de la cohorte année par année.
- [ ] Liste des hypothèses appliquées + version du jeu de paramètres.

## Règles de transparence (critères d'acceptation)

- [ ] Aucun nombre sans détail de calcul accessible en un clic : formule, valeurs d'entrée, sources.
- [ ] Chaque valeur d'entrée renvoie à sa source officielle : URL, date de récupération, date d'effet.
- [ ] Toute hypothèse (gel du point, taux de primes moyen, population stationnaire) est nommée.
- [ ] Le financeur est toujours indiqué ; une simulation FPH ne s'affiche jamais en part du budget de l'État.
- [ ] URL stable et rejouable à l'identique (version des paramètres + vue affichée + hypothèses modifiées encodées dans l'URL).
- [ ] Source affichée en clair : éditeur, titre, édition/année, localisation précise (page, tableau, article), puis le lien. Jamais un code ou un lien nu.
- [ ] Affichage de plusieurs vues côte à côte possible ; la vue affichée est toujours indiquée.

## Références

Spec §Sorties, UX et règles de transparence

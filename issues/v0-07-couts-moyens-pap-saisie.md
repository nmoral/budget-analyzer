# Saisie manuelle sourcée des coûts moyens PAP

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature
**Dépend de:** #01

## Contexte

Exception V1 (décision prise) : les coûts moyens chargés par catégorie d'emploi des PAP/RAP sont **saisis à la main** dans un CSV, chaque ligne référençant le document et la page. Un script d'extraction ne sera écrit que si le nombre de programmes suivis le justifie. Ces coûts servent au calibrage du régime permanent (#22).

## Objectif

Définir le schéma et le CSV de saisie manuelle des coûts moyens PAP, sourcés à la page.

## Tâches

- [ ] Schéma CSV : programme, catégorie d'emploi, coût moyen chargé, année, document, page, URL.
- [ ] Saisir les coûts moyens du PAP programme 140 (professeurs des écoles) pour le calibrage.
- [ ] Chaque ligne cite document + page précise (règle de transparence n° 7).

## Critères d'acceptation

- Toute ligne a un document et une page renseignés.
- Le CSV valide contre son schéma.

## Références

Spec §Agrégation (exception V1) · §Décisions prises · §Sources de données (PAP/RAP)

# API JSON scénarios/résultats + cache par version

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature
**Dépend de:** #12, #18

## Contexte

Une API JSON expose scénarios et résultats ; le front consomme l'API. Les résultats sont mis en cache par couple **(scénario, version des paramètres)** (spec §Rendu). Une simulation est rejouable à l'identique grâce à la version des paramètres (transparence n° 5).

## Objectif

Exposer scénarios et résultats via une API JSON, avec cache par (scénario, version des paramètres).

## Tâches

- [ ] Endpoints : créer/lire un scénario, lancer et lire un résultat (horizons + vues choisies).
- [ ] Le résultat porte le détail de calcul par nombre (formule, entrées, sources) pour la transparence (#20).
- [ ] Cache clé = (scénario, version des paramètres) ; invalidation quand la version change.
- [ ] Réponse incluant la version du jeu de paramètres pour rejouabilité.

## Critères d'acceptation

- Deux appels sur le même (scénario, version) renvoient un résultat identique et servent le cache.
- Un changement de version des paramètres produit un nouveau résultat (pas de cache périmé).
- La réponse expose la version des paramètres utilisée.

## Références

Spec §Rendu (Symfony) · §Règles de transparence (5)

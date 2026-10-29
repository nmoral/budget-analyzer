# Documentation fonctionnelle — brique Symfony

Ce document décrit ce que la brique applicative `symfony/` du simulateur de coût des
recrutements publics expose **aujourd'hui**, du point de vue de l'usage et du métier.

La brique Symfony porte, à terme, le moteur de calcul, l'API et le front de la V1.
À ce stade, seul le **squelette** de l'application est en place : il fournit le socle
technique sur lequel les fonctionnalités métier seront ajoutées. Aucune règle de
calcul, aucune donnée métier et aucune interface utilisateur ne sont encore exposées.

## Ce qui est disponible

### Point de santé `/health`

L'application répond sur l'adresse `GET /health` par un statut HTTP 200 et le corps
JSON suivant :

```json
{"status":"ok"}
```

Cet endpoint sert de **sonde de disponibilité** (health check) : il confirme que
l'application est démarrée et répond. Il ne consulte pas la base de données et ne
porte aucune logique métier.

Seule la méthode `GET` est prévue sur cette adresse.

## Ce qui n'est pas encore disponible

Les capacités métier arrivent dans des livraisons ultérieures et ne sont pas
présentes aujourd'hui :

- le modèle de données persistant (entités, migrations) — issue #10 ;
- la commande d'import d'un instantané du dépôt de données — issue #11 ;
- le moteur de calcul du coût des recrutements et ses règles métier — issue #12 ;
- l'API JSON exposant les résultats — issue #19 ;
- le front / les pages Twig — issue #20.

La liste ci-dessus décrit des intentions tracées par leurs issues respectives ; leur
comportement n'est pas défini par la présente livraison.

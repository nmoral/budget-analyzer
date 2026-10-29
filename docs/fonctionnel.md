# Documentation fonctionnelle — brique Symfony

Ce document décrit ce que la brique applicative `symfony/` du simulateur de coût des
recrutements publics expose **aujourd'hui**, du point de vue de l'usage et du métier.

La brique Symfony porte, à terme, le moteur de calcul, l'API et le front de la V1.
À ce stade, l'application fournit un point de santé et le **modèle de données** du
simulateur avec ses règles de sélection des valeurs réglementaires datées. Aucune
règle de calcul, aucune commande d'import et aucune interface utilisateur ne sont
encore exposées : le modèle n'est ni peuplé par un import réel ni consultable via une
API ou un écran.

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

### Modèle de données et sélection des valeurs réglementaires

Le simulateur repose sur un ensemble de données métier structurées. Elles ne sont pas
encore peuplées par un import réel (seules des données de test existent) ni exposées à
un utilisateur, mais le modèle fixe déjà les règles suivantes.

**Objets métier représentés** :

- **Source** : origine documentaire de toute valeur réglementaire (intitulé, éditeur,
  URL, date de récupération, empreinte du fichier).
- **Corps** : cadre statutaire (code, libellé, versant, catégorie A/B/C).
- **Grille** et **Échelon** : grille indiciaire d'un corps pour un grade, dans une
  version donnée, et ses échelons (rang, indice brut, indice majoré, durée en mois).
- **Régime de retraite** : code et libellé (`CAS_CIVIL`, `CAS_MILITAIRE`, `CNRACL`,
  `IRCANTEC`).
- **Financeur** : rattachement budgétaire (code, libellé) et ses codes dans les
  nomenclatures officielles (mission / programme / action pour l'État, sous-objectif
  de l'ONDAM, fonction COFOG).
- **Taux de primes** : taux de primes d'un corps pour une année d'observation (primes
  de la V1).
- **Profil** : unité de simulation (corps, grade et échelon d'entrée, régime de
  retraite, régime indemnitaire, financeur, statut titulaire/contractuel, calendrier
  de référence civil/scolaire).
- **Scénario** et **Ligne de scénario** : un scénario (titre, provenance, montant
  annoncé optionnel) et ses lignes (profil, volume, date de recrutement, étalement sur
  N années).
- **Jeu de paramètres** : instantané daté du dépôt de données (identifiant de version,
  date d'import) garantissant la **reproductibilité** d'un résultat.

**Valeurs datées — « valeur en vigueur à une date »** : les paramètres réglementaires
et les grilles indiciaires portent une **période de validité à bornes incluses**
`[début, fin]`. Une **fin vide** signifie « toujours en vigueur » (période ouverte).
La valeur en vigueur à une date donnée est l'unique valeur dont la période couvre
cette date ; s'il n'en existe aucune, le résultat est vide. Deux périodes successives
peuvent être adjacentes (fin au jour J, début au jour J+1).

**Rattachement à un jeu de paramètres** : chaque valeur datée (paramètre, grille — et
donc ses échelons —, taux de primes) appartient à un jeu de paramètres. La sélection
d'une valeur en vigueur se fait **dans le périmètre d'un jeu** ; à défaut de jeu
précisé, c'est le **dernier jeu importé** qui s'applique. Un même état de la
réglementation peut ainsi coexister en plusieurs versions sans interférence.

**Taux de primes pour une année** : le taux retenu pour un corps et une année Y est
celui de la **dernière année d'observation inférieure ou égale à Y**. À année égale,
une valeur observée directement l'emporte sur une valeur de repli (`fallback`). Le
caractère de repli de la valeur retenue est signalé. S'il n'existe aucune observation
antérieure ou égale à Y, le résultat est vide.

## Ce qui n'est pas encore disponible

Les capacités métier arrivent dans des livraisons ultérieures et ne sont pas
présentes aujourd'hui :

- la commande d'import d'un instantané du dépôt de données — issue #11 ;
- le moteur de calcul du coût des recrutements et ses règles métier — issue #12 ;
- l'API JSON exposant les résultats — issue #19 ;
- le front / les pages Twig — issue #20.

La liste ci-dessus décrit des intentions tracées par leurs issues respectives ; leur
comportement n'est pas défini par la présente livraison.

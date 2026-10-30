# Documentation fonctionnelle — brique Symfony

Ce document décrit ce que la brique applicative `symfony/` du simulateur de coût des
recrutements publics expose **aujourd'hui**, du point de vue de l'usage et du métier.

La brique Symfony porte, à terme, le moteur de calcul, l'API et le front de la V1.
À ce stade, l'application fournit un point de santé, le **modèle de données** du
simulateur avec ses règles de sélection des valeurs réglementaires datées, et une
**commande d'import** qui peuple ce modèle depuis le dépôt de données. Aucune règle
de calcul et aucune interface utilisateur ne sont encore exposées : le modèle n'est
pas consultable via une API ou un écran.

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

### Import d'un jeu de paramètres versionné

La commande `app:import <commit>` charge l'**état présent du dépôt de données**
(`data/`) dans la base du simulateur. Elle attend en argument la **référence du commit**
du dépôt de données : cette référence sert uniquement d'étiquette de version, elle n'est
pas interprétée ni ne déclenche de bascule du dépôt.

Au terme d'un import réussi, il existe un **jeu de paramètres** identifié par cette
version et daté de son import. C'est ce qui garantit la **reproductibilité** : un
résultat peut toujours être rattaché à la version exacte des données qui l'a produit, et
plusieurs versions des données peuvent coexister dans la base sans se mélanger (voir
« Rattachement à un jeu de paramètres » plus bas).

L'import est **tout ou rien** : si la moindre vérification échoue, aucune donnée n'est
écrite et la base reste dans l'état où elle était avant l'appel, avec un message d'erreur
et un code de sortie non nul. Les vérifications menées avant tout chargement sont :

- **conformité de forme** des fichiers de données : chaque fichier importé doit présenter
  les colonnes attendues et des valeurs du bon type ; une colonne requise absente, une
  valeur requise vide ou une valeur mal typée fait échouer l'import. Seule la *forme* est
  contrôlée ici ; les règles métier de cohérence (indices croissants, périodes sans
  chevauchement, bornes de valeurs) restent garanties en amont, du côté de la préparation
  des données ;
- **intégrité des fichiers sources** : l'empreinte du fichier brut archivé de chaque
  source est comparée à celle enregistrée dans son manifeste ; toute divergence fait
  échouer l'import ;
- **complétude des référentiels** : toute source et tout corps cités par les données
  doivent figurer dans les référentiels curatés du dépôt (`data/sources.json`,
  `data/corps.json`) ; une référence inconnue fait échouer l'import.

Ce qui est chargé : les **valeurs réglementaires datées** (paramètres scalaires comme le
point d'indice et les taux, grilles indiciaires et leurs échelons, taux de primes) et les
**référentiels** de sources et de corps auxquels elles se rattachent. Ne sont pas chargés
par cette commande : les coûts PAP, les régimes de retraite, les financeurs et les objets
de simulation (profils, scénarios), qui relèvent d'autres apports.

**Réimport du même commit** : relancer la commande avec la même référence de version
laisse la base dans le **même état** (pas de doublon). Les valeurs datées de cette version
sont remplacées, et les sources et corps sont mis à jour sur place.

### Modèle de données et sélection des valeurs réglementaires

Le simulateur repose sur un ensemble de données métier structurées. Elles se peuplent par
la commande d'import ci-dessus (hors objets de simulation, alimentés par l'application) et
ne sont pas encore exposées à un utilisateur, mais le modèle fixe déjà les règles
suivantes.

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

- le calcul du coût des recrutements et les **règles métier réelles** (retraite,
  indemnitaire, financeur, vues de coût) — l'**ossature** du moteur est posée (issue
  #12, voir ci-dessous), mais les règles de calcul elles-mêmes arrivent aux issues
  #13 à #18 ;
- l'API JSON exposant les résultats — issue #19 ;
- le front / les pages Twig — issue #20.

La liste ci-dessus décrit des intentions tracées par leurs issues respectives ; leur
comportement n'est pas défini par la présente livraison.

### Organisation des règles de calcul (ossature posée, pas encore exécutable)

Le moteur de calcul distingue **quatre familles de règles** qui déterminent le
traitement d'un profil de simulation :

- **retraite** : le régime appliqué (ex. `CAS_CIVIL`, `CNRACL`) ;
- **indemnitaire** : le régime de primes ;
- **financeur** : le rattachement budgétaire ;
- **vue de coût** : l'angle de restitution du coût.

Chaque famille est sélectionnée par un **code métier**. Les trois premières sont
portées par le profil ; la **vue de coût est un paramètre de simulation** choisi au
moment du calcul, et non une caractéristique du profil. Si un profil référence un
code sans règle correspondante, le système **signale une erreur explicite** (famille
et code fautif) plutôt que de l'ignorer silencieusement.

À ce stade, cette organisation est **en place mais pas encore exécutable** : aucune
règle de calcul réelle n'est livrée et aucun coût n'est produit (voir la liste
ci-dessus).

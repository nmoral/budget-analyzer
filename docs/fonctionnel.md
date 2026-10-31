# Documentation fonctionnelle — brique Symfony

Ce document décrit ce que la brique applicative `symfony/` du simulateur de coût des
recrutements publics expose **aujourd'hui**, du point de vue de l'usage et du métier.

La brique Symfony porte, à terme, le moteur de calcul, l'API et le front de la V1.
À ce stade, l'application fournit un point de santé, le **modèle de données** du
simulateur avec ses règles de sélection des valeurs réglementaires datées, une
**commande d'import** qui peuple ce modèle depuis le dépôt de données, et les
**premières règles de calcul** de la famille retraite (taux employeur). Aucune
interface utilisateur n'est encore exposée : ces règles ne sont consultables ni via
une API ni via un écran ; elles ne sont exercées que par les tests.

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
celui de la **dernière année d'observation inférieure ou égale à Y**, **dans le
périmètre d'un jeu de paramètres** (à défaut, le dernier jeu importé). À année égale,
une valeur observée directement l'emporte sur une valeur de repli (`fallback`). Le
caractère de repli de la valeur retenue est signalé. S'il n'existe aucune observation
antérieure ou égale à Y dans le jeu, le résultat est vide — sans repli silencieux sur
un autre jeu.

## Ce qui n'est pas encore disponible

Les capacités métier arrivent dans des livraisons ultérieures et ne sont pas
présentes aujourd'hui :

- le calcul du **coût complet** d'un scénario de recrutement (volumes, étalement,
  cohorte, horizons) et plusieurs **règles métier réelles** (indemnitaire au sens du
  régime de primes, financeur, vues de coût) — l'**ossature** du moteur est posée
  (issue #12, voir ci-dessous), les premières règles réelles de la famille retraite
  sont livrées (issue #13, voir « Règles de retraite » ci-dessous), le **coût
  employeur annuel d'un agent** est désormais calculé (issue #14, voir ci-dessous),
  l'**horizon « année 1 »** d'un recrutement (prorata, année pleine, vue scolaire) est
  disponible (issue #15, voir ci-dessous) et l'**horizon « régime permanent »**
  (coût moyen en population stationnaire × N postes) l'est aussi (issue #16, voir
  ci-dessous), mais les apports suivants arrivent aux issues #17 à #18 ;
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

À ce stade, cette organisation est **en place**, mais seule la famille retraite porte
des règles réelles (voir ci-dessous) ; les autres familles n'ont pas encore de règle
de calcul livrée, et aucun **coût complet** de scénario n'est encore produit (voir la
liste ci-dessus).

L'issue #14 ajoute par ailleurs une **famille de règles « autres cotisations »**,
indexée par **versant** (FPE/FPH) et dédiée à la formule de coût annuel d'un agent (voir
« Coût employeur annuel d'un agent titulaire » ci-dessous). Comme les autres familles,
un versant sans règle correspondante provoque une **erreur explicite**.

### Règles de retraite (taux employeur daté)

La famille retraite expose désormais, pour un régime donné, le **taux employeur de
retraite applicable à une date**. Deux régimes sont réellement implémentés :

- **CAS civil** (code `CAS_CIVIL`) ;
- **CNRACL** (code `CNRACL`).

Le taux retourné est **la valeur réglementaire en vigueur à la date demandée**, lue
dans les valeurs datées importées (voir « Valeurs datées » plus haut) : rien n'est
figé dans l'application. Si le paramètre réglementaire est modifié puis réimporté, le
taux restitué suit la nouvelle valeur. À défaut de jeu de paramètres précisé, c'est le
**dernier jeu importé** qui s'applique.

Si **aucune valeur réglementaire ne couvre la date demandée** pour le régime, le
système **signale une erreur explicite** nommant le paramètre concerné et la date,
plutôt que de retourner une valeur par défaut silencieuse.

**Hors de ce périmètre** : seul le **taux employeur** est fourni, pas la formule de
coût complète d'un recrutement (qui combinera ce taux avec le traitement et les autres
termes — livraisons ultérieures). Les régimes **CAS militaire** et **IRCANTEC** ne sont
pas encore implémentés. Les valeurs réglementaires datées (import) ne sont pas
modifiées par cette famille : elle ne fait que les consommer.

### Coût employeur annuel d'un agent titulaire

Le simulateur calcule désormais le **coût employeur annuel d'un agent titulaire** à une
**date simulée** donnée. Le résultat est **décomposé en quatre termes** plus leur
total, afin de rendre lisible chaque composante de la dépense :

1. **Traitement + primes** : le traitement indiciaire brut annuel (TIB) augmenté des
   primes du corps. Le TIB est l'**indice majoré** de l'agent multiplié par la valeur
   annuelle du **point d'indice** en vigueur à la date. Les primes sont exprimées par un
   **taux de primes** `p` propre au corps (voir « Taux de primes pour une année »).
2. **Retraite** : la cotisation employeur de retraite, soit le TIB multiplié par le
   **taux employeur du régime** de l'agent à la date (règle de la famille retraite,
   `CAS_CIVIL` ou `CNRACL`).
3. **Autres cotisations** (`r_autres`) : les cotisations employeur hors retraite,
   appliquées au traitement augmenté des primes, à un **taux dépendant du versant** de
   l'agent — fonction publique d'État (**FPE**) ou hospitalière (**FPH**).
4. **RAFP** (retraite additionnelle de la fonction publique) : cotisation assise sur les
   primes mais **plafonnée** — l'assiette est le plus petit de « primes » (TIB·p) et de
   **20 % du TIB** —, multipliée par le taux RAFP.

Le **total** est la somme exacte des quatre termes. Le calcul est **reproductible** (à
situation et date identiques, résultat identique au centime près, sans dérive de
précision) et ne comporte **aucun arrondi d'affichage** à ce stade (il restitue la
pleine précision ; la présentation arrondie viendra avec l'API et le front, #19/#20).
Les termes hors retraite sont **indépendants** du taux de retraite : faire varier la
date (donc le taux de retraite) ne modifie que le terme retraite.

La **situation de l'agent** prise en entrée décrit son corps, son versant, son indice
majoré et son régime de retraite. Elle est aujourd'hui **composée à la main** ; sa
dérivation automatique depuis un profil de simulation arrivera dans une issue
ultérieure.

**Erreurs explicites, jamais de valeur par défaut** : si une valeur réglementaire
nécessaire manque à la date demandée (point d'indice, taux de primes, taux d'un régime),
ou si le régime ou le versant est inconnu, le système **signale une erreur explicite**
plutôt que de retourner un résultat approché.

**Taux réels disponibles** : les **taux « autres cotisations »** (`r_autres`, FPE/FPH)
et le **taux RAFP** sont désormais **présents dans le dépôt de données** (sourçage livré
par l'issue #49, effet au 1er janvier 2026). Les quatre termes, y compris les termes 3
et 4, se calculent donc sur des **valeurs réglementaires réelles** une fois le dépôt
importé. Les trois taux employeur retenus (effet `2026-01-01`) sont :

- **FPE / État** (`r_autres`) : **16,07 %** du traitement augmenté des primes ;
- **FPH / hospitalière** (`r_autres`) : **17,13 %** ;
- **RAFP** : **5,00 %** (part employeur) appliqué à l'assiette plafonnée.

Chaque taux « autres cotisations » est la **somme de cotisations employeur hors retraite
et hors RAFP** (maladie, allocations familiales, CSA, FNAL, invalidité, et pour la FPH le
fonds pour l'emploi hospitalier). Le détail ligne à ligne de chaque agrégat, ses sources
officielles et l'**exclusion documentée du versement mobilité** (fixé localement, sans
taux national) figurent dans le catalogue de composition des taux (voir la documentation
technique). Si un taux manque à la date demandée, le système signale toujours une erreur
explicite plutôt que de retourner une valeur par défaut.

**Hors de ce périmètre** : les **contractuels** (formule propre, hors V1), les **vues de
coût** alternatives de la retraite (#18), le **coût d'un scénario** complet (volumes,
étalement, cohorte, horizons — #15 à #17) et l'**arrondi d'affichage** (#19/#20). Ce
calcul n'est encore exposé ni par une API ni par un écran : il n'est exercé que par les
tests.

### Horizon « année 1 » d'un recrutement (prorata + année pleine + vue scolaire)

À partir du coût employeur annuel ci-dessus, le simulateur calcule désormais l'**horizon
« année 1 »** d'un recrutement, c'est-à-dire le coût de la **première année** d'un agent
proraté selon sa **date de recrutement**, avec le coût **année pleine** toujours affiché
en regard. Chaque vue reprend la **décomposition en quatre termes** du coût annuel (voir
ci-dessus).

- **Année pleine** : le coût employeur annuel non proraté, valorisé à la date de
  recrutement. Il reste toujours disponible comme point de comparaison.
- **Année 1 (année civile)** : le coût de l'année calendaire du recrutement, proraté au
  **nombre de mois civils** du mois de recrutement à décembre inclus, divisé par 12. Le
  **mois d'entrée est compté entier** : le jour du mois n'intervient pas (pas de prorata
  au jour). Un recrutement au 1er septembre compte 4 mois (sept→déc), au 1er janvier 12
  mois (année 1 = année pleine), au 1er décembre 1 mois, et un recrutement en milieu de
  mois (ex. 15 septembre) compte le même nombre de mois qu'un recrutement en début de ce
  mois. Le prorata s'applique **terme par terme**, et le total de l'année 1 est la somme
  des quatre termes proratés.
- **Vue année scolaire** : proposée **uniquement pour les profils à calendrier de
  référence scolaire**. Elle proratise sur la **fenêtre de l'année scolaire** (1er
  septembre → 31 août) qui contient la date de recrutement, du mois de recrutement à fin
  août inclus (mois d'août compris), divisé par 12. Un recrutement au 1er septembre
  couvre 12 mois (vue scolaire = année pleine), au 1er novembre 10 mois. Pour un profil à
  **calendrier civil**, cette vue n'est **pas** fournie.

Le **calendrier de référence** (civil ou scolaire) et la **date de recrutement** sont des
**entrées** de ce calcul. La proratisation suit la même convention décimale que le reste
du calcul (produit exact, quotient arrondi à 6 décimales half-up), sans arrondi
d'affichage à ce stade (#19/#20).

**Hors de ce périmètre** : la **dérivation automatique** de la date de recrutement et du
calendrier depuis un profil / une ligne de scénario (qui sont ici composés à la main),
le **prorata au jour**, les **autres horizons** — régime permanent (#16, voir
ci-dessous), cohorte (#17) — et les **vues de coût** enfichables (#18). Comme le coût
annuel, cet horizon n'est exposé ni par une API ni par un écran : il n'est exercé que par
les tests.

### Horizon « régime permanent » d'un corps (population stationnaire)

À partir du coût employeur annuel ci-dessus, le simulateur calcule désormais l'**horizon
« régime permanent »** d'un corps, c'est-à-dire le coût d'une **population stationnaire** :
une situation où chaque poste est en permanence occupé, les agents se répartissant sur
tous les échelons de la carrière au prorata du temps qu'on y passe. Dans cette hypothèse,
le coût annuel moyen d'un agent du corps est la **moyenne des coûts d'échelon pondérée par
la durée de chaque échelon**, et le coût du corps est ce coût moyen multiplié par le
**nombre de postes** N.

- **Coût annuel moyen d'un agent** : pour chacun des **quatre termes** du coût annuel
  (voir ci-dessus), la moyenne pondérée `Σ(terme de l'échelon × durée en mois) / Σ(durées
  en mois)` sur tous les échelons de la carrière, chaque échelon étant valorisé à la
  **date** demandée. Le total est la somme des quatre termes moyens. Une carrière réduite
  à un seul échelon donne simplement le coût annuel de cet échelon (moyenne triviale).
- **Coût pour l'effectif** : le coût annuel moyen d'un agent multiplié par N, **terme par
  terme** ; avec N = 1, il est égal au coût moyen d'un agent.
- **Hypothèse de population** : le résultat porte explicitement l'hypothèse retenue,
  « population stationnaire », pour que l'angle de lecture du chiffre soit toujours lisible.

La **carrière** (la suite des échelons avec leur durée) et le **nombre de postes** N sont
des **entrées** de ce calcul. Chaque échelon doit porter une durée strictement positive, y
compris l'**échelon terminal** dont la durée est **fournie** (le régime permanent n'a de
sens que si l'on sait combien de temps les agents restent au sommet de la grille). Une
carrière vide, une durée nulle ou négative, ou un effectif inférieur à 1 sont **rejetés**
par une erreur explicite. Le calcul est **reproductible** (à carrière et date identiques,
résultat identique) et ne comporte **aucun arrondi d'affichage** à ce stade (#19/#20).

**Hors de ce périmètre** : la construction automatique de la carrière à partir d'une
grille persistée (charger les échelons et leurs durées, fournir la durée du terminal,
dériver la situation de chaque agent), reportée à une issue ultérieure ; la carrière est
ici composée à la main. Restent aussi hors périmètre le **calibrage** contre le coût moyen
du PAP (#22), l'**autre horizon** cohorte (#17), les **vues de coût** enfichables (#18) et
l'**arrondi d'affichage** (#19/#20). Comme les autres horizons, ce calcul n'est exposé ni
par une API ni par un écran : il n'est exercé que par les tests.

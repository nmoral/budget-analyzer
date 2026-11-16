# Documentation fonctionnelle — brique Symfony

Ce document décrit ce que la brique applicative `symfony/` du simulateur de coût des
recrutements publics expose **aujourd'hui**, du point de vue de l'usage et du métier.

La brique Symfony porte, à terme, le moteur de calcul, l'API et le front de la V1.
À ce stade, l'application fournit un point de santé, le **modèle de données** du
simulateur avec ses règles de sélection des valeurs réglementaires datées, une
**commande d'import** qui peuple ce modèle depuis le dépôt de données, les **règles
de calcul** du coût employeur (famille retraite, coût annuel, horizons, vues de coût)
et une **API JSON** qui expose la création et la lecture de scénarios ainsi que leur
**résultat de simulation** sur les horizons « année 1 », « régime permanent » et
« cohorte » (voir « API / scénarios et résultats »). Aucun écran n'est encore exposé.

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
- **complétude des référentiels** : toute source, tout corps et tout financeur cités par
  les données doivent figurer dans les référentiels curatés du dépôt (`data/sources.json`,
  `data/corps.json`, `data/funders.json`) ; une référence inconnue fait échouer l'import.

Ce qui est chargé : les **valeurs réglementaires datées** (paramètres scalaires comme le
point d'indice et les taux, grilles indiciaires et leurs échelons, taux de primes), le
**budget de référence daté par financeur** et les **référentiels** de sources, de corps et
de financeurs auxquels ils se rattachent. Ne sont pas chargés par cette commande : les
coûts PAP, les régimes de retraite et les objets de simulation (profils, scénarios), qui
relèvent d'autres apports.

**Réimport du même commit** : relancer la commande avec la même référence de version
laisse la base dans le **même état** (pas de doublon). Les valeurs datées de cette version
— y compris le budget de référence — sont remplacées, et les sources, corps et financeurs
sont mis à jour sur place.

### Modèle de données et sélection des valeurs réglementaires

Le simulateur repose sur un ensemble de données métier structurées. Elles se peuplent par
la commande d'import ci-dessus (hors objets de simulation, alimentés par l'application) et
ne sont pas encore exposées à un utilisateur, mais le modèle fixe déjà les règles
suivantes.

**Objets métier représentés** :

- **Source** : origine documentaire de toute valeur réglementaire (intitulé, éditeur,
  **édition/année**, **localisation** précise — `locator` : page, tableau, article —,
  URL, date de récupération, empreinte du fichier). L'édition et la localisation sont
  **requises** : elles rendent la source « en clair complète » pour la transparence du
  résultat (voir « API / scénarios et résultats »).
- **Corps** : cadre statutaire (code, libellé, versant, catégorie A/B/C).
- **Grille** et **Échelon** : grille indiciaire d'un corps pour un grade, dans une
  version donnée, et ses échelons (rang, indice brut, indice majoré, durée en mois).
- **Régime de retraite** : code et libellé (`CAS_CIVIL`, `CAS_MILITAIRE`, `CNRACL`,
  `IRCANTEC`).
- **Financeur** : rattachement budgétaire (code, libellé) et ses codes dans les
  nomenclatures officielles (mission / programme / action pour l'État, sous-objectif
  de l'ONDAM, fonction COFOG). Deux financeurs sont fournis par le dépôt : `ETAT_P140`
  (État, programme 140) pour la FPE et `ONDAM` pour la FPH.
- **Budget de référence** : montant budgétaire total d'un financeur sur une **période de
  validité datée** (montant en euros, unité, source), suivant une **trajectoire
  pluriannuelle** (plusieurs millésimes contigus par financeur, le dernier à fin ouverte).
  Le budget `ETAT_P140` est constitué des **crédits complets du programme 140** (LFI) et le
  budget `ONDAM` de l'**ONDAM global voté** (LFSS). Cette donnée est **exposée en base pour
  un usage futur** par la mise en regard budgétaire (#21) ; elle n'est à ce stade ni
  calculée ni affichée.
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

### API / scénarios et résultats

Une **API JSON** expose désormais la **création** et la **lecture** d'un scénario de
recrutement, puis la **lecture de son résultat de simulation** sur l'horizon « année 1 ».
C'est le premier usage réel du moteur de calcul exposé hors des tests.

**Créer un scénario** : on fournit un **titre** et une ou plusieurs **lignes**, chaque
ligne décrivant un recrutement — le **profil** visé, un **volume** (nombre d'agents),
une **date de recrutement** et, optionnellement, un **étalement sur N années**. On peut
aussi préciser la **version des paramètres** à épingler. À la création, le scénario est
**rattaché à un jeu de paramètres** (voir « Rattachement à un jeu de paramètres ») : si
aucune version n'est précisée, c'est le **dernier jeu importé** qui est épinglé ; si une
version est précisée, c'est ce jeu-là. Une version demandée **inconnue** fait **rejeter**
la création. La création renvoie l'**identifiant** du scénario et la **version des
paramètres** effectivement épinglée.

**Lire un scénario** : on retrouve son titre, la version des paramètres épinglée et ses
lignes. Un scénario **inconnu** est signalé comme introuvable.

**Lire le résultat d'un scénario** : on obtient, **par ligne** et **par vue de coût
demandée**, le coût de chaque **horizon demandé** du recrutement. Trois horizons sont
désormais sélectionnables : **année 1** (par défaut), **régime permanent** et **cohorte**.

L'horizon **année 1** est décomposé en trois fenêtres :

- **année pleine** : le coût employeur annuel non proraté, en regard ;
- **année 1** : le coût de l'année civile du recrutement, proraté au **nombre de mois
  entiers** du mois de recrutement à décembre inclus (le jour du mois n'intervient pas) ;
- **année scolaire** : proposée **uniquement pour les profils à calendrier scolaire**
  (fenêtre 1er septembre → 31 août), absente sinon.

L'horizon **régime permanent** donne le **coût annuel moyen d'un agent** du corps en
population stationnaire (voir « Horizon régime permanent » plus bas) : un seul montant par
vue, décomposé en ses quatre termes et leur total. L'horizon **cohorte** donne la **série
année par année** du coût d'un agent sur **toute sa carrière** (voir « Horizon cohorte »
plus bas) : une liste de montants — l'année _k_ couvre les mois [(k−1)·12, k·12) depuis
l'entrée — accompagnée du **cumul** (somme de toutes les années, terme par terme). Permanent
comme cohorte sont calculés **par agent** (volume d'une ligne appliqué uniquement à
l'agrégat, voir ci-dessous), à la **date de recrutement** de la ligne.

Chaque fenêtre ou montant est valorisé selon les **vues de coût** choisies (voir « Vues de
coût de la retraite ») : la vue **budgétaire** sert de référence et est la vue par
**défaut**. Pour le régime permanent et la cohorte comme pour l'année 1, c'est **seul le
terme retraite qui suit la vue** (view-aware) ; les trois autres termes restent le coût de
base, identique entre vues. Le résultat fournit en plus un **agrégat** qui additionne, pour
chaque vue, le coût de **chaque ligne multiplié par son volume** (Σ ligne × volume) : par
fenêtre pour l'année 1, terme par terme pour le régime permanent, et **aligné par index
d'année** pour la cohorte — la série agrégée a la longueur de la carrière la plus longue,
une ligne à la carrière plus courte ne contribuant **pas au-delà de sa propre carrière**
(elle y compte 0). Chaque coût est restitué en ses quatre termes (traitement + primes,
retraite, autres cotisations, RAFP) et leur total, en pleine précision (sans arrondi
d'affichage à ce stade).

La **sélection** se fait au moment de la lecture du résultat : on choisit les **horizons**
(`year1`, `permanent`, `cohorte`, combinables) et les **vues** voulus ; à défaut, le
résultat porte sur l'horizon **année 1** valorisé en vue **budgétaire**. Une **vue
inconnue** ou un **horizon inconnu** font **rejeter** la demande. Une **vue valide** dont le
jeu de
paramètres épinglé **ne contient pas** un taux, un montant ou un taux de primes **requis**
pour la date demandée fait elle aussi **rejeter** la demande, par une **erreur maîtrisée**
qui **nomme la vue et le paramètre manquant** (avec sa date) — jamais une erreur serveur
opaque. En **sélection mixte** (une vue calculable, une vue non valorisable), c'est **tout
ou rien** : toute la demande est rejetée en nommant la vue fautive, sans résultat partiel ;
cette erreur n'est **pas mémorisée**, de sorte qu'après import du paramètre manquant le
résultat se calcule. Une vue ou un horizon **répété** dans la demande est **compté une
seule fois** (l'agrégat n'est jamais doublé). L'**étalement sur N années** d'une ligne est
**stocké mais ignoré** par l'horizon année 1.

**Version épinglée, résultat rejouable** : le résultat est toujours calculé **contre la
version des paramètres épinglée au scénario**, pas « le dernier jeu importé ». La réponse
**expose cette version**, et deux demandes identiques (même scénario, même version, mêmes
horizons et vues) renvoient un **résultat identique** — garantie de rejouabilité et de
transparence. Si le scénario était épinglé à une **autre version** (taux différents), le
résultat diffère en conséquence ; il n'y a **jamais de mélange** entre versions. Après un
**nouvel import** des paramètres, les résultats précédents sont **recalculés** contre l'état
courant (jamais de résultat périmé).

**Transparence du résultat (`?detail=full`)** : on peut demander la **justification de
chaque chiffre** en ajoutant le paramètre `detail=full` à la lecture du résultat
(`GET /api/scenarios/{id}/result?detail=full`). **Sans ce drapeau, la réponse reste
identique à l'actuelle** (les quatre postes et leur total, sans justification) ; **avec**,
chaque fenêtre (année pleine, année 1, année scolaire) de chaque ligne et de chaque vue
porte, à côté de ses quatre termes, un bloc **`detail`** qui déplie **chacun des quatre
termes**.

Le dépliage `detail=full` ne concerne **que l'horizon année 1** : les horizons **régime
permanent** et **cohorte**, qui sont des **moyennes ou des sommes sans trace de calcul**,
sont restitués sans bloc `detail` même lorsque `detail=full` est demandé (limite assumée).
Les quatre termes de l'année 1 se déplient ainsi :

- la **valeur** du terme pour cette fenêtre (la valeur proratisée, le cas échéant) ;
- la **formule** en texte lisible (ex. retraite budgétaire : `TIB·r_retraite`) ;
- les **valeurs d'entrée datées** réellement utilisées — pour chacune : la clé, la valeur,
  l'unité et la **date d'effet** (ex. `taux_cas_civil = 82.28 PCT_TIB`, effet `2026-01-01`) ;
- le **facteur de prorata** de la fenêtre (`months` / `over` et le nom de la fenêtre), qui
  rend la valeur **réconciliable** : `valeur = valeur annuelle du terme × months / over`.
  L'année pleine a un prorata de **12/12** (aucune proratisation) ; l'année 1 compte les
  mois du recrutement à décembre ; l'année scolaire, du recrutement à août ;
- les **sources en clair complètes** de ces entrées (éditeur, titre, édition/année,
  localisation `locator`, URL, date de récupération), dédupliquées.

La réponse détaillée ajoute aussi une section **`meta`** : par vue, sa **définition**, la
**question métier**, ses **hypothèses** et ses **sources de méthode** (texte libre,
distinctes des sources de valeurs) ; plus les **hypothèses structurelles** appliquées par
le moteur, **nommées** (gel de la valeur du point d'indice, taux de primes moyen par corps
avec repli, population stationnaire pour le régime permanent).

Les codes de réponse (200 sans détail, 422 sélection invalide, 404 scénario inconnu) sont
**inchangés**, et la réponse légère et la réponse détaillée sont **mises en cache
séparément** (une clé par mode `detail`).

**Hors de ce périmètre** : le **ré-épinglage** d'un scénario vers une autre version et
l'**édition des lignes** après création, le **front** (#20) et l'**authentification**. Les
**sources de méthode** d'une vue restent exposées en **texte libre** (leur conversion en
vraies sources documentaires est hors de ce périmètre). Le dépliage `detail=full` reste
**limité à l'année 1** (permanent et cohorte sans trace), et la cohorte expose **toute la
carrière** sans paramètre de borne.

### Fiche de résultat d'un scénario (page web)

Une **page web lisible** présente désormais le résultat d'une simulation : on ouvre
`GET /scenarios/{id}` dans un navigateur et l'on obtient, pour le scénario `{id}`, une
**fiche** qui reprend les chiffres du service de résultats (voir « API / scénarios et
résultats ») en les rendant **transparents** — chaque nombre de l'année 1 se **déplie
jusqu'à sa formule, ses valeurs d'entrée datées et ses sources officielles en clair**.
La fiche s'ouvre toujours sur un **identifiant de scénario** existant : il n'y a **pas
d'écran de création ni de liste** (un scénario se crée via l'API `POST /api/scenarios`).

**En-tête.** La fiche affiche le **titre du scénario** et la **version du jeu de
paramètres** réellement utilisée pour le calcul.

**Vues et horizons.** Pour **chaque vue de coût** demandée (la vue **budgétaire** par
défaut) et chaque **horizon** demandé, la fiche montre les montants **repris tels quels
du service** (chaînes déjà formatées) : l'**année 1** (ses trois fenêtres — année pleine,
année 1 proratisée, et année scolaire **masquée si le profil n'en a pas**), le **régime
permanent** et la **cohorte** (série année par année + total). Un **agrégat** par vue et
par horizon est affiché en plus des lignes. La ou les **vues affichées sont toujours
nommées** ; quand plusieurs vues sont demandées, elles s'affichent **côte à côte**.

**Dépliage jusqu'à la source (année 1 seulement).** Dans les fenêtres de l'**année 1**,
chaque terme (traitement et primes, retraite, autres cotisations, RAFP) se **déplie** d'un
clic pour révéler : la **formule** en clair, les **valeurs d'entrée datées** utilisées
(clé, valeur, unité, **date d'effet**), le **prorata** de la fenêtre, et les **sources en
clair** — « **éditeur — titre, édition, localisation** » puis le lien. Une source n'est
**jamais** présentée comme un code brut ni comme un lien nu. Le dépliage reste **limité à
l'année 1** : les horizons **régime permanent** et **cohorte** sont volontairement
**allégés** (montants affichés, mais pas de dépliage par nombre), et la fiche le **mentionne
explicitement**.

**Hypothèses et version.** La fiche **nomme toutes les hypothèses appliquées** : les
**hypothèses structurelles** du moteur (gel de la valeur du point, taux de primes moyen,
population stationnaire) et, par vue, ses **hypothèses propres** (définition, question
métier, hypothèses, sources de méthode). Ces hypothèses sont en **lecture seule** (leur
**surcharge** n'est pas proposée à ce stade) et sont affichées avec la **version du jeu de
paramètres**, y compris sur les horizons allégés.

**Courbe de cohorte.** Quand l'horizon cohorte est affiché, la fiche trace une **courbe**
(un graphique **SVG**) du coût année par année, **cohérente avec le tableau cohorte**
(même nombre de points, mêmes extrêmes), une courbe par vue affichée.

**Financeur, pas de part de budget.** Chaque ligne indique son **financeur**. Conformément
au périmètre, **aucune part de budget** n'est affichée (une simulation FPH ne s'affiche
jamais comme une part du budget de l'État) : la mise en regard budgétaire est différée
(#21).

**Adresse stable et rejouable.** L'adresse de la fiche **encode la sélection** : la
**version des paramètres** (`parametersVersion`), les **vues** (`views`), les **horizons**
(`horizons`) et le **dépliage** (`detail=full`). Rejouer la même adresse redonne la
**même fiche**. Si l'adresse **épingle une version**, c'est **cette version** qui pilote le
calcul et qui est affichée ; si elle n'en précise aucune (ou une version inconnue), la
fiche retombe sur le **jeu épinglé au scénario** et affiche la version effectivement
utilisée. On peut ainsi **partager** ou **archiver** l'adresse d'un résultat précis.

**Pages d'erreur lisibles.** Un **scénario inconnu** (ou un identifiant non numérique)
affiche une **page d'erreur lisible** (404), sans trace technique. De même, une **vue ou
un horizon invalide** saisi dans l'adresse — ou une vue valide **non valorisable** faute de
paramètre daté — affiche une **page « Sélection invalide »** lisible (404) invitant à
corriger l'URL, jamais une erreur serveur opaque.

**Sans JavaScript.** Tout le contenu (y compris les blocs de dépliage) est **rendu côté
serveur** et présent dans la page : le dépliage fonctionne même **sans JavaScript** ;
l'interactivité ne fait que montrer ou masquer un contenu déjà là.

## Ce qui n'est pas encore disponible

Les capacités métier arrivent dans des livraisons ultérieures et ne sont pas
présentes aujourd'hui :

- le calcul du **coût complet** d'un scénario de recrutement (volumes, étalement,
  cohorte, horizons) et plusieurs **règles métier réelles** (indemnitaire au sens du
  régime de primes, financeur) — l'**ossature** du moteur est posée
  (issue #12, voir ci-dessous), les premières règles réelles de la famille retraite
  sont livrées (issue #13, voir « Règles de retraite » ci-dessous), le **coût
  employeur annuel d'un agent** est désormais calculé (issue #14, voir ci-dessous),
  l'**horizon « année 1 »** d'un recrutement (prorata, année pleine, vue scolaire) est
  disponible (issue #15, voir ci-dessous) et l'**horizon « régime permanent »**
  (coût moyen en population stationnaire × N postes) l'est aussi (issue #16, voir
  ci-dessous), l'**horizon « cohorte »** (série année par année du coût d'une
  cohorte de N agents avec avancement d'échelon) également (issue #17, voir
  ci-dessous) et les **vues de coût** de la retraite (quatre méthodes de valorisation)
  le sont désormais (issue #18, voir « Vues de coût de la retraite » ci-dessous) et
  l'**API JSON** des scénarios et de leur résultat « année 1 » est livrée (issue #19,
  voir « API / scénarios et résultats » ci-dessous), enrichie du **détail de calcul par
  montant** (mode `?detail=full` : formule, entrées datées, prorata et sources de chaque
  terme — issue #65, voir « API / scénarios et résultats ») et de l'**exposition par
  l'API des horizons régime permanent et cohorte** (issue #66, voir « API / scénarios et
  résultats »), mais les apports suivants restent à venir ;
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
coût** alternatives de la retraite (traitées à part, voir « Vues de coût de la retraite »
— #18), le **coût d'un scénario** complet (volumes,
étalement, cohorte, horizons — #15 à #17) et l'**arrondi d'affichage** (#19/#20). Ce
calcul n'est encore exposé ni par une API ni par un écran : il n'est exercé que par les
tests.

### Vues de coût de la retraite (quatre méthodes)

Le simulateur valorise désormais la **composante retraite** du coût employeur annuel
selon **quatre vues de coût** au choix, pour éclairer différentes questions métier.
**Seule la composante retraite change** d'une vue à l'autre : les trois autres termes du
coût annuel — traitement + primes, autres cotisations, RAFP — sont **identiques entre
toutes les vues**, et le total est recomposé comme somme des quatre termes.

La vue est un **paramètre de la simulation**, choisi au moment du calcul, et non une
caractéristique de l'agent. La vue **budgétaire** est la vue **par défaut**.

- **Budgétaire** (par défaut) — répond à « combien l'employeur verse-t-il réellement au
  titre de la retraite cette année ? ». La retraite vaut le TIB multiplié par le **taux
  de cotisation employeur du régime** de l'agent à la date (CAS civil ou CNRACL), soit
  exactement la méthode du coût annuel de base : en vue budgétaire, le résultat est
  **strictement égal** au coût employeur annuel décrit ci-dessus (non-régression).
  Aucune hypothèse.
- **Équivalent privé** — répond à « combien coûterait la retraite de cet agent s'il
  relevait du régime du secteur privé ? ». La retraite est valorisée au **taux de
  cotisation vieillesse employeur du secteur privé** appliqué au TIB, indépendamment du
  régime public.
- **Équilibre technique** — répond à « quel taux employeur équilibrerait les charges de
  pension du régime de cet agent ? ». La retraite est valorisée au **taux d'équilibre
  technique du régime** de l'agent, **propre à chaque régime** (un taux pour le CAS civil,
  un pour la CNRACL).
- **Actuarielle** — répond à « quel engagement de retraite l'employeur accumule-t-il pour
  une année de service de cet agent ? ». La retraite est l'**engagement généré par une
  année de service** (méthode des unités de crédit projetées, IAS 19 / norme comptable
  n°13 de l'État) : `(TIB × taux d'annuité) × facteur actuariel`, où `TIB × taux
  d'annuité` représente les droits acquis sur l'année et le **facteur actuariel** résume
  actualisation, survie et différé.

**Hypothèses de la vue actuarielle** : la vue actuarielle s'appuie sur des **hypothèses à
valeurs par défaut sourcées** (taux d'annuité, facteur actuariel), **surchargeables**.
Une hypothèse laissée au défaut et une hypothèse surchargée sont **distinguées** : la vue
sait restituer les hypothèses qu'elle porte et lesquelles ont été modifiées. Les vues
« taux » (équivalent privé, équilibre technique) lisent leur taux dans les **valeurs
réglementaires datées** ; la vue budgétaire n'a aucune hypothèse.

**Métadonnées de restitution** : chaque vue expose sa **définition**, la **question
métier** à laquelle elle répond, ses **hypothèses** (vide pour la budgétaire) et ses
**sources** documentaires, pour rendre lisible l'angle de lecture du chiffre.

**Erreurs explicites, jamais de valeur par défaut** : une **vue inconnue** est **rejetée**
par une erreur explicite (pas de vue par défaut silencieuse). Si une valeur réglementaire
datée requise par une vue manque à la date demandée (taux équivalent privé, taux
d'équilibre d'un régime), le système signale une erreur explicite plutôt que de retourner
un résultat approché.

**Hors de ce périmètre** : l'**édition des hypothèses par l'utilisateur** (saisie), la
**fiche de résultat** et l'**URL de partage** (#19/#20) ; l'**import des vraies valeurs**
des taux « équivalent privé » et « équilibre technique » (posées à la main tant que leur
import n'est pas livré) ; la **sélection de plusieurs vues en une passe** (le calcul porte
sur **une vue à la fois**, la boucle relève de l'appelant — #19) ; et la dérivation du
facteur actuariel depuis une **table de mortalité** (il reste une hypothèse fournie).
Comme le reste du moteur, ce calcul n'est exposé ni par une API ni par un écran : il n'est
exercé que par les tests.

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
compris l'**échelon terminal** (le régime permanent n'a de sens que si l'on sait combien de
temps les agents restent au sommet de la grille). Une carrière vide, une durée nulle ou
négative, ou un effectif inférieur à 1 sont **rejetés** par une erreur explicite. Le calcul
est **reproductible** (à carrière et date identiques, résultat identique) et ne comporte
**aucun arrondi d'affichage** à ce stade (#19/#20).

**Durée de l'échelon terminal (hypothèse métier, #66)** : dans les grilles importées, le
**dernier échelon** (terminal) n'a **pas de durée** en base (l'agent y reste
indéfiniment). Or la moyenne pondérée du régime permanent a besoin d'une durée pour chaque
échelon. Quand la carrière est construite automatiquement depuis la grille (exposition par
l'API, #66), le terminal reçoit donc une **durée fixe de 60 mois** : c'est une **hypothèse
de modélisation** assumée, qui fait entrer le terminal dans la moyenne `Σ(terme × durée) /
Σ(durées)` comme tout autre échelon (par exemple, 2 échelons de 12 mois + terminal
60 mois ⇒ pondération 12 / 12 / 60). Cette durée est **réglable** (constante fixée au
câblage), susceptible d'être affinée ultérieurement. L'horizon **cohorte n'est pas
concerné** : il ignore la durée du terminal (une fois le terminal atteint, les années
suivantes sont constantes).

**Hors de ce périmètre** : le **calibrage** contre le coût moyen du PAP (#22), les **vues
de coût** enfichables au-delà du view-aware du terme retraite (#18) et l'**arrondi
d'affichage** (#19/#20).

### Horizon « cohorte » d'un recrutement (série annuelle, avancement d'échelon)

À partir du coût employeur annuel ci-dessus, le simulateur calcule désormais l'**horizon
« cohorte »** : la **série année par année** du coût d'une cohorte de **N agents** recrutés
ensemble, de leur entrée jusqu'à un horizon donné, en tenant compte de leur **avancement
d'échelon** au fil de la carrière. Chaque année de la série reprend la **décomposition en
quatre termes** du coût annuel (voir ci-dessus).

- **Série année par année** : le résultat donne, pour chaque année de l'horizon, le coût
  annuel d'un agent. L'année _k_ couvre les douze mois qui suivent l'entrée (année 1 = mois
  1 à 12 depuis l'entrée, année 2 = mois 13 à 24, etc.) ; ce sont des **années pleines de
  douze mois glissantes depuis la date d'entrée**, sans prorata de la date de recrutement
  (ce prorata calendaire relève de l'horizon « année 1 », #15).
- **Avancement d'échelon mensuel** : au fil des mois, l'agent progresse d'un échelon au
  suivant selon la **durée** de chaque échelon. Une année entièrement passée sur un même
  échelon vaut le coût annuel de cet échelon ; une année **à cheval** sur deux échelons
  (changement en cours d'année) est valorisée **au prorata du nombre de mois passés sur
  chacun**, terme par terme.
- **Échelon terminal** : le **dernier échelon** de la carrière est le terminal ; l'agent y
  reste indéfiniment (pas de départ). Une fois le terminal atteint, toutes les années
  suivantes sont **constantes** à son coût.
- **Horizon** : par défaut **10 ans**. Un mode **« carrière complète »** dérive
  automatiquement l'horizon pour couvrir toute la progression jusqu'au terminal (nombre
  d'années nécessaires pour épuiser les échelons non terminaux, arrondi à l'année
  supérieure).
- **Cohorte de N agents** : le coût de la cohorte est, pour chaque année, le coût d'un agent
  **multiplié par N** terme par terme ; avec N = 1 il est égal au coût d'un agent. La série
  fournit aussi les **cumuls** sur tout l'horizon : coût total d'un agent (somme des années)
  et coût total de la cohorte (ce total × N).
- **Point gelé** : toutes les années sont valorisées à la **date d'entrée** ; seul
  l'échelon évolue d'une année à l'autre, pas la valeur du point d'indice ni les taux. La
  **revalorisation annuelle** (valoriser chaque année à sa propre date) est une hypothèse
  distincte, hors de ce périmètre (#18).

La **carrière** (la suite des échelons avec leur durée), la **date d'entrée**, l'**horizon**
et le **nombre d'agents** N sont des **entrées** de ce calcul. Un horizon inférieur à 1 an
ou un effectif inférieur à 1 sont **rejetés** par une erreur explicite. Le calcul est
**reproductible** (à carrière, date, horizon et N identiques, résultat identique) et ne
comporte **aucun arrondi d'affichage** à ce stade (#19/#20).

**Hypothèses et limites de la V1** : pas de **revalorisation** annuelle (point gelé à
l'entrée, #18), pas de **départs ni d'attrition** (l'effectif reste constant, les agents
restent au terminal), pas de **prorata de la première année** (années pleines depuis
l'entrée ; le prorata de la date de recrutement est l'horizon « année 1 », #15).

Depuis #66, la carrière est **construite automatiquement depuis la grille du profil**
(voir « API / scénarios et résultats ») lorsque l'horizon cohorte est demandé via l'API ;
le calcul reste par ailleurs exercé par les tests avec une carrière composée à la main.
L'exposition par l'API se fait toujours en **mode « carrière complète »** (pas de paramètre
de borne d'horizon).

**Hors de ce périmètre** : la **revalorisation annuelle** et les **départs / attrition**
(#18 et ultérieurs), les **vues de coût** enfichables au-delà du view-aware du terme
retraite (#18) et l'**arrondi d'affichage** (#19/#20).

# Simulateur du coût des recrutements publics — Spécifications

Oct 19, 2026 · @Nicolas

## Contexte et objectifs

L'outil répond à une question simple : combien coûte réellement le recrutement de N agents publics d'un métier donné, par an et dans la durée. Exemple : « recruter 1 000 professeurs des écoles coûte X € la première année, Y € par an en régime permanent ».

Le débat public mélange souvent des mesures différentes : effectifs physiques et ETPT, recrutements bruts et solde net, coût d'entrée et coût moyen, budget de l'État et budget de la Sécurité sociale. Le simulateur rend ces distinctions explicites.

Objectifs :

1. Calculer le coût employeur d'un recrutement à partir de données publiques uniquement.
2. Rendre chaque chiffre traçable jusqu'à sa source officielle et sa date d'effet.
3. Distinguer coût budgétaire (conventions comptables réelles) et coût économique.
4. À terme, confronter le budget de l'État et les programmes des candidats à ces calculs.

L'outil est public et open source : un résultat n'a de valeur dans un débat que si n'importe qui peut le vérifier.

## Périmètre V1 et non-objectifs

La V1 couvre deux profils, choisis parce qu'ils exercent les deux modes de financement et les deux régimes de retraite :

| Profil | Versant | Financeur | Régime de retraite |
| --- | --- | --- | --- |
| Professeur des écoles | FPE | Budget de l'État, programme 140 | CAS Pensions |
| Infirmier en soins généraux | FPH | Assurance maladie (ONDAM, LFSS) | CNRACL |

Non-objectifs :

- Pas de calcul individuel de paie : l'outil raisonne sur des profils types et des moyennes.
- Pas de codage des régimes indemnitaires règle par règle en V1 : les primes sont un taux moyen observé par corps.
- Pas de données individuelles (fichiers détail Siasp, non ouverts).
- Pas de coûts indirects en V1 (locaux, équipement, encadrement, formation continue).
- Pas d'avis sur l'opportunité des recrutements : l'outil chiffre, il ne juge pas.

## Glossaire

| Terme | Définition |
| --- | --- |
| Effectif physique | Nombre de personnes, quelle que soit leur quotité de travail. |
| ETP | Équivalent temps plein : une personne à 80 % compte 0,8. |
| ETPT | ETP pondéré par la durée de présence dans l'année. Un recrutement au 1er septembre compte 4/12 d'ETPT la première année. |
| Schéma d'emplois | Solde des entrées et des sorties de l'année, en ETP. |
| Plafond d'emplois | Maximum d'emplois autorisé en loi de finances, en ETPT. |
| Indice majoré (IM) | Indice fixé par la grille selon le corps, le grade et l'échelon. |
| Point d'indice | Valeur en euros d'un point d'indice majoré. |
| TIB | Traitement indiciaire brut = IM × valeur du point. |
| Taux de primes | Primes et indemnités rapportées au TIB. |
| CAS Pensions | Compte d'affectation spéciale qui finance les retraites des fonctionnaires de l'État ; l'employeur y verse une contribution en % du TIB. |
| CNRACL | Caisse de retraite des fonctionnaires territoriaux et hospitaliers. |
| ONDAM | Objectif national de dépenses d'assurance maladie, voté en LFSS ; finance les hôpitaux. |
| PAP / RAP | Projet et rapport annuels de performances, annexes budgétaires par programme. |
| GVT | Glissement vieillesse-technicité : hausse de la masse salariale due à l'avancement de carrière. |
| Profil | Combinaison corps + grade d'entrée + versant + financeur, unité de base de la simulation. |
| Scénario | Liste de profils avec volumes et calendrier de recrutement. |

## Exigences structurantes

### EX-1 — Ajouter un profil sans toucher à l'existant

Ajouter un métier (forces de l'ordre, militaires, enseignement supérieur, agents territoriaux…) doit se faire par ajout de données et, au besoin, par ajout d'une classe, jamais par modification du moteur ou des profils existants.

Conséquences pour la conception :

- Un profil est une donnée déclarative : corps, grille, taux de primes, versant, financeur, régime de retraite. Aucun `if` sur un métier dans le moteur.
- Les spécificités sont portées par des règles enfichables, sélectionnées par le profil : régime de retraite (CAS civil, CAS militaire, CNRACL, Ircantec pour les contractuels), régime indemnitaire, mode de financement. Un nouveau régime = une nouvelle implémentation d'une interface existante, découverte par tag de service Symfony.
- Les militaires, les forces de l'ordre et l'enseignement supérieur servent de cas de test de conception dès la V1 : la revue d'architecture vérifie qu'ils pourraient s'ajouter sans modification (taux de 126,07 % du CAS militaire, grilles et bonifications spécifiques, financement par les opérateurs pour les universités).
- Critère d'acceptation : l'ajout d'un troisième profil en V1.x ne modifie aucun fichier existant hors catalogue de données et configuration.

### EX-2 — Comparer des programmes de candidats (V2)

À terme, l'outil doit confronter un programme électoral (« + 10 000 policiers », « + 5 000 infirmières ») au coût calculé, et comparer plusieurs programmes entre eux.

Ce qui est posé dès la V1 pour ne pas refondre ensuite :

- L'entité Scénario existe en V1 : liste de lignes (profil, volume, date de recrutement, étalement). Une simulation simple est un scénario d'une ligne.
- Un scénario peut porter des métadonnées de provenance : auteur, document source, URL, date, citation exacte de la promesse.
- Un scénario peut porter un montant annoncé par son auteur, pour afficher l'écart entre coût annoncé et coût calculé.
- Les résultats sont calculés pour une version donnée des paramètres, pour qu'une comparaison entre programmes se fasse à règles identiques.

Hors V1 : interface de comparaison côte à côte, saisie et modération des programmes, mesures non chiffrées en volume (« renforcer », « revaloriser »).

## Sources de données

Toutes les données viennent de sources publiques ; chaque valeur ingérée garde sa source, son URL et sa date d'effet.

| Donnée | Source | Format | Usage |
| --- | --- | --- | --- |
| Valeur du point d'indice | Décrets, portail de la fonction publique | Texte | Calcul du TIB |
| Grilles indiciaires par corps | Décrets statutaires, API Légifrance (PISTE) | Texte à extraire | Carrière : IM et durée par échelon |
| Taux de cotisation employeur | Décrets CAS Pensions et CNRACL, fiches annuelles des CDG | Texte, PDF | Charges employeur |
| Taux de primes moyen par corps | Rapport annuel DGAFP, Insee (Siasp, dataviz salaires) | XLSX, API Mélodi | Primes en V1 |
| Coûts moyens par catégorie d'emploi | PAP et RAP des programmes | PDF | Calibrage et validation |
| Plafonds d'emplois et schémas d'emplois | Lois de finances, data.gouv.fr, data.economie.gouv.fr | CSV, PDF | Mise en regard avec le budget |
| Concours : postes, candidats, admis | Rapport annuel DGAFP | XLSX | Indicateur de faisabilité |
| Effectifs par versant et par corps | Insee (Siasp), DGAFP | XLSX, CSV | Contexte, part de la masse salariale |
| ONDAM et LFSS | Lois de financement de la Sécurité sociale | PDF | Mise en regard pour la FPH |

Paramètres connus au 19 octobre 2026, à vérifier à l'ingestion :

- Point d'indice : 59,0734 € par an (4,92278 € par mois), inchangé depuis le 1er juillet 2023.
- CAS Pensions, fonctionnaires civils : 82,28 % du TIB depuis le 1er janvier 2026 (78,28 % en 2025).
- CAS Pensions, militaires : 126,07 % en 2025.
- CNRACL employeur : 37,65 % en 2026, puis 40,65 % en 2027 et 43,65 % en 2028 (décret n° 2025-86).

Sources : [CDG 33, chiffres rémunération 2026](https://www.cdg33.fr/wp-content/uploads/ressources/20260605_NIF_chiffresremuneration2026.pdf) · [Retraites de l'État, taux 2026](https://retraitesdeletat.gouv.fr/professionnels/le-versement-des-cotisations/les-entites-privees-associations-entreprises-gip-etc) · [Assemblée nationale, rapport CAS Pensions](https://www.assemblee-nationale.fr/dyn/opendata/RAPPANR5L17B1996-tIII-a37.html) · [Décret n° 2025-86](https://blog.landot-avocats.net/2025/01/31/augmentation-du-taux-de-cotisation-employeurs-a-la-cnracl/)

### Choix de la source du taux de primes

1. Pour chaque profil, on retient la source la plus proche du métier qui publie la décomposition traitement / primes (DREES, Insee, DGAFP, selon le cas).
2. À défaut, on applique la moyenne versant × catégorie de la DGAFP, et la fiche le signale en clair, par exemple « taux de primes approché : moyenne de la catégorie A de la FPH ».
3. La source retenue et la raison du choix sont documentées dans le catalogue du profil.

Infirmiers de la FPH : vérifier d'abord l'étude annuelle de la DREES sur les salaires du secteur hospitalier, sinon appliquer le repli.

## Architecture

Deux briques indépendantes reliées par un contrat de fichiers : Python agrège, Symfony calcule et affiche.

&#91;embedded content: architecture · agrégation Python, rendu Symfony\]

Le dépôt de données est le seul point de passage : aucune brique ne lit les sources de l'autre.

### Agrégation (Python)

- Un script par source, idempotent, rejouable à partir du fichier brut archivé.
- Sortie en CSV normalisé : UTF-8, dates ISO 8601, point décimal, une ligne par valeur datée.
- Un manifeste par fichier : source, URL, date de récupération, empreinte SHA-256 du brut, script et version.
- Contrôles de cohérence avant commit (une commande unique, qui bloque si une anomalie est détectée et liste toutes les anomalies) : indices (brut et majoré) strictement croissants par rang dans une grille ; périodes d'effet d'une même valeur datée sans chevauchement (adjacence autorisée, fin de période ouverte admise) ; valeurs dans des bornes plausibles, définies en liste blanche (une valeur non listée n'est pas bornée) ; source renseignée sur toute valeur dont le schéma en porte une.
- Toute modification de données passe par une pull request : le diff montre la valeur changée et sa source.
- Exception en V1 : les coûts moyens des PAP sont saisis à la main dans un CSV, chaque ligne référençant le document et la page. Un script d'extraction ne sera écrit que si le nombre de programmes à suivre le justifie.

### Contrat de données

- Chaque CSV a un schéma décrit (Frictionless Table Schema), validé côté Python à l'écriture et côté Symfony à l'import.
- Un changement incompatible de schéma incrémente la version majeure du contrat.

### Rendu (Symfony)

- Une commande d'import valide le dépôt à un commit donné, charge Postgres dans une transaction et enregistre le jeu de paramètres.
- Le moteur est un domaine PHP sans dépendance à Doctrine ; les règles (régime de retraite, régime indemnitaire, financeur) sont des implémentations d'interfaces, collectées par tag de service (EX-1).
- Une API JSON expose scénarios et résultats ; le front consomme l'API.
- Les résultats sont mis en cache par couple (scénario, version des paramètres).

Volumétrie : quelques mégaoctets au total. Les CSV ne sont jamais lus à l'exécution, seulement à l'import.

## Modèle de données

Toute valeur réglementaire est datée : elle porte une date de début d'effet, une date de fin éventuelle et une référence de source. Le moteur demande toujours « la valeur en vigueur à la date D ».

| Entité | Champs principaux | Rôle |
| --- | --- | --- |
| Source | id, intitulé, éditeur, URL, date de récupération, empreinte du fichier | Origine de toute valeur |
| Paramètre daté | clé (`point_indice`, `taux_cas_civil`…), valeur, unité, début, fin, source | Valeurs scalaires réglementaires |
| Corps | code, libellé, versant, catégorie A/B/C | Cadre statutaire |
| Grille | corps, grade, version, début, fin, source | Version d'une grille indiciaire |
| Échelon | grille, rang, indice brut, indice majoré, durée en mois | Progression de carrière |
| Taux de primes | corps, valeur, année d'observation, source | Primes en V1 |
| Régime de retraite | code (`CAS_CIVIL`, `CAS_MILITAIRE`, `CNRACL`, `IRCANTEC`), paramètres liés | Règle enfichable (EX-1) |
| Financeur | code (`ETAT_P140`, `ONDAM`…), libellé, budget de référence, codes dans les nomenclatures officielles (mission / programme / action pour l'État, sous-objectif de l'ONDAM, fonction COFOG) pour préparer la V3 | Rattachement budgétaire |
| Profil | corps, grade et échelon d'entrée, régime de retraite, régime indemnitaire, financeur, statut (titulaire ou contractuel), calendrier de référence (civil ou scolaire) | Unité de simulation |
| Scénario | titre, provenance, montant annoncé optionnel, version des paramètres | Simulation ou programme (EX-2) |
| Ligne de scénario | scénario, profil, volume, date de recrutement, étalement sur N années | Contenu d'un scénario |
| Jeu de paramètres | identifiant de version (commit du dépôt de données), date d'import | Reproductibilité des résultats |

## Moteur de calcul

Le moteur calcule un coût employeur annuel par agent, puis l'agrège selon trois horizons et quatre vues de coût. Toutes les valeurs sont prises à la date simulée, depuis le jeu de paramètres choisi.

### Coût annuel d'un agent

```latex
C = TIB \cdot (1 + p) + TIB \cdot r_{retraite} + TIB \cdot (1 + p) \cdot r_{autres} + \min(TIB \cdot p,\ 0{,}2 \cdot TIB) \cdot r_{RAFP}
```

- TIB = indice majoré de l'échelon × valeur annuelle du point.
- p = taux de primes du corps.
- r retraite = taux employeur du régime du profil (CAS civil, CAS militaire, CNRACL…).
- r autres = autres cotisations employeur (maladie, famille, FNAL, mobilité…), paramétrées par versant.
- Le dernier terme est la RAFP, assise sur les primes dans la limite de 20 % du TIB.

Chaque terme est une règle séparée, fournie par le régime du profil (EX-1). La formule ci-dessus est celle des titulaires ; les contractuels auront leur propre règle.

### Trois horizons

1. **Année 1** : coût budgétaire de l'année civile du recrutement, au prorata de la date d'entrée. Un recrutement au 1er septembre pèse 4/12 d'une année pleine. Le coût en année pleine est toujours affiché juste à côté, pour éviter qu'un faible coût d'année 1 soit lu comme un faible coût réel. Pour les profils dont le calendrier de référence est scolaire (enseignement), une vue en année scolaire est proposée en option.
2. **Régime permanent** : coût annuel de N postes durablement pourvus. Hypothèse V1 : population stationnaire, chaque échelon pondéré par sa durée dans la carrière. Calibré contre le coût moyen chargé publié dans le PAP.
3. **Cohorte** : coût année par année des N agents recrutés, de l'entrée à un horizon choisi (10 ans par défaut, carrière complète en option), avec l'avancement d'échelon. Pas de départs en V1.

### Vues de coût

La composante retraite peut être valorisée de plusieurs façons, toutes défendables. L'outil les calcule toutes ; l'utilisateur choisit celle(s) qu'il affiche. Chaque vue est accompagnée de sa définition, de la question à laquelle elle répond, de ses hypothèses et de ses sources.

| Vue | Question à laquelle elle répond | Taux ou méthode retraite | Hypothèses à afficher |
| --- | --- | --- | --- |
| Budgétaire | Combien le budget paie-t-il réellement ? | Taux réel du régime (82,28 % pour le CAS civil, 37,65 % pour la CNRACL en 2026) | Aucune : conventions en vigueur |
| Équivalent privé | Combien coûterait ce poste si l'agent cotisait comme un salarié du privé ? | Taux employeur de droit commun, base + complémentaire | Périmètre exact du taux retenu, à documenter |
| Équilibre technique | Quel taux financerait le régime sans subvention d'équilibre ? | Taux d'équilibre publié ou calculé par régime | Source et année de l'estimation, démographie du régime |
| Actuarielle | Quelle pension l'agent acquiert-il chaque année, en valeur actuelle ? | Valeur actuelle des droits acquis dans l'année | Table de mortalité, taux d'actualisation, âge de départ, revalorisation des pensions |

Règles :

- Chaque vue est une méthode de valorisation enfichable, au même titre que les régimes (EX-1) : en ajouter une ne modifie pas le moteur.
- Les hypothèses de la vue actuarielle sont modifiables par l'utilisateur, avec des valeurs par défaut sourcées ; toute valeur modifiée est signalée sur la fiche et dans l'URL de partage.
- Une fiche partagée indique toujours quelle vue est affichée ; afficher plusieurs vues côte à côte est possible.
- Les autres composantes du coût (traitement, primes, autres cotisations) sont identiques dans toutes les vues.

### Hypothèses par défaut

- Euros courants, point d'indice gelé à sa dernière valeur connue. Une hypothèse de revalorisation annuelle est paramétrable et affichée.
- Temps plein, sans heures supplémentaires ni rémunérations accessoires.
- Entrée au premier échelon du grade d'entrée, ou à l'échelon prévu par le profil (stagiaire, reclassement).

## Sorties, UX et règles de transparence

Une simulation produit une fiche de résultat lisible par un non-spécialiste, dont chaque nombre se déplie jusqu'à sa source.

### Contenu d'une fiche de résultat

- Coût année 1, coût en régime permanent, coût cumulé de la cohorte, dans la ou les vues de coût choisies (budgétaire par défaut).
- Courbe du coût de la cohorte année par année.
- Mise en regard : part du budget du financeur (programme ou ONDAM), équivalent en points de pourcentage de la masse salariale du corps.
- Indicateur de faisabilité : volume demandé comparé au nombre d'admis aux concours des dernières sessions.
- Liste des hypothèses appliquées et version du jeu de paramètres.

### Règles de transparence

1. Aucun nombre affiché sans détail de calcul accessible en un clic : formule, valeurs d'entrée, sources.
2. Chaque valeur d'entrée renvoie à sa source officielle, avec URL, date de récupération et date d'effet.
3. Toute hypothèse (gel du point, taux de primes moyen, population stationnaire) est nommée sur la fiche.
4. Le financeur est toujours indiqué : une simulation FPH ne s'affiche jamais en part du budget de l'État.
5. Une simulation est partageable par URL stable et rejouable à l'identique grâce à la version des paramètres.
6. Le code (AGPL-3.0) et les données dérivées (Licence Ouverte 2.0) sont publics ; la méthodologie est publiée avec l'outil. Toute version déployée et modifiée doit publier ses modifications.
7. Une source s'affiche en clair, lisible sans cliquer : éditeur, titre du document, édition ou année, localisation précise (page, tableau, article du décret), puis le lien. Jamais un simple code ou un lien nu.

## Roadmap et décisions

### Étapes

1. **V0 — Socle de données** : dépôt de données, manifeste, ingestion du point d'indice, des taux de cotisation et des grilles des deux profils V1.
2. **V1 — Simulateur deux profils** : moteur, trois horizons, quatre vues de coût, fiche de résultat, calibrage contre les coûts moyens du PAP du programme 140.
3. **V1.x — Extension des profils** : forces de l'ordre, militaires, enseignement supérieur, ajoutés selon EX-1 ; c'est la preuve que l'architecture tient.
4. **V2 — Programmes des candidats** : saisie sourcée des programmes, écart entre coût annoncé et coût calculé, comparaison côte à côte.
5. **V3 — Vue budgétaire complète** : mise en regard des coûts avec l'ensemble des dépenses et des recettes publiques, sur les trois sphères (État, Sécurité sociale, collectivités), jamais sur le seul budget de l'État. Sources prévues : lois de finances détaillées par mission et programme, tome I de l'Évaluation des voies et moyens pour les recettes de l'État, LFSS, comptes nationaux de l'Insee par fonction (COFOG). Pour chiffrer les mesures de financement d'un programme (« financé par la suppression de telle niche »), s'appuyer sur OpenFisca plutôt que recoder la fiscalité.
6. **Plus tard** : primes règle par règle, contractuels, départs et attrition dans les cohortes, coûts indirects optionnels.

### Décisions prises

- [x] Vue économique : les trois méthodes sont retenues, au choix de l'utilisateur (voir Vues de coût).
- [x] Coûts moyens des PAP : saisie manuelle sourcée à la page en V1, extraction automatique seulement si le nombre de programmes grossit.
- [x] Source du taux de primes : la plus proche du métier, repli sur la moyenne versant × catégorie signalé en clair (voir Sources de données).
- [x] Année 1 : année civile par défaut, année pleine toujours affichée à côté, année scolaire en option selon le calendrier du profil.
- [x] Licences : AGPL-3.0 pour le code, Licence Ouverte 2.0 (Etalab) pour les données dérivées.

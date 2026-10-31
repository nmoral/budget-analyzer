# Documentation technique — brique Symfony

Ce document décrit l'architecture du squelette de l'application `symfony/` tel qu'il
existe aujourd'hui, et comment le lancer et le tester en local. Les seuils et la
chaîne qualité ne sont pas dupliqués ici : ils font foi dans `CLAUDE.md` (source
unique).

## Pile technique

- PHP 8.4 (`require: php >=8.4`), Symfony 7.x.
- Application construite sur le `MicroKernelTrait` (`App\Kernel`, `src/Kernel.php`).
- `doctrine/doctrine-bundle` avec **Doctrine ORM** (`doctrine/orm ^3.7`) et
  `doctrine/doctrine-migrations-bundle ^3.7` (voir « Persistance » plus bas).
- Serveur web : **serveur PHP intégré**, pas de PHP-FPM / nginx à ce stade.

## Organisation du code et séparation domaine / infrastructure

Le code applicatif vit sous `symfony/src/` avec un mapping PSR-4 déclaré dans
`composer.json` :

| Préfixe PSR-4 | Dossier | Rôle |
|---------------|---------|------|
| `App\` | `src/` | racine de l'application |
| `App\Domain\` | `src/Domain/` | domaine métier en **PHP pur**, sans dépendance à Doctrine ni à l'infra |
| `App\Infrastructure\` | `src/Infrastructure/` | adaptateurs techniques (contrôleurs, persistance, etc.) |

Les tests sont sous `tests/` (`App\Tests\`, mapping `autoload-dev`).

`src/Domain/` est en **PHP pur** (aucune dépendance à Doctrine ni à l'infra). Depuis
#12 la frontière est **garantie en double** : par **Deptrac** (bloquant dans
`make qa-symfony`, config `symfony/deptrac.yaml` — toute référence à `Doctrine\*` ou
`App\Infrastructure\*` depuis `App\Domain\*` fait échouer la CI) **et**, en ceinture
et bretelles, par les tests d'architecture `DomainBoundaryTest` et `AutoloadTest`
conservés. Le modèle de données Doctrine (entités et repositories) vit sous
`src/Infrastructure/` : c'est un **modèle de persistance**, pas le domaine métier.
La frontière « le domaine ne connaît ni Doctrine ni l'infra » est une décision
structurante (voir `CLAUDE.md`, section PHP).

Le contrôleur de santé `App\Infrastructure\Controller\HealthController`, routé par
attribut sur `/health` (méthode `GET`), renvoie `{"status":"ok"}`. Les routes sont
chargées par attribut depuis `src/Infrastructure/Controller/` (`config/routes.yaml`).
Le domaine héberge par ailleurs le **moteur de règles** décrit à la section suivante.

## Moteur de domaine : règles enfichables par tag

Le moteur de calcul est posé en #12 sous forme d'**ossature pure** : contrats,
résolveurs et objets de domaine, **sans aucune règle métier réelle** (celles-ci
arrivent en #13→#18). Tout vit sous `src/Domain/` (PHP pur, frontière Deptrac).

### Familles de règles et contrat

Quatre familles de règles sont modélisées, chacune par une interface sous
`src/Domain/Rule/<Famille>/` : `RetirementRule`, `IndemnitaryRule`, `FunderRule`,
`CostViewRule`. Toutes étendent l'interface commune `App\Domain\Rule\Rule`, dont le
**seul contrat en #12** est l'identité de sélection :

```php
interface Rule { public function code(): string; }
```

Le contrat commun `Rule` reste minimal (identité de sélection). Les **signatures de
calcul** sont ajoutées par famille au fil des issues #13→#18. En #13, `RetirementRule`
reçoit sa première signature de calcul :

```php
interface RetirementRule extends Rule
{
    public function employerRate(\DateTimeImmutable $date): Rate; // #13
}
```

C'est le fait précédent (contrat + value object + port) repris par les familles
suivantes. Le détail de l'implémentation retraite est décrit à la section « Famille
retraite » plus bas.

### Résolution par service locator indexé par code

Chaque famille a son résolveur (`RetirementRuleResolver`, etc.), tous dérivés de
`App\Domain\Rule\AbstractRuleResolver`. La sélection est **déléguée à un service
locator** (port standard `Psr\Container\ContainerInterface`) indexé par code :
`resolve(string $code)` retourne l'implémentation enregistrée pour ce code, ou lève
`UnknownRuleCodeException` (exception de domaine `\DomainException`, message nommant la
famille et le code) si aucune n'existe. **Aucun `match`/`if` sur un code métier** ne
vit dans le moteur (décision structurante, vérifiée par `RuleResolverSelectionTest`).

Le locator concret est construit par Symfony dans `config/services.yaml` via
`!tagged_locator { tag: 'app.rule.<famille>', index_by: 'code' }`. **Enficher** une
règle = déclarer un service implémentant l'interface de la famille, taggé
`app.rule.<famille>` avec son `code` ; elle devient résolvable **sans toucher au moteur
ni aux résolveurs**. Les résolveurs sont déclarés `public: true` (points d'entrée du
moteur, consommés par #13→#18). Les **règles réelles de la famille retraite** sont
livrées en #13 (`CasCivilRule`, `CnraclRule`, taggées dans le bloc `services`
principal — voir « Famille retraite »). Pour les autres familles, aucune
implémentation métier n'est encore livrée : la découvrabilité y est prouvée par des
implémentations de **test** (`tests/Fixtures/Rule/`, codes `X_TEST`, `IFSE`,
`ETAT_P140`, `ANNEE_1`) taggées sous `when@test`. La fixture de test `CAS_CIVIL` a été
retirée en #13 (collision de code avec la règle réelle, le locator étant indexé par
code) ; `X_TEST` est conservée comme preuve d'enfichage.

### Profil de domaine pur, adapter et `RuleEngine`

`App\Domain\Profile\Profile` est un objet de domaine **pur** portant trois codes :
retraite, indemnitaire, financeur. La **vue de coût n'y figure pas** : c'est un
paramètre de simulation passé en entrée du moteur (décision « vue de coût =
paramètre de simulation »).

`App\Domain\Rule\RuleEngine::resolve(Profile $profile, string $costViewCode)` résout
les **quatre** implémentations (les trois codes du profil + le code de vue passé en
entrée) via les résolveurs et retourne un `ResolvedRules` (agrégat des quatre règles
sélectionnées).

Le pont avec la persistance est l'**adapter** `App\Infrastructure\Profile\DomainProfileFactory`
(seul point touchant les deux côtés) : `fromEntity()` construit le `Profile` pur depuis
l'entité Doctrine `Profile`, avec le mapping `retraite = RetirementScheme.code`,
`indemnitaire = Profile.indemnitaryScheme`, `financeur = Funder.code`. Le champ
`indemnitaryScheme` étant **nullable** en base alors que le `Profile` pur exige trois
codes non nuls, un profil sans régime indemnitaire est **rejeté** (`\InvalidArgumentException`,
côté infra). Les objets de données purs (`Profile`, `ResolvedRules`) sont **exclus** de
l'autowiring (`config/services.yaml`) : ils sont construits à la main, jamais injectés.

`RuleEngine` et `DomainProfileFactory` ne sont pas encore déclarés `public` : ils
seront câblés quand #13+ les consommera. En #12, la résolution est couverte par les
**tests seuls** (`tests/Domain/Rule/`, `tests/Infrastructure/Profile/`).

### Mutation testing (Infection)

La suite du moteur est passée sous **Infection** (mutation testing), scopé à
`src/Domain` (`symfony/infection.json.dist`, `minMsi`/`minCoveredMsi` = 85), bloquant
dans `make qa-symfony`. Le driver de couverture **pcov** est embarqué dans l'image
(`symfony/Dockerfile`). Seuils et outillage font foi dans `CLAUDE.md`.

## Famille retraite : taux employeur daté (#13)

Première famille de règles réelles. Elle expose le **taux employeur de retraite à une
date** via la signature `RetirementRule::employerRate(\DateTimeImmutable): Rate`. Tout
le domaine reste pur (frontière Deptrac) ; la valeur vient des paramètres datés (#04)
par un **port** de domaine et son **adapter** d'infrastructure.

### Value object `Rate` (`App\Domain\Shared`)

`Rate` représente un taux exprimé en pourcentage. Il est **décimal-safe** : la valeur
est stockée en interne comme un **entier d'échelle** (pourcentage × 10^6), jamais comme
un `float` — aucune dérive de précision. L'échelle décimale fixe (6 décimales) est
alignée sur le `DECIMAL(14,6)` des paramètres datés.

- `Rate::fromPercentage(string)` construit depuis une chaîne décimale de pourcentage
  (ex. `'82.28'`) ; une chaîne non décimale lève `\InvalidArgumentException` (validation
  de **format**, pas de plage). Au-delà de 6 décimales, la partie fractionnaire est
  tronquée (sans erreur ni arrondi ; sans impact car la source est `DECIMAL(14,6)`).
- `Rate::fromRatio(string)` (#14) construit depuis une chaîne décimale de **ratio**
  (ex. `'0.21'` pour 21 %) : le ratio porte deux décimales de plus que le pourcentage,
  il est lu à l'échelle SCALE+2 (au-delà, tronqué). Sert à mapper un taux de primes
  stocké comme ratio vers un `Rate`.
- `percentage()` renvoie la chaîne décimale canonique sans zéros de fin (`'82.28'`) ;
  `ratio()` renvoie pourcentage / 100 (`'0.8228'`), dérivé du même entier d'échelle.
- `equals()` : **égalité par valeur**.
- **Porteur neutre** : aucune validation de plage (la cohérence métier 0–200 reste à
  l'import #04).

### Port `DatedRateProvider` et adapter

- **Port de domaine** `App\Domain\Shared\DatedRateProvider` :
  `rateAt(string $key, \DateTimeImmutable $date): Rate`. Générique par clé, réutilisable
  par #14→#18. PHP pur, aucune dépendance infra.
- **Adapter d'infrastructure** `App\Infrastructure\Rate\DatedRateProviderAdapter` :
  lit `DatedParameterRepository::valueInEffectAt($key, $date)` (dernier jeu importé par
  défaut) et mappe la valeur vers `Rate` via `Rate::fromPercentage`. Si aucun paramètre
  ne couvre la date, lève `App\Domain\Shared\MissingDatedRateException` (pas de valeur
  par défaut silencieuse).
- **Exception** `MissingDatedRateException` (domaine, `\DomainException`) : construite
  par `forKeyAndDate()`, le message **et** les accesseurs `key()` / `date()` nomment la
  clé et la date.
- **Câblage** (`config/services.yaml`) : alias du port vers l'adapter
  (`App\Domain\Shared\DatedRateProvider: '@App\Infrastructure\Rate\DatedRateProviderAdapter'`),
  de sorte que toute injection du port reçoive l'adapter sans que le domaine connaisse
  l'infra.

### Règles réelles

`App\Domain\Rule\Retirement\CasCivilRule` (code `CAS_CIVIL`, clé `taux_cas_civil`) et
`CnraclRule` (code `CNRACL`, clé `taux_cnracl`) implémentent `RetirementRule`. Chaque
règle **connaît sa clé** de paramètre daté en dur (une clé, pas une valeur) et reçoit
le port `DatedRateProvider` ; `employerRate($date)` délègue à `rateAt(clé, $date)` —
**aucun taux en dur**. Les deux sont taggées `app.rule.retirement` (attribut `code`)
dans le **bloc `services` principal** de `config/services.yaml`, donc résolues par le
profil via le moteur #12, et couvertes par Infection (règles pures : elles dépendent du
port de domaine, pas de l'adapter).

## Coût annuel d'un agent titulaire (#14)

Le domaine assemble le **coût employeur annuel** d'un agent titulaire à une date
simulée `D`, décomposé en quatre termes :

```
C = TIB·(1+p) + TIB·r_retraite + TIB·(1+p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP
```

Tout vit sous `src/Domain/Cost/` (PHP pur, frontière Deptrac) ; les valeurs datées
viennent des paramètres importés par des **ports de domaine** et leurs **adapters**
d'infrastructure. Le calcul est **décimal-safe et reproductible** (aucun float ;
deux calculs identiques donnent des termes égaux).

### Montant décimal-safe : value object `Amount` (`App\Domain\Shared`)

`Amount` est un value object de domaine pur représentant un montant décimal à
**échelle fixe 6 décimales** (alignée sur le `DECIMAL(14,6)` des paramètres datés),
toute l'arithmétique passant par **bcmath** (extension embarquée dans l'image, cf.
« Docker »). Aucun float n'intervient.

- `fromString(string)` : construit depuis une chaîne décimale à point ; une entrée
  non décimale (ex. `"12,5"`, exposant, signe `+`) lève `\InvalidArgumentException`.
  Au-delà de 6 décimales, l'entrée est arrondie half-up.
- `multipliedByRate(Rate)` : produit par un taux, **arrondi à 6 décimales half-up**
  (demi s'éloignant de zéro) — le produit intermédiaire est calculé à l'échelle
  `SCALE + SCALE + 2` avant arrondi.
- `multipliedByIndex(int)` : produit par un indice entier (ex. indice majoré), exact.
- `plus(Amount)` : somme **exacte** (aucun arrondi supplémentaire).
- `min(Amount)` : le plus petit des deux montants (plafonnement de l'assiette RAFP).
- `equals(Amount)` : égalité par valeur ; `toString()` : forme canonique à 6 décimales.

**Règle d'arrondi d'ensemble** : chaque produit est arrondi half-up à 6 décimales,
mais le total est la **somme exacte** des quatre termes — aucun arrondi métier du
résultat (l'arrondi d'affichage relève de #19/#20).

### Ports et adapters des valeurs datées

- **`DatedAmountProvider`** (port de domaine) : `amountAt(string $key, DateTimeImmutable
  $date): Amount`. Adapter `App\Infrastructure\Amount\DatedAmountProviderAdapter` au-dessus
  de `DatedParameterRepository::valueInEffectAt` (dernier jeu importé) ; lève
  `MissingDatedAmountException` si aucun paramètre ne couvre la date. Sert au TIB (clé
  `point_indice`).
- **`PrimeRateProvider`** (port de domaine) : `primeRateAt(string $bodyCode,
  DateTimeImmutable $date): PrimeRateObservation`. `PrimeRateObservation` porte le `Rate`
  et le **drapeau fallback** (transparence du calcul). Adapter
  `App\Infrastructure\Prime\PrimeRateProviderAdapter` au-dessus de
  `PrimeRateRepository::forYear(bodyCode, année de D)` (désormais scopé au jeu de
  paramètres, cf. « Valeurs datées ») ; mappe la valeur stockée (ratio) via
  `Rate::fromRatio` ; lève `MissingPrimeRateException` si aucune observation ne précède
  la date.

Les deux ports sont aliasés vers leur adapter dans `config/services.yaml`, de sorte
qu'une injection du port reçoive l'adapter sans que le domaine connaisse l'infra.

### Composants de la formule

- **`IndexedSalaryCalculator`** : TIB = indice majoré × valeur annuelle du point
  d'indice (`point_indice`) en vigueur à `D`, via `DatedAmountProvider`.
- **Famille « autres cotisations » (r_autres)** : **nouvelle famille de règles
  enfichables** `App\Domain\Rule\OtherContributions`, indexée par **versant**, découverte
  par le tag `app.rule.other_contributions` (attribut `code` = versant). Deux règles
  réelles : `FpeOtherContributionsRule` (code `FPE`, clé `taux_autres_cotisations_fpe`)
  et `FphOtherContributionsRule` (code `FPH`, clé `taux_autres_cotisations_fph`). Chaque
  règle expose `rate(DateTimeImmutable): Rate`, connaît sa clé en dur et lit le taux via
  `DatedRateProvider` (aucun taux en dur). Le résolveur `OtherContributionsRuleResolver`
  (dérivé de `AbstractRuleResolver`) lève `UnknownRuleCodeException` sur un versant sans
  règle (ex. `FPT`).
- **`RafpContributionCalculator`** : composant **fixe** (pas une famille enfichable) du
  terme RAFP `min(TIB·p, 0,2·TIB)·r_RAFP` ; le plafond 20 % est une constante de la
  formule portée par ce composant, le taux lu sous la clé `taux_rafp` via
  `DatedRateProvider`.

### Assemblage : `AnnualCostCalculator` et `AnnualCost`

`AnnualCostCalculator::compute(AgentSituation $situation, DateTimeImmutable $date):
AnnualCost` assemble les quatre termes :

- **traitement + primes** = `TIB·(1+p)` ;
- **retraite** = `TIB·r_retraite`, le taux venant de la règle du régime résolue par
  `RetirementRuleResolver` (#13) ;
- **autres cotisations** = `TIB·(1+p)·r_autres`, règle résolue par versant ;
- **RAFP** = terme plafonné ci-dessus.

`AgentSituation` est un value object de domaine pur (code corps, versant, indice
majoré, code régime de retraite), **construit à la main** ; l'adapter depuis le modèle
de persistance du profil (`Profile`/`Echelon` Doctrine) est reporté à #15. `AnnualCost`
est un value object pur exposant les quatre termes et leur **total** (somme exacte,
calculée dans son constructeur). Les termes hors retraite sont indépendants du taux de
retraite (prépare #18). Un code de régime inconnu lève `UnknownRuleCodeException` ;
toute valeur datée absente à `D` lève l'exception explicite correspondante (pas de
défaut silencieux). `AgentSituation` et `AnnualCost` sont **exclus de l'autowiring**
(`config/services.yaml`) : ce sont des données pures, jamais injectées.

### Taux réels r_autres et r_RAFP sourcés (#49)

Les clés `taux_autres_cotisations_fpe` / `_fph` (r_autres) et `taux_rafp` (RAFP),
**connues en dur** par leurs composants, sont désormais **présentes dans le dépôt de
données** (sourçage livré par **#49**, entrée datée unique à effet `2026-01-01`, unité
`PCT_TIB`) : `taux_autres_cotisations_fpe` = 16.07, `taux_autres_cotisations_fph` = 17.13,
`taux_rafp` = 5.00. Après `app:import`, `DatedRateProvider::rateAt` renvoie ces taux et
`AnnualCostCalculator::compute` produit les quatre termes **sur données réelles** (termes
3 et 4 compris). Avant la `start_date` (ex. `2025-12-31`), la lecture lève toujours
`MissingDatedRateException` — aucun défaut silencieux. La composition ligne à ligne des
deux agrégats (et l'exclusion du versement mobilité) est documentée dans
`data/catalogue_autres_cotisations.md`.

### Câblage et tests

Dans `config/services.yaml` : règles `FPE`/`FPH` taggées, résolveur
`OtherContributionsRuleResolver` déclaré `public` avec son `!tagged_locator`, et les
alias de ports `DatedAmountProvider` / `PrimeRateProvider`. Le calcul est un domaine
PHP pur **exercé par les tests seuls** (aucune route ni commande) : tests unitaires
purs (`tests/Domain/Cost/`, `tests/Domain/Shared/`) et tests d'intégration sur
`budget_test` (`tests/Infrastructure/Persistence/`) prouvant le câblage port → adapter
de bout en bout (`IndexedSalaryCalculator`, `AnnualCostCalculator` et le port de taux de
primes sont rendus `public` sous `when@test`).

## Horizon « année 1 » d'un recrutement (#15)

À partir du coût annuel (#14), le domaine calcule l'**horizon « année 1 »** d'un
recrutement : le coût de la première année proraté à la date de recrutement, le coût
année pleine en regard, et une vue année scolaire optionnelle. Tout vit sous
`src/Domain/Cost/` en **PHP pur** (frontière Deptrac, aucune dépendance Doctrine ni
infra). Le périmètre est **domaine seul** : la date de recrutement et le calendrier sont
des **entrées** du calculateur, composées à la main en test ; aucun câblage de
persistance, aucune route ni commande n'est ajouté.

### Fondation arithmétique : `Amount::proratedByMonths`

`App\Domain\Shared\Amount` reçoit sa **première division** :
`proratedByMonths(int $present, int $total = 12): Amount` multiplie le montant par la
fraction `present/total` (12 par défaut), le numérateur `× present` restant **exact** et
seul le quotient étant arrondi **half-up à 6 décimales**. Le quotient est calculé à
l'échelle `SCALE + 2` (constante `PRORATION_SCALE`) avant arrondi, cohérent avec la
convention décimale d'`Amount` (bcmath, aucun float). Il n'y a **pas de garde** sur
`total = 0` ou `present < 0` : hors périmètre, et éviter du code non testé préserve le
MSI.

### Enum de domaine `ReferenceCalendar`

`App\Domain\Cost\ReferenceCalendar` est une enum de domaine **pure** (sans backing
scalar) à deux cas : `CIVIL` (année 1 comptée du recrutement à décembre inclus) et
`SCOLAIRE` (fenêtre année scolaire 1er sept → 31 août). Elle est passée **en entrée** du
calculateur. L'entité Doctrine `Profile` porte aujourd'hui un champ `referenceCalendar`
sous forme de chaîne, non remontée vers le domaine ; le mapping chaîne → enum relève de
l'adapter de persistance reporté à une issue ultérieure.

### Comptage de mois : `CalendarMonths`

`App\Domain\Cost\CalendarMonths` est un utilitaire PHP pur (constructeur privé, méthode
statique) :
`countUntil(\DateTimeImmutable $recruitment, int $endMonth): int` compte les mois civils
du **mois** de recrutement jusqu'à la prochaine occurrence de `$endMonth` (1 = janvier …
12 = décembre), **bornes incluses**, le **jour étant ignoré** (mois d'entrée compté
entier). Si `$endMonth` est antérieur au mois de recrutement, la fenêtre franchit l'année
suivante (`$endMonth + 12`) — c'est le **rollover** de la fenêtre scolaire. Ainsi
`countUntil($d, 12)` donne l'année 1 civile (sept→déc = 4, janv→déc = 12, déc→déc = 1) et
`countUntil($d, 8)` la fenêtre scolaire (sept→août = 12, nov→août = 10).

### Calculateur et value object résultat

`App\Domain\Cost\Year1HorizonCalculator::compute(AgentSituation $situation,
\DateTimeImmutable $recruitment, ReferenceCalendar $calendar): Year1Horizon` dépend par
constructeur de l'`AnnualCostCalculator` **concret** (#14) — pas d'interface, hors
périmètre. Il calcule d'abord le coût année pleine (`AnnualCostCalculator::compute(
$situation, $recruitment)`), puis proratise chacun des quatre termes du coût annuel via
`Amount::proratedByMonths($months)`, où `$months = CalendarMonths::countUntil(...)` :
`DECEMBER` (12) pour l'année 1, `AUGUST` (8) pour la vue scolaire. La vue scolaire n'est
calculée que si `$calendar === ReferenceCalendar::SCOLAIRE`, sinon `null`.

Le résultat `App\Domain\Cost\Year1Horizon` est un value object **pur** (simple porteur à
trois getters) : `fullYear(): AnnualCost` (année pleine non proratée), `year1():
AnnualCost` (première année civile proratée) et `schoolYear(): ?AnnualCost` (vue
scolaire, `null` en calendrier civil). Les totaux des vues proratées sont **recomposés
par le constructeur d'`AnnualCost`** (somme des quatre termes proratés, jamais le total
proraté séparément), garantissant la cohérence `total = somme des termes`.

### Garde-fou d'architecture et tests

Le test d'architecture `DomainBoundaryTest` interdit tout littéral `/Doctrine|Entity/i`
dans `src/Domain/` (commentaires compris), en ceinture et bretelles de Deptrac. Ces
composants sont exercés par des **tests unitaires purs** (`tests/Domain/Cost/`,
`tests/Domain/Shared/`, PHPUnit sans conteneur ni base), les ports/règles étant fournis
en classes anonymes et `AgentSituation` construit à la main. Comme le reste du domaine de
coût, l'horizon est couvert par Infection (mutation sur `src/Domain`, MSI ≥ 85 %).

## Horizon « régime permanent » d'un corps (#16)

À partir du coût annuel (#14), le domaine calcule l'**horizon « régime permanent »** d'un
corps en **population stationnaire** : le coût annuel moyen d'un agent est la moyenne des
coûts d'échelon pondérée par la durée de chaque échelon, projetée sur N postes. Tout vit
sous `src/Domain/Cost/` en **PHP pur** (frontière Deptrac, aucune dépendance Doctrine ni
infra). Le périmètre est **domaine seul** : la carrière (échelons + durées) et N sont des
**entrées** du calculateur, composées à la main en test ; aucun câblage de persistance,
aucune route ni commande n'est ajouté.

### Fondation arithmétique : `Amount::dividedBy`

`App\Domain\Shared\Amount` reçoit une **division générique** :
`dividedBy(int $divisor): Amount` divise le montant par un diviseur entier, arrondi
**half-up à 6 décimales**. Le quotient est calculé à l'échelle `SCALE + 2` (constante
`DIVISION_SCALE`, alignée sur `PRORATION_SCALE`) avant arrondi, cohérent avec la convention
décimale d'`Amount` (bcmath, aucun float). Un **diviseur nul est rejeté**
(`\InvalidArgumentException`) ; le diviseur négatif n'est pas gardé (hors flux #16 où
`totalMonths ≥ 1` et `N ≥ 1`, et éviter du code non testé préserve le MSI). Elle complète
`proratedByMonths` (#15), qui multiplie par une fraction `present/total`, là où `dividedBy`
divise par un entier seul (moyenne pondérée, répartition par effectif).

### Enum de domaine `PopulationAssumption`

`App\Domain\Cost\PopulationAssumption` est une enum de domaine **pure** (sans backing
scalar), à un seul cas `STATIONARY` (population stationnaire). Elle est portée par le value
object résultat et exposée sans logique de calcul, pour rendre lisible l'hypothèse de
population retenue (prépare d'autres hypothèses éventuelles).

### Value objects d'entrée : `CareerStep` et `Career`

- `App\Domain\Cost\CareerStep` est un value object pur `(AgentSituation $situation, int
  $durationMonths)` : la situation valorisable d'un agent (#14) et la durée en mois pleins
  passée sur cet échelon. La **durée doit être strictement positive** (`durationMonths > 0`,
  sinon `\InvalidArgumentException`) ; l'échelon terminal reçoit donc une durée fournie, pas
  de durée vide.
- `App\Domain\Cost\Career` est un value object pur construit par
  `__construct(CareerStep ...$steps)` : la suite ordonnée des échelons, terminal inclus. Une
  **carrière vide est rejetée** (`\InvalidArgumentException`). `totalMonths(): int` renvoie
  la somme des durées des échelons ; `steps(): list<CareerStep>` les expose.

### Calculateur et value object résultat

`App\Domain\Cost\PermanentRegimeCalculator::compute(Career $career, \DateTimeImmutable
$date, int $headcount): PermanentRegimeCost` dépend par constructeur de
l'`AnnualCostCalculator` **concret** (#14) — pas d'interface, hors périmètre. Il **garde
l'effectif** (`$headcount ≥ 1`, sinon `\InvalidArgumentException`), puis, pour chacun des
quatre termes du coût annuel, accumule le numérateur `Σ(terme_i × duréeMois_i)` via
`Amount::multipliedByIndex($months)` (produit **exact** par un entier) chaîné par `plus`
(accumulateur initialisé à `Amount::fromString('0')`, somme exacte, arrondi **uniquement**
au quotient final), chaque échelon étant valorisé par `AnnualCostCalculator::compute(
$step->situation(), $date)`. Le coût moyen par agent est l'`AnnualCost` des quatre termes
`numérateur->dividedBy($career->totalMonths())`.

Le résultat `App\Domain\Cost\PermanentRegimeCost` est un value object **pur** construit
avec le coût moyen (`AnnualCost`) et l'effectif : `averagePerAgent(): AnnualCost` renvoie
le coût moyen d'un agent (quatre termes moyens + total) ; `forHeadcount(): AnnualCost`
multiplie chaque terme par N via `multipliedByIndex` (le total de l'`AnnualCost` se
**recompose** comme somme des termes × N, jamais multiplié séparément) ; `assumption():
PopulationAssumption` renvoie `STATIONARY`, sans logique. Le VO ne porte aucun calcul
métier au-delà du produit par l'effectif.

### Garde-fou d'architecture et tests

Le test d'architecture `DomainBoundaryTest` interdit tout littéral `/Doctrine|Entity/i`
dans `src/Domain/` (commentaires compris), en ceinture et bretelles de Deptrac. Ces
composants sont exercés par des **tests unitaires purs** (`tests/Domain/Cost/`,
`tests/Domain/Shared/`, PHPUnit sans conteneur ni base), les ports/règles étant fournis en
classes anonymes et `AgentSituation`/`Career` construits à la main. Comme le reste du
domaine de coût, l'horizon est couvert par Infection (mutation sur `src/Domain`,
MSI ≥ 85 %).

## Horizon « cohorte » d'un recrutement (#17)

À partir du coût annuel (#14), le domaine calcule l'**horizon « cohorte »** : la **série
annuelle** du coût d'une cohorte de N agents, de l'entrée jusqu'à un horizon, avec
**avancement d'échelon mensuel**. Tout vit sous `src/Domain/Cost/` en **PHP pur**
(frontière Deptrac, aucune dépendance Doctrine ni infra). Le périmètre est **domaine
seul** : la carrière (échelons + durées, réutilisée de #16), la date d'entrée, l'horizon et
l'effectif N sont des **entrées** du calculateur, composées à la main en test ; aucun
câblage de persistance, aucune route ni commande n'est ajouté. Aucune nouvelle méthode
`Amount` n'est requise (le prorata intra-année et les cumuls réutilisent `multipliedByIndex`,
`plus` et `dividedBy`, #14→#16).

### Calculateur `CohortHorizonCalculator`

`App\Domain\Cost\CohortHorizonCalculator` dépend par constructeur de l'`AnnualCostCalculator`
**concret** (#14) — pas d'interface, hors périmètre — et réutilise les value objects
`Career`/`CareerStep` (#16). Deux points d'entrée :

- `compute(Career $career, \DateTimeImmutable $entry, int $horizonYears, int $headcount = 1):
  CohortCostSeries` — garde `horizonYears ≥ 1` et `headcount ≥ 1` (sinon
  `\InvalidArgumentException`). Il valorise d'abord **une seule fois** le coût annuel de
  chaque échelon à la date d'entrée (**point gelé** : aucune revalorisation d'une année à
  l'autre, seul l'échelon évolue), puis construit la série année par année.
- `computeFullCareer(Career $career, \DateTimeImmutable $entry, int $headcount = 1):
  CohortCostSeries` — mode « carrière complète » : **dérive l'horizon**
  `⌈Σ(durées des échelons non terminaux) / 12⌉`, calculé en entier par
  `intdiv(Σ + 11, 12)` (sans flottant), **clampé à 1** minimum (une carrière réduite au seul
  terminal donne Σ = 0 → horizon 1 an, série minimale non vide, plutôt que de violer la
  garde). Il délègue ensuite à `compute(...)` (aucune duplication de garde ni de série).

**Avancement d'échelon** : `activeStepIndex()` détermine l'échelon actif au mois _m_
(0-indexé depuis l'entrée) par les **durées cumulées** des échelons — step0 couvre `[0, d0)`,
step1 `[d0, d0+d1)`, etc. Le **dernier `CareerStep` est le terminal** : sa durée propre est
**ignorée** et il reste actif indéfiniment une fois les échelons non terminaux épuisés (pas
de départ, pas d'attrition).

**Découpage et prorata intra-année** : l'année _k_ (0-indexée en interne) couvre les mois
`[k·12, (k+1)·12)` glissants depuis l'entrée (années pleines de 12 mois, **pas** de prorata
calendaire de la 1re année — c'est #15). `yearCost()` calcule, pour chacun des **quatre
termes** de l'`AnnualCost`, la **moyenne des coûts d'échelon actifs sur les 12 mois** : somme
terme par terme des 12 coûts mensuels (`plus`, accumulateur à `Amount::fromString('0')`) puis
`dividedBy(12)`. Une année entièrement sur un échelon rend l'`AnnualCost` de cet échelon ;
une année à cheval rend `Σ(mois_échelon × terme)/12` terme par terme (via la somme mensuelle,
bcmath exact, équivalent à la formule groupée).

### Value object résultat `CohortCostSeries`

`App\Domain\Cost\CohortCostSeries` est un value object **pur** portant la série (`list<AnnualCost>`,
une entrée par année) et l'effectif N. Il ne recalcule rien, il **projette terme par terme**
la série déjà valorisée :

- `perYear(): list<AnnualCost>` — coût annuel d'un agent, année par année (exactement
  `horizonYears` entrées) ;
- `forHeadcount(): list<AnnualCost>` — chaque année × N (chaque terme `multipliedByIndex(N)`,
  le total de l'`AnnualCost` se **recompose** comme somme des termes × N, jamais multiplié
  séparément) ; identique à `perYear()` quand N = 1 ;
- `totalPerAgent(): AnnualCost` — cumul d'un agent sur tout l'horizon (somme terme par terme
  des années) ;
- `totalForHeadcount(): AnnualCost` — `totalPerAgent()` × N terme par terme.

### Garde-fou d'architecture et tests

Le test d'architecture `DomainBoundaryTest` interdit tout littéral `/Doctrine|Entity/i` dans
`src/Domain/` (commentaires compris), en ceinture et bretelles de Deptrac. Ces composants
sont exercés par des **tests unitaires purs** (`tests/Domain/Cost/CohortHorizonCalculatorTest.php`,
PHPUnit sans conteneur ni base), les ports/règles étant fournis en classes anonymes et
`Career`/`AgentSituation` construits à la main. Comme le reste du domaine de coût, l'horizon
est couvert par Infection (mutation sur `src/Domain`, MSI ≥ 85 %).

## Vues de coût enfichables (#18)

Le domaine valorise la **composante retraite** du coût annuel (#14) selon des **vues de
coût enfichables**, dans la continuité des familles de règles EX-1 (#12). Tout vit sous
`src/Domain/` en **PHP pur** (frontière Deptrac, aucune dépendance Doctrine ni infra). Le
périmètre est **domaine seul** : la situation de l'agent, la date et le code de vue sont
des **entrées**, composées à la main en test ; aucun câblage de persistance, aucune route
ni commande n'est ajouté.

### Famille EX-1 `cost_view` et interface `CostViewRule`

La famille `cost_view` (ossature posée en #12, interface marqueur vide) reçoit en #18 sa
signature de calcul. `App\Domain\Rule\CostView\CostViewRule` (étend `Rule`) expose :

- `retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): Amount`
  — le terme retraite employeur annuel selon la vue ;
- `definition(): string`, `question(): string` — métadonnées de restitution (non vides) ;
- `assumptions(): list<string>` — hypothèses de la vue (**vide** pour la budgétaire) ;
- `sources(): list<string>` — sources documentaires.

La signature est volontairement en **types `Shared`/natifs** (`Amount`,
`\DateTimeImmutable`, `string`), **pas** `AgentSituation`, pour éviter un **cycle de
dépendances domaine** `Cost ↔ Rule` (le paquet `Cost` dépend déjà de `Rule`) : c'est la
surcouche (dans `App\Domain\Cost`) qui calcule le TIB et le passe à la vue.

Les vues sont **résolues par code** via `CostViewRuleResolver` (dérivé
d'`AbstractRuleResolver`, `family() = 'vue de coût'`) : elles sont taggées
`app.rule.cost_view` avec leur `code`, et le résolveur les sélectionne par un **service
locator indexé par code** (`!tagged_locator { index_by: code }`), jamais par un
`match`/`if`. Un code de vue inconnu lève `UnknownRuleCodeException`.

### Surcouche `CostViewCalculator`

`App\Domain\Cost\CostViewCalculator::compute(AgentSituation $situation,
\DateTimeImmutable $date, string $costViewCode): AnnualCost` est la surcouche (décision
Q2, « option B ») : elle **recompose** l'`AnnualCost` en ne remplaçant **que le terme
retraite** par celui de la vue sélectionnée, les trois autres termes (traitement + primes,
autres cotisations, RAFP) restant **ceux d'`AnnualCostCalculator`, laissé inchangé** —
zéro régression #14→#17 ; en `BUDGETAIRE`, le résultat est **strictement égal** au moteur
de base. Elle dépend par constructeur d'`AnnualCostCalculator`, d'`IndexedSalaryCalculator`
(pour calculer le TIB passé à la vue) et de `CostViewRuleResolver`. Elle calcule **une vue
à la fois** (sélection multi-vues = boucle appelant, #19).

### Les quatre vues

- **`BudgetViewRule`** (`BUDGETAIRE`, défaut) : `retraite = TIB × employerRate(régime, date)`
  via `RetirementRuleResolver` (#13) — même source de taux que le moteur de base.
  `assumptions()` vide.
- **`PrivateEquivalentViewRule`** (`EQUIVALENT_PRIVE`) : `retraite = TIB × rateAt(
  'taux_employeur_prive', date)` — **clé datée fixe** lue via le port `DatedRateProvider`,
  indépendante du régime public.
- **`TechnicalBalanceViewRule`** (`EQUILIBRE_TECHNIQUE`) : `retraite = TIB × rateAt(clé,
  date)` via `DatedRateProvider`, où la clé est **dérivée par régime** par concaténation
  (`'taux_equilibre_' . strtolower($code)` → `taux_equilibre_cas_civil`,
  `taux_equilibre_cnracl` ; pas de table ni de `match`). Un régime sans paramètre
  d'équilibre fait lever `MissingDatedRateException` (pas de défaut silencieux).
- **`ActuarialViewRule`** (`ACTUARIELLE`) : `retraite = (TIB × taux d'annuité) × facteur
  actuariel`, hypothèses lues dans le VO `AssumptionsActuarielles` injecté à ses défauts.
  Le facteur actuariel (multiplicateur sans unité) est appliqué via un `Rate` porté par son
  **ratio** (`Rate::fromRatio`) pour réemployer la multiplication décimal-safe d'`Amount`
  sans étendre le cœur (abus sémantique de `Rate` assumé et localisé).

Les **valeurs réelles** des taux « équivalent privé » et « équilibre technique » relèvent
de l'import CSV/infra (**hors périmètre #18**) : elles sont posées à la main en test, et la
règle lève tant qu'elles manquent à la date.

### Value object d'hypothèses `AssumptionsActuarielles`

`App\Domain\Rule\CostView\AssumptionsActuarielles` est un value object de domaine **pur**
portant les deux hypothèses consommées par la formule V1 :

- `tauxAnnuite(): Rate` — droits de pension acquis par année de service (défaut **1,75 %**) ;
- `facteurActuariel(): string` — multiplicateur (ä) résumant actualisation, survie et
  différé, **fourni en hypothèse** (aucune table de mortalité dans le domaine), porté comme
  **chaîne décimale** pour rester décimal-safe (défaut **12,5**).

Il est construit par la **factory `defaults()`** (défauts sourcés ; les sources
documentaires de la méthode — note INSEE sur les engagements de retraite de l'État, Jaune
budgétaire « Pensions », recommandation ANC 2013-R-02 — sont exposées par
`ActuarialViewRule::sources()`). Les surcharges `withTauxAnnuite()` /
`withFacteurActuariel()` renvoient une **nouvelle
instance** et **marquent le champ comme modifié** ; `isModified(string $field): bool`
indique si un champ a été surchargé (les autres restant « défaut ») et **rejette un champ
inconnu** (`\InvalidArgumentException`). Le VO est **exclu de l'autowiring**
(`config/services.yaml`, donnée pure) ; il est injecté à l'`ActuarialViewRule` via la
factory `AssumptionsActuarielles::defaults()`.

### Enfichabilité et câblage

Ajouter une vue = déclarer un service implémentant `CostViewRule`, taggé
`app.rule.cost_view` avec son `code` ; elle devient résolue et utilisée par la surcouche
**sans toucher ni à `CostViewCalculator` ni au résolveur**. Cette enfichabilité est prouvée
par une fixture de test (`tests/Fixtures/Rule/CostView/EnfichableCostViewRule.php`, code
`X_TEST_VIEW`) taggée sous `when@test`. Dans `config/services.yaml` : les quatre vues
taggées dans le bloc `services` principal (l'`ActuarialViewRule` recevant son VO
d'hypothèses par factory) et `CostViewCalculator` exposé `public` sous `when@test`.

### Garde-fou d'architecture et tests

Frontière **domaine pure** (Deptrac + `DomainBoundaryTest`) : aucune table de mortalité
embarquée, aucun `Doctrine`/`Entity`. Ces composants sont exercés par des **tests unitaires
purs** (`tests/Domain/Cost/CostViewCalculatorTest.php`, `tests/Domain/Rule/CostView/`,
PHPUnit sans conteneur ni base) : ports et règles fournis en classes anonymes,
`AgentSituation` et VO d'hypothèses construits à la main. Comme le reste du domaine de coût,
couvert par Infection (mutation sur `src/Domain`, MSI ≥ 85 %).

## API JSON scénarios/résultats + cache (#19)

Première **couche HTTP métier** : une API JSON crée et lit des scénarios, puis sert leur
**résultat de simulation** (horizon « année 1 », #15) valorisé par vue (#18), avec un
**cache du résultat par (scénario, version des paramètres, sélection)**. Tout vit sous
`src/Infrastructure/` (contrôleur, orchestrateur, cache, adapters, repositories) ; le
**domaine reste pur** (seul ajout domaine : `Year1HorizonCalculator::computeFrom`, voir
plus bas) et la **frontière Deptrac** est préservée.

### Endpoints et contrôleur

`App\Infrastructure\Controller\ScenarioController` (routé par **attributs**, découvert par
`config/routes.yaml` comme `HealthController`) expose trois routes :

| Route | Méthode | Rôle | Codes |
|-------|---------|------|-------|
| `/api/scenarios` | `POST` | créer un scénario | `201` / `422` |
| `/api/scenarios/{id}` (`id` = `\d+`) | `GET` | lire un scénario | `200` / `404` |
| `/api/scenarios/{id}/result?horizons=&views=` | `GET` | lire le résultat | `200` / `404` / `422` |

- **`POST /api/scenarios`** attend `{title, lines:[{profileRef, volume, recruitmentDate,
  spreadYears?}], parametersVersion?}`. `profileRef` est l'**id Doctrine** d'un `Profile`.
  Le scénario est **épinglé à un `ParameterSet`** : `parametersVersion` absent → `latest()` ;
  présent → `ParameterSetRepository::findByVersionId()`. Succès → `201` `{id,
  parametersVersion}`. Entrée invalide (corps non-JSON, `title` vide/absent, `lines` vide,
  ligne mal formée, `profileRef`/`volume`/`spreadYears` non entiers, `recruitmentDate`
  illisible, version demandée inconnue, aucun jeu importé) → **`422`** `{error}`. Le
  scénario reçoit `provenance = 'simulation'` par défaut (champ NOT NULL absent du corps).
- **`GET /api/scenarios/{id}`** → `200` `{id, title, parametersVersion, lines:[{id,
  profileRef, volume, recruitmentDate, spreadYears}]}` ; scénario inconnu → **`404`**.
- **`GET /api/scenarios/{id}/result`** → `200` avec le résultat (structure ci-dessous) ;
  scénario inconnu → `404` ; vue ou horizon inconnu → `422` (`InvalidSelectionException`).
  Une vue **valide mais non valorisable** avec le jeu épinglé — un paramètre daté requis
  (taux, montant ou prime) manque pour la date demandée — → **`422`** `{error}` nommant la
  vue et la clé/corps manquant (+ date), **jamais `500`** (#58). La chaîne de calcul
  enveloppe les exceptions de domaine `MissingDated{Rate,Amount}Exception` /
  `MissingPrimeRateException` dans `UncomputableViewException` (infra), mappée en `422` par
  le contrôleur ; l'erreur est levée **avant** la mise en cache (cache non empoisonné).
  Sélection mixte (vue calculable + vue non valorisable) → **tout-ou-rien** : `422` global
  nommant la vue fautive. En-tête **`X-Cache: MISS`** (calcul) ou **`HIT`** (servi du cache).

Le **mapping JSON est manuel** (VO/entité → tableau → `JsonResponse`, style
`HealthController`) : **pas de `symfony/serializer`** (décision Q3).

### Sélection (`horizons`, `views`) et structure de la réponse résultat

`ScenarioController::selection()` lit un paramètre `?a,b,c` : valeurs **triées dans l'ordre
d'apparition, trimmées, vides ignorées et dédupliquées** (R1 : une répétition ne double ni
l'agrégat ni la clé de cache) ; sélection vide → **défaut** (`horizons=year1`,
`views=BUDGETAIRE`). La réponse résultat :

```
{
  "scenarioId", "parametersVersion", "horizons": [...], "views": [...],
  "lines": [ { "lineId", "profileRef", "volume",
              "results": { "<vue>": { "<horizon>": { "fullYear", "year1", "schoolYear" } } } } ],
  "aggregate": { "<vue>": { "<horizon>": { "fullYear", "year1", "schoolYear" } } }
}
```

Chaque fenêtre (`fullYear`, `year1`, `schoolYear`) est un `AnnualCost` sérialisé en cinq
chaînes décimales : `treatmentAndPrimes`, `retirement`, `otherContributions`, `rafp`,
`total` (pleine précision, aucun arrondi d'affichage). `schoolYear` vaut `null` pour un
profil à calendrier civil. Le seul horizon supporté est `year1` (constante
`ScenarioResultCalculator::SUPPORTED_HORIZONS`).

### Orchestrateur `ScenarioResultCalculator`

`App\Infrastructure\Cost\ScenarioResultCalculator::compute(Scenario, horizons, views)`
valide d'abord la sélection (`assertHorizons` contre `SUPPORTED_HORIZONS` ; `assertViews`
en déléguant à `CostViewRuleResolver::resolve`, un code inconnu devenant
`InvalidSelectionException`), puis, **pour chaque ligne et chaque vue** :

1. construit la situation d'agent et le calendrier via `AgentSituationFactory` ;
2. calcule le coût année pleine valorisé par la vue (`CostViewCalculator::compute`,
   #18) **dans la portée du jeu épinglé** (voir « Scope ») ;
3. applique le prorata année 1 au domaine via `Year1HorizonCalculator::computeFrom(...)`.

L'**agrégat** est, par vue et par fenêtre, la somme `Σ(coût_ligne × volume)` **terme par
terme** (`Amount::multipliedByIndex($volume)` puis `plus`, le total de l'`AnnualCost` se
recomposant comme somme des termes). Une fenêtre `schoolYear` absente (ligne civile) n'est
pas sommée.

`App\Infrastructure\Cost\AgentSituationFactory` est l'**adapter** entité → domaine (seul
point touchant les deux côtés) : `fromProfile()` construit l'`AgentSituation`
(`body.code`, `body.versant`, `entryEchelon.increasedIndex`, `retirementScheme.code`) et
`calendarFor()` mappe `Profile.referenceCalendar` (insensible à la casse) vers
`ReferenceCalendar`, une valeur inconnue levant `\InvalidArgumentException`.

### Ajout domaine : `Year1HorizonCalculator::computeFrom`

Pour appliquer une **vue** à l'horizon année 1 sans dupliquer le prorata en infrastructure,
`App\Domain\Cost\Year1HorizonCalculator` reçoit `computeFrom(AnnualCost $fullYear,
\DateTimeImmutable $recruitment, ReferenceCalendar $calendar): Year1Horizon` : il prend un
coût annuel **déjà valorisé** (par une vue), l'expose tel quel en `fullYear`, proratise
l'année 1 (recrutement → décembre) et la vue scolaire (sept → août) si le calendrier est
scolaire. L'ancien `compute(AgentSituation, ...)` (#15) délègue désormais à `computeFrom`.
C'est le **seul ajout domaine** de #19 (reste pur, couvert par Infection).

### Scope version : `ParameterSetScope` et adapters scopés

Le calcul doit se faire **contre la version épinglée** du scénario, pas « le dernier jeu
importé ». Plutôt que reconstruire les adapters, #19 introduit une **portée de requête**
`App\Infrastructure\ParameterSet\ParameterSetScope` : `within(ParameterSet $set, callable
$cb)` active `$set` le temps du calcul (restauré en `finally`), `current()` renvoyant le
jeu actif ou `null`. Les adapters datés (`DatedRateProviderAdapter`,
`DatedAmountProviderAdapter`, `PrimeRateProviderAdapter`) reçoivent la portée et passent
`$this->scope->current()` au `?ParameterSet` de leurs repositories (`valueInEffectAt`,
`forYear`). Hors portée active (`current() === null`), ils retombent sur le **dernier jeu
importé** — comportement historique inchangé. L'architecture enfichable par tags est
préservée (le domaine ignore la portée).

`ParameterSetRepository::findByVersionId(string)` est ajouté pour résoudre une version
demandée à la création.

### Cache du résultat : `ScenarioResultCache` et pool

`App\Infrastructure\Cost\ScenarioResultCache` enveloppe l'orchestrateur sur un pool PSR-6.
La **clé** = `'scenario_result_' . sha256(scenarioId | versionId | horizons triés | vues
triées)` : deux appels identiques partagent l'entrée (rejouabilité), une **sélection** ou
une **version** différente a une clé distincte (jamais de cache croisé) ; le **tri** rend
l'ordre des horizons/vues indifférent. `get()` renvoie un `CachedScenarioResult` (résultat
+ drapeau `hit()`), lu par le contrôleur pour l'en-tête `X-Cache`. Une sélection invalide
(`InvalidSelectionException`) remonte au contrôleur et **n'est pas mise en cache** (422).
De même, une vue valide mais non valorisable (`UncomputableViewException`, paramètre daté
manquant) est levée **avant** la sauvegarde : l'erreur **n'empoisonne pas le pool**, si bien
qu'après import du paramètre manquant (qui purge le pool) le résultat se calcule (422, #58).

Le **pool** `app.cache.scenario_result` est un `FilesystemAdapter` à **répertoire et
namespace fixes** (`var/cache/scenario_result`, `config/services.yaml`), **hors**
`%kernel.cache_dir%` donc **indépendant de l'environnement** résolu par le process (A1/J4) :
le serveur web (résout `prod`) et la console `app:import` (résout `dev`) pointent le **même
stockage**, condition pour que la purge à l'import atteigne le pool réellement lu par l'API.
Il n'est **pas** déclaré sous `framework.cache.pools` (un pool framework dérive son
répertoire/namespace du `kernel.cache_dir`, cf. commentaire de `config/packages/cache.yaml`)
et son `clearer` est désactivé (`autoconfigure: false`) pour ne purger qu'au ré-import. Le
pool est injecté par `bind` : `$pool` dans le service de cache, `$resultCache` dans la
commande d'import.

### Invalidation par `app:import`

`ImportCommand` reçoit le pool (`$resultCache`) et appelle `clear()` **après un import
réussi** : les résultats en cache dépendent des paramètres importés, on les purge pour qu'ils
soient recalculés contre le nouvel état (jamais de cache périmé, y compris au ré-import
idempotent du même commit — le `versionId` ne changeant pas, l'invalidation passe par la
purge et non par la clé).

### Idempotence de l'import préservant les FK d'objets utilisateur (A2/J4)

La FK **NOT NULL** `scenario.parameter_set_id` introduite par #19 (et `profile.entry_echelon_id`)
cassait le ré-import idempotent existant (`delete`/`recreate` du `ParameterSet` → violation
de FK). `Importer` ne supprime plus les lignes **référencées par un objet utilisateur** : au
ré-import du même commit, le `ParameterSet` est **réutilisé** (même id), `Grille` et `Echelon`
sont mis à jour **en place par clé naturelle** (upsert : `{body}_{grade}_{version}`, puis
`grilleKey#rank`), tandis que `DatedParameter` et `PrimeRate` — que rien ne référence — sont
**remplacés** par rechargement, et `Source`/`Corps` restent en upsert sur `code`. La chaîne
scénario → ligne → profil → échelon reste valide (décisions Q2/Q5, toujours en une seule
transaction).

### Repositories et câblage

Ajoutés sous `src/Infrastructure/Repository/` : `ScenarioRepository` (`find`, `save` avec
ses lignes), `ScenarioLineRepository` (`forScenario`), `ProfileRepository` (`find`), et
`ParameterSetRepository::findByVersionId`. Les services (contrôleur, orchestrateur, cache,
scope, adapters, repositories) sont autowirés ; `symfony/cache` est rendu **explicite** dans
`composer.json` (déjà transitif). Aucune nouvelle commande de chaîne qualité ni nouveau port
réseau : `make qa-symfony` est inchangé.

### Tests

HTTP (`tests/Infrastructure/Controller/` : `ScenarioApiTest`, `ScenarioResultApiTest`,
`ScenarioResultCacheApiTest`), pool de cache (`tests/Infrastructure/Cache/ScenarioResultCachePoolTest`
dont l'indépendance à l'environnement), import idempotent (`tests/Infrastructure/Import/ImportCommandTest`),
repositories (`tests/Infrastructure/Persistence/ScenarioRepositoryTest`), domaine pur
(`tests/Domain/Cost/Year1HorizonCalculatorTest::computeFrom`), avec le trait de fixtures
`tests/Infrastructure/Fixture/ScenarioFixtureTrait`.

### Hors périmètre

Non exposés par #19 : les horizons **régime permanent** et **cohorte** (adapter `Career`
reconstruit depuis la grille — issue ultérieure) ; le **détail de calcul par montant**
(formule/entrées/sources par montant — #20 ; les métadonnées de vue restent disponibles mais
la trace par montant n'est pas structurée) ; le **ré-épinglage** d'un scénario (`PATCH`) et
l'édition de ses lignes ; le **hash de contenu** du jeu de paramètres (on s'appuie sur
`versionId` + purge à l'import) ; le front, l'authentification et le listing/pagination des
scénarios.

## Kernel et amorçage

- `App\Kernel` utilise `MicroKernelTrait` ; la configuration est en YAML sous
  `config/` (`bundles.php`, `packages/framework.yaml`, `packages/doctrine.yaml`,
  `routes.yaml`, `services.yaml`).
- `public/index.php` est le point d'entrée HTTP **et** le script routeur du serveur
  PHP intégré : il amorce l'environnement via `symfony/dotenv` (`.env` s'il existe),
  détermine `APP_ENV` / `APP_DEBUG`, puis instancie et exécute le `Kernel`.
- `bin/console` fournit la console Symfony (style classique, sans `symfony/runtime`,
  cohérent avec `public/index.php`). Les commandes se lancent dans le conteneur :
  `docker compose run --rm symfony php bin/console <cmd>`.

## Persistance : Doctrine ORM

`config/packages/doctrine.yaml` configure le bloc `dbal` (URL de connexion lue dans
`DATABASE_URL`, `%env(resolve:DATABASE_URL)%`) **et** le bloc `orm`. Le mapping est
déclaré par **attributs** sur les entités de `src/Infrastructure/Entity`
(`prefix App\Infrastructure\Entity`, alias `App`). La naming strategy
`underscore_number_aware` produit des colonnes en `snake_case`. Les migrations sont
gérées par `doctrine-migrations-bundle` (`config/packages/doctrine_migrations.yaml`,
namespace `App\Migrations` → `symfony/migrations/`). La migration initiale
(`Version20261029171156`) crée l'ensemble du schéma.

### Modèle de données

Le modèle de persistance comprend **12 entités** dans `src/Infrastructure/Entity/`.
Le code est en anglais, aligné sur les colonnes CSV du dépôt `data/` ; les noms de
domaine français (Corps, Grille, Échelon) sont conservés comme noms d'entité. Les
identifiants sont des entiers auto-générés ; les montants sont en `DECIMAL` (mappés en
`string` PHP) ; les dates en `date_immutable`.

- **Source** — origine documentaire d'une valeur réglementaire : `code` (clé métier
  unique, slug référencé par la colonne `source` des CSV, ex. `decret-2023-519`, résolu
  à l'import #11), `label`, `publisher`, `url`, `retrievedAt`, `fileHash`.
- **Corps** — `code` (unique), `label`, `versant`, `category` (A/B/C).
- **Grille** — version d'une grille indiciaire : `body` (FK Corps), `grade`, `version`,
  `startDate`, `endDate` (nullable), `source` (FK), `parameterSet` (FK). Valeur datée.
- **Echelon** — `grille` (FK), `rank`, `grossIndex`, `increasedIndex`,
  `durationMonths` (nullable). Valeur datée **via sa grille** (pas de FK directe vers
  le jeu de paramètres).
- **RetirementScheme** — `code` (unique), `label`.
- **Funder** — `code` (unique), `label`, puis codes de nomenclature tous nullables :
  `mission` / `program` / `action` (État), `ondamSubObjective`, `cofog`.
- **DatedParameter** — valeur scalaire réglementaire : `key` (`point_indice`,
  `taux_cas_civil`…), `value`, `unit`, `startDate`, `endDate` (nullable), `source` (FK),
  `parameterSet` (FK). Valeur datée. La colonne `key` est échappée (`` `key` ``).
- **PrimeRate** — taux de primes (primes V1) : `body` (FK Corps), `value`,
  `observationYear`, `source` (FK), `fallback` (booléen), `parameterSet` (FK).
- **Profile** — unité de simulation : `body` (FK), `grade`, `entryEchelon` (FK Echelon),
  `retirementScheme` (FK), `indemnitaryScheme` (chaîne nullable — pas d'entité dédiée,
  les primes V1 passent par `PrimeRate`), `funder` (FK), `status`, `referenceCalendar`.
- **Scenario** — `title`, `provenance`, `announcedAmount` (DECIMAL nullable),
  `parameterSet` (FK).
- **ScenarioLine** — `scenario` (FK), `profile` (FK), `volume`, `recruitmentDate`,
  `spreadYears`.
- **ParameterSet** — jeu de paramètres pour la reproductibilité : `versionId` (unique,
  commit du dépôt de données), `importedAt`. Les valeurs datées (DatedParameter, Grille,
  Echelon via sa grille, PrimeRate) et les Scénarios portent une FK vers ce jeu.

Les entités sont **purement déclaratives** (aucun `repositoryClass`, aucune logique) ;
le peuplement réel est assuré par la commande d'import `app:import` (voir « Import du
dépôt de données » plus bas).

### Valeurs datées : sémantique et repositories

Les accès « datés » sont des **services autonomes** de `src/Infrastructure/Repository/`
(l'`EntityManagerInterface` est injecté ; les entités ne déclarent pas de repository).

- **Bornes de validité incluses** : une valeur datée est en vigueur sur
  `[startDate, endDate]`, bornes **incluses** ; `endDate` à `NULL` = période ouverte
  (∞). L'adjacence est autorisée (fin au jour J, début au jour J+1). Cette sémantique
  suit le contrat Python (`python/src/checks` `check_periods_no_overlap`, bornes
  incluses) et les données (`taux_cas_civil` : 2025-12-31 → 2026-01-01).
- **« Valeur en vigueur à la date D »** = l'unique ligne où `startDate ≤ D` et
  (`endDate IS NULL OR endDate ≥ D`), regroupée par `key` (`DatedParameterRepository::`
  `valueInEffectAt`) ou par `body_code` + `grade` (`GrilleRepository::valueInEffectAt`),
  et **scopée à un jeu de paramètres**. L'unicité repose sur l'invariant de
  non-chevauchement des périodes d'un même groupe, garanti en amont (contrôles Python) ;
  l'import #11 n'ajoute **pas** de contrainte DB d'unicité (décision Q7, voir « Import du
  dépôt de données »). Retourne `null` si aucune période ne couvre D.
- **Scope du jeu de paramètres** : les deux méthodes prennent un `ParameterSet`
  optionnel ; à défaut, elles utilisent le **dernier jeu importé**
  (`ParameterSetRepository::latest()`, tri `importedAt` puis `id` décroissants).
  Si aucun jeu n'existe, le résultat est `null`.
- **Taux de primes « pour l'année Y »** (`PrimeRateRepository::forYear`) : dernier
  `observationYear ≤ Y` pour le corps ; à année égale, le tri `fallback ASC` fait
  gagner la ligne non-fallback. L'entité renvoyée porte le drapeau `fallback`. Depuis
  #14, la méthode est **scopée à un jeu de paramètres** : elle prend un `ParameterSet`
  optionnel (défaut : **dernier jeu importé** via `ParameterSetRepository::latest()`)
  et ne retient que les observations de ce jeu — **pas de repli silencieux sur un autre
  jeu**. `null` si aucun jeu n'existe ou aucune observation `≤ Y` dans le jeu.

### Tests et exécution

#### Base de test dédiée `budget_test`

Les tests s'exécutent sur une **base dédiée `budget_test`**, isolée de la base de dev
`budget` : un run de la suite ne touche jamais aux données de développement. L'isolation
est obtenue par le **mécanisme Doctrine natif** `dbname_suffix` — `config/packages/doctrine.yaml`,
bloc `when@test`, définit `dbname_suffix: '_test'`, qui suffixe le nom de base configuré
dans `DATABASE_URL`. On isole ainsi au niveau **base** (pas au niveau schéma PostgreSQL),
sans manipuler le `search_path`.

Le bootstrap PHPUnit **`tests/bootstrap.php`** (référencé par l'attribut `bootstrap` de
`phpunit.dist.xml`) provisionne cette base **une fois, avant la suite** :

1. il crée `budget_test` si elle est absente (`doctrine:database:create --if-not-exists`,
   via la console Symfony) — aucune étape manuelle de création de base, en local comme en CI ;
2. il remet le schéma `public` de `budget_test` à zéro (`DROP SCHEMA public CASCADE` puis
   `CREATE SCHEMA public`), ce qui garantit un état vide déterministe et rend le contrôle
   « schéma public sain » (`DatabaseConnectionTest`) fiable **même si `budget` est migrée**.

Le bootstrap est **tolérant à un serveur Postgres absent** : toute erreur de connexion ou
de provisioning est journalisée sur `STDERR` sans faire échouer le bootstrap, de sorte que
les tests purs unitaires (p. ex. `Architecture`) tournent sans base.

**Garde-fou** : le reset destructeur du schéma n'est exécuté **que si la base ciblée se
termine par `_test`** (`App\Tests\Support\TestDatabaseGuard::isTestDatabase`, testé
unitairement). Si `dbname_suffix` repassait à `''`, le bootstrap s'interromprait proprement
plutôt que de droper le schéma de la base de dev `budget` — aucune action destructrice hors
base de test.

#### Cas de test

Les tests de persistance (`tests/Infrastructure/Persistence/`) s'appuient sur
`IntegrationTestCase`, qui **recrée le schéma depuis le mapping ORM** en `setUp`/`tearDown`
et laisse la base (`budget_test`) vide après chaque test — y compris en purgeant
`doctrine_migration_versions`, hors mapping ORM —, pour que `DatabaseConnectionTest`
(qui exige un schéma `public` sans table applicative) reste vert.
`DatabaseConnectionTest` vérifie en outre que la connexion cible bien une base `_test`,
jamais `budget`. `MappingValidationTest` valide le mapping sans dépendre de l'état de la
base. Les repositories datés sont exposés `public` **en environnement de test seulement**
(`config/services.yaml`, bloc `when@test`) pour être récupérables depuis le conteneur de
test, faute de référence applicative tant que le moteur #12 ne les injecte pas.

Les tests d'intégration ORM exigent une base Postgres saine, donc `make up` (ou le
`up --wait postgres` de `make qa-symfony`). Le test du endpoint `/health`
(`HealthControllerTest`, `WebTestCase`) tourne, lui, sans base.

### Commandes Doctrine

```sh
docker compose run --rm symfony php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm symfony php bin/console doctrine:schema:validate
docker compose run --rm symfony php bin/console doctrine:query:sql '<SQL>'
```

`migrate` sur base vide suivi de `schema:validate` confirme que le mapping est correct
et que le schéma est « in sync ».

## Import du dépôt de données (`app:import`)

La commande console `app:import <commit>` peuple le modèle de persistance à partir du
dépôt de données. Elle vit sous `src/Infrastructure/Command/ImportCommand.php` et délègue
tout le travail au service `App\Infrastructure\Import\Importer`.

```sh
docker compose run --rm symfony php bin/console app:import <commit>
docker compose run --rm symfony php bin/console app:import <commit> --data-dir=/chemin/fixture

make import                  # raccourci : démarre Postgres puis importe le commit courant
make import COMMIT=<commit>  # importe un commit précis
```

Le `Makefile` expose un raccourci `make import` qui démarre Postgres (`up -d --wait
postgres`) puis lance `app:import` ; la variable `COMMIT` (défaut `git rev-parse HEAD`)
fournit la référence attendue par la commande.

- Argument `commit` (requis) : référence du commit du dépôt de données. C'est une
  **chaîne opaque** utilisée comme `version_id` du `ParameterSet` ; elle n'est **pas**
  résolue ni checkoutée (le conteneur ne monte pas `.git` — décision Q1). L'appelant
  (p. ex. CI via `git rev-parse HEAD`) fournit la valeur.
- Option `--data-dir` : racine du dépôt de données à importer. Défaut lié au paramètre
  `env(BUDGET_DATA_DIR)` (`/data`, montée en lecture seule dans le conteneur) ; surchargée
  pour viser une racine fixture.
- Codes de sortie : `0` au succès ; en cas d'`ImportException`, la commande affiche le
  message d'erreur et retourne `Command::FAILURE` (≠ 0).

### Composants de l'import

Sous `src/Infrastructure/Import/` :

- **`Importer`** : orchestre tout l'import dans **une seule transaction**
  (`EntityManagerInterface::wrapInTransaction`). Tout échec lève une `ImportException`
  (classe de base) et provoque le **rollback total** — aucune écriture partielle.
- **`CsvReader`** : lecture d'un CSV (`data/data/<fichier>.csv`) en en-tête + lignes
  associatives.
- **`FrictionlessSchemaValidator`** : validation de schéma **maison** (aucune lib
  Frictionless en PHP). Contrôle, contre `data/schemas/<schéma>.json` : présence des
  colonnes requises, valeurs requises non vides, et typage des valeurs non vides
  (`number`, `integer`, `year`, `boolean`, `date` ISO ; `string` et types non contraints
  acceptés). Les colonnes non requises peuvent être vides (ex. `end_date`). Lève
  `SchemaValidationException`. Ne contrôle que la **forme** (décision Q6) : les invariants
  métier restent côté Python.
- **`ManifestVerifier`** : vérifie l'intégrité des bruts par
  `sha256(data/sources/<manifest.raw>) == manifest.sha256`, manifeste lu dans
  `data/manifests/<fichier>.json`. Toute divergence (ou brut/manifeste manquant) lève
  `ManifestMismatchException` (décision Q4).
- **`SourceReferential`** / **`CorpsReferential`** : chargent les référentiels curatés
  `data/sources.json` (slug → `label`/`publisher`/`url`/`retrievedAt`/`fileHash`) et
  `data/corps.json` (code → `label`/`versant`/`category`).

### Périmètre, mapping et ordre de chargement

Le mapping fichier → schéma est la constante `Importer::FILE_TO_SCHEMA`, alignée sur le
`FILE_TO_SCHEMA` Python **hors `couts_pap`** (non importé, aucune entité cible — hors
périmètre #11). Avant tout chargement, l'`Importer` valide le schéma **et** vérifie
l'empreinte de **chacun** des 12 fichiers du mapping (C3 « avant tout chargement », C4).

Le chargement peuple ensuite, dans l'ordre :

1. `DatedParameter` (fichiers `point_indice`, `taux_cas_civil`, `taux_cas_militaire`,
   `taux_cnracl`, et depuis #49 `taux_autres_cotisations_fpe`,
   `taux_autres_cotisations_fph`, `taux_rafp`) — FK `source`, FK `parameterSet` ;
2. `Grille` (`grille_pe`, `grille_isg`) — FK `body` (Corps), `source`, `parameterSet` ;
   chaque grille est indexée en mémoire par la clé `{body_code}_{grade}_{version}` ;
3. `Echelon` (`echelon_pe`, `echelon_isg`) — rattaché à sa `Grille` par la colonne
   `grille` du CSV, résolue via cette clé composite ; clé introuvable → `ImportException`
   (« grille inconnue ») ;
4. `PrimeRate` (`taux_primes`) — FK `body` (Corps), `source`, `parameterSet`.

Sur le dépôt réel, cela produit les comptes : `dated_parameter` = 10, `grille` = 2,
`echelon` = 22, `prime_rate` = 2, `source` = 11, `corps` = 2.

### Résolution des FK et référentiels

Les FK `source` et `body` (Corps) sont résolues par **code** via des caches
code → entité (une seule entité par code dans la transaction) :

- une source citée par une colonne `source` est créée/mise à jour depuis
  `data/sources.json` ; un slug référencé mais **absent** du référentiel → refus
  (`UnknownSourceException`), même si une ligne `Source` de même code existait déjà en base
  (le référentiel fait foi) ;
- un corps cité par `body_code` est résolu depuis `data/corps.json` ; code absent → refus
  (`UnknownCorpsException`).

Ces référentiels sont des fichiers **curatés** (pas de CSV ni de schéma Frictionless) : les
colonnes `Source`/`Corps` étant toutes NOT NULL, le référentiel doit tout fournir
(décision Q3).

### Idempotence (réimport du même commit)

Réimporter le même commit laisse la base dans le même état (décision Q5, C6). En tête de
transaction, `purgeExistingCommit()` supprime en DQL les entités **scopées** au
`ParameterSet` du commit (Echelon d'abord — FK Grille —, puis Grille / DatedParameter /
PrimeRate, enfin le `ParameterSet`), puis `em->clear()` ; le set est ensuite recréé et
rechargé. Les référentiels **globaux** `Source`/`Corps` (pas de FK vers `ParameterSet`)
ne sont pas purgés mais mis à jour par **upsert sur `code`** (`findOneBy`). Aucune nouvelle
contrainte DB d'unicité n'est introduite (décision Q7) : l'atomicité vient de la
transaction, l'unicité des données de l'invariant amont.

### Câblage et configuration

L'`Importer` et ses collaborateurs sont des services autowirés (`config/services.yaml`,
bloc `App\`). La commande reçoit par `bind` le paramètre `string $defaultDataDir` =
`%env(BUDGET_DATA_DIR)%` (défaut `/data`). Aucune migration n'est ajoutée par #11 (le
schéma #10 suffit).

## Docker et exécution locale

La stack est décrite dans `docker-compose.yml` à la racine du monorepo. Services
concernés par la brique Symfony :

- `postgres` (image `postgres:16`) avec `healthcheck` `pg_isready` ;
- `symfony` : image construite depuis `symfony/Dockerfile`, dépend de `postgres`
  (démarrage conditionné à `service_healthy`).

Le service `symfony` :

- expose le serveur PHP intégré via la commande
  `php -S 0.0.0.0:8000 -t public public/index.php` (port interne `8000`, publié sur
  `${WEB_PORT}`) ;
- reçoit `DATABASE_URL`, `APP_ENV=dev`, `APP_DEBUG=1`, `APP_SECRET` par
  l'environnement du compose ;
- monte `./symfony` dans `/app` et utilise un **volume anonyme `/app/vendor`** pour
  préserver le `vendor/` construit dans l'image malgré le bind-mount ;
- tourne sous un **utilisateur non-root `app`** dont l'UID/GID est aligné sur celui
  de l'hôte (build args `UID`/`GID`, exportés par le `Makefile` depuis `id -u`/`id -g`,
  défaut `1000`). Ainsi tout ce que le conteneur écrit sur le bind-mount
  (`var/cache`, `var/log`, caches des outils…) appartient à l'utilisateur et non à
  `root` : un `git worktree remove` ou un `rm` ordinaire suffit, sans `sudo`. Le point
  de montage `symfony/vendor` est pré-créé par le `Makefile` (`mkdir -p`) pour que le
  démon Docker ne le crée pas lui-même en `root`.

Le `Dockerfile` (base `php:8.4-cli`) installe les extensions `intl`, `pdo_pgsql`,
`opcache` et **`bcmath`** (arithmétique décimale exacte du value object de domaine
`Amount`, #14), copie Composer, crée l'utilisateur `app` (UID/GID hôte), puis — sous
cet utilisateur — fait le `composer install` au build (couche de dépendances isolée)
et un `composer dump-autoload --optimize` après copie du code. Le driver de couverture
`pcov` (mutation testing) y est également embarqué.

### Ports et `.env`

`docker compose` lit un `.env` (gitignoré, propre au worktree) dérivé de
`.env.example`. Les ports suivent le format `FPYYY` (F = famille service, P = projet,
YYY = worktree) :

- `WEB_PORT` (famille 4) → `45YYY`, publie le serveur web ;
- `DB_PORT` (famille 5) → `55YYY`, publie Postgres ;
- `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` et `COMPOSE_PROJECT_NAME`
  isolent la stack du worktree.

### Commandes utiles

```sh
make up        # docker compose up -d (stack complète)
make down      # docker compose down -v
make build     # build des images

# sonde de santé une fois la stack démarrée
curl -fsS localhost:$WEB_PORT/health     # -> {"status":"ok"}

# console Symfony
docker compose run --rm symfony php bin/console <cmd>

# logs
docker compose logs symfony
```

## Chaîne qualité et CI

La chaîne qualité PHP est exécutée **dans le conteneur** et **bloquante** depuis #9.
`make qa-symfony` build l'image `symfony`, démarre Postgres (`up -d --wait postgres`)
puis enchaîne PHP-CS-Fixer (dry-run), PHPStan (`level: max`), **Deptrac** (frontière du
domaine, #12), PHPUnit et **Infection** (mutation sur `src/Domain`, MSI ≥ 85 %, #12),
en échouant au premier garde-fou KO. `make qa` lance `qa-python` puis `qa-symfony` ; en **local**,
`make qa` reste la **chaîne complète séquentielle inchangée** (mutmut complet).

### Orchestration CI (`.github/workflows/qa.yml`)

Le workflow applique **les mêmes garde-fous et les mêmes seuils** que `make qa` mais
**diverge dans l'orchestration** pour réduire le temps mur : au lieu d'un job unique
lançant `make qa`, il éclate la chaîne en deux jobs parallèles (`qa-python`,
`qa-symfony`) plus un job agrégateur `qa`. L'aperçu de cette divergence est dans
`CLAUDE.md` (section CI) ; les paragraphes ci-dessous documentent les **mécanismes
d'implémentation** côté dépôt.

**Caches.**

- `qa-python` installe `uv` avec `enable-cache: true` et
  `cache-dependency-glob: python/uv.lock` : le cache uv est restauré entre runs et
  invalidé quand `uv.lock` change.
- `qa-symfony` pré-build l'image `symfony` via `docker/build-push-action`
  (`cache-from/to: type=gha`, `push: false`) pour **réchauffer** le cache de layers du
  builder buildx par défaut du job. `docker compose build symfony` (dans
  `make qa-symfony`) réutilise alors ce builder et retrouve les layers apt/composer
  `CACHED` tant que `composer.lock` est inchangé, rejouées sinon. Les build-args
  `UID`/`GID` (hôte, `id -u` / `id -g`) sont passés au pré-build pour que sa clé de
  cache **coïncide** avec celle du build du `Makefile` ; sinon les layers postérieures à
  l'`ARG` seraient rejouées.

**Mutation (mutmut) scopée sur PR.** Sur un événement `pull_request`, mutmut ne mute que
le cœur Python modifié par la PR, pour raccourcir la CI sans baisser le MSI (≥ 85 %,
bloquant). Le job procède en deux temps :

1. `make qa-python MUTMUT_SKIP=1` : chaîne Python complète **sans** mutmut. Le hook
   `MUTMUT_SKIP` du `Makefile` neutralise uniquement les trois lignes mutmut (`run`,
   `export-cicd-stats`, `check_msi.py`) ; vide par défaut, il laisse `make qa-python`
   en local et sur push `main` en mutmut **complet**.
2. `python/scripts/mutmut_scoped.sh` (var `MUTMUT_BASE=origin/<base_ref>`,
   checkout en `fetch-depth: 0` pour disposer de l'historique) : calcule le `merge-base`
   avec la branche de base, liste les fichiers `src/{contract,ingest,sources,checks}/*.py`
   **ajoutés/modifiés/renommés** (`git diff --diff-filter=ACMR`, pour ignorer les
   suppressions et l'ancien chemin d'un renommage, absents de HEAD), puis restreint
   mutmut à ce périmètre via `python/scripts/scope_mutmut_config.py`. Ce dernier injecte
   un `only_mutate = [...]` dans la section `[tool.mutmut]` de `pyproject.toml`,
   **transitoirement sur le runner, jamais commité** ; les chemins hors
   `[A-Za-z0-9_./-]` sont rejetés pour ne pas corrompre le TOML. Comportements :
   - aucun fichier cœur modifié → étape **no-op verte** ;
   - périmètre produisant **0 mutant** (`total=0`, p. ex. module réduit à des imports)
     → **no-op verte** aussi (rien à tuer, MSI non calculable) ; `check_msi.py` n'est
     alors pas appelé ;
   - sinon, `check_msi.py` reste **bloquant** sur le périmètre réduit.

   Sur **push `main`**, aucun scoping : `make qa-python` joue mutmut **complet** sur les
   4 packages du cœur (MSI global), identique au local. Aucun seuil n'est modifié ; seul
   le **périmètre** muté sur PR change.

**Gate `qa`.** Le job `qa` (`needs: [qa-python, qa-symfony]`) est le **seul check requis**
par la protection de `main` ; son nom est préservé. Il porte `if: always()` et inspecte
`needs.*.result`, échouant (exit 1) si l'un des deux amonts n'est pas `success`. Sans
`if: always()`, un amont en échec rendrait `qa` *skipped* — or la protection de branche
traite un check requis *skipped* comme **passant**, et le merge ne serait pas bloqué. Le
gate est donc vert uniquement quand les deux chaînes le sont, rouge sinon.

Les seuils et outils (PHP-CS-Fixer PSR-12 + `strict_types`, PHPStan `level: max`,
Deptrac, PHPUnit, Infection — tous bloquants) sont décrits dans `CLAUDE.md`, qui
fait foi.

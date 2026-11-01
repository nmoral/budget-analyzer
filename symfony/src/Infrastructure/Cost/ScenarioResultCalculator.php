<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\CohortCostSeries;
use App\Domain\Cost\CohortHorizonCalculator;
use App\Domain\Cost\CostTrace;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\PermanentRegimeCalculator;
use App\Domain\Cost\Prorata;
use App\Domain\Cost\TermTrace;
use App\Domain\Cost\ViewAwareStepCost;
use App\Domain\Cost\Year1Horizon;
use App\Domain\Cost\Year1HorizonCalculator;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use App\Domain\Shared\Amount;
use App\Domain\Shared\CostInput;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\MissingPrimeRateException;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\Source;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\ScenarioLineRepository;
use App\Infrastructure\Transparency\SourceCapture;

/**
 * Orchestrateur de résultat (#19, J2) : pour chaque ligne du scénario et chaque vue
 * demandée, calcule les horizons sélectionnés — année 1 (fullYear / year1 proraté /
 * schoolYear) valorisée selon la vue, régime permanent (#66, coût annuel moyen par
 * agent sur la carrière du profil) et cohorte (#66, série année par année sur toute la
 * carrière) — puis l'agrégat Σ(résultat_ligne × volume), aligné par index d'année pour
 * la cohorte. Permanent et cohorte sont view-aware (terme retraite suivant la vue).
 *
 * Le calcul est fait CONTRE le jeu de paramètres épinglé du scénario : la portée
 * `ParameterSetScope` lie, le temps du calcul, les adapters datés à cette version
 * (rejouabilité). Le prorata année 1 est délégué au domaine (`computeFrom`). La carrière
 * du régime permanent est construite par la factory d'infrastructure (`CareerFactory`).
 */
final class ScenarioResultCalculator
{
    /** @var list<string> */
    private const array SUPPORTED_HORIZONS = ['year1', 'permanent', 'cohorte'];

    /**
     * Hypothèses structurelles appliquées par le moteur, nommées pour la transparence
     * (#65, C3). Exposées en mode `detail=full` à côté des hypothèses propres à chaque vue.
     *
     * @var list<string>
     */
    private const array STRUCTURAL_ASSUMPTIONS = [
        'Gel de la valeur du point d\'indice sur l\'horizon (aucune revalorisation projetée).',
        'Taux de primes moyen par corps, avec repli (fallback) sur la dernière observation disponible.',
        'Population stationnaire (effectifs constants) pour les horizons de régime permanent.',
    ];

    public function __construct(
        private readonly ScenarioLineRepository $lines,
        private readonly AgentSituationFactory $situations,
        private readonly CostViewCalculator $viewCalculator,
        private readonly CostViewRuleResolver $viewResolver,
        private readonly Year1HorizonCalculator $year1,
        private readonly CareerFactory $careerFactory,
        private readonly PermanentRegimeCalculator $permanent,
        private readonly CohortHorizonCalculator $cohort,
        private readonly ParameterSetScope $scope,
        private readonly SourceCapture $sourceCapture,
    ) {
    }

    /**
     * @param list<string> $horizons
     * @param list<string> $views
     *
     * @return array<string, mixed>
     *
     * @throws InvalidSelectionException  si une vue ou un horizon demandé est inconnu
     * @throws UncomputableViewException si une vue valide n'est pas valorisable (paramètre daté manquant)
     */
    public function compute(Scenario $scenario, array $horizons, array $views, bool $detail = false): array
    {
        $this->assertHorizons($horizons);
        $this->assertViews($views);

        // En mode transparence, on repart d'une capture propre : les adapters datés y
        // réenregistrent la correspondance (clé, date d'effet) → source pendant le calcul.
        if ($detail) {
            $this->sourceCapture->reset();
        }

        $set = $scenario->parameterSet;
        $lines = $this->lines->forScenario($scenario);

        $wantYear1 = \in_array('year1', $horizons, true);
        $wantPermanent = \in_array('permanent', $horizons, true);
        $wantCohorte = \in_array('cohorte', $horizons, true);

        $lineResults = [];
        /** @var array<string, list<array{Year1Horizon, int}>> $weightedYear1 */
        $weightedYear1 = array_fill_keys($views, []);
        /** @var array<string, list<array{AnnualCost, int}>> $weightedPermanent */
        $weightedPermanent = array_fill_keys($views, []);
        /** @var array<string, list<array{list<AnnualCost>, int}>> $weightedCohorte */
        $weightedCohorte = array_fill_keys($views, []);

        foreach ($lines as $line) {
            $situation = $this->situations->fromProfile($line->profile);
            $calendar = $this->situations->calendarFor($line->profile);
            $recruitment = $line->recruitmentDate;
            // La carrière (permanent/cohorte) ne dépend pas de la vue : construite une
            // fois par ligne, elle est valorisée par vue à la date de recrutement.
            $career = ($wantPermanent || $wantCohorte) ? $this->careerFactory->fromProfile($line->profile) : null;

            $byView = [];
            foreach ($views as $view) {
                $block = [];

                if ($wantYear1) {
                    try {
                        /** @var array{Year1Horizon, AnnualCost} $computed */
                        $computed = $this->scope->within($set, function () use ($situation, $recruitment, $calendar, $view): array {
                            $viewCost = $this->viewCalculator->compute($situation, $recruitment, $view);

                            return [$this->year1->computeFrom($viewCost, $recruitment, $calendar), $viewCost];
                        });
                    } catch (MissingDatedRateException|MissingDatedAmountException|MissingPrimeRateException $exception) {
                        throw UncomputableViewException::forView($view, $exception);
                    }
                    [$horizon, $viewCost] = $computed;
                    $trace = $detail ? $viewCost->trace() : null;
                    $block['year1'] = $this->windowArray($horizon, $trace);
                    $weightedYear1[$view][] = [$horizon, $line->volume];
                }

                if ($career !== null) {
                    // View-aware (#66, C1.2/C2.3) : chaque échelon est valorisé selon la vue
                    // (terme retraite suivant la vue, comme year1) via la même stratégie.
                    $stepCost = new ViewAwareStepCost($this->viewCalculator, $view);

                    if ($wantPermanent) {
                        try {
                            $permanent = $this->scope->within(
                                $set,
                                fn (): AnnualCost => $this->permanent->compute($career, $recruitment, 1, $stepCost)->averagePerAgent(),
                            );
                        } catch (MissingDatedRateException|MissingDatedAmountException|MissingPrimeRateException $exception) {
                            throw UncomputableViewException::forView($view, $exception);
                        }
                        $block['permanent'] = $this->amountArray($permanent);
                        $weightedPermanent[$view][] = [$permanent, $line->volume];
                    }

                    if ($wantCohorte) {
                        try {
                            $series = $this->scope->within(
                                $set,
                                fn (): CohortCostSeries => $this->cohort->computeFullCareer($career, $recruitment, 1, $stepCost),
                            );
                        } catch (MissingDatedRateException|MissingDatedAmountException|MissingPrimeRateException $exception) {
                            throw UncomputableViewException::forView($view, $exception);
                        }
                        $block['cohorte'] = $this->cohortArray($series);
                        $weightedCohorte[$view][] = [$series->perYear(), $line->volume];
                    }
                }

                $byView[$view] = $block;
            }

            $lineResults[] = [
                'lineId' => $line->id,
                'profileRef' => $line->profile->id,
                'volume' => $line->volume,
                'results' => $byView,
            ];
        }

        $aggregate = [];
        foreach ($views as $view) {
            $viewAggregate = [];
            if ($wantYear1) {
                $viewAggregate['year1'] = $this->aggregateWindowArray($weightedYear1[$view]);
            }
            if ($wantPermanent) {
                $viewAggregate['permanent'] = $this->aggregatePermanent($weightedPermanent[$view]);
            }
            if ($wantCohorte) {
                $viewAggregate['cohorte'] = $this->aggregateCohort($weightedCohorte[$view]);
            }
            $aggregate[$view] = $viewAggregate;
        }

        $result = [
            'scenarioId' => $scenario->id,
            'parametersVersion' => $set->versionId,
            'horizons' => $horizons,
            'views' => $views,
            'lines' => $lineResults,
            'aggregate' => $aggregate,
        ];

        if ($detail) {
            $result['meta'] = $this->metaArray($views);
        }

        return $result;
    }

    /**
     * Hypothèses appliquées (#20, C4/C6), indépendamment du mode détaillé : hypothèses
     * structurelles nommées + hypothèses propres à chaque vue (définition, question,
     * hypothèses, sources de méthode). Permet à la fiche web d'afficher ces hypothèses
     * sur les horizons allégés (permanent/cohorte) sans activer le dépliage par nombre.
     *
     * @param list<string> $views
     *
     * @return array<string, mixed>
     *
     * @throws InvalidSelectionException si une vue demandée est inconnue
     */
    public function assumptions(array $views): array
    {
        $this->assertViews($views);

        return $this->metaArray($views);
    }

    /**
     * Métadonnées de transparence (C3) : par vue, sa définition/question/hypothèses/sources
     * de méthode (texte libre, distinctes des sources de valeurs) ; plus les hypothèses
     * structurelles nommées appliquées par le moteur.
     *
     * @param list<string> $views
     *
     * @return array<string, mixed>
     */
    private function metaArray(array $views): array
    {
        $viewsMeta = [];
        foreach ($views as $view) {
            $rule = $this->viewResolver->resolve($view);
            $viewsMeta[$view] = [
                'definition' => $rule->definition(),
                'question' => $rule->question(),
                'assumptions' => $rule->assumptions(),
                'sources' => $rule->sources(),
            ];
        }

        return [
            'views' => $viewsMeta,
            'structuralAssumptions' => self::STRUCTURAL_ASSUMPTIONS,
        ];
    }

    /**
     * @param list<string> $horizons
     */
    private function assertHorizons(array $horizons): void
    {
        foreach ($horizons as $horizon) {
            if (!\in_array($horizon, self::SUPPORTED_HORIZONS, true)) {
                throw InvalidSelectionException::unknownHorizon($horizon);
            }
        }
    }

    /**
     * @param list<string> $views
     */
    private function assertViews(array $views): void
    {
        foreach ($views as $view) {
            try {
                $this->viewResolver->resolve($view);
            } catch (UnknownRuleCodeException) {
                throw InvalidSelectionException::unknownView($view);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function windowArray(Year1Horizon $horizon, ?CostTrace $trace = null): array
    {
        $schoolYear = $horizon->schoolYear();
        $schoolYearWindow = null;
        if ($schoolYear !== null) {
            $schoolYearProrata = $horizon->schoolYearProrata()
                ?? throw new \LogicException('Fenêtre scolaire présente sans prorata.');
            $schoolYearWindow = $this->amountArray(
                $schoolYear,
                $this->detailFor($schoolYear, $trace, 'schoolYear', $schoolYearProrata),
            );
        }

        return [
            'fullYear' => $this->amountArray(
                $horizon->fullYear(),
                $this->detailFor($horizon->fullYear(), $trace, 'fullYear', $horizon->fullYearProrata()),
            ),
            'year1' => $this->amountArray(
                $horizon->year1(),
                $this->detailFor($horizon->year1(), $trace, 'year1', $horizon->year1Prorata()),
            ),
            'schoolYear' => $schoolYearWindow,
        ];
    }

    /**
     * @param list<array{Year1Horizon, int}> $weighted
     *
     * @return array<string, mixed>
     */
    private function aggregateWindowArray(array $weighted): array
    {
        /** @var list<array{AnnualCost, int}> $fullYear */
        $fullYear = [];
        /** @var list<array{AnnualCost, int}> $year1 */
        $year1 = [];
        /** @var list<array{AnnualCost, int}> $schoolYear */
        $schoolYear = [];

        foreach ($weighted as [$horizon, $volume]) {
            $fullYear[] = [$horizon->fullYear(), $volume];
            $year1[] = [$horizon->year1(), $volume];
            $school = $horizon->schoolYear();
            if ($school !== null) {
                $schoolYear[] = [$school, $volume];
            }
        }

        return [
            'fullYear' => $fullYear === [] ? null : $this->amountArray($this->weightedSum($fullYear)),
            'year1' => $year1 === [] ? null : $this->amountArray($this->weightedSum($year1)),
            'schoolYear' => $schoolYear === [] ? null : $this->amountArray($this->weightedSum($schoolYear)),
        ];
    }

    /**
     * Agrégat du régime permanent sur les lignes d'une vue : Σ(coût par agent × volume),
     * terme par terme (même pondération par volume que l'agrégat année 1). `null` si la
     * vue ne porte aucune ligne.
     *
     * @param list<array{AnnualCost, int}> $weighted
     *
     * @return array<string, mixed>|null
     */
    private function aggregatePermanent(array $weighted): ?array
    {
        return $weighted === [] ? null : $this->amountArray($this->weightedSum($weighted));
    }

    /**
     * Sérialisation de la série cohorte par agent (#66, C2) : la série année par année
     * (`perYear`, chaque année = 4 termes + total) et son cumul (`total` = Σ des années,
     * terme par terme, exposé par le VO `CohortCostSeries`).
     *
     * @return array<string, mixed>
     */
    private function cohortArray(CohortCostSeries $series): array
    {
        return [
            'perYear' => array_map(fn (AnnualCost $year): array => $this->amountArray($year), $series->perYear()),
            'total' => $this->amountArray($series->totalPerAgent()),
        ];
    }

    /**
     * Agrégat cohorte sur les lignes d'une vue (#66, C2.2), ALIGNÉ PAR INDEX D'ANNÉE :
     * la série a pour longueur la carrière la plus longue ; chaque année k = Σ sur les
     * lignes de `perYear[k] × volume`, les lignes plus courtes ne contribuant pas
     * au-delà de leur propre carrière (= 0). `total` = Σ des années agrégées, terme par
     * terme. `null` si la vue ne porte aucune ligne.
     *
     * @param list<array{list<AnnualCost>, int}> $weighted
     *
     * @return array<string, mixed>|null
     */
    private function aggregateCohort(array $weighted): ?array
    {
        if ($weighted === []) {
            return null;
        }

        $maxYears = 0;
        foreach ($weighted as [$perYear, $volume]) {
            $maxYears = max($maxYears, \count($perYear));
        }

        $perYearAggregate = [];
        /** @var list<array{AnnualCost, int}> $flat */
        $flat = [];
        for ($year = 0; $year < $maxYears; ++$year) {
            /** @var list<array{AnnualCost, int}> $yearWeighted */
            $yearWeighted = [];
            foreach ($weighted as [$perYear, $volume]) {
                if (isset($perYear[$year])) {
                    $yearWeighted[] = [$perYear[$year], $volume];
                    $flat[] = [$perYear[$year], $volume];
                }
            }
            // La ligne la plus longue atteint chaque année < $maxYears ⇒ $yearWeighted non vide.
            \assert($yearWeighted !== []);
            $perYearAggregate[] = $this->amountArray($this->weightedSum($yearWeighted));
        }

        // Invariant : $weighted non vide et toute série a ≥ 1 année ⇒ $flat non vide.
        \assert($flat !== []);

        return [
            'perYear' => $perYearAggregate,
            'total' => $this->amountArray($this->weightedSum($flat)),
        ];
    }

    /**
     * @param non-empty-list<array{AnnualCost, int}> $weighted
     */
    private function weightedSum(array $weighted): AnnualCost
    {
        $treatment = null;
        $retirement = null;
        $other = null;
        $rafp = null;

        foreach ($weighted as [$cost, $volume]) {
            $treatment = $this->accumulate($treatment, $cost->treatmentAndPrimes()->multipliedByIndex($volume));
            $retirement = $this->accumulate($retirement, $cost->retirement()->multipliedByIndex($volume));
            $other = $this->accumulate($other, $cost->otherContributions()->multipliedByIndex($volume));
            $rafp = $this->accumulate($rafp, $cost->rafp()->multipliedByIndex($volume));
        }

        return new AnnualCost($treatment, $retirement, $other, $rafp);
    }

    private function accumulate(?Amount $accumulator, Amount $value): Amount
    {
        return $accumulator === null ? $value : $accumulator->plus($value);
    }

    /**
     * Valeurs des 4 termes + total (format historique, inchangé sans `$detail`). Avec
     * un dépliage (mode `detail=full`), ajoute une clé `detail` parallèle qui déplie
     * chacun des 4 termes : valeur, formule, entrées datées, prorata et sources en clair.
     *
     * @param array<string, mixed>|null $detail
     *
     * @return array<string, mixed>
     */
    private function amountArray(AnnualCost $cost, ?array $detail = null): array
    {
        $array = [
            'treatmentAndPrimes' => $cost->treatmentAndPrimes()->toString(),
            'retirement' => $cost->retirement()->toString(),
            'otherContributions' => $cost->otherContributions()->toString(),
            'rafp' => $cost->rafp()->toString(),
            'total' => $cost->total()->toString(),
        ];

        if ($detail !== null) {
            $array['detail'] = $detail;
        }

        return $array;
    }

    /**
     * Dépliage des 4 termes d'une fenêtre (C1.1/C1.3), ou `null` hors mode détaillé.
     * La formule et les entrées proviennent de la trace annuelle ; le `$prorata` de la
     * fenêtre rend chaque `value` réconciliable (value = valeur_annuelle × months/over).
     *
     * @return array<string, mixed>|null
     */
    private function detailFor(AnnualCost $cost, ?CostTrace $trace, string $window, Prorata $prorata): ?array
    {
        if ($trace === null) {
            return null;
        }

        return [
            'treatmentAndPrimes' => $this->termDetail($cost->treatmentAndPrimes(), $trace->treatmentAndPrimes(), $window, $prorata),
            'retirement' => $this->termDetail($cost->retirement(), $trace->retirement(), $window, $prorata),
            'otherContributions' => $this->termDetail($cost->otherContributions(), $trace->otherContributions(), $window, $prorata),
            'rafp' => $this->termDetail($cost->rafp(), $trace->rafp(), $window, $prorata),
        ];
    }

    /**
     * Dépliage d'un terme (C1.1/C2.1/C1.3) : sa valeur (celle de la fenêtre, éventuellement
     * proratisée), sa formule lisible, les entrées datées utilisées, le facteur de prorata
     * de la fenêtre (months/over + fenêtre) qui rend la valeur réconciliable, et les sources
     * en clair complètes (éditeur, titre, édition, locator, url, date de récupération).
     *
     * @return array<string, mixed>
     */
    private function termDetail(Amount $value, TermTrace $term, string $window, Prorata $prorata): array
    {
        return [
            'value' => $value->toString(),
            'formula' => $term->formula(),
            'inputs' => array_map(
                static fn (CostInput $input): array => [
                    'key' => $input->key(),
                    'value' => $input->value(),
                    'unit' => $input->unit(),
                    'effectiveDate' => $input->effectiveDate()->format('Y-m-d'),
                ],
                $term->inputs(),
            ),
            'prorata' => [
                'months' => $prorata->months(),
                'over' => $prorata->over(),
                'window' => $window,
            ],
            'sources' => $this->sourcesFor($term->inputs()),
        ];
    }

    /**
     * Sources en clair des entrées d'un terme, résolues via la capture infra
     * (clé + date d'effet → `Source` enrichie J1), dédupliquées par code.
     *
     * @param list<CostInput> $inputs
     *
     * @return list<array<string, string>>
     */
    private function sourcesFor(array $inputs): array
    {
        $sources = [];
        foreach ($inputs as $input) {
            $source = $this->sourceCapture->sourceFor($input->key(), $input->effectiveDate());
            if ($source === null) {
                continue;
            }
            $sources[$source->code] = $this->sourceArray($source);
        }

        return array_values($sources);
    }

    /**
     * @return array<string, string>
     */
    private function sourceArray(Source $source): array
    {
        return [
            'code' => $source->code,
            'publisher' => $source->publisher,
            'label' => $source->label,
            'edition' => $source->edition,
            'locator' => $source->locator,
            'url' => $source->url,
            'retrievedAt' => $source->retrievedAt->format('Y-m-d'),
        ];
    }
}

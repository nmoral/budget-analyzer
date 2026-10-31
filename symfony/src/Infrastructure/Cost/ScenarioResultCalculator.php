<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\CostTrace;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\TermTrace;
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
 * demandée, calcule l'horizon année 1 (fullYear / year1 proraté / schoolYear) valorisé
 * selon la vue, puis l'agrégat Σ(résultat_ligne × volume).
 *
 * Le calcul est fait CONTRE le jeu de paramètres épinglé du scénario : la portée
 * `ParameterSetScope` lie, le temps du calcul, les adapters datés à cette version
 * (rejouabilité). Le prorata année 1 est délégué au domaine (`computeFrom`).
 */
final class ScenarioResultCalculator
{
    /** @var list<string> */
    private const array SUPPORTED_HORIZONS = ['year1'];

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

        $lineResults = [];
        /** @var array<string, list<array{Year1Horizon, int}>> $weightedByView */
        $weightedByView = array_fill_keys($views, []);

        foreach ($lines as $line) {
            $situation = $this->situations->fromProfile($line->profile);
            $calendar = $this->situations->calendarFor($line->profile);
            $recruitment = $line->recruitmentDate;

            $byView = [];
            foreach ($views as $view) {
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
                $byView[$view] = ['year1' => $this->windowArray($horizon, $trace)];
                $weightedByView[$view][] = [$horizon, $line->volume];
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
            $aggregate[$view] = ['year1' => $this->aggregateWindowArray($weightedByView[$view])];
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

        return [
            'fullYear' => $this->amountArray($horizon->fullYear(), $trace),
            'year1' => $this->amountArray($horizon->year1(), $trace),
            'schoolYear' => $schoolYear === null ? null : $this->amountArray($schoolYear, $trace),
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
     * Valeurs des 4 termes + total (format historique, inchangé sans `$trace`). Avec
     * une trace (mode `detail=full`), ajoute une clé `detail` parallèle qui déplie
     * chacun des 4 termes : valeur, formule, entrées datées et sources en clair.
     *
     * @return array<string, mixed>
     */
    private function amountArray(AnnualCost $cost, ?CostTrace $trace = null): array
    {
        $array = [
            'treatmentAndPrimes' => $cost->treatmentAndPrimes()->toString(),
            'retirement' => $cost->retirement()->toString(),
            'otherContributions' => $cost->otherContributions()->toString(),
            'rafp' => $cost->rafp()->toString(),
            'total' => $cost->total()->toString(),
        ];

        if ($trace !== null) {
            $array['detail'] = [
                'treatmentAndPrimes' => $this->termDetail($cost->treatmentAndPrimes(), $trace->treatmentAndPrimes()),
                'retirement' => $this->termDetail($cost->retirement(), $trace->retirement()),
                'otherContributions' => $this->termDetail($cost->otherContributions(), $trace->otherContributions()),
                'rafp' => $this->termDetail($cost->rafp(), $trace->rafp()),
            ];
        }

        return $array;
    }

    /**
     * Dépliage d'un terme (C1.1/C2.1) : sa valeur, sa formule lisible, les entrées
     * datées utilisées et leurs sources en clair complètes (éditeur, titre, édition,
     * locator, url, date de récupération), résolues depuis la capture infra.
     *
     * @return array<string, mixed>
     */
    private function termDetail(Amount $value, TermTrace $term): array
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

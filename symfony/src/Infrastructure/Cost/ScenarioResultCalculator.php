<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\CostViewCalculator;
use App\Domain\Cost\Year1Horizon;
use App\Domain\Cost\Year1HorizonCalculator;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use App\Domain\Shared\Amount;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\MissingPrimeRateException;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\ParameterSet\ParameterSetScope;
use App\Infrastructure\Repository\ScenarioLineRepository;

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

    public function __construct(
        private readonly ScenarioLineRepository $lines,
        private readonly AgentSituationFactory $situations,
        private readonly CostViewCalculator $viewCalculator,
        private readonly CostViewRuleResolver $viewResolver,
        private readonly Year1HorizonCalculator $year1,
        private readonly ParameterSetScope $scope,
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
    public function compute(Scenario $scenario, array $horizons, array $views): array
    {
        $this->assertHorizons($horizons);
        $this->assertViews($views);

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
                    $horizon = $this->scope->within($set, fn (): Year1Horizon => $this->year1->computeFrom(
                        $this->viewCalculator->compute($situation, $recruitment, $view),
                        $recruitment,
                        $calendar,
                    ));
                } catch (MissingDatedRateException|MissingDatedAmountException|MissingPrimeRateException $exception) {
                    throw UncomputableViewException::forView($view, $exception);
                }
                $byView[$view] = ['year1' => $this->windowArray($horizon)];
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

        return [
            'scenarioId' => $scenario->id,
            'parametersVersion' => $set->versionId,
            'horizons' => $horizons,
            'views' => $views,
            'lines' => $lineResults,
            'aggregate' => $aggregate,
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
    private function windowArray(Year1Horizon $horizon): array
    {
        $schoolYear = $horizon->schoolYear();

        return [
            'fullYear' => $this->amountArray($horizon->fullYear()),
            'year1' => $this->amountArray($horizon->year1()),
            'schoolYear' => $schoolYear === null ? null : $this->amountArray($schoolYear),
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
     * @return array<string, string>
     */
    private function amountArray(AnnualCost $cost): array
    {
        return [
            'treatmentAndPrimes' => $cost->treatmentAndPrimes()->toString(),
            'retirement' => $cost->retirement()->toString(),
            'otherContributions' => $cost->otherContributions()->toString(),
            'rafp' => $cost->rafp()->toString(),
            'total' => $cost->total()->toString(),
        ];
    }
}

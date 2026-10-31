<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Cost\PermanentRegimeCalculator;
use App\Domain\Cost\PopulationAssumption;
use App\Domain\Cost\RafpContributionCalculator;
use App\Domain\Rule\OtherContributions\OtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Horizon « régime permanent » (population stationnaire) — C1/C2/C3, domaine pur.
 * Moyenne pondérée des coûts d'échelon par la durée, terme par terme, puis × N.
 * Taux FICTIFS de test (CAS civil 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %).
 */
final class PermanentRegimeCalculatorTest extends TestCase
{
    private const string DATE = '2026-01-01';

    public function testAverageIsTheDurationWeightedMeanTermByTerm(): void
    {
        // C1.1 : (IM 395, 12 mois) + (IM 634, 48 mois), date 2026-01-01.
        $date = new \DateTimeImmutable(self::DATE);
        $costA = $this->annualCostCalculator()->compute($this->situation(395), $date);
        $costB = $this->annualCostCalculator()->compute($this->situation(634), $date);

        $average = $this->compute($this->career([395 => 12, 634 => 48]), $date, 1)->averagePerAgent();

        self::assertCostEquals($this->weightedMean($costA, 12, $costB, 48, 60), $average);
    }

    public function testAverageTotalIsTheSumOfTheFourAverageTerms(): void
    {
        // C1.1 : total() = somme des 4 termes moyens (recomposé par AnnualCost).
        $average = $this->compute($this->career([395 => 12, 634 => 48]), new \DateTimeImmutable(self::DATE), 1)
            ->averagePerAgent();

        $sum = $average->treatmentAndPrimes()
            ->plus($average->retirement())
            ->plus($average->otherContributions())
            ->plus($average->rafp());

        self::assertTrue($average->total()->equals($sum));
    }

    public function testSingleStepCareerYieldsThatStepAnnualCost(): void
    {
        // C1.2 : carrière à un seul échelon (IM 395, durée quelconque) → moyenne triviale.
        $date = new \DateTimeImmutable(self::DATE);
        $reference = $this->annualCostCalculator()->compute($this->situation(395), $date);

        $average = $this->compute($this->career([395 => 24]), $date, 1)->averagePerAgent();

        self::assertCostEquals($reference, $average);
    }

    public function testTerminalStepWithProvidedDurationIsCounted(): void
    {
        // C1.3 : carrière incluant l'échelon terminal (IM 678, 60 mois fournis).
        $date = new \DateTimeImmutable(self::DATE);
        $costA = $this->annualCostCalculator()->compute($this->situation(395), $date);
        $terminal = $this->annualCostCalculator()->compute($this->situation(678), $date);

        $career = $this->career([395 => 12, 678 => 60]);
        $average = $this->compute($career, $date, 1)->averagePerAgent();

        self::assertSame(72, $career->totalMonths());
        self::assertCostEquals($this->weightedMean($costA, 12, $terminal, 60, 72), $average);
    }

    public function testForHeadcountMultipliesEachTermByN(): void
    {
        // C2.1 : N = 10 → chaque terme × 10 ; total() = somme des termes × 10.
        $cost = $this->compute($this->career([395 => 12, 634 => 48]), new \DateTimeImmutable(self::DATE), 10);
        $average = $cost->averagePerAgent();

        self::assertCostEquals($this->scaled($average, 10), $cost->forHeadcount());
    }

    public function testForHeadcountOfOneEqualsAveragePerAgent(): void
    {
        // C2.2 : N = 1 → forHeadcount() == averagePerAgent() terme à terme.
        $cost = $this->compute($this->career([395 => 12, 634 => 48]), new \DateTimeImmutable(self::DATE), 1);

        self::assertCostEquals($cost->averagePerAgent(), $cost->forHeadcount());
    }

    public function testHeadcountBelowOneThrows(): void
    {
        // C2.3 : N = 0 → erreur (N ≥ 1).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('au moins égal à 1');

        $this->compute($this->career([395 => 12]), new \DateTimeImmutable(self::DATE), 0);
    }

    public function testExposesTheStationaryAssumption(): void
    {
        // C3.1 : assumption() == PopulationAssumption::STATIONARY.
        $cost = $this->compute($this->career([395 => 12, 634 => 48]), new \DateTimeImmutable(self::DATE), 3);

        self::assertSame(PopulationAssumption::STATIONARY, $cost->assumption());
    }

    public function testComputationIsReproducible(): void
    {
        // Déterminisme : même carrière, même date → résultat égal terme à terme.
        $date = new \DateTimeImmutable(self::DATE);
        $career = $this->career([395 => 12, 634 => 48]);

        $first = $this->compute($career, $date, 5);
        $second = $this->compute($career, $date, 5);

        self::assertCostEquals($first->averagePerAgent(), $second->averagePerAgent());
        self::assertCostEquals($first->forHeadcount(), $second->forHeadcount());
    }

    /**
     * @param array<int, int> $indexToMonths indice majoré → durée de l'échelon (mois)
     */
    private function career(array $indexToMonths): Career
    {
        $steps = [];
        foreach ($indexToMonths as $increasedIndex => $months) {
            $steps[] = new CareerStep($this->situation($increasedIndex), $months);
        }

        return new Career(...$steps);
    }

    private function compute(Career $career, \DateTimeImmutable $date, int $headcount): \App\Domain\Cost\PermanentRegimeCost
    {
        return (new PermanentRegimeCalculator($this->annualCostCalculator()))->compute($career, $date, $headcount);
    }

    private function weightedMean(AnnualCost $a, int $wa, AnnualCost $b, int $wb, int $total): AnnualCost
    {
        return new AnnualCost(
            $a->treatmentAndPrimes()->multipliedByIndex($wa)->plus($b->treatmentAndPrimes()->multipliedByIndex($wb))->dividedBy($total),
            $a->retirement()->multipliedByIndex($wa)->plus($b->retirement()->multipliedByIndex($wb))->dividedBy($total),
            $a->otherContributions()->multipliedByIndex($wa)->plus($b->otherContributions()->multipliedByIndex($wb))->dividedBy($total),
            $a->rafp()->multipliedByIndex($wa)->plus($b->rafp()->multipliedByIndex($wb))->dividedBy($total),
        );
    }

    private function scaled(AnnualCost $cost, int $factor): AnnualCost
    {
        return new AnnualCost(
            $cost->treatmentAndPrimes()->multipliedByIndex($factor),
            $cost->retirement()->multipliedByIndex($factor),
            $cost->otherContributions()->multipliedByIndex($factor),
            $cost->rafp()->multipliedByIndex($factor),
        );
    }

    private static function assertCostEquals(AnnualCost $expected, AnnualCost $actual): void
    {
        self::assertTrue($expected->treatmentAndPrimes()->equals($actual->treatmentAndPrimes()), 'treatmentAndPrimes');
        self::assertTrue($expected->retirement()->equals($actual->retirement()), 'retirement');
        self::assertTrue($expected->otherContributions()->equals($actual->otherContributions()), 'otherContributions');
        self::assertTrue($expected->rafp()->equals($actual->rafp()), 'rafp');
        self::assertTrue($expected->total()->equals($actual->total()), 'total');
    }

    private function situation(int $increasedIndex): AgentSituation
    {
        return new AgentSituation('PE', 'FPE', $increasedIndex, 'CAS_CIVIL');
    }

    private function annualCostCalculator(): AnnualCostCalculator
    {
        return new AnnualCostCalculator(
            new IndexedSalaryCalculator($this->indexPointProvider()),
            $this->primeRateProvider('0.21'),
            new RetirementRuleResolver($this->container([
                'CAS_CIVIL' => $this->retirementRule('CAS_CIVIL', '82.28'),
            ])),
            new OtherContributionsRuleResolver($this->container([
                'FPE' => $this->otherRule('FPE', '10'),
            ])),
            new RafpContributionCalculator($this->ratePctProvider('taux_rafp', '5')),
        );
    }

    private function indexPointProvider(): DatedAmountProvider
    {
        return new class () implements DatedAmountProvider {
            public function amountAt(string $key, \DateTimeImmutable $date): Amount
            {
                if ($key !== 'point_indice') {
                    throw MissingDatedAmountException::forKeyAndDate($key, $date);
                }

                return Amount::fromString('59.0734');
            }
        };
    }

    private function primeRateProvider(string $ratio): PrimeRateProvider
    {
        return new class ($ratio) implements PrimeRateProvider {
            public function __construct(private readonly string $ratio)
            {
            }

            public function primeRateAt(string $bodyCode, \DateTimeImmutable $date): PrimeRateObservation
            {
                return new PrimeRateObservation(Rate::fromRatio($this->ratio), false);
            }
        };
    }

    private function retirementRule(string $code, string $percentage): RetirementRule
    {
        return new class ($code, $percentage) implements RetirementRule {
            public function __construct(
                private readonly string $codeValue,
                private readonly string $percentage,
            ) {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function employerRate(\DateTimeImmutable $date): Rate
            {
                return Rate::fromPercentage($this->percentage);
            }
        };
    }

    private function otherRule(string $code, string $percentage): OtherContributionsRule
    {
        return new class ($code, $percentage) implements OtherContributionsRule {
            public function __construct(
                private readonly string $codeValue,
                private readonly string $percentage,
            ) {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function rate(\DateTimeImmutable $date): Rate
            {
                return Rate::fromPercentage($this->percentage);
            }
        };
    }

    private function ratePctProvider(string $expectedKey, string $percentage): DatedRateProvider
    {
        return new class ($expectedKey, $percentage) implements DatedRateProvider {
            public function __construct(
                private readonly string $expectedKey,
                private readonly string $percentage,
            ) {
            }

            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                if ($key !== $this->expectedKey) {
                    throw new \LogicException(sprintf('Unexpected key "%s".', $key));
                }

                return Rate::fromPercentage($this->percentage);
            }
        };
    }

    /**
     * @param array<string, object> $services
     */
    private function container(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /**
             * @param array<string, object> $services
             */
            public function __construct(private readonly array $services)
            {
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }
        };
    }
}

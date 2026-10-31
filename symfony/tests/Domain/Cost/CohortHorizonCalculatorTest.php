<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use App\Domain\Cost\CohortCostSeries;
use App\Domain\Cost\CohortHorizonCalculator;
use App\Domain\Cost\IndexedSalaryCalculator;
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
 * Horizon « cohorte » (année par année, avancement) — C1/C2/C5, domaine pur.
 * Série annuelle du coût d'un agent : avancement mensuel d'échelon, prorata intra-année
 * terme par terme, point gelé à la date d'entrée, terminal constant.
 * Taux FICTIFS de test (CAS civil 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %).
 */
final class CohortHorizonCalculatorTest extends TestCase
{
    private const string ENTRY = '2026-01-01';

    public function testFullYearOnASingleStepYieldsThatStepAnnualCost(): void
    {
        // C1.1 : carrière K, année 1 (mois 0→11, tous step0 IM 395) = AnnualCost(IM 395).
        // Horizon 1 : vérifie aussi la borne basse de la garde (horizon ≥ 1 accepté).
        $entry = new \DateTimeImmutable(self::ENTRY);
        $expected = $this->annualCostCalculator()->compute($this->situation(395), $entry);

        $series = $this->compute($this->careerK(), $entry, 1);

        self::assertCount(1, $series->perYear());
        self::assertCostEquals($expected, $series->perYear()[0]);
    }

    public function testCrossStepYearIsMonthlyProrataTermByTerm(): void
    {
        // C1.2 : carrière K, année 2 (mois 12→17 step0 IM 395, mois 18→23 step1 IM 430)
        // = (6×terme_IM395 + 6×terme_IM430) / 12, terme par terme.
        $entry = new \DateTimeImmutable(self::ENTRY);
        $costA = $this->annualCostCalculator()->compute($this->situation(395), $entry);
        $costB = $this->annualCostCalculator()->compute($this->situation(430), $entry);

        $series = $this->compute($this->careerK(), $entry, 2);

        self::assertCostEquals($this->proratedYear($costA, 6, $costB, 6), $series->perYear()[1]);
    }

    public function testAdvancementAtExactMonthBoundary(): void
    {
        // C1.3 : step0 IM 395 (12 mois) + terminal IM 678, horizon 2.
        // Année 1 = IM 395 ; année 2 = IM 678 (avancement au mois pile 12).
        $entry = new \DateTimeImmutable(self::ENTRY);
        $cost395 = $this->annualCostCalculator()->compute($this->situation(395), $entry);
        $cost678 = $this->annualCostCalculator()->compute($this->situation(678), $entry);

        $series = $this->compute($this->careerStepThenTerminal(), $entry, 2);

        self::assertCostEquals($cost395, $series->perYear()[0]);
        self::assertCostEquals($cost678, $series->perYear()[1]);
    }

    public function testFullYearOnAnIntermediateStepYieldsThatStepAnnualCost(): void
    {
        // C1.1/C2 : carrière K, année d'indice 3 (mois 36→47) entièrement sur step1
        // (IM 430, actif [18, 54)) → AnnualCost(IM 430). Vérifie l'accumulation des
        // durées des échelons non terminaux (borne haute de step1 = 54, pas 36).
        $entry = new \DateTimeImmutable(self::ENTRY);
        $cost430 = $this->annualCostCalculator()->compute($this->situation(430), $entry);

        $series = $this->compute($this->careerK(), $entry, 4);

        self::assertCostEquals($cost430, $series->perYear()[3]);
    }

    public function testSeriesHasExactlyHorizonEntries(): void
    {
        // C2.1 : carrière K, horizon 10 → perYear() a 10 entrées.
        $series = $this->compute($this->careerK(), new \DateTimeImmutable(self::ENTRY), 10);

        self::assertCount(10, $series->perYear());
    }

    public function testTerminalStepIsConstantOnceReached(): void
    {
        // C2.2 : step0 IM 395 (12 mois) + terminal IM 678, horizon 5.
        // Années 2→5 toutes égales à AnnualCost(IM 678).
        $entry = new \DateTimeImmutable(self::ENTRY);
        $cost678 = $this->annualCostCalculator()->compute($this->situation(678), $entry);

        $series = $this->compute($this->careerStepThenTerminal(), $entry, 5);

        self::assertCount(5, $series->perYear());
        foreach ([1, 2, 3, 4] as $year) {
            self::assertCostEquals($cost678, $series->perYear()[$year]);
        }
    }

    public function testIndexPointIsFrozenAcrossYears(): void
    {
        // C5.1 : step0 IM 395 (12 mois) + terminal IM 678, horizon 10.
        // Années 2→10 strictement identiques (aucune dérive du point d'indice).
        $series = $this->compute($this->careerStepThenTerminal(), new \DateTimeImmutable(self::ENTRY), 10);

        $reference = $series->perYear()[1];
        foreach (range(2, 9) as $year) {
            self::assertCostEquals($reference, $series->perYear()[$year]);
        }
    }

    public function testHorizonBelowOneThrows(): void
    {
        // Garde (part C6 câblée en J1) : horizon = 0 → erreur.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('au moins égal à 1');

        $this->compute($this->careerK(), new \DateTimeImmutable(self::ENTRY), 0);
    }

    public function testComputationIsReproducible(): void
    {
        // Déterminisme : même carrière, même date, même horizon → série égale terme à terme.
        $entry = new \DateTimeImmutable(self::ENTRY);

        $first = $this->compute($this->careerK(), $entry, 4);
        $second = $this->compute($this->careerK(), $entry, 4);

        self::assertCount(4, $first->perYear());
        foreach (range(0, 3) as $year) {
            self::assertCostEquals($first->perYear()[$year], $second->perYear()[$year]);
        }
    }

    private function careerK(): Career
    {
        // Carrière type K : step0 IM 395 (18 mois), step1 IM 430 (36 mois),
        // step2 terminal IM 678 (durée propre ignorée, l'agent y reste).
        return new Career(
            new CareerStep($this->situation(395), 18),
            new CareerStep($this->situation(430), 36),
            new CareerStep($this->situation(678), 12),
        );
    }

    private function careerStepThenTerminal(): Career
    {
        // step0 IM 395 (12 mois) + terminal IM 678 (durée propre ignorée).
        return new Career(
            new CareerStep($this->situation(395), 12),
            new CareerStep($this->situation(678), 12),
        );
    }

    private function compute(Career $career, \DateTimeImmutable $entry, int $horizonYears): CohortCostSeries
    {
        return (new CohortHorizonCalculator($this->annualCostCalculator()))->compute($career, $entry, $horizonYears);
    }

    private function proratedYear(AnnualCost $a, int $ma, AnnualCost $b, int $mb): AnnualCost
    {
        return new AnnualCost(
            $a->treatmentAndPrimes()->multipliedByIndex($ma)->plus($b->treatmentAndPrimes()->multipliedByIndex($mb))->dividedBy(12),
            $a->retirement()->multipliedByIndex($ma)->plus($b->retirement()->multipliedByIndex($mb))->dividedBy(12),
            $a->otherContributions()->multipliedByIndex($ma)->plus($b->otherContributions()->multipliedByIndex($mb))->dividedBy(12),
            $a->rafp()->multipliedByIndex($ma)->plus($b->rafp()->multipliedByIndex($mb))->dividedBy(12),
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

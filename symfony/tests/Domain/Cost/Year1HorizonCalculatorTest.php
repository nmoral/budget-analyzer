<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Cost\RafpContributionCalculator;
use App\Domain\Cost\ReferenceCalendar;
use App\Domain\Cost\Year1HorizonCalculator;
use App\Domain\Rule\OtherContributions\OtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Horizon « année 1 » (C1/C2/C3), domaine pur : année pleine en regard, année 1 au
 * prorata du recrutement (mois entiers, terme par terme), vue scolaire optionnelle.
 * Taux FICTIFS de test (CAS civil 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %).
 */
final class Year1HorizonCalculatorTest extends TestCase
{
    private const string RECRUITMENT = '2026-09-01';

    public function testFullYearIsTheNonProratedAnnualCost(): void
    {
        // C2.1 : fullYear() = AnnualCostCalculator::compute(situation, date), non proraté.
        $date = new \DateTimeImmutable('2026-05-10');
        $reference = $this->annualCostCalculator()->compute($this->situation(), $date);

        $horizon = $this->compute($date, ReferenceCalendar::CIVIL);

        self::assertCostEquals($reference, $horizon->fullYear());
    }

    public function testYear1ProratesEachTermByMonthsUntilDecember(): void
    {
        // C1.1 : recrut. 1er sept → moisPrésents = 4 ; chaque terme = fullYear × 4/12.
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL);

        self::assertCostEquals($this->prorated($horizon->fullYear(), 4), $horizon->year1());
    }

    public function testYear1TotalIsTheSumOfProratedTerms(): void
    {
        // C1.1 : year1().total() = somme des 4 termes proratés (recomposition).
        $year1 = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL)->year1();

        $sum = $year1->treatmentAndPrimes()
            ->plus($year1->retirement())
            ->plus($year1->otherContributions())
            ->plus($year1->rafp());

        self::assertTrue($year1->total()->equals($sum));
    }

    public function testYear1EqualsFullYearWhenRecruitedInJanuary(): void
    {
        // C1.2 : recrut. 1er janvier → moisPrésents = 12 → year1() = fullYear() terme à terme.
        $horizon = $this->compute(new \DateTimeImmutable('2026-01-01'), ReferenceCalendar::CIVIL);

        self::assertCostEquals($horizon->fullYear(), $horizon->year1());
    }

    public function testYear1ProratesToASingleMonthWhenRecruitedInDecember(): void
    {
        // C1.3 : recrut. 1er décembre → moisPrésents = 1 → year1() = fullYear() × 1/12.
        $horizon = $this->compute(new \DateTimeImmutable('2026-12-01'), ReferenceCalendar::CIVIL);

        self::assertCostEquals($this->prorated($horizon->fullYear(), 1), $horizon->year1());
    }

    public function testYear1CountsTheEntryMonthWholeRegardlessOfDay(): void
    {
        // C1.4 : recrut. 15 sept → moisPrésents = 4 (mois d'entrée entier), comme C1.1.
        $horizon = $this->compute(new \DateTimeImmutable('2026-09-15'), ReferenceCalendar::CIVIL);

        self::assertCostEquals($this->prorated($horizon->fullYear(), 4), $horizon->year1());
    }

    public function testSchoolYearIsNullForCivilCalendar(): void
    {
        // C3.1 : calendrier civil → schoolYear() = null.
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL);

        self::assertNull($horizon->schoolYear());
    }

    public function testSchoolYearEqualsFullYearWhenRecruitedInSeptember(): void
    {
        // C3.2 : scolaire, recrut. 1er sept → moisPrésents = 12 (sept→août) → = fullYear().
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::SCOLAIRE);

        $schoolYear = $horizon->schoolYear();
        self::assertNotNull($schoolYear);
        self::assertCostEquals($horizon->fullYear(), $schoolYear);
    }

    public function testSchoolYearProratesByMonthsUntilAugust(): void
    {
        // C3.3 : scolaire, recrut. 1er nov → moisPrésents = 10 (nov→août) → fullYear × 10/12.
        $horizon = $this->compute(new \DateTimeImmutable('2026-11-01'), ReferenceCalendar::SCOLAIRE);

        $schoolYear = $horizon->schoolYear();
        self::assertNotNull($schoolYear);
        self::assertCostEquals($this->prorated($horizon->fullYear(), 10), $schoolYear);
    }

    public function testComputeFromExposesTheProvidedFullYearUnchanged(): void
    {
        // J2 : computeFrom applique le prorata à un AnnualCost déjà valorisé (par une
        // vue) ; fullYear() est exactement l'AnnualCost fourni, non proraté.
        $full = $this->sampleCost();

        $horizon = (new Year1HorizonCalculator($this->annualCostCalculator()))
            ->computeFrom($full, new \DateTimeImmutable('2026-11-01'), ReferenceCalendar::CIVIL);

        self::assertCostEquals($full, $horizon->fullYear());
    }

    public function testComputeFromProratesYear1FromTheProvidedCost(): void
    {
        // J2 : recrut. 1er nov → year1 = coût fourni × 2/12 (nov, déc), calendrier civil
        // → schoolYear null.
        $full = $this->sampleCost();

        $horizon = (new Year1HorizonCalculator($this->annualCostCalculator()))
            ->computeFrom($full, new \DateTimeImmutable('2026-11-01'), ReferenceCalendar::CIVIL);

        self::assertCostEquals($this->prorated($full, 2), $horizon->year1());
        self::assertNull($horizon->schoolYear());
    }

    public function testComputeFromAddsSchoolYearFromTheProvidedCost(): void
    {
        // J2 : scolaire, recrut. 1er nov → schoolYear = coût fourni × 10/12 (nov→août).
        $full = $this->sampleCost();

        $horizon = (new Year1HorizonCalculator($this->annualCostCalculator()))
            ->computeFrom($full, new \DateTimeImmutable('2026-11-01'), ReferenceCalendar::SCOLAIRE);

        $schoolYear = $horizon->schoolYear();
        self::assertNotNull($schoolYear);
        self::assertCostEquals($this->prorated($full, 10), $schoolYear);
    }

    public function testFullYearExposesAProrataOfTwelveOverTwelve(): void
    {
        // C1.3 : l'année pleine n'est pas proratisée → prorata 12/12 (facteur 1).
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL);

        self::assertSame(12, $horizon->fullYearProrata()->months());
        self::assertSame(12, $horizon->fullYearProrata()->over());
    }

    public function testYear1ExposesItsProrataFactorUntilDecember(): void
    {
        // C1.3 : recrut. 1er sept → year1 proratisé 4/12 (sept→déc), facteur exposé.
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL);

        self::assertSame(4, $horizon->year1Prorata()->months());
        self::assertSame(12, $horizon->year1Prorata()->over());
    }

    public function testSchoolYearExposesItsProrataFactorUntilAugust(): void
    {
        // C1.3 : scolaire, recrut. 1er nov → schoolYear proratisé 10/12 (nov→août).
        $horizon = $this->compute(new \DateTimeImmutable('2026-11-01'), ReferenceCalendar::SCOLAIRE);

        $prorata = $horizon->schoolYearProrata();
        self::assertNotNull($prorata);
        self::assertSame(10, $prorata->months());
        self::assertSame(12, $prorata->over());
    }

    public function testSchoolYearProrataIsNullForCivilCalendar(): void
    {
        // C1.3 : calendrier civil → pas de fenêtre scolaire, donc pas de prorata scolaire.
        $horizon = $this->compute(new \DateTimeImmutable(self::RECRUITMENT), ReferenceCalendar::CIVIL);

        self::assertNull($horizon->schoolYearProrata());
    }

    private function sampleCost(): AnnualCost
    {
        return new AnnualCost(
            Amount::fromString('1200'),
            Amount::fromString('600'),
            Amount::fromString('300'),
            Amount::fromString('120'),
        );
    }

    private function compute(\DateTimeImmutable $recruitment, ReferenceCalendar $calendar): \App\Domain\Cost\Year1Horizon
    {
        return (new Year1HorizonCalculator($this->annualCostCalculator()))
            ->compute($this->situation(), $recruitment, $calendar);
    }

    private function prorated(AnnualCost $cost, int $months): AnnualCost
    {
        return new AnnualCost(
            $cost->treatmentAndPrimes()->proratedByMonths($months),
            $cost->retirement()->proratedByMonths($months),
            $cost->otherContributions()->proratedByMonths($months),
            $cost->rafp()->proratedByMonths($months),
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

    private function situation(): AgentSituation
    {
        return new AgentSituation('PE', 'FPE', 395, 'CAS_CIVIL');
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
            public function amountAt(string $key, \DateTimeImmutable $date): DatedAmount
            {
                if ($key !== 'point_indice') {
                    throw MissingDatedAmountException::forKeyAndDate($key, $date);
                }

                return Trace::datedAmount(Amount::fromString('59.0734'));
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
                return Trace::primeObservation(Rate::fromRatio($this->ratio), false);
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

            public function employerRate(\DateTimeImmutable $date): DatedRate
            {
                return Trace::datedRate(Rate::fromPercentage($this->percentage));
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

            public function rate(\DateTimeImmutable $date): DatedRate
            {
                return Trace::datedRate(Rate::fromPercentage($this->percentage));
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

            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                if ($key !== $this->expectedKey) {
                    throw new \LogicException(sprintf('Unexpected key "%s".', $key));
                }

                return Trace::datedRate(Rate::fromPercentage($this->percentage));
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

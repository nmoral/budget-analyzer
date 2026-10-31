<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\AnnualCost;
use App\Domain\Cost\AnnualCostCalculator;
use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Cost\RafpContributionCalculator;
use App\Domain\Rule\OtherContributions\OtherContributionsRule;
use App\Domain\Rule\OtherContributions\OtherContributionsRuleResolver;
use App\Domain\Rule\Retirement\RetirementRule;
use App\Domain\Rule\Retirement\RetirementRuleResolver;
use App\Domain\Rule\UnknownRuleCodeException;
use App\Domain\Shared\Amount;
use App\Domain\Shared\CostInput;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\PrimeRateObservation;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Assemblage de la formule de coût annuel (C5/C6/C7/C8.1), isolé via des ports et
 * des règles factices (aucun conteneur ni base). Taux FICTIFS de test : r_autres
 * FPE 10 % / FPH 12 %, RAFP 5 % — les données réelles n'existent pas (sourcing #49).
 *
 * C = TIB·(1+p) + TIB·r_retraite + TIB·(1+p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP
 */
final class AnnualCostCalculatorTest extends TestCase
{
    public function testPeNominalDecomposesTheFourTermsAndTotal(): void
    {
        // C5.1 : CAS civil 82.28 %, p 0.21, r_autres 10 %, RAFP 5 %, D = 2026-06-01.
        $cost = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('28234.131530', $cost->treatmentAndPrimes()->toString());
        self::assertSame('19199.209440', $cost->retirement()->toString());
        self::assertSame('2823.413153', $cost->otherContributions()->toString());
        self::assertSame('233.339930', $cost->rafp()->toString());
        self::assertSame('50490.094053', $cost->total()->toString());
    }

    public function testIsgNominalDecomposesTheFourTermsAndTotal(): void
    {
        // C5.2 : CNRACL 37.65 %, p 0.33, r_autres 12 %, RAFP 5 %, D = 2026-06-01.
        $cost = $this->compute(
            '0.33',
            '37.65',
            '12',
            '5',
            new \DateTimeImmutable('2026-06-01'),
            versant: 'FPH',
            retirementCode: 'CNRACL',
        );

        self::assertSame('31034.210690', $cost->treatmentAndPrimes()->toString());
        self::assertSame('8785.248365', $cost->retirement()->toString());
        self::assertSame('3724.105283', $cost->otherContributions()->toString());
        self::assertSame('233.339930', $cost->rafp()->toString());
        self::assertSame('43776.904268', $cost->total()->toString());
    }

    public function testRetirementTermFollowsTheDatedRate(): void
    {
        // C5.3 : PE, D = 2025-06-01, CAS civil 78.28 % à cette date.
        $cost = $this->compute('0.21', '78.28', '10', '5', new \DateTimeImmutable('2025-06-01'));

        self::assertSame('18265.849720', $cost->retirement()->toString());
        self::assertSame('49556.734333', $cost->total()->toString());
    }

    public function testComputationIsReproducibleTermByTerm(): void
    {
        // C5.4 : C5.1 calculé deux fois → deux AnnualCost égaux terme à terme.
        $date = new \DateTimeImmutable('2026-06-01');
        $first = $this->compute('0.21', '82.28', '10', '5', $date);
        $second = $this->compute('0.21', '82.28', '10', '5', $date);

        self::assertTrue($first->treatmentAndPrimes()->equals($second->treatmentAndPrimes()));
        self::assertTrue($first->retirement()->equals($second->retirement()));
        self::assertTrue($first->otherContributions()->equals($second->otherContributions()));
        self::assertTrue($first->rafp()->equals($second->rafp()));
        self::assertTrue($first->total()->equals($second->total()));
    }

    public function testRafpBelowCapUsesPrimeBase(): void
    {
        // C6.1 : p = 0.15 (sous le plafond) → assiette = TIB·p.
        $cost = $this->compute('0.15', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('175.004948', $cost->rafp()->toString());
        self::assertSame('48891.715533', $cost->total()->toString());
    }

    public function testRafpAtCapLimit(): void
    {
        // C6.2 : p = 0.20 → TIB·p = 0,2·TIB (limite).
        $cost = $this->compute('0.20', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('233.339930', $cost->rafp()->toString());
    }

    public function testRafpCapApplied(): void
    {
        // C6.3 : p = 0.21 → plafond 0,2·TIB appliqué (233.339930, et non 245.006927).
        $cost = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('233.339930', $cost->rafp()->toString());
        self::assertNotSame('245.006927', $cost->rafp()->toString());
    }

    public function testNonRetirementTermsAreIndependentOfTheRetirementRate(): void
    {
        // C7.1 : C5.1 (CAS civil 82.28 %, D 2026) vs C5.3 (78.28 %, D 2025) →
        // traitement+primes, autres, RAFP identiques ; seule la retraite diffère.
        $withHigherRate = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));
        $withLowerRate = $this->compute('0.21', '78.28', '10', '5', new \DateTimeImmutable('2025-06-01'));

        self::assertTrue($withHigherRate->treatmentAndPrimes()->equals($withLowerRate->treatmentAndPrimes()));
        self::assertTrue($withHigherRate->otherContributions()->equals($withLowerRate->otherContributions()));
        self::assertTrue($withHigherRate->rafp()->equals($withLowerRate->rafp()));
        self::assertFalse($withHigherRate->retirement()->equals($withLowerRate->retirement()));
    }

    public function testTraceCarriesRetirementFormulaAndDatedInput(): void
    {
        // Socle C1.1 : la trace de la retraite porte la formule « TIB·r_retraite »
        // et l'entrée datée taux_cas_civil (valeur, unité PCT_TIB, date d'effet).
        $cost = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        $trace = $cost->trace();
        self::assertNotNull($trace);

        $retirement = $trace->retirement();
        self::assertSame('TIB·r_retraite', $retirement->formula());
        self::assertCount(1, $retirement->inputs());

        $input = $retirement->inputs()[0];
        self::assertSame('taux_cas_civil', $input->key());
        self::assertSame('82.28', $input->value());
        self::assertSame('PCT_TIB', $input->unit());
        self::assertSame('2026-01-01', $input->effectiveDate()->format('Y-m-d'));
    }

    public function testTraceExposesTheFourTermsWithFormulasAndInputs(): void
    {
        // Socle C1 : chacun des 4 termes porte sa formule lisible et ses entrées.
        $cost = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        $trace = $cost->trace();
        self::assertNotNull($trace);

        self::assertSame('TIB·(1+p)', $trace->treatmentAndPrimes()->formula());
        self::assertSame(['point_indice', 'taux_primes_PE'], array_map(
            static fn ($i) => $i->key(),
            $trace->treatmentAndPrimes()->inputs(),
        ));

        self::assertSame('TIB·r_retraite', $trace->retirement()->formula());
        self::assertSame(['taux_cas_civil'], array_map(
            static fn ($i) => $i->key(),
            $trace->retirement()->inputs(),
        ));

        self::assertSame('TIB·(1+p)·r_autres', $trace->otherContributions()->formula());
        self::assertSame(['taux_autres_cotisations_fpe'], array_map(
            static fn ($i) => $i->key(),
            $trace->otherContributions()->inputs(),
        ));

        self::assertSame('min(TIB·p, 0,2·TIB)·r_RAFP', $trace->rafp()->formula());
        self::assertSame(['taux_rafp'], array_map(
            static fn ($i) => $i->key(),
            $trace->rafp()->inputs(),
        ));
    }

    public function testTotalEqualsSumOfTheFourTracedTerms(): void
    {
        // C4.2 : total = somme EXACTE des quatre termes dépliés (aucune dérive).
        $cost = $this->compute('0.21', '82.28', '10', '5', new \DateTimeImmutable('2026-06-01'));

        $sum = $cost->treatmentAndPrimes()
            ->plus($cost->retirement())
            ->plus($cost->otherContributions())
            ->plus($cost->rafp());

        self::assertTrue($cost->total()->equals($sum));
        self::assertSame($cost->total()->toString(), $sum->toString());
    }

    public function testUnknownRetirementRegimeThrows(): void
    {
        // C8.1 : code de régime retraite inconnu → UnknownRuleCodeException.
        $calculator = $this->calculator('0.21', '82.28', '10', '5', 'CAS_CIVIL', 'FPE');
        $situation = new AgentSituation('PE', 'FPE', 395, 'X_INCONNU');

        $this->expectException(UnknownRuleCodeException::class);

        $calculator->compute($situation, new \DateTimeImmutable('2026-06-01'));
    }

    private function compute(
        string $primeRatio,
        string $retirementPercentage,
        string $otherPercentage,
        string $rafpPercentage,
        \DateTimeImmutable $date,
        string $bodyCode = 'PE',
        string $versant = 'FPE',
        string $retirementCode = 'CAS_CIVIL',
    ): AnnualCost {
        $calculator = $this->calculator(
            $primeRatio,
            $retirementPercentage,
            $otherPercentage,
            $rafpPercentage,
            $retirementCode,
            $versant,
        );

        return $calculator->compute(new AgentSituation($bodyCode, $versant, 395, $retirementCode), $date);
    }

    private function calculator(
        string $primeRatio,
        string $retirementPercentage,
        string $otherPercentage,
        string $rafpPercentage,
        string $retirementCode,
        string $versant,
    ): AnnualCostCalculator {
        return new AnnualCostCalculator(
            new IndexedSalaryCalculator($this->indexPointProvider()),
            $this->primeRateProvider($primeRatio),
            new RetirementRuleResolver($this->container([
                $retirementCode => $this->retirementRule($retirementCode, $retirementPercentage),
            ])),
            new OtherContributionsRuleResolver($this->container([
                $versant => $this->otherRule($versant, $otherPercentage),
            ])),
            new RafpContributionCalculator($this->ratePctProvider('taux_rafp', $rafpPercentage)),
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

                return new DatedAmount(
                    Amount::fromString('59.0734'),
                    new CostInput('point_indice', '59.0734', 'EUR_POINT', new \DateTimeImmutable('2026-01-01')),
                );
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
                return new PrimeRateObservation(
                    Rate::fromRatio($this->ratio),
                    false,
                    new CostInput('taux_primes_' . $bodyCode, $this->ratio, 'RATIO', new \DateTimeImmutable('2026-01-01')),
                );
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
                $key = $this->codeValue === 'CNRACL' ? 'taux_cnracl' : 'taux_cas_civil';

                return new DatedRate(
                    Rate::fromPercentage($this->percentage),
                    new CostInput($key, $this->percentage, 'PCT_TIB', new \DateTimeImmutable('2026-01-01')),
                );
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
                return new DatedRate(
                    Rate::fromPercentage($this->percentage),
                    new CostInput('taux_autres_cotisations_' . strtolower($this->codeValue), $this->percentage, 'PCT_TIB', new \DateTimeImmutable('2026-01-01')),
                );
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

                return new DatedRate(
                    Rate::fromPercentage($this->percentage),
                    new CostInput($key, $this->percentage, 'PCT_TIB', new \DateTimeImmutable('2026-01-01')),
                );
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

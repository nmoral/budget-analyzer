<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\Retirement;

use App\Domain\Rule\Retirement\CasCivilRule;
use App\Domain\Rule\Retirement\CnraclRule;
use App\Domain\Shared\DatedRate;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;

/**
 * Règles de retraite réelles, isolées via un port `DatedRateProvider` factice
 * (aucun conteneur ni base) : chaque règle lit SA clé de paramètre en dur et
 * renvoie le `DatedRate` fourni, sans taux en dur (C4/C5).
 */
final class RetirementRuleUnitTest extends TestCase
{
    public function testCasCivilReadsItsOwnKey(): void
    {
        $rule = new CasCivilRule($this->providerFor('taux_cas_civil', Rate::fromPercentage('82.28')));

        self::assertSame('CAS_CIVIL', $rule->code());
        self::assertTrue(
            $rule->employerRate(new \DateTimeImmutable('2026-06-01'))->rate()->equals(Rate::fromPercentage('82.28')),
        );
    }

    public function testCnraclReadsItsOwnKey(): void
    {
        $rule = new CnraclRule($this->providerFor('taux_cnracl', Rate::fromPercentage('37.65')));

        self::assertSame('CNRACL', $rule->code());
        self::assertTrue(
            $rule->employerRate(new \DateTimeImmutable('2026-06-01'))->rate()->equals(Rate::fromPercentage('37.65')),
        );
    }

    public function testCasCivilPropagatesMissingRateException(): void
    {
        // C5.2 : le port lève pour la clé/date non couverte -> la règle propage.
        $provider = new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                throw MissingDatedRateException::forKeyAndDate($key, $date);
            }
        };

        $rule = new CasCivilRule($provider);

        try {
            $rule->employerRate(new \DateTimeImmutable('2024-06-01'));
            self::fail('Expected MissingDatedRateException to propagate.');
        } catch (MissingDatedRateException $exception) {
            self::assertSame('taux_cas_civil', $exception->key());
            self::assertStringContainsString('taux_cas_civil', $exception->getMessage());
            self::assertStringContainsString('2024-06-01', $exception->getMessage());
        }
    }

    private function providerFor(string $expectedKey, Rate $rate): DatedRateProvider
    {
        return new class ($expectedKey, $rate) implements DatedRateProvider {
            public function __construct(
                private readonly string $expectedKey,
                private readonly Rate $rate,
            ) {
            }

            public function rateAt(string $key, \DateTimeImmutable $date): DatedRate
            {
                if ($key !== $this->expectedKey) {
                    throw MissingDatedRateException::forKeyAndDate($key, $date);
                }

                return Trace::datedRate($this->rate);
            }
        };
    }
}

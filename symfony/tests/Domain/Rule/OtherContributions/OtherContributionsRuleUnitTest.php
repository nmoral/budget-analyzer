<?php

declare(strict_types=1);

namespace App\Tests\Domain\Rule\OtherContributions;

use App\Domain\Rule\OtherContributions\FpeOtherContributionsRule;
use App\Domain\Rule\OtherContributions\FphOtherContributionsRule;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Règles « autres cotisations » (r_autres) par versant, isolées via un port
 * `DatedRateProvider` factice (aucun conteneur ni base) : chaque règle lit SA
 * clé de paramètre en dur et renvoie le `Rate` fourni, sans taux en dur (C4).
 * Les données réelles n'existant pas (sourcing #49), un taux fictif est injecté.
 */
final class OtherContributionsRuleUnitTest extends TestCase
{
    public function testFpeReadsItsOwnKey(): void
    {
        // C4.1 : versant FPE, D = 2026-06-01, taux lu sous `taux_autres_cotisations_fpe`.
        $rule = new FpeOtherContributionsRule(
            $this->providerFor('taux_autres_cotisations_fpe', Rate::fromPercentage('10')),
        );

        self::assertSame('FPE', $rule->code());
        self::assertTrue(
            $rule->rate(new \DateTimeImmutable('2026-06-01'))->equals(Rate::fromPercentage('10')),
        );
    }

    public function testFphReadsItsOwnKey(): void
    {
        // C4.1 (pendant FPH) : taux lu sous `taux_autres_cotisations_fph`.
        $rule = new FphOtherContributionsRule(
            $this->providerFor('taux_autres_cotisations_fph', Rate::fromPercentage('12')),
        );

        self::assertSame('FPH', $rule->code());
        self::assertTrue(
            $rule->rate(new \DateTimeImmutable('2026-06-01'))->equals(Rate::fromPercentage('12')),
        );
    }

    public function testFpePropagatesMissingRateException(): void
    {
        // C8 : donnée absente à D -> la règle propage l'exception (pas de défaut silencieux).
        $provider = new class () implements DatedRateProvider {
            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                throw MissingDatedRateException::forKeyAndDate($key, $date);
            }
        };

        $rule = new FpeOtherContributionsRule($provider);

        try {
            $rule->rate(new \DateTimeImmutable('2026-06-01'));
            self::fail('Expected MissingDatedRateException to propagate.');
        } catch (MissingDatedRateException $exception) {
            self::assertSame('taux_autres_cotisations_fpe', $exception->key());
            self::assertStringContainsString('taux_autres_cotisations_fpe', $exception->getMessage());
            self::assertStringContainsString('2026-06-01', $exception->getMessage());
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

            public function rateAt(string $key, \DateTimeImmutable $date): Rate
            {
                if ($key !== $this->expectedKey) {
                    throw MissingDatedRateException::forKeyAndDate($key, $date);
                }

                return $this->rate;
            }
        };
    }
}

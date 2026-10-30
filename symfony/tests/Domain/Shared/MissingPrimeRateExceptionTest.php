<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\MissingPrimeRateException;
use PHPUnit\Framework\TestCase;

/**
 * C8 / C3.3 — aucune observation de taux de primes pour le corps à D :
 * l'exception nomme le corps ET la date (pas de valeur par défaut silencieuse).
 */
final class MissingPrimeRateExceptionTest extends TestCase
{
    public function testMessageNamesBodyAndDate(): void
    {
        $exception = MissingPrimeRateException::forBodyAndDate('PE', new \DateTimeImmutable('2022-06-01'));

        self::assertSame(
            'Aucun taux de primes du corps "PE" observé au plus tard l\'année de la date 2022-06-01.',
            $exception->getMessage(),
        );
    }

    public function testExposesBodyAndDate(): void
    {
        $date = new \DateTimeImmutable('2022-06-01');

        $exception = MissingPrimeRateException::forBodyAndDate('PE', $date);

        self::assertSame('PE', $exception->bodyCode());
        self::assertSame($date, $exception->date());
    }
}

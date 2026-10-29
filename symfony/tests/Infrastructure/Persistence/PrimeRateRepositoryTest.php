<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Repository\PrimeRateRepository;

/**
 * C4 — taux de primes « pour l'année Y » (Postgres réel).
 */
final class PrimeRateRepositoryTest extends IntegrationTestCase
{
    public function testLaterYearPicksLastObservation(): void
    {
        // C4.1 : année postérieure à la seule observation (2023).
        $set = $this->currentSet();
        $corps = $this->newCorps('PE');
        $source = $this->newSource('dgafp-ra-2025');
        $this->newPrimeRate($corps, '0.21', 2023, false, $source, $set);
        $this->em->flush();

        $rate = $this->repository()->forYear('PE', 2026);

        self::assertNotNull($rate);
        self::assertSame(0.21, (float) $rate->value);
        self::assertFalse($rate->fallback);
    }

    public function testFallbackFlagReturned(): void
    {
        // C4.2 : la ligne retenue porte fallback=true.
        $set = $this->currentSet();
        $corps = $this->newCorps('ISG');
        $source = $this->newSource('insee-ip-2067');
        $this->newPrimeRate($corps, '0.33', 2023, true, $source, $set);
        $this->em->flush();

        $rate = $this->repository()->forYear('ISG', 2023);

        self::assertNotNull($rate);
        self::assertSame(0.33, (float) $rate->value);
        self::assertTrue($rate->fallback);
    }

    public function testYearBeforeAnyObservationReturnsNull(): void
    {
        // C4.3 : année antérieure à toute observation.
        $set = $this->currentSet();
        $corps = $this->newCorps('PE');
        $source = $this->newSource('dgafp-ra-2025');
        $this->newPrimeRate($corps, '0.21', 2023, false, $source, $set);
        $this->em->flush();

        $rate = $this->repository()->forYear('PE', 2022);

        self::assertNull($rate);
    }

    public function testNonFallbackWinsOnSameYear(): void
    {
        // C4 (départage) : à année égale, la non-fallback l'emporte.
        $set = $this->currentSet();
        $corps = $this->newCorps('PE');
        $source = $this->newSource('dgafp-ra-2025');
        $this->newPrimeRate($corps, '0.90', 2023, true, $source, $set);
        $this->newPrimeRate($corps, '0.21', 2023, false, $source, $set);
        $this->em->flush();

        $rate = $this->repository()->forYear('PE', 2023);

        self::assertNotNull($rate);
        self::assertFalse($rate->fallback);
        self::assertSame(0.21, (float) $rate->value);
    }

    private function currentSet(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->em->flush();

        return $set;
    }

    private function repository(): PrimeRateRepository
    {
        $repository = self::getContainer()->get(PrimeRateRepository::class);
        self::assertInstanceOf(PrimeRateRepository::class, $repository);

        return $repository;
    }
}

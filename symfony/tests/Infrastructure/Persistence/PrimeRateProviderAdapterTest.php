<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Shared\MissingPrimeRateException;
use App\Domain\Shared\PrimeRateProvider;
use App\Domain\Shared\Rate;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\Source;

/**
 * C3 / C8 — le port de domaine `PrimeRateProvider`, résolu par le conteneur vers
 * l'adapter d'infrastructure, renvoie p (`Rate::fromRatio`) et le drapeau
 * fallback, scopé au dernier jeu importé (Postgres réel).
 */
final class PrimeRateProviderAdapterTest extends IntegrationTestCase
{
    private ?Source $source = null;

    public function testNonFallbackObservation(): void
    {
        // C3.1 : D = 2026-06-01, PE 0.21 obs. 2023 non-fallback → p = 0.21, fallback = non.
        $set = $this->currentSet();
        $this->newPrimeRate($this->newCorps('PE'), '0.21', 2023, false, $this->source(), $set);
        $this->em->flush();

        $observation = $this->provider()->primeRateAt('PE', new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($observation->rate()->equals(Rate::fromRatio('0.21')));
        self::assertFalse($observation->isFallback());
    }

    public function testFallbackObservation(): void
    {
        // C3.2 : D = 2026-06-01, ISG 0.33 obs. 2023 fallback → p = 0.33, fallback = oui.
        $set = $this->currentSet();
        $this->newPrimeRate($this->newCorps('ISG'), '0.33', 2023, true, $this->source(), $set);
        $this->em->flush();

        $observation = $this->provider()->primeRateAt('ISG', new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($observation->rate()->equals(Rate::fromRatio('0.33')));
        self::assertTrue($observation->isFallback());
    }

    public function testThrowsWhenNoObservationUpToYearOfDate(): void
    {
        // C3.3 : PE, D = 2022-06-01, seule l'observation 2023 existe → exception explicite.
        $set = $this->currentSet();
        $this->newPrimeRate($this->newCorps('PE'), '0.21', 2023, false, $this->source(), $set);
        $this->em->flush();

        try {
            $this->provider()->primeRateAt('PE', new \DateTimeImmutable('2022-06-01'));
            self::fail('Expected MissingPrimeRateException when no observation precedes the date.');
        } catch (MissingPrimeRateException $exception) {
            self::assertSame('PE', $exception->bodyCode());
            self::assertStringContainsString('PE', $exception->getMessage());
            self::assertStringContainsString('2022-06-01', $exception->getMessage());
        }
    }

    public function testObservationsOfOlderSetsAreIgnored(): void
    {
        // Scope : seule l'observation du dernier jeu importé compte, même si un jeu
        // antérieur porte une observation plus récente.
        $corps = $this->newCorps('PE');
        $old = $this->newParameterSet('commit-old', '2025-01-15 10:00:00');
        $this->newPrimeRate($corps, '0.90', 2025, false, $this->source(), $old);
        $set = $this->currentSet();
        $this->newPrimeRate($corps, '0.21', 2023, false, $this->source(), $set);
        $this->em->flush();

        $observation = $this->provider()->primeRateAt('PE', new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($observation->rate()->equals(Rate::fromRatio('0.21')));
    }

    public function testThrowsWhenOnlyOlderSetsHaveObservations(): void
    {
        // C8 : pas de repli silencieux sur un jeu antérieur.
        $corps = $this->newCorps('PE');
        $old = $this->newParameterSet('commit-old', '2025-01-15 10:00:00');
        $this->newPrimeRate($corps, '0.21', 2023, false, $this->source(), $old);
        $this->currentSet();
        $this->em->flush();

        $this->expectException(MissingPrimeRateException::class);

        $this->provider()->primeRateAt('PE', new \DateTimeImmutable('2026-06-01'));
    }

    private function source(): Source
    {
        return $this->source ??= $this->newSource('dgafp-ra-2025');
    }

    private function currentSet(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->em->flush();

        return $set;
    }

    private function provider(): PrimeRateProvider
    {
        $provider = self::getContainer()->get(PrimeRateProvider::class);
        self::assertInstanceOf(PrimeRateProvider::class, $provider);

        return $provider;
    }
}

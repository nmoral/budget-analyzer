<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\Rate;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Rate\DatedRateProviderAdapter;
use App\Infrastructure\Repository\DatedParameterRepository;

/**
 * C3 — l'adapter branche le port de domaine `DatedRateProvider` sur l'infra des
 * paramètres datés (Postgres réel), sans aucun taux en dur.
 */
final class DatedRateProviderAdapterTest extends IntegrationTestCase
{
    public function testReturnsDatedRateFromLatestSet(): void
    {
        // C3.1 : taux_cas_civil = 82.28 sur [2026-01-01, ouvert] dans le jeu courant.
        $set = $this->currentSet();
        $source = $this->newSource('retraites-etat-2026');
        $this->newDatedParameter('taux_cas_civil', '82.28', '2026-01-01', null, $source, $set);
        $this->em->flush();

        $rate = $this->adapter()->rateAt('taux_cas_civil', new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($rate->equals(Rate::fromPercentage('82.28')));
    }

    public function testThrowsWhenNoParameterCoversDate(): void
    {
        // C5.2 (côté infra) : aucune ligne ne couvre la date -> exception nommant clé + date.
        $set = $this->currentSet();
        $source = $this->newSource('retraites-etat-2026');
        $this->newDatedParameter('taux_cas_civil', '82.28', '2026-01-01', null, $source, $set);
        $this->em->flush();

        try {
            $this->adapter()->rateAt('taux_cas_civil', new \DateTimeImmutable('2024-06-01'));
            self::fail('Expected MissingDatedRateException when no parameter covers the date.');
        } catch (MissingDatedRateException $exception) {
            self::assertSame('taux_cas_civil', $exception->key());
            self::assertStringContainsString('taux_cas_civil', $exception->getMessage());
            self::assertStringContainsString('2024-06-01', $exception->getMessage());
        }
    }

    private function adapter(): DatedRateProviderAdapter
    {
        return new DatedRateProviderAdapter($this->repository());
    }

    private function repository(): DatedParameterRepository
    {
        $repository = self::getContainer()->get(DatedParameterRepository::class);
        self::assertInstanceOf(DatedParameterRepository::class, $repository);

        return $repository;
    }

    private function currentSet(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->em->flush();

        return $set;
    }
}

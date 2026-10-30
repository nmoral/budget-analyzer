<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Shared\Amount;
use App\Domain\Shared\MissingDatedAmountException;
use App\Infrastructure\Amount\DatedAmountProviderAdapter;
use App\Infrastructure\Repository\DatedParameterRepository;

/**
 * C2 / C8 — l'adapter branche le port de domaine `DatedAmountProvider` sur les
 * paramètres datés (Postgres réel) ; le TIB est calculé de bout en bout via le
 * câblage du conteneur.
 */
final class DatedAmountProviderAdapterTest extends IntegrationTestCase
{
    public function testReturnsDatedAmountFromLatestSet(): void
    {
        $this->seedIndexPoint();

        $amount = $this->adapter()->amountAt('point_indice', new \DateTimeImmutable('2026-06-01'));

        self::assertTrue($amount->equals(Amount::fromString('59.0734')));
    }

    public function testIgnoresParametersOfOlderSets(): void
    {
        // Résolution sur le dernier jeu importé : la valeur d'un jeu antérieur est ignorée.
        $old = $this->newParameterSet('commit-old', '2025-01-15 10:00:00');
        $this->newDatedParameter('point_indice', '1.0', '2023-07-01', null, $this->newSource('ancien'), $old);
        $this->seedIndexPoint();

        $amount = $this->adapter()->amountAt('point_indice', new \DateTimeImmutable('2026-06-01'));

        self::assertSame('59.073400', $amount->toString());
    }

    public function testComputesIndexedSalaryThroughContainerWiring(): void
    {
        // C2.1 : IM 395, D = 2026-06-01, point 59.0734 depuis 2023-07-01 → 23333.993000.
        $this->seedIndexPoint();

        $tib = $this->calculator()->compute(395, new \DateTimeImmutable('2026-06-01'));

        self::assertSame('23333.993000', $tib->toString());
    }

    public function testThrowsWhenIndexPointIsMissingAtDate(): void
    {
        // C2.2 : D = 2023-06-30 → exception explicite nommant clé + date.
        $this->seedIndexPoint();

        try {
            $this->calculator()->compute(395, new \DateTimeImmutable('2023-06-30'));
            self::fail('Expected MissingDatedAmountException when the index point is missing.');
        } catch (MissingDatedAmountException $exception) {
            self::assertSame('point_indice', $exception->key());
            self::assertStringContainsString('point_indice', $exception->getMessage());
            self::assertStringContainsString('2023-06-30', $exception->getMessage());
        }
    }

    private function seedIndexPoint(): void
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->newDatedParameter('point_indice', '59.0734', '2023-07-01', null, $this->newSource('decret-2023-519'), $set);
        $this->em->flush();
    }

    private function adapter(): DatedAmountProviderAdapter
    {
        $repository = self::getContainer()->get(DatedParameterRepository::class);
        self::assertInstanceOf(DatedParameterRepository::class, $repository);

        return new DatedAmountProviderAdapter($repository);
    }

    private function calculator(): IndexedSalaryCalculator
    {
        $calculator = self::getContainer()->get(IndexedSalaryCalculator::class);
        self::assertInstanceOf(IndexedSalaryCalculator::class, $calculator);

        return $calculator;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\IndexedSalaryCalculator;
use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;
use App\Domain\Shared\MissingDatedAmountException;
use App\Tests\Support\Trace;
use PHPUnit\Framework\TestCase;

/**
 * C2 — TIB = indice majoré × `point_indice` en vigueur à D, lu via le port de
 * domaine `DatedAmountProvider` (implémentation factice PURE).
 */
final class IndexedSalaryCalculatorTest extends TestCase
{
    public function testMultipliesIncreasedIndexByIndexPointInEffect(): void
    {
        // C2.1 : IM 395, D = 2026-06-01, point 59.0734 depuis 2023-07-01 → 23333.993000.
        $calculator = new IndexedSalaryCalculator($this->pointProvider());

        $tib = $calculator->compute(395, new \DateTimeImmutable('2026-06-01'));

        self::assertSame('23333.993000', $tib->toString());
    }

    public function testThrowsWhenIndexPointIsMissingAtDate(): void
    {
        // C2.2 : D = 2023-06-30, avant le point en vigueur → exception explicite (clé + date).
        $calculator = new IndexedSalaryCalculator($this->pointProvider());

        try {
            $calculator->compute(395, new \DateTimeImmutable('2023-06-30'));
            self::fail('Expected MissingDatedAmountException when the index point is missing.');
        } catch (MissingDatedAmountException $exception) {
            self::assertSame('point_indice', $exception->key());
            self::assertSame('2023-06-30', $exception->date()->format('Y-m-d'));
        }
    }

    /**
     * Fournisseur factice : ne connaît que `point_indice` = 59.0734 depuis
     * 2023-07-01 ; toute autre clé ou date antérieure lève l'exception.
     */
    private function pointProvider(): DatedAmountProvider
    {
        return new class () implements DatedAmountProvider {
            public function amountAt(string $key, \DateTimeImmutable $date): DatedAmount
            {
                if ($key !== 'point_indice' || $date < new \DateTimeImmutable('2023-07-01')) {
                    throw MissingDatedAmountException::forKeyAndDate($key, $date);
                }

                return Trace::datedAmount(Amount::fromString('59.0734'));
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\MissingDatedAmountException;
use PHPUnit\Framework\TestCase;

/**
 * C8 — aucun montant daté ne couvre la date : l'exception nomme la clé ET la
 * date (pas de valeur par défaut silencieuse).
 */
final class MissingDatedAmountExceptionTest extends TestCase
{
    public function testMessageNamesKeyAndDate(): void
    {
        $exception = MissingDatedAmountException::forKeyAndDate('point_indice', new \DateTimeImmutable('2023-06-30'));

        self::assertSame('Aucun montant daté "point_indice" ne couvre la date 2023-06-30.', $exception->getMessage());
    }

    public function testExposesKeyAndDate(): void
    {
        $date = new \DateTimeImmutable('2023-06-30');

        $exception = MissingDatedAmountException::forKeyAndDate('point_indice', $date);

        self::assertSame('point_indice', $exception->key());
        self::assertSame($date, $exception->date());
    }
}

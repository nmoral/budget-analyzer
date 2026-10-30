<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\MissingDatedRateException;
use PHPUnit\Framework\TestCase;

/**
 * Exception de domaine levée quand aucun paramètre daté ne couvre la date
 * demandée : elle nomme explicitement la clé ET la date (pas de valeur par
 * défaut silencieuse) (C5.2).
 */
final class MissingDatedRateExceptionTest extends TestCase
{
    public function testMessageNamesKeyAndDate(): void
    {
        $date = new \DateTimeImmutable('2024-06-01');

        $exception = MissingDatedRateException::forKeyAndDate('taux_cas_civil', $date);

        self::assertStringContainsString('taux_cas_civil', $exception->getMessage());
        self::assertStringContainsString('2024-06-01', $exception->getMessage());
    }

    public function testExposesKeyAndDate(): void
    {
        $date = new \DateTimeImmutable('2024-06-01');

        $exception = MissingDatedRateException::forKeyAndDate('taux_cnracl', $date);

        self::assertSame('taux_cnracl', $exception->key());
        self::assertSame($date, $exception->date());
    }
}

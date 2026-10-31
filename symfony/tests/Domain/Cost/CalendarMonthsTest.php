<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\CalendarMonths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Utilitaire de domaine PUR `CalendarMonths` : compte, mois d'entrée inclus et
 * compté entier (jamais de prorata au jour), le nombre de mois civils d'une date
 * de recrutement jusqu'à la prochaine occurrence d'un mois de fin inclus (C4).
 *
 * Quand le mois de fin est antérieur ou égal au mois de recrutement, la fenêtre
 * franchit l'année suivante (fenêtre scolaire sept→août).
 */
final class CalendarMonthsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function countUntilCases(): iterable
    {
        // Année 1 civile : du recrutement à décembre inclus.
        yield '1er sept → déc = 4' => ['2026-09-01', 12, 4];
        yield '1er jan → déc = 12' => ['2026-01-01', 12, 12];
        yield '1er déc → déc = 1' => ['2026-12-01', 12, 1];
        yield '15 sept → déc = 4 (mois d\'entrée entier)' => ['2026-09-15', 12, 4];

        // Fenêtre année scolaire : du recrutement à fin août inclus (sept→août).
        yield 'fenêtre scolaire 1er sept → août = 12' => ['2026-09-01', 8, 12];
        yield '1er nov → août = 10' => ['2026-11-01', 8, 10];
    }

    #[DataProvider('countUntilCases')]
    public function testCountUntil(string $recruitment, int $endMonth, int $expected): void
    {
        self::assertSame(
            $expected,
            CalendarMonths::countUntil(new \DateTimeImmutable($recruitment), $endMonth),
        );
    }
}

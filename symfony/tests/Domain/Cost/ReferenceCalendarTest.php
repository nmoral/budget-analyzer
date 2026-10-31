<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cost;

use App\Domain\Cost\ReferenceCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Enum de domaine PUR `ReferenceCalendar` : calendrier de référence d'un profil,
 * entrée du calculateur d'horizon (C4). Deux cas, civil ou scolaire.
 */
final class ReferenceCalendarTest extends TestCase
{
    public function testExposesCivilAndScolaireCases(): void
    {
        self::assertSame(
            [ReferenceCalendar::CIVIL, ReferenceCalendar::SCOLAIRE],
            ReferenceCalendar::cases(),
        );
    }
}

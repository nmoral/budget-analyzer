<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Calendrier de référence d'un profil, entrée du calculateur d'horizon.
 *
 * - `CIVIL` : année 1 comptée du recrutement à décembre inclus.
 * - `SCOLAIRE` : fenêtre année scolaire (1er sept → 31 août) contenant le
 *   recrutement, prorata du recrutement à fin août inclus.
 */
enum ReferenceCalendar
{
    case CIVIL;
    case SCOLAIRE;
}

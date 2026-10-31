<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Comptage de mois civils de domaine PUR (aucune dépendance de persistance
 * ni d'infrastructure).
 *
 * Le mois d'entrée est compté entier : seul le mois du recrutement compte, jamais
 * le jour (pas de prorata au jour).
 */
final class CalendarMonths
{
    private function __construct()
    {
    }

    /**
     * Nombre de mois civils du mois de recrutement jusqu'à la prochaine occurrence
     * du mois `$endMonth` (1 = janvier … 12 = décembre), bornes incluses.
     *
     * Si `$endMonth` est antérieur au mois de recrutement, la fenêtre franchit
     * l'année suivante — cas de la fenêtre scolaire (sept → août).
     *
     * Exemples : sept → déc = 4 ; janv → déc = 12 ; déc → déc = 1 ;
     * sept → août = 12 ; nov → août = 10.
     */
    public static function countUntil(\DateTimeImmutable $recruitment, int $endMonth): int
    {
        $startMonth = (int) $recruitment->format('n');
        $end = $endMonth >= $startMonth ? $endMonth : $endMonth + 12;

        return $end - $startMonth + 1;
    }
}

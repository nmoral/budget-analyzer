<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR : les vues d'horizon « année 1 » d'un recrutement.
 *
 * - `fullYear()` : coût annuel plein, non proraté, valorisé à la date de recrutement.
 * - `year1()` : coût de la première année civile, proraté terme par terme du mois de
 *   recrutement à décembre inclus.
 * - `schoolYear()` : vue année scolaire (sept → août), présente seulement pour un
 *   calendrier de référence scolaire, sinon `null`.
 */
final class Year1Horizon
{
    public function __construct(
        private readonly AnnualCost $fullYear,
        private readonly AnnualCost $year1,
        private readonly ?AnnualCost $schoolYear,
    ) {
    }

    public function fullYear(): AnnualCost
    {
        return $this->fullYear;
    }

    public function year1(): AnnualCost
    {
        return $this->year1;
    }

    public function schoolYear(): ?AnnualCost
    {
        return $this->schoolYear;
    }
}

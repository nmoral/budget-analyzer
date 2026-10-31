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
 *
 * Chaque fenêtre expose aussi son `Prorata` (#65, C1.3), afin que sa valeur reste
 * réconciliable (value = valeur_annuelle × months/over) ; l'année pleine a un prorata
 * de 12/12 et la fenêtre scolaire son prorata est présent si et seulement si elle l'est.
 */
final class Year1Horizon
{
    public function __construct(
        private readonly AnnualCost $fullYear,
        private readonly AnnualCost $year1,
        private readonly Prorata $year1Prorata,
        private readonly ?AnnualCost $schoolYear,
        private readonly ?Prorata $schoolYearProrata,
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

    /**
     * Prorata de l'année pleine : toujours 12/12 (facteur 1, aucune proratisation).
     */
    public function fullYearProrata(): Prorata
    {
        return Prorata::full();
    }

    /**
     * Prorata de l'année 1 : mois présents du recrutement à décembre inclus, sur 12.
     */
    public function year1Prorata(): Prorata
    {
        return $this->year1Prorata;
    }

    /**
     * Prorata de la fenêtre scolaire, présent si et seulement si `schoolYear()` l'est.
     */
    public function schoolYearProrata(): ?Prorata
    {
        return $this->schoolYearProrata;
    }
}

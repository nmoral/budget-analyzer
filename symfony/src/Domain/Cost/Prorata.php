<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Facteur de prorata PUR d'une fenêtre d'horizon (#65, C1.3) : le nombre de mois
 * présents rapporté à une base de 12 mois.
 *
 * Il rend une valeur proratisée réconciliable hors du domaine : pour chaque terme
 * d'une fenêtre, `value = valeur_annuelle_du_terme × months / over`. Value object
 * PUR, sans dépendance à `Source` ni à la persistance.
 */
final class Prorata
{
    private const int FULL_YEAR = 12;

    private function __construct(
        private readonly int $months,
        private readonly int $over,
    ) {
    }

    /**
     * Prorata d'une fenêtre proratisée : `$months` mois présents sur 12.
     */
    public static function ofMonths(int $months): self
    {
        return new self($months, self::FULL_YEAR);
    }

    /**
     * Prorata de l'année pleine : 12 mois sur 12 (facteur 1, aucune proratisation).
     */
    public static function full(): self
    {
        return new self(self::FULL_YEAR, self::FULL_YEAR);
    }

    public function months(): int
    {
        return $this->months;
    }

    public function over(): int
    {
        return $this->over;
    }
}

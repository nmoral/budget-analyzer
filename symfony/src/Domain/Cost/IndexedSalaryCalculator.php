<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedAmount;
use App\Domain\Shared\DatedAmountProvider;

/**
 * Traitement indiciaire brut annuel (TIB) : indice majoré × valeur annuelle du
 * point d'indice en vigueur à la date. La clé de paramètre est connue du
 * composant ; sa valeur vient des paramètres datés.
 */
final class IndexedSalaryCalculator
{
    private const INDEX_POINT_KEY = 'point_indice';

    public function __construct(
        private readonly DatedAmountProvider $amounts,
    ) {
    }

    public function compute(int $increasedIndex, \DateTimeImmutable $date): Amount
    {
        return $this->computeTraced($increasedIndex, $date)->amount();
    }

    /**
     * TIB + l'entrée datée `point_indice` utilisée, pour la trace de calcul (#65).
     */
    public function computeTraced(int $increasedIndex, \DateTimeImmutable $date): DatedAmount
    {
        $point = $this->amounts->amountAt(self::INDEX_POINT_KEY, $date);

        return new DatedAmount($point->amount()->multipliedByIndex($increasedIndex), $point->input());
    }
}

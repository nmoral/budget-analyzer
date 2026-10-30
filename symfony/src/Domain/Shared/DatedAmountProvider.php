<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Port de domaine PUR : fournit un montant daté pour une clé de paramètre à une
 * date donnée (ex. `point_indice`). L'implémentation (adapter d'infrastructure)
 * est branchée hors du domaine.
 */
interface DatedAmountProvider
{
    /**
     * @throws MissingDatedAmountException si aucun paramètre ne couvre la date
     */
    public function amountAt(string $key, \DateTimeImmutable $date): Amount;
}

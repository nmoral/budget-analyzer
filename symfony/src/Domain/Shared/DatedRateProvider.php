<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Port de domaine PUR : fournit un taux daté pour une clé de paramètre à une
 * date donnée, accompagné de la description de l'entrée utilisée (clé, valeur,
 * unité, date d'effet) pour la transparence du calcul (#65). L'implémentation
 * (adapter d'infrastructure) est branchée hors du domaine. Réutilisable par
 * toutes les familles de règles ayant besoin d'un taux daté.
 */
interface DatedRateProvider
{
    /**
     * @throws MissingDatedRateException si aucun paramètre ne couvre la date
     */
    public function rateAt(string $key, \DateTimeImmutable $date): DatedRate;
}

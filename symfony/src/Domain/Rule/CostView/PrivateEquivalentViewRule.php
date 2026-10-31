<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedRateProvider;

/**
 * Vue équivalent privé (#18) : valorise la retraite comme si l'agent relevait du
 * secteur privé, retraite = TIB × taux de cotisation vieillesse employeur du privé,
 * lu sous la clé datée « taux_employeur_prive » via le port de domaine (aucun taux
 * en dur ; la valeur vient des paramètres datés). Indépendante du régime public.
 */
final class PrivateEquivalentViewRule implements CostViewRule
{
    private const RATE_KEY = 'taux_employeur_prive';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function code(): string
    {
        return 'EQUIVALENT_PRIVE';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): Amount
    {
        return $tib->multipliedByRate($this->rates->rateAt(self::RATE_KEY, $date)->rate());
    }

    public function definition(): string
    {
        return 'Coût retraite valorisé au taux de cotisation vieillesse employeur du secteur privé, appliqué au TIB.';
    }

    public function question(): string
    {
        return 'Combien coûterait la retraite de cet agent s\'il relevait du régime du secteur privé ?';
    }

    public function assumptions(): array
    {
        return ['Taux de cotisation vieillesse employeur du secteur privé (clé datée « taux_employeur_prive »).'];
    }

    public function sources(): array
    {
        return ['Barèmes de cotisations vieillesse employeur du régime général et complémentaire (URSSAF, AGIRC-ARRCO).'];
    }
}

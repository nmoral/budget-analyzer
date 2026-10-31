<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Shared\Amount;
use App\Domain\Shared\Rate;

/**
 * Vue actuarielle (#18) : valorise la retraite comme le coût des services rendus sur
 * l'année (méthode des unités de crédit projetées, IAS 19 / norme comptable n°13 de
 * l'État). Formule V1 : retraite = (TIB × taux d'annuité) × facteur actuariel, où
 * `TIB × taux d'annuité` = droits acquis sur l'année et `facteur actuariel` (ä) résume
 * actualisation, survie et différé — fourni en hypothèse (aucune table de mortalité
 * dans le domaine). Les hypothèses proviennent du VO `AssumptionsActuarielles` injecté
 * (défauts sourcés, surchargeables).
 *
 * Le facteur actuariel est un multiplicateur sans unité : il est appliqué via un
 * `Rate` dont le ratio porte la valeur du facteur, pour réutiliser la multiplication
 * décimal-safe d'`Amount` sans étendre le cœur.
 */
final class ActuarialViewRule implements CostViewRule
{
    public function __construct(
        private readonly AssumptionsActuarielles $assumptions,
    ) {
    }

    public function code(): string
    {
        return 'ACTUARIELLE';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): Amount
    {
        $annualRights = $tib->multipliedByRate($this->assumptions->tauxAnnuite());

        return $annualRights->multipliedByRate(Rate::fromRatio($this->assumptions->facteurActuariel()));
    }

    public function definition(): string
    {
        return 'Coût retraite = engagement généré par une année de service (unités de crédit projetées, IAS 19 / norme n°13) : (TIB × taux d\'annuité) × facteur actuariel.';
    }

    public function question(): string
    {
        return 'Quel engagement de retraite l\'employeur accumule-t-il pour une année de service de cet agent ?';
    }

    public function assumptions(): array
    {
        return [
            'Taux d\'annuité : droits de pension acquis par année de service (défaut 1,75 %).',
            'Facteur actuariel (ä) : actualisation, survie et différé résumés, fourni en hypothèse (défaut 12,5).',
        ];
    }

    public function sources(): array
    {
        return [
            'Note INSEE sur les engagements de retraite de l\'État.',
            'Jaune budgétaire « Pensions » de la fonction publique.',
            'Autorité des normes comptables, recommandation 2013-R-02.',
        ];
    }
}

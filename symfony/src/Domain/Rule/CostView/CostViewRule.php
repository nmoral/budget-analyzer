<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Rule\Rule;
use App\Domain\Shared\Amount;
use App\Domain\Shared\RetirementValuation;

/**
 * Famille « vue de coût » (#18) : angle de valorisation de la composante retraite
 * du coût annuel. Chaque vue enfichable expose sa méthode de calcul du terme
 * retraite et ses métadonnées de restitution ; les autres termes du coût sont
 * identiques entre vues (recomposés par la surcouche, cf. CostViewCalculator).
 *
 * Signature volontairement en types Shared/natifs (pas d'AgentSituation) pour
 * éviter un cycle de dépendances domaine Cost ↔ Rule : la surcouche (App\Domain\Cost)
 * calcule le TIB et le passe à la vue.
 */
interface CostViewRule extends Rule
{
    /**
     * Terme retraite employeur annuel selon cette vue, pour le TIB, la date et le
     * code de régime de retraite donnés : montant + trace (formule lisible et entrées
     * datées utilisées) pour la transparence (#65).
     */
    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): RetirementValuation;

    /**
     * Définition courte de la méthode de valorisation (non vide).
     */
    public function definition(): string;

    /**
     * Question métier à laquelle la vue répond (non vide).
     */
    public function question(): string;

    /**
     * Hypothèses sur lesquelles repose la vue (vide pour la vue budgétaire).
     *
     * @return list<string>
     */
    public function assumptions(): array;

    /**
     * Sources documentaires de la méthode (non vides).
     *
     * @return list<string>
     */
    public function sources(): array;
}

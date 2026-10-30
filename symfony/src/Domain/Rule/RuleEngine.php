<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use App\Domain\Profile\Profile;
use App\Domain\Rule\CostView\CostViewRuleResolver;
use App\Domain\Rule\Funder\FunderRuleResolver;
use App\Domain\Rule\Indemnitary\IndemnitaryRuleResolver;
use App\Domain\Rule\Retirement\RetirementRuleResolver;

/**
 * Moteur de résolution : à partir d'un profil de domaine PUR et du code de vue de
 * coût passé en entrée (paramètre de simulation), sélectionne les quatre
 * implémentations de règles via les résolveurs de famille.
 *
 * Aucun code métier en dur : chaque famille est résolue par son résolveur, qui
 * délègue la sélection à un service locator indexé par code.
 *
 * @throws UnknownRuleCodeException si un code référencé n'a aucune implémentation
 */
final class RuleEngine
{
    public function __construct(
        private readonly RetirementRuleResolver $retirement,
        private readonly IndemnitaryRuleResolver $indemnitary,
        private readonly FunderRuleResolver $funder,
        private readonly CostViewRuleResolver $costView,
    ) {
    }

    public function resolve(Profile $profile, string $costViewCode): ResolvedRules
    {
        return new ResolvedRules(
            $this->retirement->resolve($profile->retirementCode()),
            $this->indemnitary->resolve($profile->indemnitaryCode()),
            $this->funder->resolve($profile->funderCode()),
            $this->costView->resolve($costViewCode),
        );
    }
}

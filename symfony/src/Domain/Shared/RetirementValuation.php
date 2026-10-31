<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Value object de domaine PUR : valorisation du terme retraite par une vue de coût
 * (#18/#65). Porte le montant calculé, la formule lisible de la vue et la liste des
 * entrées datées utilisées, pour alimenter la trace de transparence sans créer de
 * cycle de dépendances entre les paquets de domaine (types Shared uniquement).
 */
final class RetirementValuation
{
    /**
     * @param list<CostInput> $inputs
     */
    public function __construct(
        private readonly Amount $amount,
        private readonly string $formula,
        private readonly array $inputs,
    ) {
    }

    public function amount(): Amount
    {
        return $this->amount;
    }

    public function formula(): string
    {
        return $this->formula;
    }

    /**
     * @return list<CostInput>
     */
    public function inputs(): array
    {
        return $this->inputs;
    }
}

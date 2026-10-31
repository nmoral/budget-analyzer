<?php

declare(strict_types=1);

namespace App\Domain\Cost;

use App\Domain\Shared\CostInput;

/**
 * Trace de calcul PURE d'un terme du coût annuel (#65) : sa formule en texte
 * lisible (ex. « TIB·r_retraite ») et la liste des entrées datées réellement
 * utilisées pour le produire. Value object PUR, sans dépendance à `Source` ni à
 * la persistance : sérialisation et résolution des sources se font hors du domaine (#J3).
 */
final class TermTrace
{
    /**
     * @param list<CostInput> $inputs
     */
    public function __construct(
        private readonly string $formula,
        private readonly array $inputs,
    ) {
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

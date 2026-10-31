<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR : un échelon d'une carrière, c'est-à-dire la
 * situation d'un agent valorisable et la durée (en mois pleins) passée dessus.
 *
 * La durée doit être strictement positive ; une durée nulle ou négative est
 * rejetée (pas d'échelon de durée vide, le terminal recevant une durée fournie).
 */
final class CareerStep
{
    public function __construct(
        private readonly AgentSituation $situation,
        private readonly int $durationMonths,
    ) {
        if ($durationMonths <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'La durée d\'un échelon doit être strictement positive, %d reçu.',
                $durationMonths,
            ));
        }
    }

    public function situation(): AgentSituation
    {
        return $this->situation;
    }

    public function durationMonths(): int
    {
        return $this->durationMonths;
    }
}

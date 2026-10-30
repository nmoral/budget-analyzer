<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR décrivant la situation d'un agent titulaire pour le
 * calcul de coût : corps, versant, indice majoré et régime de retraite. Construit
 * à la main (l'adapter depuis le modèle de persistance du profil est reporté à #15).
 */
final class AgentSituation
{
    public function __construct(
        private readonly string $bodyCode,
        private readonly string $versant,
        private readonly int $increasedIndex,
        private readonly string $retirementRegimeCode,
    ) {
    }

    public function bodyCode(): string
    {
        return $this->bodyCode;
    }

    public function versant(): string
    {
        return $this->versant;
    }

    public function increasedIndex(): int
    {
        return $this->increasedIndex;
    }

    public function retirementRegimeCode(): string
    {
        return $this->retirementRegimeCode;
    }
}

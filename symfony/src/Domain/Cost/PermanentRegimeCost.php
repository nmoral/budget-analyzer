<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR : coût en régime permanent (population stationnaire).
 *
 * Expose le coût annuel moyen d'un agent du corps (`averagePerAgent()`, moyenne
 * pondérée par la durée de chaque échelon, calculée par le calculateur) et sa
 * projection sur l'effectif (`forHeadcount()` = chaque terme × N). Le VO ne porte
 * aucune logique de calcul métier au-delà du produit par l'effectif et de
 * l'exposition de l'hypothèse de population.
 */
final class PermanentRegimeCost
{
    public function __construct(
        private readonly AnnualCost $averagePerAgent,
        private readonly int $headcount,
    ) {
    }

    /**
     * Coût annuel moyen d'un agent : les quatre termes moyens pondérés + total.
     */
    public function averagePerAgent(): AnnualCost
    {
        return $this->averagePerAgent;
    }

    /**
     * Coût pour l'effectif complet : chaque terme du coût moyen × N (le total de
     * l'`AnnualCost` se recompose comme somme des termes × N).
     */
    public function forHeadcount(): AnnualCost
    {
        return new AnnualCost(
            $this->averagePerAgent->treatmentAndPrimes()->multipliedByIndex($this->headcount),
            $this->averagePerAgent->retirement()->multipliedByIndex($this->headcount),
            $this->averagePerAgent->otherContributions()->multipliedByIndex($this->headcount),
            $this->averagePerAgent->rafp()->multipliedByIndex($this->headcount),
        );
    }

    /**
     * Hypothèse de population retenue : population stationnaire, sans logique.
     */
    public function assumption(): PopulationAssumption
    {
        return PopulationAssumption::STATIONARY;
    }
}

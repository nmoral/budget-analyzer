<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Value object de domaine PUR : la carrière d'un agent, suite ordonnée d'échelons
 * (`CareerStep`) assortis de leur durée, échelon terminal inclus avec sa durée
 * fournie.
 *
 * Une carrière doit comporter au moins un échelon ; une carrière vide est rejetée.
 */
final class Career
{
    /**
     * @var list<CareerStep>
     */
    private readonly array $steps;

    public function __construct(CareerStep ...$steps)
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('Une carrière doit comporter au moins un échelon.');
        }

        $this->steps = array_values($steps);
    }

    /**
     * @return list<CareerStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Durée totale de la carrière, en mois : somme des durées de ses échelons.
     */
    public function totalMonths(): int
    {
        $total = 0;
        foreach ($this->steps as $step) {
            $total += $step->durationMonths();
        }

        return $total;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\Career;
use App\Domain\Cost\CareerStep;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Repository\EchelonRepository;

/**
 * Adapter d'infrastructure : construit la carrière de domaine (`Career`, suite de
 * `CareerStep`) d'un profil à partir de son échelon d'entrée et de sa grille. La
 * carrière suit les échelons de la grille, de l'échelon d'entrée (`entryEchelon`)
 * jusqu'au dernier rang, ordonnés par rang. L'objet produit ne porte aucune
 * référence à l'entité ni à Doctrine.
 *
 * L'échelon terminal a une durée vide en base (`durationMonths` null) ; il reçoit
 * alors la durée fixe `$terminalDurationMonths`, injectée au câblage (tunable), afin
 * d'entrer dans la moyenne pondérée du régime permanent comme tout échelon.
 */
final class CareerFactory
{
    /**
     * Durée par défaut (en mois) de l'échelon terminal, dont la durée est vide en
     * base. Valeur de câblage par défaut, surchargée à l'injection si besoin.
     */
    public const int DEFAULT_TERMINAL_DURATION_MONTHS = 60;

    public function __construct(
        private readonly EchelonRepository $echelons,
        private readonly int $terminalDurationMonths = self::DEFAULT_TERMINAL_DURATION_MONTHS,
    ) {
    }

    public function fromProfile(Profile $profile): Career
    {
        $entry = $profile->entryEchelon;
        $echelons = $this->echelons->forGrilleFromRank($entry->grille, $entry->rank);

        $steps = [];
        foreach ($echelons as $echelon) {
            $situation = new AgentSituation(
                $profile->body->code,
                $profile->body->versant,
                $echelon->increasedIndex,
                $profile->retirementScheme->code,
            );
            $steps[] = new CareerStep($situation, $echelon->durationMonths ?? $this->terminalDurationMonths);
        }

        return new Career(...$steps);
    }
}

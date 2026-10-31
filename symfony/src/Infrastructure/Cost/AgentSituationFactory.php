<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Cost\AgentSituation;
use App\Domain\Cost\ReferenceCalendar;
use App\Infrastructure\Entity\Profile;

/**
 * Adapter d'infrastructure : construit la situation d'agent de domaine (`AgentSituation`)
 * et le calendrier de référence (`ReferenceCalendar`) à partir de l'entité Doctrine
 * `Profile` et de son échelon d'entrée. Seul endroit touchant les deux côtés ; l'objet
 * produit ne porte aucune référence à l'entité ni à Doctrine.
 */
final class AgentSituationFactory
{
    public function fromProfile(Profile $profile): AgentSituation
    {
        return new AgentSituation(
            $profile->body->code,
            $profile->body->versant,
            $profile->entryEchelon->increasedIndex,
            $profile->retirementScheme->code,
        );
    }

    public function calendarFor(Profile $profile): ReferenceCalendar
    {
        return match (strtolower($profile->referenceCalendar)) {
            'scolaire' => ReferenceCalendar::SCOLAIRE,
            'civil' => ReferenceCalendar::CIVIL,
            default => throw new \InvalidArgumentException(sprintf(
                'Calendrier de référence inconnu « %s » pour le profil #%s.',
                $profile->referenceCalendar,
                $profile->id ?? '?',
            )),
        };
    }
}

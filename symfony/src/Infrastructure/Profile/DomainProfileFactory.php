<?php

declare(strict_types=1);

namespace App\Infrastructure\Profile;

use App\Domain\Profile\Profile as DomainProfile;
use App\Infrastructure\Entity\Profile as ProfileEntity;

/**
 * Adapter d'infrastructure : construit un `Profile` de domaine PUR depuis l'entité
 * Doctrine `Profile`. Seul endroit touchant les deux côtés (domaine + Doctrine) ;
 * l'objet produit ne porte aucune référence à l'entité ni à Doctrine.
 *
 * Mapping des codes : retraite = `RetirementScheme.code`, indemnitaire =
 * `Profile.indemnitaryScheme` (string brut), financeur = `Funder.code`.
 */
final class DomainProfileFactory
{
    public function fromEntity(ProfileEntity $entity): DomainProfile
    {
        $indemnitaryCode = $entity->indemnitaryScheme;
        if (null === $indemnitaryCode) {
            throw new \InvalidArgumentException(sprintf(
                'Le profil #%s n\'a pas de régime indemnitaire : il ne peut être résolu par le moteur.',
                $entity->id ?? '?',
            ));
        }

        return new DomainProfile(
            $entity->retirementScheme->code,
            $indemnitaryCode,
            $entity->funder->code,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * Profil de domaine PUR : porte les codes de règles référencés par un profil de
 * simulation (retraite, indemnitaire, financeur). Sans aucune dépendance à la
 * persistance ni à l'infrastructure (frontière garantie par Deptrac).
 *
 * À distinguer du modèle de persistance homonyme côté infrastructure : cet objet
 * ne porte que l'identité de sélection des règles, alimentée par un adapter
 * d'infrastructure (`App\Infrastructure\Profile\DomainProfileFactory`).
 *
 * La vue de coût n'est PAS portée ici : c'est un paramètre de simulation passé en
 * entrée du moteur (cf. `App\Domain\Rule\RuleEngine::resolve()`).
 */
final class Profile
{
    public function __construct(
        private readonly string $retirementCode,
        private readonly string $indemnitaryCode,
        private readonly string $funderCode,
    ) {
    }

    public function retirementCode(): string
    {
        return $this->retirementCode;
    }

    public function indemnitaryCode(): string
    {
        return $this->indemnitaryCode;
    }

    public function funderCode(): string
    {
        return $this->funderCode;
    }
}

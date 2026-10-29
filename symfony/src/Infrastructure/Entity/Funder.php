<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Financeur — rattachement budgétaire.
 *
 * Champs : code (`ETAT_P140`, `ONDAM`…), libellé, et codes dans les nomenclatures
 * officielles (mission / programme / action pour l'État, sous-objectif de l'ONDAM,
 * fonction COFOG) pour préparer la V3. Un financeur n'utilise qu'une partie de ces
 * codes selon sa nomenclature : tous nullable. Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'funder')]
class Funder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $mission = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $program = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $action = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $ondamSubObjective = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $cofog = null;
}

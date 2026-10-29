<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profil — unité de simulation.
 *
 * Champs : corps, grade et échelon d'entrée, régime de retraite, régime indemnitaire,
 * financeur, statut (titulaire ou contractuel), calendrier de référence (civil ou
 * scolaire). Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile')]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Corps::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Corps $body;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $grade;

    #[ORM\ManyToOne(targetEntity: Echelon::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Echelon $entryEchelon;

    #[ORM\ManyToOne(targetEntity: RetirementScheme::class)]
    #[ORM\JoinColumn(nullable: false)]
    public RetirementScheme $retirementScheme;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $indemnitaryScheme = null;

    #[ORM\ManyToOne(targetEntity: Funder::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Funder $funder;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $status;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $referenceCalendar;
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profil — unité de simulation.
 *
 * Champs : code et libellé (référentiel `data/profiles.json`), corps, grade et
 * échelon d'entrée, régime de retraite, régime indemnitaire, financeur, statut
 * (titulaire ou contractuel), calendrier de référence (civil ou scolaire), source
 * justificative. Rattaché à un jeu de paramètres ; code unique par jeu (#62).
 * Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile')]
#[ORM\UniqueConstraint(name: 'uniq_profile_parameter_set_code', columns: ['parameter_set_id', 'code'])]
class Profile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;

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

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}

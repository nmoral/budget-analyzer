<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne de scénario — contenu d'un scénario.
 *
 * Champs : scénario, profil, volume, date de recrutement, étalement sur N années.
 * Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scenario_line')]
class ScenarioLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Scenario::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Scenario $scenario;

    #[ORM\ManyToOne(targetEntity: Profile::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Profile $profile;

    #[ORM\Column(type: Types::INTEGER)]
    public int $volume;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $recruitmentDate;

    #[ORM\Column(type: Types::INTEGER)]
    public int $spreadYears;
}

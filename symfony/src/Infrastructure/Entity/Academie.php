<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Académie — territoire de recrutement (référentiel global `data/academies.json`).
 *
 * Champs : code officiel (data.education.gouv.fr), libellé, statut (`metropole`,
 * `drom` ou `com`) et source du code. Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'academie')]
class Academie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 8, unique: true)]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $statut;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Corps — cadre statutaire.
 *
 * Champs : code, libellé, versant, catégorie A/B/C.
 * Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'corps')]
class Corps
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $versant;

    #[ORM\Column(type: Types::STRING, length: 1)]
    public string $category;
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Source — origine de toute valeur réglementaire.
 *
 * Champs : id, intitulé, éditeur, édition/année, localisation (`locator`), URL,
 * date de récupération, empreinte du fichier. `code` est la clé métier (slug
 * référencé par la colonne `source` des CSV, ex. `decret-2023-519`) que l'import
 * #11 résout vers cette entité. `edition` (année/édition du document) et `locator`
 * (localisation précise : page, tableau, article…) rendent la source « en clair
 * complète » (#65). Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'source')]
class Source
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
    public string $publisher;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $edition;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $locator;

    #[ORM\Column(type: Types::TEXT)]
    public string $url;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $retrievedAt;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $fileHash;
}

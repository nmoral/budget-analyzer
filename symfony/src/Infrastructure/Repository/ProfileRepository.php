<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\Profile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux profils importés par code, dans un jeu de paramètres (référence
 * `profileRef` d'une ligne de scénario, #62).
 */
final class ProfileRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findByCode(ParameterSet $set, string $code): ?Profile
    {
        return $this->em->getRepository(Profile::class)->findOneBy(['parameterSet' => $set, 'code' => $code]);
    }
}

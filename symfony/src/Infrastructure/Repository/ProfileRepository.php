<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\Profile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux profils par identifiant (référence `profileRef` d'une ligne de scénario).
 */
final class ProfileRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?Profile
    {
        return $this->em->find(Profile::class, $id);
    }
}

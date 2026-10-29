<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\ParameterSet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès au jeu de paramètres. Le « dernier jeu importé » sert de scope par défaut
 * aux valeurs datées (paramètre, grille).
 */
final class ParameterSetRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function latest(): ?ParameterSet
    {
        $result = $this->em->createQueryBuilder()
            ->select('s')
            ->from(ParameterSet::class, 's')
            ->orderBy('s.importedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result === null || $result instanceof ParameterSet);

        return $result;
    }
}

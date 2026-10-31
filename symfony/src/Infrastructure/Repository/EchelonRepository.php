<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Grille;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux échelons d'une grille indiciaire, ordonnés par rang.
 */
final class EchelonRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Échelons de la grille `$grille` à partir du rang `$fromRank` (inclus),
     * ordonnés par rang croissant. Liste vide si aucun échelon à ce rang ou au-delà.
     *
     * @return list<Echelon>
     */
    public function forGrilleFromRank(Grille $grille, int $fromRank): array
    {
        /** @var list<Echelon> $result */
        $result = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Echelon::class, 'e')
            ->where('e.grille = :grille')
            ->andWhere('e.rank >= :fromRank')
            ->orderBy('e.rank', 'ASC')
            ->setParameter('grille', $grille)
            ->setParameter('fromRank', $fromRank)
            ->getQuery()
            ->getResult();

        return $result;
    }
}

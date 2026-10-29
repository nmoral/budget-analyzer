<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Repository\GrilleRepository;

/**
 * C3 — « valeur en vigueur à D » pour les grilles indiciaires (Postgres réel).
 */
final class GrilleRepositoryTest extends IntegrationTestCase
{
    public function testGrilleInEffectWithEchelons(): void
    {
        // C3.1 : grille en vigueur et ses échelons.
        $set = $this->seedGrillePe();

        $grille = $this->repository()->valueInEffectAt('PE', 'classe_normale', new \DateTimeImmutable('2024-06-01'), $set);

        self::assertNotNull($grille);
        self::assertSame('2024', $grille->version);
        self::assertSame('PE', $grille->body->code);

        /** @var list<Echelon> $echelons */
        $echelons = $this->em->getRepository(Echelon::class)->findBy(['grille' => $grille], ['rank' => 'ASC']);
        self::assertCount(2, $echelons);
        self::assertSame(1, $echelons[0]->rank);
        self::assertSame(444, $echelons[0]->grossIndex);
        self::assertSame(2, $echelons[1]->rank);
    }

    public function testBeforeGrilleStartReturnsNull(): void
    {
        // C3.2 : avant le début de la grille.
        $set = $this->seedGrillePe();

        $grille = $this->repository()->valueInEffectAt('PE', 'classe_normale', new \DateTimeImmutable('2023-01-01'), $set);

        self::assertNull($grille);
    }

    public function testDefaultsToLatestParameterSet(): void
    {
        // C3 (scope jeu) : sans jeu précisé, dernier jeu importé.
        $this->seedGrillePe();

        $grille = $this->repository()->valueInEffectAt('PE', 'classe_normale', new \DateTimeImmutable('2024-06-01'));

        self::assertNotNull($grille);
        self::assertSame('2024', $grille->version);
    }

    private function seedGrillePe(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $corps = $this->newCorps('PE');
        $source = $this->newSource('decret-2017-789');
        $grille = $this->newGrille($corps, 'classe_normale', '2024', '2024-01-01', null, $source, $set);
        $this->newEchelon($grille, 1, 444, 395);
        $this->newEchelon($grille, 2, 513, 446);
        $this->em->flush();

        return $set;
    }

    private function repository(): GrilleRepository
    {
        $repository = self::getContainer()->get(GrilleRepository::class);
        self::assertInstanceOf(GrilleRepository::class, $repository);

        return $repository;
    }
}

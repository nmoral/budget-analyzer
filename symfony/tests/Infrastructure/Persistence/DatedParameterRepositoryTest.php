<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Repository\DatedParameterRepository;

/**
 * C2 — « valeur en vigueur à D » pour les paramètres datés (Postgres réel).
 */
final class DatedParameterRepositoryTest extends IntegrationTestCase
{
    public function testMidPeriod(): void
    {
        // C2.1 : milieu de période.
        $this->seedTauxCasCivil($this->currentSet());

        $param = $this->repository()->valueInEffectAt('taux_cas_civil', new \DateTimeImmutable('2026-06-01'));

        self::assertNotNull($param);
        self::assertSame(82.28, (float) $param->value);
    }

    public function testIncludedEndBound(): void
    {
        // C2.2 : borne de fin incluse.
        $this->seedTauxCasCivil($this->currentSet());

        $param = $this->repository()->valueInEffectAt('taux_cas_civil', new \DateTimeImmutable('2025-12-31'));

        self::assertNotNull($param);
        self::assertSame(78.28, (float) $param->value);
    }

    public function testIncludedStartBound(): void
    {
        // C2.3 : borne de début incluse.
        $this->seedTauxCasCivil($this->currentSet());

        $param = $this->repository()->valueInEffectAt('taux_cas_civil', new \DateTimeImmutable('2026-01-01'));

        self::assertNotNull($param);
        self::assertSame(82.28, (float) $param->value);
    }

    public function testBeforeAnyPeriodReturnsNull(): void
    {
        // C2.4 : avant toute période.
        $set = $this->currentSet();
        $source = $this->newSource('decret-2023-519');
        $this->newDatedParameter('point_indice', '59.0734', '2023-07-01', null, $source, $set);
        $this->em->flush();

        $param = $this->repository()->valueInEffectAt('point_indice', new \DateTimeImmutable('2000-01-01'));

        self::assertNull($param);
    }

    public function testOpenEndedPeriod(): void
    {
        // C2.5 : période ouverte (endDate NULL = ∞).
        $set = $this->currentSet();
        $source = $this->newSource('decret-2023-519');
        $this->newDatedParameter('point_indice', '59.0734', '2023-07-01', null, $source, $set);
        $this->em->flush();

        $param = $this->repository()->valueInEffectAt('point_indice', new \DateTimeImmutable('2030-01-01'));

        self::assertNotNull($param);
        self::assertSame(59.0734, (float) $param->value);
    }

    public function testParameterSetScope(): void
    {
        // C2.6 : scope du jeu de paramètres.
        $older = $this->newParameterSet('commit-older', '2026-01-01 10:00:00');
        $newer = $this->newParameterSet('commit-newer', '2026-02-01 10:00:00');
        $source = $this->newSource('retraites-etat-2026');
        // Deux jeux couvrant D=2026-06-01, valeurs distinctes.
        $this->newDatedParameter('taux_cas_civil', '80.00', '2026-01-01', null, $source, $older);
        $this->newDatedParameter('taux_cas_civil', '82.28', '2026-01-01', null, $source, $newer);
        $this->em->flush();

        $date = new \DateTimeImmutable('2026-06-01');

        // Sans jeu précisé : valeur du dernier jeu importé.
        $default = $this->repository()->valueInEffectAt('taux_cas_civil', $date);
        self::assertNotNull($default);
        self::assertSame(82.28, (float) $default->value);

        // Avec jeu précisé : la ligne de ce jeu.
        $fromOlder = $this->repository()->valueInEffectAt('taux_cas_civil', $date, $older);
        self::assertNotNull($fromOlder);
        self::assertSame(80.00, (float) $fromOlder->value);

        // Avec un jeu sans ligne couvrant D : null.
        $empty = $this->newParameterSet('commit-empty', '2026-03-01 10:00:00');
        $this->em->flush();
        self::assertNull($this->repository()->valueInEffectAt('taux_cas_civil', $date, $empty));
    }

    private function seedTauxCasCivil(ParameterSet $set): void
    {
        $source = $this->newSource('retraites-etat-2026');
        $this->newDatedParameter('taux_cas_civil', '78.28', '2025-01-01', '2025-12-31', $source, $set);
        $this->newDatedParameter('taux_cas_civil', '82.28', '2026-01-01', null, $source, $set);
        $this->em->flush();
    }

    private function currentSet(): ParameterSet
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $this->em->flush();

        return $set;
    }

    private function repository(): DatedParameterRepository
    {
        $repository = self::getContainer()->get(DatedParameterRepository::class);
        self::assertInstanceOf(DatedParameterRepository::class, $repository);

        return $repository;
    }
}

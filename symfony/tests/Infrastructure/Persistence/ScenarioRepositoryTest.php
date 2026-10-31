<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Infrastructure\Repository\ParameterSetRepository;
use App\Infrastructure\Repository\ProfileRepository;
use App\Infrastructure\Repository\ScenarioLineRepository;
use App\Infrastructure\Repository\ScenarioRepository;
use App\Tests\Infrastructure\Fixture\ScenarioFixtureTrait;

/**
 * Repositories Scenario / ScenarioLine (#19, J1) : persistance et relecture.
 */
final class ScenarioRepositoryTest extends IntegrationTestCase
{
    use ScenarioFixtureTrait;

    public function testSavePersistsScenarioWithItsLinesAndFindReloadsThem(): void
    {
        $set = $this->persistParameterSet($this->em, 'commit-repo');
        $profile = $this->persistProfile($this->em, $set);
        $this->em->flush();

        $scenario = new Scenario();
        $scenario->title = 'Scénario repo';
        $scenario->provenance = 'simulation';
        $scenario->parameterSet = $set;

        $line = new ScenarioLine();
        $line->scenario = $scenario;
        $line->profile = $profile;
        $line->volume = 2;
        $line->recruitmentDate = new \DateTimeImmutable('2026-09-01');
        $line->spreadYears = 0;

        $repository = new ScenarioRepository($this->em);
        $repository->save($scenario, $line);
        self::assertNotNull($scenario->id);

        $this->em->clear();

        $reloaded = $repository->find($scenario->id);
        self::assertInstanceOf(Scenario::class, $reloaded);
        self::assertSame('Scénario repo', $reloaded->title);
        self::assertSame('commit-repo', $reloaded->parameterSet->versionId);

        $lines = (new ScenarioLineRepository($this->em))->forScenario($reloaded);
        self::assertCount(1, $lines);
        self::assertSame(2, $lines[0]->volume);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $repository = new ScenarioRepository($this->em);

        self::assertNull($repository->find(999999));
    }

    public function testParameterSetRepositoryResolvesByVersionId(): void
    {
        $this->persistParameterSet($this->em, 'commit-a', '2026-01-01');
        $this->persistParameterSet($this->em, 'commit-b', '2026-02-01');
        $this->em->flush();

        $repository = new ParameterSetRepository($this->em);

        $found = $repository->findByVersionId('commit-b');
        self::assertNotNull($found);
        self::assertSame('commit-b', $found->versionId);
        self::assertNull($repository->findByVersionId('zzz'));
    }

    public function testProfileRepositoryFindsById(): void
    {
        $set = $this->persistParameterSet($this->em, 'commit-profile');
        $profile = $this->persistProfile($this->em, $set);
        $this->em->flush();
        self::assertNotNull($profile->id);

        $repository = new ProfileRepository($this->em);
        $found = $repository->find($profile->id);
        self::assertNotNull($found);
        self::assertSame($profile->id, $found->id);
        self::assertNull($repository->find(999999));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Cost;

use App\Domain\Cost\Career;
use App\Infrastructure\Cost\CareerFactory;
use App\Infrastructure\Entity\Corps;
use App\Infrastructure\Entity\Echelon;
use App\Infrastructure\Entity\Funder;
use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\Profile;
use App\Infrastructure\Entity\RetirementScheme;
use App\Infrastructure\Repository\EchelonRepository;
use App\Tests\Infrastructure\Persistence\IntegrationTestCase;

/**
 * J1 (#66) — factory d'infrastructure `Profile(+Grille) → Career` : construit la
 * carrière de domaine (échelons successifs depuis l'entrée, ordonnés par rang) à
 * partir de l'entité Profile et de sa grille, sur Postgres réel.
 */
final class CareerFactoryTest extends IntegrationTestCase
{
    public function testStepsOrderedByRankFromEntry(): void
    {
        // Entrée au rang 1 ; grille à 3 échelons (dont terminal) → 3 steps par rang croissant.
        $profile = $this->seedProfile(entryRank: 1, echelons: [
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 24],
        ]);

        $career = $this->factory()->fromProfile($profile);

        $steps = $career->steps();
        self::assertCount(3, $steps);
        self::assertSame(395, $steps[0]->situation()->increasedIndex());
        self::assertSame(420, $steps[1]->situation()->increasedIndex());
        self::assertSame(450, $steps[2]->situation()->increasedIndex());
    }

    public function testIntermediateDurationsFromDatabase(): void
    {
        // Les durées des échelons intermédiaires sont reprises telles quelles de la base.
        $profile = $this->seedProfile(entryRank: 1, echelons: [
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 24],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $steps = $this->factory()->fromProfile($profile)->steps();

        self::assertSame(12, $steps[0]->durationMonths());
        self::assertSame(24, $steps[1]->durationMonths());
    }

    public function testTerminalEchelonUsesInjectedConstant(): void
    {
        // C1.3 : le terminal (duration_months null) reçoit la durée fixe injectée (60 mois).
        $profile = $this->seedProfile(entryRank: 1, echelons: [
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 12],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $steps = $this->factory()->fromProfile($profile)->steps();

        self::assertSame(12, $steps[0]->durationMonths());
        self::assertSame(12, $steps[1]->durationMonths());
        self::assertSame(60, $steps[2]->durationMonths());
    }

    public function testTerminalDurationIsTunableViaInjection(): void
    {
        // La durée terminale est injectée au câblage, donc paramétrable (ex. 48 mois).
        $profile = $this->seedProfile(entryRank: 1, echelons: [
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $steps = $this->factory(terminalDurationMonths: 48)->fromProfile($profile)->steps();

        self::assertSame(48, $steps[1]->durationMonths());
    }

    public function testAgentSituationDerivedFromEchelon(): void
    {
        // L'AgentSituation de chaque step dérive de l'échelon (corps/versant/index/régime).
        $profile = $this->seedProfile(entryRank: 1, echelons: [
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $situation = $this->factory()->fromProfile($profile)->steps()[1]->situation();

        self::assertSame('PE', $situation->bodyCode());
        self::assertSame('FPE', $situation->versant());
        self::assertSame(450, $situation->increasedIndex());
        self::assertSame('CAS_CIVIL', $situation->retirementRegimeCode());
    }

    public function testCareerStartsAtEntryRank(): void
    {
        // La carrière commence à l'échelon d'entrée : les rangs antérieurs sont exclus.
        $profile = $this->seedProfile(entryRank: 2, echelons: [
            ['rank' => 1, 'increasedIndex' => 395, 'durationMonths' => 12],
            ['rank' => 2, 'increasedIndex' => 420, 'durationMonths' => 24],
            ['rank' => 3, 'increasedIndex' => 450, 'durationMonths' => null],
        ]);

        $steps = $this->factory()->fromProfile($profile)->steps();

        self::assertCount(2, $steps);
        self::assertSame(420, $steps[0]->situation()->increasedIndex());
        self::assertSame(450, $steps[1]->situation()->increasedIndex());
    }

    /**
     * @param list<array{rank: int, increasedIndex: int, durationMonths: int|null}> $echelons
     */
    private function seedProfile(int $entryRank, array $echelons): Profile
    {
        $set = $this->newParameterSet('commit-current', '2026-01-15 10:00:00');
        $corps = $this->newCorps('PE');
        $source = $this->newSource('decret-2017-789');
        $grille = $this->newGrille($corps, 'classe_normale', '2024', '2024-01-01', null, $source, $set);

        $byRank = [];
        foreach ($echelons as $spec) {
            $echelon = new Echelon();
            $echelon->grille = $grille;
            $echelon->rank = $spec['rank'];
            $echelon->grossIndex = $spec['increasedIndex'] + 10;
            $echelon->increasedIndex = $spec['increasedIndex'];
            $echelon->durationMonths = $spec['durationMonths'];
            $this->em->persist($echelon);
            $byRank[$spec['rank']] = $echelon;
        }

        $scheme = new RetirementScheme();
        $scheme->code = 'CAS_CIVIL';
        $scheme->label = 'CAS civil';
        $this->em->persist($scheme);

        $funder = new Funder();
        $funder->code = 'ETAT';
        $funder->label = 'État';
        $this->em->persist($funder);

        $profile = new Profile();
        $profile->body = $corps;
        $profile->grade = 'classe_normale';
        $profile->entryEchelon = $byRank[$entryRank];
        $profile->retirementScheme = $scheme;
        $profile->indemnitaryScheme = null;
        $profile->funder = $funder;
        $profile->status = 'titulaire';
        $profile->referenceCalendar = 'scolaire';
        $this->em->persist($profile);

        $this->em->flush();

        return $profile;
    }

    private function factory(?int $terminalDurationMonths = null): CareerFactory
    {
        $repository = new EchelonRepository($this->em);

        return $terminalDurationMonths === null
            ? new CareerFactory($repository)
            : new CareerFactory($repository, $terminalDurationMonths);
    }
}

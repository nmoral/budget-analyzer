<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Infrastructure\Cost\InvalidSelectionException;
use App\Infrastructure\Cost\ScenarioResultCache;
use App\Infrastructure\Cost\UncomputableViewException;
use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use App\Infrastructure\Repository\ParameterSetRepository;
use App\Infrastructure\Repository\ProfileRepository;
use App\Infrastructure\Repository\ScenarioLineRepository;
use App\Infrastructure\Repository\ScenarioRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API JSON des scénarios (#19) : création et lecture.
 *
 * Mapping JSON manuel (entité → tableau → JsonResponse), sans symfony/serializer.
 */
final class ScenarioController
{
    public function __construct(
        private readonly ScenarioRepository $scenarios,
        private readonly ScenarioLineRepository $lines,
        private readonly ProfileRepository $profiles,
        private readonly ParameterSetRepository $parameterSets,
        private readonly ScenarioResultCache $resultCache,
    ) {
    }

    #[Route('/api/scenarios', name: 'api_scenarios_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return $this->error('Corps JSON invalide.', 422);
        }

        $title = $decoded['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return $this->error('Le champ "title" est requis.', 422);
        }

        $linesRaw = $decoded['lines'] ?? null;
        if (!\is_array($linesRaw) || $linesRaw === []) {
            return $this->error('Au moins une ligne est requise.', 422);
        }

        $version = $decoded['parametersVersion'] ?? null;
        if ($version !== null && !\is_string($version)) {
            return $this->error('"parametersVersion" doit être une chaîne.', 422);
        }

        $parameterSet = $version !== null
            ? $this->parameterSets->findByVersionId($version)
            : $this->parameterSets->latest();
        if ($parameterSet === null) {
            return $this->error('Jeu de paramètres introuvable.', 422);
        }

        $scenario = new Scenario();
        $scenario->title = $title;
        $scenario->provenance = 'simulation';
        $scenario->parameterSet = $parameterSet;

        $lines = [];
        foreach ($linesRaw as $lineRaw) {
            if (!\is_array($lineRaw)) {
                return $this->error('Ligne de scénario invalide.', 422);
            }
            $line = $this->buildLine($scenario, $lineRaw);
            if ($line === null) {
                return $this->error('Ligne de scénario invalide.', 422);
            }
            $lines[] = $line;
        }

        $this->scenarios->save($scenario, ...$lines);
        \assert($scenario->id !== null);

        return new JsonResponse([
            'id' => $scenario->id,
            'parametersVersion' => $parameterSet->versionId,
        ], 201);
    }

    #[Route('/api/scenarios/{id}', name: 'api_scenarios_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $scenario = $this->scenarios->find($id);
        if ($scenario === null) {
            return $this->error('Scénario introuvable.', 404);
        }

        return new JsonResponse($this->toArray($scenario, $this->lines->forScenario($scenario)));
    }

    #[Route('/api/scenarios/{id}/result', name: 'api_scenarios_result', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function result(int $id, Request $request): JsonResponse
    {
        $scenario = $this->scenarios->find($id);
        if ($scenario === null) {
            return $this->error('Scénario introuvable.', 404);
        }

        $horizons = $this->selection($request->query->getString('horizons'), 'year1');
        $views = $this->selection($request->query->getString('views'), 'BUDGETAIRE');

        try {
            $cached = $this->resultCache->get($scenario, $horizons, $views);
        } catch (InvalidSelectionException|UncomputableViewException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $response = new JsonResponse($cached->result());
        $response->headers->set('X-Cache', $cached->hit() ? 'HIT' : 'MISS');

        return $response;
    }

    /**
     * @param array<array-key, mixed> $lineRaw
     */
    private function buildLine(Scenario $scenario, array $lineRaw): ?ScenarioLine
    {
        $profileRef = $lineRaw['profileRef'] ?? null;
        if (!\is_int($profileRef)) {
            return null;
        }
        $profile = $this->profiles->find($profileRef);
        if ($profile === null) {
            return null;
        }

        $volume = $lineRaw['volume'] ?? null;
        if (!\is_int($volume)) {
            return null;
        }

        $recruitmentDateRaw = $lineRaw['recruitmentDate'] ?? null;
        if (!\is_string($recruitmentDateRaw)) {
            return null;
        }
        try {
            $recruitmentDate = new \DateTimeImmutable($recruitmentDateRaw);
        } catch (\Exception) {
            return null;
        }

        $spreadYears = $lineRaw['spreadYears'] ?? 0;
        if (!\is_int($spreadYears)) {
            return null;
        }

        $line = new ScenarioLine();
        $line->scenario = $scenario;
        $line->profile = $profile;
        $line->volume = $volume;
        $line->recruitmentDate = $recruitmentDate;
        $line->spreadYears = $spreadYears;

        return $line;
    }

    /**
     * @param list<ScenarioLine> $lines
     *
     * @return array<string, mixed>
     */
    private function toArray(Scenario $scenario, array $lines): array
    {
        return [
            'id' => $scenario->id,
            'title' => $scenario->title,
            'parametersVersion' => $scenario->parameterSet->versionId,
            'lines' => array_map(static fn (ScenarioLine $line): array => [
                'id' => $line->id,
                'profileRef' => $line->profile->id,
                'volume' => $line->volume,
                'recruitmentDate' => $line->recruitmentDate->format('Y-m-d'),
                'spreadYears' => $line->spreadYears,
            ], $lines),
        ];
    }

    /**
     * Liste de codes depuis un paramètre `?a,b,c` : valeurs dans l'ordre d'apparition,
     * vides ignorées, dédupliquées (R1 : une répétition ne doit doubler ni l'agrégat ni
     * la clé de cache) ; sélection vide → `[$default]`.
     *
     * @return list<string>
     */
    private function selection(string $raw, string $default): array
    {
        $items = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $item): bool => $item !== '',
        )));

        return $items === [] ? [$default] : $items;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}

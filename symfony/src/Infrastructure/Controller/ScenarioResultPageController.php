<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Infrastructure\Cost\ScenarioResultCalculator;
use App\Infrastructure\Repository\ScenarioRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Fiche de résultat web (#20) : page HTML server-side pour un scénario.
 *
 * Le rendu est fait côté serveur via `ScenarioResultCalculator` (aucun appel HTTP
 * interne à l'API). La contrainte de route `\d+` garantit qu'un `{id}` non numérique
 * n'apparie pas la route (→ 404 du framework). Un scénario inconnu rend une page 404
 * lisible explicite (pas de stack trace), quel que soit l'environnement.
 *
 * J1 : socle minimal (titre + version des paramètres). Les montants par vue et horizon
 * arrivent en J2.
 */
final class ScenarioResultPageController
{
    /** @var list<string> */
    private const array DEFAULT_HORIZONS = ['year1'];

    /** @var list<string> */
    private const array DEFAULT_VIEWS = ['BUDGETAIRE'];

    public function __construct(
        private readonly Environment $twig,
        private readonly ScenarioRepository $scenarios,
        private readonly ScenarioResultCalculator $calculator,
    ) {
    }

    #[Route('/scenarios/{id}', name: 'web_scenario_result', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id): Response
    {
        $scenario = $this->scenarios->find($id);
        if ($scenario === null) {
            return new Response(
                $this->twig->render('error/scenario_not_found.html.twig'),
                Response::HTTP_NOT_FOUND,
            );
        }

        $result = $this->calculator->compute($scenario, self::DEFAULT_HORIZONS, self::DEFAULT_VIEWS);

        return new Response($this->twig->render('scenario/result.html.twig', [
            'scenario' => $scenario,
            'result' => $result,
            'parametersVersion' => $result['parametersVersion'],
        ]));
    }
}

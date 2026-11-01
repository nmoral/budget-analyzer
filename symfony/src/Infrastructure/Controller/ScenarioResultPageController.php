<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Infrastructure\Cost\ScenarioResultCalculator;
use App\Infrastructure\Repository\ScenarioLineRepository;
use App\Infrastructure\Repository\ScenarioRepository;
use Symfony\Component\HttpFoundation\Request;
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
 * J2 : les vues (`?views=`, défaut BUDGETAIRE) et horizons (`?horizons=`, défaut
 * year1) demandés découlent de l'URL ; la fiche affiche les montants repris tels
 * quels du service, par vue (côte à côte) et horizon, l'agrégat, et le financeur de
 * chaque ligne (`Profile.funder`). Aucune part de budget (différée #21).
 */
final class ScenarioResultPageController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ScenarioRepository $scenarios,
        private readonly ScenarioLineRepository $lines,
        private readonly ScenarioResultCalculator $calculator,
    ) {
    }

    #[Route('/scenarios/{id}', name: 'web_scenario_result', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request): Response
    {
        $scenario = $this->scenarios->find($id);
        if ($scenario === null) {
            return new Response(
                $this->twig->render('error/scenario_not_found.html.twig'),
                Response::HTTP_NOT_FOUND,
            );
        }

        $views = $this->selection($request->query->getString('views'), 'BUDGETAIRE');
        $horizons = $this->selection($request->query->getString('horizons'), 'year1');
        // Dépliage par nombre (C3) : year1 seul porte le détail (formule, entrées
        // datées, sources en clair). Le contrat d'URL expose `detail=full`.
        $detail = $request->query->getString('detail') === 'full';

        $result = $this->calculator->compute($scenario, $horizons, $views, $detail);

        // Financeur par ligne (C7) : Profile.funder, indexé par identifiant de ligne
        // pour être joint aux lignes du résultat côté template. Aucune part de budget.
        $funders = [];
        foreach ($this->lines->forScenario($scenario) as $line) {
            $funders[(int) $line->id] = $line->profile->funder->label;
        }

        return new Response($this->twig->render('scenario/result.html.twig', [
            'scenario' => $scenario,
            'result' => $result,
            'parametersVersion' => $result['parametersVersion'],
            'funders' => $funders,
            // Hypothèses appliquées (C4/C6) : nommées sur la fiche indépendamment du
            // dépliage par nombre (year1 seul), y compris sur permanent/cohorte allégés.
            'assumptions' => $this->calculator->assumptions($views),
        ]));
    }

    /**
     * Liste de codes depuis un paramètre `?a,b,c` : valeurs dans l'ordre d'apparition,
     * vides ignorées, dédupliquées ; sélection vide → `[$default]`. Même sémantique
     * que l'API résultat (contrat d'URL rejouable commun).
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
}

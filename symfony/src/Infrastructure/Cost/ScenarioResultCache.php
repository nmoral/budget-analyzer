<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Infrastructure\Entity\Scenario;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache du résultat de scénario (#19, J3) autour de `ScenarioResultCalculator`.
 *
 * La clé = f(scénario, versionId du jeu épinglé, horizons triés, vues triées) : deux
 * appels identiques partagent l'entrée (rejouabilité), une sélection ou une version
 * différente a une clé distincte (jamais de cache croisé). Le tri rend l'ordre des
 * horizons/vues indifférent. L'invalidation par ré-import passe par la purge du pool
 * (cf. `app:import`), le versionId d'un import idempotent ne changeant pas.
 */
final class ScenarioResultCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly ScenarioResultCalculator $calculator,
    ) {
    }

    /**
     * @param list<string> $horizons
     * @param list<string> $views
     *
     * @throws InvalidSelectionException  si une vue ou un horizon demandé est inconnu
     * @throws UncomputableViewException si une vue valide n'est pas valorisable (paramètre daté manquant) ; l'erreur n'est pas mise en cache (levée avant la sauvegarde)
     */
    public function get(Scenario $scenario, array $horizons, array $views): CachedScenarioResult
    {
        $item = $this->pool->getItem($this->key($scenario, $horizons, $views));

        if ($item->isHit()) {
            $cached = $item->get();
            \assert(\is_array($cached));
            /** @var array<string, mixed> $cached */

            return new CachedScenarioResult($cached, true);
        }

        $result = $this->calculator->compute($scenario, $horizons, $views);
        $item->set($result);
        $this->pool->save($item);

        return new CachedScenarioResult($result, false);
    }

    /**
     * @param list<string> $horizons
     * @param list<string> $views
     */
    private function key(Scenario $scenario, array $horizons, array $views): string
    {
        $sortedHorizons = $horizons;
        sort($sortedHorizons);

        $sortedViews = $views;
        sort($sortedViews);

        $raw = implode('|', [
            (string) $scenario->id,
            $scenario->parameterSet->versionId,
            implode(',', $sortedHorizons),
            implode(',', $sortedViews),
        ]);

        return 'scenario_result_' . hash('sha256', $raw);
    }
}

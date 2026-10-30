<?php

declare(strict_types=1);

namespace App\Domain\Rule;

use Psr\Container\ContainerInterface;

/**
 * Résolveur de règles d'une famille : retourne l'implémentation correspondant au
 * code demandé.
 *
 * La sélection est déléguée à un service locator (port PSR-11) indexé par code :
 * aucun `match`/`if` sur un code métier ne vit ici. Le locator concret, construit
 * par découverte des services taggés de la famille et indexé par `Rule::code()`,
 * est fourni par la configuration (cf. config/services.yaml) ; le domaine ne
 * dépend que de l'abstraction PSR-11.
 *
 * @template T of Rule
 */
abstract class AbstractRuleResolver
{
    /**
     * @param ContainerInterface $rules implémentations de la famille, indexées par code
     */
    public function __construct(private readonly ContainerInterface $rules)
    {
    }

    /**
     * Nom métier de la famille, utilisé dans le message d'erreur.
     */
    abstract protected function family(): string;

    /**
     * @return T
     *
     * @throws UnknownRuleCodeException si aucune implémentation n'est enregistrée pour ce code
     */
    public function resolve(string $code): Rule
    {
        if (!$this->rules->has($code)) {
            throw UnknownRuleCodeException::forFamily($this->family(), $code);
        }

        /** @var T $rule */
        $rule = $this->rules->get($code);

        return $rule;
    }
}

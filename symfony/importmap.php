<?php

declare(strict_types=1);

/**
 * Carte d'imports (AssetMapper) : point d'entrée `app` + socle Stimulus.
 *
 * Les dépendances distantes (`@hotwired/stimulus`) sont figées dans `assets/vendor/`
 * par `bin/console importmap:install` (versionnées), donc servies localement : aucun
 * accès réseau au rendu ni au build. Un vrai contrôleur Stimulus arrive en J3.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
];

<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Vérifie l'empreinte SHA-256 du brut archivé d'un fichier contre son manifeste (C4) :
 * `sha256(data/sources/<manifest.raw>) == manifest.sha256`.
 */
final class ManifestVerifier
{
    public function verify(string $dataDir, string $file): void
    {
        $manifestPath = sprintf('%s/manifests/%s.json', $dataDir, $file);
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            throw new ManifestMismatchException(sprintf('manifeste introuvable pour `%s`', $file));
        }

        /** @var mixed $manifest */
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new ManifestMismatchException(sprintf('manifeste invalide pour `%s`', $file));
        }

        $rawName = $manifest['raw'] ?? null;
        $expected = $manifest['sha256'] ?? null;
        if (!is_string($rawName) || !is_string($expected)) {
            throw new ManifestMismatchException(sprintf('manifeste incomplet pour `%s`', $file));
        }

        $sourcePath = sprintf('%s/sources/%s', $dataDir, $rawName);

        if (!is_file($sourcePath)) {
            throw new ManifestMismatchException(
                sprintf('brut introuvable `%s` pour `%s`', $rawName, $file),
            );
        }

        $actual = hash_file('sha256', $sourcePath);
        if ($actual !== $expected) {
            throw new ManifestMismatchException(sprintf(
                'empreinte SHA-256 divergente pour `%s` (fichier `%s`)',
                $rawName,
                $file,
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Profile;

use App\Infrastructure\Entity\Profile as ProfileEntity;
use App\Infrastructure\Profile\DomainProfileFactory;
use PHPUnit\Framework\TestCase;

/**
 * Garde de frontière : l'entité Doctrine autorise un régime indemnitaire nul en
 * base, mais le moteur résout une règle indemnitaire pour chaque profil. L'adapter
 * refuse donc un profil sans régime indemnitaire (frontière, pas de code silencieux).
 */
final class DomainProfileFactoryGuardTest extends TestCase
{
    public function testRejectsEntityWithoutIndemnitaryScheme(): void
    {
        $entity = new ProfileEntity();
        $entity->indemnitaryScheme = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('régime indemnitaire');

        (new DomainProfileFactory())->fromEntity($entity);
    }
}

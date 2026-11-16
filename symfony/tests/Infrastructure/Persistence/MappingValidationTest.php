<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exemple C1.1 (volet « The mapping files are correct ») : le mapping ORM de
 * toutes les entités de persistance est valide et toutes les entités de la spec
 * sont effectivement mappées. Ce volet ne dépend pas de l'état de la base.
 *
 * Le volet « database schema is in sync » (base migrée) est vérifié par la
 * commande d'intégration `doctrine:migrations:migrate` + `doctrine:schema:validate`.
 */
final class MappingValidationTest extends KernelTestCase
{
    /**
     * Entités de la spec (section Modèle de données) + Jeu de paramètres.
     */
    private const array EXPECTED_ENTITIES = [
        'App\Infrastructure\Entity\Source',
        'App\Infrastructure\Entity\DatedParameter',
        'App\Infrastructure\Entity\Corps',
        'App\Infrastructure\Entity\Grille',
        'App\Infrastructure\Entity\Echelon',
        'App\Infrastructure\Entity\PrimeRate',
        'App\Infrastructure\Entity\RetirementScheme',
        'App\Infrastructure\Entity\Funder',
        'App\Infrastructure\Entity\Profile',
        'App\Infrastructure\Entity\Scenario',
        'App\Infrastructure\Entity\ScenarioLine',
        'App\Infrastructure\Entity\ParameterSet',
        'App\Infrastructure\Entity\ReferenceBudget',
    ];

    public function testAllSpecEntitiesAreMapped(): void
    {
        $mapped = array_map(
            static fn (object $metadata): string => $metadata->getName(),
            $this->entityManager()->getMetadataFactory()->getAllMetadata(),
        );

        sort($mapped);
        $expected = self::EXPECTED_ENTITIES;
        sort($expected);

        self::assertSame($expected, $mapped);
    }

    public function testMappingIsValid(): void
    {
        $validator = new SchemaValidator($this->entityManager());
        $errors = $validator->validateMapping();

        self::assertSame(
            [],
            $errors,
            "The mapping files are not correct:\n" . print_r($errors, true),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}

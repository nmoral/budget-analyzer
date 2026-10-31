<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Source « en clair complète » (#65) : ajout de l'édition/année (`edition`) et de
 * la localisation (`locator` : page/tableau/article) sur la table `source`.
 *
 * Les deux colonnes sont NOT NULL. Pour ne pas casser une base déjà importée, elles
 * sont ajoutées avec un défaut transitoire (lignes existantes renseignées), puis le
 * défaut est retiré : les prochains `INSERT` (via l'ORM) fournissent la valeur, et
 * un ré-import `app:import` réécrit les valeurs réelles depuis `data/sources.json`.
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Source enrichie (#65) : colonnes edition et locator (NOT NULL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE source ADD edition VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE source ADD locator VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE source ALTER edition DROP DEFAULT');
        $this->addSql('ALTER TABLE source ALTER locator DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source DROP edition');
        $this->addSql('ALTER TABLE source DROP locator');
    }
}

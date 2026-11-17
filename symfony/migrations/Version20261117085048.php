<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Référentiel des profils par la chaîne d'import (#62) : `profile` gagne `code`,
 * `label`, `source_id` et `parameter_set_id` (NOT NULL, code unique par jeu de
 * paramètres).
 *
 * Les profils proviennent désormais du seul `app:import` : des profils saisis à la
 * main ne peuvent recevoir ni code ni jeu de paramètres. La migration refuse donc de
 * s'appliquer tant qu'il en reste, sans rien supprimer : c'est à l'exploitant de les
 * purger.
 */
final class Version20261117085048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils importés : code, label, source et jeu de paramètres sur profile (code unique par jeu).';
    }

    public function up(Schema $schema): void
    {
        $existing = $this->connection->fetchOne('SELECT count(*) FROM profile');
        $this->abortIf(
            is_numeric($existing) && (int) $existing > 0,
            sprintf(
                '%d profil(s) non importé(s) présent(s) dans la table profile, à purger avant de migrer '
                . '(ainsi que les lignes de scénario qui les référencent) : les profils proviennent '
                . 'désormais de app:import. Aucune donnée n\'a été supprimée.',
                is_numeric($existing) ? (int) $existing : 0,
            ),
        );

        $this->addSql('ALTER TABLE profile ADD code VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE profile ADD label VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE profile ADD source_id INT NOT NULL');
        $this->addSql('ALTER TABLE profile ADD parameter_set_id INT NOT NULL');
        $this->addSql('ALTER TABLE profile ADD CONSTRAINT FK_8157AA0F953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE profile ADD CONSTRAINT FK_8157AA0FF48507E9 FOREIGN KEY (parameter_set_id) REFERENCES parameter_set (id) NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX uniq_profile_parameter_set_code ON profile (parameter_set_id, code)');
        $this->addSql('CREATE INDEX IDX_8157AA0F953C1C61 ON profile (source_id)');
        $this->addSql('CREATE INDEX IDX_8157AA0FF48507E9 ON profile (parameter_set_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile DROP CONSTRAINT FK_8157AA0F953C1C61');
        $this->addSql('ALTER TABLE profile DROP CONSTRAINT FK_8157AA0FF48507E9');
        $this->addSql('DROP INDEX uniq_profile_parameter_set_code');
        $this->addSql('DROP INDEX IDX_8157AA0F953C1C61');
        $this->addSql('DROP INDEX IDX_8157AA0FF48507E9');
        $this->addSql('ALTER TABLE profile DROP code');
        $this->addSql('ALTER TABLE profile DROP label');
        $this->addSql('ALTER TABLE profile DROP source_id');
        $this->addSql('ALTER TABLE profile DROP parameter_set_id');
    }
}

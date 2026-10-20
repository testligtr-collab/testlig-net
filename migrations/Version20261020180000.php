<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records the last person who wrote news text. Existing rows keep the original author.
 */
final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the last news content editor and backfill it from the author.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_articles ADD last_content_editor_id BINARY(16) DEFAULT NULL');
        $this->addSql('UPDATE news_articles SET last_content_editor_id = author_id WHERE last_content_editor_id IS NULL');
        $this->addSql('ALTER TABLE news_articles MODIFY last_content_editor_id BINARY(16) NOT NULL');
        $this->addSql('CREATE INDEX idx_news_article_last_editor ON news_articles (last_content_editor_id)');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT FK_NEWS_ARTICLE_LAST_EDITOR FOREIGN KEY (last_content_editor_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_articles DROP FOREIGN KEY FK_NEWS_ARTICLE_LAST_EDITOR');
        $this->addSql('DROP INDEX idx_news_article_last_editor ON news_articles');
        $this->addSql('ALTER TABLE news_articles DROP last_content_editor_id');
    }
}

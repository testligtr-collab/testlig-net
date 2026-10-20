<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Text news categories and articles. Image storage stays outside this table.
 */
final class Version20261020143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add text news categories and articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE news_categories (
            id BINARY(16) NOT NULL,
            created_by_id BINARY(16) DEFAULT NULL,
            name VARCHAR(80) NOT NULL,
            slug VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_news_category_slug (slug),
            INDEX idx_news_category_created_by (created_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE news_articles (
            id BINARY(16) NOT NULL,
            category_id BINARY(16) DEFAULT NULL,
            author_id BINARY(16) NOT NULL,
            submitted_by_id BINARY(16) DEFAULT NULL,
            published_by_id BINARY(16) DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            slug VARCHAR(180) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            body LONGTEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            submitted_at DATETIME DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_news_article_slug (slug),
            INDEX idx_news_article_status_published (status, published_at),
            INDEX idx_news_article_category (category_id),
            INDEX idx_news_article_author (author_id),
            INDEX idx_news_article_submitted_by (submitted_by_id),
            INDEX idx_news_article_published_by (published_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE news_categories ADD CONSTRAINT FK_NEWS_CAT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT FK_NEWS_ARTICLE_CATEGORY FOREIGN KEY (category_id) REFERENCES news_categories (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT FK_NEWS_ARTICLE_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT FK_NEWS_ARTICLE_SUBMITTED_BY FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT FK_NEWS_ARTICLE_PUBLISHED_BY FOREIGN KEY (published_by_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_articles DROP FOREIGN KEY FK_NEWS_ARTICLE_CATEGORY');
        $this->addSql('ALTER TABLE news_articles DROP FOREIGN KEY FK_NEWS_ARTICLE_AUTHOR');
        $this->addSql('ALTER TABLE news_articles DROP FOREIGN KEY FK_NEWS_ARTICLE_SUBMITTED_BY');
        $this->addSql('ALTER TABLE news_articles DROP FOREIGN KEY FK_NEWS_ARTICLE_PUBLISHED_BY');
        $this->addSql('ALTER TABLE news_categories DROP FOREIGN KEY FK_NEWS_CAT_CREATED_BY');
        $this->addSql('DROP TABLE news_articles');
        $this->addSql('DROP TABLE news_categories');
    }
}

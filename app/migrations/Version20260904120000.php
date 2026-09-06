<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Foundation for 4-way historical-spelling alignment (SV1657 / SV / SVGBS / HSV):
 *
 *  - translations.is_alignment_pivot: marks SV1657 as the pivot for the new
 *    spelling-normalisation alignment pipeline. Deliberately separate from
 *    source_lang_authority (still SV/Jongbloed), which anchors the unrelated
 *    Hebrew/Greek word_links propagation -- the two pivots serve different
 *    algorithms and must not be conflated.
 *  - inter_translation_links.method: extended with the HistoricalAlignmentService
 *    method vocabulary (anchor, window, compound, phrase, moved, one_to_many,
 *    synonym, particle_drop, prefix_drop), alongside the existing pairwise
 *    methods (auto_source_pivot, auto_sequence, auto_positional, manual, manual_empty).
 *  - inter_translation_links.score: float 0-1 confidence for the new pipeline,
 *    separate from the legacy 0-100 `confidence` column.
 *  - inter_translation_links.updated_at / updated_by: last status-change
 *    tracking, set on every write including re-confirming an existing link.
 *  - review_lock: per-scope (verse/chapter/book) soft locking for the review UI.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SV1657 alignment pivot flag, extend inter_translation_links for historical alignment, add review_lock table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translations ADD COLUMN IF NOT EXISTS is_alignment_pivot BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("UPDATE translations SET is_alignment_pivot = TRUE WHERE code = 'SV1657'");

        $this->addSql('ALTER TABLE inter_translation_links DROP CONSTRAINT IF EXISTS inter_translation_links_method_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE inter_translation_links ADD CONSTRAINT inter_translation_links_method_check
                CHECK (method IN (
                    'auto_source_pivot', 'auto_sequence', 'auto_positional', 'manual', 'manual_empty',
                    'anchor', 'window', 'compound', 'phrase', 'moved', 'one_to_many', 'synonym',
                    'particle_drop', 'prefix_drop'
                ))
            SQL);

        $this->addSql('ALTER TABLE inter_translation_links ADD COLUMN IF NOT EXISTS score REAL CHECK (score BETWEEN 0 AND 1)');
        $this->addSql('ALTER TABLE inter_translation_links ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ');
        $this->addSql('ALTER TABLE inter_translation_links ADD COLUMN IF NOT EXISTS updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL');
        $this->addSql("UPDATE inter_translation_links SET score = 1.0 WHERE method IN ('manual', 'manual_empty') AND score IS NULL");

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS review_lock (
                id          SERIAL       PRIMARY KEY,
                scope_type  VARCHAR(10)  NOT NULL CHECK (scope_type IN ('verse', 'chapter', 'book')),
                scope_id    VARCHAR(20)  NOT NULL,
                user_id     INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                locked_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                expires_at  TIMESTAMPTZ  NOT NULL,
                UNIQUE (scope_type, scope_id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_review_lock_expires ON review_lock (expires_at)');

        $this->addSql("COMMENT ON COLUMN translations.is_alignment_pivot IS 'TRUE for SV1657: pivot text for the 4-way historical-spelling alignment pipeline (separate from source_lang_authority)'");
        $this->addSql("COMMENT ON TABLE review_lock IS 'Soft per-scope lock for the alignment review UI. Expired rows (expires_at < NOW()) are treated as free on the next lock attempt.'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS review_lock');

        $this->addSql('ALTER TABLE inter_translation_links DROP COLUMN IF EXISTS updated_by');
        $this->addSql('ALTER TABLE inter_translation_links DROP COLUMN IF EXISTS updated_at');
        $this->addSql('ALTER TABLE inter_translation_links DROP COLUMN IF EXISTS score');

        $this->addSql('ALTER TABLE inter_translation_links DROP CONSTRAINT IF EXISTS inter_translation_links_method_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE inter_translation_links ADD CONSTRAINT inter_translation_links_method_check
                CHECK (method IN (
                    'auto_source_pivot', 'auto_sequence', 'auto_positional', 'manual', 'manual_empty'
                ))
            SQL);

        $this->addSql('ALTER TABLE translations DROP COLUMN IF EXISTS is_alignment_pivot');
    }
}

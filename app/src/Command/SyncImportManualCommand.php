<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importeert manuele links van productie naar de lokale dev-database.
 * Bestaande links worden nooit overschreven (ON CONFLICT DO NOTHING).
 *
 * Verwacht in <input-dir>:
 *   manual_word_links.csv
 *   manual_empty_links.csv
 *   manual_itl.csv
 */
#[AsCommand(
    name: 'app:sync:import-manual',
    description: 'Import manual links from prod CSV into local dev database',
)]
class SyncImportManualCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input-dir', null, InputOption::VALUE_REQUIRED,
                'Directory containing the CSV files', './sync')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Parse and report counts without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dir    = rtrim((string) $input->getOption('input-dir'), '/\\');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Import manual links' . ($dryRun ? ' (dry run)' : ''));

        $required = ['manual_word_links.csv', 'manual_empty_links.csv', 'manual_itl.csv'];
        foreach ($required as $file) {
            if (!file_exists("{$dir}/{$file}")) {
                $io->error("Missing file: {$dir}/{$file}");
                return Command::FAILURE;
            }
        }

        $this->connection->beginTransaction();
        try {
            $inserted = 0;

            // ── 1. word_links + link_confidence ──────────────────────────────
            $rows = $this->readCsv("{$dir}/manual_word_links.csv");
            foreach ($rows as $row) {
                // Upsert word_link matched on (source pair + translation_word)
                $existing = $this->connection->fetchOne(
                    "SELECT id FROM word_links
                     WHERE (hebrew_word_id IS NOT DISTINCT FROM :he)
                       AND (greek_word_id  IS NOT DISTINCT FROM :gr)
                       AND translation_word_id = :tw",
                    [
                        'he' => $row['hebrew_word_id'] !== '' ? (int) $row['hebrew_word_id'] : null,
                        'gr' => $row['greek_word_id']  !== '' ? (int) $row['greek_word_id']  : null,
                        'tw' => (int) $row['translation_word_id'],
                    ]
                );

                if ($existing === false) {
                    // word_link bestaat nog niet in dev
                    if (!$dryRun) {
                        $this->connection->executeStatement(
                            "INSERT INTO word_links (source_language, hebrew_word_id, greek_word_id, translation_word_id)
                             VALUES (:sl, :he, :gr, :tw)",
                            [
                                'sl' => $row['source_language'],
                                'he' => $row['hebrew_word_id'] !== '' ? (int) $row['hebrew_word_id'] : null,
                                'gr' => $row['greek_word_id']  !== '' ? (int) $row['greek_word_id']  : null,
                                'tw' => (int) $row['translation_word_id'],
                            ]
                        );
                        $existing = (int) $this->connection->lastInsertId();
                    }
                    $inserted++;
                }

                if (!$dryRun && $existing) {
                    // created_by_user_id is intentionally not synced: it's an FK to
                    // the local users table, and prod/dev user IDs don't correspond
                    // to the same accounts.
                    $this->connection->executeStatement(
                        "INSERT INTO link_confidence (link_id, method, score, created_at, notes)
                         VALUES (:id, :method, :score, :created_at, :notes)
                         ON CONFLICT (link_id, method) DO NOTHING",
                        [
                            'id'         => (int) $existing,
                            'method'     => $row['method'],
                            'score'      => $row['score'] !== '' ? (float) $row['score'] : null,
                            'created_at' => $row['created_at'] ?: null,
                            'notes'      => $row['notes'] ?: null,
                        ]
                    );
                }
            }
            $io->text(sprintf('  word_links:  %d new', $inserted));

            // ── 2. manual_empty_links ─────────────────────────────────────────
            $rows = $this->readCsv("{$dir}/manual_empty_links.csv");
            $emptyInserted = 0;
            foreach ($rows as $row) {
                if (!$dryRun) {
                    $affected = $this->connection->executeStatement(
                        "INSERT INTO manual_empty_links
                             (source_language, hebrew_word_id, greek_word_id, translation_id, created_at, notes)
                         VALUES (:sl, :he, :gr, :tid, :created_at, :notes)
                         ON CONFLICT DO NOTHING",
                        [
                            'sl'         => $row['source_language'],
                            'he'         => $row['hebrew_word_id'] !== '' ? (int) $row['hebrew_word_id'] : null,
                            'gr'         => $row['greek_word_id']  !== '' ? (int) $row['greek_word_id']  : null,
                            'tid'        => (int) $row['translation_id'],
                            'created_at' => $row['created_at'] ?: null,
                            'notes'      => $row['notes'] ?: null,
                        ]
                    );
                    $emptyInserted += $affected;
                } else {
                    $emptyInserted++;
                }
            }
            $io->text(sprintf('  empty_links: %d new%s', $emptyInserted, $dryRun ? ' (would insert)' : ''));

            // ── 3. inter_translation_links ────────────────────────────────────
            // word_a_id/word_b_id in the CSV are natural keys (see
            // SyncExportManualCommand), not the exporting database's raw
            // translation_words IDs — resolve each to this database's own ID.
            $rows = $this->readCsv("{$dir}/manual_itl.csv");
            $itlInserted = 0;
            $itlSkipped  = 0;
            foreach ($rows as $row) {
                $wa = $this->resolveTranslationWordId(
                    $row['word_a_translation'], (int) $row['word_a_book_id'],
                    (int) $row['word_a_chapter'], (int) $row['word_a_verse'],
                    (int) $row['word_a_word_position']
                );
                $wb = $this->resolveTranslationWordId(
                    $row['word_b_translation'], (int) $row['word_b_book_id'],
                    (int) $row['word_b_chapter'], (int) $row['word_b_verse'],
                    (int) $row['word_b_word_position']
                );

                if ($wa === null || $wb === null) {
                    // Referenced word doesn't exist locally (e.g. that
                    // translation hasn't been ingested here) — skip rather
                    // than fail the whole import.
                    $itlSkipped++;
                    continue;
                }

                if (!$dryRun) {
                    $affected = $this->connection->executeStatement(
                        "INSERT INTO inter_translation_links (word_a_id, word_b_id, method, confidence)
                         VALUES (:wa, :wb, :method, :conf)
                         ON CONFLICT (word_a_id, word_b_id) DO NOTHING",
                        [
                            'wa'     => $wa,
                            'wb'     => $wb,
                            'method' => $row['method'],
                            'conf'   => (int) $row['confidence'],
                        ]
                    );
                    $itlInserted += $affected;
                } else {
                    $itlInserted++;
                }
            }
            $io->text(sprintf('  itl_links:   %d new%s', $itlInserted, $dryRun ? ' (would insert)' : ''));
            if ($itlSkipped > 0) {
                $io->warning(sprintf(
                    '  itl_links:   %d skipped (referenced translation word not found locally)',
                    $itlSkipped
                ));
            }

            if (!$dryRun) {
                $this->connection->commit();
            } else {
                $this->connection->rollBack();
            }

        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run complete — no changes written.' : 'Import complete.');
        return Command::SUCCESS;
    }

    /**
     * Resolves a translation word's natural key (translation code, book,
     * chapter, verse, word position) to this database's own
     * translation_words.id. Returns null if the word doesn't exist locally.
     */
    private function resolveTranslationWordId(
        string $translationCode,
        int $bookId,
        int $chapter,
        int $verse,
        int $wordPosition,
    ): ?int {
        $id = $this->connection->fetchOne(
            "SELECT tw.id
             FROM translation_words tw
             JOIN translation_verses tv ON tv.id = tw.verse_id
             JOIN translations tr ON tr.id = tv.translation_id
             WHERE tr.code = :code
               AND tv.book_id = :book_id
               AND tv.chapter = :chapter
               AND tv.verse = :verse
               AND tw.word_position = :word_position",
            [
                'code'          => $translationCode,
                'book_id'       => $bookId,
                'chapter'       => $chapter,
                'verse'         => $verse,
                'word_position' => $wordPosition,
            ]
        );

        return $id !== false ? (int) $id : null;
    }

    /**
     * Streams the CSV row by row instead of loading it fully into memory —
     * the equivalent export can hold hundreds of thousands of rows.
     */
    private function readCsv(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $headers = fgetcsv($fh);
        while (($line = fgetcsv($fh)) !== false) {
            yield array_combine($headers, $line);
        }
        fclose($fh);
    }
}

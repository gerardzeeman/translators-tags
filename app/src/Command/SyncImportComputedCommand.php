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
 * Importeert berekende links van dev naar de productie-database.
 *
 * Beschermingsregels:
 *   - Een berekende link wordt NIET ingevoegd als er al een manuele link
 *     bestaat voor hetzelfde (bronwoord, doelwoord)-paar op prod.
 *   - Bestaande berekende links worden vervangen door de dev-versie,
 *     maar alleen als er geen manuele link is voor dat bronwoord.
 *   - Manuele links (method='manual') worden nooit aangeraakt.
 *
 * Verwacht in <input-dir>:
 *   computed_word_links.csv
 *   computed_itl.csv
 */
#[AsCommand(
    name: 'app:sync:import-computed',
    description: 'Import computed links from dev CSV into prod database (manual links are protected)',
)]
class SyncImportComputedCommand extends Command
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
                'Report what would change without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dir    = rtrim((string) $input->getOption('input-dir'), '/\\');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Import computed links (prod)' . ($dryRun ? ' — DRY RUN' : ''));

        foreach (['computed_word_links.csv', 'computed_itl.csv'] as $file) {
            if (!file_exists("{$dir}/{$file}")) {
                $io->error("Missing file: {$dir}/{$file}");
                return Command::FAILURE;
            }
        }

        $this->connection->beginTransaction();
        try {
            [$inserted, $skipped, $replaced] = $this->importWordLinks($io, $dir, $dryRun);
            $io->text(sprintf(
                '  word_links:  %d inserted, %d replaced, %d skipped (manual guard)',
                $inserted, $replaced, $skipped
            ));

            [$itlInserted, $itlSkipped, $itlReplaced] = $this->importItl($io, $dir, $dryRun);
            $io->text(sprintf(
                '  itl_links:   %d inserted, %d replaced, %d skipped (manual guard)',
                $itlInserted, $itlReplaced, $itlSkipped
            ));

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

    // ── word_links ────────────────────────────────────────────────────────────

    private function importWordLinks(SymfonyStyle $io, string $dir, bool $dryRun): array
    {
        $rows     = $this->readCsv("{$dir}/computed_word_links.csv");
        $inserted = $skipped = $replaced = 0;

        foreach ($rows as $row) {
            $he = $row['hebrew_word_id'] !== '' ? (int) $row['hebrew_word_id'] : null;
            $gr = $row['greek_word_id']  !== '' ? (int) $row['greek_word_id']  : null;
            $tw = (int) $row['translation_word_id'];

            // Bestaat er al een manuele link voor dit (bronwoord, doelwoord)?
            $hasManual = (bool) $this->connection->fetchOne(
                "SELECT 1
                 FROM word_links wl
                 JOIN link_confidence lc ON lc.link_id = wl.id AND lc.method = 'manual'
                 WHERE (wl.hebrew_word_id IS NOT DISTINCT FROM :he)
                   AND (wl.greek_word_id  IS NOT DISTINCT FROM :gr)
                   AND wl.translation_word_id = :tw",
                ['he' => $he, 'gr' => $gr, 'tw' => $tw]
            );

            if ($hasManual) {
                $skipped++;
                continue;
            }

            // Zoek bestaande berekende link voor dit paar
            $existingId = $this->connection->fetchOne(
                "SELECT wl.id
                 FROM word_links wl
                 WHERE (wl.hebrew_word_id IS NOT DISTINCT FROM :he)
                   AND (wl.greek_word_id  IS NOT DISTINCT FROM :gr)
                   AND wl.translation_word_id = :tw",
                ['he' => $he, 'gr' => $gr, 'tw' => $tw]
            );

            if ($existingId !== false) {
                // Vervang berekende confidence (score kan gewijzigd zijn)
                if (!$dryRun) {
                    $this->connection->executeStatement(
                        "INSERT INTO link_confidence (link_id, method, score)
                         VALUES (:id, :method, :score)
                         ON CONFLICT (link_id, method) DO UPDATE SET score = EXCLUDED.score",
                        [
                            'id'     => (int) $existingId,
                            'method' => $row['method'],
                            'score'  => $row['score'] !== '' ? (float) $row['score'] : null,
                        ]
                    );
                }
                $replaced++;
                continue;
            }

            // Nieuwe link invoegen
            if (!$dryRun) {
                $this->connection->executeStatement(
                    "INSERT INTO word_links (source_language, hebrew_word_id, greek_word_id, translation_word_id)
                     VALUES (:sl, :he, :gr, :tw)",
                    ['sl' => $row['source_language'], 'he' => $he, 'gr' => $gr, 'tw' => $tw]
                );
                $newId = (int) $this->connection->lastInsertId();

                $this->connection->executeStatement(
                    "INSERT INTO link_confidence (link_id, method, score)
                     VALUES (:id, :method, :score)
                     ON CONFLICT (link_id, method) DO UPDATE SET score = EXCLUDED.score",
                    [
                        'id'     => $newId,
                        'method' => $row['method'],
                        'score'  => $row['score'] !== '' ? (float) $row['score'] : null,
                    ]
                );
            }
            $inserted++;
        }

        return [$inserted, $skipped, $replaced];
    }

    // ── inter_translation_links ───────────────────────────────────────────────

    private function importItl(SymfonyStyle $io, string $dir, bool $dryRun): array
    {
        $rows     = $this->readCsv("{$dir}/computed_itl.csv");
        $inserted = $skipped = $replaced = 0;

        foreach ($rows as $row) {
            $wa = (int) $row['word_a_id'];
            $wb = (int) $row['word_b_id'];

            // Bestaat er al een manuele ITL-link voor dit paar?
            $hasManual = (bool) $this->connection->fetchOne(
                "SELECT 1 FROM inter_translation_links
                 WHERE word_a_id = :wa AND word_b_id = :wb
                   AND method IN ('manual', 'manual_empty')",
                ['wa' => $wa, 'wb' => $wb]
            );

            if ($hasManual) {
                $skipped++;
                continue;
            }

            // Verwijder bestaande berekende link voor dit paar en vervang
            $existed = (bool) $this->connection->fetchOne(
                "SELECT 1 FROM inter_translation_links WHERE word_a_id = :wa AND word_b_id = :wb",
                ['wa' => $wa, 'wb' => $wb]
            );

            if (!$dryRun) {
                if ($existed) {
                    $this->connection->executeStatement(
                        "DELETE FROM inter_translation_links WHERE word_a_id = :wa AND word_b_id = :wb",
                        ['wa' => $wa, 'wb' => $wb]
                    );
                }
                $this->connection->executeStatement(
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
            }

            $existed ? $replaced++ : $inserted++;
        }

        return [$inserted, $skipped, $replaced];
    }

    // ── CSV helper ────────────────────────────────────────────────────────────

    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $headers = fgetcsv($fh);
        $rows    = [];
        while (($line = fgetcsv($fh)) !== false) {
            $rows[] = array_combine($headers, $line);
        }
        fclose($fh);

        return $rows;
    }
}

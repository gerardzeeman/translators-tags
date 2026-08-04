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
 * Exporteert alle manuele links naar CSV-bestanden.
 * Draait op de productie-database (via SSH-tunnel of rechtstreeks).
 *
 * Uitvoer:
 *   <output-dir>/manual_word_links.csv
 *   <output-dir>/manual_empty_links.csv
 *   <output-dir>/manual_itl.csv
 */
#[AsCommand(
    name: 'app:sync:export-manual',
    description: 'Export manual links to CSV for sync to dev',
)]
class SyncExportManualCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output-dir', null, InputOption::VALUE_REQUIRED,
            'Directory where CSV files are written', './sync'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $dir = rtrim((string) $input->getOption('output-dir'), '/\\');

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $io->error("Cannot create output directory: {$dir}");
            return Command::FAILURE;
        }

        $io->title('Export manual links');

        // ── 1. word_links + link_confidence (method = manual) ────────────────
        $wordLinksFile = "{$dir}/manual_word_links.csv";
        $count = $this->exportQuery(
            "SELECT
                wl.id,
                wl.source_language,
                wl.hebrew_word_id,
                wl.greek_word_id,
                wl.translation_word_id,
                lc.method,
                lc.score,
                lc.created_at,
                lc.notes
             FROM word_links wl
             JOIN link_confidence lc ON lc.link_id = wl.id
             WHERE lc.method = 'manual'
             ORDER BY wl.id",
            $wordLinksFile
        );
        $io->text(sprintf('  word_links:    %d rows → %s', $count, $wordLinksFile));

        // ── 2. manual_empty_links ─────────────────────────────────────────────
        $emptyFile = "{$dir}/manual_empty_links.csv";
        $count = $this->exportQuery(
            "SELECT id, source_language, hebrew_word_id, greek_word_id,
                    translation_id, created_at, notes
             FROM manual_empty_links
             ORDER BY id",
            $emptyFile
        );
        $io->text(sprintf('  empty_links:   %d rows → %s', $count, $emptyFile));

        // ── 3. inter_translation_links (manual + manual_empty) ────────────────
        $itlFile = "{$dir}/manual_itl.csv";
        $count = $this->exportQuery(
            "SELECT id, word_a_id, word_b_id, method, confidence, created_at
             FROM inter_translation_links
             WHERE method IN ('manual', 'manual_empty')
             ORDER BY id",
            $itlFile
        );
        $io->text(sprintf('  itl_links:     %d rows → %s', $count, $itlFile));

        $io->success('Export complete.');
        return Command::SUCCESS;
    }

    /**
     * Streams query results straight to CSV instead of buffering the whole
     * result set in a PHP array, which exhausts memory on large tables.
     */
    private function exportQuery(string $sql, string $path): int
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open file for writing: {$path}");
        }

        $count      = 0;
        $headerDone = false;
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            if (!$headerDone) {
                fputcsv($fh, array_keys($row));
                $headerDone = true;
            }
            fputcsv($fh, $row);
            $count++;
        }

        fclose($fh);
        return $count;
    }
}

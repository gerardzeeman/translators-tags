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
 * Exporteert berekende (niet-manuele) links naar CSV.
 * Draait op de dev-database na een ingest + align-run.
 *
 * Uitvoer:
 *   <output-dir>/computed_word_links.csv
 *   <output-dir>/computed_itl.csv
 */
#[AsCommand(
    name: 'app:sync:export-computed',
    description: 'Export computed (non-manual) links to CSV for sync to prod',
)]
class SyncExportComputedCommand extends Command
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

        $io->title('Export computed links');

        // ── 1. word_links + link_confidence (method != manual) ───────────────
        $wordLinksFile = "{$dir}/computed_word_links.csv";
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                wl.source_language,
                wl.hebrew_word_id,
                wl.greek_word_id,
                wl.translation_word_id,
                lc.method,
                lc.score
             FROM word_links wl
             JOIN link_confidence lc ON lc.link_id = wl.id
             WHERE lc.method != 'manual'
             ORDER BY wl.id, lc.method"
        );
        $this->writeCsv($wordLinksFile, $rows);
        $io->text(sprintf('  word_links:  %d rows → %s', count($rows), $wordLinksFile));

        // ── 2. inter_translation_links (auto methods) ─────────────────────────
        $itlFile = "{$dir}/computed_itl.csv";
        $rows = $this->connection->fetchAllAssociative(
            "SELECT word_a_id, word_b_id, method, confidence
             FROM inter_translation_links
             WHERE method NOT IN ('manual', 'manual_empty')
             ORDER BY word_a_id, word_b_id"
        );
        $this->writeCsv($itlFile, $rows);
        $io->text(sprintf('  itl_links:   %d rows → %s', count($rows), $itlFile));

        $io->success('Export complete.');
        return Command::SUCCESS;
    }

    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open file for writing: {$path}");
        }

        if (!empty($rows)) {
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fh, $row);
            }
        }

        fclose($fh);
    }
}

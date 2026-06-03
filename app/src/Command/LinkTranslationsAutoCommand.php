<?php

namespace App\Command;

use App\Repository\LinkingRepository;
use App\Repository\TranslationRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:link:translations:auto',
    description: 'Auto-link words across translation pairs in the same family (SV↔HSV, etc.)',
)]
class LinkTranslationsAutoCommand extends Command
{
    public function __construct(
        private readonly Connection             $connection,
        private readonly LinkingRepository     $linkingRepo,
        private readonly TranslationRepository $translationRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,  'Do not write to DB')
            ->addOption('reset',   null, InputOption::VALUE_NONE,  'Delete existing auto-links before re-running')
            ->addOption('family',  null, InputOption::VALUE_OPTIONAL, 'Only process this family (e.g. SV)', null)
            ->addOption('book',    null, InputOption::VALUE_OPTIONAL, 'Only process this book USFM code', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $reset  = (bool) $input->getOption('reset');
        $family = $input->getOption('family');
        $book   = $input->getOption('book');

        $io->title('Auto-link translation pairs');

        $pairs = $this->linkingRepo->fetchTranslationPairs();
        if ($family) {
            $pairs = array_filter($pairs, fn($p) => $p['family'] === $family);
        }

        if (empty($pairs)) {
            $io->warning('No translation pairs found.');
            return Command::SUCCESS;
        }

        foreach ($pairs as $pair) {
            $io->section(sprintf('%s ↔ %s (family: %s)', $pair['code_a'], $pair['code_b'], $pair['family']));

            $this->processPair(
                $io, $dryRun, $reset,
                (int) $pair['id_a'], $pair['code_a'],
                (int) $pair['id_b'], $pair['code_b'],
                $book,
            );
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    private function processPair(
        SymfonyStyle $io, bool $dryRun, bool $reset,
        int $idA, string $codeA,
        int $idB, string $codeB,
        ?string $bookFilter
    ): void {
        // Fetch all verses present in both translations
        $versesSql = "
            SELECT tv_a.id AS verse_id_a, tv_b.id AS verse_id_b,
                   tv_a.book_id, tv_a.chapter, tv_a.verse, b.usfm_code
            FROM translation_verses tv_a
            JOIN translation_verses tv_b
                ON tv_b.translation_id = :id_b
               AND tv_b.book_id  = tv_a.book_id
               AND tv_b.chapter  = tv_a.chapter
               AND tv_b.verse    = tv_a.verse
            JOIN books b ON b.id = tv_a.book_id
            WHERE tv_a.translation_id = :id_a
        ";
        $params = ['id_a' => $idA, 'id_b' => $idB];

        if ($bookFilter) {
            $versesSql .= " AND b.usfm_code = :usfm";
            $params['usfm'] = strtoupper($bookFilter);
        }
        $versesSql .= " ORDER BY tv_a.book_id, tv_a.chapter, tv_a.verse";

        // Count first for a meaningful progress bar (cheap COUNT query)
        $countSql = "SELECT COUNT(*) FROM ({$versesSql}) t";
        $total    = (int) $this->connection->fetchOne($countSql, $params);

        // Stream rows one at a time — avoids loading 31 k verse records into memory at once
        $verses = $this->connection->iterateAssociative($versesSql, $params);

        $io->progressStart($total);
        $linked = 0;

        foreach ($verses as $v) {
            $verseIdA = (int) $v['verse_id_a'];
            $verseIdB = (int) $v['verse_id_b'];

            // Load words for both translations
            $wordsA = $this->connection->fetchAllAssociative(
                "SELECT id, word_position, word_normalised, is_filler FROM translation_words WHERE verse_id = :vid ORDER BY word_position",
                ['vid' => $verseIdA]
            );
            $wordsB = $this->connection->fetchAllAssociative(
                "SELECT id, word_position, word_normalised, is_filler FROM translation_words WHERE verse_id = :vid ORDER BY word_position",
                ['vid' => $verseIdB]
            );

            if (empty($wordsA) || empty($wordsB)) {
                $io->progressAdvance();
                continue;
            }

            // Get IDs for reset/skip
            $idsA = array_column($wordsA, 'id');
            $idsB = array_column($wordsB, 'id');

            if ($reset && !$dryRun) {
                $this->linkingRepo->resetVerseAutoLinks($idsA, $idsB);
            }

            // Skip if already has manual links
            $hasManual = $this->hasManualLinks($idsA, $idsB);
            if ($hasManual && !$reset) {
                $io->progressAdvance();
                continue;
            }

            // Pass 1: Source-pivot matching
            $newLinks = $this->sourcePivotPass($wordsA, $wordsB, $idA, $idB);

            // Pass 2: Sequence alignment for remaining words
            $linkedA = array_column($newLinks, 0);
            $linkedB = array_column($newLinks, 1);
            $unlinkedA = array_values(array_filter($wordsA, fn($w) => !in_array($w['id'], $linkedA)));
            $unlinkedB = array_values(array_filter($wordsB, fn($w) => !in_array($w['id'], $linkedB)));

            $sequenceLinks = $this->sequenceAlignmentPass($unlinkedA, $unlinkedB);
            $newLinks      = array_merge($newLinks, $sequenceLinks);

            // Pass 3: Positional fallback for still-unlinked
            $linkedA = array_column($newLinks, 0);
            $linkedB = array_column($newLinks, 1);
            $remainA = array_values(array_filter($wordsA, fn($w) => !in_array($w['id'], $linkedA) && !$w['is_filler']));
            $remainB = array_values(array_filter($wordsB, fn($w) => !in_array($w['id'], $linkedB) && !$w['is_filler']));
            $positionalLinks = $this->positionalPass($remainA, $remainB);
            $newLinks        = array_merge($newLinks, $positionalLinks);

            // Write links
            if (!$dryRun) {
                foreach ($newLinks as [$wA, $wB, $method, $confidence]) {
                    $this->linkingRepo->saveInterTranslationLink($wA, $wB, $method, $confidence);
                }
            }

            $linked += count($newLinks);

            // Free per-verse memory explicitly
            unset($wordsA, $wordsB, $idsA, $idsB, $newLinks, $linkedA, $linkedB,
                  $unlinkedA, $unlinkedB, $sequenceLinks, $remainA, $remainB, $positionalLinks);

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->text(sprintf('  Linked %d word pairs%s', $linked, $dryRun ? ' (dry run)' : ''));
    }

    /**
     * Pass 1 – Source pivot: if word_a and word_b share the same source word (via word_links),
     * they correspond to each other.
     */
    private function sourcePivotPass(array $wordsA, array $wordsB, int $idA, int $idB): array
    {
        $listA = implode(',', array_map('intval', array_column($wordsA, 'id')));
        $listB = implode(',', array_map('intval', array_column($wordsB, 'id')));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT wl_a.translation_word_id AS tw_a, wl_b.translation_word_id AS tw_b
             FROM word_links wl_a
             JOIN word_links wl_b ON (
                 (wl_a.hebrew_word_id IS NOT NULL AND wl_a.hebrew_word_id = wl_b.hebrew_word_id) OR
                 (wl_a.greek_word_id  IS NOT NULL AND wl_a.greek_word_id  = wl_b.greek_word_id)
             )
             WHERE wl_a.translation_word_id IN ({$listA})
               AND wl_b.translation_word_id IN ({$listB})"
        );

        // Source pivot = shared source-language link → very high confidence
        return array_map(fn($r) => [(int) $r['tw_a'], (int) $r['tw_b'], 'auto_source_pivot', 100], $rows);
    }

    /**
     * Pass 2 – Needleman-Wunsch sequence alignment on normalised word text.
     * O(n*m) DP; fine for typical verse lengths (< 30 words each side).
     */
    private function sequenceAlignmentPass(array $wordsA, array $wordsB): array
    {
        if (empty($wordsA) || empty($wordsB)) return [];

        $n = count($wordsA);
        $m = count($wordsB);

        // Score: +2 match, -1 mismatch, -1 gap
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 0; $i <= $n; $i++) $dp[$i][0] = -$i;
        for ($j = 0; $j <= $m; $j++) $dp[0][$j] = -$j;

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $match  = $this->wordSimilarity($wordsA[$i - 1]['word_normalised'], $wordsB[$j - 1]['word_normalised']);
                $dp[$i][$j] = max(
                    $dp[$i - 1][$j - 1] + $match,
                    $dp[$i - 1][$j] - 1,
                    $dp[$i][$j - 1] - 1
                );
            }
        }

        // Traceback
        $links = [];
        $i = $n; $j = $m;
        while ($i > 0 && $j > 0) {
            $match = $this->wordSimilarity($wordsA[$i - 1]['word_normalised'], $wordsB[$j - 1]['word_normalised']);
            if ($dp[$i][$j] === $dp[$i - 1][$j - 1] + $match && $match >= 0) {
                if ($match > 0 && !$wordsA[$i - 1]['is_filler'] && !$wordsB[$j - 1]['is_filler']) {
                    // Exact match (score 2) → 90, prefix match (score 1) → 70
                    $conf    = $match === 2 ? 90 : 70;
                    $links[] = [(int) $wordsA[$i - 1]['id'], (int) $wordsB[$j - 1]['id'], 'auto_sequence', $conf];
                }
                $i--; $j--;
            } elseif ($dp[$i][$j] === $dp[$i - 1][$j] - 1) {
                $i--;
            } else {
                $j--;
            }
        }

        return $links;
    }

    private function wordSimilarity(?string $a, ?string $b): int
    {
        if (!$a || !$b) return -1;
        if ($a === $b) return 2;
        // Prefix match (first 4 chars for stemming)
        if (strlen($a) >= 4 && strlen($b) >= 4 && substr($a, 0, 4) === substr($b, 0, 4)) return 1;
        return -1;
    }

    /**
     * Pass 3 – Positional fallback: match by relative position in verse.
     */
    private function positionalPass(array $remainA, array $remainB): array
    {
        if (empty($remainA) || empty($remainB)) return [];

        $links = [];
        $used  = [];

        foreach ($remainA as $wA) {
            // Find closest unlinked word in B by relative position
            $posA    = (int) $wA['word_position'];
            $bestJ   = null;
            $bestDist = PHP_INT_MAX;

            foreach ($remainB as $j => $wB) {
                if (isset($used[$j])) continue;
                $dist = abs((int) $wB['word_position'] - $posA);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestJ    = $j;
                }
            }

            if ($bestJ !== null && $bestDist <= 2) {
                // Distance 0 → 60, distance 1 → 40, distance 2 → 20
                $conf         = 60 - ($bestDist * 20);
                $links[]      = [(int) $wA['id'], (int) $remainB[$bestJ]['id'], 'auto_positional', $conf];
                $used[$bestJ] = true;
            }
        }

        return $links;
    }

    private function hasManualLinks(array $idsA, array $idsB): bool
    {
        $listA = implode(',', array_map('intval', $idsA));
        $listB = implode(',', array_map('intval', $idsB));

        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM inter_translation_links
             WHERE method = 'manual'
               AND ((word_a_id IN ({$listA}) AND word_b_id IN ({$listB}))
                 OR (word_a_id IN ({$listB}) AND word_b_id IN ({$listA})))
             LIMIT 1"
        );
    }
}

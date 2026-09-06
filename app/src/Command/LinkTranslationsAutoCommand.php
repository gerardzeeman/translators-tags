<?php

namespace App\Command;

use App\Repository\LinkingRepository;
use App\Repository\TranslationRepository;
use App\Service\Alignment\HistoricalAlignmentService;
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
        private readonly Connection                    $connection,
        private readonly LinkingRepository              $linkingRepo,
        private readonly TranslationRepository          $translationRepo,
        private readonly HistoricalAlignmentService      $historicalAlignmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,  'Do not write to DB')
            ->addOption('reset',   null, InputOption::VALUE_NONE,  'Delete existing auto-links before re-running (pairwise engine only -- the historical engine always does this, since manual links are passed through as protected anchors instead of being skipped over)')
            ->addOption('family',  null, InputOption::VALUE_OPTIONAL, 'Only process this family (e.g. SV)', null)
            ->addOption('book',    null, InputOption::VALUE_OPTIONAL, 'Only process this book USFM code', null)
            ->addOption('chapter', null, InputOption::VALUE_OPTIONAL, 'Only process this chapter, e.g. GEN.1', null)
            ->addOption('verse',   null, InputOption::VALUE_OPTIONAL, 'Only process this verse, e.g. GEN.1.1', null)
            ->addOption('engine',  null, InputOption::VALUE_OPTIONAL, 'Which pipeline to run: "pairwise" (default, legacy source-pivot/sequence/positional passes) or "historical" (HistoricalAlignmentService)', 'pairwise')
            ->addOption('topology', null, InputOption::VALUE_OPTIONAL, 'Historical engine only: "star" (default, everything pivots through the is_alignment_pivot translation) or "chain" (adjacent editions linked directly to each other by alignment_sequence, e.g. SV1657<->SV<->SVGBS<->HSV)', 'star');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $reset    = (bool) $input->getOption('reset');
        $family   = $input->getOption('family');
        $engine   = $input->getOption('engine');
        $topology = $input->getOption('topology');

        if (!in_array($engine, ['pairwise', 'historical'], true)) {
            $io->error("Ongeldige --engine waarde '{$engine}'. Gebruik 'pairwise' of 'historical'.");
            return Command::FAILURE;
        }
        if (!in_array($topology, ['star', 'chain'], true)) {
            $io->error("Ongeldige --topology waarde '{$topology}'. Gebruik 'star' of 'chain'.");
            return Command::FAILURE;
        }

        [$book, $chapter, $verse, $scopeError] = $this->resolveScope(
            $input->getOption('book'),
            $input->getOption('chapter'),
            $input->getOption('verse'),
        );
        if ($scopeError !== null) {
            $io->error($scopeError);
            return Command::FAILURE;
        }

        $io->title($engine === 'historical' ? "Auto-link historical alignment ({$topology} topology)" : 'Auto-link translation pairs');

        $pairs = match (true) {
            $engine === 'historical' && $topology === 'chain' => $this->linkingRepo->fetchChainAlignmentPairs(),
            $engine === 'historical' => $this->linkingRepo->fetchHistoricalAlignmentPairs(),
            default => $this->linkingRepo->fetchTranslationPairs(),
        };

        if ($family) {
            $pairs = array_filter($pairs, fn($p) => $p['family'] === $family);
        }

        if (empty($pairs)) {
            $io->warning('No translation pairs found.');
            return Command::SUCCESS;
        }

        // Start each full historical recompute from a clean alignment_note
        // slate so particle_drop/prefix_drop flags don't go stale across
        // runs (see LinkingRepository::clearAlignmentNotesForTranslation()).
        // markAlignmentNote() only ever marks the CURRENT pair's id_a side
        // (see processHistoricalPair()), but which translation that is
        // depends on topology: star always has id_a = the pivot, chain
        // rotates id_a through every translation in the chain. A note set
        // by one topology's run is therefore never on id_a for the OTHER
        // topology's pairs, so clearing must cover every translation that
        // appears as id_a OR id_b across the pairs actually being
        // processed now -- clearing only id_a left the non-pivot side's
        // notes permanently stale whenever star and chain topology were
        // ever both run against the same verse.
        if ($engine === 'historical' && !$dryRun) {
            $clearedIds = [];
            foreach ($pairs as $pair) {
                foreach ([(int) $pair['id_a'], (int) $pair['id_b']] as $id) {
                    if (!isset($clearedIds[$id])) {
                        $this->linkingRepo->clearAlignmentNotesForTranslation($id, $book, $chapter, $verse);
                        $clearedIds[$id] = true;
                    }
                }
            }
        }

        foreach ($pairs as $pair) {
            $io->section(sprintf('%s ↔ %s (family: %s)', $pair['code_a'], $pair['code_b'], $pair['family']));

            if ($engine === 'historical') {
                $this->processHistoricalPair(
                    $io, $dryRun,
                    (int) $pair['id_a'], (int) $pair['id_b'],
                    $book, $chapter, $verse,
                );
            } else {
                $this->processPair(
                    $io, $dryRun, $reset,
                    (int) $pair['id_a'], $pair['code_a'],
                    (int) $pair['id_b'], $pair['code_b'],
                    $book, $chapter, $verse,
                );
            }
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    /**
     * Parses --book/--chapter/--verse into a single (book, chapter, verse)
     * scope. At most one of the three may be given (--chapter and --verse
     * already imply the book).
     *
     * @return array{0: ?string, 1: ?int, 2: ?int, 3: ?string} [book, chapter, verse, error]
     */
    private function resolveScope(?string $book, ?string $chapterOpt, ?string $verseOpt): array
    {
        $given = array_filter(['book' => $book, 'chapter' => $chapterOpt, 'verse' => $verseOpt], fn($v) => $v !== null);
        if (count($given) > 1) {
            return [null, null, null, 'Gebruik slechts één van --book, --chapter of --verse.'];
        }

        if ($verseOpt !== null) {
            $parts = explode('.', $verseOpt);
            if (count($parts) !== 3 || $parts[0] === '' || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
                return [null, null, null, "Ongeldig --verse formaat '{$verseOpt}', verwacht bijv. GEN.1.1"];
            }
            return [strtoupper($parts[0]), (int) $parts[1], (int) $parts[2], null];
        }

        if ($chapterOpt !== null) {
            $parts = explode('.', $chapterOpt);
            if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[1])) {
                return [null, null, null, "Ongeldig --chapter formaat '{$chapterOpt}', verwacht bijv. GEN.1"];
            }
            return [strtoupper($parts[0]), (int) $parts[1], null, null];
        }

        if ($book !== null) {
            return [strtoupper($book), null, null, null];
        }

        return [null, null, null, null];
    }

    /**
     * Builds the optional book/chapter/verse WHERE-fragment shared by both
     * engines' verse queries. Assumes the book table is aliased `b` and the
     * "A-side" verse table `tv_a`.
     */
    private function scopeWhereSql(?string $book, ?int $chapter, ?int $verse, array &$params): string
    {
        $sql = '';
        if ($book !== null) {
            $sql .= ' AND b.usfm_code = :usfm';
            $params['usfm'] = $book;
        }
        if ($chapter !== null) {
            $sql .= ' AND tv_a.chapter = :chapter';
            $params['chapter'] = $chapter;
        }
        if ($verse !== null) {
            $sql .= ' AND tv_a.verse = :verse';
            $params['verse'] = $verse;
        }

        return $sql;
    }

    private function processPair(
        SymfonyStyle $io, bool $dryRun, bool $reset,
        int $idA, string $codeA,
        int $idB, string $codeB,
        ?string $bookFilter, ?int $chapterFilter, ?int $verseFilter,
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
        $versesSql .= $this->scopeWhereSql($bookFilter, $chapterFilter, $verseFilter, $params);
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
            // Note: filler words (cursive additions, no source-language backing) are NOT
            // excluded here -- that exclusion only applies to word_links (Dutch → Hebrew/Greek,
            // see align_heuristic.py). An addition in one Dutch edition is very often the exact
            // same addition in another (SV and SV(GBS) especially), so it should still get
            // matched to its Dutch counterpart across translations.
            $linkedA = array_column($newLinks, 0);
            $linkedB = array_column($newLinks, 1);
            $remainA = array_values(array_filter($wordsA, fn($w) => !in_array($w['id'], $linkedA)));
            $remainB = array_values(array_filter($wordsB, fn($w) => !in_array($w['id'], $linkedB)));
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
                // Filler status (cursive additions) does not block a Dutch-to-Dutch match here
                // -- see the note on the Pass 3 filter above.
                if ($match > 0) {
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

    // ── Historical-alignment engine (HistoricalAlignmentService, is_alignment_pivot) ──

    /**
     * Unlike processPair(), this always resets non-manual links and always
     * recomputes -- there is no "skip verse if it has manual links" branch,
     * because manual links are passed into HistoricalAlignmentService as
     * protected forced anchors instead: the rest of the pipeline aligns
     * around them, exactly as it already does around its own algorithmic
     * anchors. --reset is accepted but irrelevant here.
     */
    private function processHistoricalPair(
        SymfonyStyle $io, bool $dryRun,
        int $idA, int $idB,
        ?string $bookFilter, ?int $chapterFilter, ?int $verseFilter,
    ): void {
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
        $versesSql .= $this->scopeWhereSql($bookFilter, $chapterFilter, $verseFilter, $params);
        $versesSql .= " ORDER BY tv_a.book_id, tv_a.chapter, tv_a.verse";

        $countSql = "SELECT COUNT(*) FROM ({$versesSql}) t";
        $total    = (int) $this->connection->fetchOne($countSql, $params);
        $verses   = $this->connection->iterateAssociative($versesSql, $params);

        $io->progressStart($total);
        $linked = 0;

        foreach ($verses as $v) {
            $verseIdA = (int) $v['verse_id_a'];
            $verseIdB = (int) $v['verse_id_b'];

            $wordsA = $this->connection->fetchAllAssociative(
                "SELECT id, word_position, word_text FROM translation_words WHERE verse_id = :vid ORDER BY word_position",
                ['vid' => $verseIdA]
            );
            $wordsB = $this->connection->fetchAllAssociative(
                "SELECT id, word_position, word_text FROM translation_words WHERE verse_id = :vid ORDER BY word_position",
                ['vid' => $verseIdB]
            );

            if (empty($wordsA) || empty($wordsB)) {
                $io->progressAdvance();
                continue;
            }

            $idsA = array_column($wordsA, 'id');
            $idsB = array_column($wordsB, 'id');

            // Bestaande manual-links worden ALTIJD gerespecteerd: als vaste
            // ankers meegegeven, nooit verwijderd of overschreven.
            $forcedAnchors = $this->resolveForcedAnchors($wordsA, $wordsB, $idsA, $idsB);

            if (!$dryRun) {
                $this->linkingRepo->resetVerseAutoLinks($idsA, $idsB);
            }

            $srcRaw = array_column($wordsA, 'word_text');
            $tgtRaw = array_column($wordsB, 'word_text');
            $result = $this->historicalAlignmentService->alignPair($srcRaw, $tgtRaw, $forcedAnchors);

            $verseLinked = 0;
            foreach ($result->links as $link) {
                // 'manual' links already exist in the DB and were fed in as
                // forced anchors -- never re-write them here.
                if ($link->kind === 'manual' || !$link->tgt) {
                    continue;
                }
                foreach ($link->tgt as $tgtPos) {
                    if (!$dryRun) {
                        $this->linkingRepo->saveInterTranslationLink(
                            (int) $wordsA[$link->src]['id'],
                            (int) $wordsB[$tgtPos]['id'],
                            $link->kind,
                            null,
                            null,
                            $link->score,
                        );
                    }
                    $verseLinked++;
                }
            }
            $linked += $verseLinked;

            if (!$dryRun) {
                // particle_drop is pair-independent (purely a function of the
                // the pivot's own text) so it's always safe to (re-)assert. prefix_drop
                // can vary per pair -- only ever set it, never overwrite an
                // existing note, so a flag from any of the 3 pairs sticks.
                $particleWordIds = array_map(fn($i) => (int) $wordsA[$i]['id'], $result->particles);
                $prefixWordIds = array_map(fn($i) => (int) $wordsA[$i]['id'], $result->droppedPrefixes);
                $this->linkingRepo->markAlignmentNote($particleWordIds, 'particle_drop');
                $this->linkingRepo->markAlignmentNote($prefixWordIds, 'prefix_drop', onlyIfUnset: true);
            }

            unset($wordsA, $wordsB, $idsA, $idsB, $forcedAnchors, $result, $srcRaw, $tgtRaw);
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->text(sprintf('  Linked %d word pairs%s', $linked, $dryRun ? ' (dry run)' : ''));
    }

    /**
     * Maps existing manual links for this verse pair to [srcPos, tgtPos]
     * position pairs relative to $wordsA/$wordsB's order, for
     * HistoricalAlignmentService::alignPair()'s $forcedAnchors argument.
     * (word_a_id, word_b_id) in inter_translation_links is ordered by ID,
     * not by "which side is the pivot", so either id could belong to
     * either side -- resolved here via the id -> position maps.
     */
    private function resolveForcedAnchors(array $wordsA, array $wordsB, array $idsA, array $idsB): array
    {
        $manualRows = $this->linkingRepo->fetchManualLinkPairs($idsA, $idsB);
        if (!$manualRows) {
            return [];
        }

        $posA = [];
        foreach ($wordsA as $idx => $w) {
            $posA[(int) $w['id']] = $idx;
        }
        $posB = [];
        foreach ($wordsB as $idx => $w) {
            $posB[(int) $w['id']] = $idx;
        }

        $forced = [];
        foreach ($manualRows as $row) {
            $x = (int) $row['word_a_id'];
            $y = (int) $row['word_b_id'];
            if (isset($posA[$x], $posB[$y])) {
                $forced[] = [$posA[$x], $posB[$y]];
            } elseif (isset($posA[$y], $posB[$x])) {
                $forced[] = [$posA[$y], $posB[$x]];
            }
        }

        return $forced;
    }
}

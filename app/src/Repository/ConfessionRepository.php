<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * ConfessionRepository
 * Read-only queries for the confessional-document works added by
 * db/migrate_add_confession_documents.sql (Canones Dordraceni, and future
 * works such as the Nederlandse Geloofsbelijdenis / Heidelbergse Catechismus)
 * -- reuses the Institutio pipeline's work/segment/token/lemma_gloss tables,
 * but deliberately does NOT touch InstitutioRepository: that class is
 * hardcoded to a single work (WORK_SLUG = 'institutio-1559') and to a
 * book->chapter->section reading structure that doesn't fit these documents
 * (Canones interleaves numbered "Articulus" and "Rejectio Errorum"
 * paragraphs within a chapter -- see segment.kind), so a parallel,
 * work-slug-parametrized repository is simpler and safer than reshaping
 * 1400 lines of already-in-production Institutio code around a second
 * hierarchy shape it was never designed for.
 *
 * Dutch translations, where they exist, are read generically as a
 * layer-keyed map (see attachTokens()) rather than a single hardcoded
 * layer like InstitutioRepository's 'llm' -- a segment can have zero, one,
 * or (once more Dutch versions are added, per the user's stated plan) many
 * translation rows, each independently editable/addable without touching
 * this repository. No alignment/sentence-pairing support yet (these
 * translations aren't sentence-aligned to the Latin the way Institutio's
 * 'llm'/'weijenberg1865' layers are) -- each layer is shown as one flowing
 * block of text per segment.
 */
class ConfessionRepository
{
    /**
     * The only `translation.layer` value that is itself Latin (the HC's
     * editio-princeps-1563 reading) rather than a Dutch translation --
     * see db/migrate_add_translation_token.sql and
     * tokenize_translation_latin.py.
     */
    private const LATIN_TRANSLATION_LAYER = 'editio-princeps-1563';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    private function getWorkId(string $workSlug): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM work WHERE slug = :slug', ['slug' => $workSlug]);
        return $id === false ? null : (int) $id;
    }

    /** @return array{id: int, slug: string, title: string, source: ?string}|null */
    public function getWork(string $workSlug): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, slug, title, source FROM work WHERE slug = :slug',
            ['slug' => $workSlug]
        );
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'], 'source' => $row['source']];
    }

    /**
     * One row per chapter, with its heading and how many articles/rejections
     * it holds -- powers the table of contents. article_count/rejection_count
     * are only meaningful for works that use segment.kind (currently only
     * Canones); for others every segment counts as "article" and
     * rejection_count is always 0, so the template can just show one count.
     * @return array<int, array{chapter: int, heading: ?string, article_count: int, rejection_count: int}>
     */
    public function getChapters(string $workSlug): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT chapter,
                    max(heading) AS heading,
                    count(*) FILTER (WHERE kind = 'article' OR kind IS NULL) AS article_count,
                    count(*) FILTER (WHERE kind = 'rejection')               AS rejection_count
             FROM segment
             WHERE work_id = :work_id AND chapter IS NOT NULL
             GROUP BY chapter
             ORDER BY chapter",
            ['work_id' => $workId]
        );

        return array_map(
            fn($r) => [
                'chapter'         => (int) $r['chapter'],
                'heading'         => $r['heading'],
                'article_count'   => (int) $r['article_count'],
                'rejection_count' => (int) $r['rejection_count'],
            ],
            $rows
        );
    }

    /**
     * True if this work has segments with book/chapter both NULL matching
     * the given ref prefix (e.g. 'voorwoord' or 'besluit') -- used to show/
     * hide the "Voorwoord"/"Besluit" links on the table-of-contents page.
     */
    public function hasUnnumberedSection(string $workSlug, string $refPrefix): bool
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return false;
        }
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM segment WHERE work_id = :work_id AND chapter IS NULL AND ref LIKE :prefix LIMIT 1",
            ['work_id' => $workId, 'prefix' => $refPrefix . '.%']
        );
    }

    /**
     * @return array{heading: ?string, articles: array<int, array>, rejections: array<int, array>}
     */
    public function getChapter(string $workSlug, int $chapter): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return ['heading' => null, 'articles' => [], 'rejections' => []];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, ref, section, kind, heading, text_la
             FROM segment
             WHERE work_id = :work_id AND chapter = :chapter
             ORDER BY seq',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        if (!$rows) {
            return ['heading' => null, 'articles' => [], 'rejections' => []];
        }

        $withTokens = $this->attachTokens($rows);
        // Works that don't use the article/rejection split (e.g. the HC)
        // have kind = NULL on every segment -- those count as "articles" too,
        // matching getChapters()'s count query.
        $articles = array_values(array_filter($withTokens, fn($r) => $r['kind'] !== 'rejection'));
        $rejections = array_values(array_filter($withTokens, fn($r) => $r['kind'] === 'rejection'));

        return ['heading' => $rows[0]['heading'], 'articles' => $articles, 'rejections' => $rejections];
    }

    /**
     * All segments of a flat (no chapter grouping) work, in order -- e.g.
     * the NGB's 37 articles. book/chapter are both NULL for every segment
     * of such a work, same convention as Institutio front matter.
     * @return array<int, array{id: int, section: int, heading: ?string, text_la: string, tokens: array}>
     */
    public function getFlatArticles(string $workSlug): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, section, kind, heading, text_la
             FROM segment
             WHERE work_id = :work_id AND chapter IS NULL
             ORDER BY seq',
            ['work_id' => $workId]
        );

        return $this->attachTokens($rows);
    }

    /**
     * The unnumbered segments (book/chapter both NULL) whose ref starts
     * with the given prefix, in order -- used for both "voorwoord" and
     * "besluit" (Canones' preface and conclusion).
     * @return array<int, array{id: int, section: int, text_la: string, tokens: array}>
     */
    public function getUnnumberedSection(string $workSlug, string $refPrefix): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, section, kind, heading, text_la
             FROM segment
             WHERE work_id = :work_id AND chapter IS NULL AND ref LIKE :prefix
             ORDER BY seq',
            ['work_id' => $workId, 'prefix' => $refPrefix . '.%']
        );

        return $this->attachTokens($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows each with at least 'id', 'text_la'
     * @return array<int, array<string, mixed>> same rows, with a 'tokens' key added
     */
    private function attachTokens(array $rows): array
    {
        $segmentIds = array_map(fn($r) => (int) $r['id'], $rows);
        $tokenRows = $this->connection->fetchAllAssociative(
            'SELECT t.segment_id, t.char_start, t.char_end, t.lemma, lg.gloss_nl, lg.number
             FROM token t
             LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
             WHERE t.segment_id IN (' . implode(',', array_fill(0, count($segmentIds), '?')) . ')
               AND t.is_word
             ORDER BY t.segment_id, t.char_start',
            $segmentIds
        );

        $tokensBySegment = [];
        foreach ($tokenRows as $t) {
            $tokensBySegment[(int) $t['segment_id']][] = [
                'char_start' => (int) $t['char_start'],
                'char_end'   => (int) $t['char_end'],
                'lemma'      => $t['lemma'],
                'gloss'      => $t['gloss_nl'],
                'number'     => $t['number'] !== null ? (int) $t['number'] : null,
            ];
        }

        // Layer-keyed rather than a single hardcoded name: a segment can
        // have zero, one, or (once more Dutch versions are added) several
        // translation rows, each shown as its own block in the UI.
        $translationRows = $this->connection->fetchAllAssociative(
            'SELECT id, segment_id, layer, text_nl
             FROM translation
             WHERE segment_id IN (' . implode(',', array_fill(0, count($segmentIds), '?')) . ')
             ORDER BY segment_id, layer',
            $segmentIds
        );
        $translationsBySegment = [];
        $latinTranslationIdBySegment = [];
        foreach ($translationRows as $t) {
            $translationsBySegment[(int) $t['segment_id']][$t['layer']] = $t['text_nl'];
            // Only the HC's editio-princeps-1563 layer is Latin (every
            // other layer is Dutch and was never run through LatinCy) --
            // see db/migrate_add_translation_token.sql.
            if ($t['layer'] === self::LATIN_TRANSLATION_LAYER) {
                $latinTranslationIdBySegment[(int) $t['segment_id']] = (int) $t['id'];
            }
        }

        // Word-hover parts for that Latin translation layer, built the
        // same way as the segment's own text_la (see
        // splitTextIntoWordParts) but from translation_token instead of
        // token, so a reading that differs from text_la still gets its
        // own correct lemmas rather than falling back to plain text.
        $latinTranslationPartsBySegment = [];
        if ($latinTranslationIdBySegment) {
            $translationIds = array_values($latinTranslationIdBySegment);
            $translationTokenRows = $this->connection->fetchAllAssociative(
                'SELECT tt.translation_id, tt.char_start, tt.char_end, tt.lemma, lg.gloss_nl, lg.number
                 FROM translation_token tt
                 LEFT JOIN lemma_gloss lg ON lg.lemma = tt.lemma
                 WHERE tt.translation_id IN (' . implode(',', array_fill(0, count($translationIds), '?')) . ')
                   AND tt.is_word
                 ORDER BY tt.translation_id, tt.char_start',
                $translationIds
            );
            $tokensByTranslationId = [];
            foreach ($translationTokenRows as $t) {
                $tokensByTranslationId[(int) $t['translation_id']][] = [
                    'char_start' => (int) $t['char_start'],
                    'char_end'   => (int) $t['char_end'],
                    'lemma'      => $t['lemma'],
                    'gloss'      => $t['gloss_nl'],
                    'number'     => $t['number'] !== null ? (int) $t['number'] : null,
                ];
            }
            foreach ($latinTranslationIdBySegment as $segId => $translationId) {
                if (isset($tokensByTranslationId[$translationId])) {
                    $latinTranslationPartsBySegment[$segId] = $this->splitTextIntoWordParts(
                        $translationsBySegment[$segId][self::LATIN_TRANSLATION_LAYER],
                        $tokensByTranslationId[$translationId]
                    );
                }
            }
        }

        $proofTextsBySegment = $this->getProofTexts($segmentIds);

        return array_map(
            fn($r) => [
                'id'                    => (int) $r['id'],
                'ref'                   => $r['ref'] ?? null,
                'section'               => (int) $r['section'],
                'kind'                  => $r['kind'],
                'heading'               => $r['heading'],
                'text_la'               => $r['text_la'],
                'tokens'                => $tokensBySegment[(int) $r['id']] ?? [],
                'translations'          => $translationsBySegment[(int) $r['id']] ?? [],
                'latinTranslationParts' => $latinTranslationPartsBySegment[(int) $r['id']] ?? null,
                'proofTexts'            => $proofTextsBySegment[(int) $r['id']] ?? [],
            ],
            $rows
        );
    }

    /**
     * Lettered Scripture proof texts (bewijsteksten) per segment, in order --
     * currently only the HC's classic Dutch apparatus (see
     * db/migrate_add_hc_proof_texts.sql) -- each with where it goes in every
     * translation layer it's anchored in (segment_proof_text_anchor).
     * @param int[] $segmentIds
     * @return array<int, array<int, array{id: int, glyph: string, refs_text: string, anchors: array<string, array{anchor: string, occurrence: int}>}>>
     */
    private function getProofTexts(array $segmentIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.segment_id, p.glyph, p.refs_text, a.layer, a.anchor, a.anchor_occurrence
             FROM segment_proof_text p
             LEFT JOIN segment_proof_text_anchor a ON a.proof_text_id = p.id
             WHERE p.segment_id IN (' . implode(',', array_fill(0, count($segmentIds), '?')) . ')
             ORDER BY p.segment_id, p.ordinal',
            $segmentIds
        );
        $byId = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $byId[$id] ??= [
                'segment_id' => (int) $r['segment_id'],
                'id'         => $id,
                'glyph'      => $r['glyph'],
                'refs_text'  => $r['refs_text'],
                'anchors'    => [],
            ];
            if ($r['layer'] !== null) {
                $byId[$id]['anchors'][$r['layer']] = ['anchor' => $r['anchor'], 'occurrence' => (int) $r['anchor_occurrence']];
            }
        }
        $bySegment = [];
        foreach ($byId as $p) {
            $segmentId = $p['segment_id'];
            unset($p['segment_id']);
            $bySegment[$segmentId][] = $p;
        }
        return $bySegment;
    }

    /**
     * Splits one translation layer's Dutch text into plain-text parts and
     * proof-text marker parts ({type: 'proof', glyph, id, refs_text}), each
     * marker placed right after the given occurrence of its anchor phrase
     * for that layer. Word matching
     * uses the same normalization as parse_hc_prooftexts_cgk.py (lowercase,
     * runs of Unicode letters/digits), so punctuation/spacing differences
     * don't matter. A letter whose anchor isn't found (none stored, or the
     * text has since been corrected at exactly that spot) is left out of the
     * inline text -- it's still in the list under the answer -- rather than
     * guessed.
     * @param array<int, array{id: int, glyph: string, refs_text: string, anchors: array<string, array{anchor: string, occurrence: int}>}> $proofTexts
     * @return array<int, array{type: string, content?: string, glyph?: string, id?: int, refs_text?: string}>
     */
    public function placeProofTextMarkers(string $text, array $proofTexts, string $layer): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE);
        $words = array_map(fn($w) => mb_strtolower($w[0]), $m[0]);
        $wordEnds = array_map(fn($w) => $w[1] + strlen($w[0]), $m[0]);  // byte offsets

        $markersAt = [];  // byte offset => proof texts inserted there
        foreach ($proofTexts as $p) {
            if (!isset($p['anchors'][$layer])) {
                continue;
            }
            $anchor = explode(' ', $p['anchors'][$layer]['anchor']);
            $n = count($anchor);
            $seen = 0;
            for ($i = 0; $i + $n <= count($words); $i++) {
                if (array_slice($words, $i, $n) === $anchor && ++$seen === $p['anchors'][$layer]['occurrence']) {
                    $markersAt[$wordEnds[$i + $n - 1]][] = $p;
                    break;
                }
            }
        }
        ksort($markersAt);

        $parts = [];
        $cursor = 0;
        foreach ($markersAt as $offset => $markers) {
            if ($offset > $cursor) {
                $parts[] = ['type' => 'text', 'content' => substr($text, $cursor, $offset - $cursor)];
            }
            foreach ($markers as $p) {
                $parts[] = ['type' => 'proof', 'glyph' => $p['glyph'], 'id' => $p['id'], 'refs_text' => $p['refs_text']];
            }
            $cursor = $offset;
        }
        if ($cursor < strlen($text)) {
            $parts[] = ['type' => 'text', 'content' => substr($text, $cursor)];
        }
        return $parts;
    }

    /**
     * One proof-text letter's references with their verse text in the given
     * Bible translation (translations.code), for the verse side panel
     * (ConfessionController::proofText). A range comes back as one entry
     * with every verse numbered.
     * @return array{glyph: string, ref: string, refs: array<int, array{label: string, verses: array<int, array{verse: int, text: string}>}>}|null
     */
    public function getProofTextVerses(int $proofTextId, string $translationCode): ?array
    {
        $head = $this->connection->fetchAssociative(
            'SELECT p.glyph, s.ref FROM segment_proof_text p JOIN segment s ON s.id = p.segment_id WHERE p.id = :id',
            ['id' => $proofTextId]
        );
        if ($head === false) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.ordinal, r.label, tv.verse, tv.verse_text
             FROM segment_proof_text_ref r
             LEFT JOIN translations t ON t.code = :code
             LEFT JOIN translation_verses tv
                    ON tv.translation_id = t.id AND tv.book_id = r.book_id AND tv.chapter = r.chapter
                   AND tv.verse BETWEEN r.verse_start AND r.verse_end
             WHERE r.proof_text_id = :id
             ORDER BY r.ordinal, tv.verse",
            ['id' => $proofTextId, 'code' => $translationCode]
        );
        $refs = [];
        foreach ($rows as $r) {
            $refs[(int) $r['ordinal']]['label'] = $r['label'];
            $refs[(int) $r['ordinal']]['verses'] ??= [];
            if ($r['verse'] !== null) {
                $refs[(int) $r['ordinal']]['verses'][] = ['verse' => (int) $r['verse'], 'text' => $r['verse_text']];
            }
        }
        return ['glyph' => $head['glyph'], 'ref' => $head['ref'], 'refs' => array_values($refs)];
    }

    /**
     * Verse text for a list of references (from ScriptureReferenceFinder --
     * the clickable Bible references inline in the confession texts), in the
     * given Bible translation, for the verse side panel. A reference without
     * verses is the whole chapter.
     * @param array<int, array{usfm: string, chapter: int, verse_start: ?int, verse_end: ?int}> $refs
     * @return array<int, array{label: string, verses: array<int, array{verse: int, text: string}>}>
     */
    public function getVersesForRefs(array $refs, string $translationCode): array
    {
        $result = [];
        foreach ($refs as $ref) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT b.name_nl, tv.verse, tv.verse_text
                 FROM books b
                 LEFT JOIN translations t ON t.code = :code
                 LEFT JOIN translation_verses tv
                        ON tv.translation_id = t.id AND tv.book_id = b.id AND tv.chapter = :chapter
                       AND (CAST(:vs AS INT) IS NULL OR tv.verse BETWEEN :vs AND :ve)
                 WHERE b.usfm_code = :usfm
                 ORDER BY tv.verse",
                ['code' => $translationCode, 'usfm' => $ref['usfm'], 'chapter' => $ref['chapter'],
                 'vs' => $ref['verse_start'], 've' => $ref['verse_end']]
            );
            if (!$rows) {
                continue;  // unknown book
            }
            $label = $rows[0]['name_nl'] . ' ' . $ref['chapter'];
            if ($ref['verse_start'] !== null) {
                $label .= ':' . $ref['verse_start'] . ($ref['verse_end'] > $ref['verse_start'] ? '-' . $ref['verse_end'] : '');
            }
            $result[] = [
                'label'  => $label,
                'verses' => array_values(array_map(
                    fn($r) => ['verse' => (int) $r['verse'], 'text' => $r['verse_text']],
                    array_filter($rows, fn($r) => $r['verse'] !== null)
                )),
            ];
        }
        return $result;
    }

    /**
     * Turns the character spans of ScriptureReferenceFinder into clickable
     * {type: 'ref', content, refs} parts inside an existing parts list
     * (word-hover parts from splitTextIntoWordParts(), or a single plain
     * text part, or placeProofTextMarkers() output), which must cover its
     * text contiguously apart from zero-width proof-text markers. Word parts that
     * fall inside a span are absorbed into the link -- a reference's
     * "Rom"/"iii"/"19" tokens have no useful lemma hover anyway.
     * @param array<int, array{type: string, content: string}> $parts
     * @param array<int, array{start: int, end: int, refs: string}> $spans refs already encoded
     * @return array<int, array{type: string, content: string}>
     */
    public function applyReferenceSpans(array $parts, array $spans): array
    {
        if (!$spans) {
            return $parts;
        }
        $out = [];
        $offset = 0;
        $spanIndex = 0;
        $current = null;  // the ref part being built
        foreach ($parts as $part) {
            // Zero-width parts (proof-text letters) take no text: kept as is.
            if (!isset($part['content'])) {
                $out[] = $part;
                continue;
            }
            $content = $part['content'];
            $len = mb_strlen($content);
            $pos = 0;
            while ($pos < $len) {
                $abs = $offset + $pos;
                $span = $spans[$spanIndex] ?? null;
                if ($span !== null && $abs >= $span['start'] && $abs < $span['end']) {
                    $take = min($len - $pos, $span['end'] - $abs);
                    $current ??= ['type' => 'ref', 'content' => '', 'refs' => $span['refs']];
                    $current['content'] .= mb_substr($content, $pos, $take);
                    $pos += $take;
                    if ($offset + $pos >= $span['end']) {
                        $out[] = $current;
                        $current = null;
                        $spanIndex++;
                    }
                    continue;
                }
                $until = $span !== null && $span['start'] > $abs ? min($len, $span['start'] - $offset) : $len;
                $piece = mb_substr($content, $pos, $until - $pos);
                // A part untouched by any span keeps its own type (and a
                // word part its lemma hover); a split one becomes plain text.
                $out[] = $pos === 0 && $until === $len ? $part : ['type' => 'text', 'content' => $piece];
                $pos = $until;
            }
            $offset += $len;
        }
        if ($current !== null) {
            $out[] = $current;
        }
        return $out;
    }

    /**
     * Splits text_la into {type, content, lemma?, gloss?} parts using the
     * segment's tokens -- identical algorithm to InstitutioRepository's
     * private splitTextIntoWordParts(), duplicated rather than shared since
     * that method lives on a class this repository deliberately doesn't
     * depend on (see class docblock).
     * @param array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}> $tokens
     * @return array<int, array{type: string, content: string, lemma?: ?string, gloss?: ?string}>
     */
    public function splitTextIntoWordParts(string $text, array $tokens): array
    {
        $parts = [];
        $cursor = 0;
        foreach ($tokens as $tok) {
            $start = $tok['char_start'];
            $end = $tok['char_end'];
            if ($start < $cursor) {
                continue;
            }
            if ($start > $cursor) {
                $parts[] = ['type' => 'text', 'content' => mb_substr($text, $cursor, $start - $cursor)];
            }
            $parts[] = [
                'type'    => 'word',
                'content' => mb_substr($text, $start, $end - $start),
                'lemma'   => $tok['lemma'],
                'gloss'   => $tok['gloss'],
                'number'  => $tok['number'] ?? null,
            ];
            $cursor = $end;
        }
        $remaining = mb_substr($text, $cursor);
        if ($remaining !== '') {
            $parts[] = ['type' => 'text', 'content' => $remaining];
        }
        return $parts;
    }

    /**
     * Tokenizes $text on whitespace into words with codepoint (not byte)
     * offsets, matching the char_start/char_end convention the LatinCy
     * tokens already use (see splitTextIntoWordParts). Deliberately not
     * regex-with-PREG_OFFSET_CAPTURE, which returns byte offsets --
     * wrong the moment the text contains a non-ASCII character like the
     * æ ligature these Latin texts actually use.
     * @return array<int, array{word: string, start: int, end: int}>
     */
    private function tokenizeWithOffsets(string $text): array
    {
        $chars = mb_str_split($text);
        $n = count($chars);
        $tokens = [];
        $i = 0;
        while ($i < $n) {
            while ($i < $n && ctype_space($chars[$i])) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            $start = $i;
            while ($i < $n && !ctype_space($chars[$i])) {
                $i++;
            }
            $tokens[] = ['word' => implode('', array_slice($chars, $start, $i - $start)), 'start' => $start, 'end' => $i];
        }
        return $tokens;
    }

    /**
     * Longest-common-subsequence match flags: for each word in $wordsA,
     * whether it participates in the LCS with $wordsB (true = unchanged,
     * false = differs). Standard O(n*m) DP -- texts here are a single
     * question/answer (at most a few hundred words), so this is cheap.
     * @param string[] $wordsA
     * @param string[] $wordsB
     * @return bool[] same length as $wordsA
     */
    private function lcsMatchFlags(array $wordsA, array $wordsB): array
    {
        $n = count($wordsA);
        $m = count($wordsB);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $wordsA[$i] === $wordsB[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $matched = array_fill(0, $n, false);
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($wordsA[$i] === $wordsB[$j]) {
                $matched[$i] = true;
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $matched;
    }

    /**
     * Word-level diff between two Latin readings of the same segment
     * (currently: the main text_la and the editio-princeps-1563 layer) --
     * returns the [start, end) codepoint ranges in $textA where a word
     * has no counterpart in the LCS with $textB, i.e. genuinely differs
     * rather than just having shifted position. Punctuation stays
     * attached to its word (a punctuation-only change, e.g. "?" vs "!",
     * is still a real difference worth flagging).
     * @return array<int, array{0: int, 1: int}>
     */
    public function computeLatinDiffRanges(string $textA, string $textB): array
    {
        if ($textA === $textB) {
            return [];
        }
        $tokensA = $this->tokenizeWithOffsets($textA);
        $tokensB = $this->tokenizeWithOffsets($textB);
        $matched = $this->lcsMatchFlags(
            array_column($tokensA, 'word'),
            array_column($tokensB, 'word')
        );
        $ranges = [];
        foreach ($tokensA as $idx => $tok) {
            if (!$matched[$idx]) {
                $ranges[] = [$tok['start'], $tok['end']];
            }
        }
        return $ranges;
    }

    /**
     * Annotates each 'word'-type part (see splitTextIntoWordParts) with
     * 'differs' => bool, true when its span overlaps one of $diffRanges.
     * $parts must cover the same text computeLatinDiffRanges() was given
     * as $textA, in order from offset 0 -- true for the full-segment
     * parts array before it's split at "?" (splitPartsAtQuestionMark just
     * redistributes existing part arrays, so the flag survives that).
     * @param array<int, array{type: string, content: string}> $parts
     * @param array<int, array{0: int, 1: int}> $diffRanges
     * @return array<int, array{type: string, content: string}>
     */
    public function markWordPartsDiffering(array $parts, array $diffRanges): array
    {
        if (!$diffRanges) {
            return $parts;
        }
        $cursor = 0;
        foreach ($parts as &$part) {
            $start = $cursor;
            $end = $cursor + mb_strlen($part['content']);
            $cursor = $end;
            if ($part['type'] !== 'word') {
                continue;
            }
            foreach ($diffRanges as [$rangeStart, $rangeEnd]) {
                if ($start < $rangeEnd && $end > $rangeStart) {
                    $part['differs'] = true;
                    break;
                }
            }
        }
        unset($part);
        return $parts;
    }

    /**
     * Splits a word-parts array (see splitTextIntoWordParts) into its
     * question and answer halves at the first "?" -- used for the
     * Heidelbergse Catechismus, whose segments each store one
     * Vraag+Antwoord pair as a single flowing text (see parse_hc.py), but
     * are shown as two visually separate lines. Only the "text"-type part
     * containing the "?" is actually split; word parts (with their
     * lemma/gloss) are passed through untouched on whichever side they
     * fall on, so word-hover keeps working across the split.
     *
     * Not meaningful for works without a question/answer structure (NGB's
     * flat articles, Canones' Articulus/Rejectio) -- those never contain a
     * literal "?", so 'answer' comes back empty and the caller should fall
     * back to rendering 'question' as the whole text.
     *
     * @param array<int, array{type: string, content: string, lemma?: ?string, gloss?: ?string}> $parts
     * @return array{question: array, answer: array}
     */
    public function splitPartsAtQuestionMark(array $parts): array
    {
        $question = [];
        $answer = [];
        $found = false;
        foreach ($parts as $part) {
            if ($found) {
                $answer[] = $part;
                continue;
            }
            if ($part['type'] === 'text' && str_contains($part['content'], '?')) {
                $pos = mb_strpos($part['content'], '?');
                $before = mb_substr($part['content'], 0, $pos + 1);
                $after = ltrim(mb_substr($part['content'], $pos + 1));
                if ($before !== '') {
                    $question[] = ['type' => 'text', 'content' => $before];
                }
                if ($after !== '') {
                    $answer[] = ['type' => 'text', 'content' => $after];
                }
                $found = true;
                continue;
            }
            $question[] = $part;
        }
        return $found ? ['question' => $question, 'answer' => $answer] : ['question' => $parts, 'answer' => []];
    }

    /**
     * Same split as splitPartsAtQuestionMark(), for a plain translated
     * string rather than a tokenised parts array.
     * @return array{question: string, answer: string}
     */
    public function splitTextAtQuestionMark(string $text): array
    {
        $pos = mb_strpos($text, '?');
        if ($pos === false) {
            return ['question' => $text, 'answer' => ''];
        }
        return [
            'question' => trim(mb_substr($text, 0, $pos + 1)),
            'answer'   => trim(mb_substr($text, $pos + 1)),
        ];
    }

    /**
     * The chapter numbers before/after $chapter that actually have segments,
     * for prev/next navigation -- null at either end of the work.
     * @return array{prev: ?int, next: ?int}
     */
    public function getAdjacentChapters(string $workSlug, int $chapter): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return ['prev' => null, 'next' => null];
        }
        $prev = $this->connection->fetchOne(
            'SELECT max(chapter) FROM segment WHERE work_id = :work_id AND chapter < :chapter',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        $next = $this->connection->fetchOne(
            'SELECT min(chapter) FROM segment WHERE work_id = :work_id AND chapter > :chapter',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        return [
            'prev' => $prev !== false && $prev !== null ? (int) $prev : null,
            'next' => $next !== false && $next !== null ? (int) $next : null,
        ];
    }

    /**
     * One segment's text_la, for the correction editor (see
     * ConfessionController::editText/saveText).
     * @return array{id: int, ref: string, text_la: string}|null
     */
    public function getSegmentText(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, ref, text_la FROM segment WHERE id = :id',
            ['id' => $segmentId]
        );
        return $row === false ? null : ['id' => (int) $row['id'], 'ref' => $row['ref'], 'text_la' => $row['text_la']];
    }

    /**
     * Corrects a segment's Latin source text (e.g. fixing a source-OCR/
     * transcription error found after ingest). Unlike translation edits,
     * this changes text_la itself, which every token's char_start/char_end
     * was computed against -- so the segment's existing tokens are dropped
     * and its status reset to 'ingested', exactly like a fresh (re-)ingest,
     * so a subsequent `tokenize_latin.py` run picks it back up. The old/new
     * text is recorded in segment_text_correction as an audit trail (see
     * db/migrate_add_confession_documents.sql) -- append-only, never
     * updated or deleted.
     */
    public function saveTextCorrection(int $segmentId, string $newText, ?int $userId, ?string $note): void
    {
        $this->connection->transactional(function (Connection $conn) use ($segmentId, $newText, $userId, $note) {
            $old = $conn->fetchOne('SELECT text_la FROM segment WHERE id = :id', ['id' => $segmentId]);
            if ($old === false) {
                throw new \InvalidArgumentException("Segment {$segmentId} bestaat niet.");
            }
            if ($old === $newText) {
                return;
            }

            $conn->executeStatement(
                'INSERT INTO segment_text_correction (segment_id, old_text, new_text, note, corrected_by_user_id)
                 VALUES (:segment_id, :old_text, :new_text, :note, :user_id)',
                ['segment_id' => $segmentId, 'old_text' => $old, 'new_text' => $newText,
                 'note' => $note, 'user_id' => $userId]
            );

            $conn->executeStatement(
                "UPDATE segment SET text_la = :text_la, status = 'ingested' WHERE id = :id",
                ['text_la' => $newText, 'id' => $segmentId]
            );

            $conn->executeStatement('DELETE FROM token WHERE segment_id = :id', ['id' => $segmentId]);
        });
    }

    /**
     * @return array<int, array{new_text: string, note: ?string, created_at: \DateTimeInterface}>
     */
    public function getTextCorrectionHistory(int $segmentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT old_text, new_text, note, created_at
             FROM segment_text_correction WHERE segment_id = :id ORDER BY created_at DESC',
            ['id' => $segmentId]
        );
    }

    /**
     * A segment's Dutch translation row for a given layer, for the
     * correction editor (see ConfessionController::editTranslation/
     * saveTranslation). Refuses the HC's 'editio-princeps-1563' layer --
     * that's a Latin reading, not a Dutch translation (see
     * LATIN_TRANSLATION_LAYER), and editing it would need to invalidate
     * translation_token the way saveTextCorrection() invalidates token,
     * which this simpler direct-write flow doesn't do.
     * @return array{id: int, segment_id: int, ref: string, layer: string, text_nl: string}|null
     */
    public function getTranslationForEdit(int $segmentId, string $layer): ?array
    {
        if ($layer === self::LATIN_TRANSLATION_LAYER) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT t.id, t.segment_id, s.ref, t.layer, t.text_nl
             FROM translation t
             JOIN segment s ON s.id = t.segment_id
             WHERE t.segment_id = :segment_id AND t.layer = :layer',
            ['segment_id' => $segmentId, 'layer' => $layer]
        );
        if ($row === false) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'segment_id' => (int) $row['segment_id'],
            'ref'        => $row['ref'],
            'layer'      => $row['layer'],
            'text_nl'    => $row['text_nl'],
        ];
    }

    /**
     * Corrects a Dutch translation's text (e.g. fixing a typo). Unlike
     * saveTextCorrection() for segment.text_la, this never needs to touch
     * any tokenization -- none of the Dutch translation layers are
     * LatinCy-tokenized (only the Latin 'editio-princeps-1563' layer is,
     * via translation_token, and getTranslationForEdit() already refuses
     * to hand that layer to this editor). The old/new text is recorded in
     * translation_text_correction as an audit trail (see
     * db/migrate_add_translation_text_correction.sql) -- append-only,
     * never updated or deleted.
     */
    public function saveTranslationCorrection(int $translationId, string $newText, ?int $userId, ?string $note): void
    {
        $this->connection->transactional(function (Connection $conn) use ($translationId, $newText, $userId, $note) {
            $old = $conn->fetchOne('SELECT text_nl FROM translation WHERE id = :id', ['id' => $translationId]);
            if ($old === false) {
                throw new \InvalidArgumentException("Vertaling {$translationId} bestaat niet.");
            }
            if ($old === $newText) {
                return;
            }

            $conn->executeStatement(
                'INSERT INTO translation_text_correction (translation_id, old_text, new_text, note, corrected_by_user_id)
                 VALUES (:translation_id, :old_text, :new_text, :note, :user_id)',
                ['translation_id' => $translationId, 'old_text' => $old, 'new_text' => $newText,
                 'note' => $note, 'user_id' => $userId]
            );

            $conn->executeStatement(
                'UPDATE translation SET text_nl = :text_nl WHERE id = :id',
                ['text_nl' => $newText, 'id' => $translationId]
            );
        });
    }

    /**
     * @return array<int, array{old_text: string, new_text: string, note: ?string, created_at: \DateTimeInterface}>
     */
    public function getTranslationCorrectionHistory(int $translationId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT old_text, new_text, note, created_at
             FROM translation_text_correction WHERE translation_id = :id ORDER BY created_at DESC',
            ['id' => $translationId]
        );
    }
}

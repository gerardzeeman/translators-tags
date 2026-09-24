<?php

namespace App\Service;

/**
 * Finds the Bible references written inline in the confession texts, so
 * they can be made clickable (verse side panel). Two notations occur:
 *
 *  - Dutch (the 'traditioneel' layer of the Canones and NGB):
 *      "Rom. 3:19, 23", "1 Joh. 4:9", "Rom. 11:33-36",
 *      "Ef. 1:4, 5 en 2:10", "Gen. 6:5; 8:21"
 *  - Latin (segment.text_la): 17th-century Roman chapter numbers in the
 *    Canones ("Rom. iii. 19", "Johan. xvii. 6", "Rom. xi. 33–36",
 *    "Gen. vi. 5 et viii. 21", "Ver. 23" = same book and chapter as the
 *    reference before it), Arabic ones in the NGB ("Rom. 1, 20"), and a
 *    few whole-chapter references ("1 Tim. 3.", "Matt. xiii.").
 *
 * Deliberately conservative, like CitationFormatter: only whitelisted book
 * abbreviations start a reference, so a capitalized word followed by a
 * number ("Christi. 1 Cor.") never turns into a false link.
 *
 * Every match is one link: a book with all its chapter/verse groups up to
 * the next book ("Ef. 1:4, 5 en 2:10" is one link to three verses; in
 * "Hand. 20:27; Rom. 12:3" each book is its own link). Offsets are in
 * characters (mb), matching ConfessionRepository's word-part offsets.
 */
class ScriptureReferenceFinder
{
    /** Dutch abbreviation (without period) => USFM code. */
    private const DUTCH_BOOKS = [
        'Gen' => 'GEN', 'Ex' => 'EXO', 'Lev' => 'LEV', 'Num' => 'NUM', 'Deut' => 'DEU',
        'Joz' => 'JOS', 'Richt' => 'JDG', 'Ruth' => 'RUT', 'Ezra' => 'EZR', 'Neh' => 'NEH',
        'Est' => 'EST', 'Job' => 'JOB', 'Ps' => 'PSA', 'Spr' => 'PRO', 'Pred' => 'ECC',
        'Hoogl' => 'SNG', 'Jes' => 'ISA', 'Jer' => 'JER', 'Klaagl' => 'LAM', 'Ez' => 'EZK',
        'Dan' => 'DAN', 'Hos' => 'HOS', 'Joël' => 'JOL', 'Amos' => 'AMO', 'Ob' => 'OBA',
        'Jona' => 'JON', 'Micha' => 'MIC', 'Nah' => 'NAM', 'Hab' => 'HAB', 'Zef' => 'ZEP',
        'Hag' => 'HAG', 'Zach' => 'ZEC', 'Mal' => 'MAL',
        'Matth' => 'MAT', 'Mark' => 'MRK', 'Luk' => 'LUK', 'Joh' => 'JHN', 'Hand' => 'ACT',
        'Rom' => 'ROM', 'Gal' => 'GAL', 'Ef' => 'EPH', 'Filipp' => 'PHP', 'Fil' => 'PHP',
        'Kol' => 'COL', 'Tit' => 'TIT', 'Filem' => 'PHM', 'Hebr' => 'HEB', 'Jak' => 'JAS',
        'Jud' => 'JUD', 'Openb' => 'REV',
    ];

    /** Latin abbreviation (without period) => USFM code. */
    private const LATIN_BOOKS = [
        'Gen' => 'GEN', 'Exod' => 'EXO', 'Levit' => 'LEV', 'Num' => 'NUM', 'Deut' => 'DEU',
        'Jos' => 'JOS', 'Iudic' => 'JDG', 'Judic' => 'JDG', 'Job' => 'JOB', 'Iob' => 'JOB',
        'Psa' => 'PSA', 'Psal' => 'PSA', 'Psalm' => 'PSA', 'Prov' => 'PRO', 'Eccles' => 'ECC',
        'Esa' => 'ISA', 'Esai' => 'ISA', 'Isa' => 'ISA', 'Jer' => 'JER', 'Ierem' => 'JER',
        'Ezech' => 'EZK', 'Dan' => 'DAN', 'Hos' => 'HOS', 'Joel' => 'JOL', 'Mich' => 'MIC',
        'Hab' => 'HAB', 'Zach' => 'ZEC', 'Mal' => 'MAL',
        'Matt' => 'MAT', 'Matth' => 'MAT', 'Marc' => 'MRK', 'Luc' => 'LUK',
        'Johan' => 'JHN', 'Joan' => 'JHN', 'Iohan' => 'JHN', 'John' => 'JHN',
        'Act' => 'ACT', 'Actor' => 'ACT', 'Rom' => 'ROM', 'Galat' => 'GAL', 'Gal' => 'GAL',
        'Ephes' => 'EPH', 'Eph' => 'EPH', 'Phil' => 'PHP', 'Philip' => 'PHP', 'Coloss' => 'COL',
        'Col' => 'COL', 'Tit' => 'TIT', 'Heb' => 'HEB', 'Hebr' => 'HEB', 'Jac' => 'JAS',
        'Iac' => 'JAS', 'Jud' => 'JUD', 'Apoc' => 'REV', 'Apocal' => 'REV',
    ];

    /** Base name of a numbered book (Dutch or Latin) => USFM suffix; "1 Kor" => "1CO". */
    private const NUMBERED = [
        'Sam' => 'SA', 'Kon' => 'KI', 'Reg' => 'KI', 'Kron' => 'CH', 'Chron' => 'CH',
        'Kor' => 'CO', 'Cor' => 'CO', 'Thess' => 'TH', 'Tim' => 'TI',
        'Petr' => 'PE', 'Pet' => 'PE', 'Joh' => 'JN', 'Johan' => 'JN', 'John' => 'JN', 'Iohan' => 'JN',
    ];

    private const VERSES = '\d{1,3}(?:\s*(?:[-–]|,)\s*\d{1,3})*';

    /**
     * @return array<int, array{start: int, end: int, refs: array<int, array{usfm: string, chapter: int, verse_start: ?int, verse_end: ?int}>}>
     */
    public function findDutch(string $text): array
    {
        $group = '(\d{1,3}):(' . self::VERSES . ')';
        $pattern = '/(?<![\p{L}\d])(?:([1-3])\s)?(' . $this->bookAlternation(self::DUTCH_BOOKS) . ')\.\s?' . $group
            . '(?:(?:;\s*|\s+en\s+)' . $group . ')*/u';
        return $this->find($text, $pattern, fn(array $m) => $this->parseDutchMatch($m));
    }

    /**
     * @return array<int, array{start: int, end: int, refs: array<int, array{usfm: string, chapter: int, verse_start: ?int, verse_end: ?int}>}>
     */
    public function findLatin(string $text): array
    {
        $chapter = '(?:[ivxlc]{1,7}|\d{1,3})';
        // Book, chapter, then optionally the verses: "Rom. iii. 19",
        // "Rom. 1, 20" (NGB), or nothing ("1 Tim. 3.", "Matt. xiii.");
        // then more chapters of the same book: "; viii. 21" / " et viii. 21".
        $pattern = '/(?<![\p{L}\d])(?:(?:([1-3])\s?)?(' . $this->bookAlternation(self::LATIN_BOOKS) . ')\.?\s(' . $chapter . ')(?:[.,]\s?(' . self::VERSES . '))?'
            . '(?:(?:;\s*|\s+et\s+)' . $chapter . '\.\s?' . self::VERSES . ')*'
            . '|Vers?\.\s?(' . self::VERSES . '))(?![\p{L}\d])/u';

        $previous = null;  // [usfm, chapter] of the last reference, for "Ver. 23"
        return $this->find($text, $pattern, function (array $m) use (&$previous) {
            if ($m[5][1] >= 0) {
                if ($previous === null) {
                    return [];
                }
                return $this->verseRefs($previous[0], $previous[1], $m[5][0]);
            }
            $usfm = $this->bookUsfm($m[1][0] ?? '', $m[2][0], self::LATIN_BOOKS);
            if ($usfm === null) {
                return [];
            }
            // The first chapter/verses come from the capture groups; any
            // "; ch. v" / "et ch. v" continuations are re-read from the
            // matched text after them (the regex repetition only keeps the
            // last one).
            $first = $this->chapterNumber($m[3][0]);
            if ($first === null) {
                return [];
            }
            $hasVerses = $m[4][1] >= 0;
            $refs = $hasVerses
                ? $this->verseRefs($usfm, $first, $m[4][0])
                : [['usfm' => $usfm, 'chapter' => $first, 'verse_start' => null, 'verse_end' => null]];
            $afterFirst = $hasVerses ? $m[4][1] + strlen($m[4][0]) : $m[3][1] + strlen($m[3][0]);
            $rest = substr($m[0][0], $afterFirst - $m[0][1]);
            preg_match_all('/([ivxlc]{1,7}|\d{1,3})\.\s?(' . self::VERSES . ')/u', $rest, $more, PREG_SET_ORDER);
            foreach ($more as $c) {
                $ch = $this->chapterNumber($c[1]);
                if ($ch !== null) {
                    $refs = array_merge($refs, $this->verseRefs($usfm, $ch, $c[2]));
                }
            }
            $last = end($refs);
            $previous = [$usfm, $last['chapter']];
            return $refs;
        });
    }

    /**
     * Runs $pattern over $text and turns each match into a span via
     * $toRefs (which returns [] to reject a match).
     */
    private function find(string $text, string $pattern, callable $toRefs): array
    {
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $spans = [];
        foreach ($matches as $m) {
            $m = array_map(fn($g) => $g[0] === null ? ['', -1] : $g, $m);
            $refs = $toRefs($m);
            if (!$refs) {
                continue;
            }
            // Trailing separators ("Rom. x. 14, 15." keeps its period
            // outside the link) are never part of the match; offsets to mb.
            $matched = rtrim($m[0][0], " \t,;");
            $start = mb_strlen(substr($text, 0, $m[0][1]));
            $spans[] = ['start' => $start, 'end' => $start + mb_strlen($matched), 'refs' => $refs];
        }
        return $spans;
    }

    private function parseDutchMatch(array $m): array
    {
        $usfm = $this->bookUsfm($m[1][0], $m[2][0], self::DUTCH_BOOKS);
        if ($usfm === null) {
            return [];
        }
        // Re-read every "chapter:verses" group of the match (the regex
        // repetition only keeps the last one).
        preg_match_all('/(\d{1,3}):(' . self::VERSES . ')/u', $m[0][0], $groups, PREG_SET_ORDER);
        $refs = [];
        foreach ($groups as $g) {
            $refs = array_merge($refs, $this->verseRefs($usfm, (int) $g[1], $g[2]));
        }
        return $refs;
    }

    /**
     * Regex alternation of every known book name (plain and numbered-book
     * base names), longest first -- matching only real book names up front
     * means a word like "Christi." before "1 Cor. i. 8" can't swallow the
     * "1" as its own chapter number.
     */
    private function bookAlternation(array $books): string
    {
        $names = array_unique(array_merge(array_keys($books), array_keys(self::NUMBERED)));
        usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return implode('|', array_map(fn($n) => preg_quote($n, '/'), $names));
    }

    private function bookUsfm(string $number, string $name, array $books): ?string
    {
        if ($number !== '') {
            return isset(self::NUMBERED[$name]) ? $number . self::NUMBERED[$name] : null;
        }
        return $books[$name] ?? null;
    }

    /**
     * "19" / "14, 15" / "33-36" / "4, 5, 6" => verse ranges, consecutive
     * single verses merged ("18, 19" => 18-19).
     * @return array<int, array{usfm: string, chapter: int, verse_start: int, verse_end: int}>
     */
    private function verseRefs(string $usfm, int $chapter, string $verses): array
    {
        $refs = [];
        foreach (preg_split('/\s*,\s*/u', trim($verses)) as $piece) {
            if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $piece, $r)) {
                [$start, $end] = [(int) $r[1], (int) $r[2]];
            } elseif (ctype_digit($piece)) {
                $start = $end = (int) $piece;
            } else {
                continue;
            }
            $last = count($refs) - 1;
            if ($last >= 0 && $refs[$last]['verse_end'] + 1 === $start) {
                $refs[$last]['verse_end'] = max($end, $refs[$last]['verse_end']);
            } else {
                $refs[] = ['usfm' => $usfm, 'chapter' => $chapter, 'verse_start' => $start, 'verse_end' => max($start, $end)];
            }
        }
        return $refs;
    }

    /** Arabic or (lowercase) Roman chapter number; null if it isn't one. */
    private function chapterNumber(string $s): ?int
    {
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $values = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100];
        $total = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $v = $values[$s[$i]] ?? null;
            if ($v === null) {
                return null;
            }
            $next = $i + 1 < $len ? ($values[$s[$i + 1]] ?? 0) : 0;
            $total += $v < $next ? -$v : $v;
        }
        return $total > 0 && $total <= 150 ? $total : null;
    }

    /**
     * Compact, URL-safe form of a reference list for the verse panel link:
     * "ROM.3.19-19;ROM.3.23-23", whole chapter as "1TI.3".
     * @param array<int, array{usfm: string, chapter: int, verse_start: ?int, verse_end: ?int}> $refs
     */
    public function encode(array $refs): string
    {
        return implode(';', array_map(
            fn($r) => $r['usfm'] . '.' . $r['chapter'] . ($r['verse_start'] !== null ? '.' . $r['verse_start'] . '-' . $r['verse_end'] : ''),
            $refs
        ));
    }

    /**
     * Inverse of encode(), strict: anything malformed is dropped, and at
     * most 20 references are accepted (it comes from a query string).
     * @return array<int, array{usfm: string, chapter: int, verse_start: ?int, verse_end: ?int}>
     */
    public function decode(string $encoded): array
    {
        $refs = [];
        foreach (array_slice(explode(';', $encoded), 0, 20) as $piece) {
            if (preg_match('/^([1-4]?[A-Z]{2,3})\.(\d{1,3})(?:\.(\d{1,3})-(\d{1,3}))?$/', $piece, $m)) {
                $refs[] = [
                    'usfm' => $m[1], 'chapter' => (int) $m[2],
                    'verse_start' => isset($m[3]) ? (int) $m[3] : null,
                    'verse_end' => isset($m[4]) ? (int) $m[4] : null,
                ];
            }
        }
        return $refs;
    }
}

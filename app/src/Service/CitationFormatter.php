<?php

namespace App\Service;

/**
 * CitationFormatter
 * Best-effort conversion of Calvin's own inline Scripture citations -- still
 * stored in the old print apparatus form ("Iesa. 24. d. 23", the middle
 * letter being a print-page margin marker, not a verse indicator) -- into a
 * modern Dutch reference ("Jes. 24:23"), for display next to the Dutch
 * translation, and into structured (USFM book, chapter, verse) references
 * for looking up the actual verse text. Deliberately conservative: only
 * recognized book abbreviations and a specific grammar (book. chapter.
 * letter. verse[, et letter. verse]*[; ...]*[, et alibi.]) are converted;
 * anything else (patristic/classical citations, scholarly bibliography,
 * unrecognized formats) is left alone -- a partial or guessed conversion
 * would be worse than showing the original Latin, or than pretending to
 * link to a verse that isn't really there.
 */
class CitationFormatter
{
    // Longest/most specific spelling first within any group sharing a
    // prefix, so e.g. "Cor" is checked before it could ever be confused with
    // "Colos" (both start with "Co"), and "Iudic" (Judges) before a generic
    // "Iud" would ever be considered.
    private const BOOKS = [
        'iudic' => 'Richt', 'iudi' => 'Richt',
        'iosue' => 'Joz', 'ios' => 'Joz',
        'genes' => 'Gen', 'gene' => 'Gen', 'gen' => 'Gen',
        'exodi' => 'Ex', 'exod' => 'Ex', 'exo' => 'Ex',
        'levit' => 'Lev', 'lev' => 'Lev',
        'nume' => 'Num', 'num' => 'Num', 'nu' => 'Num',
        'deut' => 'Deut',
        'ruth' => 'Ruth',
        'samu' => 'Sam', 'sam' => 'Sam',
        'reg' => 'Kon',
        'paralip' => 'Kron', 'paral' => 'Kron', 'para' => 'Kron',
        'ezra' => 'Ezra', 'esdr' => 'Ezra',
        'nehem' => 'Neh', 'nehe' => 'Neh', 'neh' => 'Neh',
        'esther' => 'Est', 'esth' => 'Est',
        'iob' => 'Job',
        'psalm' => 'Ps', 'psal' => 'Ps', 'psa' => 'Ps',
        'prover' => 'Spr', 'prove' => 'Spr', 'prov' => 'Spr', 'pro' => 'Spr',
        'eccles' => 'Pred', 'eccle' => 'Pred', 'eccl' => 'Pred',
        'cant' => 'Hgl',
        'iesa' => 'Jes', 'jesa' => 'Jes', 'lesa' => 'Jes', 'ies' => 'Jes',
        'ierem' => 'Jer', 'iere' => 'Jer', 'lerem' => 'Jer', 'ier' => 'Jer',
        'thren' => 'Klaagl', 'thre' => 'Klaagl',
        'ezech' => 'Ez', 'ezec' => 'Ez', 'eze' => 'Ez', 'ez' => 'Ez',
        'daniel' => 'Dan', 'danie' => 'Dan', 'dani' => 'Dan', 'dan' => 'Dan',
        'oseae' => 'Hos', 'osee' => 'Hos', 'hos' => 'Hos',
        'ioel' => 'Joël',
        'amos' => 'Amos',
        'abdias' => 'Ob',
        'ionas' => 'Jona',
        'micha' => 'Micha', 'mich' => 'Micha',
        'nahum' => 'Nah',
        'habac' => 'Hab',
        'sopho' => 'Zef', 'zepha' => 'Zef',
        'hagg' => 'Hag',
        'zach' => 'Zach',
        'malach' => 'Mal', 'malac' => 'Mal',
        'matth' => 'Matt', 'matt' => 'Matt', 'mtth' => 'Matt', 'mat' => 'Matt',
        'marc' => 'Mark',
        'luc' => 'Luk',
        'iohann' => 'Joh', 'iohan' => 'Joh', 'johan' => 'Joh',
        'ioan' => 'Joh', 'joan' => 'Joh', 'ioh' => 'Joh', 'joh' => 'Joh',
        'act' => 'Hand',
        'rom' => 'Rom', 'ro' => 'Rom',
        'corin' => 'Kor', 'cori' => 'Kor', 'cor' => 'Kor',
        'galat' => 'Gal', 'gala' => 'Gal', 'gal' => 'Gal',
        'ephes' => 'Ef', 'ephe' => 'Ef', 'eph' => 'Ef',
        'philipp' => 'Filip', 'philip' => 'Filip', 'phil' => 'Filip',
        'coloss' => 'Kol', 'colos' => 'Kol', 'col' => 'Kol',
        'thess' => 'Tess', 'thes' => 'Tess', 'the' => 'Tess', 'tes' => 'Tess',
        'timot' => 'Tim', 'timo' => 'Tim', 'tim' => 'Tim',
        'tit' => 'Tit',
        'philem' => 'Filem',
        'hebr' => 'Hebr', 'heb' => 'Hebr',
        'iacob' => 'Jak', 'iac' => 'Jak',
        'petri' => 'Petr', 'petr' => 'Petr', 'pet' => 'Petr',
        'apocal' => 'Openb', 'apoca' => 'Openb', 'apoc' => 'Openb', 'apo' => 'Openb',
    ];

    // USFM code(s) per Dutch book abbreviation above, matching this app's
    // existing `books.usfm_code` values. A plain string for books that are
    // never numbered; an array keyed by book-number ('' = unnumbered, '1',
    // '2', '3') for books that can be split (1/2 Korinthe, 1/2/3 Johannes, ...).
    private const USFM = [
        'Gen' => 'GEN', 'Ex' => 'EXO', 'Lev' => 'LEV', 'Num' => 'NUM', 'Deut' => 'DEU',
        'Joz' => 'JOS', 'Richt' => 'JDG', 'Ruth' => 'RUT',
        'Sam' => ['1' => '1SA', '2' => '2SA'],
        'Kon' => ['1' => '1KI', '2' => '2KI'],
        'Kron' => ['1' => '1CH', '2' => '2CH'],
        'Ezra' => 'EZR', 'Neh' => 'NEH', 'Est' => 'EST', 'Job' => 'JOB', 'Ps' => 'PSA',
        'Spr' => 'PRO', 'Pred' => 'ECC', 'Hgl' => 'SNG', 'Jes' => 'ISA', 'Jer' => 'JER',
        'Klaagl' => 'LAM', 'Ez' => 'EZK', 'Dan' => 'DAN', 'Hos' => 'HOS', 'Joël' => 'JOL',
        'Amos' => 'AMO', 'Ob' => 'OBA', 'Jona' => 'JON', 'Micha' => 'MIC', 'Nah' => 'NAM',
        'Hab' => 'HAB', 'Zef' => 'ZEP', 'Hag' => 'HAG', 'Zach' => 'ZEC', 'Mal' => 'MAL',
        'Matt' => 'MAT', 'Mark' => 'MRK', 'Luk' => 'LUK',
        'Joh' => ['' => 'JHN', '1' => '1JN', '2' => '2JN', '3' => '3JN'],
        'Hand' => 'ACT', 'Rom' => 'ROM',
        'Kor' => ['1' => '1CO', '2' => '2CO'],
        'Gal' => 'GAL', 'Ef' => 'EPH', 'Filip' => 'PHP', 'Kol' => 'COL',
        'Tess' => ['1' => '1TH', '2' => '2TH'],
        'Tim' => ['1' => '1TI', '2' => '2TI'],
        'Tit' => 'TIT', 'Filem' => 'PHM', 'Hebr' => 'HEB', 'Jak' => 'JAS',
        'Petr' => ['1' => '1PE', '2' => '2PE'],
        'Openb' => 'REV',
    ];

    /**
     * @return string|null Dutch-formatted reference, or null if this note
     *   doesn't cleanly match the recognized old-apparatus citation grammar
     *   (caller should fall back to showing the original Latin note).
     */
    public function toDutch(string $note): ?string
    {
        $parsed = $this->parse($note);
        if ($parsed === null) {
            return null;
        }

        $rendered = [];
        foreach ($parsed['clauses'] as $c) {
            $label = $c['book_num'] !== '' ? "{$c['book_num']} {$c['book']}." : "{$c['book']}.";
            $verses = array_map(fn($v) => "{$v['chapter']}:{$v['verse']}", $c['verses']);
            $rendered[] = $label . ' ' . implode(', en ', $verses);
        }
        return implode('; ', $rendered) . $parsed['suffix'];
    }

    /**
     * Structured Bible verse references extracted from a citation note, for
     * looking up the actual verse text. Empty if the note doesn't cleanly
     * match the recognized grammar, or matches but names a book this app
     * doesn't have USFM data for.
     *
     * @return array<int, array{usfm: string, chapter: int, verse: int, label: string}>
     */
    public function extractBibleRefs(string $note): array
    {
        $parsed = $this->parse($note);
        if ($parsed === null) {
            return [];
        }

        $refs = [];
        foreach ($parsed['clauses'] as $c) {
            $usfm = $this->lookupUsfm($c['book'], $c['book_num']);
            if ($usfm === null) {
                continue;
            }
            $label = $c['book_num'] !== '' ? "{$c['book_num']} {$c['book']}." : "{$c['book']}.";
            foreach ($c['verses'] as $v) {
                $refs[] = [
                    'usfm'    => $usfm,
                    'chapter' => (int) $v['chapter'],
                    'verse'   => (int) $v['verse'],
                    'label'   => "{$label} {$v['chapter']}:{$v['verse']}",
                ];
            }
        }
        return $refs;
    }

    /**
     * @return array{clauses: array<int, array{book: string, book_num: string, verses: array<int, array{chapter: string, verse: string}>}>, suffix: string}|null
     */
    private function parse(string $note): ?array
    {
        $note = trim($note);
        $suffix = '';
        if (preg_match('/^(.*?),?\s*et\s+alibi\.?\s*$/iu', $note, $m)) {
            $note = trim($m[1]);
            $suffix = ', en elders';
        }
        if ($note === '') {
            return null;
        }

        $clauses = [];
        foreach (explode(';', $note) as $clause) {
            $parsedClause = $this->parseClause(trim($clause));
            if ($parsedClause === null) {
                return null; // any unrecognized clause aborts the whole conversion
            }
            $clauses[] = $parsedClause;
        }
        return ['clauses' => $clauses, 'suffix' => $suffix];
    }

    /**
     * @return array{book: string, book_num: string, verses: array<int, array{chapter: string, verse: string}>}|null
     */
    private function parseClause(string $clause): ?array
    {
        if (!preg_match('/^(?:(\d)\.\s*)?([A-Za-z]+)\.\s*(\d+)\.\s*(.+)$/u', $clause, $m)) {
            return null;
        }
        [, $bookNum, $bookToken, $chapter, $rest] = $m;

        $book = $this->lookupBook($bookToken);
        if ($book === null) {
            return null;
        }

        $verses = [];
        $currentChapter = $chapter;
        foreach (preg_split('/\s*,?\s*et\s+/iu', $rest) as $group) {
            $group = rtrim(trim($group), '.');
            if ($group === '') {
                return null;
            }
            if (preg_match('/^(\d+)\.\s*[a-z]\.\s*(\d+)$/u', $group, $gm)) {
                $currentChapter = $gm[1];
                $verses[] = ['chapter' => $currentChapter, 'verse' => $gm[2]];
            } elseif (preg_match('/^[a-z]\.\s*(\d+)$/u', $group, $gm)) {
                $verses[] = ['chapter' => $currentChapter, 'verse' => $gm[1]];
            } else {
                return null;
            }
        }

        return ['book' => $book, 'book_num' => $bookNum, 'verses' => $verses];
    }

    private function lookupBook(string $token): ?string
    {
        $normalized = mb_strtolower($token);
        foreach (self::BOOKS as $prefix => $dutch) {
            if (str_starts_with($normalized, $prefix)) {
                return $dutch;
            }
        }
        return null;
    }

    private function lookupUsfm(string $book, string $bookNum): ?string
    {
        $entry = self::USFM[$book] ?? null;
        if ($entry === null) {
            return null;
        }
        if (is_string($entry)) {
            return $bookNum === '' ? $entry : null; // never numbered but citation had a number -- distrust it
        }
        return $entry[$bookNum] ?? null;
    }
}

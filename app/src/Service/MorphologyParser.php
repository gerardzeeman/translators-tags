<?php

namespace App\Service;

/**
 * MorphologyParser
 * Decodes Robinson Greek morphology codes and Hebrew ETCBC codes
 * into human-readable grammatical descriptions.
 */
class MorphologyParser
{
    // ── Greek (Robinson) ────────────────────────────────────────────────────

    private const GK_POS = [
        'V'    => 'Verb',
        'N'    => 'Noun',
        'A'    => 'Adjective',
        'T'    => 'Article',
        'P'    => 'Personal Pronoun',
        'R'    => 'Relative Pronoun',
        'C'    => 'Reciprocal Pronoun',
        'D'    => 'Demonstrative Pronoun',
        'K'    => 'Correlative Pronoun',
        'I'    => 'Interrogative Pronoun',
        'X'    => 'Indefinite Pronoun',
        'Q'    => 'Correlative/Interrogative Pronoun',
        'F'    => 'Reflexive Pronoun',
        'S'    => 'Possessive Pronoun',
        'ADV'  => 'Adverb',
        'CONJ' => 'Conjunction',
        'COND' => 'Conditional Particle',
        'PRT'  => 'Particle',
        'PREP' => 'Preposition',
        'INJ'  => 'Interjection',
        'ARAM' => 'Aramaic Transliteration',
        'HEB'  => 'Hebrew Transliteration',
    ];

    private const GK_TENSE = [
        'P' => 'Present', 'I' => 'Imperfect', 'F' => 'Future',
        'A' => 'Aorist',  'X' => 'Perfect',   'Y' => 'Pluperfect',
        '2A' => '2nd Aorist', '2F' => '2nd Future', '2X' => '2nd Perfect',
    ];

    private const GK_VOICE = [
        'A' => 'Active',  'M' => 'Middle',   'P' => 'Passive',
        'D' => 'Middle-Deponent', 'O' => 'Passive-Deponent',
        'N' => 'Middle/Passive',  'E' => 'Middle/Passive-Deponent',
        'Q' => 'Active/Middle',
    ];

    private const GK_MOOD = [
        'I' => 'Indicative', 'S' => 'Subjunctive', 'O' => 'Optative',
        'M' => 'Imperative', 'N' => 'Infinitive',  'P' => 'Participle',
    ];

    private const GK_CASE = [
        'N' => 'Nominative', 'G' => 'Genitive', 'D' => 'Dative',
        'A' => 'Accusative', 'V' => 'Vocative',
    ];

    private const GK_NUMBER = ['S' => 'Singular', 'P' => 'Plural'];

    private const GK_GENDER = ['M' => 'Masculine', 'F' => 'Feminine', 'N' => 'Neuter'];

    private const GK_PERSON = ['1' => '1st', '2' => '2nd', '3' => '3rd'];

    public function describeGreek(string $code): string
    {
        if (!$code) return '';

        $parts = explode('-', $code);
        $pos   = array_shift($parts);

        // Strip leading 2 from tense prefix for 2nd aorist etc.
        $posLabel = self::GK_POS[$pos] ?? $pos;

        if (empty($parts)) {
            return $posLabel; // indeclinable: PREP, CONJ, ADV, PRT …
        }

        $desc = [$posLabel];

        // Verb: TVMpn or TVMCN (tense-voice-mood-person-number)
        if ($pos === 'V' && isset($parts[0])) {
            $tvm = $parts[0];
            // Handle 2nd aorist prefix
            $tenseKey = '';
            $rest     = $tvm;
            if (str_starts_with($tvm, '2')) {
                $tenseKey = '2' . substr($tvm, 1, 1);
                $rest     = substr($tvm, 2);
            } else {
                $tenseKey = substr($tvm, 0, 1);
                $rest     = substr($tvm, 1);
            }
            $voice = substr($rest, 0, 1);
            $mood  = substr($rest, 1, 1);

            $desc[] = self::GK_TENSE[$tenseKey] ?? $tenseKey;
            $desc[] = self::GK_VOICE[$voice]    ?? $voice;
            $desc[] = self::GK_MOOD[$mood]      ?? $mood;

            if (isset($parts[1])) {
                $pn = $parts[1];
                if (ctype_digit($pn[0])) {
                    $desc[] = (self::GK_PERSON[$pn[0]] ?? $pn[0]) . ' Person';
                    $desc[] = self::GK_NUMBER[$pn[1] ?? ''] ?? '';
                } else {
                    // Participle: case-number-gender
                    $desc[] = self::GK_CASE[$pn[0]]   ?? $pn[0];
                    $desc[] = self::GK_NUMBER[$pn[1]]  ?? '';
                    $desc[] = self::GK_GENDER[$pn[2]]  ?? '';
                }
            }
            return implode(', ', array_filter($desc));
        }

        // Noun / Article / Adjective / Pronoun: CNG
        if (isset($parts[0]) && strlen($parts[0]) >= 2) {
            $cng = $parts[0];
            // Person prefix for personal pronouns (P-1NS)
            $offset = 0;
            if (in_array($pos, ['P', 'F', 'S']) && ctype_digit($cng[0])) {
                $desc[] = (self::GK_PERSON[$cng[0]] ?? $cng[0]) . ' Person';
                $offset = 1;
            }
            $desc[] = self::GK_CASE[$cng[$offset]     ?? ''] ?? '';
            $desc[] = self::GK_NUMBER[$cng[$offset+1] ?? ''] ?? '';
            $desc[] = self::GK_GENDER[$cng[$offset+2] ?? ''] ?? '';

            // Degree suffix for adjectives
            if (isset($parts[1])) {
                $desc[] = match ($parts[1]) {
                    'C' => 'Comparative',
                    'S' => 'Superlative',
                    default => $parts[1],
                };
            }
        }

        return implode(', ', array_filter($desc));
    }

    // ── Hebrew (simplified ETCBC/OpenScriptures) ─────────────────────────────

    public function describeHebrew(string $code): string
    {
        if (!$code) return '';

        // ETCBC codes are complex; return a minimal human-readable label
        // A full implementation would parse each segment per the ETCBC spec.
        $map = [
            'HVqp' => 'Verb, Qal Perfect',
            'HVqi' => 'Verb, Qal Imperfect',
            'HVqr' => 'Verb, Qal Imperative',
            'HVqc' => 'Verb, Qal Infinitive Construct',
            'HVqa' => 'Verb, Qal Infinitive Absolute',
            'HVqP' => 'Verb, Qal Participle Active',
            'HNcmsa' => 'Noun, Common, Masculine, Singular, Absolute',
            'HNcmsc' => 'Noun, Common, Masculine, Singular, Construct',
            'HNcmpa' => 'Noun, Common, Masculine, Plural, Absolute',
            'HNpmsa' => 'Proper Noun, Masculine, Singular, Absolute',
        ];

        return $map[$code] ?? $code;
    }
}

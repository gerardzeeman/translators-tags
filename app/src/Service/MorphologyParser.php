<?php

namespace App\Service;

/**
 * MorphologyParser
 * Decodes Robinson Greek morphology codes and Hebrew OpenScriptures/TEHMC codes
 * into human-readable Dutch grammatical descriptions.
 */
class MorphologyParser
{
    // ── Greek (Robinson) ────────────────────────────────────────────────────

    private const GK_POS = [
        'V'    => 'Werkwoord',
        'N'    => 'Zelfst. naamwoord', // Zelfstandig naamwoord
        'A'    => 'Bijv. naamwoord', // Bijvoeglijk naamwoord
        'T'    => 'Lidwoord',
        'P'    => 'Pers. voornaamwoord', // Persoonlijk voornaamwoord
        'R'    => 'Betr. voornaamwoord', // Betrekkelijk voornaamwoord'
        'C'    => 'Wederkerig voornaamwoord',
        'D'    => 'Aanw. voornaamwoord', // Aanwijzend voornaamwoord
        'K'    => 'Correlatief voornaamwoord',
        'I'    => 'Vragend voornaamwoord',
        'X'    => 'Onb. voornaamwoord', // Onbepaald voornaamwoord
        'Q'    => 'Correlatief/vragend voornaamwoord',
        'F'    => 'Reflexief voornaamwoord',
        'S'    => 'Bez. voornaamwoord', // Bezittelijk voornaamwoord
        'ADV'  => 'Bijwoord',
        'CONJ' => 'Voegwoord',
        'COND' => 'Voorw. partikel', // Voorwaardelijk partikel
        'PRT'  => 'Partikel',
        'PREP' => 'Voorzetsel',
        'INJ'  => 'Uitroep',
        'ARAM' => 'Aramese transliteratie',
        'HEB'  => 'Hebreeuwse transliteratie',
    ];

    private const GK_TENSE = [
        'P' => 'Presens', 'I' => 'Imperfectum', 'F' => 'Futurum',
        'A' => 'Aoristus', 'X' => 'Perfectum', 'Y' => 'Plusquamperfectum',
        '2A' => '2e aoristus', '2F' => '2e futurum', '2X' => '2e perfectum',
    ];

    private const GK_VOICE = [
        'A' => 'Actief',  'M' => 'Medium',   'P' => 'Passief',
        'D' => 'Medium-deponens', 'O' => 'Passief-deponens',
        'N' => 'Medium/passief',  'E' => 'Medium/passief-deponens',
        'Q' => 'Actief/medium',
    ];

    private const GK_MOOD = [
        'I' => 'Indicatief', 'S' => 'Conjunctief', 'O' => 'Optatief',
        'M' => 'Imperatief', 'N' => 'Infinitief',  'P' => 'Participium',
    ];

    private const GK_CASE = [
        'N' => 'Nominatief', 'G' => 'Genitief', 'D' => 'Datief',
        'A' => 'Accusatief', 'V' => 'Vocatief',
    ];

    private const GK_NUMBER = ['S' => 'Enkelvoud', 'P' => 'Meervoud'];

    private const GK_GENDER = ['M' => 'Mannelijk', 'F' => 'Vrouwelijk', 'N' => 'Onzijdig'];

    private const GK_PERSON = ['1' => '1e', '2' => '2e', '3' => '3e'];

    public function describeGreek(string $code): string
    {
        if (!$code) return '';

        $parts = explode('-', $code);
        $pos   = array_shift($parts);

        $posLabel = self::GK_POS[$pos] ?? $pos;

        if (empty($parts)) {
            return $posLabel;
        }

        $desc = [$posLabel];

        if ($pos === 'V' && isset($parts[0])) {
            $tvm = $parts[0];
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
                    $desc[] = (self::GK_PERSON[$pn[0]] ?? $pn[0]) . ' persoon';
                    $desc[] = self::GK_NUMBER[$pn[1] ?? ''] ?? '';
                } else {
                    $desc[] = self::GK_CASE[$pn[0]]   ?? $pn[0];
                    $desc[] = self::GK_NUMBER[$pn[1]]  ?? '';
                    $desc[] = self::GK_GENDER[$pn[2]]  ?? '';
                }
            }
            return implode(', ', array_filter($desc));
        }

        if (isset($parts[0]) && strlen($parts[0]) >= 2) {
            $cng    = $parts[0];
            $offset = 0;
            if (in_array($pos, ['P', 'F', 'S']) && ctype_digit($cng[0])) {
                $desc[] = (self::GK_PERSON[$cng[0]] ?? $cng[0]) . ' persoon';
                $offset = 1;
            }
            $desc[] = self::GK_CASE[$cng[$offset]     ?? ''] ?? '';
            $desc[] = self::GK_NUMBER[$cng[$offset+1] ?? ''] ?? '';
            $desc[] = self::GK_GENDER[$cng[$offset+2] ?? ''] ?? '';

            if (isset($parts[1])) {
                $desc[] = match ($parts[1]) {
                    'C' => 'Comparatief',
                    'S' => 'Superlatief',
                    default => $parts[1],
                };
            }
        }

        return implode(', ', array_filter($desc));
    }

    // ── Hebrew (OpenScriptures / TEHMC) ──────────────────────────────────────

    // Separate tables for Hebrew and Aramaic stems
    private const HE_STEM_H = [
        'q' => 'Qal',   'n' => 'Nifal',  'p' => 'Piël',  'P' => 'Puël',
        'h' => 'Hifil', 'H' => 'Hofal',  't' => 'Hitpaël','o' => 'Polal',
        'u' => 'Poël',  'c' => 'Tifil',  'D' => 'Nitpaël',
    ];
    private const HE_STEM_A = [
        'q' => 'Peal',  'l' => 'Peal',   'L' => 'Peil',  'm' => 'Paël',
        'f' => 'Hafel', 'a' => 'Afel',   'A' => 'Hafel', 'e' => 'Shafel',
    ];

    // Verb forms
    private const HE_FORM = [
        'p' => 'Perfectum',
        'q' => 'Consecutief perfectum',
        'i' => 'Imperfectum',
        'n' => 'Imperfectum (indicatief)',
        'j' => 'Jussief',
        'c' => 'Cohortatief',
        'w' => 'Consecutief imperfectum',
        'v' => 'Imperatief',
        'r' => 'Participium actief',
        's' => 'Participium passief',
        'a' => 'Infinitief absoluut',
    ];

    // Non-verb functions
    private const HE_FUNC = [
        'C' => 'Voegwoord',
        'c' => 'Consecutief voegwoord',
        'D' => 'Bijwoord',
        'R' => 'Voorzetsel',
        'T' => 'Partikel',
        'P' => 'Voornaamwoord',
    ];

    // Particle subtypes
    private const HE_PARTICLE = [
        'd' => 'Bep. lidwoord', // Bepaald lidwoord
        'a' => 'Bep. lidwoord', // Bepaald lidwoord
        'i' => 'Vragend partikel',
        'n' => 'Ontkennend partikel',
        'o' => 'Lijdend-voorwerpindicator',
        'r' => 'Betrekkelijk partikel',
        'j' => 'Uitroep',
        'm' => 'Aanw. partikel', // Aanwijzend partikel
        'c' => 'Voorw. partikel', // Voorwaardelijk partikel
        'h' => 'Paragogische hé',
        'u' => 'Paragogische nun',
    ];

    // Suffix types
    private const HE_SUFFIX = [
        'd' => 'Richting-suffix',
        'h' => 'Paragogische hé',
        'n' => 'Paragogische nun',
    ];

    // Noun/adjective forms
    private const HE_NOUN_FORM = [
        'c' => 'gewoon',
        'g' => 'gentilicium',
        'p' => 'eigennaam',
    ];

    private const HE_ADJ_FORM = [
        'a' => '',          // common adjective — no label needed
        'c' => 'telwoord',
        'o' => 'rangtelwoord',
    ];

    // Shared gender/number/state/person
    private const HE_GENDER = [
        'm' => 'mannelijk', 'f' => 'vrouwelijk',
        'b' => 'beide geslachten', 'c' => 'beide geslachten',
    ];
    private const HE_NUMBER = [
        's' => 'enkelvoud', 'p' => 'meervoud', 'd' => 'dualis',
    ];
    private const HE_STATE = [
        'a' => 'absoluut', 'c' => 'construct', 'd' => 'bepaald',
    ];
    private const HE_PERSON = [
        '1' => '1e persoon', '2' => '2e persoon', '3' => '3e persoon',
    ];

    public function describeHebrew(string $code): string
    {
        if (!$code) return '';

        // Compound codes separated by /  e.g. "HC/Ncmsa" or "HTo/Ncmsa"
        // After first part the / replaces the language prefix
        $firstLang = $code[0] ?? 'H';
        if (str_contains($code, '/')) {
            $parts = explode('/', $code);
            $descriptions = [];
            foreach ($parts as $i => $part) {
                if ($i === 0) {
                    $descriptions[] = $this->parseHebrewPart($part);
                } else {
                    // restore language prefix
                    $descriptions[] = $this->parseHebrewPart($firstLang . $part);
                }
            }
            return implode(' + ', array_filter($descriptions));
        }

        return $this->parseHebrewPart($code);
    }

    private function parseHebrewPart(string $code): string
    {
        if (strlen($code) < 2) return $code;

        $lang = $code[0]; // H or A
        $func = $code[1]; // function letter

        $isAramaic = $lang === 'A';
        $rest = substr($code, 2);

        return match (true) {
            $func === 'V'           => $this->parseVerb($rest, $isAramaic),
            $func === 'N'           => $this->parseNoun($rest),
            $func === 'A'           => $this->parseAdjective($rest),
            $func === 'P'           => $this->parsePronoun($rest),
            $func === 'T'           => $this->parseParticle($rest),
            $func === 'R'           => $this->parsePreposition($rest),
            in_array($func, ['C','c']) => $this->parseConjunction($func),
            $func === 'D'           => 'Bijwoord',
            $func === 'S'           => $this->parseSuffix($rest),
            default                 => $code,
        };
    }

    private function parseVerb(string $rest, bool $aramaic): string
    {
        if (!$rest) return 'Werkwoord';

        $stemChar = $rest[0];
        $stems    = $aramaic ? self::HE_STEM_A : self::HE_STEM_H;
        $stem     = $stems[$stemChar] ?? $stemChar;

        if (strlen($rest) < 2) return "Werkwoord, $stem";

        $formChar = $rest[1];
        $after    = substr($rest, 2); // everything after form

        // Infinitive: form char is 'a' or 'c' AND next char is a letter (state), not digit
        if (($formChar === 'a' || $formChar === 'c') && $after !== '' && !ctype_digit($after[0])) {
            $formLabel = $formChar === 'a' ? 'Infinitief absoluut' : 'Infinitief construct';
            return "Werkwoord, $stem, $formLabel";
        }

        // Participle: form char is 'r' or 's'
        if ($formChar === 'r' || $formChar === 's') {
            $formLabel = $formChar === 'r' ? 'Participium actief' : 'Participium passief';
            $gns = $this->parseGenderNumberState($after);
            return implode(', ', array_filter(["Werkwoord, $stem", $formLabel, ...$gns]));
        }

        // Finite form: form + person + gender + number
        $formLabel = self::HE_FORM[$formChar] ?? $formChar;
        $parts = ['Werkwoord', $stem, $formLabel];

        if (strlen($after) >= 3) {
            $person = self::HE_PERSON[$after[0]] ?? null;
            $gender = self::HE_GENDER[$after[1]] ?? null;
            $number = self::HE_NUMBER[$after[2]] ?? null;
            if ($person) $parts[] = $person;
            if ($gender) $parts[] = $gender;
            if ($number) $parts[] = $number;
        } elseif (strlen($after) >= 2) {
            // imperative without explicit person, or 2-char ending
            $gender = self::HE_GENDER[$after[0]] ?? null;
            $number = self::HE_NUMBER[$after[1]] ?? null;
            if ($gender) $parts[] = $gender;
            if ($number) $parts[] = $number;
        }

        return implode(', ', array_filter($parts));
    }

    private function parseNoun(string $rest): string
    {
        if (!$rest) return 'Zelfstandig naamwoord';

        $formChar = $rest[0];
        $form = self::HE_NOUN_FORM[$formChar] ?? $formChar;
        $base = match ($formChar) {
            'p'     => 'Eigennaam',
            'g'     => 'Zelfstandig naamwoord (gentilicium)',
            default => 'Zelfstandig naamwoord',
        };

        $gns = $this->parseGenderNumberState(substr($rest, 1));
        return implode(', ', array_filter([$base, ...$gns]));
    }

    private function parseAdjective(string $rest): string
    {
        if (!$rest) return 'Bijvoeglijk naamwoord';

        $formChar = $rest[0];
        $base = match ($formChar) {
            'c' => 'Telwoord',
            'o' => 'Rangtelwoord',
            default => 'Bijvoeglijk naamwoord',
        };

        $gns = $this->parseGenderNumberState(substr($rest, 1));
        return implode(', ', array_filter([$base, ...$gns]));
    }

    private function parsePronoun(string $rest): string
    {
        if (!$rest) return 'Voornaamwoord';

        // Sub-type: first char
        $type = match ($rest[0] ?? '') {
            'p' => 'Persoonlijk voornaamwoord',
            'r' => 'Betrekkelijk voornaamwoord',
            'd' => 'Aanwijzend voornaamwoord',
            'i' => 'Onbepaald voornaamwoord',
            'x' => 'Reflexief voornaamwoord',
            default => 'Voornaamwoord',
        };

        // Remaining may be gender+number
        $gn = substr($rest, 1);
        $parts = [$type];
        if (strlen($gn) >= 1) $parts[] = self::HE_GENDER[$gn[0]] ?? null;
        if (strlen($gn) >= 2) $parts[] = self::HE_NUMBER[$gn[1]] ?? null;

        return implode(', ', array_filter($parts));
    }

    private function parseParticle(string $rest): string
    {
        $type = self::HE_PARTICLE[$rest[0] ?? ''] ?? 'Partikel';
        return $type;
    }

    private function parsePreposition(string $rest): string
    {
        if (($rest[0] ?? '') === 'd') {
            return 'Voorzetsel (bepaald)';
        }
        return 'Voorzetsel';
    }

    private function parseConjunction(string $func): string
    {
        return $func === 'c' ? 'Consecutief voegwoord' : 'Voegwoord';
    }

    private function parseSuffix(string $rest): string
    {
        return self::HE_SUFFIX[$rest[0] ?? ''] ?? 'Suffix';
    }

    /** Parse gender + number + state from a 3-char string */
    private function parseGenderNumberState(string $gns): array
    {
        $result = [];
        if (strlen($gns) >= 1 && isset(self::HE_GENDER[$gns[0]])) {
            $result[] = self::HE_GENDER[$gns[0]];
        }
        if (strlen($gns) >= 2 && isset(self::HE_NUMBER[$gns[1]])) {
            $result[] = self::HE_NUMBER[$gns[1]];
        }
        if (strlen($gns) >= 3 && isset(self::HE_STATE[$gns[2]])) {
            $result[] = self::HE_STATE[$gns[2]];
        }
        return $result;
    }
}

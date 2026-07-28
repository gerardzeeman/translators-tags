<?php

namespace App\Service\Embed;

/**
 * Parses the body of a blog embed fenced code block (simple "sleutel: waarde"
 * lines, one per line) into an associative array. Deliberately not a YAML
 * parser: the embed config is written by the dropdown-picker UI and only
 * ever needs flat scalar key/value pairs, so a line-based parser avoids an
 * extra dependency.
 */
class EmbedConfigParser
{
    /** @return array<string, string> */
    public function parse(string $body): array
    {
        $config = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $config[trim(strtolower($key))] = trim($value);
        }
        return $config;
    }

    public static function bool(array $config, string $key, bool $default = false): bool
    {
        $value = strtolower($config[$key] ?? '');
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['ja', 'true', '1', 'yes'], true);
    }

    public static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? '';
        return ctype_digit($value) ? (int) $value : $default;
    }

    public static function str(array $config, string $key, ?string $default = null): ?string
    {
        $value = trim($config[$key] ?? '');
        return $value === '' ? $default : $value;
    }
}

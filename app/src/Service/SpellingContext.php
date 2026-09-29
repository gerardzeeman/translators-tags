<?php

namespace App\Service;

/**
 * The spots of one text (a segment's Dutch) the spelling rules go through:
 * how often each old form has come by so far -- its n-th occurrence is spot
 * n, however the text is split into parts (paragraphs, text around Hebrew
 * words) -- and the choices made at single spots (spelling_choice): another
 * of the rules for that old form (its id), or 0 for Los's own form.
 */
final class SpellingContext
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @param array<string, array<int, int>> $choices key => occurrence => rule id (0: keep Los) */
    public function __construct(private readonly array $choices = []) {}

    /** The next occurrence (1, 2, ...) of an old form. */
    public function next(string $key): int
    {
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    /** The choice at a spot: a rule id, 0 (Los's form), or null (the default). */
    public function choice(string $key, int $occurrence): ?int
    {
        return $this->choices[$key][$occurrence] ?? null;
    }
}

<?php

namespace App\Service;

use App\Repository\BlogRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Turns a blog title into a unique URL slug, e.g. "Voorbeeld" -> "voorbeeld".
 * On collision, appends -2, -3, ... until a free slug is found.
 */
class BlogSlugGenerator
{
    private AsciiSlugger $slugger;

    public function __construct(private readonly BlogRepository $blogRepository)
    {
        $this->slugger = new AsciiSlugger('nl');
    }

    public function generate(string $title, ?int $excludeBlogId = null): string
    {
        $base = strtolower((string) $this->slugger->slug($title));
        if ($base === '') {
            $base = 'blog';
        }

        $slug = $base;
        $suffix = 2;
        while ($this->slugTaken($slug, $excludeBlogId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $excludeBlogId): bool
    {
        $existing = $this->blogRepository->findBySlug($slug);
        if (!$existing) {
            return false;
        }

        return $existing->getId() !== $excludeBlogId;
    }
}

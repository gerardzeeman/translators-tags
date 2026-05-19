<?php

namespace App\Entity;

use App\Repository\TranslationWordRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TranslationWordRepository::class)]
#[ORM\Table(name: 'translation_words')]
#[ORM\UniqueConstraint(name: 'tw_pos_unique', columns: ['verse_id', 'word_position'])]
class TranslationWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TranslationVerse::class, inversedBy: 'words')]
    #[ORM\JoinColumn(name: 'verse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TranslationVerse $verse;

    #[ORM\Column(type: 'smallint')]
    private int $wordPosition;

    #[ORM\Column(type: 'text')]
    private string $wordText;

    #[ORM\Column(type: 'text')]
    private string $wordNormalised;

    #[ORM\Column(type: 'smallint')]
    private int $charStart;

    #[ORM\Column(type: 'smallint')]
    private int $charEnd;

    #[ORM\OneToMany(mappedBy: 'translationWord', targetEntity: WordLink::class)]
    private Collection $wordLinks;

    public function __construct()
    {
        $this->wordLinks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getVerse(): TranslationVerse { return $this->verse; }
    public function getWordPosition(): int { return $this->wordPosition; }
    public function getWordText(): string { return $this->wordText; }
    public function getWordNormalised(): string { return $this->wordNormalised; }
    public function getCharStart(): int { return $this->charStart; }
    public function getCharEnd(): int { return $this->charEnd; }
    public function getWordLinks(): Collection { return $this->wordLinks; }

    /** True when at least one link with a manual confirmation exists. */
    public function isManuallyVerified(): bool
    {
        foreach ($this->wordLinks as $link) {
            foreach ($link->getConfidences() as $conf) {
                if ($conf->getMethod() === 'manual') {
                    return true;
                }
            }
        }
        return false;
    }

    public function getBestConfidenceMethod(): ?string
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($this->wordLinks as $link) {
            foreach ($link->getConfidences() as $conf) {
                if ($conf->getScore() > $bestScore) {
                    $bestScore = $conf->getScore();
                    $best = $conf->getMethod();
                }
            }
        }
        return $best;
    }
}

<?php

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\Table(name: 'books')]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id;

    #[ORM\Column(type: 'string', length: 3)]
    private string $usfmCode;

    #[ORM\Column(type: 'string', length: 2)]
    private string $testament;

    #[ORM\Column(type: 'text')]
    private string $nameNl;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nameOriginal = null;

    #[ORM\Column(type: 'smallint')]
    private int $chapterCount;

    #[ORM\OneToMany(mappedBy: 'book', targetEntity: HebrewWord::class)]
    private Collection $hebrewWords;

    #[ORM\OneToMany(mappedBy: 'book', targetEntity: GreekWord::class)]
    private Collection $greekWords;

    #[ORM\OneToMany(mappedBy: 'book', targetEntity: TranslationVerse::class)]
    private Collection $translationVerses;

    public function __construct()
    {
        $this->hebrewWords       = new ArrayCollection();
        $this->greekWords        = new ArrayCollection();
        $this->translationVerses = new ArrayCollection();
    }

    public function getId(): int { return $this->id; }
    public function getUsfmCode(): string { return $this->usfmCode; }
    public function getTestament(): string { return $this->testament; }
    public function getNameNl(): string { return $this->nameNl; }
    public function getNameOriginal(): ?string { return $this->nameOriginal; }
    public function getChapterCount(): int { return $this->chapterCount; }

    public function isOldTestament(): bool { return $this->testament === 'OT'; }
    public function isNewTestament(): bool { return $this->testament === 'NT'; }

    public function getHebrewWords(): Collection { return $this->hebrewWords; }
    public function getGreekWords(): Collection { return $this->greekWords; }
    public function getTranslationVerses(): Collection { return $this->translationVerses; }
}

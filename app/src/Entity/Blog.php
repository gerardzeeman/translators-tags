<?php

namespace App\Entity;

use App\Repository\BlogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlogRepository::class)]
#[ORM\Table(name: 'blogs')]
class Blog
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const VISIBILITY_PUBLIC     = 'public';
    public const VISIBILITY_LOGGED_IN  = 'logged_in';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $contentMd = '';

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'string', length: 20)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }

    public function getContentMd(): string { return $this->contentMd; }
    public function setContentMd(string $contentMd): self { $this->contentMd = $contentMd; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isPublished(): bool { return $this->status === self::STATUS_PUBLISHED; }

    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): self { $this->visibility = $visibility; return $this; }
    public function isPublic(): bool { return $this->visibility === self::VISIBILITY_PUBLIC; }

    public function getAuthor(): User { return $this->author; }
    public function setAuthor(User $author): self { $this->author = $author; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    public function publish(): self
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt ??= new \DateTimeImmutable();
        return $this;
    }

    public function unpublish(): self
    {
        $this->status = self::STATUS_DRAFT;
        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\NewsDigestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsDigestRepository::class)]
#[ORM\Table(name: 'news_digests')]
class NewsDigest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $url;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'integer')]
    private int $successCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $failureCount = 0;

    public function __construct(string $idempotencyKey, string $title, string $body, ?string $url)
    {
        $this->idempotencyKey = $idempotencyKey;
        $this->title = $title;
        $this->body = $body;
        $this->url = $url;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getUrl(): ?string { return $this->url; }
    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }

    public function getSuccessCount(): int { return $this->successCount; }
    public function getFailureCount(): int { return $this->failureCount; }

    public function incrementSuccess(int $by = 1): self { $this->successCount += $by; return $this; }
    public function incrementFailure(int $by = 1): self { $this->failureCount += $by; return $this; }
}

<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_user_endpoint', columns: ['user_id', 'endpoint'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(type: 'string', length: 255)]
    private string $p256dhKey;

    #[ORM\Column(type: 'string', length: 255)]
    private string $authKey;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFailureAt = null;

    public function __construct(User $user, string $endpoint)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getEndpoint(): string { return $this->endpoint; }

    public function setKeys(string $p256dh, string $auth): self
    {
        $this->p256dhKey = $p256dh;
        $this->authKey = $auth;
        return $this;
    }

    public function getP256dhKey(): string { return $this->p256dhKey; }
    public function getAuthKey(): string { return $this->authKey; }

    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markFailure(): self { $this->lastFailureAt = new \DateTimeImmutable(); return $this; }
    public function getLastFailureAt(): ?\DateTimeImmutable { return $this->lastFailureAt; }
}

<?php

namespace App\Repository;

use App\Entity\NewsDigest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NewsDigestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsDigest::class);
    }

    public function findOneByIdempotencyKey(string $key): ?NewsDigest
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }
}

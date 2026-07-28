<?php

namespace App\Repository;

use App\Entity\Blog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Blog::class);
    }

    public function findBySlug(string $slug): ?Blog
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->count(['slug' => $slug]) > 0;
    }

    /**
     * Published blogs visible to the current audience, newest first.
     * $includeLoggedIn controls whether 'logged_in'-visibility blogs are included.
     *
     * @return Blog[]
     */
    public function findPublishedForAudience(bool $includeLoggedIn): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->setParameter('status', Blog::STATUS_PUBLISHED)
            ->orderBy('b.publishedAt', 'DESC');

        if (!$includeLoggedIn) {
            $qb->andWhere('b.visibility = :visibility')
               ->setParameter('visibility', Blog::VISIBILITY_PUBLIC);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Blog[]
     */
    public function findAllByAuthorOrderedByUpdated(int $authorId): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.author = :authorId')
            ->setParameter('authorId', $authorId)
            ->orderBy('b.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Blog[]
     */
    public function findAllOrderedByUpdated(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

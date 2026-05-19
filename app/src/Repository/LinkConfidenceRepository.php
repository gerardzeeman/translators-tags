<?php
namespace App\Repository;
use App\Entity\LinkConfidence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class LinkConfidenceRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $r) { parent::__construct($r, LinkConfidence::class); }
}

<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByUserAndEndpoint(User $user, string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['user' => $user, 'endpoint' => $endpoint]);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * All subscriptions belonging to a user who is (directly or via
     * role_hierarchy, e.g. ROLE_ADMIN) entitled to $role.
     *
     * @return PushSubscription[]
     */
    public function findAllForRole(string $role): array
    {
        $matching = [];
        foreach ($this->findAll() as $subscription) {
            $reachable = $this->roleHierarchy->getReachableRoleNames($subscription->getUser()->getRoles());
            if (in_array($role, $reachable, true)) {
                $matching[] = $subscription;
            }
        }
        return $matching;
    }
}

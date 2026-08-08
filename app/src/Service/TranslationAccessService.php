<?php

namespace App\Service;

use App\Entity\Translation;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Single source of truth for "which role gates viewing/linking this
 * translation". SV is always unrestricted (it's the source-language
 * authority); every other translation maps to its own viewer role.
 */
class TranslationAccessService
{
    private const ROLE_BY_CODE = [
        'HSV'   => 'ROLE_VIEWER_HSV',
        'SVGBS' => 'ROLE_VIEWER_SVGBS',
    ];

    public function __construct(private readonly Security $security) {}

    /**
     * The role required to view the given translation code, or null if
     * it's unrestricted (SV, or any future translation not yet gated).
     */
    public function requiredRole(string $code): ?string
    {
        return self::ROLE_BY_CODE[$code] ?? null;
    }

    /**
     * Whether the CURRENTLY AUTHENTICATED user may view this translation.
     */
    public function isVisible(Translation|string $translation): bool
    {
        $code = $translation instanceof Translation ? $translation->getCode() : $translation;
        $role = $this->requiredRole($code);
        return $role === null || $this->security->isGranted($role);
    }

    /**
     * Whether a user holding exactly $roles (already role-hierarchy-resolved,
     * see RoleHierarchyInterface::getReachableRoleNames()) may view this
     * translation. Used where the "current user" isn't the right subject,
     * e.g. gating a blog embed by its AUTHOR's roles, not the visitor's.
     *
     * @param string[] $roles
     */
    public function isVisibleForRoles(string $code, array $roles): bool
    {
        $role = $this->requiredRole($code);
        return $role === null || in_array($role, $roles, true);
    }
}

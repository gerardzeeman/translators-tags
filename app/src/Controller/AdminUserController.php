<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    private const ASSIGNABLE_ROLES = [
        'ROLE_VIEWER'                => 'Viewer',
        'ROLE_VIEWER_HSV'            => 'Viewer HSV',
        'ROLE_LINKER'                => 'Linker',
        'ROLE_EDIT_STRONG_TRNL'      => 'Strong\'s vertaling bewerken',
        'ROLE_EDIT_INSTITUTIO_TRNL'  => 'Institutie-vertaalvoorstellen indienen',
        'ROLE_REVIEW_INSTITUTIO_TRNL' => 'Institutie-vertaalvoorstellen beoordelen',
        'ROLE_BLOGGER'               => 'Blogger',
        'ROLE_ADMIN'                 => 'Admin',
    ];

    #[Route('', name: 'admin_users_index')]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'users' => $userRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        UserRepository $userRepository,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_new', $request->request->get('_csrf_token'))) {
                $error = 'Ongeldig formulierverzoek.';
            } else {
                $email    = trim($request->request->get('email', ''));
                $name     = trim($request->request->get('display_name', ''));
                $password = $request->request->get('password', '');
                $roles    = array_intersect(
                    (array) $request->request->all('roles'),
                    array_keys(self::ASSIGNABLE_ROLES)
                );

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Voer een geldig e-mailadres in.';
                } elseif (strlen($name) < 2) {
                    $error = 'Naam moet minimaal 2 tekens bevatten.';
                } elseif (strlen($password) < 8) {
                    $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
                } elseif ($userRepository->findOneBy(['email' => $email])) {
                    $error = 'Er bestaat al een account met dit e-mailadres.';
                }

                if (!$error) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setDisplayName($name);
                    $user->setPassword($passwordHasher->hashPassword($user, $password));
                    $user->setRoles(array_values($roles));
                    $user->setIsVerified(true);

                    $em->persist($user);
                    $em->flush();

                    $this->addFlash('success', "Gebruiker {$email} aangemaakt.");
                    return $this->redirectToRoute('admin_users_index');
                }
            }
        }

        return $this->render('admin/users/new.html.twig', [
            'error'            => $error,
            'assignable_roles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_user_edit_' . $user->getId(), $request->request->get('_csrf_token'))) {
                $error = 'Ongeldig formulierverzoek.';
            } else {
                $name  = trim($request->request->get('display_name', ''));
                $roles = array_intersect(
                    (array) $request->request->all('roles'),
                    array_keys(self::ASSIGNABLE_ROLES)
                );

                if (strlen($name) < 2) {
                    $error = 'Naam moet minimaal 2 tekens bevatten.';
                }

                if (!$error) {
                    $user->setDisplayName($name);
                    $user->setRoles(array_values($roles));
                    $em->flush();

                    $this->addFlash('success', 'Gebruiker bijgewerkt.');
                    return $this->redirectToRoute('admin_users_index');
                }
            }
        }

        return $this->render('admin/users/edit.html.twig', [
            'user'             => $user,
            'error'            => $error,
            'assignable_roles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_users_delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('admin_users_index');
        }

        // Prevent self-deletion
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Je kunt je eigen account niet verwijderen.');
            return $this->redirectToRoute('admin_users_index');
        }

        $em->remove($user);
        $em->flush();

        $this->addFlash('success', "Gebruiker {$user->getEmail()} verwijderd.");
        return $this->redirectToRoute('admin_users_index');
    }
}

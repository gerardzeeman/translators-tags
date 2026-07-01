<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Redirect already logged-in users
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        // Intercepted by the security firewall — this code is never reached.
        throw new \LogicException('This method should never be called directly.');
    }

    #[Route('/profile', name: 'app_profile')]
    public function profile(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;
        $success = null;

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'update_name') {
                if (!$this->isCsrfTokenValid('profile_name', $request->request->get('_csrf_token'))) {
                    $error = 'Ongeldig formulierverzoek.';
                } else {
                    $name = trim($request->request->get('display_name', ''));
                    if (strlen($name) < 2 || strlen($name) > 100) {
                        $error = 'Naam moet tussen 2 en 100 tekens zijn.';
                    } else {
                        $user->setDisplayName($name);
                        $em->flush();
                        $success = 'Naam bijgewerkt.';
                    }
                }
            } elseif ($action === 'change_password') {
                if (!$this->isCsrfTokenValid('profile_password', $request->request->get('_csrf_token'))) {
                    $error = 'Ongeldig formulierverzoek.';
                } else {
                    $current = $request->request->get('current_password', '');
                    $new     = $request->request->get('new_password', '');
                    $confirm = $request->request->get('confirm_password', '');

                    if (!$passwordHasher->isPasswordValid($user, $current)) {
                        $error = 'Huidig wachtwoord is onjuist.';
                    } elseif (strlen($new) < 8) {
                        $error = 'Nieuw wachtwoord moet minimaal 8 tekens bevatten.';
                    } elseif ($new !== $confirm) {
                        $error = 'Wachtwoorden komen niet overeen.';
                    } else {
                        $user->setPassword($passwordHasher->hashPassword($user, $new));
                        $em->flush();
                        $success = 'Wachtwoord gewijzigd.';
                    }
                }
            }
        }

        return $this->render('security/profile.html.twig', [
            'user'    => $user,
            'error'   => $error,
            'success' => $success,
        ]);
    }
}

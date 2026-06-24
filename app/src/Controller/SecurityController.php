<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        RateLimiterFactory $registerIpLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error       = null;
        $lastEmail   = '';
        $lastName    = '';

        if ($request->isMethod('POST')) {
            $limiter = $registerIpLimiter->create($request->getClientIp());
            if (!$limiter->consume(1)->isAccepted()) {
                $error = 'Te veel registratiepogingen. Probeer het over 15 minuten opnieuw.';
            }

            $email    = trim($request->request->get('email', ''));
            $name     = trim($request->request->get('display_name', ''));
            $password = $request->request->get('password', '');
            $confirm  = $request->request->get('confirm_password', '');

            if ($error) {
                // rate limited — fall through to render with error
            } elseif (!$this->isCsrfTokenValid('register', $request->request->get('_csrf_token'))) {
                $error = 'Ongeldig formulierverzoek.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Voer een geldig e-mailadres in.';
            } elseif (strlen($name) < 2) {
                $error = 'Naam moet minimaal 2 tekens bevatten.';
            } elseif (strlen($password) < 8) {
                $error = 'Wachtwoord moet minimaal 8 tekens bevatten.';
            } elseif ($password !== $confirm) {
                $error = 'Wachtwoorden komen niet overeen.';
            } elseif ($userRepository->findByEmail($email)) {
                $error = 'Registratie mislukt. Controleer uw gegevens en probeer het opnieuw.';
            }

            if (!$error) {
                $user = new User();
                $user->setEmail($email);
                $user->setDisplayName($name);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setIsVerified(true); // Auto-verify; add email verification later if needed

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Account aangemaakt. U kunt nu inloggen.');
                return $this->redirectToRoute('app_login');
            }

            $lastEmail = $email;
            $lastName  = $name;
        }

        return $this->render('security/register.html.twig', [
            'error'      => $error,
            'last_email' => $lastEmail,
            'last_name'  => $lastName,
        ]);
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
                    if (strlen($name) < 2) {
                        $error = 'Naam moet minimaal 2 tekens bevatten.';
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

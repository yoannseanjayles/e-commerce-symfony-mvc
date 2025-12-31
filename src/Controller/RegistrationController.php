<?php

namespace App\Controller;

use App\Entity\Users;
use App\Form\RegistrationFormType;
use App\Repository\UsersRepository;
use App\Security\UsersAuthenticator;
use App\Service\SendMailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        UsersAuthenticator $authenticator,
        EntityManagerInterface $entityManager,
        SendMailService $mail,
        SessionInterface $session,
        #[Autowire(service: 'limiter.registration')] RateLimiterFactory $registrationLimiter
    ): Response
    {
        $user = new Users();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $ip = $request->getClientIp() ?? 'unknown';
            $rateLimit = $registrationLimiter->create($ip)->consume(1);
            if (!$rateLimit->isAccepted()) {
                $this->addFlash('danger', 'Trop de tentatives. Merci de réessayer plus tard.');
                return $this->redirectToRoute('app_register');
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // encode the plain password
            $user->setPassword(
            $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();

            $expires = time() + (3 * 60 * 60);
            $unsignedUrl = $this->generateUrl('verify_user', [
                'id' => $user->getId(),
                'expires' => $expires,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
            $verificationUrl = (new UriSigner($this->getParameter('kernel.secret')))->sign($unsignedUrl);

            // On envoie un mail
            $mail->send(
                'no-reply@monsite.net',
                $user->getEmail(),
                'Activation de votre compte sur le site e-commerce',
                'register',
                [
                    'user' => $user,
                    'verification_url' => $verificationUrl,
                    'expires_at' => $expires,
                ]
            );

            // Authentifier l'utilisateur
            $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request
            );

            // Si une validation de panier était en attente, rediriger vers la validation
            if ($session->get('pending_cart_validation')) {
                $session->remove('pending_cart_validation');
                return $this->redirectToRoute('cart_validate');
            }

            return $this->redirectToRoute('shionhouse_index');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verif', name: 'verify_user')]
    public function verifyUser(Request $request, UsersRepository $usersRepository, EntityManagerInterface $em): Response
    {
        $signer = new UriSigner($this->getParameter('kernel.secret'));
        if (!$signer->checkRequest($request)) {
            $this->addFlash('danger', 'Le lien de vérification est invalide.');
            return $this->redirectToRoute('app_login');
        }

        $expires = (int) $request->query->get('expires', 0);
        if ($expires <= 0 || $expires < time()) {
            $this->addFlash('danger', 'Le lien de vérification a expiré.');
            return $this->redirectToRoute('app_login');
        }

        $userId = (int) $request->query->get('id', 0);
        if ($userId <= 0) {
            $this->addFlash('danger', 'Le lien de vérification est invalide.');
            return $this->redirectToRoute('app_login');
        }

        $user = $usersRepository->find($userId);
        if ($user && !$user->getIsVerified()) {
            $user->setIsVerified(true);
            $em->flush();
            $this->addFlash('success', 'Utilisateur activé');
            return $this->redirectToRoute('profile_index');
        }

        $this->addFlash('warning', 'Cet utilisateur est déjà activé ou introuvable.');
        return $this->redirectToRoute('profile_index');
    }

    #[Route('/renvoiverif', name: 'resend_verif', methods: ['POST'])]
    public function resendVerif(
        Request $request,
        SendMailService $mail,
        UsersRepository $usersRepository,
        #[Autowire(service: 'limiter.resend_verification')] RateLimiterFactory $resendVerificationLimiter
    ): Response
    {
        $user = $this->getUser();

        if(!$user){
            $this->addFlash('danger', 'Vous devez être connecté pour accéder à cette page');
            return $this->redirectToRoute('app_login');    
        }

        if($user->getIsVerified()){
            $this->addFlash('warning', 'Cet utilisateur est déjà activé');
            return $this->redirectToRoute('profile_index');    
        }

        if (!$this->isCsrfTokenValid('resend_verif', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Formulaire expiré. Merci de réessayer.');
            return $this->redirectToRoute('profile_index');
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $rateLimit = $resendVerificationLimiter->create($ip)->consume(1);
        if (!$rateLimit->isAccepted()) {
            $this->addFlash('danger', 'Trop de tentatives. Merci de réessayer plus tard.');
            return $this->redirectToRoute('profile_index');
        }

        $expires = time() + (3 * 60 * 60);
        $unsignedUrl = $this->generateUrl('verify_user', [
            'id' => $user->getId(),
            'expires' => $expires,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $verificationUrl = (new UriSigner($this->getParameter('kernel.secret')))->sign($unsignedUrl);

        // On envoie un mail
        $mail->send(
            'no-reply@monsite.net',
            $user->getEmail(),
            'Activation de votre compte sur le site e-commerce',
            'register',
            [
                'user' => $user,
                'verification_url' => $verificationUrl,
                'expires_at' => $expires,
            ]
        );
        $this->addFlash('success', 'Email de vérification envoyé');
        return $this->redirectToRoute('profile_index');
    }
}

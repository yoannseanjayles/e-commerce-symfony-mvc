<?php

namespace App\Controller;

use App\Entity\Orders;
use App\Repository\OrdersRepository;
use App\Service\Payment\StripeCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class StripeController extends AbstractController
{
    #[Route('/stripe/success', name: 'stripe_success')]
    public function success(Request $request, SessionInterface $session, StripeCheckoutService $stripeCheckoutService): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        if ($sessionId === '') {
            $this->addFlash('warning', 'Paiement Stripe: session manquante.');
            return $this->redirectToRoute('cart_index');
        }

        $order = $stripeCheckoutService->findOrderByCheckoutSessionId($sessionId);
        if (!$order) {
            $this->addFlash('warning', 'Paiement Stripe: commande introuvable.');
            return $this->redirectToRoute('cart_index');
        }

        try {
            $paid = $stripeCheckoutService->finalizeOrderIfPaid($order, $sessionId);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Paiement Stripe: erreur lors de la confirmation.');
            return $this->redirectToRoute('cart_index');
        }

        if ($paid) {
            $session->remove('panier');
            $this->addFlash('success', sprintf('Paiement confirmé ! Référence: %s', (string) $order->getReference()));
        } else {
            $this->addFlash('warning', 'Paiement non confirmé pour le moment.');
        }

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/stripe/cancel', name: 'stripe_cancel')]
    public function cancel(SessionInterface $session, OrdersRepository $ordersRepository, EntityManagerInterface $entityManager): Response
    {
        $orderId = (int) $session->get('cart_validate_order_id', 0);
        if ($orderId > 0) {
            $order = $ordersRepository->find($orderId);
            if ($order instanceof Orders && $order->getPaymentStatus() === Orders::PAYMENT_STATUS_PENDING) {
                $order->setPaymentStatus(Orders::PAYMENT_STATUS_CANCELED);
                $order->setStatus(Orders::STATUS_CANCELED);
                $entityManager->flush();
            }
        }

        $this->addFlash('warning', 'Paiement annulé. Votre panier est toujours disponible.');
        return $this->redirectToRoute('cart_index');
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request, StripeCheckoutService $stripeCheckoutService): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('stripe-signature');

        try {
            $event = $stripeCheckoutService->constructWebhookEvent($payload, $signature);
        } catch (\Throwable $e) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        if (($event->type ?? null) === 'checkout.session.completed') {
            $sessionObj = $event->data->object ?? null;
            $sessionId = is_object($sessionObj) && property_exists($sessionObj, 'id') ? (string) $sessionObj->id : '';

            if ($sessionId !== '') {
                $order = $stripeCheckoutService->findOrderByCheckoutSessionId($sessionId);
                if ($order) {
                    try {
                        $stripeCheckoutService->finalizeOrderIfPaid($order, $sessionId);
                    } catch (\Throwable $e) {
                        // Swallow errors to avoid webhook retries storms; payment will be reconciled manually.
                    }
                }
            }
        }

        return new Response('OK', Response::HTTP_OK);
    }
}

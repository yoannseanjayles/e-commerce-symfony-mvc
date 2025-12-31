<?php

namespace App\Controller;

use App\Entity\ContactMessage;
use App\Form\ContactMessageType;
use App\Repository\AboutPageRepository;
use App\Repository\CategoriesRepository;
use App\Repository\HeroRepository;
use App\Repository\CouponsRepository;
use App\Repository\PopularItemRepository;
use App\Repository\ProductsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{
   #[Route('/', name: 'shionhouse_index')]
    public function index(HeroRepository $heroRepository, CouponsRepository $couponsRepository, PopularItemRepository $popularItemRepository, ProductsRepository $productsRepository): Response
    {
        $heroes = $heroRepository->findActiveHeros();
        
        $coupons = $couponsRepository->createQueryBuilder('c')
            ->where('c.is_valid = :valid')
            ->andWhere('c.validity > :now')
            ->setParameter('valid', true)
            ->setParameter('now', new \DateTime())
            ->orderBy('c.discount', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();
        
        $popularItems = $popularItemRepository->findActiveItems();
        $newArrivals = $productsRepository->findNewArrivals(8);
        
        return $this->render('main/index.html.twig', [
            'heroes' => $heroes,
            'coupons' => $coupons,
            'popularItems' => $popularItems,
            'newArrivals' => $newArrivals
        ]);
    }

    #[Route('/contact', name: 'contact')]
    public function contact(Request $request, EntityManagerInterface $em): Response
    {
        $contactMessage = new ContactMessage();
        $form = $this->createForm(ContactMessageType::class, $contactMessage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($contactMessage);
            $em->flush();

            $this->addFlash('success', 'Votre message a été envoyé avec succès ! Nous vous répondrons dans les plus brefs délais.');
            return $this->redirectToRoute('contact');
        }

        return $this->render('main/contact.html.twig', [
            'contactForm' => $form->createView()
        ]);
    }

    #[Route('/about', name: 'about')]
    public function about(AboutPageRepository $aboutPageRepository): Response
    {
        $aboutPage = $aboutPageRepository->findOneBy([]);
        
        return $this->render('main/about.html.twig', [
            'aboutPage' => $aboutPage
        ]);
    }
}

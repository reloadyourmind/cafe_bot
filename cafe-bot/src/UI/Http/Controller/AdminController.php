<?php

namespace App\UI\Http\Controller;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(param: 'env(ADMIN_PANEL_SECRET)')] private readonly string $adminPanelSecret,
    ) {}

    #[Route('/admin/{secret}', name: 'admin_panel', methods: ['GET', 'POST'])]
    public function adminPanel(Request $request, string $secret): Response
    {
        if ($secret !== $this->adminPanelSecret) {
            return new Response('Forbidden', 403);
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            
            if ($action === 'add_admin') {
                $telegramUserId = (int) $request->request->get('telegram_user_id');
                $name = trim($request->request->get('name'));
                $nickname = trim($request->request->get('nickname')) ?: null;
                
                if ($telegramUserId && $name) {
                    // Check if admin already exists
                    $existingAdmin = $this->entityManager->getRepository(Admin::class)
                        ->findOneBy(['telegramUserId' => $telegramUserId]);
                    
                    if (!$existingAdmin) {
                        $admin = new Admin($telegramUserId, $name, $nickname);
                        $this->entityManager->persist($admin);
                        $this->entityManager->flush();
                        $this->addFlash('success', 'Administrator added successfully!');
                    } else {
                        $this->addFlash('error', 'Administrator with this Telegram ID already exists!');
                    }
                } else {
                    $this->addFlash('error', 'Please fill in all required fields!');
                }
            } elseif ($action === 'remove_admin') {
                $adminId = (int) $request->request->get('admin_id');
                $admin = $this->entityManager->getRepository(Admin::class)->find($adminId);
                
                if ($admin) {
                    $this->entityManager->remove($admin);
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Administrator removed successfully!');
                } else {
                    $this->addFlash('error', 'Administrator not found!');
                }
            } elseif ($action === 'toggle_admin') {
                $adminId = (int) $request->request->get('admin_id');
                $admin = $this->entityManager->getRepository(Admin::class)->find($adminId);
                
                if ($admin) {
                    $admin->setActive(!$admin->isActive());
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Administrator status updated!');
                } else {
                    $this->addFlash('error', 'Administrator not found!');
                }
            }
            
            return $this->redirectToRoute('admin_panel', ['secret' => $secret]);
        }

        $admins = $this->entityManager->getRepository(Admin::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/panel.html.twig', [
            'admins' => $admins,
        ]);
    }
}
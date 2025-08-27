<?php

namespace App\UI\Http\Controller;

use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Infrastructure\Telegram\TelegramClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramClient $telegramClient,
        #[Autowire(param: 'env(TELEGRAM_ADMIN_IDS)')] private readonly string $adminIds,
        #[Autowire(param: 'env(TELEGRAM_WEBHOOK_SECRET)')] private readonly string $webhookSecret,
    ) {}

    #[Route('/telegram/webhook/{secret}', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request, string $secret): Response
    {
        if ($secret !== $this->webhookSecret) {
            return new Response('Forbidden', 403);
        }

        $update = json_decode($request->getContent(), true) ?? [];
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$message) {
            return new JsonResponse(['ok' => true]);
        }

        $chatId = (int)($message['chat']['id'] ?? 0);
        $text = trim((string)($message['text'] ?? ''));

        if ($text === '/start') {
            $this->telegramClient->sendMessage($chatId, "☕️ Welcome to Cafe Bot!\nUse /menu to browse 🍽️ and /order to place 📦");
            return new JsonResponse(['ok' => true]);
        }

        if ($text === '/menu') {
            $items = $this->entityManager->getRepository(MenuItem::class)->findBy(['active' => true]);
            if (!$items) {
                $this->telegramClient->sendMessage($chatId, "📭 Menu is empty. Please check back later.");
            } else {
                $lines = ["📋 <b>Menu</b>:"]; 
                foreach ($items as $item) {
                    $price = number_format($item->getPriceCents() / 100, 2);
                    $lines[] = sprintf("• %s — <b>$%s</b>", htmlspecialchars($item->getName()), $price);
                }
                $this->telegramClient->sendMessage($chatId, implode("\n", $lines));
            }
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/additem')) {
            if (!$this->isAdmin($message)) {
                $this->telegramClient->sendMessage($chatId, "⛔ You are not allowed to do this.");
                return new JsonResponse(['ok' => true]);
            }
            // Format: /additem Name | 3.50 | description
            $payload = trim(substr($text, strlen('/additem')));
            if ($payload === '') {
                $this->telegramClient->sendMessage($chatId, "🛠️ Usage: /additem Name | 3.50 | optional description");
                return new JsonResponse(['ok' => true]);
            }
            $parts = array_map('trim', explode('|', $payload));
            $name = $parts[0] ?? '';
            $priceCents = isset($parts[1]) ? (int)round(((float)$parts[1]) * 100) : 0;
            $desc = $parts[2] ?? null;
            if ($name === '' || $priceCents <= 0) {
                $this->telegramClient->sendMessage($chatId, "⚠️ Please provide name and positive price. Example: /additem Espresso | 2.50 | Strong coffee");
                return new JsonResponse(['ok' => true]);
            }
            $item = new MenuItem($name, $priceCents, $desc);
            $this->entityManager->persist($item);
            $this->entityManager->flush();
            $this->telegramClient->sendMessage($chatId, "✅ Added: <b>" . htmlspecialchars($name) . "</b> at $" . number_format($priceCents/100, 2));
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/order')) {
            // Format: /order ItemName | qty
            $payload = trim(substr($text, strlen('/order')));
            if ($payload === '') {
                $this->telegramClient->sendMessage($chatId, "🛒 Usage: /order Item Name | 2\nUse /menu to see items 🍽️");
                return new JsonResponse(['ok' => true]);
            }
            $parts = array_map('trim', explode('|', $payload));
            $name = $parts[0] ?? '';
            $qty = max(1, (int)($parts[1] ?? 1));

            $menuRepo = $this->entityManager->getRepository(MenuItem::class);
            $item = $menuRepo->findOneBy(['name' => $name, 'active' => true]);
            if (!$item) {
                $this->telegramClient->sendMessage($chatId, "❌ Item not found. Try /menu");
                return new JsonResponse(['ok' => true]);
            }

            $order = new Order($chatId);
            $orderItem = new OrderItem($item, $qty);
            $order->addItem($orderItem);
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->telegramClient->sendMessage($chatId, sprintf("📦 Order created! #%d\n%s × %d = <b>$%s</b>\nUse /confirm %d to confirm ✅", $order->getId(), htmlspecialchars($item->getName()), $qty, number_format($orderItem->getSubtotalCents()/100, 2), $order->getId()));
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/confirm')) {
            $orderId = (int)trim(substr($text, strlen('/confirm')));
            $order = $this->entityManager->getRepository(Order::class)->find($orderId);
            if (!$order || $order->getTelegramUserId() !== $chatId) {
                $this->telegramClient->sendMessage($chatId, "⚠️ Order not found.");
                return new JsonResponse(['ok' => true]);
            }
            $order->setStatus(Order::STATUS_CONFIRMED);
            $this->entityManager->flush();
            $this->telegramClient->sendMessage($chatId, "✅ Order confirmed! We will notify you when it's ready. ⏳");
            $this->notifyAdmins(sprintf("🆕 New order #%d by %d. Total: $%s", $order->getId(), $chatId, number_format($order->getTotalCents()/100, 2)));
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/complete')) {
            if (!$this->isAdmin($message)) {
                $this->telegramClient->sendMessage($chatId, "⛔ Not allowed.");
                return new JsonResponse(['ok' => true]);
            }
            $orderId = (int)trim(substr($text, strlen('/complete')));
            $order = $this->entityManager->getRepository(Order::class)->find($orderId);
            if (!$order) {
                $this->telegramClient->sendMessage($chatId, "⚠️ Order not found.");
                return new JsonResponse(['ok' => true]);
            }
            $order->setStatus(Order::STATUS_COMPLETED);
            $this->entityManager->flush();
            $this->telegramClient->sendMessage($chatId, "✅ Order marked as completed.");
            $this->telegramClient->sendMessage($order->getTelegramUserId(), sprintf("🎉 Your order #%d is ready! Enjoy! 😋", $order->getId()));
            return new JsonResponse(['ok' => true]);
        }

        if ($text === '/orders') {
            if (!$this->isAdmin($message)) {
                $this->telegramClient->sendMessage($chatId, "⛔ Not allowed.");
                return new JsonResponse(['ok' => true]);
            }
            $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 10);
            if (!$orders) {
                $this->telegramClient->sendMessage($chatId, "📭 No orders yet.");
                return new JsonResponse(['ok' => true]);
            }
            $lines = ["📦 <b>Recent Orders</b>:"];
            foreach ($orders as $o) {
                $lines[] = sprintf("#%d — %s — $%s", $o->getId(), strtoupper($o->getStatus()), number_format($o->getTotalCents()/100, 2));
            }
            $this->telegramClient->sendMessage($chatId, implode("\n", $lines));
            return new JsonResponse(['ok' => true]);
        }

        $this->telegramClient->sendMessage($chatId, "🤖 Commands:\n/start — welcome\n/menu — list items\n/order Name | qty — place\n/confirm ID — confirm order\n\n<b>Admin</b>:\n/additem Name | price | desc\n/orders\n/complete ID");
        return new JsonResponse(['ok' => true]);
    }

    private function isAdmin(array $message): bool
    {
        $uid = (int)($message['from']['id'] ?? 0);
        $ids = array_filter(array_map('trim', explode(',', $this->adminIds)));
        return in_array((string)$uid, $ids, true);
    }
}


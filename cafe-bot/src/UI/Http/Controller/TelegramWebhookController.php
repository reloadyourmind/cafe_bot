<?php

namespace App\UI\Http\Controller;

use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\AdminSession;
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
        #[Autowire(param: 'env(PLACEHOLDER_IMAGE_URL)')] private readonly string $placeholderImageUrl,
    ) {}

    #[Route('/telegram/webhook/{secret}', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request, string $secret): Response
    {
        if ($secret !== $this->webhookSecret) {
            return new Response('Forbidden', 403);
        }

        $update = json_decode($request->getContent(), true) ?? [];
        $callback = $update['callback_query'] ?? null;
        $message = $update['message'] ?? $update['edited_message'] ?? ($callback['message'] ?? null);
        if (!$message) {
            return new JsonResponse(['ok' => true]);
        }

        $chatId = (int)($message['chat']['id'] ?? ($callback['from']['id'] ?? 0));
        $text = trim((string)($message['text'] ?? ''));

        if ($text === '/start') {
            $this->telegramClient->sendMessage($chatId, "☕️ Welcome to Cafe Bot!\nUse /menu to browse 🍽️ and /order to place 📦");
            return new JsonResponse(['ok' => true]);
        }

        // Handle admin wizard steps before commands
        if ($this->handleAdminWizard($chatId, $text)) {
            return new JsonResponse(['ok' => true]);
        }

        // Handle inline keyboard callbacks
        if ($callback) {
            $data = (string)($callback['data'] ?? '');
            if (preg_match('/^addqty:(-?\d+):(\d+)$/', $data, $m)) {
                $qty = max(1, (int)$m[1]);
                $itemId = (int)$m[2];
                $item = $this->entityManager->getRepository(MenuItem::class)->find($itemId);
                if ($item) {
                    $order = new Order($chatId);
                    $orderItem = new OrderItem($item, $qty);
                    $order->addItem($orderItem);
                    $this->entityManager->persist($order);
                    $this->entityManager->flush();
                    $this->telegramClient->answerCallbackQuery((string)$callback['id'], '🛒 Added x'.$qty);
                    $this->telegramClient->sendMessage($chatId, sprintf("🛒 <b>%s</b> × %d. Order #%d total $%s. /confirm %d", htmlspecialchars($item->getName()), $qty, $order->getId(), number_format($order->getTotalCents()/100, 2), $order->getId()));
                } else {
                    $this->telegramClient->answerCallbackQuery((string)$callback['id'], 'Item not found', true);
                }
                return new JsonResponse(['ok' => true]);
            }
            if (preg_match('/^qty:([+-]1):(\d+)$/', $data, $m)) {
                $delta = $m[1] === '+1' ? 1 : -1;
                $itemId = (int)$m[2];
                $textResp = $delta > 0 ? '➕' : '➖';
                $this->telegramClient->answerCallbackQuery((string)$callback['id'], $textResp);
                $this->telegramClient->sendMessage($chatId, "Tip: Tap Add 🛒 to add to cart");
                return new JsonResponse(['ok' => true]);
            }
            if (str_starts_with($data, 'add:')) {
                $id = (int)substr($data, 4);
                $item = $this->entityManager->getRepository(MenuItem::class)->find($id);
                if ($item) {
                    $order = new Order($chatId);
                    $orderItem = new OrderItem($item, 1);
                    $order->addItem($orderItem);
                    $this->entityManager->persist($order);
                    $this->entityManager->flush();
                    $this->telegramClient->answerCallbackQuery((string)$callback['id'], '🛒 Added to cart!');
                    $this->telegramClient->sendMessage($chatId, sprintf("🛒 <b>%s</b> added. Order #%d total $%s. Use /confirm %d", htmlspecialchars($item->getName()), $order->getId(), number_format($order->getTotalCents()/100, 2), $order->getId()));
                } else {
                    $this->telegramClient->answerCallbackQuery((string)$callback['id'], 'Item not found', true);
                }
                return new JsonResponse(['ok' => true]);
            }
        }

        if ($text === '/menu') {
            $items = $this->entityManager->getRepository(MenuItem::class)->findBy(['active' => true]);
            if (!$items) {
                $this->telegramClient->sendMessage($chatId, "📭 Menu is empty. Please check back later.");
                return new JsonResponse(['ok' => true]);
            }
            foreach ($items as $item) {
                $caption = sprintf("<b>%s</b>\n$%s\n%s", htmlspecialchars($item->getName()), number_format($item->getPriceCents()/100, 2), htmlspecialchars($item->getDescription() ?? ''));
                $keyboard = [
                    'inline_keyboard' => [[
                        ['text' => '➖', 'callback_data' => 'qty:-1:' . $item->getId()],
                        ['text' => 'Add 🛒', 'callback_data' => 'addqty:1:' . $item->getId()],
                        ['text' => '➕', 'callback_data' => 'qty:+1:' . $item->getId()],
                    ]],
                ];
                $photo = $item->getPhotoUrl() ?: $this->placeholderImageUrl;
                $this->telegramClient->sendPhoto($chatId, $photo, [
                    'caption' => $caption,
                    'reply_markup' => json_encode($keyboard),
                ]);
            }
            return new JsonResponse(['ok' => true]);
        }

        if (str_starts_with($text, '/additem')) {
            if (!$this->isAdmin($message)) {
                $this->telegramClient->sendMessage($chatId, "⛔ You are not allowed to do this.");
                return new JsonResponse(['ok' => true]);
            }
            // Start wizard
            $session = $this->entityManager->getRepository(AdminSession::class)->findOneBy(['telegramUserId' => $chatId]);
            if ($session) {
                $this->entityManager->remove($session);
                $this->entityManager->flush();
            }
            $session = new AdminSession($chatId, AdminSession::FLOW_ADD, 'name');
            $this->entityManager->persist($session);
            $this->entityManager->flush();
            $this->telegramClient->sendMessage($chatId, "🧩 Let's add a product!\nStep 1/4 — Send name ✍️");
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

    private function handleAdminWizard(int $chatId, string $text): bool
    {
        $repo = $this->entityManager->getRepository(AdminSession::class);
        $session = $repo->findOneBy(['telegramUserId' => $chatId]);
        if (!$session) { return false; }

        $data = $session->getData();
        switch ($session->getStep()) {
            case 'name':
                if ($text === '') { $this->telegramClient->sendMessage($chatId, "⚠️ Please send a non-empty name."); return true; }
                $data['name'] = $text;
                $session->setData($data);
                $session->setStep('price');
                $this->entityManager->flush();
                $this->telegramClient->sendMessage($chatId, "Step 2/4 — Send price, e.g. 3.50 💵");
                return true;
            case 'price':
                $price = (float)str_replace(',', '.', $text);
                if ($price <= 0) { $this->telegramClient->sendMessage($chatId, "⚠️ Send a positive price, e.g. 2.00"); return true; }
                $data['priceCents'] = (int)round($price * 100);
                $session->setData($data);
                $session->setStep('description');
                $this->entityManager->flush();
                $this->telegramClient->sendMessage($chatId, "Step 3/4 — Send description (or '-' to skip) 📝");
                return true;
            case 'description':
                $data['description'] = $text === '-' ? null : $text;
                $session->setData($data);
                $session->setStep('photo');
                $this->entityManager->flush();
                $this->telegramClient->sendMessage($chatId, "Step 4/4 — Send photo URL (or '-' to skip) 🖼️");
                return true;
            case 'photo':
                $data['photoUrl'] = $text === '-' ? null : $text;
                $name = $data['name'];
                $priceCents = (int)$data['priceCents'];
                $desc = $data['description'] ?? null;
                $photo = $data['photoUrl'] ?? null;
                $item = new MenuItem($name, $priceCents, $desc, $photo);
                $this->entityManager->persist($item);
                $this->entityManager->remove($session);
                $this->entityManager->flush();
                $this->telegramClient->sendMessage($chatId, sprintf("✅ Added <b>%s</b> $%s", htmlspecialchars($name), number_format($priceCents/100, 2)));
                return true;
        }
        return false;
    }

    private function isAdmin(array $message): bool
    {
        $uid = (int)($message['from']['id'] ?? 0);
        $ids = array_filter(array_map('trim', explode(',', $this->adminIds)));
        return in_array((string)$uid, $ids, true);
    }
}


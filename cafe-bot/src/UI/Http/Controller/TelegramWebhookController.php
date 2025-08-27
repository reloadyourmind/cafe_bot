<?php

namespace App\UI\Http\Controller;

use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use Symfony\Contracts\Cache\CacheInterface;
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
        private readonly CacheInterface $cache,
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
            $this->telegramClient->sendMessage($chatId, "☕️ Welcome!\n/menu — browse 🍽️\n/order — quick order 📦");
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
            // Start wizard (cache-based)
            $this->setWizardState($chatId, ['step' => 'name', 'data' => []]);
            $this->telegramClient->sendMessage($chatId, "🧩 Add product — Step 1/4\nSend name ✍️");
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

        $this->telegramClient->sendMessage($chatId, "🤖 /menu • /order • /confirm <id>\n<b>Admin</b>: /additem • /orders • /complete <id>");
        return new JsonResponse(['ok' => true]);
    }

    private function handleAdminWizard(int $chatId, string $text): bool
    {
        $state = $this->getWizardState($chatId);
        if (!$state) { return false; }
        $step = $state['step'] ?? 'name';
        $data = $state['data'] ?? [];
        switch ($step) {
            case 'name':
                if ($text === '') { $this->telegramClient->sendMessage($chatId, "⚠️ Please send a non-empty name."); return true; }
                $data['name'] = $text;
                $this->setWizardState($chatId, ['step' => 'price', 'data' => $data]);
                $this->telegramClient->sendMessage($chatId, "Step 2/4\nSend price, e.g. 3.50 💵");
                return true;
            case 'price':
                $price = (float)str_replace(',', '.', $text);
                if ($price <= 0) { $this->telegramClient->sendMessage($chatId, "⚠️ Send a positive price, e.g. 2.00"); return true; }
                $data['priceCents'] = (int)round($price * 100);
                $this->setWizardState($chatId, ['step' => 'description', 'data' => $data]);
                $this->telegramClient->sendMessage($chatId, "Step 3/4\nSend description (or '-' to skip) 📝");
                return true;
            case 'description':
                $data['description'] = $text === '-' ? null : $text;
                $this->setWizardState($chatId, ['step' => 'photo', 'data' => $data]);
                $this->telegramClient->sendMessage($chatId, "Step 4/4\nSend photo URL (or '-' to skip) 🖼️");
                return true;
            case 'photo':
                $data['photoUrl'] = $text === '-' ? null : $text;
                $name = $data['name'];
                $priceCents = (int)$data['priceCents'];
                $desc = $data['description'] ?? null;
                $photo = $data['photoUrl'] ?? null;
                $item = new MenuItem($name, $priceCents, $desc, $photo);
                $this->entityManager->persist($item);
                $this->entityManager->flush();
                $this->clearWizardState($chatId);
                $this->telegramClient->sendMessage($chatId, sprintf("✅ Added <b>%s</b> — $%s", htmlspecialchars($name), number_format($priceCents/100, 2)));
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

    private function wizardCacheKey(int $chatId): string
    {
        return 'admin_wizard_'.$chatId;
    }

    private function getWizardState(int $chatId): ?array
    {
        $key = $this->wizardCacheKey($chatId);
        return $this->cache->get($key, function () { return null; });
    }

    private function setWizardState(int $chatId, array $state): void
    {
        $key = $this->wizardCacheKey($chatId);
        // use low-level pool to set TTL
        if (method_exists($this->cache, 'delete')) {
            // Nothing
        }
        $this->cache->delete($key);
        $this->cache->get($key, function () use ($state) { return $state; });
    }

    private function clearWizardState(int $chatId): void
    {
        $this->cache->delete($this->wizardCacheKey($chatId));
    }
}


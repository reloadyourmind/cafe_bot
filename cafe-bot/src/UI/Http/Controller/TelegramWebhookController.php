<?php

namespace App\UI\Http\Controller;

use App\Entity\Admin;
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
        #[Autowire(param: 'env(TELEGRAM_WEBHOOK_SECRET)')] private readonly string $webhookSecret,
        #[Autowire(param: 'env(PLACEHOLDER_IMAGE_URL)')] private readonly string $placeholderImageUrl,
        #[Autowire(param: 'env(TELEGRAM_USE_WEBHOOK)')] private readonly bool $useWebhook,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/telegram/webhook/{secret}', name: 'telegram_webhook', methods: ['POST'])]
    public function webhook(Request $request, string $secret): Response
    {
        if ($this->useWebhook && $secret !== $this->webhookSecret) {
            return new Response('Forbidden', 403);
        }

        $update = json_decode($request->getContent(), true) ?? [];
        $callback = $update['callback_query'] ?? null;
        $message = $update['message'] ?? $update['edited_message'] ?? ($callback['message'] ?? null);
        
        if (!$message) {
            return new JsonResponse(['ok' => true]);
        }

        $chatId = (int)($message['chat']['id'] ?? ($callback['from']['id'] ?? 0));
        $userId = (int)($message['from']['id'] ?? ($callback['from']['id'] ?? 0));
        $text = trim((string)($message['text'] ?? ''));

        // Handle callback queries
        if ($callback) {
            $this->handleCallbackQuery($callback, $chatId, $userId);
            return new JsonResponse(['ok' => true]);
        }

        // Handle text commands
        $this->handleTextCommand($text, $chatId, $userId, $message);
        return new JsonResponse(['ok' => true]);
    }

    private function handleCallbackQuery(array $callback, int $chatId, int $userId): void
    {
        $data = (string)($callback['data'] ?? '');
        $callbackId = (string)($callback['id'] ?? '');

        if (str_starts_with($data, 'menu_')) {
            $this->handleMenuCallback($data, $chatId, $userId, $callbackId);
        } elseif (str_starts_with($data, 'product_')) {
            $this->handleProductCallback($data, $chatId, $userId, $callbackId);
        } elseif (str_starts_with($data, 'order_')) {
            $this->handleOrderCallback($data, $chatId, $userId, $callbackId);
        } elseif (str_starts_with($data, 'admin_')) {
            $this->handleAdminCallback($data, $chatId, $userId, $callbackId);
        }
    }

    private function handleMenuCallback(string $data, int $chatId, int $userId, string $callbackId): void
    {
        $isAdmin = $this->isAdmin($userId);
        
        if ($data === 'menu_main') {
            $this->showMainMenu($chatId, $isAdmin);
        } elseif ($data === 'menu_customer') {
            $this->showCustomerMenu($chatId);
        } elseif ($data === 'menu_admin') {
            if ($isAdmin) {
                $this->showAdminMenu($chatId);
            } else {
                $this->telegramClient->answerCallbackQuery($callbackId, 'Access denied', true);
            }
        }
        
        $this->telegramClient->answerCallbackQuery($callbackId);
    }

    private function handleProductCallback(string $data, int $chatId, int $userId, string $callbackId): void
    {
        if (preg_match('/^product_(\d+)_(\d+)$/', $data, $matches)) {
            $productId = (int)$matches[1];
            $quantity = (int)$matches[2];
            
            $product = $this->entityManager->getRepository(MenuItem::class)->find($productId);
            if (!$product || !$product->isActive()) {
                $this->telegramClient->answerCallbackQuery($callbackId, 'Product not available', true);
                return;
            }

            // Get or create current order
            $order = $this->getCurrentOrder($userId);
            if (!$order) {
                $order = new Order($userId, 'Customer', null, null);
                $this->entityManager->persist($order);
            }

            // Add or update order item
            $existingItem = null;
            foreach ($order->getItems() as $item) {
                if ($item->getMenuItem()->getId() === $productId) {
                    $existingItem = $item;
                    break;
                }
            }

            if ($existingItem) {
                $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
            } else {
                $orderItem = new OrderItem($product, $quantity);
                $order->addItem($orderItem);
            }

            $this->entityManager->flush();

            $this->telegramClient->answerCallbackQuery($callbackId, "Added {$product->getName()} x{$quantity}");
            $this->showCustomerMenu($chatId);
        }
    }

    private function handleOrderCallback(string $data, int $chatId, int $userId, string $callbackId): void
    {
        if ($data === 'order_view') {
            $this->showCurrentOrder($chatId, $userId);
        } elseif ($data === 'order_confirm') {
            $this->confirmOrder($chatId, $userId);
        } elseif ($data === 'order_cancel') {
            $this->cancelOrder($chatId, $userId);
        }
        
        $this->telegramClient->answerCallbackQuery($callbackId);
    }

    private function handleAdminCallback(string $data, int $chatId, int $userId, string $callbackId): void
    {
        if (!$this->isAdmin($userId)) {
            $this->telegramClient->answerCallbackQuery($callbackId, 'Access denied', true);
            return;
        }

        if ($data === 'admin_products') {
            $this->showAdminProducts($chatId);
        } elseif ($data === 'admin_orders') {
            $this->showAdminOrders($chatId);
        } elseif (str_starts_with($data, 'admin_order_status_')) {
            $orderId = (int)substr($data, 19);
            $this->showOrderStatusOptions($chatId, $orderId);
        } elseif (str_starts_with($data, 'admin_set_status_')) {
            $parts = explode('_', $data);
            $orderId = (int)$parts[3];
            $status = $parts[4];
            $this->updateOrderStatus($chatId, $orderId, $status);
        }
        
        $this->telegramClient->answerCallbackQuery($callbackId);
    }

    private function handleTextCommand(string $text, int $chatId, int $userId, array $message): void
    {
        if ($text === '/start') {
            $this->showMainMenu($chatId, $this->isAdmin($userId));
        } elseif ($text === '/menu') {
            $this->showMainMenu($chatId, $this->isAdmin($userId));
        } else {
            $this->showMainMenu($chatId, $this->isAdmin($userId));
        }
    }

    private function showMainMenu(int $chatId, bool $isAdmin): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🍽️ Browse Menu', 'callback_data' => 'menu_customer']],
                [['text' => '🛒 My Order', 'callback_data' => 'order_view']],
            ]
        ];

        if ($isAdmin) {
            $keyboard['inline_keyboard'][] = [['text' => '⚙️ Admin Panel', 'callback_data' => 'menu_admin']];
        }

        $this->telegramClient->sendMessage($chatId, "☕️ Welcome to our Cafe Bot!\n\nChoose an option below:", [
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function showCustomerMenu(int $chatId): void
    {
        $products = $this->entityManager->getRepository(MenuItem::class)->findBy(['active' => true]);
        
        if (empty($products)) {
            $this->telegramClient->sendMessage($chatId, "📭 Menu is currently empty. Please check back later!");
            return;
        }

        foreach ($products as $product) {
            $caption = sprintf(
                "<b>%s</b>\n$%s\n%s",
                htmlspecialchars($product->getName()),
                number_format($product->getPriceCents() / 100, 2),
                htmlspecialchars($product->getDescription() ?? '')
            );

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➖', 'callback_data' => "product_{$product->getId()}_-1"],
                        ['text' => 'Add to Cart', 'callback_data' => "product_{$product->getId()}_1"],
                        ['text' => '➕', 'callback_data' => "product_{$product->getId()}_1"],
                    ]
                ]
            ];

            $photo = $product->getPhotoUrl() ?: $this->placeholderImageUrl;
            $this->telegramClient->sendPhoto($chatId, $photo, [
                'caption' => $caption,
                'reply_markup' => json_encode($keyboard)
            ]);
        }

        $backKeyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Back to Main Menu', 'callback_data' => 'menu_main']]
            ]
        ];

        $this->telegramClient->sendMessage($chatId, "Select items to add to your order:", [
            'reply_markup' => json_encode($backKeyboard)
        ]);
    }

    private function showAdminMenu(int $chatId): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📦 Manage Products', 'callback_data' => 'admin_products']],
                [['text' => '📋 View Orders', 'callback_data' => 'admin_orders']],
                [['text' => '🔙 Back to Main Menu', 'callback_data' => 'menu_main']]
            ]
        ];

        $this->telegramClient->sendMessage($chatId, "⚙️ Admin Panel\n\nChoose an option:", [
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function showCurrentOrder(int $chatId, int $userId): void
    {
        $order = $this->getCurrentOrder($userId);
        
        if (!$order || $order->getItems()->isEmpty()) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🍽️ Browse Menu', 'callback_data' => 'menu_customer']],
                    [['text' => '🔙 Back to Main Menu', 'callback_data' => 'menu_main']]
                ]
            ];
            $this->telegramClient->sendMessage($chatId, "🛒 Your cart is empty!\n\nBrowse our menu to add items.", [
                'reply_markup' => json_encode($keyboard)
            ]);
            return;
        }

        $message = "🛒 <b>Your Order #{$order->getId()}</b>\n\n";
        $total = 0;

        foreach ($order->getItems() as $item) {
            $subtotal = $item->getSubtotalCents();
            $total += $subtotal;
            $message .= sprintf(
                "• %s x%d = $%s\n",
                htmlspecialchars($item->getMenuItem()->getName()),
                $item->getQuantity(),
                number_format($subtotal / 100, 2)
            );
        }

        $message .= "\n<b>Total: $" . number_format($total / 100, 2) . "</b>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Confirm Order', 'callback_data' => 'order_confirm'],
                    ['text' => '❌ Cancel Order', 'callback_data' => 'order_cancel']
                ],
                [['text' => '🍽️ Add More Items', 'callback_data' => 'menu_customer']],
                [['text' => '🔙 Back to Main Menu', 'callback_data' => 'menu_main']]
            ]
        ];

        $this->telegramClient->sendMessage($chatId, $message, [
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function confirmOrder(int $chatId, int $userId): void
    {
        $order = $this->getCurrentOrder($userId);
        
        if (!$order) {
            $this->telegramClient->sendMessage($chatId, "❌ No active order found!");
            return;
        }

        $order->setStatus(Order::STATUS_CONFIRMED);
        $this->entityManager->flush();

        $this->telegramClient->sendMessage($chatId, "✅ Order confirmed! We'll notify you when it's ready. ⏳");
        
        // Notify admins
        $this->notifyAdmins("🆕 New order #{$order->getId()} confirmed!\nTotal: $" . number_format($order->getTotalCents() / 100, 2));
    }

    private function cancelOrder(int $chatId, int $userId): void
    {
        $order = $this->getCurrentOrder($userId);
        
        if (!$order) {
            $this->telegramClient->sendMessage($chatId, "❌ No active order found!");
            return;
        }

        $this->entityManager->remove($order);
        $this->entityManager->flush();

        $this->telegramClient->sendMessage($chatId, "❌ Order cancelled.");
    }

    private function showAdminProducts(int $chatId): void
    {
        $products = $this->entityManager->getRepository(MenuItem::class)->findAll();
        
        $message = "📦 <b>Product Management</b>\n\n";
        
        if (empty($products)) {
            $message .= "No products found.";
        } else {
            foreach ($products as $product) {
                $status = $product->isActive() ? '✅' : '❌';
                $message .= sprintf(
                    "%s <b>%s</b> - $%s\n",
                    $status,
                    htmlspecialchars($product->getName()),
                    number_format($product->getPriceCents() / 100, 2)
                );
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Back to Admin Panel', 'callback_data' => 'menu_admin']]
            ]
        ];

        $this->telegramClient->sendMessage($chatId, $message, [
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function showAdminOrders(int $chatId): void
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 10);
        
        $message = "📋 <b>Recent Orders</b>\n\n";
        
        if (empty($orders)) {
            $message .= "No orders found.";
        } else {
            foreach ($orders as $order) {
                $statusEmoji = match($order->getStatus()) {
                    Order::STATUS_NEW => '🆕',
                    Order::STATUS_CONFIRMED => '✅',
                    Order::STATUS_COMPLETED => '🎉',
                    default => '❓'
                };
                
                $message .= sprintf(
                    "%s <b>Order #%d</b> - %s - $%s\n",
                    $statusEmoji,
                    $order->getId(),
                    strtoupper($order->getStatus()),
                    number_format($order->getTotalCents() / 100, 2)
                );
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Back to Admin Panel', 'callback_data' => 'menu_admin']]
            ]
        ];

        $this->telegramClient->sendMessage($chatId, $message, [
            'reply_markup' => json_encode($keyboard)
        ]);
    }

    private function updateOrderStatus(int $chatId, int $orderId, string $status): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        
        if (!$order) {
            $this->telegramClient->sendMessage($chatId, "❌ Order not found!");
            return;
        }

        $oldStatus = $order->getStatus();
        $order->setStatus($status);
        $this->entityManager->flush();

        $this->telegramClient->sendMessage($chatId, "✅ Order #{$orderId} status updated to " . strtoupper($status));
        
        // Notify customer about status change
        $this->notifyCustomer($order->getTelegramUserId(), $orderId, $oldStatus, $status);
    }

    private function notifyCustomer(int $customerId, int $orderId, string $oldStatus, string $newStatus): void
    {
        $message = "📢 <b>Order Update</b>\n\n";
        $message .= "Order #{$orderId} status changed:\n";
        $message .= "From: " . strtoupper($oldStatus) . "\n";
        $message .= "To: " . strtoupper($newStatus) . "\n\n";
        
        $statusMessage = match($newStatus) {
            Order::STATUS_CONFIRMED => "Your order has been confirmed and is being prepared! 👨‍🍳",
            Order::STATUS_COMPLETED => "Your order is ready for pickup! 🎉",
            default => "Your order status has been updated."
        };
        
        $message .= $statusMessage;

        $this->telegramClient->sendMessage($customerId, $message);
    }

    private function notifyAdmins(string $message): void
    {
        $admins = $this->entityManager->getRepository(Admin::class)->findBy(['active' => true]);
        
        foreach ($admins as $admin) {
            $this->telegramClient->sendMessage($admin->getTelegramUserId(), $message);
        }
    }

    private function getCurrentOrder(int $userId): ?Order
    {
        return $this->entityManager->getRepository(Order::class)
            ->findOneBy(['telegramUserId' => $userId, 'status' => Order::STATUS_NEW]);
    }

    private function isAdmin(int $userId): bool
    {
        $admin = $this->entityManager->getRepository(Admin::class)
            ->findOneBy(['telegramUserId' => $userId, 'active' => true]);
        
        return $admin !== null;
    }
}
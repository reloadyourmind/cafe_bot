# Telegram Cafe Bot

A comprehensive Telegram bot for cafe order management with admin and customer interfaces.

## Features

### Customer Features
- Browse menu with photos and descriptions
- Add items to cart with quantity selection
- View current order
- Confirm or cancel orders
- Receive notifications when order status changes

### Admin Features
- Manage products (add, edit, activate/deactivate)
- View all orders
- Update order status
- Web-based admin panel for managing administrators
- Receive notifications for new orders

## Setup

### Prerequisites
- Docker and Docker Compose
- Telegram Bot Token (from @BotFather)

### Installation

1. Clone the repository and navigate to the project directory:
```bash
cd cafe-bot
```

2. Copy the environment file and configure it:
```bash
cp .env.example .env
```

3. Edit `.env` file with your configuration:
```env
TELEGRAM_BOT_TOKEN="your_bot_token_from_botfather"
TELEGRAM_WEBHOOK_SECRET="your_secure_webhook_secret"
TELEGRAM_WEBHOOK_URL="https://yourdomain.com/telegram/webhook"
ADMIN_PANEL_SECRET="your_secure_admin_panel_secret"
```

4. Start the services:
```bash
docker compose up -d
```

5. Run database migrations:
```bash
docker compose exec app php bin/console doctrine:migrations:migrate
```

6. Set up webhook (if using webhook mode):
```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
     -H "Content-Type: application/json" \
     -d '{"url": "https://yourdomain.com/telegram/webhook/your_webhook_secret"}'
```

### Configuration Modes

The bot supports two modes:

1. **Webhook Mode** (recommended for production):
   - Set `TELEGRAM_USE_WEBHOOK=true`
   - Configure `TELEGRAM_WEBHOOK_URL`
   - Set up webhook with Telegram

2. **Polling Mode** (for development):
   - Set `TELEGRAM_USE_WEBHOOK=false`
   - Run: `docker compose exec app php bin/console app:telegram:poll`

## Usage

### Customer Interface
1. Start a conversation with your bot
2. Use `/start` to see the main menu
3. Browse products and add to cart
4. View your order and confirm when ready

### Admin Interface
1. Add administrators via the web panel: `https://yourdomain.com/admin/your_admin_secret`
2. Use the admin menu in Telegram to manage products and orders
3. Update order status to notify customers

## Database Schema

The application uses PostgreSQL with the following main tables:
- `menu_items` - Product catalog
- `orders` - Customer orders
- `order_items` - Individual items in orders
- `admins` - Administrator users

See `sql/schema.sql` for the complete schema.

## API Endpoints

- `POST /telegram/webhook/{secret}` - Telegram webhook endpoint
- `GET /admin/{secret}` - Admin panel interface

## Development

### Running Tests
```bash
docker compose exec app php bin/phpunit
```

### Database Migrations
```bash
# Create new migration
docker compose exec app php bin/console make:migration

# Run migrations
docker compose exec app php bin/console doctrine:migrations:migrate
```

### Adding New Features
1. Create entities in `src/Entity/`
2. Create controllers in `src/UI/Http/Controller/`
3. Add routes in controller attributes
4. Create templates in `templates/`

## Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `TELEGRAM_BOT_TOKEN` | Bot token from @BotFather | Yes |
| `TELEGRAM_WEBHOOK_SECRET` | Secret for webhook security | Yes |
| `TELEGRAM_USE_WEBHOOK` | Use webhook mode (true/false) | Yes |
| `TELEGRAM_WEBHOOK_URL` | Webhook URL for production | If webhook mode |
| `ADMIN_PANEL_SECRET` | Secret for admin panel access | Yes |
| `PLACEHOLDER_IMAGE_URL` | Default image for products | No |
| `DATABASE_URL` | PostgreSQL connection string | Yes |

## Security Notes

- Change all default secrets in production
- Use HTTPS for webhook URLs
- Regularly update dependencies
- Monitor admin panel access
- Use strong passwords for database

## Troubleshooting

### Bot not responding
1. Check webhook status: `curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"`
2. Verify webhook URL is accessible
3. Check logs: `docker compose logs app`

### Database issues
1. Check database connection: `docker compose exec app php bin/console doctrine:database:create`
2. Run migrations: `docker compose exec app php bin/console doctrine:migrations:migrate`

### Admin panel not accessible
1. Verify `ADMIN_PANEL_SECRET` is set correctly
2. Check URL format: `https://yourdomain.com/admin/your_secret`
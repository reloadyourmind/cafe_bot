-- Database schema for Telegram Cafe Bot
-- This file contains the complete database schema in plain SQL

-- Menu items table
CREATE TABLE menu_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description CLOB DEFAULT NULL,
    price_cents INTEGER NOT NULL,
    photo_url VARCHAR(1024) DEFAULT NULL,
    active BOOLEAN NOT NULL DEFAULT 1
);

-- Orders table
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    telegram_user_id BIGINT NOT NULL,
    orderer_name VARCHAR(255) NOT NULL,
    orderer_nickname VARCHAR(255) DEFAULT NULL,
    orderer_phone VARCHAR(20) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    total_cents INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
);

-- Order items table
CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    order_id INTEGER NOT NULL,
    menu_item_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price_cents INTEGER NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items (id)
);

-- Admins table
CREATE TABLE admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    telegram_user_id BIGINT NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    nickname VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    active BOOLEAN NOT NULL DEFAULT 1
);

-- Indexes
CREATE INDEX IDX_62809DB08D9F6D38 ON order_items (order_id);
CREATE INDEX IDX_62809DB09AB44FE0 ON order_items (menu_item_id);
CREATE INDEX IDX_orders_telegram_user_id ON orders (telegram_user_id);
CREATE INDEX IDX_orders_status ON orders (status);
CREATE INDEX IDX_admins_telegram_user_id ON admins (telegram_user_id);
CREATE INDEX IDX_admins_active ON admins (active);
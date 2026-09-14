CREATE DATABASE IF NOT EXISTS hornys_compta
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hornys_compta;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    display_name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    can_view_accounting TINYINT(1) NOT NULL DEFAULT 1,
    can_edit_accounting TINYINT(1) NOT NULL DEFAULT 1,
    can_view_referentiel TINYINT(1) NOT NULL DEFAULT 0,
    can_edit_referentiel TINYINT(1) NOT NULL DEFAULT 0,
    can_view_employees TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_employees TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_grades TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_logs TINYINT(1) NOT NULL DEFAULT 0,
    grade_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    pay_percent DECIMAL(5,2) NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    shift_label VARCHAR(80) NOT NULL,
    category VARCHAR(80) NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_entries_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category ENUM('burger', 'fries', 'drink', 'dessert', 'other') NOT NULL DEFAULT 'other',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    accounting_entry_id INT UNSIGNED NULL,
    invoice_date DATE NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_invoice_entry FOREIGN KEY (accounting_entry_id)
        REFERENCES accounting_entries(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_invoice_item_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_webhooks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    webhook_url TEXT NOT NULL,
    log_invoices TINYINT(1) NOT NULL DEFAULT 1,
    log_employees TINYINT(1) NOT NULL DEFAULT 0,
    log_referentiel TINYINT(1) NOT NULL DEFAULT 0,
    log_grades TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category ENUM('burger', 'fries', 'drink', 'dessert', 'other') NOT NULL DEFAULT 'other',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    CONSTRAINT fk_recipe_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient FOREIGN KEY (ingredient_id)
        REFERENCES ingredients(id)
        ON DELETE CASCADE,
    UNIQUE KEY uniq_product_ingredient (product_id, ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Le compte admin est cree automatiquement au premier chargement PHP.
-- Identifiant : admin
-- Mot de passe : hornys2611

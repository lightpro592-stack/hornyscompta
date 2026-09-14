<?php
declare(strict_types=1);

session_start();

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'hornys_compta');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'mysql');
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');
define('APP_SECRET', getenv('APP_SECRET') ?: 'hornys-stable-secret-prod-v1');

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_DRIVER === 'pgsql') {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=' . DB_SSLMODE;
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    ensure_schema($pdo);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    if (DB_DRIVER === 'pgsql') {
        ensure_schema_pgsql($pdo);
        return;
    }

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensure_user_permission_columns($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grades (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            pay_percent DECIMAL(5,2) NOT NULL DEFAULT 100,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('burger', 'fries', 'drink', 'dessert', 'other') NOT NULL DEFAULT 'other',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            unit VARCHAR(30) NOT NULL DEFAULT 'piece',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute(['admin']);

    if (!$stmt->fetch()) {
        $insert = $pdo->prepare('
            INSERT INTO users (username, password_hash, role, display_name)
            VALUES (?, ?, ?, ?)
        ');
        $insert->execute([
            'admin',
            password_hash('hornys2611', PASSWORD_DEFAULT),
            'admin',
            'Administration',
        ]);
    }
}

function ensure_schema_pgsql(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'employee',
            display_name VARCHAR(100) NOT NULL,
            active SMALLINT NOT NULL DEFAULT 1,
            can_view_accounting SMALLINT NOT NULL DEFAULT 1,
            can_edit_accounting SMALLINT NOT NULL DEFAULT 1,
            can_view_referentiel SMALLINT NOT NULL DEFAULT 0,
            can_edit_referentiel SMALLINT NOT NULL DEFAULT 0,
            can_view_employees SMALLINT NOT NULL DEFAULT 0,
            can_manage_employees SMALLINT NOT NULL DEFAULT 0,
            can_manage_grades SMALLINT NOT NULL DEFAULT 0,
            can_manage_logs SMALLINT NOT NULL DEFAULT 0,
            grade_id INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grades (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            pay_percent DECIMAL(5,2) NOT NULL DEFAULT 100,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounting_entries (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            entry_date DATE NOT NULL,
            shift_label VARCHAR(80) NOT NULL,
            category VARCHAR(80) NOT NULL,
            type VARCHAR(20) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id SERIAL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'other',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            active SMALLINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            accounting_entry_id INTEGER NULL REFERENCES accounting_entries(id) ON DELETE SET NULL,
            invoice_date DATE NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoice_items (
            id SERIAL PRIMARY KEY,
            invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
            product_id INTEGER NULL REFERENCES products(id) ON DELETE SET NULL,
            product_name VARCHAR(120) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            quantity INTEGER NOT NULL,
            line_total DECIMAL(10,2) NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredients (
            id SERIAL PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            unit VARCHAR(30) NOT NULL DEFAULT 'piece',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS discord_webhooks (
            id SERIAL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            webhook_url TEXT NOT NULL,
            log_invoices SMALLINT NOT NULL DEFAULT 1,
            log_employees SMALLINT NOT NULL DEFAULT 0,
            log_referentiel SMALLINT NOT NULL DEFAULT 0,
            log_grades SMALLINT NOT NULL DEFAULT 0,
            active SMALLINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_ingredients (
            id SERIAL PRIMARY KEY,
            product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            CONSTRAINT uniq_product_ingredient UNIQUE (product_id, ingredient_id)
        )
    ");

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute(['admin']);

    if (!$stmt->fetch()) {
        $insert = $pdo->prepare('
            INSERT INTO users (
                username, password_hash, role, display_name,
                can_view_accounting, can_edit_accounting,
                can_view_referentiel, can_edit_referentiel,
                can_view_employees, can_manage_employees,
                can_manage_grades, can_manage_logs
            ) VALUES (?, ?, ?, ?, 1, 1, 1, 1, 1, 1, 1, 1)
        ');
        $insert->execute([
            'admin',
            password_hash('hornys2611', PASSWORD_DEFAULT),
            'admin',
            'Administration',
        ]);
    }
}

function ensure_user_permission_columns(PDO $pdo): void
{
    $columns = [
        'can_view_accounting' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_edit_accounting' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_referentiel' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_edit_referentiel' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_view_employees' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_manage_employees' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_manage_grades' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_manage_logs' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'grade_id' => 'INT UNSIGNED NULL',
    ];

    $stmt = $pdo->prepare('
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "users"
    ');
    $stmt->execute([DB_NAME]);
    $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($columns as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $column $definition");
        }
    }

    $pdo->exec("
        UPDATE users
        SET can_view_accounting = 1,
            can_edit_accounting = 1,
            can_view_referentiel = 1,
            can_edit_referentiel = 1,
            can_view_employees = 1,
            can_manage_employees = 1,
            can_manage_grades = 1,
            can_manage_logs = 1
        WHERE role = 'admin'
    ");
}

function current_user(): ?array
{
    $userId = auth_cookie_user_id() ?: ($_SESSION['user_id'] ?? null);

    if (empty($userId)) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user_id'] = (int) $user['id'];
            return $user;
        }
    } catch (Throwable $e) {
        error_log('Error in current_user: ' . $e->getMessage());
    }

    return null;
}

function auth_cookie_user_id(): ?int
{
    $cookieValue = $_COOKIE['hornys_auth'] ?? null;
    if (empty($cookieValue)) {
        return null;
    }

    $parts = explode(':', $cookieValue, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) {
        return null;
    }

    [$userId, $signature] = $parts;
    $expected = hash_hmac('sha256', $userId, APP_SECRET);

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    return (int) $userId;
}

function set_auth_user(int $userId): void
{
    $_SESSION['user_id'] = $userId;
    $value = $userId . ':' . hash_hmac('sha256', (string) $userId, APP_SECRET);
    
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
               
    setcookie('hornys_auth', $value, [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_auth_user(): void
{
    unset($_SESSION['user_id']);
    
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie('hornys_auth', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: /index.php');
        exit;
    }

    return $user;
}

function require_admin(array $user): void
{
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Acces refuse.');
    }
}

function can(array $user, string $permission): bool
{
    return $user['role'] === 'admin' || !empty($user[$permission]);
}

function require_permission(array $user, string $permission): void
{
    if (!can($user, $permission)) {
        http_response_code(403);
        exit('Acces refuse.');
    }
}

function default_page_for(array $user): string
{
    if (can($user, 'can_view_accounting')) {
        return '/dashboard.php';
    }

    if (can($user, 'can_view_referentiel')) {
        return '/referentiel.php';
    }

    if (can($user, 'can_view_employees')) {
        return '/liste-employes.php';
    }

    if (can($user, 'can_manage_employees')) {
        return '/gestion-employes.php';
    }

    if (can($user, 'can_manage_grades')) {
        return '/gestion-grades.php';
    }

    if (can($user, 'can_manage_logs')) {
        return '/logs.php';
    }

    return '/logout.php';
}

function send_discord_log(string $eventType, string $title, string $description, array $fields = []): void
{
    $eventColumn = [
        'invoice' => 'log_invoices',
        'employee' => 'log_employees',
        'referentiel' => 'log_referentiel',
        'grade' => 'log_grades',
    ][$eventType] ?? null;

    if (!$eventColumn) {
        return;
    }

    $stmt = db()->query("SELECT * FROM discord_webhooks WHERE active = 1 AND $eventColumn = 1");
    $webhooks = $stmt->fetchAll();

    if (!$webhooks) {
        return;
    }

    $embedFields = [];
    foreach ($fields as $name => $value) {
        $value = (string) $value;
        if (strlen($value) > 1000) {
            $value = substr($value, 0, 997) . '...';
        }

        $embedFields[] = [
            'name' => (string) $name,
            'value' => $value !== '' ? $value : '-',
            'inline' => true,
        ];
    }

    $payload = json_encode([
        'username' => "Horny's Logs",
        'embeds' => [[
            'title' => $title,
            'description' => $description,
            'color' => 13901856,
            'fields' => $embedFields,
            'timestamp' => gmdate('c'),
        ]],
    ], JSON_UNESCAPED_UNICODE);

    foreach ($webhooks as $webhook) {
        $ch = curl_init($webhook['webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: Hornys-Compta/1.0'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            error_log(sprintf(
                '[Hornys Discord] Webhook %s failed with HTTP %s %s',
                $webhook['name'],
                $status,
                $error
            ));
        }
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|string $amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' $';
}

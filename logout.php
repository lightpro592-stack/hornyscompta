<?php
require_once __DIR__ . '/config.php';

clear_auth_user();
$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    $params = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 3600,
        'path' => $params['path'] ?: '/',
        'secure' => (bool) $params['secure'],
        'httponly' => (bool) $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ];

    if (!empty($params['domain'])) {
        $cookieOptions['domain'] = $params['domain'];
    }

    setcookie(session_name(), '', $cookieOptions);
    session_destroy();
}

prevent_private_cache();
header('Location: /index.php');
exit;

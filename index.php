<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    $redirectUrl = default_page_for(current_user());
    header('Location: ' . $redirectUrl);
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            set_auth_user((int) $user['id']);
            header('Location: ' . default_page_for($user));
            exit;
        }

        $error = 'Identifiant ou mot de passe incorrect.';
    } catch (PDOException $exception) {
        error_log('Database connection failed: ' . $exception->getMessage());
        $error = "Impossible de joindre la base de donnees. Verifie que MySQL est lance et que la base hornys_compta existe.";
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horny's Comptabilite</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="brand-panel">
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Caisse & compta</h1>
            <p>Suivi des services, ventes burger, frites, boissons et depenses de l'equipe.</p>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <h2 id="login-title">Connexion</h2>

            <?php if ($error): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <label for="username">Identifiant</label>
                <input id="username" name="username" value="<?= e($username) ?>" required autofocus>

                <label for="password">Mot de passe / PIN</label>
                <input id="password" name="password" type="password" inputmode="numeric" required>

                <button type="submit">Se connecter</button>
            </form>
        </section>
    </main>
</body>
</html>

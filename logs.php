<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_manage_logs');

$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_webhook') {
            $webhookId = (int) ($_POST['webhook_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['webhook_url'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            $logInvoices = isset($_POST['log_invoices']) ? 1 : 0;
            $logEmployees = isset($_POST['log_employees']) ? 1 : 0;
            $logReferentiel = isset($_POST['log_referentiel']) ? 1 : 0;
            $logGrades = isset($_POST['log_grades']) ? 1 : 0;

            if ($name === '') {
                throw new RuntimeException('Le nom du log est obligatoire.');
            }

            if (!filter_var($url, FILTER_VALIDATE_URL) || !str_contains($url, 'discord.com/api/webhooks/')) {
                throw new RuntimeException('Entre une URL de webhook Discord valide.');
            }

            if ($webhookId > 0) {
                $stmt = $pdo->prepare('
                    UPDATE discord_webhooks
                    SET name = ?, webhook_url = ?, active = ?,
                        log_invoices = ?, log_employees = ?, log_referentiel = ?, log_grades = ?
                    WHERE id = ?
                ');
                $stmt->execute([$name, $url, $active, $logInvoices, $logEmployees, $logReferentiel, $logGrades, $webhookId]);
                $message = 'Webhook modifie.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO discord_webhooks
                        (name, webhook_url, active, log_invoices, log_employees, log_referentiel, log_grades)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$name, $url, $active, $logInvoices, $logEmployees, $logReferentiel, $logGrades]);
                $message = 'Webhook ajoute.';
            }
        }

        if ($action === 'delete_webhook') {
            $webhookId = (int) ($_POST['webhook_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM discord_webhooks WHERE id = ?');
            $stmt->execute([$webhookId]);
            $message = 'Webhook supprime.';
        }

        if ($action === 'test_webhook') {
            $webhookId = (int) ($_POST['webhook_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM discord_webhooks WHERE id = ? LIMIT 1');
            $stmt->execute([$webhookId]);
            $webhook = $stmt->fetch();

            if (!$webhook) {
                throw new RuntimeException('Webhook introuvable.');
            }

            $payload = json_encode([
                'username' => "Horny's Logs",
                'embeds' => [[
                    'title' => 'Test logs Horny\'s',
                    'description' => 'Le webhook Discord est bien configure.',
                    'color' => 13901856,
                    'timestamp' => gmdate('c'),
                ]],
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($webhook['webhook_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Discord a refuse le test. Verifie le webhook.');
            }

            $message = 'Message de test envoye.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$webhooks = $pdo->query('SELECT * FROM discord_webhooks ORDER BY active DESC, name')->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logs - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Logs</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_accounting')): ?><a href="dashboard.php">Facturation</a><?php endif; ?>
            <?php if (can($user, 'can_view_referentiel')): ?><a href="referentiel.php">Referentiel</a><?php endif; ?>
            <?php if (can($user, 'can_view_employees')): ?><a href="liste-employes.php">Liste des employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_employees')): ?><a href="gestion-employes.php">Gestion employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_grades')): ?><a href="gestion-grades.php">Gestion grade</a><?php endif; ?>
            <?php if (can($user, 'can_view_accounting')): ?><a href="statistique.php">Statistique</a><?php endif; ?>
            <a href="logout.php">Deconnexion</a>
        </nav>
    </header>

    <main class="app-layout">
        <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

        <form class="panel" method="post">
            <input type="hidden" name="action" value="save_webhook">
            <h2>Ajouter un webhook Discord</h2>
            <div class="employee-form-grid">
                <label>Nom
                    <input name="name" placeholder="Salon factures, staff logs..." required>
                </label>
                <label>URL webhook Discord
                    <input name="webhook_url" placeholder="https://discord.com/api/webhooks/..." required>
                </label>
            </div>
            <div class="permission-grid">
                <label class="checkbox-row"><input name="active" type="checkbox" checked> Actif</label>
                <label class="checkbox-row"><input name="log_invoices" type="checkbox" checked> Factures</label>
                <label class="checkbox-row"><input name="log_employees" type="checkbox"> Employes</label>
                <label class="checkbox-row"><input name="log_referentiel" type="checkbox"> Referentiel</label>
                <label class="checkbox-row"><input name="log_grades" type="checkbox"> Grades</label>
            </div>
            <button type="submit">Ajouter le webhook</button>
        </form>

        <section class="panel table-panel">
            <h2>Webhooks configures</h2>
            <div class="webhook-list">
                <?php foreach ($webhooks as $webhook): ?>
                    <article class="webhook-card">
                        <form method="post" class="webhook-edit">
                            <input type="hidden" name="action" value="save_webhook">
                            <input type="hidden" name="webhook_id" value="<?= (int) $webhook['id'] ?>">
                            <label>Nom
                                <input name="name" value="<?= e($webhook['name']) ?>" required>
                            </label>
                            <label>URL webhook Discord
                                <input name="webhook_url" value="<?= e($webhook['webhook_url']) ?>" required>
                            </label>
                            <div class="permission-grid">
                                <label class="checkbox-row"><input name="active" type="checkbox" <?= (int) $webhook['active'] === 1 ? 'checked' : '' ?>> Actif</label>
                                <label class="checkbox-row"><input name="log_invoices" type="checkbox" <?= (int) $webhook['log_invoices'] === 1 ? 'checked' : '' ?>> Factures</label>
                                <label class="checkbox-row"><input name="log_employees" type="checkbox" <?= (int) $webhook['log_employees'] === 1 ? 'checked' : '' ?>> Employes</label>
                                <label class="checkbox-row"><input name="log_referentiel" type="checkbox" <?= (int) $webhook['log_referentiel'] === 1 ? 'checked' : '' ?>> Referentiel</label>
                                <label class="checkbox-row"><input name="log_grades" type="checkbox" <?= (int) $webhook['log_grades'] === 1 ? 'checked' : '' ?>> Grades</label>
                            </div>
                            <button type="submit">Modifier</button>
                        </form>
                        <div class="webhook-actions">
                            <form method="post">
                                <input type="hidden" name="action" value="test_webhook">
                                <input type="hidden" name="webhook_id" value="<?= (int) $webhook['id'] ?>">
                                <button type="submit">Tester</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_webhook">
                                <input type="hidden" name="webhook_id" value="<?= (int) $webhook['id'] ?>">
                                <button type="submit" class="danger-button">Supprimer</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$webhooks): ?><p class="empty-state">Aucun webhook configure.</p><?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>

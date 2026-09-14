<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_manage_employees');

$pdo = db();
$message = '';
$error = '';

$grades = $pdo->query('SELECT id, name, pay_percent FROM grades ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_employee') {
            $displayName = trim($_POST['display_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $gradeId = (int) ($_POST['grade_id'] ?? 0);

            if ($displayName === '' || $username === '') {
                throw new RuntimeException('Complete le nom et l identifiant.');
            }

            if (!preg_match('/^\d{4}$/', $password)) {
                throw new RuntimeException('Le mot de passe employe doit contenir 4 chiffres.');
            }

            $stmt = $pdo->prepare('
                INSERT INTO users (
                    username, password_hash, role, display_name, active,
                    grade_id
                ) VALUES (?, ?, "employee", ?, 1, ?)
            ');
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $displayName,
                $gradeId > 0 ? $gradeId : null,
            ]);
            $message = 'Employe cree.';
        }

        if ($action === 'update_employee') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $displayName = trim($_POST['display_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            $gradeId = (int) ($_POST['grade_id'] ?? 0);

            if ($employeeId <= 0) {
                throw new RuntimeException('Employe introuvable.');
            }

            if ($employeeId === (int) $user['id'] && $active === 0) {
                throw new RuntimeException('Tu ne peux pas desactiver ton propre compte.');
            }

            if ($displayName === '' || $username === '') {
                throw new RuntimeException('Complete le nom et l identifiant.');
            }

            if ($password !== '') {
                if (!preg_match('/^\d{4}$/', $password) && $employeeId !== (int) $user['id']) {
                    throw new RuntimeException('Le mot de passe employe doit contenir 4 chiffres.');
                }

                $stmt = $pdo->prepare('
                    UPDATE users
                    SET username = ?, display_name = ?, password_hash = ?, active = ?,
                        grade_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $username,
                    $displayName,
                    password_hash($password, PASSWORD_DEFAULT),
                    $active,
                    $gradeId > 0 ? $gradeId : null,
                    $employeeId,
                ]);
            } else {
                $stmt = $pdo->prepare('
                    UPDATE users
                    SET username = ?, display_name = ?, active = ?,
                        grade_id = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $username,
                    $displayName,
                    $active,
                    $gradeId > 0 ? $gradeId : null,
                    $employeeId,
                ]);
            }

            $message = 'Employe modifie.';
        }

        if ($action === 'delete_employee') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);

            if ($employeeId <= 0) {
                throw new RuntimeException('Employe introuvable.');
            }

            if ($employeeId === (int) $user['id']) {
                throw new RuntimeException('Tu ne peux pas supprimer ton propre compte.');
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
            $stmt->execute([$employeeId]);
            $message = 'Employe supprime.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$employees = $pdo->query("
    SELECT id, username, role, display_name, active, grade_id, created_at
    FROM users
    ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, display_name
")->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion employes - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Gestion employes</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_accounting')): ?>
                <a href="dashboard.php">Facturation</a>
            <?php endif; ?>
            <?php if (can($user, 'can_view_referentiel')): ?>
                <a href="referentiel.php">Referentiel</a>
            <?php endif; ?>
            <a href="liste-employes.php">Liste des employes</a>
            <?php if (can($user, 'can_manage_grades')): ?>
                <a href="gestion-grades.php">Gestion grade</a>
            <?php endif; ?>
            <?php if (can($user, 'can_manage_logs')): ?>
                <a href="logs.php">Logs</a>
            <?php endif; ?>
            <a href="logout.php">Deconnexion</a>
        </nav>
    </header>

    <main class="app-layout">
        <?php if ($message): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="panel" method="post">
            <input type="hidden" name="action" value="create_employee">
            <h2>Creer un compte employe</h2>

            <div class="employee-form-grid">
                <label>Nom affiche
                    <input name="display_name" required>
                </label>
                <label>Identifiant
                    <input name="username" required>
                </label>
                <label>Mot de passe a 4 chiffres
                    <input name="password" inputmode="numeric" maxlength="4" pattern="\d{4}" required>
                </label>
                <label>Grade
                    <select name="grade_id">
                        <option value="0">Aucun grade</option>
                        <?php foreach ($grades as $grade): ?>
                            <option value="<?= (int) $grade['id'] ?>"><?= e($grade['name']) ?> - <?= e($grade['pay_percent']) ?> %</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <button type="submit">Creer le compte</button>
        </form>

        <section class="panel table-panel">
            <h2>Modifier les employes</h2>
            <div class="employee-list">
                <?php foreach ($employees as $employee): ?>
                    <article class="employee-card">
                        <form method="post" class="employee-edit">
                            <input type="hidden" name="action" value="update_employee">
                            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">

                            <div class="employee-form-grid">
                                <label>Nom affiche
                                    <input name="display_name" value="<?= e($employee['display_name']) ?>" required>
                                </label>
                                <label>Identifiant
                                    <input name="username" value="<?= e($employee['username']) ?>" required>
                                </label>
                                <label>Nouveau mot de passe
                                    <input name="password" inputmode="numeric" maxlength="20" placeholder="laisser vide">
                                </label>
                                <label>Grade
                                    <select name="grade_id">
                                        <option value="0">Aucun grade</option>
                                        <?php foreach ($grades as $grade): ?>
                                            <option value="<?= (int) $grade['id'] ?>" <?= (int) $employee['grade_id'] === (int) $grade['id'] ? 'selected' : '' ?>>
                                                <?= e($grade['name']) ?> - <?= e($grade['pay_percent']) ?> %
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <label class="checkbox-row">
                                <input name="active" type="checkbox" <?= (int) $employee['active'] === 1 ? 'checked' : '' ?>>
                                Compte actif
                            </label>

                            <button type="submit">Modifier</button>
                        </form>

                        <?php if ($employee['role'] === 'employee'): ?>
                            <form method="post" class="danger-form">
                                <input type="hidden" name="action" value="delete_employee">
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                <button type="submit" class="danger-button">Supprimer l'employe</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>

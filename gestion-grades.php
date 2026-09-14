<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_manage_grades');

$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_grade') {
            $gradeId = (int) ($_POST['grade_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $payPercent = (float) str_replace(',', '.', $_POST['pay_percent'] ?? '100');

            if ($name === '') {
                throw new RuntimeException('Le nom du grade est obligatoire.');
            }

            if ($payPercent < 0) {
                throw new RuntimeException('Le pourcentage de paye ne peut pas etre negatif.');
            }

            if ($gradeId > 0) {
                $stmt = $pdo->prepare('UPDATE grades SET name = ?, pay_percent = ? WHERE id = ?');
                $stmt->execute([$name, $payPercent, $gradeId]);
                $message = 'Grade modifie.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO grades (name, pay_percent) VALUES (?, ?)');
                $stmt->execute([$name, $payPercent]);
                $message = 'Grade cree.';
            }
        }

        if ($action === 'assign_grade') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $gradeId = (int) ($_POST['grade_id'] ?? 0);

            if ($employeeId <= 0) {
                throw new RuntimeException('Employe introuvable.');
            }

            $stmt = $pdo->prepare('UPDATE users SET grade_id = ? WHERE id = ?');
            $stmt->execute([$gradeId > 0 ? $gradeId : null, $employeeId]);
            $message = 'Grade attribue.';
        }

        if ($action === 'delete_grade') {
            $gradeId = (int) ($_POST['grade_id'] ?? 0);

            if ($gradeId <= 0) {
                throw new RuntimeException('Grade introuvable.');
            }

            $stmt = $pdo->prepare('UPDATE users SET grade_id = NULL WHERE grade_id = ?');
            $stmt->execute([$gradeId]);

            $stmt = $pdo->prepare('DELETE FROM grades WHERE id = ?');
            $stmt->execute([$gradeId]);
            $message = 'Grade supprime.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$grades = $pdo->query('SELECT id, name, pay_percent, created_at FROM grades ORDER BY pay_percent DESC, name')->fetchAll();
$employees = $pdo->query('
    SELECT users.id, users.display_name, users.username, users.role, users.grade_id,
           grades.name AS grade_name, grades.pay_percent
    FROM users
    LEFT JOIN grades ON grades.id = users.grade_id
    ORDER BY users.role = "admin" DESC, users.display_name
')->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion grade - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Gestion grade</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_accounting')): ?><a href="dashboard.php">Facturation</a><?php endif; ?>
            <?php if (can($user, 'can_view_referentiel')): ?><a href="referentiel.php">Referentiel</a><?php endif; ?>
            <?php if (can($user, 'can_view_employees')): ?><a href="liste-employes.php">Liste des employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_employees')): ?><a href="gestion-employes.php">Gestion employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_logs')): ?><a href="logs.php">Logs</a><?php endif; ?>
            <a href="logout.php">Deconnexion</a>
        </nav>
    </header>

    <main class="app-layout">
        <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

        <form class="panel" method="post">
            <input type="hidden" name="action" value="save_grade">
            <h2>Creer un grade</h2>
            <div class="employee-form-grid">
                <label>Nom du grade
                    <input name="name" placeholder="Serveur, manager, patron..." required>
                </label>
                <label>% de paye
                    <input name="pay_percent" type="number" min="0" step="0.01" value="100" required>
                </label>
            </div>
            <button type="submit">Creer le grade</button>
        </form>

        <section class="panel table-panel">
            <h2>Grades existants</h2>
            <div class="grade-list">
                <?php foreach ($grades as $grade): ?>
                    <article class="grade-card">
                        <form method="post" class="grade-edit">
                            <input type="hidden" name="action" value="save_grade">
                            <input type="hidden" name="grade_id" value="<?= (int) $grade['id'] ?>">
                            <input name="name" value="<?= e($grade['name']) ?>" required>
                            <input name="pay_percent" type="number" min="0" step="0.01" value="<?= e($grade['pay_percent']) ?>" required>
                            <button type="submit">Modifier</button>
                        </form>
                        <form method="post" class="danger-form">
                            <input type="hidden" name="action" value="delete_grade">
                            <input type="hidden" name="grade_id" value="<?= (int) $grade['id'] ?>">
                            <button type="submit" class="danger-button">Supprimer</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$grades): ?><p class="empty-state">Aucun grade cree pour le moment.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel table-panel">
            <h2>Attribuer les grades</h2>
            <div class="employee-list">
                <?php foreach ($employees as $employee): ?>
                    <form method="post" class="employee-row">
                        <input type="hidden" name="action" value="assign_grade">
                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                        <div>
                            <strong><?= e($employee['display_name']) ?></strong>
                            <span><?= e($employee['username']) ?> - <?= e($employee['role']) ?></span>
                        </div>
                        <select name="grade_id">
                            <option value="0">Aucun grade</option>
                            <?php foreach ($grades as $grade): ?>
                                <option value="<?= (int) $grade['id'] ?>" <?= (int) $employee['grade_id'] === (int) $grade['id'] ? 'selected' : '' ?>>
                                    <?= e($grade['name']) ?> - <?= e($grade['pay_percent']) ?> %
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Attribuer</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>

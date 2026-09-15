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
            
            $perms = [
                'can_view_accounting' => isset($_POST['can_view_accounting']) ? 1 : 0,
                'can_edit_accounting' => isset($_POST['can_edit_accounting']) ? 1 : 0,
                'can_view_referentiel' => isset($_POST['can_view_referentiel']) ? 1 : 0,
                'can_edit_referentiel' => isset($_POST['can_edit_referentiel']) ? 1 : 0,
                'can_view_employees' => isset($_POST['can_view_employees']) ? 1 : 0,
                'can_manage_employees' => isset($_POST['can_manage_employees']) ? 1 : 0,
                'can_manage_grades' => isset($_POST['can_manage_grades']) ? 1 : 0,
                'can_manage_logs' => isset($_POST['can_manage_logs']) ? 1 : 0,
                'can_view_stats' => isset($_POST['can_view_stats']) ? 1 : 0,
                'can_edit_stats' => isset($_POST['can_edit_stats']) ? 1 : 0,
                'can_manage_stock' => isset($_POST['can_manage_stock']) ? 1 : 0,
            ];

            if ($name === '') {
                throw new RuntimeException('Le nom du grade est obligatoire.');
            }

            if ($payPercent < 0) {
                throw new RuntimeException('Le pourcentage de paye ne peut pas etre negatif.');
            }

            if ($gradeId > 0) {
                $stmt = $pdo->prepare('
                    UPDATE grades 
                    SET name = ?, pay_percent = ?, 
                        can_view_accounting = ?, can_edit_accounting = ?, 
                        can_view_referentiel = ?, can_edit_referentiel = ?, 
                        can_view_employees = ?, can_manage_employees = ?, 
                        can_manage_grades = ?, can_manage_logs = ?,
                        can_view_stats = ?, can_edit_stats = ?,
                        can_manage_stock = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $name, $payPercent, 
                    $perms['can_view_accounting'], $perms['can_edit_accounting'],
                    $perms['can_view_referentiel'], $perms['can_edit_referentiel'],
                    $perms['can_view_employees'], $perms['can_manage_employees'],
                    $perms['can_manage_grades'], $perms['can_manage_logs'],
                    $perms['can_view_stats'], $perms['can_edit_stats'],
                    $perms['can_manage_stock'],
                    $gradeId
                ]);
                $message = 'Grade modifié.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO grades (
                        name, pay_percent, 
                        can_view_accounting, can_edit_accounting, 
                        can_view_referentiel, can_edit_referentiel, 
                        can_view_employees, can_manage_employees, 
                        can_manage_grades, can_manage_logs,
                        can_view_stats, can_edit_stats,
                        can_manage_stock
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $name, $payPercent, 
                    $perms['can_view_accounting'], $perms['can_edit_accounting'],
                    $perms['can_view_referentiel'], $perms['can_edit_referentiel'],
                    $perms['can_view_employees'], $perms['can_manage_employees'],
                    $perms['can_manage_grades'], $perms['can_manage_logs'],
                    $perms['can_view_stats'], $perms['can_edit_stats'],
                    $perms['can_manage_stock']
                ]);
                $message = 'Grade créé.';
            }
        }

        if ($action === 'assign_grade') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $gradeId = (int) ($_POST['grade_id'] ?? 0);

            if ($employeeId <= 0) {
                throw new RuntimeException('Employé introuvable.');
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
            $message = 'Grade supprimé.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$grades = $pdo->query('SELECT * FROM grades ORDER BY pay_percent DESC, name')->fetchAll();
$employees = $pdo->query("
    SELECT users.id, users.display_name, users.username, users.role, users.grade_id,
           grades.name AS grade_name, grades.pay_percent
    FROM users
    LEFT JOIN grades ON grades.id = users.grade_id
    ORDER BY CASE WHEN users.role = 'admin' THEN 0 ELSE 1 END, users.display_name
")->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion grades - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <?php render_app_shell_start($user, 'Gestion grades', 'gestion-grades'); ?>
        <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

        <form class="panel" method="post">
            <input type="hidden" name="action" value="save_grade">
            <h2>Créer un grade</h2>
            <div class="employee-form-grid">
                <label>Nom du grade
                    <input name="name" placeholder="Serveur, manager, patron..." required>
                </label>
                <label>% de paye
                    <input name="pay_percent" type="number" min="0" step="0.01" value="100" required>
                </label>
            </div>

            <h3>Permissions du grade</h3>
            <div class="permissions-grid">
                <label class="checkbox-row"><input type="checkbox" name="can_view_accounting" checked> Voir la comptabilité</label>
                <label class="checkbox-row"><input type="checkbox" name="can_edit_accounting" checked> Modifier la comptabilité</label>
                <label class="checkbox-row"><input type="checkbox" name="can_view_referentiel"> Voir le référentiel</label>
                <label class="checkbox-row"><input type="checkbox" name="can_edit_referentiel"> Modifier le référentiel</label>
                <label class="checkbox-row"><input type="checkbox" name="can_view_employees"> Voir les employés</label>
                <label class="checkbox-row"><input type="checkbox" name="can_manage_employees"> Gérer les employés</label>
                <label class="checkbox-row"><input type="checkbox" name="can_manage_grades"> Gérer les grades</label>
                <label class="checkbox-row"><input type="checkbox" name="can_manage_logs"> Voir les logs</label>
                <label class="checkbox-row"><input type="checkbox" name="can_view_stats"> Voir les statistiques</label>
                <label class="checkbox-row"><input type="checkbox" name="can_edit_stats"> Modifier les statistiques</label>
                <label class="checkbox-row"><input type="checkbox" name="can_manage_stock"> Gérer les stocks</label>
            </div>
            <button type="submit">Créer le grade</button>
        </form>

        <section class="panel table-panel">
            <h2>Grades existants</h2>
            <div class="grade-list">
                <?php foreach ($grades as $grade): ?>
                    <article class="grade-card">
                        <form method="post" class="grade-edit">
                            <input type="hidden" name="action" value="save_grade">
                            <input type="hidden" name="grade_id" value="<?= (int) $grade['id'] ?>">
                            <div class="employee-form-grid">
                                <label>Nom <input name="name" value="<?= e($grade['name']) ?>" required></label>
                                <label>% Paye <input name="pay_percent" type="number" min="0" step="0.01" value="<?= e($grade['pay_percent']) ?>" required></label>
                            </div>
                            
                            <div class="permissions-grid mini">
                                <label class="checkbox-row"><input type="checkbox" name="can_view_accounting" <?= $grade['can_view_accounting'] ? 'checked' : '' ?>> Compta (V)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_edit_accounting" <?= $grade['can_edit_accounting'] ? 'checked' : '' ?>> Compta (E)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_view_referentiel" <?= $grade['can_view_referentiel'] ? 'checked' : '' ?>> Ref (V)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_edit_referentiel" <?= $grade['can_edit_referentiel'] ? 'checked' : '' ?>> Ref (E)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_view_employees" <?= $grade['can_view_employees'] ? 'checked' : '' ?>> Emp (V)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_manage_employees" <?= $grade['can_manage_employees'] ? 'checked' : '' ?>> Emp (M)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_manage_grades" <?= $grade['can_manage_grades'] ? 'checked' : '' ?>> Grades</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_manage_logs" <?= $grade['can_manage_logs'] ? 'checked' : '' ?>> Logs</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_view_stats" <?= $grade['can_view_stats'] ? 'checked' : '' ?>> Stats (V)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_edit_stats" <?= $grade['can_edit_stats'] ? 'checked' : '' ?>> Stats (E)</label>
                                <label class="checkbox-row"><input type="checkbox" name="can_manage_stock" <?= $grade['can_manage_stock'] ? 'checked' : '' ?>> Stocks</label>
                            </div>
                            <button type="submit">Modifier</button>
                        </form>
                        <form method="post" class="danger-form">
                            <input type="hidden" name="action" value="delete_grade">
                            <input type="hidden" name="grade_id" value="<?= (int) $grade['id'] ?>">
                            <button type="submit" class="danger-button">Supprimer</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$grades): ?><p class="empty-state">Aucun grade créé pour le moment.</p><?php endif; ?>
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
    <?php render_app_shell_end(); ?>
</body>
</html>

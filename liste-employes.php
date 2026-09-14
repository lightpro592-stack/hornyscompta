<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_view_employees');

$employees = db()->query('
    SELECT users.id, users.username, users.role, users.display_name, users.active, users.created_at,
           grades.name AS grade_name, grades.pay_percent,
           COALESCE(employee_income.income_total, 0) AS income_total,
           can_view_accounting, can_edit_accounting,
           can_view_referentiel, can_edit_referentiel,
           can_view_employees, can_manage_employees, can_manage_grades, can_manage_logs
    FROM users
    LEFT JOIN grades ON grades.id = users.grade_id
    LEFT JOIN (
        SELECT user_id, SUM(amount) AS income_total
        FROM accounting_entries
        WHERE type = "income"
        GROUP BY user_id
    ) employee_income ON employee_income.user_id = users.id
    ORDER BY users.role = "admin" DESC, users.display_name
')->fetchAll();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liste des employes - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Liste des employes</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_accounting')): ?>
                <a href="dashboard.php">Facturation</a>
            <?php endif; ?>
            <?php if (can($user, 'can_view_referentiel')): ?>
                <a href="referentiel.php">Referentiel</a>
            <?php endif; ?>
            <?php if (can($user, 'can_manage_employees')): ?>
                <a href="gestion-employes.php">Gestion employes</a>
            <?php endif; ?>
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
        <section class="panel table-panel">
            <h2>Employes et salaires</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Compte cree le</th>
                            <th>Grade</th>
                            <th>Paye</th>
                            <th>Rapporte</th>
                            <th>Salaire du</th>
                            <th>Part entreprise</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><?= e($employee['display_name']) ?></td>
                                <td><?= e($employee['created_at']) ?></td>
                                <td><?= e($employee['grade_name'] ?: 'Non attribue') ?></td>
                                <td><?= $employee['pay_percent'] !== null ? e($employee['pay_percent']) . ' %' : '-' ?></td>
                                <?php
                                    $incomeTotal = (float) $employee['income_total'];
                                    $payPercent = $employee['pay_percent'] !== null ? (float) $employee['pay_percent'] : 0.0;
                                    $salaryDue = $incomeTotal * ($payPercent / 100);
                                    $companyShare = $incomeTotal - $salaryDue;
                                ?>
                                <td><?= money($incomeTotal) ?></td>
                                <td><?= money($salaryDue) ?></td>
                                <td><?= money($companyShare) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>

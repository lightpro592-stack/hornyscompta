<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_view_accounting');

$pdo = db();
$message = '';
$error = '';
$canEditStatistics = can($user, 'can_manage_grades');

ensure_statistics_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canEditStatistics) {
            throw new RuntimeException('Tu n as pas la permission de modifier les statistiques.');
        }

        $companyCash = parse_amount($_POST['company_cash'] ?? '0');
        $payrollOverrideRaw = trim($_POST['payroll_override'] ?? '');
        $payrollOverride = $payrollOverrideRaw === '' ? null : parse_amount($payrollOverrideRaw);
        $taxPercent = parse_amount($_POST['tax_percent'] ?? '0');

        if ($companyCash < 0 || ($payrollOverride !== null && $payrollOverride < 0) || $taxPercent < 0) {
            throw new RuntimeException('Les montants et pourcentages ne peuvent pas etre negatifs.');
        }

        $stmt = $pdo->prepare('
            UPDATE statistics_settings
            SET company_cash = ?, payroll_override = ?, tax_percent = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
        ');
        $stmt->execute([$companyCash, $payrollOverride, $taxPercent, $user['id']]);
        $message = 'Statistiques mises a jour.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$settings = $pdo->query('SELECT * FROM statistics_settings WHERE id = 1')->fetch();
$turnover = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM accounting_entries WHERE type = 'income'")->fetchColumn();

$employeeRows = $pdo->query("
    SELECT users.id, users.display_name,
           COALESCE(grades.pay_percent, 0) AS pay_percent,
           COALESCE(employee_income.income_total, 0) AS income_total
    FROM users
    LEFT JOIN grades ON grades.id = users.grade_id
    LEFT JOIN (
        SELECT user_id, SUM(amount) AS income_total
        FROM accounting_entries
        WHERE type = 'income'
        GROUP BY user_id
    ) employee_income ON employee_income.user_id = users.id
    WHERE users.role = 'employee'
    ORDER BY users.display_name
")->fetchAll();

$calculatedPayroll = 0.0;
foreach ($employeeRows as $employeeRow) {
    $calculatedPayroll += (float) $employeeRow['income_total'] * ((float) $employeeRow['pay_percent'] / 100);
}

$companyCash = (float) ($settings['company_cash'] ?? 0);
$payrollOverride = $settings['payroll_override'] !== null ? (float) $settings['payroll_override'] : null;
$payroll = $payrollOverride ?? $calculatedPayroll;
$taxPercent = (float) ($settings['tax_percent'] ?? 0);
$taxBase = max(0, $turnover - $payroll);
$taxes = $taxBase * ($taxPercent / 100);
$finalProfit = $turnover - $payroll - $taxes;

function ensure_statistics_settings(PDO $pdo): void
{
    if (DB_DRIVER === 'pgsql') {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS statistics_settings (
                id INTEGER PRIMARY KEY,
                company_cash DECIMAL(10,2) NOT NULL DEFAULT 0,
                payroll_override DECIMAL(10,2) NULL,
                tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                updated_by INTEGER NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    } else {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS statistics_settings (
                id INT UNSIGNED PRIMARY KEY,
                company_cash DECIMAL(10,2) NOT NULL DEFAULT 0,
                payroll_override DECIMAL(10,2) NULL,
                tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                updated_by INT UNSIGNED NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    $stmt = $pdo->prepare('SELECT id FROM statistics_settings WHERE id = 1');
    $stmt->execute();
    if (!$stmt->fetch()) {
        $insert = $pdo->prepare('INSERT INTO statistics_settings (id, company_cash, payroll_override, tax_percent) VALUES (1, 0, NULL, 0)');
        $insert->execute();
    }
}

function parse_amount(string $value): float
{
    return (float) str_replace(',', '.', $value);
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statistique - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Statistique</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_accounting')): ?><a href="dashboard.php">Facturation</a><?php endif; ?>
            <?php if (can($user, 'can_view_referentiel')): ?><a href="referentiel.php">Referentiel</a><?php endif; ?>
            <?php if (can($user, 'can_view_employees')): ?><a href="liste-employes.php">Liste des employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_employees')): ?><a href="gestion-employes.php">Gestion employes</a><?php endif; ?>
            <?php if (can($user, 'can_manage_grades')): ?><a href="gestion-grades.php">Gestion grade</a><?php endif; ?>
            <a href="statistique.php">Statistique</a>
            <?php if (can($user, 'can_manage_logs')): ?><a href="logs.php">Logs</a><?php endif; ?>
            <a href="logout.php">Deconnexion</a>
        </nav>
    </header>

    <main class="app-layout">
        <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="metrics statistics-metrics">
            <article>
                <span>Argent total entreprise</span>
                <strong><?= money($companyCash) ?></strong>
            </article>
            <article>
                <span>Chiffre d'affaires</span>
                <strong><?= money($turnover) ?></strong>
            </article>
            <article>
                <span>Masse salariale</span>
                <strong><?= money($payroll) ?></strong>
            </article>
            <article>
                <span>Impots (<?= e((string) $taxPercent) ?> %)</span>
                <strong><?= money($taxes) ?></strong>
            </article>
            <article>
                <span>Benefice final</span>
                <strong><?= money($finalProfit) ?></strong>
            </article>
        </section>

        <section class="panel">
            <h2>Calcul</h2>
            <div class="formula-grid">
                <div><span>Base impots</span><strong><?= money($turnover) ?> - <?= money($payroll) ?> = <?= money($taxBase) ?></strong></div>
                <div><span>Impots</span><strong><?= money($taxBase) ?> x <?= e((string) $taxPercent) ?> % = <?= money($taxes) ?></strong></div>
                <div><span>Benefice final</span><strong><?= money($turnover) ?> - <?= money($payroll) ?> - <?= money($taxes) ?> = <?= money($finalProfit) ?></strong></div>
            </div>
        </section>

        <?php if ($canEditStatistics): ?>
            <form class="panel" method="post">
                <h2>Modifier les statistiques</h2>
                <div class="employee-form-grid">
                    <label>Argent total entreprise
                        <input name="company_cash" type="number" min="0" step="0.01" value="<?= e((string) $companyCash) ?>" required>
                    </label>
                    <label>Masse salariale forcee
                        <input name="payroll_override" type="number" min="0" step="0.01" value="<?= $payrollOverride !== null ? e((string) $payrollOverride) : '' ?>" placeholder="<?= e((string) $calculatedPayroll) ?>">
                    </label>
                    <label>% impots
                        <input name="tax_percent" type="number" min="0" step="0.01" value="<?= e((string) $taxPercent) ?>" required>
                    </label>
                </div>
                <button type="submit">Enregistrer</button>
            </form>
        <?php endif; ?>

        <section class="panel table-panel">
            <h2>Detail masse salariale</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employe</th>
                            <th>Rapporte</th>
                            <th>% paye</th>
                            <th>Salaire calcule</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employeeRows as $employeeRow): ?>
                            <?php $employeeSalary = (float) $employeeRow['income_total'] * ((float) $employeeRow['pay_percent'] / 100); ?>
                            <tr>
                                <td><?= e($employeeRow['display_name']) ?></td>
                                <td><?= money($employeeRow['income_total']) ?></td>
                                <td><?= e((string) $employeeRow['pay_percent']) ?> %</td>
                                <td><?= money($employeeSalary) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>

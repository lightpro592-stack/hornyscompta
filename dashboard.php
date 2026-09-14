<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_view_accounting');
$pdo = db();
$message = '';
$error = '';
$products = $pdo->query('
    SELECT id, name, category, price
    FROM products
    WHERE active = 1
    ORDER BY category, name
')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_invoice') {
            require_permission($user, 'can_edit_accounting');
            $productIds = $_POST['product_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $items = [];
            $total = 0.0;

            $productMap = [];
            foreach ($products as $product) {
                $productMap[(int) $product['id']] = $product;
            }

            foreach ($productIds as $index => $productId) {
                $productId = (int) $productId;
                $quantity = (int) ($quantities[$index] ?? 0);

                if ($productId <= 0 || $quantity <= 0 || !isset($productMap[$productId])) {
                    continue;
                }

                $product = $productMap[$productId];
                $unitPrice = (float) $product['price'];
                $lineTotal = $unitPrice * $quantity;
                $total += $lineTotal;
                $items[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ];
            }

            if (!$items || $total <= 0) {
                throw new RuntimeException('Ajoute au moins un produit avec une quantite.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                INSERT INTO accounting_entries
                    (user_id, entry_date, shift_label, category, type, amount, note)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ' . (DB_DRIVER === 'pgsql' ? 'RETURNING id' : '') . '
            ');
            $stmt->execute([
                $user['id'],
                $_POST['invoice_date'] ?: date('Y-m-d'),
                'Facture',
                'Vente restaurant',
                'income',
                $total,
                trim($_POST['note'] ?? 'Facture caisse'),
            ]);
            $entryId = DB_DRIVER === 'pgsql' ? (int) $stmt->fetchColumn() : (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('
                INSERT INTO invoices (user_id, accounting_entry_id, invoice_date, total, note)
                VALUES (?, ?, ?, ?, ?)
                ' . (DB_DRIVER === 'pgsql' ? 'RETURNING id' : '') . '
            ');
            $stmt->execute([
                $user['id'],
                $entryId,
                $_POST['invoice_date'] ?: date('Y-m-d'),
                $total,
                trim($_POST['note'] ?? ''),
            ]);
            $invoiceId = DB_DRIVER === 'pgsql' ? (int) $stmt->fetchColumn() : (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('
                INSERT INTO invoice_items
                    (invoice_id, product_id, product_name, unit_price, quantity, line_total)
                VALUES (?, ?, ?, ?, ?, ?)
            ');

            foreach ($items as $item) {
                $stmt->execute([
                    $invoiceId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['unit_price'],
                    $item['quantity'],
                    $item['line_total'],
                ]);
            }

            $pdo->commit();
            $soldItems = [];
            foreach ($items as $item) {
                $soldItems[] = $item['quantity'] . ' x ' . $item['product_name'] . ' (' . money($item['line_total']) . ')';
            }

            send_discord_log('invoice', 'Nouvelle facture', 'Une facture a ete creee.', [
                'Employe' => $user['display_name'],
                'Jour' => date('d/m/Y', strtotime($_POST['invoice_date'] ?: date('Y-m-d'))),
                'Heure' => date('H:i:s'),
                'Total' => money($total),
                'Vendu' => implode("\n", $soldItems),
            ]);
            $message = 'Facture creee.';
        }

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$where = $user['role'] === 'admin' ? '' : 'WHERE entries.user_id = :user_id';
$entriesSql = "
    SELECT entries.*, users.display_name
    FROM accounting_entries entries
    JOIN users ON users.id = entries.user_id
    $where
    ORDER BY entries.entry_date DESC, entries.created_at DESC
    LIMIT 80
";
$entries = $pdo->prepare($entriesSql);
if ($user['role'] !== 'admin') {
    $entries->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
}
$entries->execute();
$entryRows = $entries->fetchAll();

$summarySql = "
    SELECT
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income_total,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense_total
    FROM accounting_entries
";
if ($user['role'] !== 'admin') {
    $summarySql .= ' WHERE user_id = ' . (int) $user['id'];
}
$summary = $pdo->query($summarySql)->fetch();
$balance = (float) $summary['income_total'] - (float) $summary['expense_total'];

$invoiceWhere = $user['role'] === 'admin' ? '' : 'WHERE invoices.user_id = :user_id';
$invoiceStmt = $pdo->prepare("
    SELECT invoices.*, users.display_name
    FROM invoices
    JOIN users ON users.id = invoices.user_id
    $invoiceWhere
    ORDER BY invoices.created_at DESC
    LIMIT 10
");
if ($user['role'] !== 'admin') {
    $invoiceStmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
}
$invoiceStmt->execute();
$invoices = $invoiceStmt->fetchAll();

$invoiceItems = [];
if ($invoices) {
    $invoiceIds = array_column($invoices, 'id');
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT *
        FROM invoice_items
        WHERE invoice_id IN ($placeholders)
        ORDER BY id
    ");
    $itemsStmt->execute($invoiceIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $invoiceItems[$item['invoice_id']][] = $item;
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facturation - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Facturation</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - <?= e($user['role']) ?></span>
            <?php if (can($user, 'can_view_referentiel')): ?>
                <a href="referentiel.php">Referentiel</a>
            <?php endif; ?>
            <?php if (can($user, 'can_view_employees')): ?>
                <a href="liste-employes.php">Liste des employes</a>
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
        <?php if ($message): ?>
            <div class="notice"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="metrics">
            <article>
                <span>Ventes</span>
                <strong><?= money($summary['income_total']) ?></strong>
            </article>
            <article>
                <span>Sorties</span>
                <strong><?= money($summary['expense_total']) ?></strong>
            </article>
            <article>
                <span>Solde</span>
                <strong><?= money($balance) ?></strong>
            </article>
        </section>

        <?php if (can($user, 'can_edit_accounting')): ?>
        <section class="grid one-column">
            <form class="panel invoice-form" method="post" id="invoice-form">
                <input type="hidden" name="action" value="create_invoice">
                <h2>Nouvelle facture</h2>

                <div class="employee-form-grid">
                    <label for="invoice_date">Date
                        <input id="invoice_date" name="invoice_date" type="date" value="<?= date('Y-m-d') ?>" required>
                    </label>

                    <label for="employee_name">Employe
                        <input id="employee_name" value="<?= e($user['display_name']) ?>" readonly>
                    </label>
                </div>

                <div class="invoice-lines" id="invoice-lines">
                    <div class="invoice-line">
                        <select name="product_id[]" class="product-select" required>
                            <option value="">Choisir un produit</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int) $product['id'] ?>" data-price="<?= e($product['price']) ?>">
                                    <?= e($product['name']) ?> - <?= money($product['price']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input name="quantity[]" class="quantity-input" type="number" min="1" step="1" value="1" required>
                        <strong class="line-total">0,00 $</strong>
                        <button type="button" class="remove-line">Retirer</button>
                    </div>
                </div>

                <div class="invoice-actions">
                    <button type="button" id="add-line">Ajouter un item</button>
                    <div class="invoice-total">
                        <span>Total facture</span>
                        <strong id="invoice-total">0,00 $</strong>
                    </div>
                </div>

                <label for="note">Note</label>
                <textarea id="note" name="note" rows="3" placeholder="Client, commande speciale..."></textarea>

                <button type="submit">Valider la facture</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel table-panel">
            <h2>Dernieres factures</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employe</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td><?= e($invoice['invoice_date']) ?></td>
                                <td><?= e($invoice['display_name']) ?></td>
                                <td>
                                    <div class="invoice-items-summary">
                                        <?php foreach ($invoiceItems[$invoice['id']] ?? [] as $item): ?>
                                            <span><?= e($item['quantity']) ?> x <?= e($item['product_name']) ?> (<?= money($item['line_total']) ?>)</span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?= money($invoice['total']) ?></td>
                                <td><?= e($invoice['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invoices): ?>
                            <tr>
                                <td colspan="5">Aucune facture pour le moment.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
        const invoiceLines = document.querySelector('#invoice-lines');
        const addLineButton = document.querySelector('#add-line');
        const invoiceTotal = document.querySelector('#invoice-total');
        const formatMoney = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' });

        function updateInvoiceTotal() {
            let total = 0;
            document.querySelectorAll('.invoice-line').forEach((line) => {
                const select = line.querySelector('.product-select');
                const quantity = Number(line.querySelector('.quantity-input').value || 0);
                const price = Number(select.selectedOptions[0]?.dataset.price || 0);
                const lineTotal = price * quantity;
                total += lineTotal;
                line.querySelector('.line-total').textContent = formatMoney.format(lineTotal);
            });
            invoiceTotal.textContent = formatMoney.format(total);
        }

        function bindLine(line) {
            line.querySelector('.product-select').addEventListener('change', updateInvoiceTotal);
            line.querySelector('.quantity-input').addEventListener('input', updateInvoiceTotal);
            line.querySelector('.remove-line').addEventListener('click', () => {
                if (document.querySelectorAll('.invoice-line').length > 1) {
                    line.remove();
                    updateInvoiceTotal();
                }
            });
        }

        if (invoiceLines && addLineButton) {
            document.querySelectorAll('.invoice-line').forEach(bindLine);
            addLineButton.addEventListener('click', () => {
                const clone = invoiceLines.querySelector('.invoice-line').cloneNode(true);
                clone.querySelector('.product-select').value = '';
                clone.querySelector('.quantity-input').value = '1';
                clone.querySelector('.line-total').textContent = formatMoney.format(0);
                invoiceLines.appendChild(clone);
                bindLine(clone);
            });
            updateInvoiceTotal();
        }
    </script>
</body>
</html>

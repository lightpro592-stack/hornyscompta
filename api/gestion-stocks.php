<?php
require_once __DIR__ . '/../config.php';
$user = require_login();
require_permission($user, 'can_manage_stock');

$message = '';
$error = '';

// Traitement de la mise à jour des stocks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $ingredientId = (int)$_POST['ingredient_id'];
    $newQuantity = (float)$_POST['quantity'];
    $oldQuantity = (float)$_POST['old_quantity'];
    $ingredientName = $_POST['ingredient_name'];

    try {
        $stmt = db()->prepare('UPDATE ingredients SET stock_quantity = ? WHERE id = ?');
        $stmt->execute([$newQuantity, $ingredientId]);

        // Log Discord
        send_discord_log(
            'stock',
            'Mise à jour du stock',
            sprintf('Le stock de **%s** a été modifié.', $ingredientName),
            [
                'Utilisateur' => $user['display_name'],
                'Ancien stock' => $oldQuantity,
                'Nouveau stock' => $newQuantity,
                'Différence' => ($newQuantity - $oldQuantity)
            ]
        );

        $message = "Stock mis à jour pour $ingredientName.";
    } catch (PDOException $e) {
        $error = "Erreur lors de la mise à jour : " . $e->getMessage();
    }
}

// Récupération de tous les ingrédients avec leurs stocks
$stmt = db()->query('SELECT * FROM ingredients ORDER BY name ASC');
$ingredients = $stmt->fetchAll();

// Calcul du stock théorique basé sur les recettes (Optionnel, affichage informatif)
// Pour chaque produit vendu, on pourrait déduire, mais ici on gère le stock manuel comme demandé.

render_app_shell_start($user, 'Gestion des Stocks', 'stocks');
?>

<div class="stats-grid">
    <div class="stats-card full-width">
        <div class="card-header">
            <h2>Inventaire des ingrédients</h2>
            <p>Mettez à jour les quantités réelles en stock après chaque livraison ou inventaire.</p>
        </div>

        <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ingrédient</th>
                        <th>Unité</th>
                        <th>Quantité actuelle</th>
                        <th>Nouvelle quantité</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ingredients as $ing): ?>
                        <tr>
                            <td><strong><?= e($ing['name']) ?></strong></td>
                            <td><?= e($ing['unit']) ?></td>
                            <td><?= (float)$ing['stock_quantity'] ?></td>
                            <td colspan="2">
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="ingredient_id" value="<?= $ing['id'] ?>">
                                    <input type="hidden" name="ingredient_name" value="<?= e($ing['name']) ?>">
                                    <input type="hidden" name="old_quantity" value="<?= $ing['stock_quantity'] ?>">
                                    <input type="number" name="quantity" step="0.01" value="<?= (float)$ing['stock_quantity'] ?>" required>
                                    <button type="submit" name="update_stock" class="btn-small">Sauvegarder</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.inline-form {
    display: flex;
    gap: 10px;
    align-items: center;
}
.inline-form input {
    width: 100px;
    margin: 0;
}
.btn-small {
    padding: 5px 10px;
    font-size: 0.9em;
}
.full-width {
    grid-column: 1 / -1;
}
</style>

<?php render_app_shell_end(); ?>

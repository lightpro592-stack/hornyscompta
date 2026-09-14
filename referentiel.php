<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_permission($user, 'can_view_referentiel');

$pdo = db();
$message = '';
$error = '';
$canEditReferentiel = can($user, 'can_edit_referentiel');

$categoryLabels = [
    'burger' => 'Burger',
    'fries' => 'Frites',
    'drink' => 'Soda / boisson',
    'dessert' => 'Dessert',
    'other' => 'Autre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        require_permission($user, 'can_edit_referentiel');

        if ($action === 'save_product') {
            $id = (int) ($_POST['product_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $category = array_key_exists($_POST['category'] ?? '', $categoryLabels) ? $_POST['category'] : 'other';
            $price = (float) str_replace(',', '.', $_POST['price'] ?? '0');
            $active = isset($_POST['active']) ? 1 : 0;

            if ($name === '') {
                throw new RuntimeException('Le nom du produit est obligatoire.');
            }

            if ($price < 0) {
                throw new RuntimeException('Le prix ne peut pas etre negatif.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE products SET name = ?, category = ?, price = ?, active = ? WHERE id = ?');
                $stmt->execute([$name, $category, $price, $active, $id]);
                $message = 'Produit modifie.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (name, category, price, active) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $category, $price, $active]);
                $message = 'Produit ajoute.';
            }
        }

        if ($action === 'save_ingredient') {
            $productId = (int) ($_POST['ingredient_product_id'] ?? 0);
            $name = trim($_POST['ingredient_name'] ?? '');
            $unit = trim($_POST['unit'] ?? 'piece');
            $quantity = (float) str_replace(',', '.', $_POST['ingredient_quantity'] ?? '1');

            if ($productId <= 0) {
                throw new RuntimeException('Choisis le produit concerne par la recette.');
            }

            if ($name === '') {
                throw new RuntimeException('Le nom de l ingredient est obligatoire.');
            }

            if ($quantity <= 0) {
                throw new RuntimeException('La quantite doit etre superieure a 0.');
            }

            $stmt = $pdo->prepare(DB_DRIVER === 'pgsql'
                ? 'INSERT INTO ingredients (name, unit) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET unit = EXCLUDED.unit RETURNING id'
                : 'INSERT INTO ingredients (name, unit) VALUES (?, ?) ON DUPLICATE KEY UPDATE unit = VALUES(unit)'
            );
            $stmt->execute([$name, $unit ?: 'piece']);

            $ingredientId = DB_DRIVER === 'pgsql' ? (int) $stmt->fetchColumn() : (int) $pdo->lastInsertId();
            if ($ingredientId === 0) {
                $stmt = $pdo->prepare('SELECT id FROM ingredients WHERE name = ? LIMIT 1');
                $stmt->execute([$name]);
                $ingredientId = (int) $stmt->fetchColumn();
            }

            $stmt = $pdo->prepare(DB_DRIVER === 'pgsql'
                ? 'INSERT INTO product_ingredients (product_id, ingredient_id, quantity) VALUES (?, ?, ?) ON CONFLICT (product_id, ingredient_id) DO UPDATE SET quantity = EXCLUDED.quantity'
                : 'INSERT INTO product_ingredients (product_id, ingredient_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stmt->execute([$productId, $ingredientId, $quantity]);
            $message = 'Ingredient ajoute a la recette.';
        }

        if ($action === 'add_recipe_ingredient') {
            $productId = (int) ($_POST['recipe_product_id'] ?? 0);
            $ingredientId = (int) ($_POST['recipe_ingredient_id'] ?? 0);
            $quantity = (float) str_replace(',', '.', $_POST['quantity'] ?? '1');

            if ($productId <= 0 || $ingredientId <= 0) {
                throw new RuntimeException('Choisis un produit et un ingredient.');
            }

            if ($quantity <= 0) {
                throw new RuntimeException('La quantite doit etre superieure a 0.');
            }

            $stmt = $pdo->prepare(DB_DRIVER === 'pgsql'
                ? 'INSERT INTO product_ingredients (product_id, ingredient_id, quantity) VALUES (?, ?, ?) ON CONFLICT (product_id, ingredient_id) DO UPDATE SET quantity = EXCLUDED.quantity'
                : 'INSERT INTO product_ingredients (product_id, ingredient_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stmt->execute([$productId, $ingredientId, $quantity]);
            $message = 'Recette mise a jour.';
        }

        if ($action === 'delete_recipe_ingredient') {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM product_ingredients WHERE id = ?');
            $stmt->execute([$recipeId]);
            $message = 'Ingredient retire de la recette.';
        }

        if ($action === 'delete_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);

            if ($productId <= 0) {
                throw new RuntimeException('Produit introuvable.');
            }

            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $message = 'Recette supprimee.';
        }

        if ($action === 'delete_ingredient') {
            $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);

            if ($ingredientId <= 0) {
                throw new RuntimeException('Ingredient introuvable.');
            }

            $stmt = $pdo->prepare('DELETE FROM ingredients WHERE id = ?');
            $stmt->execute([$ingredientId]);
            $message = 'Ingredient supprime.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$products = $pdo->query('SELECT * FROM products ORDER BY category, name')->fetchAll();
$ingredients = $pdo->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$recipes = $pdo->query('
    SELECT
        recipe.id,
        recipe.quantity,
        products.id AS product_id,
        products.name AS product_name,
        ingredients.name AS ingredient_name,
        ingredients.unit
    FROM product_ingredients recipe
    JOIN products ON products.id = recipe.product_id
    JOIN ingredients ON ingredients.id = recipe.ingredient_id
    ORDER BY products.name, ingredients.name
')->fetchAll();

$recipesByProduct = [];
foreach ($recipes as $recipe) {
    $recipesByProduct[$recipe['product_id']][] = $recipe;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Referentiel - Horny's</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <p class="eyebrow">Restaurant le Horny's</p>
            <h1>Referentiel</h1>
        </div>
        <nav>
            <span><?= e($user['display_name']) ?> - admin</span>
            <?php if (can($user, 'can_view_accounting')): ?>
                <a href="dashboard.php">Facturation</a>
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

        <?php if ($canEditReferentiel): ?>
        <section class="grid">
            <form class="panel" method="post">
                <input type="hidden" name="action" value="save_product">
                <h2>Creer un produit</h2>

                <label for="name">Nom</label>
                <input id="name" name="name" placeholder="Horny's Burger, frites, soda..." required>

                <label for="category">Categorie</label>
                <select id="category" name="category">
                    <?php foreach ($categoryLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="price">Prix</label>
                <input id="price" name="price" type="number" min="0" step="0.01" required>

                <label class="checkbox-row">
                    <input name="active" type="checkbox" checked>
                    Produit actif
                </label>

                <button type="submit">Ajouter au menu</button>
            </form>

            <form class="panel" method="post">
                <input type="hidden" name="action" value="save_ingredient">
                <h2>Ajouter un ingredient a une recette</h2>

                <label for="ingredient_product_id">Produit</label>
                <select id="ingredient_product_id" name="ingredient_product_id" required>
                    <option value="">Choisir</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="ingredient_name">Nom</label>
                <input id="ingredient_name" name="ingredient_name" placeholder="Steak, cheddar, pain, sauce..." required>

                <label for="unit">Unite</label>
                <input id="unit" name="unit" placeholder="piece, tranche, portion, cl..." value="piece" required>

                <label for="ingredient_quantity">Quantite pour la recette</label>
                <input id="ingredient_quantity" name="ingredient_quantity" type="number" min="0.01" step="0.01" value="1" required>

                <button type="submit">Ajouter a la recette</button>
            </form>
        </section>

        <section class="panel table-panel">
            <h2>Composer une recette</h2>
            <form class="inline-form" method="post">
                <input type="hidden" name="action" value="add_recipe_ingredient">

                <label for="recipe_product_id">Produit</label>
                <select id="recipe_product_id" name="recipe_product_id" required>
                    <option value="">Choisir</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="recipe_ingredient_id">Ingredient</label>
                <select id="recipe_ingredient_id" name="recipe_ingredient_id" required>
                    <option value="">Choisir</option>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <option value="<?= (int) $ingredient['id'] ?>"><?= e($ingredient['name']) ?> (<?= e($ingredient['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <label for="quantity">Quantite</label>
                <input id="quantity" name="quantity" type="number" min="0.01" step="0.01" value="1" required>

                <button type="submit">Ajouter a la recette</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel table-panel">
            <h2>Produits et recettes</h2>
            <div class="product-list">
                <?php foreach ($products as $product): ?>
                    <article class="product-card">
                        <?php if ($canEditReferentiel): ?>
                        <form method="post" class="product-edit">
                            <input type="hidden" name="action" value="save_product">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                            <input name="name" value="<?= e($product['name']) ?>" required>
                            <select name="category">
                                <?php foreach ($categoryLabels as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $product['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="price" type="number" min="0" step="0.01" value="<?= e($product['price']) ?>" required>
                            <label class="checkbox-row">
                                <input name="active" type="checkbox" <?= (int) $product['active'] === 1 ? 'checked' : '' ?>>
                                Actif
                            </label>
                            <button type="submit">Modifier</button>
                        </form>

                        <form method="post" class="danger-form">
                            <input type="hidden" name="action" value="delete_product">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                            <button type="submit" class="danger-button">Supprimer cette recette</button>
                        </form>
                        <?php else: ?>
                            <div class="product-view">
                                <strong><?= e($product['name']) ?></strong>
                                <span><?= e($categoryLabels[$product['category']] ?? 'Autre') ?></span>
                                <span><?= money($product['price']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="recipe-lines">
                            <?php foreach ($recipesByProduct[$product['id']] ?? [] as $recipe): ?>
                                <?php if ($canEditReferentiel): ?>
                                <form method="post" class="recipe-line">
                                    <input type="hidden" name="action" value="delete_recipe_ingredient">
                                    <input type="hidden" name="recipe_id" value="<?= (int) $recipe['id'] ?>">
                                    <span><?= e($recipe['ingredient_name']) ?></span>
                                    <strong><?= e($recipe['quantity']) ?> <?= e($recipe['unit']) ?></strong>
                                    <button type="submit" aria-label="Retirer">Retirer</button>
                                </form>
                                <?php else: ?>
                                    <div class="recipe-line">
                                        <span><?= e($recipe['ingredient_name']) ?></span>
                                        <strong><?= e($recipe['quantity']) ?> <?= e($recipe['unit']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($recipesByProduct[$product['id']])): ?>
                                <p class="empty-state">Aucun ingredient dans cette recette.</p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <p class="empty-state">Aucun produit cree pour le moment.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($canEditReferentiel): ?>
        <section class="panel table-panel">
            <h2>Ingredients existants</h2>
            <div class="ingredient-list">
                <?php foreach ($ingredients as $ingredient): ?>
                    <form method="post" class="ingredient-row">
                        <input type="hidden" name="action" value="delete_ingredient">
                        <input type="hidden" name="ingredient_id" value="<?= (int) $ingredient['id'] ?>">
                        <span><?= e($ingredient['name']) ?></span>
                        <strong><?= e($ingredient['unit']) ?></strong>
                        <button type="submit" class="danger-button">Supprimer</button>
                    </form>
                <?php endforeach; ?>
                <?php if (!$ingredients): ?>
                    <p class="empty-state">Aucun ingredient cree pour le moment.</p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>

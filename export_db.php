<?php
require_once __DIR__ . '/config.php';

// Seul l'admin peut exporter les données
$user = require_login();
require_admin($user);

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="hornys_export_' . date('Y-m-d_H-i') . '.sql"');

$pdo = db();
$tables = [
    'grades',
    'users',
    'accounting_entries',
    'products',
    'invoices',
    'invoice_items',
    'ingredients',
    'product_ingredients',
    'discord_webhooks'
];

echo "-- Horny's Export SQL\n";
echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    // Vider la table en ligne (optionnel, mais propre pour un transfert complet)
    echo "-- Data for table `$table`\n";
    echo "TRUNCATE TABLE `$table`;\n";

    $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $columns = array_keys($rows[0]);
        $colList = implode('`, `', $columns);
        
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $values[] = "NULL";
                } elseif (is_numeric($val) && !in_array($table, ['users', 'grades', 'products', 'ingredients']) ) {
                     // Pour les montants et IDs
                     $values[] = $val;
                } else {
                    $values[] = $pdo->quote((string)$val);
                }
            }
            $valList = implode(', ', $values);
            echo "INSERT INTO `$table` (`$colList`) VALUES ($valList);\n";
        }
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
exit;

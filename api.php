<?php
/**
 * BistroFlow POS REST API - Backend for HeidiSQL / MySQL Integration
 * Set Content-Type to JSON & handle CORS
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = 'localhost';
$db   = 'bistroflow_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_products':
        $stmt = $pdo->query("SELECT * FROM products ORDER BY id ASC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        break;

    case 'save_product':
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['id']) && !empty($input['id'])) {
            // Update
            $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, price=?, stock=?, emoji=? WHERE id=?");
            $stmt->execute([$input['name'], $input['category'], $input['price'], $input['stock'], $input['emoji'], $input['id']]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO products (name, category, price, stock, emoji) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$input['name'], $input['category'], $input['price'], $input['stock'], $input['emoji']]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_product':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'get_transactions':
        $stmt = $pdo->query("SELECT * FROM transactions ORDER BY datetime DESC");
        $transactions = $stmt->fetchAll();
        foreach ($transactions as &$tx) {
            $stmtItems = $pdo->prepare("SELECT product_name as name, price, qty FROM transaction_details WHERE transaction_id = ?");
            $stmtItems->execute([$tx['id']]);
            $tx['items'] = $stmtItems->fetchAll();
            $tx['subtotal'] = (float)$tx['subtotal'];
            $tx['discountPercent'] = (int)$tx['discount_percent'];
            $tx['discountVal'] = (float)$tx['discount_val'];
            $tx['taxVal'] = (float)$tx['tax_val'];
            $tx['grandTotal'] = (float)$tx['grand_total'];
            $tx['cashGiven'] = (float)$tx['cash_given'];
            $tx['change'] = (float)$tx['change_val'];
        }
        echo json_encode(['status' => 'success', 'data' => $transactions]);
        break;

    case 'save_transaction':
        $input = json_decode(file_get_contents('php://input'), true);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (id, datetime, subtotal, discount_percent, discount_val, tax_val, grand_total, cash_given, change_val) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['id'], $input['datetime'], $input['subtotal'], $input['discountPercent'],
                $input['discountVal'], $input['taxVal'], $input['grandTotal'], $input['cashGiven'], $input['change']
            ]);

            $stmtDetail = $pdo->prepare("INSERT INTO transaction_details (transaction_id, product_id, product_name, price, qty, subtotal_item) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");

            foreach ($input['items'] as $item) {
                $stmtDetail->execute([$input['id'], $item['id'] ?? null, $item['name'], $item['price'], $item['qty'], $item['price'] * $item['qty']]);
                if (isset($item['id'])) {
                    $stmtStock->execute([$item['qty'], $item['id']]);
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>
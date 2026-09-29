<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataFile = sys_get_temp_dir() . '/hunre_escrow_orders.json';

$defaultOrders = [
    'ORD-HUNRE-98471' => [
        'order_code' => 'ORD-HUNRE-98471',
        'buyer_id' => 1,
        'buyer_name' => 'Nguyễn Văn An',
        'seller_id' => 3,
        'seller_name' => 'Lê Hoàng Cường',
        'product_id' => 3,
        'product_title' => 'Bàn phím cơ DareU EK87 Blue Switch',
        'hub_id' => 1,
        'hub_name' => 'Trạm Hub CS1 (Nhà A - Văn Phòng Đoàn Trường)',
        'locker_code' => 'LOCKER-M-01',
        'escrow_amount' => 250000.0,
        'saga_step' => 4,
        'status' => 'STORED_AT_HUB',
        'deadline' => '17:30 ngày 18/09/2026',
        'created_at' => '2026-09-16 11:00:00'
    ]
];

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode($defaultOrders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
$orders = json_decode(file_get_contents($dataFile), true) ?: $defaultOrders;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Lấy danh sách hoặc 1 đơn
if ($method === 'GET') {
    $parts = explode('/', trim($path, '/'));
    $orderCode = end($parts);
    if (!empty($orderCode) && isset($orders[$orderCode])) {
        echo json_encode(['success' => true, 'order' => $orders[$orderCode]]);
    } else {
        echo json_encode(['success' => true, 'orders' => array_values($orders), 'order' => reset($orders)]);
    }
    exit;
}

// Tạo đơn mới
if ($method === 'POST' && strpos($path, 'orders') !== false && !strpos($path, 'release') && !strpos($path, 'dispute')) {
    $code = 'ORD-HUNRE-' . str_pad((string)(time() % 100000), 5, '0', STR_PAD_LEFT);
    $newOrder = [
        'order_code' => $code,
        'buyer_id' => (int)($input['buyer_id'] ?? 1),
        'buyer_name' => $input['buyer_name'] ?? 'Nguyễn Văn An',
        'seller_id' => (int)($input['seller_id'] ?? 3),
        'seller_name' => $input['seller_name'] ?? 'Lê Hoàng Cường',
        'product_id' => (int)($input['product_id'] ?? 1),
        'product_title' => $input['product_title'] ?? 'Đồ dùng học tập HUNRE',
        'hub_id' => 1,
        'hub_name' => 'Trạm Hub CS1 (Nhà A - Văn Phòng Đoàn Trường)',
        'locker_code' => 'LOCKER-M-02',
        'escrow_amount' => (float)($input['agreed_price'] ?? $input['escrow_amount'] ?? 100000),
        'saga_step' => 2,
        'status' => 'DEPOSITED',
        'deadline' => '17:30 ngày mai',
        'created_at' => date('Y-m-d H:i:s')
    ];
    $orders[$code] = $newOrder;
    file_put_contents($dataFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Tạo đơn ký quỹ thành công!', 'order' => $newOrder]);
    exit;
}

// Advance / Release / Dispute
if ($method === 'POST') {
    $parts = explode('/', trim($path, '/'));
    $orderCode = $input['order_code'] ?? ($parts[count($parts) - 2] ?? null);
    if (!isset($orders[$orderCode])) {
        $orderCode = array_key_first($orders);
    }

    $action = $input['action'] ?? (strpos($path, 'release') !== false ? 'SATISFIED' : (strpos($path, 'dispute') !== false ? 'DISPUTE' : 'CHECKOUT'));

    if ($action === 'SATISFIED' || strpos($path, 'release') !== false) {
        $orders[$orderCode]['saga_step'] = 7;
        $orders[$orderCode]['status'] = 'RELEASED';
        $msg = 'Giải ngân ký quỹ thành công! Cộng +5 Điểm Uy Tín cho cả hai sinh viên.';
    } elseif ($action === 'DISPUTE' || strpos($path, 'dispute') !== false) {
        $orders[$orderCode]['saga_step'] = 7;
        $orders[$orderCode]['status'] = 'DISPUTED';
        $msg = 'Đã tiếp nhận khiếu nại! Tiền ký quỹ bị đóng băng hoàn cọc.';
    } else {
        $orders[$orderCode]['saga_step'] = 5;
        $orders[$orderCode]['status'] = 'BUYER_CHECKOUT';
        $msg = 'Cập nhật trạng thái thành công.';
    }

    file_put_contents($dataFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true, 'message' => $msg, 'order' => $orders[$orderCode]]);
    exit;
}
<?php
require_once 'config.php';

header('Content-Type: application/json');

const POSITION_LIMIT = 4;

function normalizedPosition(string $position): string {
    $value = strtolower(trim($position));
    if (strpos($value, 'barista') !== false) return 'barista';
    if (strpos($value, 'cashier') !== false) return 'cashier';
    return $value;
}

$positions = ['barista' => 'Barista', 'cashier' => 'Cashier'];
$availability = [];

foreach ($positions as $key => $label) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE status = 'approved' AND LOWER(position) LIKE ?");
    $stmt->execute(['%' . $key . '%']);
    $active = (int)$stmt->fetchColumn();
    $availability[$key] = [
        'label' => $label,
        'active' => $active,
        'limit' => POSITION_LIMIT,
        'available' => $active < POSITION_LIMIT,
        'remaining' => max(0, POSITION_LIMIT - $active),
    ];
}

echo json_encode(['success' => true, 'positions' => $availability]);

<?php
declare(strict_types=1);

/*
 * Backend API pre GitHub Pages.
 * Nastav prihlasovacie údaje v config.php (nie v GitHub Pages).
 * Tento endpoint iba kontroluje, či claim_uid existuje. Nemení databázu.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'message' => 'API nie je nakonfigurované.']);
    exit;
}
$config = require $configFile;

// Povoliť iba presnú doménu tvojej GitHub Pages stránky.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = $config['allowed_origin'] ?? '';
if ($origin !== '' && hash_equals($allowedOrigin, $origin)) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Použi POST požiadavku.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$code = is_array($body) ? trim((string)($body['code'] ?? '')) : '';
if ($code === '' || strlen($code) > 128) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'Zadaj platný kód.']);
    exit;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int)$config['db_port'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $stmt = $pdo->prepare(
        'SELECT 1 FROM `advent_calendar_claims` WHERE `claim_uid` = :code LIMIT 1'
    );
    $stmt->execute(['code' => $code]);
    $exists = (bool)$stmt->fetchColumn();

    echo json_encode([
        'valid' => $exists,
        'message' => $exists ? 'Kód je evidovaný.' : 'Kód sa v databáze nenašiel.'
    ]);
} catch (Throwable $e) {
    error_log('MTA code verification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['valid' => false, 'message' => 'Interná chyba overovacieho servera.']);
}

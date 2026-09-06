<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

$partnerName = trim((string)($_GET['partner_name'] ?? ''));
$partnerId = trim((string)($_GET['partner_id'] ?? ''));
$transactionDate = trim((string)($_GET['transaction_date'] ?? ''));
$currency = strtoupper(trim((string)($_GET['currency'] ?? '')));
$transactionTypes = array_values(array_filter(array_map(
    static fn(string $value): string => strtoupper(trim($value)),
    explode(',', (string)($_GET['transaction_type'] ?? ''))
)));

if (($partnerName === '' && $partnerId === '') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Corporate Partner and a valid Transaction Date are required.']);
    exit;
}

try {
    $pdo = fileRecDbConnection();
    $where = [];
    $params = [];

    if ($partnerName !== '' && $partnerId !== '') {
        $where[] = '(TRIM(partner_name) = :partner_name OR TRIM(partner_id) = :partner_id)';
        $params[':partner_name'] = $partnerName;
        $params[':partner_id'] = $partnerId;
    } elseif ($partnerName !== '') {
        $where[] = 'TRIM(partner_name) = :partner_name';
        $params[':partner_name'] = $partnerName;
    } else {
        $where[] = 'TRIM(partner_id) = :partner_id';
        $params[':partner_id'] = $partnerId;
    }

    $where[] = '(DATE(tran_date) = :tran_date OR DATE(settled_date) = :settled_date)';
    $params[':tran_date'] = $transactionDate;
    $params[':settled_date'] = $transactionDate;
    $where[] = "transaction_currency IS NOT NULL AND TRIM(transaction_currency) <> ''";

    if ($currency !== '' && $currency !== 'ALL') {
        $where[] = 'UPPER(TRIM(transaction_currency)) = :currency';
        $params[':currency'] = $currency;
    }

    if ($transactionTypes !== []) {
        $typePlaceholders = [];
        foreach ($transactionTypes as $index => $transactionType) {
            $placeholder = ':tran_type_' . $index;
            $typePlaceholders[] = $placeholder;
            $params[$placeholder] = $transactionType;
        }
        $where[] = 'UPPER(TRIM(tran_type)) IN (' . implode(', ', $typePlaceholders) . ')';
    }

    $sql = "SELECT
                id,
                partner_id,
                partner_name,
                CASE
                    WHEN settled_date IS NOT NULL
                         AND TRIM(CAST(settled_date AS CHAR)) <> ''
                         AND TRIM(CAST(settled_date AS CHAR)) NOT IN ('0000-00-00', '0000-00-00 00:00:00')
                    THEN settled_date
                    ELSE tran_date
                END AS transaction_date,
                tran_date,
                settled_date,
                reference_id,
                base_tran_amt AS amount,
                comm_tran_amt AS commission,
                transaction_currency AS currency,
                tran_type AS transaction_type
            FROM partner_settlement_data
            WHERE " . implode(' AND ', $where) . "
            ORDER BY transaction_date ASC, reference_id ASC, id ASC";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    echo json_encode([
        'success' => true,
        'rows' => $statement->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load settlement data.']);
}

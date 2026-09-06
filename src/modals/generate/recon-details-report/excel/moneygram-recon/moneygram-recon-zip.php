<?php

declare(strict_types=1);

define('MONEYGRAM_RECON_EXPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/moneygram-recon-format.php';
require_once __DIR__ . '/../../../../../config/auth.php';
require_once __DIR__ . '/../../../../../config/db.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function moneygram_settlement_report_type(string $transactionType): string
{
    return match (strtoupper(trim($transactionType))) {
        'REC' => 'payout',
        'RRC' => 'payout-cancelled',
        'SEN' => 'sendout',
        'RSN', 'REF' => 'sendout-cancelled',
        default => '',
    };
}

function moneygram_settlement_key(string $date, string $reference): string
{
    return substr(trim($date), 0, 10) . '|' . strtoupper(trim($reference));
}

function moneygram_settlement_rows(string $date, string $partnerName, string $partnerId, string $currency, string $transactionType): array
{
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
    $params[':tran_date'] = $date;
    $params[':settled_date'] = $date;
    $where[] = "transaction_currency IS NOT NULL AND TRIM(transaction_currency) <> ''";
    if ($currency !== 'ALL') {
        $where[] = 'UPPER(TRIM(transaction_currency)) = :currency';
        $params[':currency'] = $currency;
    }
    $types = array_values(array_filter(array_map('trim', explode(',', strtoupper($transactionType)))));
    if ($types !== []) {
        $placeholders = [];
        foreach ($types as $index => $type) {
            $placeholder = ':type_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $type;
        }
        $where[] = 'UPPER(TRIM(tran_type)) IN (' . implode(', ', $placeholders) . ')';
    }

    $sql = "SELECT
                CASE WHEN settled_date IS NOT NULL AND TRIM(CAST(settled_date AS CHAR)) <> ''
                     AND CAST(settled_date AS CHAR) NOT LIKE '0000-00-00%' THEN settled_date ELSE tran_date END AS display_date,
                reference_id, base_tran_amt AS amount, comm_tran_amt AS commission,
                transaction_currency AS currency, tran_type AS transaction_type
            FROM partner_settlement_data
            WHERE " . implode(' AND ', $where) . '
            ORDER BY display_date, reference_id, id';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function moneygram_daily_rows(array $data): array
{
    $rows = [];
    foreach (($data['days'] ?? []) as $day) {
        $fallbackDate = (string)($day['date'] ?? '');
        foreach (($day['rows'] ?? []) as $row) {
            if (!is_array($row) || !moneygram_recon_has_partner($row)) continue;
            $rows[] = [
                'display_date' => substr(moneygram_recon_row_partner_date($row, $fallbackDate), 0, 10),
                'reference_id' => moneygram_recon_partner_ref($row),
                'amount' => (float)($row['partner_principal'] ?? 0),
                'commission' => (float)($row['partner_commission'] ?? 0),
                'currency' => moneygram_recon_currency_bucket(moneygram_recon_partner_currency($row)),
                'transaction_type' => (string)($row['partner_tran_type'] ?? $row['partner_transaction_type'] ?? ''),
            ];
        }
    }
    return $rows;
}

function moneygram_settlement_pairs(array $settlements, array $dailyRows): array
{
    $dailyByKey = [];
    foreach ($dailyRows as $index => $row) {
        $dailyByKey[moneygram_settlement_key((string)$row['display_date'], (string)$row['reference_id'])][] = $index;
    }
    $used = [];
    $pairs = [];
    foreach ($settlements as $settlement) {
        $key = moneygram_settlement_key((string)$settlement['display_date'], (string)$settlement['reference_id']);
        $dailyIndex = null;
        foreach (($dailyByKey[$key] ?? []) as $candidate) {
            if (!isset($used[$candidate])) { $dailyIndex = $candidate; break; }
        }
        if ($dailyIndex !== null) $used[$dailyIndex] = true;
        $pairs[] = ['settlement' => $settlement, 'daily' => $dailyIndex !== null ? $dailyRows[$dailyIndex] : null, 'status' => 'paid'];
    }
    foreach ($dailyRows as $index => $dailyRow) {
        if (!isset($used[$index])) $pairs[] = ['settlement' => null, 'daily' => $dailyRow, 'status' => 'not-paid'];
    }
    return $pairs;
}

function moneygram_settlement_build_workbook(array $pairs, string $currency, string $statusFilter, string $activeReportType, string $date, string $generatedBy): array
{
    $spreadsheet = new Spreadsheet();
    $reportTypes = ['payout', 'payout-cancelled', 'sendout', 'sendout-cancelled'];
    $activeIndex = 0;
    foreach ($reportTypes as $index => $reportType) {
        $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle(moneygram_recon_report_type_label($reportType));
        $sheet->setCellValue('A1', 'MLHUILLIER PHILIPPINES');
        $sheet->setCellValue('A2', 'CORPORATE DEPARTMENT');
        $sheet->setCellValue('A3', 'SETTLEMENT VS DAILY REPORT');
        $sheet->setCellValue('A4', ($currency === 'ALL' ? 'ALL CURRENCY' : $currency) . ' Transactions');
        $sheet->setCellValue('A6', 'Bank Partner:');
        $sheet->setCellValue('B6', 'MONEYGRAM');
        $sheet->setCellValue('C6', 'Report Date:');
        $sheet->setCellValue('D6', moneygram_recon_date_label($date));
        $sheet->setCellValue('A7', 'Generated Date:');
        $sheet->setCellValue('B7', date('F d, Y h:i:s A'));
        $sheet->setCellValue('A8', 'Generated By:');
        $sheet->setCellValue('B8', $generatedBy);
        $sheet->mergeCells('A10:F10');
        $sheet->mergeCells('G10:L10');
        $sheet->mergeCells('M10:M11');
        $sheet->mergeCells('N10:N11');
        $sheet->fromArray(['SETTLEMENT DATA', '', '', '', '', '', 'DAILY DATA', '', '', '', '', '', 'STATUS', 'REMARKS'], null, 'A10');
        $sheet->fromArray(['DATE', 'REFERENCE ID', 'AMOUNT', 'COMMISSION', 'CURRENCY', 'TRANSACTION TYPE', 'DATE', 'REFERENCE ID', 'AMOUNT', 'COMMISSION', 'CURRENCY', 'TRANSACTION TYPE'], null, 'A11');
        $sheet->getStyle('A10:N11')->getFont()->setBold(true);
        $sheet->getStyle('A10:N11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A10:N11')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A12');
        $widths = [12, 20, 12, 14, 10, 20, 12, 20, 12, 14, 10, 20, 12, 35];
        foreach ($widths as $column => $width) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 1))->setWidth($width);

        $rowNumber = 12;
        foreach ($pairs as $pair) {
            $settlement = $pair['settlement'];
            $daily = $pair['daily'];
            $type = moneygram_settlement_report_type((string)(($daily['transaction_type'] ?? '') ?: ($settlement['transaction_type'] ?? '')));
            if ($type !== $reportType) continue;
            if ($statusFilter !== 'all' && $pair['status'] !== $statusFilter) continue;
            $rowCurrency = strtoupper((string)(($daily['currency'] ?? '') ?: ($settlement['currency'] ?? '')));
            if ($currency !== 'ALL' && $rowCurrency !== $currency) continue;
            $sheet->fromArray([
                $settlement ? moneygram_recon_date_label(substr((string)$settlement['display_date'], 0, 10)) : '',
                $settlement['reference_id'] ?? '', $settlement ? (float)$settlement['amount'] : null,
                $settlement ? (float)$settlement['commission'] : null, $settlement['currency'] ?? '', $settlement['transaction_type'] ?? '',
                $daily ? moneygram_recon_date_label(substr((string)$daily['display_date'], 0, 10)) : '',
                $daily['reference_id'] ?? '', $daily ? (float)$daily['amount'] : null,
                $daily ? (float)$daily['commission'] : null, $daily['currency'] ?? '', $daily['transaction_type'] ?? '',
                $pair['status'] === 'paid' ? 'Paid' : 'Not Paid', '',
            ], null, 'A' . $rowNumber++);
        }
        $lastRow = max(12, $rowNumber - 1);
        $sheet->getStyle("A10:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("C12:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("I12:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("M12:M{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if ($reportType === $activeReportType) $activeIndex = $index;
    }
    $spreadsheet->setActiveSheetIndex($activeIndex);
    return ['spreadsheet' => $spreadsheet, 'filename' => 'MONEYGRAM-SETTLEMENT-VS-DAILY-' . $date . '.xlsx'];
}

try {
    if (!isAuthenticated()) throw new RuntimeException('Your session has expired. Please log in again.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP support is not available on this server.');
    $startDate = moneygram_recon_input_date('start_date');
    $endDate = moneygram_recon_input_date('end_date');
    $filter = moneygram_recon_input_filter();
    $currency = moneygram_recon_input_currency();
    $reportType = moneygram_recon_input_report_type();
    $settlementReportType = strtolower(trim((string)($_GET['settlement_report_type'] ?? 'payout')));
    if (!in_array($settlementReportType, ['payout', 'payout-cancelled', 'sendout', 'sendout-cancelled'], true)) $settlementReportType = 'payout';
    $settlementFilter = strtolower(trim((string)($_GET['settlement_filter'] ?? 'all')));
    if (!in_array($settlementFilter, ['all', 'paid', 'not-paid'], true)) $settlementFilter = 'all';
    $partnerName = trim((string)($_GET['partnerName'] ?? 'MONEYGRAM')) ?: 'MONEYGRAM';
    $partnerId = trim((string)($_GET['partner_id'] ?? ''));
    $transactionType = strtoupper(trim((string)($_GET['transaction_type'] ?? '')));

    $dailyExport = moneygram_recon_build_workbook($startDate, $endDate, $partnerName, $filter, $currency, $reportType);
    $data = moneygram_recon_fetch_data($startDate, $endDate, $partnerName);
    $settlements = moneygram_settlement_rows($startDate, $partnerName, $partnerId, $currency, $transactionType === 'ALL' ? '' : $transactionType);
    $pairs = moneygram_settlement_pairs($settlements, moneygram_daily_rows($data));
    $settlementExport = moneygram_settlement_build_workbook($pairs, $currency, $settlementFilter, $settlementReportType, $startDate, moneygram_recon_generated_by());

    $dailyPath = tempnam(sys_get_temp_dir(), 'daily-kpx-');
    $settlementPath = tempnam(sys_get_temp_dir(), 'settlement-daily-');
    $zipPath = tempnam(sys_get_temp_dir(), 'recon-reports-');
    if ($dailyPath === false || $settlementPath === false || $zipPath === false) throw new RuntimeException('Unable to create temporary export files.');
    (new Xlsx($dailyExport['spreadsheet']))->save($dailyPath);
    (new Xlsx($settlementExport['spreadsheet']))->save($settlementPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create ZIP export.');
    $zip->addFile($dailyPath, $dailyExport['filename']);
    $zip->addFile($settlementPath, $settlementExport['filename']);
    $zip->close();

    $zipFilename = 'MONEYGRAM-RECON-REPORTS-' . $startDate . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    readfile($zipPath);
    unlink($dailyPath);
    unlink($settlementPath);
    unlink($zipPath);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}

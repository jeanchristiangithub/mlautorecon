<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($payload['month'] ?? ''))
        ? (string) $payload['month']
        : date('Y-m');

    $spreadsheet = new Spreadsheet();
    $sheetNames = ['VISMIN EDI' => 'VISMIN', 'LNCR EDI' => 'LNCR'];
    $sheetIndex = 0;

    foreach ($sheetNames as $sheetName => $mainzone) {
        $sheet = $sheetIndex === 0
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();
        $sheet->setTitle($sheetName);

        $topHeaders = [
            'A' => 'BRANCH ID', 'B' => 'CODE', 'C' => 'BRANCH NAME',
            'D' => 'REGION DESCRIPTION',
            'E' => 'MONEYGRAM PO PHP', 'F' => 'MONEYGRAM PO PHP',
            'G' => 'MONEYGRAM PO PHP', 'H' => 'MONEYGRAM PO PHP',
            'I' => 'MONEYGRAM PO USD', 'J' => 'MONEYGRAM PO USD',
            'K' => 'MONEYGRAM PO USD', 'L' => 'MONEYGRAM PO USD',
            'M' => 'MONEYGRAM SO PHP', 'N' => 'MONEYGRAM SO PHP',
            'O' => 'MONEYGRAM SO PHP', 'P' => 'MONEYGRAM SO PHP',
            'Q' => 'MONEYGRAM SO USD', 'R' => 'MONEYGRAM SO USD',
            'S' => 'MONEYGRAM SO USD', 'T' => 'MONEYGRAM SO USD',
            'U' => 'BRANCH STATUS',
        ];
        foreach ($topHeaders as $column => $label) {
            $sheet->setCellValue($column . '1', $label);
        }
        $subHeaders = ['COUNT', 'PRINCIPAL', 'CHARGE', 'FX SHARE'];
        foreach (range(5, 20) as $columnIndex) {
            $sheet->setCellValue([$columnIndex, 2], $subHeaders[($columnIndex - 5) % 4]);
        }

        $rowNumber = 3;
        foreach ($rows as $record) {
            if (strtoupper(trim((string) ($record['mainzone'] ?? ''))) !== $mainzone) continue;
            $php = is_array($record['metrics']['PHP'] ?? null) ? $record['metrics']['PHP'] : [];
            $usd = is_array($record['metrics']['USD'] ?? null) ? $record['metrics']['USD'] : [];
            $values = [
                (string) ($record['branch_id'] ?? ''), (string) ($record['code'] ?? ''),
                (string) ($record['branch_name'] ?? ''),
                (string) ($record['region_description'] ?? ''),
                $php['payout_count'] ?? 0, $php['payout_principal'] ?? 0,
                $php['payout_charge'] ?? 0, $php['payout_fx_share'] ?? 0,
                $usd['payout_count'] ?? 0, $usd['payout_principal'] ?? 0,
                $usd['payout_charge'] ?? 0, $usd['payout_fx_share'] ?? 0,
                $php['sendout_count'] ?? 0, $php['sendout_principal'] ?? 0,
                $php['sendout_charge'] ?? 0, $php['sendout_fx_share'] ?? 0,
                $usd['sendout_count'] ?? 0, $usd['sendout_principal'] ?? 0,
                $usd['sendout_charge'] ?? 0, $usd['sendout_fx_share'] ?? 0,
                (string) ($record['ml_matic_status'] ?? ''),
            ];
            foreach ($values as $offset => $value) {
                $columnIndex = $offset + 1;
                if ($columnIndex <= 4 || $columnIndex === 21) {
                    $sheet->setCellValueExplicit([$columnIndex, $rowNumber], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$columnIndex, $rowNumber], (float) $value);
                }
            }
            $rowNumber++;
        }

        $dataLastRow = $rowNumber - 1;
        $lastRow = max(3, $dataLastRow);
        $totalRow = $dataLastRow >= 3 ? $dataLastRow + 2 : 3;
        $sheet->setCellValue('D' . $totalRow, 'TOTAL');
        foreach (range('E', 'T') as $column) {
            $sheet->setCellValue(
                $column . $totalRow,
                $dataLastRow >= 3 ? '=SUM(' . $column . '3:' . $column . $dataLastRow . ')' : 0
            );
        }
        $sheet->getStyle('A1:U2')->getFont()->setBold(true);
        $sheet->getStyle('A1:U2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:U' . $lastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('D' . $totalRow . ':T' . $totalRow)->getFont()->setBold(true);
        $sheet->getStyle('D' . $totalRow . ':T' . $totalRow)->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('E3:T' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
        foreach (['E', 'I', 'M', 'Q'] as $countColumn) {
            $sheet->getStyle($countColumn . '3:' . $countColumn . $totalRow)->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->freezePane('A3');
        foreach (range('A', 'U') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheetIndex++;
    }

    $spreadsheet->setActiveSheetIndex(0);
    $filename = 'EDI_Report_' . str_replace('-', '_', $month) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unable to export the EDI report.']);
}

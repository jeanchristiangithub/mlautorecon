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
    $webSummary = is_array($payload['web_summary'] ?? null) ? $payload['web_summary'] : [];
    $partner = trim((string) ($payload['partner'] ?? ''));
    $timeFrame = trim((string) ($payload['time_frame'] ?? ''));
    $date = trim((string) ($payload['date'] ?? ''));
    $startDate = trim((string) ($payload['start_date'] ?? ''));
    $endDate = trim((string) ($payload['end_date'] ?? ''));
    $month = trim((string) ($payload['month'] ?? ''));
    $validDate = static function (string $value): bool {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    };
    if ($timeFrame === 'Daily' && $validDate($date)) {
        $periodLabel = $date;
    } elseif (
        $timeFrame === 'Date Range'
        && $validDate($startDate)
        && $validDate($endDate)
        && $startDate <= $endDate
    ) {
        $periodLabel = $startDate . '_to_' . $endDate;
    } elseif ($timeFrame === 'Monthly' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $periodLabel = $month;
    } else {
        throw new InvalidArgumentException('A valid Time Frame period is required.');
    }

    $spreadsheet = new Spreadsheet();
    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('VOLUME SUMMARY');

    $summarySheet->setCellValue('A1', 'CORPORATE PARTNER');
    $summarySheet->setCellValue('B1', 'WEB REPORT');
    $summarySheet->setCellValue('F1', 'EDI');
    $summarySheet->setCellValue('N1', 'ADDITIONAL');
    $summarySheet->setCellValue('T1', 'VARIANCE');
    $summarySheet->mergeCells('A1:A3');
    $summarySheet->mergeCells('B1:E1');
    $summarySheet->mergeCells('F1:M1');
    $summarySheet->mergeCells('N1:S1');
    $summarySheet->mergeCells('T1:W1');
    $summarySheet->setCellValue('F2', 'VISMIN');
    $summarySheet->setCellValue('J2', 'LNCR');
    $summarySheet->setCellValue('N2', 'VISMIN');
    $summarySheet->setCellValue('Q2', 'LNCR');
    $summarySheet->mergeCells('F2:I2');
    $summarySheet->mergeCells('J2:M2');
    $summarySheet->mergeCells('N2:P2');
    $summarySheet->mergeCells('Q2:S2');
    foreach (['B', 'C', 'D', 'E', 'T', 'U', 'V', 'W'] as $column) {
        $summarySheet->mergeCells($column . '2:' . $column . '3');
    }
    foreach (['B', 'F', 'J', 'T'] as $startColumn) {
        $startIndex = PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($startColumn);
        foreach (['VOLUME', 'PRINCIPAL', 'CHARGE', 'FX SHARE'] as $offset => $heading) {
            $summarySheet->setCellValue([$startIndex + $offset, $startColumn === 'B' || $startColumn === 'T' ? 2 : 3], $heading);
        }
    }
    foreach (['N', 'Q'] as $startColumn) {
        $startIndex = PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($startColumn);
        foreach (['VOLUME', 'PRINCIPAL', 'CHARGE'] as $offset => $heading) {
            $summarySheet->setCellValue([$startIndex + $offset, 3], $heading);
        }
    }

    $ediSummary = [];
    foreach (['VISMIN', 'LNCR'] as $mainzone) {
        foreach (['payout', 'sendout'] as $flow) {
            foreach (['PHP', 'USD'] as $currency) {
                $ediSummary[$mainzone][$flow][$currency] = [0.0, 0.0, 0.0, 0.0];
            }
        }
    }
    foreach ($rows as $record) {
        $mainzone = strtoupper(trim((string) ($record['mainzone'] ?? '')));
        if (!isset($ediSummary[$mainzone])) continue;
        foreach (['payout', 'sendout'] as $flow) {
            foreach (['PHP', 'USD'] as $currency) {
                $metrics = is_array($record['metrics'][$currency] ?? null) ? $record['metrics'][$currency] : [];
                foreach (['count', 'principal', 'charge', 'fx_share'] as $index => $metric) {
                    $ediSummary[$mainzone][$flow][$currency][$index] += (float) ($metrics[$flow . '_' . $metric] ?? 0);
                }
            }
        }
    }

    $summaryDefinitions = [
        ['MONEYGRAM PAYOUT - PHP', 'payout', 'PHP'],
        ['MONEYGRAM PAYOUT - USD', 'payout', 'USD'],
        ['MONEYGRAM SENDOUT - PHP', 'sendout', 'PHP'],
        ['MONEYGRAM SENDOUT - USD', 'sendout', 'USD'],
    ];
    foreach ($summaryDefinitions as $offset => [$label, $flow, $currency]) {
        $rowNumber = 4 + $offset;
        $web = is_array($webSummary[$flow][$currency] ?? null) ? $webSummary[$flow][$currency] : [];
        $webValues = [
            (float) ($web['volume'] ?? 0), (float) ($web['principal'] ?? 0),
            (float) ($web['charge'] ?? 0), (float) ($web['fx_share'] ?? 0),
        ];
        $vismin = $ediSummary['VISMIN'][$flow][$currency];
        $lncr = $ediSummary['LNCR'][$flow][$currency];
        $hasEdiData = count(array_filter(
            array_merge($vismin, $lncr),
            static fn(float $value): bool => $value != 0.0
        )) > 0;
        if (!$hasEdiData) {
            $webValues = [0.0, 0.0, 0.0, 0.0];
        }
        $variance = array_map(
            static fn(float $value, int $index): float => $value - $vismin[$index] - $lncr[$index],
            $webValues,
            array_keys($webValues)
        );
        $summarySheet->setCellValue('A' . $rowNumber, $label);
        foreach ([2 => $webValues, 6 => $vismin, 10 => $lncr, 20 => $variance] as $startColumn => $values) {
            foreach ($values as $valueOffset => $value) {
                if (!$hasEdiData && in_array($startColumn, [2, 20], true)) {
                    $summarySheet->setCellValue([$startColumn + $valueOffset, $rowNumber], null);
                } else {
                    $summarySheet->setCellValue([$startColumn + $valueOffset, $rowNumber], $value);
                }
            }
        }
    }
    $summaryTotalRow = 9;
    $summarySheet->setCellValue('A' . $summaryTotalRow, 'GRAND TOTAL:');
    foreach (array_merge(range('B', 'M'), range('T', 'W')) as $column) {
        $summarySheet->setCellValue($column . $summaryTotalRow, '=SUM(' . $column . '4:' . $column . '7)');
    }
    $summarySheet->getStyle('A1:W3')->getFont()->setBold(true);
    $summarySheet->getStyle('A1:W3')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $summarySheet->getStyle('A1:W7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $summarySheet->getStyle('A' . $summaryTotalRow . ':W' . $summaryTotalRow)->getFont()->setBold(true);
    $summarySheet->getStyle('A' . $summaryTotalRow . ':W' . $summaryTotalRow)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
    $summarySheet->getStyle('B4:W' . $summaryTotalRow)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
    foreach (['B', 'F', 'J', 'N', 'Q', 'T'] as $countColumn) {
        $summarySheet->getStyle($countColumn . '4:' . $countColumn . $summaryTotalRow)->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0');
    }
    $summarySheet->freezePane('B4');
    foreach (range('A', 'W') as $column) {
        $summarySheet->getColumnDimension($column)->setAutoSize(true);
    }

    $sheetNames = ['VISMIN EDI' => 'VISMIN', 'LNCR EDI' => 'LNCR'];

    foreach ($sheetNames as $sheetName => $mainzone) {
        $sheet = $spreadsheet->createSheet();
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
    }

    $spreadsheet->setActiveSheetIndex(0);
    $partnerLabel = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $partner), '_'));
    if ($partnerLabel === '') {
        $partnerLabel = 'PARTNER';
    }
    $filename = 'EDI_Report_' . $partnerLabel . '_'
        . str_replace('-', '_', $periodLabel) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unable to export the EDI report.']);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $status = trim((string) ($_GET['status'] ?? ''));
    $mainzone = trim((string) ($_GET['mainzone'] ?? ''));
    $zone = trim((string) ($_GET['zone'] ?? ''));
    $regionCode = trim((string) ($_GET['region'] ?? ''));
    $branchId = trim((string) ($_GET['branch_id'] ?? ''));
    $timeFrame = trim((string) ($_GET['time_frame'] ?? 'Monthly'));
    $date = trim((string) ($_GET['date'] ?? ''));
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));
    $month = trim((string) ($_GET['month'] ?? ''));

    $validDate = static function (string $value): bool {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    };
    if ($timeFrame === 'Daily') {
        if (!$validDate($date)) throw new InvalidArgumentException('A valid Date is required.');
        $periodStart = $date;
        $periodEnd = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    } elseif ($timeFrame === 'Date Range') {
        if (!$validDate($startDate) || !$validDate($endDate)) {
            throw new InvalidArgumentException('A valid Start Date and End Date are required.');
        }
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('Start Date must not be later than End Date.');
        }
        $periodStart = $startDate;
        $periodEnd = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
    } elseif ($timeFrame === 'Monthly') {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('A valid Month is required.');
        }
        $periodStart = $month . '-01';
        $periodEnd = (new DateTimeImmutable($periodStart))->modify('first day of next month')->format('Y-m-d');
    } else {
        throw new InvalidArgumentException('A valid Time Frame is required.');
    }
    $snapshotMonthStart = (new DateTimeImmutable($periodEnd))
        ->modify('-1 day')
        ->modify('first day of this month')
        ->format('Y-m-d');
    $snapshotMonthEnd = (new DateTimeImmutable($snapshotMonthStart))
        ->modify('first day of next month')
        ->format('Y-m-d');

    $sql = "WITH moneygram_source_data AS (
        SELECT mpd.agent_name, mpd.tran_date, mpd.tran_type,
               mpd.settlement_currency, mpd.transaction_currency, mpd.reference_id,
               mpd.base_amt, mpd.comm_amt, mpd.fx_rev_share_amt
        FROM filerecondb.moneygram_partner_data mpd
        WHERE mpd.tran_date >= ? AND mpd.tran_date < ?

        UNION ALL

        SELECT psd.agent_name, psd.tran_date, psd.tran_type,
               psd.settlement_currency, psd.transaction_currency, psd.reference_id,
               psd.base_tran_amt AS base_amt,
               psd.comm_tran_amt AS comm_amt,
               psd.fx_rev_share_tran_amt AS fx_rev_share_amt
        FROM filerecondb.partner_settlement_data psd
        WHERE UPPER(TRIM(COALESCE(psd.partner_name, ''))) = 'MONEYGRAM'
          AND psd.tran_date >= ? AND psd.tran_date < ?
          AND NOT EXISTS (
              SELECT 1
              FROM filerecondb.moneygram_partner_data existing
              WHERE existing.reference_id COLLATE utf8mb4_0900_ai_ci
                    = psd.reference_id COLLATE utf8mb4_0900_ai_ci
                AND existing.tran_date = psd.tran_date
                AND UPPER(TRIM(existing.tran_type)) = UPPER(TRIM(psd.tran_type))
                AND UPPER(TRIM(existing.settlement_currency)) = UPPER(TRIM(psd.settlement_currency))
                AND UPPER(TRIM(existing.transaction_currency)) = UPPER(TRIM(psd.transaction_currency))
          )
    ), moneygram_web_branch_data AS (
        SELECT web.ccref_no COLLATE utf8mb4_0900_ai_ci AS ccref_no,
               web.effective_date,
               CASE WHEN COUNT(DISTINCT NULLIF(TRIM(web.branch_id), '')) = 1
                    THEN MIN(NULLIF(TRIM(web.branch_id), '')) END AS branch_id
        FROM (
            SELECT ccref_no, branch_id,
                   DATE(CASE
                       WHEN date_cancelled IS NOT NULL AND TRIM(CAST(date_cancelled AS CHAR)) <> ''
                           THEN date_cancelled
                       WHEN date_claimed IS NOT NULL AND TRIM(CAST(date_claimed AS CHAR)) <> ''
                           THEN date_claimed
                       WHEN date_send IS NOT NULL AND TRIM(CAST(date_send AS CHAR)) <> ''
                           THEN date_send
                       ELSE NULL
                   END) AS effective_date
            FROM filerecondb.ml_web_data
            WHERE UPPER(TRIM(COALESCE(partnerName, ''))) = 'MONEYGRAM'
        ) web
        WHERE web.effective_date >= ? AND web.effective_date < ?
        GROUP BY web.ccref_no COLLATE utf8mb4_0900_ai_ci, web.effective_date
    ), moneygram_daily_data AS (
        SELECT mlwd.branch_id, mpd.agent_name, mpd.tran_date, mpd.tran_type,
               mpd.settlement_currency, mpd.transaction_currency, mpd.reference_id,
               mpd.base_amt, mpd.comm_amt, mpd.fx_rev_share_amt
        FROM moneygram_source_data mpd
        LEFT JOIN moneygram_web_branch_data mlwd
            ON mlwd.ccref_no = mpd.reference_id COLLATE utf8mb4_0900_ai_ci
           AND mlwd.effective_date = mpd.tran_date
        WHERE mpd.tran_date >= ? AND mpd.tran_date < ?
    ), windowed_moneygram_data AS (
        SELECT mg.*,
               MIN(NULLIF(TRIM(mg.branch_id), '')) OVER (
                   PARTITION BY TRIM(mg.agent_name) COLLATE utf8mb4_0900_ai_ci
               ) AS minimum_agent_branch_id,
               MAX(NULLIF(TRIM(mg.branch_id), '')) OVER (
                   PARTITION BY TRIM(mg.agent_name) COLLATE utf8mb4_0900_ai_ci
               ) AS maximum_agent_branch_id
        FROM moneygram_daily_data mg
    ), resolved_moneygram_data AS (
        SELECT COALESCE(
                   NULLIF(TRIM(mg.branch_id), ''),
                   CASE WHEN mg.minimum_agent_branch_id = mg.maximum_agent_branch_id
                        THEN mg.minimum_agent_branch_id END
               ) AS branch_id,
               mg.agent_name, mg.tran_date, mg.tran_type,
               mg.settlement_currency, mg.transaction_currency, mg.reference_id,
               mg.base_amt, mg.comm_amt, mg.fx_rev_share_amt
        FROM windowed_moneygram_data mg
    ), branch_cte AS (
        SELECT mbp_branch_id, mbp_code,
               COALESCE(
                   NULLIF(TRIM(mbp_mlmatic_branch_name), ''),
                   NULLIF(TRIM(mkpxbm_branch_name), ''),
                   NULLIF(TRIM(mbp_branch_name_description), ''),
                   ''
               ) AS branch_name,
               COALESCE(
                   NULLIF(TRIM(mbp_gl_region), ''),
                   NULLIF(TRIM(mrm_region_description), ''),
                   ''
               ) AS region_description,
               mbp_mlmatic_status, mbp_mainzone, mbp_zone, mbp_region_code,
               mbp_mlmatic_region
        FROM filerecondb.corporate_branch_status_history
        WHERE posted_date = (
            SELECT MAX(snapshot.posted_date)
            FROM filerecondb.corporate_branch_status_history snapshot
            WHERE snapshot.posted_date >= ?
              AND snapshot.posted_date < ?
        )
    ), report_data AS (
        SELECT h.mbp_branch_id AS branch_id, h.mbp_code AS code, h.branch_name,
               h.mbp_mlmatic_status AS ml_matic_status,
               h.mbp_mainzone AS mainzone, h.region_description,
               h.mbp_mlmatic_region AS ml_matic_region,
               mg.tran_date, mg.tran_type, mg.settlement_currency,
               mg.transaction_currency, mg.reference_id,
               mg.base_amt, mg.comm_amt, mg.fx_rev_share_amt
        FROM branch_cte h
        LEFT JOIN resolved_moneygram_data mg
            ON h.mbp_branch_id COLLATE utf8mb4_0900_ai_ci
             = mg.branch_id COLLATE utf8mb4_0900_ai_ci
        WHERE 1 = 1";
    $parameters = [
        $periodStart,
        $periodEnd,
        $periodStart,
        $periodEnd,
        $periodStart,
        $periodEnd,
        $periodStart,
        $periodEnd,
        $snapshotMonthStart,
        $snapshotMonthEnd,
    ];
    $mainzoneColumn = 'h.mbp_mainzone';
    $zoneColumn = 'h.mbp_zone';
    $regionColumn = 'h.mbp_region_code';

    if ($status !== '') {
        $sql .= " AND TRIM(UPPER(h.mbp_mlmatic_status)) = TRIM(UPPER(?))";
        $parameters[] = $status;
    }

    if ($mainzone !== '') {
        $sql .= " AND TRIM(UPPER({$mainzoneColumn})) = TRIM(UPPER(?))";
        $parameters[] = $mainzone;
    }

    $isShowroomRegion = $zone === '' && in_array(strtoupper($regionCode), ['LZN', 'NCR', 'VIS', 'MIN'], true);
    if (strcasecmp($zone, 'Showroom') === 0 || $isShowroomRegion) {
        $sql .= " AND UPPER(TRIM(h.branch_name)) LIKE '%SHOWROOM%'";
        if ($regionCode !== '') {
            $sql .= " AND TRIM(UPPER({$zoneColumn})) = TRIM(UPPER(?))";
            $parameters[] = $regionCode;
        }
    } else {
        if ($zone !== '') {
            $sql .= " AND TRIM(UPPER({$zoneColumn})) = TRIM(UPPER(?))";
            $parameters[] = $zone;
        }
        if ($regionCode !== '') {
            $sql .= " AND TRIM(UPPER({$regionColumn})) = TRIM(UPPER(?))";
            $parameters[] = $regionCode;
        }
    }

    if ($branchId !== '') {
        $sql .= ' AND TRIM(h.mbp_branch_id) = TRIM(?)';
        $parameters[] = $branchId;
    }

    $sql .= ")";

    $amountExpression = static function (string $column): string {
        return 'ABS(COALESCE(mpd.`' . $column . '`, 0))';
    };
    $baseAmount = $amountExpression('base_amt');
    $commissionAmount = $amountExpression('comm_amt');
    $fxShareAmount = $amountExpression('fx_rev_share_amt');
    $metricsSql = $sql . " SELECT
            mpd.code, mpd.branch_name, mpd.ml_matic_status, mpd.mainzone,
            mpd.region_description, mpd.ml_matic_region,
            TRIM(mpd.branch_id) AS branch_id,
            mpd.settlement_currency AS currency,
            SUM(CASE WHEN mpd.tran_type = 'REC' THEN 1
                     WHEN mpd.tran_type = 'RRC' THEN -1 ELSE 0 END) AS payout_count,
            SUM(CASE WHEN mpd.tran_type = 'REC' THEN {$baseAmount}
                     WHEN mpd.tran_type = 'RRC' THEN -{$baseAmount} ELSE 0 END) AS payout_principal,
            SUM(CASE WHEN mpd.tran_type = 'REC' THEN {$commissionAmount}
                     WHEN mpd.tran_type = 'RRC' THEN -{$commissionAmount} ELSE 0 END) AS payout_charge,
            SUM(CASE WHEN mpd.tran_type = 'REC' THEN {$fxShareAmount}
                     WHEN mpd.tran_type = 'RRC' THEN -{$fxShareAmount} ELSE 0 END) AS payout_fx_share,
            SUM(CASE WHEN mpd.tran_type = 'SEN' THEN 1
                     WHEN mpd.tran_type IN ('RSN', 'REF') THEN -1 ELSE 0 END) AS sendout_count,
            SUM(CASE WHEN mpd.tran_type = 'SEN' THEN {$baseAmount}
                     WHEN mpd.tran_type IN ('RSN', 'REF') THEN -{$baseAmount} ELSE 0 END) AS sendout_principal,
            SUM(CASE WHEN mpd.tran_type = 'SEN' THEN {$commissionAmount}
                     WHEN mpd.tran_type IN ('RSN', 'REF') THEN -{$commissionAmount} ELSE 0 END) AS sendout_charge,
            SUM(CASE WHEN mpd.tran_type = 'SEN' THEN {$fxShareAmount}
                     WHEN mpd.tran_type IN ('RSN', 'REF') THEN -{$fxShareAmount} ELSE 0 END) AS sendout_fx_share
        FROM report_data mpd
        GROUP BY mpd.code, mpd.branch_name, mpd.ml_matic_status, mpd.mainzone,
                 mpd.region_description, mpd.ml_matic_region,
                 TRIM(mpd.branch_id), mpd.settlement_currency
        ORDER BY mpd.branch_name, TRIM(mpd.branch_id)";
    $statement = fileRecDbConnection()->prepare($metricsSql);
    $statement->execute($parameters);

    // Preserve the branch totals response consumed by the table, summary and export.
    $branches = [];
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $branch = array_intersect_key($row, array_flip([
            'branch_id', 'code', 'branch_name', 'ml_matic_status',
            'mainzone', 'region_description', 'ml_matic_region'
        ]));
        $key = json_encode($branch, JSON_THROW_ON_ERROR);
        if (!isset($branches[$key])) {
            $branches[$key] = $branch + ['metrics' => []];
        }
        $currency = (string) $row['currency'];
        if (in_array($currency, ['PHP', 'USD'], true)) {
            $branches[$key]['metrics'][$currency] = array_diff_key($row, $branch, ['currency' => true]);
        }
    }
    $branchRows = array_values($branches);

    $webSummarySql = "WITH web_report_source AS (
        SELECT mpd.tran_type, mpd.transaction_currency,
               mpd.base_amt, mpd.comm_amt, mpd.fx_rev_share_amt
        FROM filerecondb.moneygram_partner_data mpd
        WHERE mpd.tran_date >= ? AND mpd.tran_date < ?

        UNION ALL

        SELECT psd.tran_type, psd.transaction_currency,
               psd.base_tran_amt AS base_amt,
               psd.comm_tran_amt AS comm_amt,
               psd.fx_rev_share_tran_amt AS fx_rev_share_amt
        FROM filerecondb.partner_settlement_data psd
        WHERE UPPER(TRIM(COALESCE(psd.partner_name, ''))) = 'MONEYGRAM'
          AND (
              (psd.tran_date >= ? AND psd.tran_date < ?)
              OR (psd.settled_date >= ? AND psd.settled_date < ?)
          )
          AND NOT EXISTS (
              SELECT 1
              FROM filerecondb.moneygram_partner_data existing
              WHERE existing.reference_id COLLATE utf8mb4_0900_ai_ci
                    = psd.reference_id COLLATE utf8mb4_0900_ai_ci
                AND (
                    DATE(existing.tran_date) = DATE(psd.tran_date)
                    OR DATE(existing.tran_date) = DATE(psd.settled_date)
                )
          )
    )
    SELECT
        CASE
            WHEN UPPER(TRIM(tran_type)) IN ('REC', 'RRC') THEN 'payout'
            WHEN UPPER(TRIM(tran_type)) IN ('SEN', 'RSN', 'REF') THEN 'sendout'
            ELSE NULL
        END AS flow,
        UPPER(TRIM(transaction_currency)) AS currency,
        SUM(CASE
            WHEN UPPER(TRIM(tran_type)) IN ('REC', 'SEN') THEN 1
            WHEN UPPER(TRIM(tran_type)) IN ('RRC', 'RSN', 'REF') THEN -1
            ELSE 0
        END) AS volume,
        SUM(CASE
            WHEN UPPER(TRIM(tran_type)) IN ('REC', 'SEN') THEN ABS(COALESCE(base_amt, 0))
            WHEN UPPER(TRIM(tran_type)) IN ('RRC', 'RSN', 'REF') THEN -ABS(COALESCE(base_amt, 0))
            ELSE 0
        END) AS principal,
        SUM(CASE
            WHEN UPPER(TRIM(tran_type)) IN ('REC', 'SEN') THEN ABS(COALESCE(comm_amt, 0))
            WHEN UPPER(TRIM(tran_type)) IN ('RRC', 'RSN', 'REF') THEN -ABS(COALESCE(comm_amt, 0))
            ELSE 0
        END) AS charge,
        SUM(CASE
            WHEN UPPER(TRIM(tran_type)) IN ('REC', 'SEN') THEN ABS(COALESCE(fx_rev_share_amt, 0))
            WHEN UPPER(TRIM(tran_type)) IN ('RRC', 'RSN', 'REF') THEN -ABS(COALESCE(fx_rev_share_amt, 0))
            ELSE 0
        END) AS fx_share
    FROM web_report_source
    GROUP BY flow, currency
    HAVING flow IS NOT NULL AND currency IN ('PHP', 'USD')";
    $webSummaryStatement = fileRecDbConnection()->prepare($webSummarySql);
    $webSummaryStatement->execute([
        $periodStart,
        $periodEnd,
        $periodStart,
        $periodEnd,
        $periodStart,
        $periodEnd,
    ]);

    $webSummary = [];
    while ($summaryRow = $webSummaryStatement->fetch(PDO::FETCH_ASSOC)) {
        $webSummary[(string) $summaryRow['flow']][(string) $summaryRow['currency']] = [
            'volume' => (int) $summaryRow['volume'],
            'principal' => (float) $summaryRow['principal'],
            'charge' => (float) $summaryRow['charge'],
            'fx_share' => (float) $summaryRow['fx_share'],
        ];
    }

    echo json_encode([
        'success' => true,
        'rows' => $branchRows,
        'web_summary' => $webSummary,
    ]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to generate the EDI report.']);
}

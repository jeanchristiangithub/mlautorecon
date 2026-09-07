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
    $month = trim((string) ($_GET['month'] ?? ''));

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        throw new InvalidArgumentException('A valid Month is required.');
    }
    $monthStart = $month . '-01';
    $nextMonthStart = (new DateTimeImmutable($monthStart))->modify('first day of next month')->format('Y-m-d');
    // Match the branch-history snapshot day used by the supplied report query.
    $snapshotStart = $month . '-22';
    $snapshotEnd = (new DateTimeImmutable($snapshotStart))->modify('+1 day')->format('Y-m-d');

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
               COALESCE(mbp_branch_name_description, mbp_mlmatic_branch_name,
                        mkpxbm_branch_name) AS branch_name,
               mbp_mlmatic_status, mbp_mainzone, mbp_zone, mbp_region_code,
               mbp_gl_region, mbp_mlmatic_region
        FROM filerecondb.corporate_branch_status_history
        WHERE posted_date >= ? AND posted_date < ?
    ), report_data AS (
        SELECT mg.*, h.mbp_code AS code, h.branch_name,
               h.mbp_mlmatic_status AS ml_matic_status,
               h.mbp_mainzone AS mainzone, h.mbp_gl_region AS region_description,
               h.mbp_mlmatic_region AS ml_matic_region
        FROM resolved_moneygram_data mg
        LEFT JOIN branch_cte h
            ON h.mbp_branch_id COLLATE utf8mb4_0900_ai_ci
             = mg.branch_id COLLATE utf8mb4_0900_ai_ci
        WHERE 1 = 1";
    $parameters = [
        $monthStart,
        $nextMonthStart,
        $monthStart,
        $nextMonthStart,
        $monthStart,
        $nextMonthStart,
        $monthStart,
        $nextMonthStart,
        $snapshotStart,
        $snapshotEnd,
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
        $sql .= ' AND TRIM(mg.branch_id) = TRIM(?)';
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

    echo json_encode(['success' => true, 'rows' => $branchRows]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to generate the EDI report.']);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function branchStatusLogBranchesRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isAuthenticated()) {
    branchStatusLogBranchesRespond(401, [
        'success' => false,
        'error' => 'Your session has expired. Please log in again.',
    ]);
}

$query = trim((string) ($_GET['q'] ?? ''));
$showAll = filter_var($_GET['all'] ?? false, FILTER_VALIDATE_BOOL);
if (mb_strlen($query) > 150) {
    branchStatusLogBranchesRespond(422, [
        'success' => false,
        'error' => 'The search value is too long.',
    ]);
}

try {
    $connection = fileRecDbConnection();
    $sql = "WITH normalized_branches AS (
                SELECT
                    TRIM(mbp_branch_id) AS branch_id,
                    COALESCE(
                        NULLIF(TRIM(mbp_mlmatic_branch_name), ''),
                        NULLIF(TRIM(mkpxbm_branch_name), ''),
                        NULLIF(TRIM(mbp_branch_name_description), ''),
                        ''
                    ) AS branch_name,
                    posted_at
                FROM filerecondb.corporate_branch_status_history
            ), merged_branches AS (
                SELECT branch_id, branch_name, MAX(posted_at) AS posted_at
                FROM normalized_branches
                WHERE branch_id <> ''
                  AND branch_name <> ''
                GROUP BY branch_id, branch_name
            ), ranked_branches AS (
                SELECT
                    branch_id,
                    branch_name,
                    posted_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY branch_id
                        ORDER BY posted_at DESC, branch_name ASC
                    ) AS branch_id_rank,
                    ROW_NUMBER() OVER (
                        PARTITION BY branch_name
                        ORDER BY posted_at DESC, branch_id ASC
                    ) AS branch_name_rank
                FROM merged_branches
            )
            SELECT branch_id, branch_name, posted_at
            FROM ranked_branches
            WHERE branch_id_rank = 1
              AND branch_name_rank = 1";
    $parameters = [];

    if ($query !== '') {
        $sql .= ' AND (branch_id LIKE ? OR branch_name LIKE ?)';
        $searchValue = '%' . $query . '%';
        $parameters = [$searchValue, $searchValue];
    }

    $sql .= ' ORDER BY branch_name ASC, branch_id ASC';
    if (!$showAll) {
        $sql .= ' LIMIT 100';
    }
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);

    branchStatusLogBranchesRespond(200, [
        'success' => true,
        'branches' => $statement->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $exception) {
    branchStatusLogBranchesRespond(500, [
        'success' => false,
        'error' => 'Unable to load the branch search list.',
    ]);
}

<?php
/**
 * CSV Export Endpoint
 * Supports the same view/type/month/from_date/to_date params as gen_statement.php
 * PLUS the filter params: q, ftype, fcat, date_from, date_to
 * All results are exported in chronological order (oldest first).
 */
session_start();
date_default_timezone_set('Asia/Kolkata');
require 'queries.php';

// ── Input validation ────────────────────────────────────────────────────────
$view      = isset($_GET['view'])      ? $_GET['view']           : '';
$id        = isset($_GET['id'])        ? intval($_GET['id'])      : 0;
$type      = isset($_GET['type'])      ? $_GET['type']            : 'filtered'; // full|monthly|custom|filtered
$month     = isset($_GET['month'])     ? trim($_GET['month'])     : '';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date   = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : '';

// Search/filter params (mirror index.php)
$filterQ        = isset($_GET['q'])         ? trim($_GET['q'])         : '';
$filterType     = isset($_GET['ftype'])     ? trim($_GET['ftype'])     : '';
$filterCat      = isset($_GET['fcat'])      ? intval($_GET['fcat'])    : 0;
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

if (!in_array($view, ['bank', 'wallet']) || $id <= 0) {
    http_response_code(400);
    die('Invalid parameters');
}
if (!in_array($filterType, ['income', 'expense', ''])) $filterType = '';
if ($filterDateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) $filterDateFrom = '';
if ($filterDateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo))   $filterDateTo   = '';

$db      = getDB();
$queries = new Queries();

// ── Resolve entity name ─────────────────────────────────────────────────────
if ($view === 'bank') {
    $entity = $queries->getBankById($id);
    if (!$entity) { http_response_code(404); die('Bank not found'); }
    $entityName = $entity['name'];
} else {
    $entity = $queries->getWalletById($id);
    if (!$entity) { http_response_code(404); die('Wallet not found'); }
    $entityName = $entity['name'];
}

// ── Build transaction result set ────────────────────────────────────────────
$result = null;
$period = '';

if ($type === 'monthly' && $month && preg_match('/^\d{4}-\d{2}$/', $month)) {
    list($yr, $mo) = explode('-', $month);
    $period = date('F_Y', strtotime($month . '-01'));
    if ($view === 'bank') {
        $result = $queries->getBankMonthlyTransactions($id, $yr, str_pad((int)$mo, 2, '0', STR_PAD_LEFT));
    } else {
        $result = $queries->getWalletMonthlyTransactions($id, $yr, str_pad((int)$mo, 2, '0', STR_PAD_LEFT));
    }

} elseif ($type === 'custom' && $from_date && $to_date
          && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)
          && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $period = date('Y-m-d', strtotime($from_date)) . '_to_' . date('Y-m-d', strtotime($to_date));
    $col    = $view === 'bank' ? 't.payment_bank_id' : 't.wallet_id';
    $stmt   = $db->prepare(
        "SELECT t.*, w.name as wallet, b.name as payment_method,
                c.name as category_name
         FROM transactions t
         LEFT JOIN wallets w    ON w.id = t.wallet_id
         LEFT JOIN banks b      ON b.id = t.payment_bank_id
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $col = ? AND t.deleted_at IS NULL AND t.date BETWEEN ? AND ?
         ORDER BY t.date ASC, t.id ASC"
    );
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $from_date, SQLITE3_TEXT);
    $stmt->bindValue(3, $to_date, SQLITE3_TEXT);
    $result = $stmt->execute();

} elseif ($type === 'full') {
    $period = 'full';
    $col    = $view === 'bank' ? 't.payment_bank_id' : 't.wallet_id';
    $stmt   = $db->prepare(
        "SELECT t.*, w.name as wallet, b.name as payment_method,
                c.name as category_name
         FROM transactions t
         LEFT JOIN wallets w    ON w.id = t.wallet_id
         LEFT JOIN banks b      ON b.id = t.payment_bank_id
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $col = ? AND t.deleted_at IS NULL
         ORDER BY t.date ASC, t.id ASC"
    );
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $result = $stmt->execute();

} else {
    // 'filtered' mode — honour filter params, no pagination (export all matches)
    $period  = 'filtered';
    $filters = [
        'q'           => $filterQ,
        'type'        => $filterType,
        'category_id' => $filterCat,
        'date_from'   => $filterDateFrom,
        'date_to'     => $filterDateTo,
    ];
    if ($view === 'bank') {
        $result = $queries->searchBankTransactions($id, $filters, 1, 99999);
    } else {
        $result = $queries->searchWalletTransactions($id, $filters, 1, 99999);
    }
}

if (!$result) {
    http_response_code(500);
    die('Query failed');
}

// ── Collect rows & compute running balance ──────────────────────────────────
$rows = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

// Sort chronologically (some queries return DESC)
usort($rows, function($a, $b) {
    return strcmp($a['date'] . $a['id'], $b['date'] . $b['id']);
});

$runningBalance = 0;
foreach ($rows as &$r) {
    if ($r['type'] === 'income') {
        $runningBalance += $r['amount'];
    } else {
        $runningBalance -= $r['amount'];
    }
    $r['_balance'] = $runningBalance;
}
unset($r);

// ── Emit CSV ─────────────────────────────────────────────────────────────────
$safeEntityName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $entityName);
$filename = $view . '_' . $safeEntityName . '_' . $period . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel opens it correctly
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['Date', 'Title', 'Type', 'Amount (Rs.)', 'Category', 'Wallet / Bank', 'Note', 'Running Balance (Rs.)'], ',', '"', '\\');

foreach ($rows as $r) {
    $isTransfer = !empty($r['transfer_pair_id']);
    $typeLabel  = $isTransfer
        ? ($r['type'] === 'expense' ? 'Transfer Out' : 'Transfer In')
        : ucfirst($r['type']);

    $walletOrBank = '';
    if ($view === 'bank') {
        $walletOrBank = $r['wallet'] ?? ($isTransfer ? 'Transfer' : 'Bank Direct');
    } else {
        $walletOrBank = $r['payment_method'] ?? '';
    }

    fputcsv($out, [
        $r['date'],
        $r['title']  ?? '',
        $typeLabel,
        number_format($r['amount'], 2, '.', ''),
        $r['category_name'] ?? 'Uncategorised',
        $walletOrBank,
        $r['note']   ?? '',
        number_format($r['_balance'], 2, '.', ''),
    ], ',', '"', '\\');
}

fclose($out);
exit;

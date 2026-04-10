<?php
session_start();
// Set timezone to India (IST, UTC+5:30)
date_default_timezone_set('Asia/Kolkata');
require 'queries.php';

$type = isset($_GET['type']) ? $_GET['type'] : 'full';
$view = isset($_GET['view']) ? $_GET['view'] : 'bank';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$month = isset($_GET['month']) ? $_GET['month'] : null;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : null;
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : null;

$db = getDB();
$queries = new Queries();
$version = '';

$configFile = dirname(__DIR__) . '/config.yaml';
if(file_exists($configFile)) {
    $configContent = file_get_contents($configFile);
    if(preg_match('/AppVersion:\s*(.+)/', $configContent, $matches)) {
        $version = trim($matches[1]);
    }
}

if (!$id || ($view !== 'bank' && $view !== 'wallet')) {
    die('Invalid parameters');
}

$transactions = null;
$name = '';
$statement_period = '';
$summary = ['income' => 0, 'expense' => 0, 'net' => 0];
$budget_info = null;

if ($view === 'bank') {
    $bank = $queries->getBankById($id);
    if (!$bank) die('Bank not found');
    $name = $bank['name'];

    if ($type === 'full') {
        $statement_period = 'Full Statement';
        $stmt = $db->prepare(
            "SELECT t.*, w.name as wallet FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ? ORDER BY t.date ASC"
        );
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $transactions = $stmt->execute();
        // Set opening balance to 0 for full statements
        $budget_info = ['opening_balance' => 0];
    } elseif ($type === 'monthly' && $month) {
        list($year, $monthOnly) = explode('-', $month);
        $statement_period = date('F Y', strtotime($month . '-01'));
        $stmt = $db->prepare(
            "SELECT t.*, w.name as wallet FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ? AND strftime('%Y-%m', t.date) = ? ORDER BY t.date ASC"
        );
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $month, SQLITE3_TEXT);
        $transactions = $stmt->execute();
        // Calculate opening balance (balance as of end of previous month)
        $prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
        $last_day_prev = date('Y-m-t', strtotime($prev_month . '-01'));
        $stmt_open = $db->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as opening_balance FROM transactions t LEFT JOIN wallets w ON t.wallet_id=w.id WHERE w.bank_id = ? AND t.date <= ?");
        $stmt_open->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt_open->bindValue(2, $last_day_prev, SQLITE3_TEXT);
        $result_open = $stmt_open->execute();
        $opening_row = $result_open->fetchArray(SQLITE3_ASSOC);
        $opening_balance = $opening_row['opening_balance'] ?? 0;
        $budget_info = ['opening_balance' => $opening_balance];
    } elseif ($type === 'custom' && $from_date && $to_date) {
        $statement_period = date('M d, Y', strtotime($from_date)) . ' to ' . date('M d, Y', strtotime($to_date));
        $stmt = $db->prepare(
            "SELECT t.*, w.name as wallet FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ? AND t.date BETWEEN ? AND ? ORDER BY t.date ASC"
        );
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $from_date, SQLITE3_TEXT);
        $stmt->bindValue(3, $to_date, SQLITE3_TEXT);
        $transactions = $stmt->execute();
        // Calculate opening balance (balance before the from_date)
        $stmt_open = $db->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as opening_balance FROM transactions t LEFT JOIN wallets w ON t.wallet_id=w.id WHERE w.bank_id = ? AND t.date < ?");
        $stmt_open->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt_open->bindValue(2, $from_date, SQLITE3_TEXT);
        $result_open = $stmt_open->execute();
        $opening_row = $result_open->fetchArray(SQLITE3_ASSOC);
        $opening_balance = $opening_row['opening_balance'] ?? 0;
        $budget_info = ['opening_balance' => $opening_balance];
    }
} else {
    $wallet = $queries->getWalletById($id);
    if (!$wallet) die('Wallet not found');
    $name = $wallet['name'];

    if ($type === 'full') {
        $statement_period = 'Full Statement';
        $stmt = $db->prepare("SELECT t.* FROM transactions t WHERE t.wallet_id = ? ORDER BY t.date ASC");
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $transactions = $stmt->execute();
        // For balance wallets, set opening balance to 0 for full statements
        if ($wallet['wallet_type'] !== 'budget') {
            $budget_info = ['opening_balance' => 0];
        }
    } elseif ($type === 'monthly' && $month) {
        $statement_period = date('F Y', strtotime($month . '-01'));
        $stmt = $db->prepare("SELECT t.* FROM transactions t WHERE t.wallet_id = ? AND strftime('%Y-%m', t.date) = ? ORDER BY t.date ASC");
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $month, SQLITE3_TEXT);
        $transactions = $stmt->execute();
        // Get budget for this wallet/month if it's a budget-type wallet
        if ($wallet['wallet_type'] === 'budget') {
            list($year, $monthOnly) = explode('-', $month);
            $budget_info = $queries->getBudgetByWalletMonth($id, $year, $monthOnly);
        } else {
            // For balance wallets, calculate opening balance (balance as of end of previous month)
            list($year, $monthOnly) = explode('-', $month);
            $prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
            $last_day_prev = date('Y-m-t', strtotime($prev_month . '-01'));
            $stmt_open = $db->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as opening_balance FROM transactions WHERE wallet_id = ? AND date <= ?");
            $stmt_open->bindValue(1, $id, SQLITE3_INTEGER);
            $stmt_open->bindValue(2, $last_day_prev, SQLITE3_TEXT);
            $result_open = $stmt_open->execute();
            $opening_row = $result_open->fetchArray(SQLITE3_ASSOC);
            $opening_balance = $opening_row['opening_balance'] ?? 0;
            $budget_info = ['opening_balance' => $opening_balance];
        }
    } elseif ($type === 'custom' && $from_date && $to_date) {
        $statement_period =  date('M d, Y', strtotime($from_date)) . ' to ' . date('M d, Y', strtotime($to_date));
        $stmt = $db->prepare("SELECT t.* FROM transactions t WHERE t.wallet_id = ? AND t.date BETWEEN ? AND ? ORDER BY t.date ASC");
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $from_date, SQLITE3_TEXT);
        $stmt->bindValue(3, $to_date, SQLITE3_TEXT);
        $transactions = $stmt->execute();
    }
}

$balance = 0;
// For budget wallets, start balance from budget limit
if ($budget_info && isset($budget_info['expected_expense'])) {
    $balance = $budget_info['expected_expense'];
}
// For balance wallets' monthly statements, start balance from opening balance
if ($budget_info && isset($budget_info['opening_balance'])) {
    $balance = $budget_info['opening_balance'];
}
$tx_data = [];
while ($tx = $transactions->fetchArray(SQLITE3_ASSOC)) {
    if ($tx['type'] === 'income') {
        $summary['income'] += $tx['amount'];
        $balance += $tx['amount'];
    } else {
        $summary['expense'] += $tx['amount'];
        $balance -= $tx['amount'];
    }
    $tx['balance'] = $balance;
    $tx_data[] = $tx;
}
$summary['net'] = $summary['income'] - $summary['expense'];

// For non-budget wallet/bank statements, reverse to show newest first (after balance calculation)
// This applies to full, monthly, and custom statements
if (!($view === 'wallet' && isset($wallet) && $wallet['wallet_type'] === 'budget')) {
    $tx_data = array_reverse($tx_data);
}

// For budget wallet full statements, group transactions by month
$grouped_by_month = [];
if ($view === 'wallet' && $wallet['wallet_type'] === 'budget' && $type === 'full') {
    foreach ($tx_data as $tx) {
        $month_key = substr($tx['date'], 0, 7); // YYYY-MM
        if (!isset($grouped_by_month[$month_key])) {
            $grouped_by_month[$month_key] = [
                'transactions' => [],
                'summary' => ['income' => 0, 'expense' => 0, 'net' => 0]
            ];
        }
        $grouped_by_month[$month_key]['transactions'][] = $tx;

        if ($tx['type'] === 'income') {
            $grouped_by_month[$month_key]['summary']['income'] += $tx['amount'];
        } else {
            $grouped_by_month[$month_key]['summary']['expense'] += $tx['amount'];
        }
    }

    // Recalculate balance for each month (each month starts fresh from budget limit)
    foreach ($grouped_by_month as $month_key => &$month_data) {
        $month_data['summary']['net'] = $month_data['summary']['income'] - $month_data['summary']['expense'];

        // Fetch budget for this month and recalculate balances
        list($year, $monthOnly) = explode('-', $month_key);
        $month_budget = $queries->getBudgetByWalletMonth($id, $year, $monthOnly);

        if ($month_budget && isset($month_budget['expected_expense'])) {
            $month_balance = $month_budget['expected_expense'];
            foreach ($month_data['transactions'] as &$tx) {
                if ($tx['type'] === 'income') {
                    $month_balance += $tx['amount'];
                } else {
                    $month_balance -= $tx['amount'];
                }
                $tx['balance'] = $month_balance;
            }
        }
    }

    // Sort by month descending (newest first)
    krsort($grouped_by_month);
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';

class MYPDF extends TCPDF {
    public $appversion = '';

    public function Header() {
        // Background color - blue header box
        $this->SetFillColor(52, 152, 219);
        $this->Rect(0, 0, 210, 30, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 18);

        // Insert money bag icon image - vertically centered in blue section
        $icon_path = __DIR__ . '/resource/images/icon.jpg';
        if (file_exists($icon_path)) {
            $this->Image($icon_path, 10, 7, 12, 16, 'JPG');  // Centered at Y=7
        }

        // Header text - Main title (Camel Case) - vertically centered
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 20);
        $this->SetXY(25, 9);  // Adjusted for vertical centering
        $this->Cell(0, 10, 'Expense Manager', 0, 0, 'L', false);

        // Version
        $this->SetFont('helvetica', '', 12);
        $this->SetXY(25, 19);  // Adjusted for vertical centering
        $this->Cell(0, 5, 'v' . $this->appversion, 0, 1, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->Ln(6);  // Space after header
    }

    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'Generated: ' . date('M d, Y h:i A'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->getPage() . ' of ' . $this->getAliasNbPages(), 0, 1, 'R');
    }
}

$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->appversion = $version;
$pdf->SetMargins(12, 36, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage('P');

// Title with wallet/bank name - Professional format without icon box
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(41, 57, 86);
$pdf->Cell(0, 7, htmlspecialchars($name), 0, 1, 'L', false);

// Statement period
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 4, 'Statement Period: ' . htmlspecialchars($statement_period), 0, 1);
$pdf->Ln(3);

// Combined Budget & Summary section - skip if we have grouped_by_month (will show monthly summaries instead)
if (empty($grouped_by_month)) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 7, 'FINANCIAL SUMMARY', 1, 1, 'L', true);
}

if ($budget_info && isset($budget_info['expected_expense']) && empty($grouped_by_month)) {
    // Budget-type wallet - show budget info
    $budget = $budget_info['expected_expense'];
    $net_spending = $summary['expense'] - $summary['income'];
    $remaining = $budget - $net_spending;
    $percentage_used = ($net_spending / $budget) * 100;
    $percentage_used = min($percentage_used, 100);

    // Budget Limit row
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 6, 'Budget Limit:', 1, 0, 'L', true);
    $pdf->SetTextColor(52, 152, 219);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Rs. ' . number_format($budget, 2), 1, 1, 'R', true);

    // Income row
    $pdf->SetFillColor(232, 245, 233);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 6, 'Total Income:', 1, 0, 'L', true);
    $pdf->SetTextColor(39, 174, 96);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['income'], 2), 1, 1, 'R', true);

    // Expense row
    $pdf->SetFillColor(253, 235, 235);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 6, 'Total Expense:', 1, 0, 'L', true);
    $pdf->SetTextColor(231, 76, 60);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['expense'], 2), 1, 1, 'R', true);

    // Net Spending row
    $pdf->SetFillColor(236, 240, 241);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 6, 'Net Spending:', 1, 0, 'L', true);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Rs. ' . number_format($net_spending, 2), 1, 1, 'R', true);

    // Divider row
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(70, 3, '', 1, 0, 'L', true);
    $pdf->Cell(0, 3, '', 1, 1, 'R', true);

    // Remaining row
    $remaining_color = $remaining >= 0 ? [232, 245, 233] : [253, 235, 235];
    $pdf->SetFillColor($remaining_color[0], $remaining_color[1], $remaining_color[2]);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 6, 'Remaining:', 1, 0, 'L', true);
    $pdf->SetTextColor($remaining >= 0 ? 39 : 231, $remaining >= 0 ? 174 : 76, $remaining >= 0 ? 96 : 60);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Rs. ' . number_format($remaining, 2), 1, 1, 'R', true);

    // Progress bar row with percentage
    $pdf->SetFont('helvetica', '', 10);
    $cell_height = 8;

    // Left label cell
    $pdf->SetFillColor(236, 240, 241);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, $cell_height, 'Budget Used:', 1, 0, 'L', true);

    // Determine progress bar color
    if ($percentage_used >= 100) {
        $bar_color = [231, 76, 60];  // Red
        $text_color = [231, 76, 60];
    } elseif ($percentage_used >= 80) {
        $bar_color = [241, 196, 15];  // Yellow/Orange
        $text_color = [241, 196, 15];
    } else {
        $bar_color = [39, 174, 96];  // Green
        $text_color = [39, 174, 96];
    }

    // Right cell with colored background to show progress
    $pdf->SetFillColor($bar_color[0], $bar_color[1], $bar_color[2]);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, $cell_height, number_format($percentage_used, 1) . '%', 1, 1, 'C', true);

} else if (empty($grouped_by_month)) {
    // Balance-type wallet or bank - show only summary

    // For monthly statements on balance wallets, show opening and closing balance
    if ($type === 'monthly' && $budget_info && isset($budget_info['opening_balance'])) {
        // Opening Balance row
        $pdf->SetFillColor(232, 245, 233);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Opening Balance:', 1, 0, 'L', true);
        $pdf->SetTextColor(52, 152, 219);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($budget_info['opening_balance'], 2), 1, 1, 'R', true);

        // Income row
        $pdf->SetFillColor(232, 245, 233);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Total Income:', 1, 0, 'L', true);
        $pdf->SetTextColor(39, 174, 96);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['income'], 2), 1, 1, 'R', true);

        // Expense row
        $pdf->SetFillColor(253, 235, 235);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Total Expense:', 1, 0, 'L', true);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['expense'], 2), 1, 1, 'R', true);

        // Divider row
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(70, 3, '', 1, 0, 'L', true);
        $pdf->Cell(0, 3, '', 1, 1, 'R', true);

        // Closing Balance row
        $closing_balance = $budget_info['opening_balance'] + $summary['income'] - $summary['expense'];
        $pdf->SetFillColor(236, 240, 241);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Closing Balance:', 1, 0, 'L', true);
        $pdf->SetTextColor($closing_balance >= 0 ? 39 : 231, $closing_balance >= 0 ? 174 : 76, $closing_balance >= 0 ? 96 : 60);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($closing_balance, 2), 1, 1, 'R', true);
    } else {
        // For full/custom statements on balance wallets, show net balance
        // Income row
        $pdf->SetFillColor(232, 245, 233);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Total Income:', 1, 0, 'L', true);
        $pdf->SetTextColor(39, 174, 96);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['income'], 2), 1, 1, 'R', true);

        // Expense row
        $pdf->SetFillColor(253, 235, 235);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Total Expense:', 1, 0, 'L', true);
        $pdf->SetTextColor(231, 76, 60);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['expense'], 2), 1, 1, 'R', true);

        // Net Balance row
        $pdf->SetFillColor(236, 240, 241);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(70, 6, 'Net Balance:', 1, 0, 'L', true);
        $pdf->SetTextColor($summary['net'] >= 0 ? 39 : 231, $summary['net'] >= 0 ? 174 : 76, $summary['net'] >= 0 ? 96 : 60);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Rs. ' . number_format($summary['net'], 2), 1, 1, 'R', true);
    }
}

$pdf->Ln(4);

// Function to render transactions table
function renderTransactionsTable(&$pdf, $tx_data, $budget_info = null, $month = null, $type = null) {
    // Transactions table header
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(52, 73, 94);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(22, 7, 'Date', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Description', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'Debit', 1, 0, 'C', true);
    $pdf->Cell(20, 7, 'Credit', 1, 0, 'C', true);
    $pdf->Cell(28, 7, 'Balance', 1, 0, 'C', true);
    $pdf->Cell(0, 7, 'Note', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);

    foreach ($tx_data as $idx => $tx) {
        if ($idx % 2 == 0) {
            $pdf->SetFillColor(250, 250, 250);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $debit = ($tx['type'] === 'expense') ? number_format($tx['amount'], 2) : '';
        $credit = ($tx['type'] === 'income') ? number_format($tx['amount'], 2) : '';

        $description = htmlspecialchars(substr($tx['title'] ?? 'Transaction', 0, 20));
        $note = htmlspecialchars(substr($tx['note'] ?? '', 0, 10));

        // Format date as DD-mmm-YYYY
        $formatted_date = date('j-M-Y', strtotime($tx['date']));

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(22, 6, $formatted_date, 1, 0, 'C', true);
        $pdf->Cell(45, 6, $description, 1, 0, 'L', true);

        $pdf->SetTextColor(231, 76, 60);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(20, 6, $debit, 1, 0, 'R', true);

        $pdf->SetTextColor(39, 174, 96);
        $pdf->Cell(20, 6, $credit, 1, 0, 'R', true);

        $pdf->SetTextColor(52, 152, 219);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(28, 6, 'Rs. ' . number_format($tx['balance'], 2), 1, 0, 'R', true);

        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, $note, 1, 1, 'L', true);
    }

    // Add opening balance entry at the end for balance-type wallet statements
    if ($budget_info && isset($budget_info['opening_balance'])) {
        $pdf->SetFillColor(250, 250, 250);

        // Get month/year from $month variable if available
        if ($month) {
            list($year, $monthOnly) = explode('-', $month);
            $monthName = date('F', strtotime($month . '-01'));
            $firstOfMonth = $year . '-' . str_pad($monthOnly, 2, '0', STR_PAD_LEFT) . '-01';
            $dateStr = date('j-M-Y', strtotime($firstOfMonth));
            $noteStr = 'Opening for ' . $monthName;
        } else {
            // For full statements, use arbitrary date
            $dateStr = 'Opening';
            $noteStr = 'Opening Balance';
        }

        // Date - 1st of the month
        $pdf->SetTextColor(52, 152, 219);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(22, 6, $dateStr, 1, 0, 'C', true);

        // Description
        $pdf->Cell(45, 6, 'Opening Balance', 1, 0, 'L', true);

        // Debit (blank)
        $pdf->Cell(20, 6, '', 1, 0, 'R', true);

        // Credit (blank)
        $pdf->Cell(20, 6, '', 1, 0, 'R', true);

        // Balance - Opening balance amount
        $pdf->Cell(28, 6, 'Rs. ' . number_format($budget_info['opening_balance'], 2), 1, 0, 'R', true);

        // Note
        $pdf->Cell(0, 6, $noteStr, 1, 1, 'L', true);
    }

    // Add budget limit entry at the end for budget-type wallet monthly statements
    if ($budget_info && isset($budget_info['expected_expense']) && $type === 'monthly' && $month) {
        $pdf->SetFillColor(250, 250, 250);

        // Get month name from $month variable
        list($year, $monthOnly) = explode('-', $month);
        $monthName = date('F', strtotime($month . '-01'));
        $firstOfMonth = $year . '-' . str_pad($monthOnly, 2, '0', STR_PAD_LEFT) . '-01';
        $formatted_budget_date = date('j-M-Y', strtotime($firstOfMonth));

        // Date - 1st of the month
        $pdf->SetTextColor(52, 152, 219);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(22, 6, $formatted_budget_date, 1, 0, 'C', true);

        // Description
        $pdf->Cell(45, 6, 'Budget Limit', 1, 0, 'L', true);

        // Debit (blank)
        $pdf->Cell(20, 6, '', 1, 0, 'R', true);

        // Credit (blank)
        $pdf->Cell(20, 6, '', 1, 0, 'R', true);

        // Balance - Budget amount
        $pdf->Cell(28, 6, 'Rs. ' . number_format($budget_info['expected_expense'], 2), 1, 0, 'R', true);

        // Note
        $pdf->Cell(0, 6, 'Budget for ' . $monthName, 1, 1, 'L', true);
    }
}

// Render transactions table - for grouped months (budget wallet full statements)
if (!empty($grouped_by_month)) {
    $first_month = true;
    foreach ($grouped_by_month as $month_key => $month_data) {
        if (!$first_month) {
            $pdf->AddPage('P');

            // Repeat title and statement period on new page
            $pdf->SetFont('helvetica', 'B', 20);
            $pdf->SetTextColor(41, 57, 86);
            $pdf->Cell(0, 7, htmlspecialchars($name), 0, 1, 'L', false);

            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(100, 100, 100);
            $monthName = date('F Y', strtotime($month_key . '-01'));
            $pdf->Cell(0, 4, 'Statement Period: ' . $monthName, 0, 1);
            $pdf->Ln(3);
        }
        $first_month = false;

        // Fetch budget for this month
        list($year, $monthOnly) = explode('-', $month_key);
        $month_budget_info = $queries->getBudgetByWalletMonth($id, $year, $monthOnly);

        // Show budget summary for this month
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(52, 152, 219);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 7, 'FINANCIAL SUMMARY', 1, 1, 'L', true);

        $month_summary = $month_data['summary'];
        if ($month_budget_info && isset($month_budget_info['expected_expense'])) {
            $budget = $month_budget_info['expected_expense'];
            $net_spending = $month_summary['expense'] - $month_summary['income'];
            $remaining = $budget - $net_spending;
            $percentage_used = ($net_spending / $budget) * 100;
            $percentage_used = min($percentage_used, 100);

            // Budget Limit row
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, 6, 'Budget Limit:', 1, 0, 'L', true);
            $pdf->SetTextColor(52, 152, 219);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Rs. ' . number_format($budget, 2), 1, 1, 'R', true);

            // Income row
            $pdf->SetFillColor(232, 245, 233);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, 6, 'Total Income:', 1, 0, 'L', true);
            $pdf->SetTextColor(39, 174, 96);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Rs. ' . number_format($month_summary['income'], 2), 1, 1, 'R', true);

            // Expense row
            $pdf->SetFillColor(253, 235, 235);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, 6, 'Total Expense:', 1, 0, 'L', true);
            $pdf->SetTextColor(231, 76, 60);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Rs. ' . number_format($month_summary['expense'], 2), 1, 1, 'R', true);

            // Net Spending row
            $pdf->SetFillColor(236, 240, 241);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, 6, 'Net Spending:', 1, 0, 'L', true);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Rs. ' . number_format($net_spending, 2), 1, 1, 'R', true);

            // Divider row
            $pdf->SetFillColor(200, 200, 200);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(70, 3, '', 1, 0, 'L', true);
            $pdf->Cell(0, 3, '', 1, 1, 'R', true);

            // Remaining row
            $remaining_color = $remaining >= 0 ? [232, 245, 233] : [253, 235, 235];
            $pdf->SetFillColor($remaining_color[0], $remaining_color[1], $remaining_color[2]);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, 6, 'Remaining:', 1, 0, 'L', true);
            $pdf->SetTextColor($remaining >= 0 ? 39 : 231, $remaining >= 0 ? 174 : 76, $remaining >= 0 ? 96 : 60);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Rs. ' . number_format($remaining, 2), 1, 1, 'R', true);

            // Progress bar row
            $pdf->SetFont('helvetica', '', 10);
            $cell_height = 8;
            $pdf->SetFillColor(236, 240, 241);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(70, $cell_height, 'Budget Used:', 1, 0, 'L', true);

            if ($percentage_used >= 100) {
                $bar_color = [231, 76, 60];
            } elseif ($percentage_used >= 80) {
                $bar_color = [241, 196, 15];
            } else {
                $bar_color = [39, 174, 96];
            }

            $pdf->SetFillColor($bar_color[0], $bar_color[1], $bar_color[2]);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, $cell_height, number_format($percentage_used, 1) . '%', 1, 1, 'C', true);
        }

        $pdf->Ln(4);
        // Reverse transactions to show newest first for display
        $display_transactions = array_reverse($month_data['transactions']);
        renderTransactionsTable($pdf, $display_transactions, $month_budget_info, $month_key, 'monthly');
    }
} else {
    // Single page rendering (banks, balance wallets, or monthly/custom statements)
    // For budget wallet monthly/custom statements, reverse to show newest first
    if (($type === 'monthly' || $type === 'custom') && $view === 'wallet' && isset($wallet) && $wallet['wallet_type'] === 'budget') {
        $tx_data = array_reverse($tx_data);
    }
    renderTransactionsTable($pdf, $tx_data, $budget_info, $month, $type);
}

$filename = strtolower($view) . '_statement_' . date('Y-m-d_His') . '.pdf';
$pdf->Output($filename, 'D');
?>

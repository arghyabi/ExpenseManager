<?php
session_start();
require 'queries.php';

// Function to generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to validate CSRF token
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Ensure CSRF token is generated for use in forms
generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for all state-changing operations
    $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!validateCSRFToken($csrf_token)) {
        // CSRF token validation failed - log and reject
        error_log("CSRF token validation failed");
        header('HTTP/1.1 403 Forbidden');
        exit('CSRF token validation failed');
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // ====================================
    // TRANSACTION ACTIONS
    // ====================================
    if (in_array($action, ['tx_add', 'tx_edit', 'tx_delete'])) {
        $tx_id = isset($_POST['tx_id']) ? intval($_POST['tx_id']) : 0;

        if ($action === 'tx_add') {
            $date = isset($_POST['date']) ? $_POST['date'] : '';
            $type = isset($_POST['type']) ? $_POST['type'] : '';
            $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
            $wallet = isset($_POST['wallet']) ? intval($_POST['wallet']) : 0;
            $account = isset($_POST['account']) ? intval($_POST['account']) : 0;
            $note = isset($_POST['note']) ? $_POST['note'] : '';
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';

            // Validate required fields and formats
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if ($wallet > 0 && $amount > 0 && $account > 0 && $validDate && $validType) {
                $queries->addTransaction($validDate, $validType, $amount, $wallet, $account, $note, $title, $payment_method);
            }
        }
        elseif ($action === 'tx_edit' && $tx_id > 0) {
            $date = isset($_POST['date']) ? $_POST['date'] : '';
            $type = isset($_POST['type']) ? $_POST['type'] : '';
            $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
            $wallet = isset($_POST['wallet']) ? intval($_POST['wallet']) : 0;
            $account = isset($_POST['account']) ? intval($_POST['account']) : 0;
            $note = isset($_POST['note']) ? $_POST['note'] : '';
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';

            // Validate required fields and formats
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if ($wallet > 0 && $amount > 0 && $account > 0 && $validDate && $validType) {
                $queries->editTransaction($tx_id, $validDate, $validType, $amount, $wallet, $account, $note, $title, $payment_method);
            }
        }
        elseif ($action === 'tx_delete' && $tx_id > 0) {
            $queries->deleteTransaction($tx_id);
        }

        // Redirect back to the wallet or bank page
        $wallet_id = isset($_POST['wallet_id']) ? $_POST['wallet_id'] : null;
        $bank_id = isset($_POST['bank_id']) ? $_POST['bank_id'] : null;
        $budget_month = isset($_POST['budget_month']) ? $_POST['budget_month'] : null;

        $redirect = 'index.php';
        if ($wallet_id) {
            $redirect = 'index.php?view=wallet&id=' . intval($wallet_id);
            // Preserve budget_month if navigating from budget view
            if ($budget_month && preg_match('/^\d{4}-\d{2}$/', $budget_month)) {
                $redirect .= '&budget_month=' . urlencode($budget_month);
            }
        } elseif ($bank_id) {
            $redirect = 'index.php?view=bank&id=' . intval($bank_id);
        }
        header('Location: ' . $redirect);
        exit;
    }

    // ====================================
    // BANK ACTIONS
    // ====================================
    elseif (in_array($action, ['bank_add', 'bank_edit', 'bank_delete'])) {
        $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;

        if ($action === 'bank_add') {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($name) {
                $queries->addBank($name, $description);
            }
        }
        elseif ($action === 'bank_edit' && $bank_id > 0) {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($name) {
                $queries->editBank($bank_id, $name, $description);
            }
        }
        elseif ($action === 'bank_delete' && $bank_id > 0) {
            $db = getDB();
            // Check if bank has dependent wallets
            $dependentWallets = $db->querySingle(
                "SELECT COUNT(*) FROM wallets WHERE bank_id = " . intval($bank_id)
            );

            if ($dependentWallets == 0) {
                $queries->deleteBank($bank_id);
            }
        }

        header('Location: index.php');
        exit;
    }

    // ====================================
    // WALLET ACTIONS
    // ====================================
    elseif (in_array($action, ['wallet_add', 'wallet_edit', 'wallet_delete'])) {
        $wallet_id = isset($_POST['wallet_id']) ? intval($_POST['wallet_id']) : 0;

        if ($action === 'wallet_add') {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $wallet_type = isset($_POST['wallet_type']) ? trim($_POST['wallet_type']) : 'balance';
            // Validate wallet type
            if (!in_array($wallet_type, ['budget', 'balance'])) {
                $wallet_type = 'balance';
            }

            // Wallet is now independent of bank
            if ($name) {
                $queries->addWallet($name, 0, $description, $wallet_type);
            }
        }
        elseif ($action === 'wallet_edit' && $wallet_id > 0) {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $wallet_type = isset($_POST['wallet_type']) ? trim($_POST['wallet_type']) : null;
            // Validate wallet type if provided
            if ($wallet_type && !in_array($wallet_type, ['budget', 'balance'])) {
                $wallet_type = null;
            }

            // Wallet is now independent of bank
            if ($name) {
                $queries->editWallet($wallet_id, $name, 0, $description, $wallet_type);
            }
        }
        elseif ($action === 'wallet_delete' && $wallet_id > 0) {
            $db = getDB();

            try {
                // Start transaction
                $db->exec('BEGIN');

                // Delete all transactions associated with this wallet first
                $stmt = $db->prepare("DELETE FROM transactions WHERE wallet_id = ?");
                $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete transactions");
                }

                // Then delete the wallet
                $queries->deleteWallet($wallet_id);

                // Commit transaction
                $db->exec('COMMIT');
            } catch (Exception $e) {
                // Rollback on error
                $db->exec('ROLLBACK');
                error_log("Wallet delete failed: " . $e->getMessage());
            }
        }

        header('Location: index.php');
        exit;
    }

    // ====================================
    // BUDGET ACTIONS
    // ====================================
    elseif (in_array($action, ['budget_add', 'budget_edit', 'budget_delete'])) {
        $budget_id = isset($_POST['budget_id']) ? intval($_POST['budget_id']) : 0;
        $wallet_id = isset($_POST['wallet_id']) ? intval($_POST['wallet_id']) : 0;
        $budget_month_param = isset($_POST['budget_month']) ? $_POST['budget_month'] : null;

        if ($action === 'budget_add' && $wallet_id > 0) {
            $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
            $month = isset($_POST['month']) ? intval($_POST['month']) : date('m');
            $expected_income = isset($_POST['expected_income']) ? floatval($_POST['expected_income']) : 0;
            $expected_expense = isset($_POST['expected_expense']) ? floatval($_POST['expected_expense']) : 0;
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

            $queries->addBudget($wallet_id, $year, $month, $expected_income, $expected_expense, $notes);
        }
        elseif ($action === 'budget_edit' && $budget_id > 0) {
            $expected_income = isset($_POST['expected_income']) ? floatval($_POST['expected_income']) : 0;
            $expected_expense = isset($_POST['expected_expense']) ? floatval($_POST['expected_expense']) : 0;
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

            $queries->editBudget($budget_id, $expected_income, $expected_expense, $notes);
        }
        elseif ($action === 'budget_delete' && $budget_id > 0) {
            $queries->deleteBudget($budget_id);
        }

        // Redirect back to wallet page
        if ($wallet_id > 0) {
            $redirect = 'index.php?view=wallet&id=' . intval($wallet_id);
            // Preserve budget_month if provided and valid
            if ($budget_month_param && preg_match('/^\d{4}-\d{2}$/', $budget_month_param)) {
                $redirect .= '&budget_month=' . urlencode($budget_month_param);
            }
            header('Location: ' . $redirect);
        } else {
            header('Location: index.php');
        }
        exit;
    }
}

// ====================================
// GET API HANDLERS
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // Get bank months for dropdown
    if ($action === 'get_bank_months') {
        $bank_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($bank_id > 0) {
            $queries = new Queries();
            $result = $queries->getBankMonthlySummary($bank_id);
            $months = [];

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $months[] = [
                    'year' => $row['year'],
                    'month' => $row['month'],
                    'display' => date('F Y', strtotime($row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT) . '-01'))
                ];
            }

            header('Content-Type: application/json');
            echo json_encode($months);
            exit;
        }
    }

    // Get wallet months for dropdown
    if ($action === 'get_wallet_months') {
        $wallet_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($wallet_id > 0) {
            $queries = new Queries();
            $result = $queries->getWalletMonthlySummary($wallet_id);
            $months = [];

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $months[] = [
                    'year' => $row['year'],
                    'month' => $row['month'],
                    'display' => date('F Y', strtotime($row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT) . '-01'))
                ];
            }

            header('Content-Type: application/json');
            echo json_encode($months);
            exit;
        }
    }
}
?>

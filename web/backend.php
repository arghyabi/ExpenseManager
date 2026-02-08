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

            // Validate required fields and formats
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if ($wallet > 0 && $amount > 0 && $account > 0 && $validDate && $validType) {
                $queries->addTransaction($validDate, $validType, $amount, $wallet, $account, $note, $title);
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

            // Validate required fields and formats
            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if ($wallet > 0 && $amount > 0 && $account > 0 && $validDate && $validType) {
                $queries->editTransaction($tx_id, $validDate, $validType, $amount, $wallet, $account, $note, $title);
            }
        }
        elseif ($action === 'tx_delete' && $tx_id > 0) {
            $queries->deleteTransaction($tx_id);
        }

        // Redirect back to the wallet or bank page
        $wallet_id = isset($_POST['wallet_id']) ? $_POST['wallet_id'] : null;
        $bank_id = isset($_POST['bank_id']) ? $_POST['bank_id'] : null;

        if ($wallet_id) {
            header('Location: index.php?view=wallet&id=' . intval($wallet_id));
        } elseif ($bank_id) {
            header('Location: index.php?view=bank&id=' . intval($bank_id));
        } else {
            header('Location: index.php');
        }
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
            $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($name && $bank_id > 0) {
                $queries->addWallet($name, $bank_id, $description);
            }
        }
        elseif ($action === 'wallet_edit' && $wallet_id > 0) {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($name && $bank_id > 0) {
                $queries->editWallet($wallet_id, $name, $bank_id, $description);
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
}
?>

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

function setFlashError($message) {
    $_SESSION['flash_error'] = $message;
}

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
            $walletRaw = isset($_POST['wallet']) ? trim($_POST['wallet']) : '';
            $wallet = ($walletRaw !== '' && intval($walletRaw) > 0) ? intval($walletRaw) : null;
            $note = isset($_POST['note']) ? $_POST['note'] : '';
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $bankIdRaw = isset($_POST['payment_method']) ? intval($_POST['payment_method']) : 0;
            $payment_bank_id = $bankIdRaw > 0 ? $bankIdRaw : null;
            $catRaw = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
            $category_id = $catRaw > 0 ? $catRaw : null;

            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if (!$validDate) {
                setFlashError('Invalid date. Please select a valid date.');
            } elseif (!$validType) {
                setFlashError('Invalid transaction type.');
            } elseif ($amount <= 0) {
                setFlashError('Amount must be greater than 0.');
            } elseif ($payment_bank_id === null) {
                setFlashError('Please select a bank/payment method.');
            }

            if ($amount > 0 && $validDate && $validType && $payment_bank_id !== null) {
                $new_id = $queries->addTransaction($validDate, $validType, $amount, $wallet, $note, $title, $payment_bank_id, $category_id);
                $queries->auditLog('tx_add', 'transaction', $new_id,
                    ucfirst($validType) . ' ₹' . number_format($amount, 2) . ' — ' . $title . ' on ' . $validDate);

                // If this transaction was submitted from the Pay Bill modal, mark the reminder paid
                $reminder_id_raw = isset($_POST['reminder_id']) ? intval($_POST['reminder_id']) : 0;
                if ($reminder_id_raw > 0) {
                    $queries->markBillReminderPaid($reminder_id_raw, $validDate);
                }
            }
        }
        elseif ($action === 'tx_edit' && $tx_id > 0) {
            $date = isset($_POST['date']) ? $_POST['date'] : '';
            $type = isset($_POST['type']) ? $_POST['type'] : '';
            $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
            $walletRaw = isset($_POST['wallet']) ? trim($_POST['wallet']) : '';
            $wallet = ($walletRaw !== '' && intval($walletRaw) > 0) ? intval($walletRaw) : null;
            $note = isset($_POST['note']) ? $_POST['note'] : '';
            $title = isset($_POST['title']) ? $_POST['title'] : '';
            $bankIdRaw = isset($_POST['payment_method']) ? intval($_POST['payment_method']) : 0;
            $payment_bank_id = $bankIdRaw > 0 ? $bankIdRaw : null;
            $catRaw = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
            $category_id = $catRaw > 0 ? $catRaw : null;

            $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
            $validType = in_array($type, ['income', 'expense']) ? $type : null;

            if (!$validDate) {
                setFlashError('Invalid date. Please select a valid date.');
            } elseif (!$validType) {
                setFlashError('Invalid transaction type.');
            } elseif ($amount <= 0) {
                setFlashError('Amount must be greater than 0.');
            } elseif ($payment_bank_id === null) {
                setFlashError('Please select a bank/payment method.');
            }

            if ($amount > 0 && $validDate && $validType && $payment_bank_id !== null) {
                $queries->editTransaction($tx_id, $validDate, $validType, $amount, $wallet, $note, $title, $payment_bank_id, $category_id);
                $queries->auditLog('tx_edit', 'transaction', $tx_id,
                    'Edited #' . $tx_id . ' → ' . ucfirst($validType) . ' ₹' . number_format($amount, 2) . ' — ' . $title . ' on ' . $validDate);
            }
        }
        elseif ($action === 'tx_delete' && $tx_id > 0) {
            $tx = $queries->getTransactionById($tx_id);
            $queries->deleteTransaction($tx_id);
            $queries->auditLog('tx_delete', 'transaction', $tx_id,
                'Deleted #' . $tx_id . ($tx ? ' — ' . ($tx['title'] ?? '') . ' ₹' . number_format($tx['amount'], 2) . ' on ' . $tx['date'] : ''));
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
    // TRANSFER ACTION
    // ====================================
    elseif ($action === 'transfer_add') {
        $date       = isset($_POST['date'])         ? $_POST['date']                    : '';
        $from_bank  = isset($_POST['from_bank_id']) ? intval($_POST['from_bank_id'])    : 0;
        $to_bank    = isset($_POST['to_bank_id'])   ? intval($_POST['to_bank_id'])      : 0;
        $amount     = isset($_POST['amount'])       ? floatval($_POST['amount'])        : 0;
        $note       = isset($_POST['note'])         ? trim($_POST['note'])              : '';
        $title      = isset($_POST['title'])        ? trim($_POST['title'])             : 'Transfer';
        $ref_bank   = isset($_POST['ref_bank_id'])  ? intval($_POST['ref_bank_id'])     : 0;

        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

        if (!$validDate) {
            setFlashError('Invalid date for transfer.');
        } elseif ($from_bank <= 0 || $to_bank <= 0) {
            setFlashError('Please select both source and destination banks.');
        } elseif ($from_bank === $to_bank) {
            setFlashError('Source and destination banks must be different.');
        } elseif ($amount <= 0) {
            setFlashError('Transfer amount must be greater than 0.');
        } else {
            $result = $queries->addTransfer($validDate, $from_bank, $to_bank, $amount, $note, $title);
            if ($result) {
                $queries->auditLog('transfer_add', 'transfer', $result[0],
                    'Transfer ₹' . number_format($amount, 2) . ' bank #' . $from_bank . ' → bank #' . $to_bank . ' on ' . $validDate);
            }
        }

        // Redirect back to whichever bank we were on
        $redirect = $ref_bank > 0 ? 'index.php?view=bank&id=' . $ref_bank : 'index.php';
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
            $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0;

            if ($name) {
                $new_bank_id = $queries->addBank($name, $description, $opening_balance);
                $queries->auditLog('bank_add', 'bank', $new_bank_id, 'Added bank: ' . $name);
            }
        }
        elseif ($action === 'bank_edit' && $bank_id > 0) {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0;

            if ($name) {
                // No rename cascade needed — transactions reference bank by id, not name
                $queries->editBank($bank_id, $name, $description, $opening_balance);
                $queries->auditLog('bank_edit', 'bank', $bank_id, 'Edited bank #' . $bank_id . ': ' . $name);
            }
        }
        elseif ($action === 'bank_delete' && $bank_id > 0) {
            $db = getDB();
            $bank = $queries->getBankById($bank_id);

            if ($bank) {
                try {
                    $db->exec('BEGIN');

                    // Detach wallets so bank can be removed without deleting transactions.
                    $detachStmt = $db->prepare("UPDATE wallets SET bank_id = NULL WHERE bank_id = ?");
                    $detachStmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
                    if (!$detachStmt->execute()) {
                        throw new Exception("Failed to detach wallets from bank");
                    }

                    // Orphan transactions that were bank-direct (no wallet) — they have
                    // no remaining container, so delete them.
                    $cleanupStmt = $db->prepare(
                        "DELETE FROM transactions WHERE wallet_id IS NULL AND payment_bank_id = ?"
                    );
                    $cleanupStmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
                    if (!$cleanupStmt->execute()) {
                        throw new Exception("Failed to clean orphaned transactions");
                    }

                    // Remaining transactions (those attached to a wallet) keep their
                    // payment_bank_id but the bank row is gone — they show as orphan_bank.
                    if (!$queries->deleteBank($bank_id)) {
                        throw new Exception("Failed to delete bank");
                    }

                    $db->exec('COMMIT');
                    $queries->auditLog('bank_delete', 'bank', $bank_id, 'Deleted bank #' . $bank_id . ': ' . ($bank['name'] ?? ''));
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    error_log("Bank delete failed: " . $e->getMessage());
                }
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
            $bank_id_raw = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
            $bank_id = $bank_id_raw > 0 ? $bank_id_raw : null;
            $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : 0;

            // Validate wallet type
            if (!in_array($wallet_type, ['budget', 'balance'])) {
                $wallet_type = 'balance';
            }

            if ($name) {
                $new_wallet_id = $queries->addWallet($name, $bank_id, $description, $wallet_type, $opening_balance);
                $queries->auditLog('wallet_add', 'wallet', $new_wallet_id, 'Added wallet: ' . $name);
            }
        }
        elseif ($action === 'wallet_edit' && $wallet_id > 0) {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $wallet_type = isset($_POST['wallet_type']) ? trim($_POST['wallet_type']) : null;
            $bank_id_raw = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
            $bank_id = $bank_id_raw > 0 ? $bank_id_raw : null;
            $opening_balance = isset($_POST['opening_balance']) ? floatval($_POST['opening_balance']) : null;
            // If the field was submitted (even as 0), use it; if not present at all, preserve existing
            if (!isset($_POST['opening_balance'])) {
                $opening_balance = null; // triggers preserve in queries.php
            }

            // Validate wallet type if provided
            if ($wallet_type && !in_array($wallet_type, ['budget', 'balance'])) {
                $wallet_type = null;
            }

            if ($name) {
                $queries->editWallet($wallet_id, $name, $bank_id, $description, $wallet_type, $opening_balance);
                $queries->auditLog('wallet_edit', 'wallet', $wallet_id, 'Edited wallet #' . $wallet_id . ': ' . $name);
            }
        }
        elseif ($action === 'wallet_delete' && $wallet_id > 0) {
            $db = getDB();

            try {
                // Start transaction
                $db->exec('BEGIN');

                // Keep transactions, but mark them orphaned from wallet.
                $orphanStmt = $db->prepare("UPDATE transactions SET wallet_id = NULL WHERE wallet_id = ?");
                $orphanStmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
                if (!$orphanStmt->execute()) {
                    throw new Exception("Failed to orphan wallet transactions");
                }

                // Remove budgets tied to deleted wallet.
                $budgetStmt = $db->prepare("DELETE FROM budget WHERE wallet_id = ?");
                $budgetStmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
                if (!$budgetStmt->execute()) {
                    throw new Exception("Failed to delete wallet budgets");
                }

                // Then delete the wallet
                if (!$queries->deleteWallet($wallet_id)) {
                    throw new Exception("Failed to delete wallet");
                }

                // Commit transaction
                $db->exec('COMMIT');
                $queries->auditLog('wallet_delete', 'wallet', $wallet_id, 'Deleted wallet #' . $wallet_id);
            } catch (Exception $e) {
                // Rollback on error
                $db->exec('ROLLBACK');
                error_log("Wallet delete failed: " . $e->getMessage());
            }
        }

        // Redirect back to the page the user was on, or home if not provided
        $return_to = isset($_POST['return_to']) ? $_POST['return_to'] : '';
        // Only allow relative URLs on the same host to prevent open redirect
        $parsed = parse_url($return_to);
        if ($return_to && empty($parsed['host'])) {
            header('Location: ' . $return_to);
        } else {
            header('Location: index.php');
        }
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

            // Block duplicate: only one budget per wallet per month
            $existing = $queries->getBudgetByWalletMonth($wallet_id, $year, $month);
            if ($existing) {
                $_SESSION['flash_error'] = 'A budget already exists for this month. Use Edit to update it.';
            } else {
                $queries->addBudget($wallet_id, $year, $month, $expected_income, $expected_expense, $notes);
            }
        }
        elseif ($action === 'budget_edit' && $budget_id > 0) {
            $expected_income = isset($_POST['expected_income']) ? floatval($_POST['expected_income']) : 0;
            $expected_expense = isset($_POST['expected_expense']) ? floatval($_POST['expected_expense']) : 0;
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
            $month = isset($_POST['month']) ? intval($_POST['month']) : 0;

            $existingBudget = $queries->getBudgetById($budget_id);
            if (!$existingBudget) {
                $_SESSION['flash_error'] = 'Budget record not found.';
            } elseif ($year >= 1900 && $month >= 1 && $month <= 12) {
                $targetWalletId = intval($existingBudget['wallet_id']);
                $conflict = $queries->getBudgetByWalletMonth($targetWalletId, $year, $month);

                if ($conflict && intval($conflict['id']) !== $budget_id) {
                    $_SESSION['flash_error'] = 'A budget already exists for the selected month. Use Edit on that month instead.';
                } else {
                    $queries->editBudget($budget_id, $expected_income, $expected_expense, $notes, $year, $month);
                }
            } else {
                $_SESSION['flash_error'] = 'Invalid month or year selected.';
            }
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

    // ====================================
    // CATEGORY ACTIONS
    // ====================================
    elseif (in_array($action, ['category_add', 'category_edit', 'category_delete'])) {
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

        if ($action === 'category_add') {
            $name  = isset($_POST['name'])  ? trim($_POST['name'])  : '';
            $color = isset($_POST['color']) ? trim($_POST['color']) : '#95a5a6';
            if ($name) {
                $new_id = $queries->addCategory($name, $color);
                $queries->auditLog('category_add', 'category', $new_id, json_encode(['name' => $name, 'color' => $color]));
            }
        }
        elseif ($action === 'category_edit' && $category_id > 0) {
            $name  = isset($_POST['name'])  ? trim($_POST['name'])  : '';
            $color = isset($_POST['color']) ? trim($_POST['color']) : '#95a5a6';
            if ($name) {
                $queries->editCategory($category_id, $name, $color);
                $queries->auditLog('category_edit', 'category', $category_id, json_encode(['name' => $name, 'color' => $color]));
            }
        }
        elseif ($action === 'category_delete' && $category_id > 0) {
            $queries->deleteCategory($category_id);
            $queries->auditLog('category_delete', 'category', $category_id, null);
        }

        header('Location: index.php?view=categories');
        exit;
    }

    // ====================================
    // BILL REMINDER ACTIONS
    // ====================================
    elseif (in_array($action, ['reminder_add', 'reminder_edit', 'reminder_delete', 'reminder_toggle'])) {
        $reminder_id = isset($_POST['reminder_id']) ? intval($_POST['reminder_id']) : 0;

        if (in_array($action, ['reminder_add', 'reminder_edit'])) {
            $title           = isset($_POST['title'])           ? trim($_POST['title'])           : '';
            $type            = isset($_POST['type'])            ? trim($_POST['type'])             : 'expense';
            $default_amount  = isset($_POST['default_amount'])  ? floatval($_POST['default_amount']) : 0;
            $note            = isset($_POST['note'])            ? trim($_POST['note'])             : '';
            $frequency       = isset($_POST['frequency'])       ? trim($_POST['frequency'])        : 'monthly';
            $notify_day      = isset($_POST['notify_day'])      ? intval($_POST['notify_day'])     : 1;
            $notify_month    = isset($_POST['notify_month'])    ? intval($_POST['notify_month'])   : 1;

            $walletRaw = isset($_POST['wallet_id'])       ? trim($_POST['wallet_id'])       : '';
            $wallet_id = ($walletRaw !== '' && intval($walletRaw) > 0) ? intval($walletRaw) : null;

            $bankRaw         = isset($_POST['payment_bank_id']) ? intval($_POST['payment_bank_id']) : 0;
            $payment_bank_id = $bankRaw > 0 ? $bankRaw : null;

            $catRaw      = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
            $category_id = $catRaw > 0 ? $catRaw : null;

            $validType      = in_array($type,      ['income', 'expense'])          ? $type      : null;
            $validFreq      = in_array($frequency, ['monthly', 'yearly'])          ? $frequency : null;
            $validDay       = ($notify_day >= 1 && $notify_day <= 28)              ? $notify_day  : null;
            $validMonth     = ($notify_month >= 1 && $notify_month <= 12)          ? $notify_month : null;

            if ($title && $validType && $validFreq && $validDay && $validMonth) {
                if ($action === 'reminder_add') {
                    $new_id = $queries->addBillReminder($title, $validType, $default_amount, $wallet_id, $payment_bank_id, $category_id, $note, $validFreq, $validDay, $validMonth);
                    $queries->auditLog('reminder_add', 'bill_reminder', $new_id, 'Added bill reminder: ' . $title);
                } elseif ($reminder_id > 0) {
                    $queries->editBillReminder($reminder_id, $title, $validType, $default_amount, $wallet_id, $payment_bank_id, $category_id, $note, $validFreq, $validDay, $validMonth);
                    $queries->auditLog('reminder_edit', 'bill_reminder', $reminder_id, 'Edited bill reminder: ' . $title);
                }
            } else {
                setFlashError('Please fill in all required fields for the bill reminder.');
            }
        } elseif ($action === 'reminder_delete' && $reminder_id > 0) {
            $queries->deleteBillReminder($reminder_id);
            $queries->auditLog('reminder_delete', 'bill_reminder', $reminder_id, null);
        } elseif ($action === 'reminder_toggle' && $reminder_id > 0) {
            $active = isset($_POST['active']) ? intval($_POST['active']) : 0;
            $queries->toggleBillReminder($reminder_id, $active);
            $queries->auditLog('reminder_toggle', 'bill_reminder', $reminder_id, ($active ? 'Resumed' : 'Paused') . ' bill reminder #' . $reminder_id);
        }

        header('Location: index.php?view=reminders');
        exit;
    }

    // ====================================
    // RECURRING RULE ACTIONS
    // ====================================
    elseif (in_array($action, ['recurring_add', 'recurring_edit', 'recurring_delete', 'recurring_toggle'])) {
        $rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;

        if (in_array($action, ['recurring_add', 'recurring_edit'])) {
            $title    = isset($_POST['title'])    ? trim($_POST['title'])            : '';
            $type     = isset($_POST['type'])     ? trim($_POST['type'])             : '';
            $amount   = isset($_POST['amount'])   ? floatval($_POST['amount'])       : 0;
            $note     = isset($_POST['note'])     ? trim($_POST['note'])             : '';
            $freq     = isset($_POST['frequency'])? trim($_POST['frequency'])        : 'monthly';
            $next_due = isset($_POST['next_due']) ? trim($_POST['next_due'])         : '';

            $walletRaw = isset($_POST['wallet_id'])      ? trim($_POST['wallet_id'])      : '';
            $wallet_id = ($walletRaw !== '' && intval($walletRaw) > 0) ? intval($walletRaw) : null;

            $bankRaw   = isset($_POST['payment_bank_id']) ? intval($_POST['payment_bank_id']) : 0;
            $payment_bank_id = $bankRaw > 0 ? $bankRaw : null;

            $catRaw      = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
            $category_id = $catRaw > 0 ? $catRaw : null;

            $validType    = in_array($type, ['income', 'expense']) ? $type : null;
            $validFreq    = in_array($freq, ['daily', 'weekly', 'monthly', 'yearly']) ? $freq : null;
            $validDate    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_due) ? $next_due : null;

            if ($title && $validType && $amount > 0 && $validFreq && $validDate) {
                $ruleDetails = '"' . $title . '" — ' . ucfirst($validType) . ' ₹' . number_format($amount, 2) . ' · ' . ucfirst($validFreq) . ' from ' . $validDate;
                if ($action === 'recurring_add') {
                    $new_id = $queries->addRecurringRule($title, $validType, $amount, $wallet_id, $payment_bank_id, $category_id, $note, $validFreq, $validDate);
                    $queries->auditLog('recurring_add', 'recurring_rule', $new_id, 'Added: ' . $ruleDetails);
                } elseif ($rule_id > 0) {
                    $queries->editRecurringRule($rule_id, $title, $validType, $amount, $wallet_id, $payment_bank_id, $category_id, $note, $validFreq, $validDate);
                    $queries->auditLog('recurring_edit', 'recurring_rule', $rule_id, 'Edited: ' . $ruleDetails);
                }
            } else {
                setFlashError('Please fill in all required fields for the recurring rule.');
            }
        } elseif ($action === 'recurring_delete' && $rule_id > 0) {
            $queries->deleteRecurringRule($rule_id);
            $queries->auditLog('recurring_delete', 'recurring_rule', $rule_id, null);
        } elseif ($action === 'recurring_toggle' && $rule_id > 0) {
            $active = isset($_POST['active']) ? intval($_POST['active']) : 0;
            $queries->toggleRecurringRule($rule_id, $active);
            $queries->auditLog('recurring_toggle', 'recurring_rule', $rule_id, ($active ? 'Resumed' : 'Paused') . ' recurring rule #' . $rule_id);
        }

        header('Location: index.php?view=recurring');
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

    // Get last transaction amount for a bill reminder (pre-fills the Pay form)
    if ($action === 'get_last_reminder_amount') {
        $reminder_id = isset($_GET['reminder_id']) ? intval($_GET['reminder_id']) : 0;
        header('Content-Type: application/json');
        if ($reminder_id <= 0) { echo json_encode(['amount' => null]); exit; }
        $queries = new Queries();
        $reminder = $queries->getBillReminderById($reminder_id);
        if (!$reminder) { echo json_encode(['amount' => null]); exit; }
        $last = $queries->getLastTransactionAmountForReminder(
            $reminder['title'],
            $reminder['wallet_id'] ? intval($reminder['wallet_id']) : null,
            $reminder['payment_bank_id'] ? intval($reminder['payment_bank_id']) : null
        );
        echo json_encode(['amount' => $last, 'default_amount' => floatval($reminder['default_amount'])]);
        exit;
    }

    // Get budget details for a specific wallet + month/year
    if ($action === 'get_budget_for_month') {
        $wallet_id = isset($_GET['wallet_id']) ? intval($_GET['wallet_id']) : 0;
        $year = isset($_GET['year']) ? intval($_GET['year']) : 0;
        $month = isset($_GET['month']) ? intval($_GET['month']) : 0;

        header('Content-Type: application/json');

        if ($wallet_id <= 0 || $year < 1900 || $month < 1 || $month > 12) {
            echo json_encode(['exists' => false]);
            exit;
        }

        $queries = new Queries();
        $budget = $queries->getBudgetByWalletMonth($wallet_id, $year, $month);

        if ($budget) {
            echo json_encode([
                'exists' => true,
                'id' => intval($budget['id']),
                'expected_income' => floatval($budget['expected_income']),
                'expected_expense' => floatval($budget['expected_expense']),
                'notes' => $budget['notes'] ?? ''
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
        exit;
    }
}
?>

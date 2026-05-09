<?php
/**
 * Database Queries Module
 * Centralized location for all SQL operations
 */

require 'dbcon.php';

class Queries {

    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    // ====================================================
    // BANK QUERIES
    // ====================================================

    public function getAllBanks() {
        return $this->db->query("SELECT * FROM banks ORDER BY name ASC");
    }

    public function getBankById($bank_id) {
        $stmt = $this->db->prepare("SELECT * FROM banks WHERE id = ?");
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function addBank($name, $description = '') {
        $stmt = $this->db->prepare("INSERT INTO banks (name, description) VALUES (?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editBank($bank_id, $name, $description = '') {
        $stmt = $this->db->prepare("UPDATE banks SET name = ?, description = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        $stmt->bindValue(3, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteBank($bank_id) {
        $stmt = $this->db->prepare("DELETE FROM banks WHERE id = ?");
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getAllBanksWithDetails() {
        return $this->db->query(
            "SELECT
                b.id,
                b.name,
                b.description,
                (
                    SELECT COUNT(*)
                    FROM wallets w
                    WHERE w.bank_id = b.id
                ) as wallet_count,
                (
                    SELECT IFNULL(SUM(
                        CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                    ), 0)
                    FROM transactions t
                    WHERE t.payment_method = b.name
                ) as balance,
                (
                    SELECT COUNT(*)
                    FROM transactions t
                    LEFT JOIN wallets w2 ON t.wallet_id = w2.id
                    WHERE t.payment_method = b.name
                    AND (t.wallet_id IS NULL OR w2.id IS NULL)
                ) as warning_count
            FROM banks b
            ORDER BY b.name ASC"
        );
    }

    // ====================================================
    // WALLET QUERIES
    // ====================================================

    public function getAllWallets() {
        return $this->db->query("SELECT * FROM wallets ORDER BY name ASC");
    }

    public function getAllWalletsWithDetails() {
        return $this->db->query(
            "SELECT
                w.id,
                w.name,
                w.bank_id,
                w.description,
                w.wallet_type,
                COALESCE(b.name, 'Unknown') as bank_name,
                IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance,
                IFNULL(SUM(
                    CASE
                        WHEN t.id IS NOT NULL
                             AND (
                                t.payment_method IS NULL
                                OR t.payment_method = ''
                                OR pb.id IS NULL
                             ) THEN 1
                        ELSE 0
                    END
                ), 0) as warning_count
            FROM wallets w
            LEFT JOIN banks b ON w.bank_id = b.id
            LEFT JOIN transactions t ON w.id = t.wallet_id
            LEFT JOIN banks pb ON pb.name = t.payment_method
            GROUP BY w.id, w.name, w.bank_id, w.description, w.wallet_type, b.name
            ORDER BY w.wallet_type ASC, w.name ASC"
        );
    }

    public function getWalletById($wallet_id) {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id = ?");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getWalletsByBank($bank_id) {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE bank_id = ? ORDER BY name ASC");
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getWalletsByBankWithDetails($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT
                w.id,
                w.name,
                w.bank_id,
                w.description,
                w.wallet_type,
                IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance
            FROM wallets w
            LEFT JOIN transactions t ON w.id = t.wallet_id
            WHERE w.bank_id = ?
            GROUP BY w.id, w.name, w.bank_id, w.description, w.wallet_type
            ORDER BY w.name ASC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function addWallet($name, $bank_id, $description = '', $wallet_type = 'balance') {
        $stmt = $this->db->prepare("INSERT INTO wallets (name, bank_id, description, wallet_type) VALUES (?, ?, ?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        $stmt->bindValue(4, $wallet_type, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editWallet($wallet_id, $name, $bank_id, $description = '', $wallet_type = null) {
        // Respect explicit wallet type from UI. If absent, keep existing value.
        if ($wallet_type === null) {
            $existing = $this->getWalletById($wallet_id);
            $wallet_type = $existing['wallet_type'] ?? 'balance';
        }
        $stmt = $this->db->prepare("UPDATE wallets SET name = ?, bank_id = ?, description = ?, wallet_type = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        $stmt->bindValue(4, $wallet_type, SQLITE3_TEXT);
        $stmt->bindValue(5, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteWallet($wallet_id) {
        $stmt = $this->db->prepare("DELETE FROM wallets WHERE id = ?");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // ====================================================
    // TRANSACTION QUERIES - WALLET SPECIFIC
    // ====================================================

    public function getWalletTransactions($wallet_id, $page = 1, $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet,
                    CASE
                        WHEN t.payment_method IS NULL OR t.payment_method = '' THEN 1
                        ELSE 0
                    END as is_missing_bank,
                    CASE
                        WHEN t.payment_method IS NOT NULL
                             AND t.payment_method != ''
                             AND pb.id IS NULL THEN 1
                        ELSE 0
                    END as is_orphan_bank
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             LEFT JOIN banks pb ON pb.name=t.payment_method
             WHERE t.wallet_id = ?
             ORDER BY t.date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $per_page, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getWalletTransactionCount($wallet_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM transactions WHERE wallet_id = ?");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    public function getWalletMonthlyTransactions($wallet_id, $year, $month) {
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet,
                    CASE
                        WHEN t.payment_method IS NULL OR t.payment_method = '' THEN 1
                        ELSE 0
                    END as is_missing_bank,
                    CASE
                        WHEN t.payment_method IS NOT NULL
                             AND t.payment_method != ''
                             AND pb.id IS NULL THEN 1
                        ELSE 0
                    END as is_orphan_bank
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             LEFT JOIN banks pb ON pb.name=t.payment_method
             WHERE t.wallet_id = ?
             AND strftime('%Y', t.date) = ?
             AND strftime('%m', t.date) = ?
             ORDER BY t.date DESC"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $year, SQLITE3_TEXT);
        $stmt->bindValue(3, $month, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function getWalletBalance($wallet_id) {
        $stmt = $this->db->prepare(
            "SELECT IFNULL(SUM(
                CASE WHEN type='income' THEN amount ELSE -amount END
            ), 0) as balance
            FROM transactions
            WHERE wallet_id = ?"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    public function getWalletMonthlySummary($wallet_id) {
        $stmt = $this->db->prepare(
            "SELECT
                wallet_id,
                strftime('%Y', date) AS year,
                strftime('%m', date) AS month,
                SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense,
                SUM(CASE WHEN type='income' THEN amount ELSE -amount END) AS net
            FROM transactions
            WHERE wallet_id = ?
            GROUP BY year, month
            ORDER BY year DESC, month DESC"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // TRANSACTION QUERIES - BANK SPECIFIC (aggregated)
    // ====================================================

    public function getBankTransactions($bank_id, $page = 1, $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet,
                    CASE
                        WHEN t.wallet_id IS NOT NULL AND w.id IS NULL THEN 1
                        WHEN t.wallet_id IS NULL THEN 1
                        ELSE 0
                    END as is_orphan_wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE t.payment_method = (SELECT name FROM banks WHERE id = ?)
             ORDER BY t.date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $per_page, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getBankTransactionCount($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM transactions t
             WHERE t.payment_method = (SELECT name FROM banks WHERE id = ?)"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    public function getBankMonthlyTransactions($bank_id, $year, $month) {
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet,
                    CASE
                        WHEN t.wallet_id IS NOT NULL AND w.id IS NULL THEN 1
                        WHEN t.wallet_id IS NULL THEN 1
                        ELSE 0
                    END as is_orphan_wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE t.payment_method = (SELECT name FROM banks WHERE id = ?)
             AND strftime('%Y', t.date) = ?
             AND strftime('%m', t.date) = ?
             ORDER BY t.date DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $year, SQLITE3_TEXT);
        $stmt->bindValue(3, $month, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function getBankBalance($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT IFNULL(SUM(
                CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
            ), 0) as balance
            FROM transactions t
            WHERE t.payment_method = (SELECT name FROM banks WHERE id = ?)"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    public function getTotalBalance() {
        $result = $this->db->query(
            "SELECT IFNULL(SUM(
                CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
            ), 0) as balance
            FROM transactions t
            WHERE t.payment_method IS NOT NULL AND t.payment_method != ''"
        )->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    public function getBankMonthlySummary($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT
                strftime('%Y', t.date) AS year,
                strftime('%m', t.date) AS month,
                SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END) AS income,
                SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) AS expense,
                SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END) AS net
            FROM transactions t
            WHERE t.payment_method = (SELECT name FROM banks WHERE id = ?)
            GROUP BY year, month
            ORDER BY year DESC, month DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // TRANSACTION QUERIES - GLOBAL
    // ====================================================

    public function addTransaction($date, $type, $amount, $wallet_id, $note = '', $title = '', $payment_method = '') {
        $stmt = $this->db->prepare(
            "INSERT INTO transactions (date, type, amount, wallet_id, note, title, payment_method)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(5, $note, SQLITE3_TEXT);
        $stmt->bindValue(6, $title, SQLITE3_TEXT);
        $stmt->bindValue(7, $payment_method, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editTransaction($tx_id, $date, $type, $amount, $wallet_id, $note = '', $title = '', $payment_method = '') {
        $stmt = $this->db->prepare(
            "UPDATE transactions
             SET date=?, type=?, amount=?, wallet_id=?, note=?, title=?, payment_method=?
             WHERE id=?"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(5, $note, SQLITE3_TEXT);
        $stmt->bindValue(6, $title, SQLITE3_TEXT);
        $stmt->bindValue(7, $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(8, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteTransaction($tx_id) {
        $stmt = $this->db->prepare("DELETE FROM transactions WHERE id=?");
        $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getTransactionById($tx_id) {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getWalletSummaryByBankPaymentMethod($bank_id) {
        // Get bank name first
        $bankStmt = $this->db->prepare("SELECT name FROM banks WHERE id = ?");
        $bankStmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $bankRow = $bankStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$bankRow) {
            return null;
        }

        $bankName = $bankRow['name'];

        // Get wallet summaries for transactions with this bank's payment method
        $stmt = $this->db->prepare(
            "SELECT
                w.id,
                w.name,
                w.description,
                w.wallet_type,
                IFNULL(SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END), 0) as total_income,
                IFNULL(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END), 0) as total_expense
            FROM transactions t
            JOIN wallets w ON t.wallet_id = w.id
            WHERE t.payment_method = ?
            GROUP BY w.id, w.name, w.description, w.wallet_type
            ORDER BY w.name ASC"
        );
        $stmt->bindValue(1, $bankName, SQLITE3_TEXT);
        return $stmt->execute();
    }

    // ====================================================
    // PAYMENT METHOD QUERIES
    // ===================================================

    public function getAllPaymentMethods() {
        // Get all distinct payment methods from transactions, sorted
        $stmt = $this->db->prepare(
            "SELECT DISTINCT payment_method FROM transactions
             WHERE payment_method IS NOT NULL AND payment_method != ''
             ORDER BY payment_method ASC"
        );
        return $stmt->execute();
    }

    public function updateTransactionPaymentMethod($tx_id, $payment_method) {
        $stmt = $this->db->prepare("UPDATE transactions SET payment_method = ? WHERE id = ?");
        $stmt->bindValue(1, $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(2, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // BUDGET QUERIES
    // ====================================================

    public function getBudgetByWalletMonth($wallet_id, $year, $month) {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
        $dateStr = "$year-$monthStr";

        $stmt = $this->db->prepare("SELECT * FROM budget WHERE wallet_id = ? AND month = ?");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $dateStr, SQLITE3_TEXT);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function addBudget($wallet_id, $year, $month, $expected_income = 0, $expected_expense = 0, $notes = '') {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
        $dateStr = "$year-$monthStr";

        $stmt = $this->db->prepare(
            "INSERT INTO budget (wallet_id, month, expected_income, expected_expense, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $dateStr, SQLITE3_TEXT);
        $stmt->bindValue(3, $expected_income, SQLITE3_FLOAT);
        $stmt->bindValue(4, $expected_expense, SQLITE3_FLOAT);
        $stmt->bindValue(5, $notes, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function editBudget($budget_id, $expected_income, $expected_expense, $notes = '') {
        $stmt = $this->db->prepare(
            "UPDATE budget SET expected_income = ?, expected_expense = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->bindValue(1, $expected_income, SQLITE3_FLOAT);
        $stmt->bindValue(2, $expected_expense, SQLITE3_FLOAT);
        $stmt->bindValue(3, $notes, SQLITE3_TEXT);
        $stmt->bindValue(4, $budget_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteBudget($budget_id) {
        $stmt = $this->db->prepare("DELETE FROM budget WHERE id = ?");
        $stmt->bindValue(1, $budget_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getAllWalletBudgets($wallet_id) {
        $stmt = $this->db->prepare(
            "SELECT * FROM budget WHERE wallet_id = ? ORDER BY month DESC"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getBillBankBudgets($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT b.*, w.name as wallet_name FROM budget b
             LEFT JOIN wallets w ON b.wallet_id = w.id
             WHERE w.bank_id = ?
             ORDER BY b.month DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
}

// Create a global instance
$queries = new Queries();
?>

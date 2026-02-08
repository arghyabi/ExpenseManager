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
                COALESCE(COUNT(DISTINCT w.id), 0) as wallet_count,
                IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance
            FROM banks b
            LEFT JOIN wallets w ON b.id = w.bank_id
            LEFT JOIN transactions t ON w.id = t.wallet_id
            GROUP BY b.id, b.name, b.description
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
                COALESCE(b.name, 'Unknown') as bank_name,
                IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance
            FROM wallets w
            LEFT JOIN banks b ON w.bank_id = b.id
            LEFT JOIN transactions t ON w.id = t.wallet_id
            GROUP BY w.id, w.name, w.bank_id, w.description, b.name
            ORDER BY w.name ASC"
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
                IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance
            FROM wallets w
            LEFT JOIN transactions t ON w.id = t.wallet_id
            WHERE w.bank_id = ?
            GROUP BY w.id, w.name, w.bank_id, w.description
            ORDER BY w.name ASC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function addWallet($name, $bank_id, $description = '') {
        $stmt = $this->db->prepare("INSERT INTO wallets (name, bank_id, description) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editWallet($wallet_id, $name, $bank_id, $description = '') {
        $stmt = $this->db->prepare("UPDATE wallets SET name = ?, bank_id = ?, description = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        $stmt->bindValue(4, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteWallet($wallet_id) {
        $stmt = $this->db->prepare("DELETE FROM wallets WHERE id = ?");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // ACCOUNT QUERIES
    // ====================================================

    public function getAllAccounts() {
        return $this->db->query("SELECT * FROM accounts ORDER BY name ASC");
    }

    // ====================================================
    // TRANSACTION QUERIES - WALLET SPECIFIC
    // ====================================================

    public function getWalletTransactions($wallet_id, $page = 1, $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
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
            "SELECT t.*, w.name as wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
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
            "SELECT t.*, w.name as wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ?
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
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ?"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    public function getBankMonthlyTransactions($bank_id, $year, $month) {
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id=w.id
             WHERE w.bank_id = ?
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
            LEFT JOIN wallets w ON t.wallet_id=w.id
            WHERE w.bank_id = ?"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    public function getTotalBalance() {
        $stmt = $this->db->prepare(
            "SELECT IFNULL(SUM(
                CASE WHEN type='income' THEN amount ELSE -amount END
            ), 0) as balance
            FROM transactions"
        );
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
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
            LEFT JOIN wallets w ON t.wallet_id=w.id
            WHERE w.bank_id = ?
            GROUP BY year, month
            ORDER BY year DESC, month DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // TRANSACTION QUERIES - GLOBAL
    // ====================================================

    public function addTransaction($date, $type, $amount, $wallet_id, $account_id, $note = '', $title = '') {
        $stmt = $this->db->prepare(
            "INSERT INTO transactions (date, type, amount, wallet_id, account_id, note, title, payment_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'cash')"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(5, $account_id, SQLITE3_INTEGER);
        $stmt->bindValue(6, $note, SQLITE3_TEXT);
        $stmt->bindValue(7, $title, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editTransaction($tx_id, $date, $type, $amount, $wallet_id, $account_id, $note = '', $title = '') {
        $stmt = $this->db->prepare(
            "UPDATE transactions
             SET date=?, type=?, amount=?, wallet_id=?, account_id=?, note=?, title=?
             WHERE id=?"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(5, $account_id, SQLITE3_INTEGER);
        $stmt->bindValue(6, $note, SQLITE3_TEXT);
        $stmt->bindValue(7, $title, SQLITE3_TEXT);
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
}

// Create a global instance
$queries = new Queries();
?>

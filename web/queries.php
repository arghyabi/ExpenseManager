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

    public function addBank($name, $description = '', $opening_balance = 0) {
        $stmt = $this->db->prepare("INSERT INTO banks (name, description, opening_balance) VALUES (?, ?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        $stmt->bindValue(3, $opening_balance, SQLITE3_FLOAT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editBank($bank_id, $name, $description = '', $opening_balance = 0) {
        $stmt = $this->db->prepare("UPDATE banks SET name = ?, description = ?, opening_balance = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $description, SQLITE3_TEXT);
        $stmt->bindValue(3, $opening_balance, SQLITE3_FLOAT);
        $stmt->bindValue(4, $bank_id, SQLITE3_INTEGER);
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
                b.opening_balance,
                (
                    SELECT COUNT(*)
                    FROM wallets w
                    WHERE w.bank_id = b.id
                ) as wallet_count,
                (
                    IFNULL(b.opening_balance, 0) +
                    IFNULL((
                        SELECT SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END)
                        FROM transactions t
                        WHERE t.payment_bank_id = b.id AND t.deleted_at IS NULL
                    ), 0)
                ) as balance,
                (
                    SELECT COUNT(*)
                    FROM transactions t
                    LEFT JOIN wallets w2 ON t.wallet_id = w2.id
                    WHERE t.payment_bank_id = b.id
                    AND t.wallet_id IS NOT NULL
                    AND w2.id IS NULL
                    AND t.deleted_at IS NULL
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
                w.opening_balance,
                COALESCE(b.name, 'Unknown') as bank_name,
                IFNULL(w.opening_balance, 0) + IFNULL(SUM(
                    CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END
                ), 0) as balance,
                IFNULL(SUM(
                    CASE
                        WHEN t.id IS NOT NULL AND t.payment_bank_id IS NULL THEN 1
                        ELSE 0
                    END
                ), 0) as warning_count
            FROM wallets w
            LEFT JOIN banks b ON w.bank_id = b.id
            LEFT JOIN transactions t ON w.id = t.wallet_id AND t.deleted_at IS NULL
            GROUP BY w.id, w.name, w.bank_id, w.description, w.wallet_type, w.opening_balance, b.name
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
            LEFT JOIN transactions t ON w.id = t.wallet_id AND t.deleted_at IS NULL
            WHERE w.bank_id = ?
            GROUP BY w.id, w.name, w.bank_id, w.description, w.wallet_type
            ORDER BY w.name ASC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function addWallet($name, $bank_id, $description = '', $wallet_type = 'balance', $opening_balance = 0) {
        $stmt = $this->db->prepare("INSERT INTO wallets (name, bank_id, description, wallet_type, opening_balance) VALUES (?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        if ($bank_id) {
            $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(2, null, SQLITE3_NULL);
        }
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        $stmt->bindValue(4, $wallet_type, SQLITE3_TEXT);
        $stmt->bindValue(5, $opening_balance, SQLITE3_FLOAT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editWallet($wallet_id, $name, $bank_id, $description = '', $wallet_type = null, $opening_balance = null) {
        // Respect explicit wallet type from UI. If absent, keep existing value.
        if ($wallet_type === null) {
            $existing = $this->getWalletById($wallet_id);
            $wallet_type = $existing['wallet_type'] ?? 'balance';
        }
        // Preserve existing opening_balance if not explicitly provided
        if ($opening_balance === null) {
            $existing = $existing ?? $this->getWalletById($wallet_id);
            $opening_balance = $existing['opening_balance'] ?? 0;
        }
        $stmt = $this->db->prepare("UPDATE wallets SET name = ?, bank_id = ?, description = ?, wallet_type = ?, opening_balance = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        if ($bank_id) {
            $stmt->bindValue(2, $bank_id, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(2, null, SQLITE3_NULL);
        }
        $stmt->bindValue(3, $description, SQLITE3_TEXT);
        $stmt->bindValue(4, $wallet_type, SQLITE3_TEXT);
        $stmt->bindValue(5, $opening_balance, SQLITE3_FLOAT);
        $stmt->bindValue(6, $wallet_id, SQLITE3_INTEGER);
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
            "SELECT t.*, w.name as wallet, b.name as payment_method,
                    c.name as category_name, c.color as category_color,
                    CASE WHEN t.payment_bank_id IS NULL THEN 1 ELSE 0 END as is_missing_bank
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id = w.id
             LEFT JOIN banks b ON b.id = t.payment_bank_id
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.wallet_id = ?
               AND t.deleted_at IS NULL
             ORDER BY t.date DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $per_page, SQLITE3_INTEGER);
        $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getWalletTransactionCount($wallet_id) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM transactions WHERE wallet_id = ? AND deleted_at IS NULL");
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    public function getWalletMonthlyTransactions($wallet_id, $year, $month) {
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet, b.name as payment_method,
                    c.name as category_name, c.color as category_color,
                    CASE WHEN t.payment_bank_id IS NULL THEN 1 ELSE 0 END as is_missing_bank
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id = w.id
             LEFT JOIN banks b ON b.id = t.payment_bank_id
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.wallet_id = ?
               AND t.deleted_at IS NULL
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
            "SELECT
                IFNULL(w.opening_balance, 0) +
                IFNULL(SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END), 0) as balance
            FROM wallets w
            LEFT JOIN transactions t ON t.wallet_id = w.id AND t.deleted_at IS NULL
            WHERE w.id = ?"
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
            WHERE wallet_id = ? AND deleted_at IS NULL
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
                    c.name as category_name, c.color as category_color
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id = w.id
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.payment_bank_id = ?
               AND t.deleted_at IS NULL
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
            "SELECT COUNT(*) as count FROM transactions t WHERE t.payment_bank_id = ? AND t.deleted_at IS NULL"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Search/filter bank transactions.
     * $filters: ['q'=>string, 'type'=>'income'|'expense'|'', 'category_id'=>int|0, 'date_from'=>'YYYY-MM-DD'|'', 'date_to'=>'YYYY-MM-DD'|'']
     */
    public function searchBankTransactions($bank_id, $filters = [], $page = 1, $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        [$where, $params] = $this->_buildFilterWhere('t.payment_bank_id = ?', $bank_id, $filters);
        $sql = "SELECT t.*, w.name as wallet,
                    c.name as category_name, c.color as category_color
                FROM transactions t
                LEFT JOIN wallets w ON t.wallet_id = w.id
                LEFT JOIN categories c ON c.id = t.category_id
                WHERE $where
                ORDER BY t.date DESC
                LIMIT $per_page OFFSET $offset";
        return $this->_execFilter($sql, $bank_id, $filters, $params);
    }

    public function searchBankTransactionCount($bank_id, $filters = []) {
        [$where, $params] = $this->_buildFilterWhere('t.payment_bank_id = ?', $bank_id, $filters);
        $sql = "SELECT COUNT(*) as count FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                WHERE $where";
        $result = $this->_execFilter($sql, $bank_id, $filters, $params)->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    /**
     * Search/filter wallet transactions.
     */
    public function searchWalletTransactions($wallet_id, $filters = [], $page = 1, $per_page = 10) {
        $offset = ($page - 1) * $per_page;
        [$where, $params] = $this->_buildFilterWhere('t.wallet_id = ?', $wallet_id, $filters);
        $sql = "SELECT t.*, w.name as wallet, b.name as payment_method,
                    c.name as category_name, c.color as category_color,
                    CASE WHEN t.payment_bank_id IS NULL THEN 1 ELSE 0 END as is_missing_bank
                FROM transactions t
                LEFT JOIN wallets w ON t.wallet_id = w.id
                LEFT JOIN banks b ON b.id = t.payment_bank_id
                LEFT JOIN categories c ON c.id = t.category_id
                WHERE $where
                ORDER BY t.date DESC
                LIMIT $per_page OFFSET $offset";
        return $this->_execFilter($sql, $wallet_id, $filters, $params);
    }

    public function searchWalletTransactionCount($wallet_id, $filters = []) {
        [$where, $params] = $this->_buildFilterWhere('t.wallet_id = ?', $wallet_id, $filters);
        $sql = "SELECT COUNT(*) as count FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                WHERE $where";
        $result = $this->_execFilter($sql, $wallet_id, $filters, $params)->fetchArray(SQLITE3_ASSOC);
        return $result['count'] ?? 0;
    }

    /** Build WHERE clause + ordered params list for filter queries. */
    private function _buildFilterWhere($baseCondition, $baseId, $filters) {
        $clauses = [$baseCondition, 't.deleted_at IS NULL'];
        $params  = [['i', $baseId]]; // [type, value] pairs

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $clauses[] = "(t.title LIKE ? OR t.note LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = ['s', $like];
            $params[] = ['s', $like];
        }

        $type = $filters['type'] ?? '';
        if ($type === 'income' || $type === 'expense') {
            $clauses[] = "t.type = ?";
            $params[] = ['s', $type];
        }

        $catId = isset($filters['category_id']) ? intval($filters['category_id']) : 0;
        if ($catId > 0) {
            $clauses[] = "t.category_id = ?";
            $params[] = ['i', $catId];
        } elseif ($catId === -1) {
            // -1 means "Uncategorised"
            $clauses[] = "t.category_id IS NULL";
        }

        $dateFrom = trim($filters['date_from'] ?? '');
        if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $clauses[] = "t.date >= ?";
            $params[] = ['s', $dateFrom];
        }

        $dateTo = trim($filters['date_to'] ?? '');
        if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $clauses[] = "t.date <= ?";
            $params[] = ['s', $dateTo];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** Execute a filter query with positional params. */
    private function _execFilter($sql, $baseId, $filters, $params) {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $p) {
            $type = $p[0] === 'i' ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($i + 1, $p[1], $type);
        }
        return $stmt->execute();
    }

    public function getBankMonthlyTransactions($bank_id, $year, $month) {
        $stmt = $this->db->prepare(
            "SELECT t.*, w.name as wallet,
                    c.name as category_name, c.color as category_color
             FROM transactions t
             LEFT JOIN wallets w ON t.wallet_id = w.id
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.payment_bank_id = ?
               AND t.deleted_at IS NULL
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
            "SELECT
                IFNULL(b.opening_balance, 0) +
                IFNULL(SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END), 0) as balance
            FROM banks b
            LEFT JOIN transactions t ON t.payment_bank_id = b.id AND t.deleted_at IS NULL
            WHERE b.id = ?"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    public function getTotalBalance() {
        $result = $this->db->query(
            "SELECT
                IFNULL((SELECT SUM(opening_balance) FROM banks), 0) +
                IFNULL((SELECT SUM(opening_balance) FROM wallets), 0) +
                IFNULL(SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END), 0) as balance
            FROM transactions t
            WHERE t.payment_bank_id IS NOT NULL AND t.deleted_at IS NULL"
        )->fetchArray(SQLITE3_ASSOC);
        return $result['balance'] ?? 0;
    }

    /**
     * Global monthly income/expense summary for the last N months.
     * Only counts bank-level transactions (payment_bank_id IS NOT NULL).
     * Returns array ordered oldest → newest.
     */
    // $months = 0 means all time
    public function getGlobalMonthlySummary($months = 6) {
        if ($months === 0) {
            $sql = "SELECT
                strftime('%Y', t.date)  AS year,
                strftime('%m', t.date)  AS month,
                SUM(CASE WHEN t.type='income'  AND t.transfer_pair_id IS NULL THEN t.amount ELSE 0 END) AS income,
                SUM(CASE WHEN t.type='expense' AND t.transfer_pair_id IS NULL THEN t.amount ELSE 0 END) AS expense
             FROM transactions t
             WHERE t.payment_bank_id IS NOT NULL
               AND t.transfer_pair_id IS NULL
               AND t.deleted_at IS NULL
             GROUP BY year, month
             ORDER BY year ASC, month ASC";
            $result = $this->db->query($sql);
        } else {
            $stmt = $this->db->prepare(
                "SELECT
                    strftime('%Y', t.date)  AS year,
                    strftime('%m', t.date)  AS month,
                    SUM(CASE WHEN t.type='income'  AND t.transfer_pair_id IS NULL THEN t.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN t.type='expense' AND t.transfer_pair_id IS NULL THEN t.amount ELSE 0 END) AS expense
                 FROM transactions t
                 WHERE t.payment_bank_id IS NOT NULL
                   AND t.transfer_pair_id IS NULL
                   AND t.deleted_at IS NULL
                   AND t.date >= date('now', '-' || ? || ' months', 'start of month')
                 GROUP BY year, month
                 ORDER BY year ASC, month ASC
                 LIMIT ?"
            );
            $stmt->bindValue(1, $months, SQLITE3_INTEGER);
            $stmt->bindValue(2, $months, SQLITE3_INTEGER);
            $result = $stmt->execute();
        }
        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * Global top-N categories by total activity (expense + income), optionally filtered by month range.
     * Returns total_expense, total_income, and total (sum of both) per category.
     * $months = 0 means all time.
     */
    public function getGlobalCategoryBreakdown($limit = 6, $months = 0) {
        $dateFilter = $months > 0 ? "AND t.date >= date('now', '-' || $months || ' months', 'start of month')" : '';
        $sql = "SELECT
                COALESCE(c.name,  'Uncategorised') AS category_name,
                COALESCE(c.color, '#95a5a6')       AS category_color,
                SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) AS total_expense,
                SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) AS total_income,
                SUM(t.amount)                                             AS total,
                COUNT(*)                                                  AS tx_count
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.transfer_pair_id IS NULL
               AND t.deleted_at IS NULL
               $dateFilter
             GROUP BY t.category_id
             ORDER BY total DESC
             LIMIT $limit";
        $result = $this->db->query($sql);
        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }
        return $rows;
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
            WHERE t.payment_bank_id = ?
              AND t.deleted_at IS NULL
            GROUP BY year, month
            ORDER BY year DESC, month DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // TRANSACTION QUERIES - GLOBAL
    // ====================================================

    public function addTransaction($date, $type, $amount, $wallet_id, $note = '', $title = '', $payment_bank_id = null, $category_id = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO transactions (date, type, amount, wallet_id, note, title, payment_bank_id, category_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id === null ? null : $wallet_id, $wallet_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5, $note, SQLITE3_TEXT);
        $stmt->bindValue(6, $title, SQLITE3_TEXT);
        $stmt->bindValue(7, $payment_bank_id === null ? null : $payment_bank_id, $payment_bank_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(8, $category_id === null ? null : $category_id, $category_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editTransaction($tx_id, $date, $type, $amount, $wallet_id, $note = '', $title = '', $payment_bank_id = null, $category_id = null) {
        $stmt = $this->db->prepare(
            "UPDATE transactions
             SET date=?, type=?, amount=?, wallet_id=?, note=?, title=?, payment_bank_id=?, category_id=?
             WHERE id=?"
        );
        $stmt->bindValue(1, $date, SQLITE3_TEXT);
        $stmt->bindValue(2, $type, SQLITE3_TEXT);
        $stmt->bindValue(3, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id === null ? null : $wallet_id, $wallet_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5, $note, SQLITE3_TEXT);
        $stmt->bindValue(6, $title, SQLITE3_TEXT);
        $stmt->bindValue(7, $payment_bank_id === null ? null : $payment_bank_id, $payment_bank_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(8, $category_id === null ? null : $category_id, $category_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(9, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteTransaction($tx_id) {
        $tx = $this->getTransactionById($tx_id);
        if ($tx && !empty($tx['transfer_pair_id'])) {
            // Transfers: hard-delete both legs (no audit trail needed for paired entries)
            $paired_id = $tx['transfer_pair_id'];
            $stmt = $this->db->prepare("DELETE FROM transactions WHERE id = ? OR id = ?");
            $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
            $stmt->bindValue(2, $paired_id, SQLITE3_INTEGER);
            return $stmt->execute();
        }
        // Regular transactions: soft-delete (sets deleted_at, keeps row for audit trail)
        $stmt = $this->db->prepare("UPDATE transactions SET deleted_at = datetime('now','localtime') WHERE id = ?");
        $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    /**
     * Create a bank-to-bank transfer as two paired transaction rows.
     * Returns [from_tx_id, to_tx_id] on success or false.
     */
    public function addTransfer($date, $from_bank_id, $to_bank_id, $amount, $note = '', $title = '') {
        // Insert the expense leg (money leaves from_bank)
        $stmt1 = $this->db->prepare(
            "INSERT INTO transactions (date, type, amount, wallet_id, note, title, payment_bank_id, category_id, transfer_pair_id)
             VALUES (?, 'expense', ?, NULL, ?, ?, ?, NULL, NULL)"
        );
        $stmt1->bindValue(1, $date, SQLITE3_TEXT);
        $stmt1->bindValue(2, $amount, SQLITE3_FLOAT);
        $stmt1->bindValue(3, $note, SQLITE3_TEXT);
        $stmt1->bindValue(4, $title, SQLITE3_TEXT);
        $stmt1->bindValue(5, $from_bank_id, SQLITE3_INTEGER);
        if (!$stmt1->execute()) return false;
        $from_id = $this->db->lastInsertRowid();

        // Insert the income leg (money arrives at to_bank)
        $stmt2 = $this->db->prepare(
            "INSERT INTO transactions (date, type, amount, wallet_id, note, title, payment_bank_id, category_id, transfer_pair_id)
             VALUES (?, 'income', ?, NULL, ?, ?, ?, NULL, NULL)"
        );
        $stmt2->bindValue(1, $date, SQLITE3_TEXT);
        $stmt2->bindValue(2, $amount, SQLITE3_FLOAT);
        $stmt2->bindValue(3, $note, SQLITE3_TEXT);
        $stmt2->bindValue(4, $title, SQLITE3_TEXT);
        $stmt2->bindValue(5, $to_bank_id, SQLITE3_INTEGER);
        if (!$stmt2->execute()) return false;
        $to_id = $this->db->lastInsertRowid();

        // Link the two rows by setting transfer_pair_id on each
        $link = $this->db->prepare("UPDATE transactions SET transfer_pair_id = ? WHERE id = ?");
        $link->bindValue(1, $to_id, SQLITE3_INTEGER);
        $link->bindValue(2, $from_id, SQLITE3_INTEGER);
        $link->execute();
        $link2 = $this->db->prepare("UPDATE transactions SET transfer_pair_id = ? WHERE id = ?");
        $link2->bindValue(1, $from_id, SQLITE3_INTEGER);
        $link2->bindValue(2, $to_id, SQLITE3_INTEGER);
        $link2->execute();

        return [$from_id, $to_id];
    }

    public function getTransactionById($tx_id) {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->bindValue(1, $tx_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getWalletSummaryByBankPaymentMethod($bank_id) {
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
            WHERE t.payment_bank_id = ?
            GROUP BY w.id, w.name, w.description, w.wallet_type
            ORDER BY w.name ASC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // BUDGET QUERIES
    // ====================================================

    public function getBudgetById($budget_id) {
        $stmt = $this->db->prepare("SELECT * FROM budget WHERE id = ?");
        $stmt->bindValue(1, $budget_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

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

    public function editBudget($budget_id, $expected_income, $expected_expense, $notes = '', $year = null, $month = null) {
        if ($year !== null && $month !== null) {
            $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
            $dateStr = "$year-$monthStr";

            $stmt = $this->db->prepare(
                "UPDATE budget
                 SET month = ?, expected_income = ?, expected_expense = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->bindValue(1, $dateStr, SQLITE3_TEXT);
            $stmt->bindValue(2, $expected_income, SQLITE3_FLOAT);
            $stmt->bindValue(3, $expected_expense, SQLITE3_FLOAT);
            $stmt->bindValue(4, $notes, SQLITE3_TEXT);
            $stmt->bindValue(5, $budget_id, SQLITE3_INTEGER);
            return $stmt->execute();
        }

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
    // ====================================================
    // AUDIT LOG QUERIES
    // ====================================================

    public function auditLog($action, $entity_type, $entity_id, $summary) {
        $stmt = $this->db->prepare(
            "INSERT INTO audit_log (action, entity_type, entity_id, summary)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bindValue(1, $action,      SQLITE3_TEXT);
        $stmt->bindValue(2, $entity_type, SQLITE3_TEXT);
        $stmt->bindValue(3, $entity_id === null ? null : $entity_id, $entity_id === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(4, $summary,     SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function getAuditCount($entity_type = '') {
        if ($entity_type) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS cnt FROM audit_log WHERE entity_type = ?"
            );
            $stmt->bindValue(1, $entity_type, SQLITE3_TEXT);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM audit_log");
        }
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    public function getAuditLog($limit = 50, $entity_type = '', $offset = 0) {
        if ($entity_type) {
            $stmt = $this->db->prepare(
                "SELECT * FROM audit_log WHERE entity_type = ?
                 ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, $entity_type, SQLITE3_TEXT);
            $stmt->bindValue(2, $limit, SQLITE3_INTEGER);
            $stmt->bindValue(3, $offset, SQLITE3_INTEGER);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, $limit, SQLITE3_INTEGER);
            $stmt->bindValue(2, $offset, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }
        return $rows;
    }

    // ====================================================
    // CATEGORY QUERIES
    // ====================================================

    public function getAllCategories() {
        return $this->db->query("SELECT * FROM categories ORDER BY name ASC");
    }

    public function getCategoryById($category_id) {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->bindValue(1, $category_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function addCategory($name, $color = '#95a5a6') {
        $stmt = $this->db->prepare("INSERT INTO categories (name, color) VALUES (?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $color, SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editCategory($category_id, $name, $color) {
        $stmt = $this->db->prepare("UPDATE categories SET name = ?, color = ? WHERE id = ?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $color, SQLITE3_TEXT);
        $stmt->bindValue(3, $category_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteCategory($category_id) {
        // Null out category_id on transactions before deleting
        $stmt = $this->db->prepare("UPDATE transactions SET category_id = NULL WHERE category_id = ?");
        $stmt->bindValue(1, $category_id, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt2 = $this->db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt2->bindValue(1, $category_id, SQLITE3_INTEGER);
        return $stmt2->execute();
    }

    // Spending breakdown by category for a wallet
    public function getWalletCategoryBreakdown($wallet_id) {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(c.name, 'Uncategorised') as category_name,
                COALESCE(c.color, '#95a5a6') as category_color,
                SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) as total_expense,
                SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) as total_income,
                COUNT(*) as tx_count
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.wallet_id = ?
              AND t.deleted_at IS NULL
            GROUP BY t.category_id
            HAVING total_expense > 0
            ORDER BY total_expense DESC"
        );
        $stmt->bindValue(1, $wallet_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // Spending breakdown by category for a bank
    public function getBankCategoryBreakdown($bank_id) {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(c.name, 'Uncategorised') as category_name,
                COALESCE(c.color, '#95a5a6') as category_color,
                SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) as total_expense,
                SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) as total_income,
                COUNT(*) as tx_count
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE t.payment_bank_id = ?
              AND t.deleted_at IS NULL
            GROUP BY t.category_id
            HAVING total_expense > 0
            ORDER BY total_expense DESC"
        );
        $stmt->bindValue(1, $bank_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ====================================================
    // RECURRING RULE QUERIES
    // ====================================================

    public function getAllRecurringRules() {
        return $this->db->query(
            "SELECT r.*,
                    w.name as wallet_name,
                    b.name as bank_name,
                    c.name as category_name, c.color as category_color
             FROM recurring_rules r
             LEFT JOIN wallets    w ON w.id = r.wallet_id
             LEFT JOIN banks      b ON b.id = r.payment_bank_id
             LEFT JOIN categories c ON c.id = r.category_id
             ORDER BY r.active DESC, r.next_due ASC"
        );
    }

    public function getRecurringRuleById($rule_id) {
        $stmt = $this->db->prepare(
            "SELECT r.*,
                    w.name as wallet_name,
                    b.name as bank_name,
                    c.name as category_name
             FROM recurring_rules r
             LEFT JOIN wallets    w ON w.id = r.wallet_id
             LEFT JOIN banks      b ON b.id = r.payment_bank_id
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.id = ?"
        );
        $stmt->bindValue(1, $rule_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function addRecurringRule($title, $type, $amount, $wallet_id, $payment_bank_id, $category_id, $note, $frequency, $next_due) {
        $stmt = $this->db->prepare(
            "INSERT INTO recurring_rules
                (title, type, amount, wallet_id, payment_bank_id, category_id, note, frequency, next_due, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->bindValue(1, $title,           SQLITE3_TEXT);
        $stmt->bindValue(2, $type,            SQLITE3_TEXT);
        $stmt->bindValue(3, $amount,          SQLITE3_FLOAT);
        $stmt->bindValue(4, $wallet_id          === null ? null : $wallet_id,         $wallet_id         === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5, $payment_bank_id    === null ? null : $payment_bank_id,   $payment_bank_id   === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(6, $category_id        === null ? null : $category_id,       $category_id       === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(7, $note,            SQLITE3_TEXT);
        $stmt->bindValue(8, $frequency,       SQLITE3_TEXT);
        $stmt->bindValue(9, $next_due,        SQLITE3_TEXT);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editRecurringRule($rule_id, $title, $type, $amount, $wallet_id, $payment_bank_id, $category_id, $note, $frequency, $next_due) {
        $stmt = $this->db->prepare(
            "UPDATE recurring_rules
             SET title=?, type=?, amount=?, wallet_id=?, payment_bank_id=?, category_id=?, note=?, frequency=?, next_due=?
             WHERE id=?"
        );
        $stmt->bindValue(1,  $title,           SQLITE3_TEXT);
        $stmt->bindValue(2,  $type,            SQLITE3_TEXT);
        $stmt->bindValue(3,  $amount,          SQLITE3_FLOAT);
        $stmt->bindValue(4,  $wallet_id          === null ? null : $wallet_id,       $wallet_id         === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5,  $payment_bank_id    === null ? null : $payment_bank_id, $payment_bank_id   === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(6,  $category_id        === null ? null : $category_id,     $category_id       === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(7,  $note,            SQLITE3_TEXT);
        $stmt->bindValue(8,  $frequency,       SQLITE3_TEXT);
        $stmt->bindValue(9,  $next_due,        SQLITE3_TEXT);
        $stmt->bindValue(10, $rule_id,         SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function toggleRecurringRule($rule_id, $active) {
        $stmt = $this->db->prepare("UPDATE recurring_rules SET active = ? WHERE id = ?");
        $stmt->bindValue(1, $active ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(2, $rule_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteRecurringRule($rule_id) {
        $stmt = $this->db->prepare("DELETE FROM recurring_rules WHERE id = ?");
        $stmt->bindValue(1, $rule_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    /**
     * Lazy due-processing engine.
     * Fetches all active rules with next_due <= today, posts one transaction per
     * overdue period, and advances next_due. Called on every bank/wallet page load.
     * Returns the count of transactions posted.
     */
    public function processRecurringRules() {
        $today = date('Y-m-d');
        $stmt  = $this->db->prepare(
            "SELECT * FROM recurring_rules WHERE active = 1 AND next_due <= ?"
        );
        $stmt->bindValue(1, $today, SQLITE3_TEXT);
        $result = $stmt->execute();

        $posted = 0;
        while ($rule = $result->fetchArray(SQLITE3_ASSOC)) {
            // Post one transaction per due period (catch-up one at a time per page load)
            $wallet_id       = $rule['wallet_id']       ? intval($rule['wallet_id'])       : null;
            $payment_bank_id = $rule['payment_bank_id'] ? intval($rule['payment_bank_id']) : null;
            $category_id     = $rule['category_id']     ? intval($rule['category_id'])     : null;

            $this->addTransaction(
                $rule['next_due'],
                $rule['type'],
                $rule['amount'],
                $wallet_id,
                $rule['note'],
                $rule['title'],
                $payment_bank_id,
                $category_id
            );
            $posted++;

            // Advance next_due by one frequency period
            $next = $this->_advanceDate($rule['next_due'], $rule['frequency']);
            $upd  = $this->db->prepare("UPDATE recurring_rules SET next_due = ? WHERE id = ?");
            $upd->bindValue(1, $next,         SQLITE3_TEXT);
            $upd->bindValue(2, $rule['id'],   SQLITE3_INTEGER);
            $upd->execute();
        }
        return $posted;
    }

    /** Advance an ISO date string by one frequency period. */
    private function _advanceDate($date_str, $frequency) {
        $dt = new DateTime($date_str);
        switch ($frequency) {
            case 'daily':   $dt->modify('+1 day');   break;
            case 'weekly':  $dt->modify('+7 days');  break;
            case 'yearly':  $dt->modify('+1 year');  break;
            case 'monthly':
            default:        $dt->modify('+1 month'); break;
        }
        return $dt->format('Y-m-d');
    }

    // ====================================================
    // BILL REMINDER QUERIES
    // ====================================================

    public function getAllBillReminders() {
        return $this->db->query(
            "SELECT r.*,
                    w.name as wallet_name,
                    b.name as bank_name,
                    c.name as category_name, c.color as category_color
             FROM bill_reminders r
             LEFT JOIN wallets    w ON w.id = r.wallet_id
             LEFT JOIN banks      b ON b.id = r.payment_bank_id
             LEFT JOIN categories c ON c.id = r.category_id
             ORDER BY r.active DESC, r.title ASC"
        );
    }

    public function getBillReminderById($reminder_id) {
        $stmt = $this->db->prepare(
            "SELECT r.*,
                    w.name as wallet_name,
                    b.name as bank_name,
                    c.name as category_name
             FROM bill_reminders r
             LEFT JOIN wallets    w ON w.id = r.wallet_id
             LEFT JOIN banks      b ON b.id = r.payment_bank_id
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.id = ?"
        );
        $stmt->bindValue(1, $reminder_id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function addBillReminder($title, $type, $default_amount, $wallet_id, $payment_bank_id, $category_id, $note, $frequency, $notify_day, $notify_month) {
        $stmt = $this->db->prepare(
            "INSERT INTO bill_reminders
                (title, type, default_amount, wallet_id, payment_bank_id, category_id, note, frequency, notify_day, notify_month, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->bindValue(1,  $title,          SQLITE3_TEXT);
        $stmt->bindValue(2,  $type,           SQLITE3_TEXT);
        $stmt->bindValue(3,  $default_amount, SQLITE3_FLOAT);
        $stmt->bindValue(4,  $wallet_id          === null ? null : $wallet_id,       $wallet_id         === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5,  $payment_bank_id    === null ? null : $payment_bank_id, $payment_bank_id   === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(6,  $category_id        === null ? null : $category_id,     $category_id       === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(7,  $note,           SQLITE3_TEXT);
        $stmt->bindValue(8,  $frequency,      SQLITE3_TEXT);
        $stmt->bindValue(9,  $notify_day,     SQLITE3_INTEGER);
        $stmt->bindValue(10, $notify_month,   SQLITE3_INTEGER);
        return $stmt->execute() ? $this->db->lastInsertRowid() : false;
    }

    public function editBillReminder($reminder_id, $title, $type, $default_amount, $wallet_id, $payment_bank_id, $category_id, $note, $frequency, $notify_day, $notify_month) {
        $stmt = $this->db->prepare(
            "UPDATE bill_reminders
             SET title=?, type=?, default_amount=?, wallet_id=?, payment_bank_id=?, category_id=?, note=?, frequency=?, notify_day=?, notify_month=?
             WHERE id=?"
        );
        $stmt->bindValue(1,  $title,          SQLITE3_TEXT);
        $stmt->bindValue(2,  $type,           SQLITE3_TEXT);
        $stmt->bindValue(3,  $default_amount, SQLITE3_FLOAT);
        $stmt->bindValue(4,  $wallet_id          === null ? null : $wallet_id,       $wallet_id         === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(5,  $payment_bank_id    === null ? null : $payment_bank_id, $payment_bank_id   === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(6,  $category_id        === null ? null : $category_id,     $category_id       === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(7,  $note,           SQLITE3_TEXT);
        $stmt->bindValue(8,  $frequency,      SQLITE3_TEXT);
        $stmt->bindValue(9,  $notify_day,     SQLITE3_INTEGER);
        $stmt->bindValue(10, $notify_month,   SQLITE3_INTEGER);
        $stmt->bindValue(11, $reminder_id,    SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteBillReminder($reminder_id) {
        $stmt = $this->db->prepare("DELETE FROM bill_reminders WHERE id = ?");
        $stmt->bindValue(1, $reminder_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function toggleBillReminder($reminder_id, $active) {
        $stmt = $this->db->prepare("UPDATE bill_reminders SET active = ? WHERE id = ?");
        $stmt->bindValue(1, $active ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(2, $reminder_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    /**
     * Returns bill reminders that are currently due (notification should show).
     * A monthly reminder is due if today's day-of-month >= notify_day.
     * A yearly reminder is due if today's month == notify_month AND today's day >= notify_day.
     */
    public function getDueBillReminders() {
        $todayDay  = intval(date('j'));
        $todayMon  = intval(date('n'));
        $todayYear = intval(date('Y'));

        $result = $this->db->query(
            "SELECT r.*,
                    w.name as wallet_name,
                    b.name as bank_name,
                    c.name as category_name, c.color as category_color
             FROM bill_reminders r
             LEFT JOIN wallets    w ON w.id = r.wallet_id
             LEFT JOIN banks      b ON b.id = r.payment_bank_id
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE r.active = 1"
        );

        $due = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Check if already paid this cycle
            if (!empty($row['last_paid_date'])) {
                $paid = new DateTime($row['last_paid_date']);
                if ($row['frequency'] === 'monthly') {
                    // Hide if paid in the current calendar month
                    if (intval($paid->format('Y')) === $todayYear &&
                        intval($paid->format('n')) === $todayMon) {
                        continue;
                    }
                } elseif ($row['frequency'] === 'yearly') {
                    // Hide if paid in the current calendar year
                    if (intval($paid->format('Y')) === $todayYear) {
                        continue;
                    }
                }
            }

            if ($row['frequency'] === 'monthly') {
                if ($todayDay >= intval($row['notify_day'])) {
                    $due[] = $row;
                }
            } elseif ($row['frequency'] === 'yearly') {
                if ($todayMon === intval($row['notify_month']) && $todayDay >= intval($row['notify_day'])) {
                    $due[] = $row;
                }
            }
        }
        return $due;
    }

    public function markBillReminderPaid($reminder_id, $date) {
        $stmt = $this->db->prepare(
            "UPDATE bill_reminders SET last_paid_date = ? WHERE id = ?"
        );
        $stmt->bindValue(1, $date,        SQLITE3_TEXT);
        $stmt->bindValue(2, $reminder_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    /**
     * Returns the amount of the most recent transaction matching the given title
     * (case-insensitive) for a specific wallet or bank, to pre-fill the pay form.
     */
    public function getLastTransactionAmountForReminder($title, $wallet_id, $payment_bank_id) {
        if ($wallet_id) {
            $stmt = $this->db->prepare(
                "SELECT amount FROM transactions
                 WHERE LOWER(title) = LOWER(?) AND wallet_id = ? AND deleted_at IS NULL
                 ORDER BY date DESC, id DESC LIMIT 1"
            );
            $stmt->bindValue(1, $title, SQLITE3_TEXT);
            $stmt->bindValue(2, $wallet_id, SQLITE3_INTEGER);
        } elseif ($payment_bank_id) {
            $stmt = $this->db->prepare(
                "SELECT amount FROM transactions
                 WHERE LOWER(title) = LOWER(?) AND payment_bank_id = ? AND deleted_at IS NULL
                 ORDER BY date DESC, id DESC LIMIT 1"
            );
            $stmt->bindValue(1, $title, SQLITE3_TEXT);
            $stmt->bindValue(2, $payment_bank_id, SQLITE3_INTEGER);
        } else {
            $stmt = $this->db->prepare(
                "SELECT amount FROM transactions
                 WHERE LOWER(title) = LOWER(?) AND deleted_at IS NULL
                 ORDER BY date DESC, id DESC LIMIT 1"
            );
            $stmt->bindValue(1, $title, SQLITE3_TEXT);
        }
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? floatval($row['amount']) : null;
    }
}

// Create a global instance
$queries = new Queries();
?>

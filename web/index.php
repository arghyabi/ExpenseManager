<?php
session_start();
require 'queries.php';

// Generate CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$db = getDB();

# -----------------------
# Read config for version
# -----------------------
$version = '';
$configFile = dirname(__DIR__) . '/config.yaml';
if(file_exists($configFile)) {
    $configContent = file_get_contents($configFile);
    if(preg_match('/AppVersion:\s*(.+)/', $configContent, $matches)) {
        $version = trim($matches[1]);
    }
}

# -----------------------
# Determine view type
# -----------------------
$view = isset($_GET['view']) ? $_GET['view'] : 'main';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

# -----------------------
# Load data based on view
# -----------------------
$wallets = null;
$transactions = null;
$currentBank = null;
$currentWallet = null;
$balance = 0;
$monthly = null;
$page = 1;
$totalPages = 1;
$totalCount = 0;

if ($view === 'bank' && $id > 0) {
    $currentBank = $queries->getBankById($id);
    if ($currentBank) {
        $wallets = $queries->getWalletsByBank($id);
        $balance = $queries->getBankBalance($id);
        $monthly = $queries->getBankMonthlySummary($id);

        $perPage = 10;
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

        # Check if filtering by month
        $selectedMonth = isset($_GET['month']) ? $_GET['month'] : null;
        $validMonth = null;

        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            # Validate and parse month
            list($selectedYear, $selectedMonthOnly) = explode('-', $selectedMonth);
            $selectedYear = intval($selectedYear);
            $selectedMonthOnly = intval($selectedMonthOnly);

            # Ensure month is valid (1-12)
            if ($selectedMonthOnly >= 1 && $selectedMonthOnly <= 12) {
                $validMonth = $selectedMonth;
                $transactions = $queries->getBankMonthlyTransactions($id, $selectedYear, str_pad($selectedMonthOnly, 2, '0', STR_PAD_LEFT));
                $totalCount = 0;
                $totalPages = 1;
            } else {
                # Show all transactions with pagination
                $totalCount = $queries->getBankTransactionCount($id);
                $totalPages = max(1, (int)ceil($totalCount / $perPage));
                $transactions = $queries->getBankTransactions($id, $page, $perPage);
            }
        } else {
            # Show all transactions with pagination
            $totalCount = $queries->getBankTransactionCount($id);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $transactions = $queries->getBankTransactions($id, $page, $perPage);
        }
    }
}
elseif ($view === 'wallet' && $id > 0) {
    $currentWallet = $queries->getWalletById($id);
    if ($currentWallet) {
        $balance = $queries->getWalletBalance($id);
        $monthly = $queries->getWalletMonthlySummary($id);

        $perPage = 10;
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

        # Check if filtering by month
        $selectedMonth = isset($_GET['month']) ? $_GET['month'] : null;
        $validMonth = null;

        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            # Validate and parse month
            list($selectedYear, $selectedMonthOnly) = explode('-', $selectedMonth);
            $selectedYear = intval($selectedYear);
            $selectedMonthOnly = intval($selectedMonthOnly);

            # Ensure month is valid (1-12)
            if ($selectedMonthOnly >= 1 && $selectedMonthOnly <= 12) {
                $validMonth = $selectedMonth;
                $transactions = $queries->getWalletMonthlyTransactions($id, $selectedYear, str_pad($selectedMonthOnly, 2, '0', STR_PAD_LEFT));
                $totalCount = 0;
                $totalPages = 1;
            } else {
                # Show all transactions with pagination
                $totalCount = $queries->getWalletTransactionCount($id);
                $totalPages = max(1, (int)ceil($totalCount / $perPage));
                $transactions = $queries->getWalletTransactions($id, $page, $perPage);
            }
        } else {
            # Show all transactions with pagination
            $totalCount = $queries->getWalletTransactionCount($id);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $transactions = $queries->getWalletTransactions($id, $page, $perPage);
        }
    }
}
else {
    # Main view - calculate total balance across all banks and wallets
    $balance = $queries->getTotalBalance();
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Manager</title>
    <link rel="stylesheet" href="resource/css/style.css">
    <link rel="icon" href="resource/images/icon.png">
    <script>
        // Expose CSRF token to JavaScript for use in dynamic forms
        window.csrfToken = '<?= htmlspecialchars($csrf_token) ?>';
    </script>
</head>
<body>

<div class="app-header">
    <h1>💰 Expense Manager <?php if($version) echo '<span class="version">' . htmlspecialchars($version) . '</span>'; ?></h1>
    <div>
        <button id="theme-toggle" class="theme-toggle">🌙</button>
    </div>
</div>

<!-- Navigation breadcrumb -->
<div class="nav-breadcrumb">
    <div class="breadcrumb-content">
        <a href="index.php" class="breadcrumb-link">Home</a>
        <?php if ($view === 'bank' && $currentBank): ?>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">🏦 <?= htmlspecialchars($currentBank['name']) ?></span>
        <?php elseif ($view === 'wallet' && $currentWallet): ?>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">💳 <?= htmlspecialchars($currentWallet['name']) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($view === 'bank' && $currentBank): ?>
        <div class="breadcrumb-menu">
            <button class="bank-menu-btn" type="button">⋮</button>
            <div class="bank-menu-dropdown">
                <button class="bank-menu-item bank-edit-option" data-id="<?= htmlspecialchars($currentBank['id']) ?>" data-name="<?= htmlspecialchars($currentBank['name']) ?>" data-description="<?= htmlspecialchars($currentBank['description'] ?? '') ?>">✏️ Edit Bank</button>
                <div style="height: 1px; background: #ddd; margin: 5px 0;"></div>
                <button class="bank-menu-item bank-full-statement-btn">📥 Full Statement</button>
                <button class="bank-menu-item bank-monthly-statement-btn">📄 Monthly Statement</button>
                <button class="bank-menu-item bank-custom-range-btn">📅 Custom Range</button>
            </div>
        </div>
    <?php elseif ($view === 'wallet' && $currentWallet): ?>
        <div class="breadcrumb-menu">
            <button class="wallet-menu-btn" type="button">⋮</button>
            <div class="wallet-menu-dropdown">
                <button class="wallet-menu-item wallet-edit-option" data-id="<?= htmlspecialchars($id) ?>" data-name="<?= htmlspecialchars($currentWallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($currentWallet['wallet_type'] ?? 'balance') ?>" data-description="<?= htmlspecialchars($currentWallet['description'] ?? '') ?>">✏️ Edit Wallet</button>
                <div style="height: 1px; background: #ddd; margin: 5px 0;"></div>
                <button class="wallet-menu-item wallet-full-statement-btn">📥 Full Statement</button>
                <button class="wallet-menu-item wallet-monthly-statement-btn">📄 Monthly Statement</button>
                <button class="wallet-menu-item wallet-custom-range-btn">📅 Custom Range</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Balance Display (for Balance Wallets and Bank Views only) -->
<?php if ($view !== 'wallet' || ($view === 'wallet' && ($currentWallet['wallet_type'] ?? 'balance') === 'balance')): ?>
<div class="balance-card">
    <h2>Balance</h2>
    <div class="balance-amount">₹ <?= number_format($balance, 2) ?></div>
</div>
<?php endif; ?>

<?php if ($view === 'main'): ?>
    <!-- MAIN VIEW: All Banks -->
    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
        <button id="open-add-bank" class="btn" type="button">🏦 Add Bank</button>
        <button id="open-add-wallet" class="btn" type="button">💳 Add Wallet</button>
    </div>

    <!-- Banks Grid -->
    <h2>Banks</h2>
    <div class="card-grid">
        <?php
        $banksResult = $queries->getAllBanksWithDetails();
        while ($bank = $banksResult->fetchArray(SQLITE3_ASSOC)):
        ?>
        <div class="card bank-card" data-bank-id="<?= htmlspecialchars($bank['id']) ?>">
            <div class="card-header">
                <strong>🏦 <?= htmlspecialchars($bank['name']) ?></strong>
                <div class="card-menu-container">
                    <button class="card-menu-btn" data-id="<?= htmlspecialchars($bank['id']) ?>" data-name="<?= htmlspecialchars($bank['name']) ?>" data-description="<?= htmlspecialchars($bank['description'] ?? '') ?>">⋮</button>
                    <div class="card-menu-dropdown">
                        <a href="index.php?view=bank&id=<?= htmlspecialchars($bank['id']) ?>" class="card-menu-item">👁️ View</a>
                        <button class="card-menu-item bank-edit-btn" data-id="<?= htmlspecialchars($bank['id']) ?>" data-name="<?= htmlspecialchars($bank['name']) ?>" data-description="<?= htmlspecialchars($bank['description'] ?? '') ?>">✏️ Edit</button>
                        <button class="card-menu-item card-delete-danger bank-delete-btn" data-id="<?= htmlspecialchars($bank['id']) ?>" data-name="<?= htmlspecialchars($bank['name']) ?>">🗑️ Delete</button>
                    </div>
                </div>
            </div>
            <div class="card-body card-clickable" data-href="index.php?view=bank&id=<?= htmlspecialchars($bank['id']) ?>">
                <div class="card-row">
                    <span class="label">Balance</span>
                    <span class="value">₹ <?= number_format($bank['balance'], 2) ?></span>
                </div>
                <?php if (!empty($bank['description'])): ?>
                <div class="card-row">
                    <span class="label">Add'l Info</span>
                    <span class="value" style="font-size: 0.9rem;"><?= htmlspecialchars($bank['description']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <!-- Wallets Grid - Separated by Type -->
    <h2>Wallets<span class="section-subtitle">(organized by type)</span></h2>

    <?php
    // Fetch all wallets and separate by type
    $walletsResult = $queries->getAllWalletsWithDetails();
    $budgetWallets = [];
    $balanceWallets = [];

    while ($wallet = $walletsResult->fetchArray(SQLITE3_ASSOC)):
        if ($wallet['wallet_type'] === 'budget') {
            $budgetWallets[] = $wallet;
        } else {
            $balanceWallets[] = $wallet;
        }
    endwhile;
    ?>

    <!-- Budget Tracker Wallets -->
    <?php if (!empty($budgetWallets)): ?>
    <div style="margin-bottom: 24px;">
        <h3 style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 12px;">📊 Monthly Budget Trackers</h3>
        <div class="card-grid">
            <?php foreach ($budgetWallets as $wallet): ?>
            <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>">
                <div class="card-header">
                    <strong>📊 <?= htmlspecialchars($wallet['name']) ?></strong>
                    <div class="card-menu-container">
                        <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                        <div class="card-menu-dropdown">
                            <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                            <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">✏️ Edit</button>
                            <button class="card-menu-item card-delete-danger wallet-delete-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>">🗑️ Delete</button>
                        </div>
                    </div>
                </div>
                <div class="card-body card-clickable" data-href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>">
                    <?php
                    // Get current month's budget for this wallet
                    $currentYear = date('Y');
                    $currentMonth = date('m');
                    $currentBudget = $queries->getBudgetByWalletMonth($wallet['id'], $currentYear, intval($currentMonth));

                    // Get current month's actual spending
                    $currentMonthTx = $queries->getWalletMonthlyTransactions($wallet['id'], $currentYear, $currentMonth);
                    $actualIncome = 0;
                    $actualExpense = 0;
                    while($tx = $currentMonthTx->fetchArray(SQLITE3_ASSOC)) {
                        if ($tx['type'] === 'income') {
                            $actualIncome += $tx['amount'];
                        } else {
                            $actualExpense += $tx['amount'];
                        }
                    }

                    $expectedExpense = $currentBudget['expected_expense'] ?? 0;
                    $netSpending = $actualExpense - $actualIncome;
                    $budgetRemaining = $expectedExpense - $netSpending;
                    $budgetPercent = $expectedExpense > 0 ? min(100, max(0, ($netSpending / $expectedExpense) * 100)) : 0;
                    ?>

                    <!-- Current Month Budget Info -->
                    <div class="card-row">
                        <span class="label">Budget Limit</span>
                        <span class="value" style="color: #3498db; font-weight: 700;">₹ <?= number_format($expectedExpense, 0) ?></span>
                    </div>

                    <?php if ($expectedExpense > 0): ?>
                    <div class="card-row">
                        <span class="label">Spent</span>
                        <span class="value" style="color: #e74c3c;">₹ <?= number_format($netSpending, 0) ?></span>
                    </div>

                    <!-- Mini Progress Bar -->
                    <div style="margin: 8px 0; background: rgba(0,0,0,0.1); height: 6px; border-radius: 3px; overflow: hidden;">
                        <?php
                        $barColor = '#27ae60'; // Green
                        if ($budgetPercent >= 50 && $budgetPercent < 70) {
                            $barColor = '#f39c12'; // Yellow
                        } elseif ($budgetPercent >= 70 && $budgetPercent < 85) {
                            $barColor = '#e67e22'; // Orange
                        } elseif ($budgetPercent >= 85) {
                            $barColor = '#e74c3c'; // Red
                        }
                        ?>
                        <div style="width: <?= min($budgetPercent, 100) ?>%; height: 100%; background: <?= $barColor ?>; transition: width 300ms ease;"></div>
                    </div>

                    <div class="card-row">
                        <span class="label" style="font-size: 0.85rem;">Left</span>
                        <span class="value" style="color: <?= $budgetRemaining >= 0 ? '#27ae60' : '#e74c3c' ?>; font-size: 0.9rem;">₹ <?= number_format($budgetRemaining >= 0 ? $budgetRemaining : abs($budgetRemaining), 0) ?><?= $budgetRemaining < 0 ? ' over' : '' ?></span>
                    </div>
                    <?php else: ?>
                    <div class="card-row" style="color: var(--text-muted); font-size: 0.88rem; font-style: italic;">
                        No budget set for this month
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Balance Tracking Wallets -->
    <?php if (!empty($balanceWallets)): ?>
    <div>
        <h3 style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 12px;">💳 Running Balance Wallets</h3>
        <div class="card-grid">
            <?php foreach ($balanceWallets as $wallet): ?>
            <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>">
                <div class="card-header">
                    <strong>💳 <?= htmlspecialchars($wallet['name']) ?></strong>
                    <div class="card-menu-container">
                        <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                        <div class="card-menu-dropdown">
                            <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                            <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">✏️ Edit</button>
                            <button class="card-menu-item card-delete-danger wallet-delete-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>">🗑️ Delete</button>
                        </div>
                    </div>
                </div>
                <div class="card-body card-clickable" data-href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>">
                    <div class="card-row">
                        <span class="label">Balance</span>
                        <span class="value">₹ <?= number_format($wallet['balance'], 2) ?></span>
                    </div>
                    <div class="card-row">
                        <span class="label">Type</span>
                        <span class="value" style="color: #27ae60;">Balance Wallet</span>
                    </div>
                    <?php if (!empty($wallet['description'])): ?>
                    <div class="card-row">
                        <span class="label">Add'l Info</span>
                        <span class="value" style="font-size: 0.9rem;"><?= htmlspecialchars($wallet['description']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($view === 'bank' && $currentBank): ?>
    <!-- BANK VIEW -->

    <!-- Wallet Activity Summary for this Bank - REMOVED -->

    <hr>

    <!-- Bank's Monthly Summary -->
    <h2>Bank's Monthly Summary</h2>
    <?php
    $allBankMonths = [];
    $monthlyCopy = $queries->getBankMonthlySummary($id);
    while($m = $monthlyCopy->fetchArray(SQLITE3_ASSOC)) {
        $allBankMonths[] = $m;
    }

    $bankMonthsPerPage = 4;
    $totalBankMonths = count($allBankMonths);
    $bankMonthPage = isset($_GET['bank_month_page']) ? max(1, intval($_GET['bank_month_page'])) : 1;
    $bankMonthTotalPages = max(1, (int)ceil($totalBankMonths / $bankMonthsPerPage));
    $bankMonthOffset = ($bankMonthPage - 1) * $bankMonthsPerPage;
    $displayBankMonths = array_slice($allBankMonths, $bankMonthOffset, $bankMonthsPerPage);
    ?>

    <div class="grid monthly-grid">
        <?php foreach ($displayBankMonths as $r):
            $monthLink = htmlspecialchars($r['year']) . '-' . str_pad($r['month'], 2, '0', STR_PAD_LEFT);
        ?>
        <a href="?view=bank&id=<?= htmlspecialchars($id) ?>&month=<?= $monthLink ?>" style="text-decoration: none;">
            <div class="card month-card" style="cursor: pointer; transition: transform 200ms ease, box-shadow 200ms ease;">
                <div class="card-header"><?= htmlspecialchars($r['month']) ?>/<?= htmlspecialchars($r['year']) ?></div>
                <div class="card-body">
                    <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'], 2) ?></span></div>
                    <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'], 2) ?></span></div>
                    <div class="card-row"><span class="label">Net</span><span class="value">₹ <?= number_format($r['net'], 2) ?></span></div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Bank Monthly Pagination -->
    <?php if ($bankMonthTotalPages > 1): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($bankMonthPage > 1): ?>
                <a class="btn" href="?view=bank&id=<?= htmlspecialchars($id) ?>&bank_month_page=<?= $bankMonthPage-1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info">Month Page <?= $bankMonthPage ?> of <?= $bankMonthTotalPages ?> (<?= $totalBankMonths ?> months)</span>

            <?php if($bankMonthPage < $bankMonthTotalPages): ?>
                <a class="btn" href="?view=bank&id=<?= htmlspecialchars($id) ?>&bank_month_page=<?= $bankMonthPage+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <hr>

    <!-- Bank's Transactions -->
    <h2><?php if ($validMonth): ?>Transactions for <?= htmlspecialchars(date('F Y', strtotime($validMonth . '-01'))) ?> <a href="?view=bank&id=<?= htmlspecialchars($id) ?>" style="font-size: 0.8rem; margin-left: 10px;">← View All</a><?php else: ?>All Transactions (from all wallets in this bank)<?php endif; ?></h2>
    <div class="grid tx-grid">
        <?php while($r = $transactions->fetchArray(SQLITE3_ASSOC)): ?>
        <div class="card tx-card">
            <div class="tx-container">
                <div class="tx-left-section">
                    <div class="tx-title"><?= htmlspecialchars($r['title'] ?? 'Transaction') ?></div>
                    <div class="tx-date"><?= htmlspecialchars($r['date']) ?></div>
                    <?php if(!empty($r['wallet'])): ?>
                    <div class="tx-note" style="color: var(--text-muted); font-size: 0.85rem;">💳 <?= htmlspecialchars($r['wallet']) ?></div>
                    <?php endif; ?>
                    <?php if(!empty($r['note'])): ?>
                    <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="tx-right-section">
                    <div class="tx-menu-container">
                        <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-account="<?= htmlspecialchars($r['account_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-payment-method="<?= htmlspecialchars($r['payment_method'] ?? '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . ($r['wallet'] ?? 'Unknown')) ?>">⋮</button>
                        <div class="tx-menu-dropdown">
                            <button class="tx-menu-item tx-edit-option">✏️ Edit</button>
                            <button class="tx-menu-item tx-delete-option">🗑️ Delete</button>
                        </div>
                    </div>
                    <div class="type-badge <?= $r['type'] ?>"><?= ucfirst($r['type']) ?></div>
                    <div class="tx-amount">₹ <?= number_format($r['amount'], 2) ?></div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <!-- Pagination for all transactions view -->
    <?php if (!$selectedMonth): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>

            <?php if($page < $totalPages): ?>
                <a class="btn" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($view === 'wallet' && $currentWallet): ?>
    <!-- WALLET VIEW -->
    <!-- Add Transaction Button -->
    <div style="text-align: center; margin-bottom: 15px;">
        <button id="open-add" class="btn" type="button">➕ Add Transaction</button>
    </div>

    <!-- Wallet Type Header Section -->
    <?php
    $walletType = $currentWallet['wallet_type'] ?? 'balance';
    $currentBalance = $queries->getWalletBalance($id);
    ?>

    <?php if ($walletType === 'balance'): ?>
    <!-- RUNNING BALANCE WALLET - Balance already shown above, just show context -->
    <div class="wallet-instruction-box balance-instruction">
        <div class="instruction-text">✓ Balance updated with every transaction</div>
    </div>
    <?php else: ?>
    <!-- BUDGET TRACKER WALLET - Focus on Monthly Budget -->
    <div class="wallet-instruction-box budget-instruction">
        <div class="instruction-title">📊 Monthly Budget Tracking</div>
        <div class="instruction-main">✓ Set and track your monthly budget limit</div>
        <div class="instruction-subtitle">Each month resets independently - focus on staying within budget!</div>
    </div>
    <?php endif; ?>

    <!-- Monthly Summary for this Wallet -->
    <h2>Monthly Summary</h2>
    <?php
    $monthlySummary = $queries->getWalletMonthlySummary($id);
    $allMonths = [];
    while($m = $monthlySummary->fetchArray(SQLITE3_ASSOC)) {
        $allMonths[] = $m;
    }

    $monthsPerPage = 4;
    $totalMonths = count($allMonths);
    $monthPage = isset($_GET['month_page']) ? max(1, intval($_GET['month_page'])) : 1;
    $monthTotalPages = max(1, (int)ceil($totalMonths / $monthsPerPage));
    $monthOffset = ($monthPage - 1) * $monthsPerPage;
    $displayMonths = array_slice($allMonths, $monthOffset, $monthsPerPage);
    ?>

    <div class="monthly-grid">
        <?php
        foreach ($displayMonths as $index => $r):
            $monthLink = htmlspecialchars($r['year']) . '-' . str_pad($r['month'], 2, '0', STR_PAD_LEFT);

            // For Budget wallets: Get budget amount
            if ($walletType === 'budget') {
                $budgetData = $queries->getBudgetByWalletMonth($id, $r['year'], intval($r['month']));
                $budgetAmount = $budgetData['expected_expense'] ?? 0;
            } else {
                // For Balance wallets: Calculate previous month ending balance
                $previousMonthBalance = 0;
                if ($index === 0) {
                    // First month in list - calculate balance from all previous months
                    $stmt = $db->prepare(
                        "SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as balance
                         FROM transactions
                         WHERE wallet_id = ?
                         AND (
                            (strftime('%Y', date) < ? OR (strftime('%Y', date) = ? AND strftime('%m', date) < ?))
                         )"
                    );
                    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                    $stmt->bindValue(2, $r['year'], SQLITE3_TEXT);
                    $stmt->bindValue(3, $r['year'], SQLITE3_TEXT);
                    $stmt->bindValue(4, str_pad($r['month'], 2, '0', STR_PAD_LEFT), SQLITE3_TEXT);
                    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    $previousMonthBalance = $result['balance'] ?? 0;
                } else {
                    // Use previous month's data (net is the change that month)
                    $prevMonth = $displayMonths[$index - 1];
                    // Recalculate from all months before current
                    $stmt = $db->prepare(
                        "SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as balance
                         FROM transactions
                         WHERE wallet_id = ?
                         AND (
                            (strftime('%Y', date) < ? OR (strftime('%Y', date) = ? AND strftime('%m', date) < ?))
                         )"
                    );
                    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                    $stmt->bindValue(2, $r['year'], SQLITE3_TEXT);
                    $stmt->bindValue(3, $r['year'], SQLITE3_TEXT);
                    $stmt->bindValue(4, str_pad($r['month'], 2, '0', STR_PAD_LEFT), SQLITE3_TEXT);
                    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                    $previousMonthBalance = $result['balance'] ?? 0;
                }
            }
        ?>
        <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month=<?= $monthLink ?>" style="text-decoration: none;">
            <div class="card month-card" style="cursor: pointer; transition: transform 200ms ease, box-shadow 200ms ease;">
                <div class="card-header"><?= htmlspecialchars($r['month']) ?>/<?= htmlspecialchars($r['year']) ?></div>
                <div class="card-body">
                    <?php if ($walletType === 'budget'): ?>
                        <!-- Budget Tracker: Show Budget + Income/Expense/Saved/Over -->
                        <div class="card-row"><span class="label">Budget</span><span class="value" style="color: #3498db;">₹ <?= number_format($budgetAmount, 2) ?></span></div>
                        <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'], 2) ?></span></div>
                        <?php
                            $saved = $budgetAmount - ($r['expense'] - $r['income']);
                            $isOver = $saved < 0;
                            $displayValue = abs($saved);
                            $label = $isOver ? 'Over' : 'Saved';
                            $color = $isOver ? '#e74c3c' : '#27ae60';
                        ?>
                        <div class="card-row"><span class="label"><?= $label ?></span><span class="value" style="color: <?= $color ?>;">₹ <?= number_format($displayValue, 2) ?></span></div>
                    <?php else: ?>
                        <!-- Running Balance: Show Previous Month Balance + Income/Expense/Net -->
                        <div class="card-row"><span class="label">Prev Bal</span><span class="value" style="color: #7f8c8d;">₹ <?= number_format($previousMonthBalance, 2) ?></span></div>
                        <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Net</span><span class="value">₹ <?= number_format($r['net'], 2) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Monthly Pagination -->
    <?php if ($monthTotalPages > 1): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($monthPage > 1): ?>
                <a class="btn" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month_page=<?= $monthPage-1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info">Month Page <?= $monthPage ?> of <?= $monthTotalPages ?> (<?= $totalMonths ?> months)</span>

            <?php if($monthPage < $monthTotalPages): ?>
                <a class="btn" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month_page=<?= $monthPage+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <hr>

    <!-- BUDGET TRACKER SECTION (Only show for budget wallets) -->
    <?php if ($walletType === 'budget'): ?>
    <!-- Budget Month Navigation -->
    <?php
    $currentYear = date('Y');
    $currentMonthNum = date('m');
    $currentMonthFormatted = date('Y-m');

    // Check if a specific month is requested via URL (either month or budget_month parameter)
    $selectedMonthParam = isset($_GET['month']) ? htmlspecialchars($_GET['month']) : (isset($_GET['budget_month']) ? htmlspecialchars($_GET['budget_month']) : null);

    if ($selectedMonthParam) {
        // Parse YYYY-MM format
        $parts = explode('-', $selectedMonthParam);
        if (count($parts) === 2) {
            $selectYear = intval($parts[0]);
            $selectMonthNum = intval($parts[1]);
            if ($selectMonthNum >= 1 && $selectMonthNum <= 12) {
                $currentYear = $selectYear;
                $currentMonthNum = $selectMonthNum;
                $currentMonthFormatted = $selectYear . '-' . str_pad($selectMonthNum, 2, '0', STR_PAD_LEFT);
            }
        }
    }

    // Calculate previous and next month for navigation
    $prevDate = new DateTime($currentMonthFormatted . '-01');
    $prevDate->modify('-1 month');
    $prevMonthFormatted = $prevDate->format('Y-m');

    $nextDate = new DateTime($currentMonthFormatted . '-01');
    $nextDate->modify('+1 month');
    $nextMonthFormatted = $nextDate->format('Y-m');

    $monthBudget = $queries->getBudgetByWalletMonth($id, $currentYear, $currentMonthNum);

    // Calculate current month actual figures
    // Zero-pad the month for database query (e.g., 3 becomes "03")
    $monthPadded = str_pad($currentMonthNum, 2, '0', STR_PAD_LEFT);
    $currentMonthActual = $queries->getWalletMonthlyTransactions($id, $currentYear, $monthPadded);
    $actualIncome = 0;
    $actualExpense = 0;
    while($txRow = $currentMonthActual->fetchArray(SQLITE3_ASSOC)) {
        if ($txRow['type'] === 'income') {
            $actualIncome += $txRow['amount'];
        } else {
            $actualExpense += $txRow['amount'];
        }
    }
    $actualNet = $actualIncome - $actualExpense;
    ?>

    <!-- Budget Card for Selected Month -->
    <div class="budget-nav-wrapper">
        <h2 style="margin: 0;">Budget & Actuals</h2>
        <div class="budget-nav-controls">
            <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month=<?= htmlspecialchars($prevMonthFormatted) ?>" class="btn btn-nav-compact">← Prev</a>
            <span class="budget-nav-month">
                <?= htmlspecialchars(date('F Y', strtotime($currentMonthFormatted . '-01'))) ?>
            </span>
            <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month=<?= htmlspecialchars($nextMonthFormatted) ?>" class="btn btn-nav-compact">Next →</a>
        </div>
    </div>
    <div class="budget-container">
        <div class="card budget-card" data-budget-id="<?= htmlspecialchars($monthBudget['id'] ?? '') ?>">
            <div class="card-header budget-card-header">
                <strong>📊 Budget: <?= htmlspecialchars(date('F Y', strtotime($currentMonthFormatted . '-01'))) ?></strong>
                <?php if ($monthBudget): ?>
                <button id="budget-edit-btn" class="btn-small">✏️ Edit</button>
                <?php else: ?>
                <button id="budget-add-btn" class="btn-small">➕ Set Budget</button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="budget-grid">
                    <?php
                    $expectedIncome = $monthBudget['expected_income'] ?? 0;
                    $expectedExpense = $monthBudget['expected_expense'] ?? 0;
                    $varIncome = $actualIncome - $expectedIncome;
                    $varExpense = $expectedExpense - $actualExpense;
                    $varNet = $actualNet - ($expectedIncome - $expectedExpense);
                    $hasIncome = $expectedIncome > 0 || $actualIncome > 0;
                    $isExpenseOnly = !$hasIncome && $expectedExpense > 0;

                    // Income offsets expenses - so net spending = Expense - Income
                    $netSpending = $actualExpense - $actualIncome;
                    $budgetRemaining = $expectedExpense - $netSpending;
                    $budgetPercent = $expectedExpense > 0 ? max(0, ($netSpending / $expectedExpense) * 100) : 0;

                    // Only consider over budget if a budget was actually set AND net spending exceeds expected
                    $isOverBudget = $monthBudget && $expectedExpense > 0 && $netSpending > $expectedExpense;
                    ?>

                    <!-- UNIFIED BUDGET CARD (Progress Bar Format with Income Details) -->
                    <!-- Budget Limit Column -->
                    <div class="budget-column" data-budget-type="limit" data-budget-expected-expense="<?= htmlspecialchars($expectedExpense) ?>">
                        <div class="budget-col-header">💰 Budget Limit</div>
                        <?php if ($hasIncome): ?>
                        <div class="budget-row">
                            <span class="label">Expected Income</span>
                            <span class="value neutral">₹ <?= number_format($expectedIncome, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="budget-row">
                            <span class="label">Budget Expense</span>
                            <span class="value neutral">₹ <?= number_format($expectedExpense, 2) ?></span>
                        </div>
                        <div class="budget-progress-bar" style="margin: 8px 0;">
                            <?php
                            // Calculate color based on budget percentage
                            // Green (0-50%) → Yellow (50-70%) → Orange (70-85%) → Red (85%+)
                            $barColor = '#27ae60'; // Default green
                            if ($budgetPercent >= 50 && $budgetPercent < 70) {
                                $ratio = ($budgetPercent - 50) / 20;
                                $r = intval(39 + (243 - 39) * $ratio);
                                $g = intval(174 + (156 - 174) * $ratio);
                                $b = intval(96 + (18 - 96) * $ratio);
                                $barColor = sprintf('#%02x%02x%02x', $r, $g, $b);
                            } elseif ($budgetPercent >= 70 && $budgetPercent < 85) {
                                $ratio = ($budgetPercent - 70) / 15;
                                $r = intval(243 + (230 - 243) * $ratio);
                                $g = intval(156 + (100 - 156) * $ratio);
                                $b = intval(18 + (35 - 18) * $ratio);
                                $barColor = sprintf('#%02x%02x%02x', $r, $g, $b);
                            } elseif ($budgetPercent >= 85 && $budgetPercent < 100) {
                                $ratio = ($budgetPercent - 85) / 15;
                                $r = intval(230 + (231 - 230) * $ratio);
                                $g = intval(100 + (60 - 100) * $ratio);
                                $b = intval(35 + (60 - 35) * $ratio);
                                $barColor = sprintf('#%02x%02x%02x', $r, $g, $b);
                            } elseif ($budgetPercent >= 100) {
                                $barColor = '#c0392b';
                            }
                            ?>
                            <div class="budget-progress-fill" style="width: <?= min($budgetPercent, 100) ?>%; background: <?= $barColor ?>; transition: background 300ms ease;"></div>
                        </div>
                        <div class="budget-row" style="font-size: 0.85rem; color: var(--text-muted);">
                            <?= number_format($budgetPercent, 1) ?>% used
                        </div>
                    </div>

                    <!-- Actual Column -->
                    <div class="budget-column">
                        <div class="budget-col-header">💳 Actual</div>
                        <?php if ($hasIncome): ?>
                        <div class="budget-row">
                            <span class="label">Actual Income</span>
                            <span class="value income">₹ <?= number_format($actualIncome, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="budget-row">
                            <span class="label"><?= $hasIncome ? 'Actual Expense' : 'Spent' ?></span>
                            <span class="value expense">₹ <?= number_format($actualExpense, 2) ?></span>
                        </div>
                        <?php if ($hasIncome): ?>
                        <div class="budget-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">
                            <span class="label">Net Spending</span>
                            <span class="value <?= $netSpending >= 0 ? 'expense' : 'income' ?>" style="font-weight: bold;">₹ <?= number_format(abs($netSpending), 2) ?></span>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; text-align: right;">
                                (Expense - Income)
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="budget-row" style="margin-top: 8px;">
                            <span style="font-size: 0.9rem; color: var(--text-muted);">Out of ₹<?= number_format($expectedExpense, 2) ?> budget</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Variance/Status Column -->
                    <div class="budget-column">
                        <div class="budget-col-header"><?= $isOverBudget ? '🔴 Over Budget' : '✅ Budget Left' ?></div>
                        <?php if ($hasIncome): ?>
                        <div class="budget-row">
                            <span class="label">Income Variance</span>
                            <span class="value <?= $varIncome >= 0 ? 'income' : 'expense' ?>">₹ <?= number_format(abs($varIncome), 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="budget-row">
                            <span class="label"><?= $isOverBudget ? 'Exceeded By' : 'Remaining' ?></span>
                            <span class="value <?= $isOverBudget ? 'expense' : 'income' ?>" style="font-size: 1.1rem;">
                                <?php $absRemaining = abs($budgetRemaining); ?>
                                <?= ($isOverBudget && $absRemaining > 0) ? '-' : '' ?>₹ <?= number_format($absRemaining, 2) ?>
                            </span>
                        </div>
                        <div class="budget-row" style="margin-top: 8px; font-size: 0.9rem;">
                            <?php if ($isOverBudget): ?>
                                <span style="color: #e74c3c;">⚠️ Spent ₹<?= number_format(abs($budgetRemaining), 2) ?> extra!</span>
                            <?php else: ?>
                                <span style="color: #27ae60;">✅ Can spend ₹<?= number_format($budgetRemaining, 2) ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($monthBudget && !empty($monthBudget['notes'])): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border);">
                    <strong>Notes:</strong> <?= htmlspecialchars($monthBudget['notes']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- END BUDGET TRACKER SECTION -->

    <hr>

    <!-- Transactions -->
    <h2><?php if ($validMonth): ?>Transactions for <?= htmlspecialchars(date('F Y', strtotime($validMonth . '-01'))) ?> <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>" style="font-size: 0.8rem; margin-left: 10px;">← View All</a><?php else: ?>All Transactions<?php endif; ?></h2>
    <div class="tx-grid">
        <?php
        if ($transactions):
            while($r = $transactions->fetchArray(SQLITE3_ASSOC)):
        ?>
            <div class="card tx-card">
                <div class="tx-container">
                    <div class="tx-left-section">
                        <div class="tx-title"><?= htmlspecialchars($r['title'] ?? 'Transaction') ?></div>
                        <div class="tx-date"><?= htmlspecialchars($r['date']) ?></div>
                        <?php if(!empty($r['payment_method'])): ?>
                        <div class="tx-note" style="color: var(--text-muted); font-size: 0.85rem;">💳 <?= htmlspecialchars($r['payment_method']) ?></div>
                        <?php endif; ?>
                        <?php if(!empty($r['note'])): ?>
                        <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="tx-right-section">
                        <div class="tx-menu-container">
                            <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-account="<?= htmlspecialchars($r['account_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-payment-method="<?= htmlspecialchars($r['payment_method'] ?? '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . $currentWallet['name']) ?>">⋮</button>
                            <div class="tx-menu-dropdown">
                                <button class="tx-menu-item tx-edit-option">✏️ Edit</button>
                                <button class="tx-menu-item tx-delete-option">🗑️ Delete</button>
                            </div>
                        </div>
                        <div class="type-badge <?= $r['type'] ?>"><?= ucfirst($r['type']) ?></div>
                        <div class="tx-amount">₹ <?= number_format($r['amount'], 2) ?></div>
                    </div>
                </div>
            </div>
        <?php
            endwhile;
        else:
            echo '<p class="empty-state">No transactions.</p>';
        endif;
        ?>
    </div>

    <!-- Pagination for all transactions view -->
    <?php if (!$selectedMonth): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>

            <?php if($page < $totalPages): ?>
                <a class="btn" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Add/Edit Transaction Modal -->
<div id="modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <h3 id="modal-title">Add Transaction</h3>
            <button id="modal-close" class="theme-toggle">✕</button>
        </div>
        <form id="tx-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="tx-id" name="tx_id" value="">
            <input type="hidden" id="tx-action" name="action" value="tx_add">
            <input type="hidden" id="tx-wallet-id" name="wallet_id" value="<?= ($view === 'wallet' && $id > 0) ? htmlspecialchars($id) : '' ?>">
            <input type="hidden" id="tx-bank-id" name="bank_id" value="<?= ($view === 'bank' && $id > 0) ? htmlspecialchars($id) : '' ?>">
            <input type="hidden" id="tx-budget-month" name="budget_month" value="<?= isset($_GET['month']) ? htmlspecialchars($_GET['month']) : (isset($_GET['budget_month']) ? htmlspecialchars($_GET['budget_month']) : '') ?>">>

            <div class="form-group">
                <label for="m_date">Date</label>
                <input id="m_date" type="date" name="date" required>
            </div>
            <div class="form-group">
                <label for="m_title">Title/Name</label>
                <input id="m_title" type="text" name="title" placeholder="e.g., Grocery, Fuel, Salary" required>
            </div>
            <div class="form-group">
                <label for="m_type">Type</label>
                <select id="m_type" name="type" required>
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
            </div>
            <div class="form-group">
                <label for="m_amount">Amount</label>
                <input id="m_amount" type="number" step="0.01" name="amount" required>
            </div>
            <div class="form-group">
                <label for="m_payment_method">
                    <span id="payment-method-label">From:</span>
                </label>
                <select id="m_payment_method" name="payment_method" required>
                    <option value="">-- Select Bank --</option>
                    <?php
                    // Get all banks from database
                    $allBanks = $queries->getAllBanks();
                    while($bank = $allBanks->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($bank['name']) ?>"><?= htmlspecialchars($bank['name']) ?></option>
                    <?php } ?>
                </select>
                <small style="color: var(--text-muted); margin-top: 4px; display: block;" id="payment-method-hint">
                    Where the payment will be deducted from
                </small>
            </div>
            <?php if ($view !== 'wallet'): ?>
            <div class="form-group">
                <label for="m_wallet">Wallet</label>
                <select id="m_wallet" name="wallet" required>
                    <option value="">-- Select Wallet --</option>
                    <?php
                    $allWallets = $queries->getAllWallets();
                    while($w = $allWallets->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($w['id']) ?>"><?= htmlspecialchars($w['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <?php else: ?>
            <!-- When in wallet view, wallet is auto-selected via hidden input -->
            <input type="hidden" id="m_wallet" name="wallet" value="<?= htmlspecialchars($id) ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="m_account">Account</label>
                <select id="m_account" name="account" required>
                    <option value="">-- Select Account --</option>
                    <?php
                    // Reset the result pointer for reuse
                    $accountsResult = $queries->getAllAccounts();
                    while($a = $accountsResult->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($a['id']) ?>"><?= htmlspecialchars($a['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="m_note">Note</label>
                <input id="m_note" type="text" name="note" placeholder="Optional notes...">
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">💾 Save</button>
                <button type="button" id="modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Wallet Modal -->
<div id="wallet-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="wallet-modal-title">
        <div class="modal-header">
            <h3 id="wallet-modal-title">Add Wallet</h3>
            <button id="wallet-modal-close" class="theme-toggle">✕</button>
        </div>
        <form id="wallet-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="wallet-id" name="wallet_id" value="">
            <input type="hidden" id="wallet-action" name="action" value="wallet_add">

            <div class="form-group">
                <label for="wallet-name">Wallet Name</label>
                <input id="wallet-name" type="text" name="name" required>
            </div>
            <div class="form-group">
                <label for="wallet-description">Description</label>
                <input id="wallet-description" type="text" name="description" placeholder="Optional description...">
            </div>
            <div class="form-group">
                <label for="wallet-type">Wallet Type</label>
                <select id="wallet-type" name="wallet_type" required>
                    <option value="balance">💳 Running Balance Wallet (tracks money over months)</option>
                    <option value="budget">📊 Monthly Budget Tracker (budget vs spending each month)</option>
                </select>
                <small style="color: var(--text-muted); margin-top: 4px; display: block;">
                    <strong>Balance Wallet:</strong> For tracking actual money with income/expenses<br>
                    <strong>Budget Tracker:</strong> For monthly budget limits and spending only
                </small>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">💾 Save Wallet</button>
                <button type="button" id="wallet-modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Bank Modal -->
<div id="bank-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="bank-modal-title">
        <div class="modal-header">
            <h3 id="bank-modal-title">Add Bank</h3>
            <button id="bank-modal-close" class="theme-toggle">✕</button>
        </div>
        <form id="bank-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="bank-id" name="bank_id" value="">
            <input type="hidden" id="bank-action" name="action" value="bank_add">

            <div class="form-group">
                <label for="bank-name">Bank Name</label>
                <input id="bank-name" type="text" name="name" required>
            </div>
            <div class="form-group">
                <label for="bank-description">Description</label>
                <input id="bank-description" type="text" name="description" placeholder="Optional description...">
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">💾 Save Bank</button>
                <button type="button" id="bank-modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Budget Modal -->
<div id="budget-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="budget-modal-title">
        <div class="modal-header">
            <h3 id="budget-modal-title">Set Budget</h3>
            <button id="budget-modal-close" class="theme-toggle">✕</button>
        </div>
        <form id="budget-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="budget-id" name="budget_id" value="">
            <input type="hidden" id="budget-action" name="action" value="budget_add">
            <input type="hidden" id="budget-wallet-id" name="wallet_id" value="">
            <input type="hidden" id="budget-year" name="year" value="">
            <input type="hidden" id="budget-month" name="month" value="">
            <input type="hidden" id="budget-month-param" name="budget_month" value="">

            <div class="form-group">
                <label>Month & Year</label>
                <div id="budget-month-display" style="padding: 10px; background: var(--surface-alt); border-radius: 4px; font-weight: 600;"></div>
            </div>

            <div class="form-group">
                <label for="budget-expected-income">Expected Income (₹) <span style="color: var(--muted); font-weight: normal;">Optional</span></label>
                <input id="budget-expected-income" type="number" step="0.01" name="expected_income" placeholder="Leave blank if not needed...">
            </div>

            <div class="form-group">
                <label for="budget-expected-expense">Expected Expense (₹) <span style="color: var(--muted); font-weight: normal;">Required</span></label>
                <input id="budget-expected-expense" type="number" step="0.01" name="expected_expense" value="0" required>
            </div>

            <div class="form-group">
                <label for="budget-notes">Notes</label>
                <textarea id="budget-notes" name="notes" rows="3" placeholder="e.g., Planned shopping, medical bills expected..."></textarea>
            </div>

            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">💾 Save Budget</button>
                <button type="button" id="budget-modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Monthly Statement Modal - Bank -->
<div id="bank-monthly-statement-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="bank-monthly-statement-title">
        <div class="modal-header">
            <h3 id="bank-monthly-statement-title">Download Monthly Statement</h3>
            <button type="button" class="theme-toggle" onclick="document.getElementById('bank-monthly-statement-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadBankMonthlyStatement(event)">
            <div class="form-group">
                <label for="bank-month-select">Select Month & Year</label>
                <select id="bank-month-select" required>
                    <option value="">-- Select Month --</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn">📥 Download</button>
                <button type="button" class="back-link" onclick="document.getElementById('bank-monthly-statement-modal').classList.remove('open');">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Date Range Modal - Bank -->
<div id="bank-custom-range-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="bank-custom-range-title">
        <div class="modal-header">
            <h3 id="bank-custom-range-title">Download Statement - Custom Range</h3>
            <button type="button" class="theme-toggle" onclick="document.getElementById('bank-custom-range-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadBankCustomRange(event)">
            <div class="form-group">
                <label for="bank-from-date">From Date</label>
                <input id="bank-from-date" type="date" required>
            </div>
            <div class="form-group">
                <label for="bank-to-date">To Date</label>
                <input id="bank-to-date" type="date" required>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn">📥 Download</button>
                <button type="button" class="back-link" onclick="document.getElementById('bank-custom-range-modal').classList.remove('open');">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Monthly Statement Modal - Wallet -->
<div id="wallet-monthly-statement-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="wallet-monthly-statement-title">
        <div class="modal-header">
            <h3 id="wallet-monthly-statement-title">Download Monthly Statement</h3>
            <button type="button" class="theme-toggle" onclick="document.getElementById('wallet-monthly-statement-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadWalletMonthlyStatement(event)">
            <div class="form-group">
                <label for="wallet-month-select">Select Month & Year</label>
                <select id="wallet-month-select" required>
                    <option value="">-- Select Month --</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn">📥 Download</button>
                <button type="button" class="back-link" onclick="document.getElementById('wallet-monthly-statement-modal').classList.remove('open');">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Date Range Modal - Wallet -->
<div id="wallet-custom-range-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="wallet-custom-range-title">
        <div class="modal-header">
            <h3 id="wallet-custom-range-title">Download Statement - Custom Range</h3>
            <button type="button" class="theme-toggle" onclick="document.getElementById('wallet-custom-range-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadWalletCustomRange(event)">
            <div class="form-group">
                <label for="wallet-from-date">From Date</label>
                <input id="wallet-from-date" type="date" required>
            </div>
            <div class="form-group">
                <label for="wallet-to-date">To Date</label>
                <input id="wallet-to-date" type="date" required>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn">📥 Download</button>
                <button type="button" class="back-link" onclick="document.getElementById('wallet-custom-range-modal').classList.remove('open');">Cancel</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>

<script src="resource/js/theme.js"></script>
<script src="resource/js/modal.js"></script>
<script src="resource/js/transactions.js"></script>
<script src="resource/js/wallets.js"></script>
<script src="resource/js/statements.js"></script>
<script src="resource/js/banks.js"></script>
<script src="resource/js/budget.js"></script>
<script>
// Wallet modal opener for main add button
document.addEventListener('DOMContentLoaded', function() {
    const openWalletBtn = document.getElementById('open-add-wallet');
    if (openWalletBtn) {
        openWalletBtn.addEventListener('click', function() {
            // If on a bank view, pre-select the current bank
            const bankId = this.dataset.bank || '';
            window.openWalletModal('add', null, '', bankId, '');
        });
    }

    const openBankBtn = document.getElementById('open-add-bank');
    if (openBankBtn) {
        openBankBtn.addEventListener('click', function() {
            window.openBankModal('add');
        });
    }

    // Set default date to today
    const dateInput = document.getElementById('m_date');
    if (dateInput) {
        dateInput.valueAsDate = new Date();
    }

    // Month selector for wallet view
    const monthSelect = document.getElementById('month-select');
    if (monthSelect) {
        monthSelect.addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('month', this.value);
            window.location.href = url.toString();
        });
    }
});
</script>


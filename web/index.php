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
    <a href="index.php" class="breadcrumb-link">Home</a>
    <?php if ($view === 'bank' && $currentBank): ?>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">🏦 <?= htmlspecialchars($currentBank['name']) ?></span>
    <?php elseif ($view === 'wallet' && $currentWallet): ?>
        <span class="breadcrumb-sep">›</span>
        <a href="index.php?view=bank&id=<?= htmlspecialchars($currentWallet['bank_id']) ?>" class="breadcrumb-link">🏦 <?php
            $b = $queries->getBankById($currentWallet['bank_id']);
            echo htmlspecialchars($b['name'] ?? 'Bank');
        ?></a>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">💳 <?= htmlspecialchars($currentWallet['name']) ?></span>
    <?php endif; ?>
</div>

<!-- Balance Display -->
<div class="balance-card">
    <h2>Balance</h2>
    <div class="balance-amount">₹ <?= number_format($balance, 2) ?></div>
</div>

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
                <div class="card-row">
                    <span class="label">Wallets</span>
                    <span class="value"><?= $bank['wallet_count'] ?></span>
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

    <!-- Wallets Grid (Independent) -->
    <h2>Wallets<span class="section-subtitle">(all wallets across all banks)</span></h2>
    <div class="card-grid">
        <?php
        $walletsResult = $queries->getAllWalletsWithDetails();
        while ($wallet = $walletsResult->fetchArray(SQLITE3_ASSOC)):
        ?>
        <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-bank-id="<?= htmlspecialchars($wallet['bank_id'] ?? '') ?>">
            <div class="card-header">
                <strong>💳 <?= htmlspecialchars($wallet['name']) ?></strong>
                <div class="card-menu-container">
                    <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank="<?= htmlspecialchars($wallet['bank_id'] ?? '') ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                    <div class="card-menu-dropdown">
                        <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                        <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank="<?= htmlspecialchars($wallet['bank_id'] ?? '') ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">✏️ Edit</button>
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
                    <span class="label">Bank</span>
                    <span class="value"><?= htmlspecialchars($wallet['bank_name']) ?></span>
                </div>
                <?php if (!empty($wallet['description'])): ?>
                <div class="card-row">
                    <span class="label">Add'l Info</span>
                    <span class="value" style="font-size: 0.9rem;"><?= htmlspecialchars($wallet['description']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

<?php elseif ($view === 'bank' && $currentBank): ?>
    <!-- BANK VIEW -->
    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
        <button id="open-add-wallet" class="btn" type="button" data-bank="<?= htmlspecialchars($currentBank['id']) ?>">💳 Add Wallet to this Bank</button>
        <button class="btn bank-edit-btn" data-id="<?= htmlspecialchars($currentBank['id']) ?>" data-name="<?= htmlspecialchars($currentBank['name']) ?>" data-description="<?= htmlspecialchars($currentBank['description'] ?? '') ?>" type="button">🏦 Edit Bank</button>
    </div>

    <!-- Wallets in this bank -->
    <h2>Wallets in <?= htmlspecialchars($currentBank['name']) ?></h2>
    <div class="card-grid">
        <?php
        $walletsInBank = $queries->getWalletsByBankWithDetails($id);
        $hasWallets = false;
        while ($wallet = $walletsInBank->fetchArray(SQLITE3_ASSOC)):
            $hasWallets = true;
        ?>
        <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-bank-id="<?= htmlspecialchars($wallet['bank_id']) ?>">
            <div class="card-header">
                <strong>💳 <?= htmlspecialchars($wallet['name']) ?></strong>
                <div class="card-menu-container">
                    <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank="<?= htmlspecialchars($wallet['bank_id']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                    <div class="card-menu-dropdown">
                        <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                        <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank="<?= htmlspecialchars($wallet['bank_id']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">✏️ Edit</button>
                        <button class="card-menu-item card-delete-danger wallet-delete-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>">🗑️ Delete</button>
                    </div>
                </div>
            </div>
            <div class="card-body card-clickable" data-href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>">
                <div class="card-row">
                    <span class="label">Balance</span>
                    <span class="value">₹ <?= number_format($wallet['balance'], 2) ?></span>
                </div>
                <?php if (!empty($wallet['description'])): ?>
                <div class="card-row">
                    <span class="label">Add'l Info</span>
                    <span class="value" style="font-size: 0.9rem;"><?= htmlspecialchars($wallet['description']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <?php if (!$hasWallets): ?>
        <p class="empty-state">No wallets in this bank yet. <a href="index.php?view=bank&id=<?= htmlspecialchars($id) ?>">Add one now</a>!</p>
    <?php endif; ?>

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
                    <div class="tx-title"><?= htmlspecialchars($r['title'] ?? $r['wallet'] ?? 'Transaction') ?></div>
                    <div class="tx-date"><?= htmlspecialchars($r['date']) ?></div>
                    <?php if(!empty($r['note'])): ?>
                    <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="tx-right-section">
                    <div class="tx-menu-container">
                        <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-account="<?= htmlspecialchars($r['account_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . ($r['wallet'] ?? 'Unknown')) ?>">⋮</button>
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
    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
        <button id="open-add" class="btn" type="button">➕ Add Transaction</button>
        <button class="btn wallet-edit-btn" data-id="<?= htmlspecialchars($id) ?>" data-name="<?= htmlspecialchars($currentWallet['name']) ?>" data-bank="<?= htmlspecialchars($currentWallet['bank_id']) ?>" data-description="<?= htmlspecialchars($currentWallet['description'] ?? '') ?>">✏️ Edit Wallet</button>
    </div>



    <!-- Monthly Summary for this Wallet -->
    <h2>Monthly Summary</h2>
    <?php
    $monthlySummary = $queries->getWalletMonthlySummary($id);
    $allMonths = [];
    while($m = $monthlySummary->fetchArray(SQLITE3_ASSOC)) {
        $allMonths[] = $m;
    }

    $monthsPerPage = 6;
    $totalMonths = count($allMonths);
    $monthPage = isset($_GET['month_page']) ? max(1, intval($_GET['month_page'])) : 1;
    $monthTotalPages = max(1, (int)ceil($totalMonths / $monthsPerPage));
    $monthOffset = ($monthPage - 1) * $monthsPerPage;
    $displayMonths = array_slice($allMonths, $monthOffset, $monthsPerPage);
    ?>

    <div class="monthly-grid">
        <?php
        foreach ($displayMonths as $r):
            $monthLink = htmlspecialchars($r['year']) . '-' . str_pad($r['month'], 2, '0', STR_PAD_LEFT);
        ?>
        <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month=<?= $monthLink ?>" style="text-decoration: none;">
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
                        <?php if(!empty($r['note'])): ?>
                        <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="tx-right-section">
                        <div class="tx-menu-container">
                            <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-account="<?= htmlspecialchars($r['account_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . $currentWallet['name']) ?>">⋮</button>
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
                <label for="wallet-bank">Bank</label>
                <select id="wallet-bank" name="bank_id" required>
                    <option value="">-- Select Bank --</option>
                    <?php
                    $allBanks = $queries->getAllBanks();
                    while($b = $allBanks->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($b['id']) ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="wallet-description">Description</label>
                <input id="wallet-description" type="text" name="description" placeholder="Optional description...">
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

</body>
</html>

<script src="resource/js/theme.js"></script>
<script src="resource/js/modal.js"></script>
<script src="resource/js/transactions.js"></script>
<script src="resource/js/wallets.js"></script>
<script src="resource/js/banks.js"></script>
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


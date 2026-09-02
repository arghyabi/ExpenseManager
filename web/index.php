<?php
session_start();
require 'queries.php';

// Generate CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$flash_error = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : '';
if ($flash_error !== '') {
    unset($_SESSION['flash_error']);
}

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
// Normalise unknown views to main (prevents blank page)
if (!in_array($view, ['main', 'bank', 'wallet', 'categories', 'recurring', 'audit', 'reminders'])) {
    $view = 'main';
}

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

# -----------------------------------------------------------------------
# Read search/filter params (used by both bank and wallet views)
# -----------------------------------------------------------------------
$filterQ        = isset($_GET['q'])           ? trim($_GET['q'])               : '';
$filterType     = isset($_GET['ftype'])       ? trim($_GET['ftype'])           : '';
$filterCat      = isset($_GET['fcat'])        ? intval($_GET['fcat'])          : 0;
$filterDateFrom = isset($_GET['date_from'])   ? trim($_GET['date_from'])       : '';
$filterDateTo   = isset($_GET['date_to'])     ? trim($_GET['date_to'])         : '';

// Sanitise type
if (!in_array($filterType, ['income', 'expense', ''])) $filterType = '';
// Sanitise dates
if ($filterDateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) $filterDateFrom = '';
if ($filterDateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo))   $filterDateTo   = '';

$txFilters = [
    'q'           => $filterQ,
    'type'        => $filterType,
    'category_id' => $filterCat,
    'date_from'   => $filterDateFrom,
    'date_to'     => $filterDateTo,
];
$hasActiveFilter = ($filterQ !== '' || $filterType !== '' || $filterCat !== 0 || $filterDateFrom !== '' || $filterDateTo !== '');

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
            list($selectedYear, $selectedMonthOnly) = explode('-', $selectedMonth);
            $selectedYear = intval($selectedYear);
            $selectedMonthOnly = intval($selectedMonthOnly);

            if ($selectedMonthOnly >= 1 && $selectedMonthOnly <= 12) {
                $validMonth = $selectedMonth;
                $transactions = $queries->getBankMonthlyTransactions($id, $selectedYear, str_pad($selectedMonthOnly, 2, '0', STR_PAD_LEFT));
                $totalCount = 0;
                $totalPages = 1;
            } else {
                $totalCount = $queries->getBankTransactionCount($id);
                $totalPages = max(1, (int)ceil($totalCount / $perPage));
                $transactions = $queries->getBankTransactions($id, $page, $perPage);
            }
        } elseif ($hasActiveFilter) {
            # Filtered search
            $totalCount = $queries->searchBankTransactionCount($id, $txFilters);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $transactions = $queries->searchBankTransactions($id, $txFilters, $page, $perPage);
        } else {
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

        $selectedMonth = isset($_GET['month']) ? $_GET['month'] : null;
        $validMonth = null;

        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            list($selectedYear, $selectedMonthOnly) = explode('-', $selectedMonth);
            $selectedYear = intval($selectedYear);
            $selectedMonthOnly = intval($selectedMonthOnly);

            if ($selectedMonthOnly >= 1 && $selectedMonthOnly <= 12) {
                $validMonth = $selectedMonth;
                $transactions = $queries->getWalletMonthlyTransactions($id, $selectedYear, str_pad($selectedMonthOnly, 2, '0', STR_PAD_LEFT));
                $totalCount = 0;
                $totalPages = 1;
            } else {
                $totalCount = $queries->getWalletTransactionCount($id);
                $totalPages = max(1, (int)ceil($totalCount / $perPage));
                $transactions = $queries->getWalletTransactions($id, $page, $perPage);
            }
        } elseif ($hasActiveFilter) {
            # Filtered search
            $totalCount = $queries->searchWalletTransactionCount($id, $txFilters);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $transactions = $queries->searchWalletTransactions($id, $txFilters, $page, $perPage);
        } else {
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

# -----------------------------------------------------------------------
# Dashboard chart data (main view only)
# -----------------------------------------------------------------------
$dashMonthly  = [];
$dashCategory = [];
if ($view === 'main') {
    $dashMonthly  = $queries->getGlobalMonthlySummary(6);
    $dashCategory = $queries->getGlobalCategoryBreakdown(6);
}

# -----------------------------------------------------------------------
# Run recurring rule due-processing on every page load
# -----------------------------------------------------------------------
$recurringPosted = $queries->processRecurringRules();

# Load recurring rules list (only needed for the recurring view)
$recurringRules = [];
if ($view === 'recurring') {
    $rr = $queries->getAllRecurringRules();
    while ($rule = $rr->fetchArray(SQLITE3_ASSOC)) {
        $recurringRules[] = $rule;
    }
}

# Load bill reminders list (only for reminders view)
$billReminders = [];
if ($view === 'reminders') {
    $br = $queries->getAllBillReminders();
    while ($r = $br->fetchArray(SQLITE3_ASSOC)) {
        $billReminders[] = $r;
    }
}

# Load due reminders for notification bar (main view only, lightweight)
$dueBillReminders = [];
if ($view === 'main') {
    $dueBillReminders = $queries->getDueBillReminders();
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Manager</title>
    <link rel="stylesheet" href="resource/css/style.css?v=<?= htmlspecialchars($version) ?>">
    <link rel="icon" href="resource/images/icon.png">
    <script>
        // Expose CSRF token to JavaScript for use in dynamic forms
        window.csrfToken = '<?= htmlspecialchars($csrf_token) ?>';
        // Expose wallet's default bank id for pre-selecting payment method
        window.walletDefaultBank = '<?= ($view === 'wallet' && $currentWallet && !empty($currentWallet['bank_id'])) ? htmlspecialchars($currentWallet['bank_id']) : '' ?>';
        // Expose current bank id (bank view) so the modal can pre-select it
        window.currentBankName = '<?= ($view === 'bank' && $currentBank) ? htmlspecialchars($currentBank['id']) : '' ?>';
    </script>
</head>
<body>

<div class="app-header">
    <h1>💰 Expense Manager <?php if($version) echo '<span class="version">' . htmlspecialchars($version) . '</span>'; ?></h1>
    <div>
        <button id="theme-toggle" class="theme-toggle">🌙</button>
    </div>
</div>

<?php if (!empty($flash_error)): ?>
<div class="flash-error" role="alert" aria-live="polite">
    ⚠ <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<?php if ($view === 'main' && !empty($dueBillReminders)): ?>
<!-- Bill Payment Notification Banner -->
<div class="bill-notif-bar" id="bill-notif-bar">
    <div class="bill-notif-header">
        <span class="bill-notif-icon">🔔</span>
        <span class="bill-notif-title">Pending Bills</span>
        <span class="bill-notif-count"><?= count($dueBillReminders) ?></span>
    </div>
    <div class="bill-notif-list">
        <?php foreach ($dueBillReminders as $notif): ?>
        <button class="bill-notif-item"
                type="button"
                data-reminder-id="<?= htmlspecialchars($notif['id']) ?>"
                data-title="<?= htmlspecialchars($notif['title']) ?>"
                data-type="<?= htmlspecialchars($notif['type']) ?>"
                data-default-amount="<?= htmlspecialchars($notif['default_amount']) ?>"
                data-wallet-id="<?= htmlspecialchars($notif['wallet_id'] ?? '') ?>"
                data-wallet-name="<?= htmlspecialchars($notif['wallet_name'] ?? '') ?>"
                data-bank-id="<?= htmlspecialchars($notif['payment_bank_id'] ?? '') ?>"
                data-bank-name="<?= htmlspecialchars($notif['bank_name'] ?? '') ?>"
                data-category-id="<?= htmlspecialchars($notif['category_id'] ?? '') ?>"
                data-note="<?= htmlspecialchars($notif['note'] ?? '') ?>">
            <span class="bill-notif-item-name"><?= htmlspecialchars($notif['title']) ?></span>
            <?php if ($notif['bank_name']): ?>
            <span class="bill-notif-item-bank">🏦 <?= htmlspecialchars($notif['bank_name']) ?></span>
            <?php endif; ?>
            <?php if ($notif['wallet_name']): ?>
            <span class="bill-notif-item-bank">💳 <?= htmlspecialchars($notif['wallet_name']) ?></span>
            <?php endif; ?>
            <span class="bill-notif-item-arrow">›</span>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

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
                <button class="bank-menu-item bank-edit-option" data-id="<?= htmlspecialchars($currentBank['id']) ?>" data-name="<?= htmlspecialchars($currentBank['name']) ?>" data-description="<?= htmlspecialchars($currentBank['description'] ?? '') ?>" data-opening-balance="<?= htmlspecialchars($currentBank['opening_balance'] ?? 0) ?>">✏️ Edit Bank</button>
                <div style="height: 1px; background: #ddd; margin: 5px 0;"></div>
                <button class="bank-menu-item bank-full-statement-btn">📥 Full Statement (PDF)</button>
                <button class="bank-menu-item bank-full-csv-btn">📋 Full Statement (CSV)</button>
                <button class="bank-menu-item bank-monthly-statement-btn">📄 Monthly Statement</button>
                <button class="bank-menu-item bank-custom-range-btn">📅 Custom Range</button>
            </div>
        </div>
    <?php elseif ($view === 'wallet' && $currentWallet): ?>
        <div class="breadcrumb-menu">
            <button class="wallet-menu-btn" type="button">⋮</button>
            <div class="wallet-menu-dropdown">
                <button class="wallet-menu-item wallet-edit-option" data-id="<?= htmlspecialchars($id) ?>" data-name="<?= htmlspecialchars($currentWallet['name']) ?>" data-bank-id="<?= htmlspecialchars($currentWallet['bank_id']) ?>" data-wallet-type="<?= htmlspecialchars($currentWallet['wallet_type'] ?? 'balance') ?>" data-description="<?= htmlspecialchars($currentWallet['description'] ?? '') ?>" data-opening-balance="<?= htmlspecialchars($currentWallet['opening_balance'] ?? 0) ?>">✏️ Edit Wallet</button>
                <div style="height: 1px; background: #ddd; margin: 5px 0;"></div>
                <button class="wallet-menu-item wallet-full-statement-btn">📥 Full Statement (PDF)</button>
                <button class="wallet-menu-item wallet-full-csv-btn">📋 Full Statement (CSV)</button>
                <button class="wallet-menu-item wallet-monthly-statement-btn">📄 Monthly Statement</button>
                <button class="wallet-menu-item wallet-custom-range-btn">📅 Custom Range</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Balance Display (for Balance Wallets and Bank Views only) -->
<?php if ($view !== 'wallet' || ($view === 'wallet' && ($currentWallet['wallet_type'] ?? 'balance') === 'balance')): ?>
<div class="balance-card<?= $view === 'wallet' ? ' balance-card--wallet' : '' ?>">
    <div class="balance-card-main">
        <div>
            <h2>Balance</h2>
            <div class="balance-amount">₹ <?= number_format($balance, 2) ?></div>
        </div>
        <?php if ($view === 'wallet'): ?>
        <button id="open-add" class="btn" type="button">➕ Add Transaction</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($view === 'main'): ?>
    <!-- MAIN VIEW: All Banks -->
    <div class="action-bar">
        <button id="open-add-bank" class="btn" type="button">🏦 Add Bank</button>
        <button id="open-add-wallet" class="btn" type="button">💳 Add Wallet</button>
        <a href="index.php?view=categories" class="btn btn-ghost" style="text-decoration:none;">🏷️ Categories</a>
        <a href="index.php?view=recurring" class="btn btn-ghost" style="text-decoration:none;">🔁 Recurring</a>
        <a href="index.php?view=reminders" class="btn btn-ghost btn-reminders-nav" style="text-decoration:none;">🔔 Bill Reminders</a>
        <a href="index.php?view=audit" class="btn btn-ghost" style="text-decoration:none;">📋 Audit Log</a>
    </div>

    <!-- ─── DASHBOARD CHARTS ─────────────────────────────────── -->
    <?php if (!empty($dashMonthly) || !empty($dashCategory)): ?>
    <div class="dashboard-charts">

        <?php if (!empty($dashMonthly)):
            // ── compute scale for bar chart ───────────────────────
            $maxVal = 0;
            foreach ($dashMonthly as $m) {
                $maxVal = max($maxVal, $m['income'], $m['expense']);
            }
            $maxVal = $maxVal > 0 ? $maxVal : 1;

            $chartW   = 540;   // SVG viewBox width
            $chartH   = 160;   // SVG viewBox height
            $padL      = 58;   // left padding for y-axis labels
            $padB      = 30;   // bottom padding for x-axis labels
            $padT      = 10;   // top padding
            $plotW     = $chartW - $padL - 10;
            $plotH     = $chartH - $padB - $padT;
            $nMonths   = count($dashMonthly);
            $groupW    = $plotW / $nMonths;
            $barW      = max(6, min(22, $groupW * 0.35));
            $gap       = $barW * 0.4;

            // y-axis: 4 gridlines
            $ySteps    = 4;
            $yTickStep = $maxVal / $ySteps;
        ?>
        <div class="dash-chart-box">
            <div class="dash-chart-title">Monthly Overview — Last <?= $nMonths ?> Months</div>
            <div class="dash-chart-legend">
                <span class="dash-legend-dot" style="background:#27ae60;"></span> Income
                &nbsp;
                <span class="dash-legend-dot" style="background:#e74c3c;"></span> Expense
            </div>
            <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="dash-svg" aria-label="Monthly income and expense chart">

                <?php for ($yi = 0; $yi <= $ySteps; $yi++):
                    $yVal = $yTickStep * $yi;
                    $yPx  = $padT + $plotH - ($plotH * $yi / $ySteps);
                    $yLabel = $yVal >= 100000 ? round($yVal/1000) . 'k' : number_format($yVal, 0);
                ?>
                <!-- gridline -->
                <line x1="<?= $padL ?>" y1="<?= $yPx ?>" x2="<?= $chartW - 10 ?>" y2="<?= $yPx ?>"
                      stroke="var(--border)" stroke-width="0.5"/>
                <text x="<?= $padL - 4 ?>" y="<?= $yPx + 3 ?>" text-anchor="end"
                      font-size="9" fill="var(--muted)">₹<?= $yLabel ?></text>
                <?php endfor; ?>

                <?php foreach ($dashMonthly as $i => $m):
                    $cx      = $padL + ($i + 0.5) * $groupW;
                    $incH    = $plotH * ($m['income']  / $maxVal);
                    $expH    = $plotH * ($m['expense'] / $maxVal);
                    $incX    = $cx - $barW - $gap / 2;
                    $expX    = $cx + $gap / 2;
                    $incY    = $padT + $plotH - $incH;
                    $expY    = $padT + $plotH - $expH;
                    $label   = date('M y', mktime(0,0,0, intval($m['month']), 1, intval($m['year'])));
                ?>
                <!-- income bar -->
                <rect x="<?= round($incX, 1) ?>" y="<?= round($incY, 1) ?>"
                      width="<?= $barW ?>" height="<?= round($incH, 1) ?>"
                      fill="#27ae60" rx="2" opacity="0.9">
                    <title>Income <?= $label ?>: ₹<?= number_format($m['income'], 2) ?></title>
                </rect>
                <!-- expense bar -->
                <rect x="<?= round($expX, 1) ?>" y="<?= round($expY, 1) ?>"
                      width="<?= $barW ?>" height="<?= round($expH, 1) ?>"
                      fill="#e74c3c" rx="2" opacity="0.9">
                    <title>Expense <?= $label ?>: ₹<?= number_format($m['expense'], 2) ?></title>
                </rect>
                <!-- x-axis label -->
                <text x="<?= round($cx, 1) ?>" y="<?= $chartH - 5 ?>"
                      text-anchor="middle" font-size="9" fill="var(--muted)"><?= $label ?></text>
                <?php endforeach; ?>

                <!-- x-axis baseline -->
                <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>"
                      x2="<?= $chartW - 10 ?>" y2="<?= $padT + $plotH ?>"
                      stroke="var(--border)" stroke-width="1"/>
            </svg>
        </div>
        <?php endif; ?>

        <?php if (!empty($dashCategory)):
            $maxCat = max(array_column($dashCategory, 'total_expense'));
            $maxCat = $maxCat > 0 ? $maxCat : 1;
        ?>
        <div class="dash-chart-box">
            <div class="dash-chart-title">Top Spending Categories</div>
            <div class="dash-cat-list">
                <?php foreach ($dashCategory as $cat):
                    $pct = round(100 * $cat['total_expense'] / $maxCat);
                    $label = $cat['total_expense'] >= 100000
                        ? '₹' . round($cat['total_expense'] / 1000) . 'k'
                        : '₹' . number_format($cat['total_expense'], 0);
                ?>
                <div class="dash-cat-row">
                    <span class="dash-cat-name">
                        <span class="cat-color-dot" style="background:<?= htmlspecialchars($cat['category_color']) ?>;"></span>
                        <?= htmlspecialchars($cat['category_name']) ?>
                    </span>
                    <div class="dash-cat-bar-wrap">
                        <div class="dash-cat-bar-fill"
                             style="width:<?= $pct ?>%;background:<?= htmlspecialchars($cat['category_color']) ?>;"></div>
                    </div>
                    <span class="dash-cat-amount"><?= $label ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>
    <!-- ─── END DASHBOARD CHARTS ─────────────────────────────── -->

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
                        <button class="card-menu-item bank-edit-btn" data-id="<?= htmlspecialchars($bank['id']) ?>" data-name="<?= htmlspecialchars($bank['name']) ?>" data-description="<?= htmlspecialchars($bank['description'] ?? '') ?>" data-opening-balance="<?= htmlspecialchars($bank['opening_balance'] ?? 0) ?>">✏️ Edit</button>
                        <button class="card-menu-item card-delete-danger bank-delete-btn" data-id="<?= htmlspecialchars($bank['id']) ?>" data-name="<?= htmlspecialchars($bank['name']) ?>">🗑️ Delete</button>
                    </div>
                </div>
            </div>
            <div class="card-body card-clickable" data-href="index.php?view=bank&id=<?= htmlspecialchars($bank['id']) ?>">
                <div class="card-row">
                    <span class="label">Balance</span>
                    <span class="value">₹ <?= number_format($bank['balance'], 2) ?></span>
                </div>
                <?php if (!empty($bank['warning_count'])): ?>
                <div class="card-row">
                    <span class="label warning-label">Warnings</span>
                    <span class="value warning-value">⚠ <?= htmlspecialchars($bank['warning_count']) ?></span>
                </div>
                <?php endif; ?>
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
        <h3 style="color: var(--muted); font-size: 1.1rem; margin-bottom: 12px;">📊 Monthly Budget Trackers</h3>
        <div class="card-grid">
            <?php foreach ($budgetWallets as $wallet): ?>
            <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>">
                <div class="card-header">
                    <strong>📊 <?= htmlspecialchars($wallet['name']) ?></strong>
                    <div class="card-menu-container">
                        <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                        <div class="card-menu-dropdown">
                            <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                            <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank-id="<?= htmlspecialchars($wallet['bank_id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>" data-opening-balance="<?= htmlspecialchars($wallet['opening_balance'] ?? 0) ?>">✏️ Edit</button>
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
                    <div class="card-row" style="color: var(--muted); font-size: 0.88rem; font-style: italic;">
                        No budget set for this month
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($wallet['warning_count'])): ?>
                    <div class="card-row">
                        <span class="label warning-label">Warnings</span>
                        <span class="value warning-value">⚠ <?= htmlspecialchars($wallet['warning_count']) ?></span>
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
        <h3 style="color: var(--muted); font-size: 1.1rem; margin-bottom: 12px;">💳 Running Balance Wallets</h3>
        <div class="card-grid">
            <?php foreach ($balanceWallets as $wallet): ?>
            <div class="card wallet-card" data-wallet-id="<?= htmlspecialchars($wallet['id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>">
                <div class="card-header">
                    <strong>💳 <?= htmlspecialchars($wallet['name']) ?></strong>
                    <div class="card-menu-container">
                        <button class="card-menu-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>">⋮</button>
                        <div class="card-menu-dropdown">
                            <a href="index.php?view=wallet&id=<?= htmlspecialchars($wallet['id']) ?>" class="card-menu-item">👁️ View</a>
                            <button class="card-menu-item wallet-edit-btn" data-id="<?= htmlspecialchars($wallet['id']) ?>" data-name="<?= htmlspecialchars($wallet['name']) ?>" data-bank-id="<?= htmlspecialchars($wallet['bank_id']) ?>" data-wallet-type="<?= htmlspecialchars($wallet['wallet_type']) ?>" data-description="<?= htmlspecialchars($wallet['description'] ?? '') ?>" data-opening-balance="<?= htmlspecialchars($wallet['opening_balance'] ?? 0) ?>">✏️ Edit</button>
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
                    <?php if (!empty($wallet['warning_count'])): ?>
                    <div class="card-row">
                        <span class="label warning-label">Warnings</span>
                        <span class="value warning-value">⚠ <?= htmlspecialchars($wallet['warning_count']) ?></span>
                    </div>
                    <?php endif; ?>
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

    <!-- Add Transaction / Transfer Buttons -->
    <div class="action-bar" style="justify-content:center;">
        <button id="open-add" class="btn" type="button">➕ Add Transaction</button>
        <button id="open-transfer" class="btn btn-transfer" type="button">🔄 Transfer</button>
    </div>

    <hr>

    <!-- Bank's Monthly Summary -->
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

    <div class="summary-section-header">
        <h2 style="margin:0;">Bank's Monthly Summary</h2>
        <?php if ($bankMonthTotalPages > 1): ?>
        <div class="pagination-inline">
            <?php if($bankMonthPage > 1): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&bank_month_page=<?= $bankMonthPage-1 ?>">← Prev</a>
            <?php endif; ?>
            <span class="page-info"><?= $bankMonthPage ?> / <?= $bankMonthTotalPages ?></span>
            <?php if($bankMonthPage < $bankMonthTotalPages): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&bank_month_page=<?= $bankMonthPage+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

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


    <hr>

    <!-- Bank's Transactions -->
    <div class="tx-section-header">
        <h2 style="margin:0"><?php if ($validMonth): ?>Transactions for <?= htmlspecialchars(date('F Y', strtotime($validMonth . '-01'))) ?> <a href="?view=bank&id=<?= htmlspecialchars($id) ?>" style="font-size: 0.8rem; margin-left: 10px;">← View All</a><?php elseif ($hasActiveFilter): ?>Search Results <?php else: ?>All Transactions<?php endif; ?></h2>
        <?php if (!$validMonth): ?>
        <button class="btn btn-small filter-toggle-btn" type="button" id="filter-toggle">🔍 <?= $hasActiveFilter ? 'Filters Active' : 'Filter' ?></button>
        <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <?php if (!$validMonth): ?>
    <div id="filter-bar" class="filter-bar<?= $hasActiveFilter ? ' filter-bar--open' : '' ?>">
        <form method="GET" action="" class="filter-form">
            <input type="hidden" name="view" value="bank">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
            <div class="filter-row">
                <div class="filter-field">
                    <label for="f-q-bank">Keyword</label>
                    <input id="f-q-bank" type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Title or note…" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label for="f-type-bank">Type</label>
                    <select id="f-type-bank" name="ftype">
                        <option value="">All</option>
                        <option value="income"  <?= $filterType === 'income'  ? 'selected' : '' ?>>Income</option>
                        <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>Expense</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="f-cat-bank">Category</label>
                    <select id="f-cat-bank" name="fcat">
                        <option value="0">All categories</option>
                        <option value="-1" <?= $filterCat === -1 ? 'selected' : '' ?>>Uncategorised</option>
                        <?php
                        $filterCatsResult = $queries->getAllCategories();
                        while ($fc = $filterCatsResult->fetchArray(SQLITE3_ASSOC)):
                        ?>
                        <option value="<?= htmlspecialchars($fc['id']) ?>" <?= $filterCat === intval($fc['id']) ? 'selected' : '' ?>><?= htmlspecialchars($fc['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="f-from-bank">From</label>
                    <input id="f-from-bank" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label for="f-to-bank">To</label>
                    <input id="f-to-bank" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" autocomplete="off">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-small">Apply</button>
                <?php if ($hasActiveFilter): ?>
                <a href="?view=bank&id=<?= htmlspecialchars($id) ?>" class="btn btn-small btn-outline">✕ Clear</a>
                <?php endif; ?>
                <?php
                $csvParams = 'view=bank&id=' . intval($id) . '&type=filtered';
                if ($filterQ !== '')        $csvParams .= '&q='         . urlencode($filterQ);
                if ($filterType !== '')     $csvParams .= '&ftype='     . urlencode($filterType);
                if ($filterCat !== 0)       $csvParams .= '&fcat='      . intval($filterCat);
                if ($filterDateFrom !== '') $csvParams .= '&date_from=' . urlencode($filterDateFrom);
                if ($filterDateTo !== '')   $csvParams .= '&date_to='   . urlencode($filterDateTo);
                ?>
                <a href="export_csv.php?<?= $csvParams ?>" class="btn btn-small btn-csv">📥 Export CSV</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php if ($hasActiveFilter): ?>
    <div class="filter-result-info">Showing <?= $totalCount ?> matching transaction<?= $totalCount !== 1 ? 's' : '' ?></div>
    <?php endif; ?>
    <?php if (!$selectedMonth && $totalPages > 1): ?>
    <div class="pagination-wrap" style="margin-bottom:8px;">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?><?= $filterQS ?>">← Prev</a>
            <?php endif; ?>
            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>
            <?php if($page < $totalPages): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?><?= $filterQS ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="grid tx-grid">
        <?php while($r = $transactions->fetchArray(SQLITE3_ASSOC)): ?>
        <?php $isTransfer = !empty($r['transfer_pair_id']); ?>
        <div class="card tx-card<?= $isTransfer ? ' tx-transfer-card' : '' ?>">
            <div class="tx-container">
                <div class="tx-left-section">
                    <div class="tx-title"><?= htmlspecialchars($r['title'] ?? 'Transaction') ?></div>
                    <div class="tx-date"><?= htmlspecialchars($r['date']) ?></div>
                    <?php if($isTransfer): ?>
                    <div class="tx-transfer-badge">🔄 <?= $r['type'] === 'expense' ? 'Transfer Out' : 'Transfer In' ?></div>
                    <?php elseif(!empty($r['category_name'])): ?>
                    <span class="tx-category-badge" style="background:<?= htmlspecialchars($r['category_color']) ?>;"><?= htmlspecialchars($r['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if(!$isTransfer): ?>
                        <?php if(!empty($r['wallet'])): ?>
                        <div class="tx-note" style="color: var(--muted); font-size: 0.85rem;">💳 <?= htmlspecialchars($r['wallet']) ?></div>
                        <?php else: ?>
                        <div class="tx-note" style="color: var(--muted); font-size: 0.85rem;">🏦 Bank Direct</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if(!empty($r['note'])): ?>
                    <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="tx-right-section">
                    <div class="tx-menu-container">
                        <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-payment-method="<?= htmlspecialchars($r['payment_bank_id'] ?? '') ?>" data-category="<?= htmlspecialchars($r['category_id'] ?? '') ?>" data-transfer="<?= htmlspecialchars($isTransfer ? '1' : '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . ($r['title'] ?? 'Transfer')) ?>">⋮</button>
                        <div class="tx-menu-dropdown">
                            <?php if(!$isTransfer): ?>
                            <button class="tx-menu-item tx-edit-option">✏️ Edit</button>
                            <?php endif; ?>
                            <button class="tx-menu-item tx-delete-option">🗑️ <?= $isTransfer ? 'Delete Transfer' : 'Delete' ?></button>
                        </div>
                    </div>
                    <div class="type-badge <?= $isTransfer ? 'transfer' : $r['type'] ?>"><?= $isTransfer ? ($r['type'] === 'expense' ? 'Transfer ↑' : 'Transfer ↓') : ucfirst($r['type']) ?></div>
                    <div class="tx-amount">₹ <?= number_format($r['amount'], 2) ?></div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <!-- Pagination for all transactions view -->
    <?php
    // Build filter query string to preserve across pagination
    $filterQS = '';
    if ($filterQ !== '')        $filterQS .= '&q='         . urlencode($filterQ);
    if ($filterType !== '')     $filterQS .= '&ftype='     . urlencode($filterType);
    if ($filterCat !== 0)       $filterQS .= '&fcat='      . intval($filterCat);
    if ($filterDateFrom !== '') $filterQS .= '&date_from=' . urlencode($filterDateFrom);
    if ($filterDateTo !== '')   $filterQS .= '&date_to='   . urlencode($filterDateTo);
    ?>
    <?php if (!$selectedMonth): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?><?= $filterQS ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>

            <?php if($page < $totalPages): ?>
                <a class="btn btn-nav-compact" href="?view=bank&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?><?= $filterQS ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bank Category Breakdown -->
    <?php
    $bankBreakdown = $queries->getBankCategoryBreakdown($id);
    $bankBreakdownRows = [];
    while ($br = $bankBreakdown->fetchArray(SQLITE3_ASSOC)) {
        $bankBreakdownRows[] = $br;
    }
    if (!empty($bankBreakdownRows)):
    ?>
    <hr>
    <h2>Spending by Category</h2>
    <div class="category-breakdown-grid">
        <?php foreach ($bankBreakdownRows as $br): ?>
        <div class="category-breakdown-item">
            <span class="cat-color-dot" style="background:<?= htmlspecialchars($br['category_color']) ?>;"></span>
            <span class="cat-breakdown-name"><?= htmlspecialchars($br['category_name']) ?></span>
            <span class="cat-breakdown-count"><?= htmlspecialchars($br['tx_count']) ?> tx</span>
            <span class="cat-breakdown-amount">₹ <?= number_format($br['total_expense'], 2) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($view === 'wallet' && $currentWallet): ?>
    <!-- WALLET VIEW -->

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
    <!-- BUDGET TRACKER WALLET - Add Transaction + instruction -->
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
        <div class="wallet-instruction-box budget-instruction" style="margin-bottom:0; flex:1 1 auto;">
            <div class="instruction-title">📊 Monthly Budget Tracking</div>
            <div class="instruction-main">✓ Set and track your monthly budget limit</div>
            <div class="instruction-subtitle">Each month resets independently - focus on staying within budget!</div>
        </div>
        <button id="open-add" class="btn" type="button" style="flex-shrink:0;">➕ Add Transaction</button>
    </div>
    <?php endif; ?>

    <!-- Monthly Summary for this Wallet -->
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

    <div class="summary-section-header">
        <h2 style="margin:0;">Monthly Summary</h2>
        <?php if ($monthTotalPages > 1): ?>
        <div class="pagination-inline">
            <?php if($monthPage > 1): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month_page=<?= $monthPage-1 ?>">← Prev</a>
            <?php endif; ?>
            <span class="page-info"><?= $monthPage ?> / <?= $monthTotalPages ?></span>
            <?php if($monthPage < $monthTotalPages): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month_page=<?= $monthPage+1 ?>">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="monthly-grid">
        <?php
        foreach ($displayMonths as $index => $r):
            $monthLink = htmlspecialchars($r['year']) . '-' . str_pad($r['month'], 2, '0', STR_PAD_LEFT);

            // For all wallets: fetch budget for this month (may be null)
            $monthBudgetCard = $queries->getBudgetByWalletMonth($id, $r['year'], intval($r['month']));
            $budgetAmount = $monthBudgetCard['expected_expense'] ?? 0;

            // For balance wallets (or budget wallets without a budget): also compute previous-month balance
            $previousMonthBalance = 0;
            if ($walletType !== 'budget' || !$monthBudgetCard) {
                $stmt = $db->prepare(
                    "SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as balance
                     FROM transactions
                     WHERE wallet_id = ? AND deleted_at IS NULL
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
        ?>
        <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>&month=<?= $monthLink ?>" style="text-decoration: none;">
            <div class="card month-card" style="cursor: pointer; transition: transform 200ms ease, box-shadow 200ms ease;">
                <div class="card-header"><?= htmlspecialchars($r['month']) ?>/<?= htmlspecialchars($r['year']) ?></div>
                <div class="card-body">
                    <?php if ($monthBudgetCard && $budgetAmount > 0): ?>
                        <!-- Has budget: Show Budget + Income/Expense/Saved or Over -->
                        <div class="card-row"><span class="label">Budget</span><span class="value" style="color: #3498db;">₹ <?= number_format($budgetAmount, 2) ?></span></div>
                        <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'], 2) ?></span></div>
                        <?php
                            $saved = $budgetAmount - ($r['expense'] - $r['income']);
                            $isOver = $saved < 0;
                            $displayValue = abs($saved);
                            $cardLabel = $isOver ? 'Over' : 'Saved';
                            $cardColor = $isOver ? '#e74c3c' : '#27ae60';
                        ?>
                        <div class="card-row"><span class="label"><?= $cardLabel ?></span><span class="value" style="color: <?= $cardColor ?>;">₹ <?= number_format($displayValue, 2) ?></span></div>
                    <?php else: ?>
                        <!-- No budget: Show Previous Month Balance + Income/Expense/Net -->
                        <?php if ($walletType === 'balance'): ?>
                        <div class="card-row"><span class="label">Prev Bal</span><span class="value" style="color: #7f8c8d;">₹ <?= number_format($previousMonthBalance, 2) ?></span></div>
                        <?php endif; ?>
                        <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'], 2) ?></span></div>
                        <div class="card-row"><span class="label">Net</span><span class="value">₹ <?= number_format($r['net'], 2) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>


    <!-- BUDGET TRACKER SECTION (budget wallets only) -->
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
           <div class="card budget-card"
               data-budget-id="<?= htmlspecialchars($monthBudget['id'] ?? '') ?>"
               data-budget-notes="<?= htmlspecialchars($monthBudget['notes'] ?? '', ENT_QUOTES) ?>">
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
                        <div class="budget-row" style="font-size: 0.85rem; color: var(--muted);">
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
                            <div style="font-size: 0.8rem; color: var(--muted); margin-top: 4px; text-align: right;">
                                (Expense - Income)
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="budget-row" style="margin-top: 8px;">
                            <span style="font-size: 0.9rem; color: var(--muted);">Out of ₹<?= number_format($expectedExpense, 2) ?> budget</span>
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
    <div class="tx-section-header">
        <h2 style="margin:0"><?php if ($validMonth): ?>Transactions for <?= htmlspecialchars(date('F Y', strtotime($validMonth . '-01'))) ?> <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>" style="font-size: 0.8rem; margin-left: 10px;">← View All</a><?php elseif ($hasActiveFilter): ?>Search Results<?php else: ?>All Transactions<?php endif; ?></h2>
        <?php if (!$validMonth): ?>
        <button class="btn btn-small filter-toggle-btn" type="button" id="filter-toggle">🔍 <?= $hasActiveFilter ? 'Filters Active' : 'Filter' ?></button>
        <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <?php if (!$validMonth): ?>
    <div id="filter-bar" class="filter-bar<?= $hasActiveFilter ? ' filter-bar--open' : '' ?>">
        <form method="GET" action="" class="filter-form">
            <input type="hidden" name="view" value="wallet">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
            <div class="filter-row">
                <div class="filter-field">
                    <label for="f-q-wallet">Keyword</label>
                    <input id="f-q-wallet" type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Title or note…" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label for="f-type-wallet">Type</label>
                    <select id="f-type-wallet" name="ftype">
                        <option value="">All</option>
                        <option value="income"  <?= $filterType === 'income'  ? 'selected' : '' ?>>Income</option>
                        <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>Expense</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="f-cat-wallet">Category</label>
                    <select id="f-cat-wallet" name="fcat">
                        <option value="0">All categories</option>
                        <option value="-1" <?= $filterCat === -1 ? 'selected' : '' ?>>Uncategorised</option>
                        <?php
                        $filterCatsResult2 = $queries->getAllCategories();
                        while ($fc2 = $filterCatsResult2->fetchArray(SQLITE3_ASSOC)):
                        ?>
                        <option value="<?= htmlspecialchars($fc2['id']) ?>" <?= $filterCat === intval($fc2['id']) ? 'selected' : '' ?>><?= htmlspecialchars($fc2['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="f-from-wallet">From</label>
                    <input id="f-from-wallet" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" autocomplete="off">
                </div>
                <div class="filter-field">
                    <label for="f-to-wallet">To</label>
                    <input id="f-to-wallet" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" autocomplete="off">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-small">Apply</button>
                <?php if ($hasActiveFilter): ?>
                <a href="?view=wallet&id=<?= htmlspecialchars($id) ?>" class="btn btn-small btn-outline">✕ Clear</a>
                <?php endif; ?>
                <?php
                $csvParamsW = 'view=wallet&id=' . intval($id) . '&type=filtered';
                if ($filterQ !== '')        $csvParamsW .= '&q='         . urlencode($filterQ);
                if ($filterType !== '')     $csvParamsW .= '&ftype='     . urlencode($filterType);
                if ($filterCat !== 0)       $csvParamsW .= '&fcat='      . intval($filterCat);
                if ($filterDateFrom !== '') $csvParamsW .= '&date_from=' . urlencode($filterDateFrom);
                if ($filterDateTo !== '')   $csvParamsW .= '&date_to='   . urlencode($filterDateTo);
                ?>
                <a href="export_csv.php?<?= $csvParamsW ?>" class="btn btn-small btn-csv">📥 Export CSV</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php if ($hasActiveFilter): ?>
    <div class="filter-result-info">Showing <?= $totalCount ?> matching transaction<?= $totalCount !== 1 ? 's' : '' ?></div>
    <?php endif; ?>
    <?php if (!$selectedMonth && $totalPages > 1): ?>
    <div class="pagination-wrap" style="margin-bottom:8px;">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?><?= $filterQS ?>">← Prev</a>
            <?php endif; ?>
            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>
            <?php if($page < $totalPages): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?><?= $filterQS ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
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
                        <?php if(!empty($r['category_name'])): ?>
                        <span class="tx-category-badge" style="background:<?= htmlspecialchars($r['category_color']) ?>;"><?= htmlspecialchars($r['category_name']) ?></span>
                        <?php endif; ?>
                        <?php if(!empty($r['payment_method'])): ?>
                        <div class="tx-note" style="color: var(--muted); font-size: 0.85rem;">💳 <?= htmlspecialchars($r['payment_method']) ?></div>
                        <?php elseif(!empty($r['is_missing_bank'])): ?>
                        <div class="tx-note" style="color: #d35400; font-size: 0.85rem;">⚠ Bank not selected. Update transaction to assign a bank.</div>
                        <?php endif; ?>
                        <?php if(!empty($r['note'])): ?>
                        <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="tx-right-section">
                        <div class="tx-menu-container">
                            <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-wallet="<?= htmlspecialchars($r['wallet_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-title="<?= htmlspecialchars($r['title'] ?? '') ?>" data-payment-method="<?= htmlspecialchars($r['payment_bank_id'] ?? '') ?>" data-category="<?= htmlspecialchars($r['category_id'] ?? '') ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . $currentWallet['name']) ?>">⋮</button>
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
    <?php
    // Reuse $filterQS built in bank section (available since this is the same request)
    if (!isset($filterQS)) {
        $filterQS = '';
        if ($filterQ !== '')        $filterQS .= '&q='         . urlencode($filterQ);
        if ($filterType !== '')     $filterQS .= '&ftype='     . urlencode($filterType);
        if ($filterCat !== 0)       $filterQS .= '&fcat='      . intval($filterCat);
        if ($filterDateFrom !== '') $filterQS .= '&date_from=' . urlencode($filterDateFrom);
        if ($filterDateTo !== '')   $filterQS .= '&date_to='   . urlencode($filterDateTo);
    }
    ?>
    <?php if (!$selectedMonth): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php if($page > 1): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page-1 ?><?= $filterQS ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>

            <?php if($page < $totalPages): ?>
                <a class="btn btn-nav-compact" href="?view=wallet&id=<?= htmlspecialchars($id) ?>&page=<?= $page+1 ?><?= $filterQS ?>">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Wallet Category Breakdown -->
    <?php
    $walletBreakdown = $queries->getWalletCategoryBreakdown($id);
    $walletBreakdownRows = [];
    while ($wbr = $walletBreakdown->fetchArray(SQLITE3_ASSOC)) {
        $walletBreakdownRows[] = $wbr;
    }
    if (!empty($walletBreakdownRows)):
    ?>
    <hr>
    <h2>Spending by Category</h2>
    <div class="category-breakdown-grid">
        <?php foreach ($walletBreakdownRows as $wbr): ?>
        <div class="category-breakdown-item">
            <span class="cat-color-dot" style="background:<?= htmlspecialchars($wbr['category_color']) ?>;"></span>
            <span class="cat-breakdown-name"><?= htmlspecialchars($wbr['category_name']) ?></span>
            <span class="cat-breakdown-count"><?= htmlspecialchars($wbr['tx_count']) ?> tx</span>
            <span class="cat-breakdown-amount">₹ <?= number_format($wbr['total_expense'], 2) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($view === 'categories'): ?>
    <!-- CATEGORIES MANAGEMENT VIEW -->
    <div class="action-bar">
        <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">← Home</a>
        <button id="open-add-category" class="btn" type="button">➕ Add Category</button>
    </div>

    <h2>Categories</h2>
    <?php
    $allCategoriesList = $queries->getAllCategories();
    $categoryRows = [];
    while ($catRow = $allCategoriesList->fetchArray(SQLITE3_ASSOC)) {
        $categoryRows[] = $catRow;
    }
    ?>
    <?php if (empty($categoryRows)): ?>
    <p class="empty-state">No categories yet. Add one above.</p>
    <?php else: ?>
    <div class="category-list">
        <?php foreach ($categoryRows as $catRow): ?>
        <div class="category-list-item">
            <span class="cat-color-swatch" style="background:<?= htmlspecialchars($catRow['color']) ?>;"></span>
            <span class="cat-list-name"><?= htmlspecialchars($catRow['name']) ?></span>
            <div class="cat-list-actions">
                <button class="btn-small cat-edit-btn"
                    data-id="<?= htmlspecialchars($catRow['id']) ?>"
                    data-name="<?= htmlspecialchars($catRow['name']) ?>"
                    data-color="<?= htmlspecialchars($catRow['color']) ?>">✏️ Edit</button>
                <button class="btn-small btn-danger cat-delete-btn"
                    data-id="<?= htmlspecialchars($catRow['id']) ?>"
                    data-name="<?= htmlspecialchars($catRow['name']) ?>">🗑️ Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add/Edit Category Modal -->
    <div id="category-modal-overlay" class="modal-overlay" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="category-modal-title">
            <div class="modal-header">
                <h3 id="category-modal-title">Add Category</h3>
                <button id="category-modal-close" class="modal-close-btn">✕</button>
            </div>
            <form id="category-form" action="backend.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" id="cat-id" name="category_id" value="">
                <input type="hidden" id="cat-action" name="action" value="category_add">
                <div class="form-group">
                    <label for="cat-name">Name</label>
                    <input id="cat-name" type="text" name="name" required placeholder="e.g., Groceries" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="cat-color">Colour</label>
                    <input id="cat-color" type="color" name="color" value="#95a5a6" required autocomplete="off">
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="submit">💾 Save</button>
                    <button type="button" id="category-modal-cancel" class="back-link">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        function openCatModal(mode, id, name, color) {
            document.getElementById('category-modal-title').textContent = mode === 'edit' ? 'Edit Category' : 'Add Category';
            document.getElementById('cat-id').value = id || '';
            document.getElementById('cat-action').value = mode === 'edit' ? 'category_edit' : 'category_add';
            document.getElementById('cat-name').value = name || '';
            document.getElementById('cat-color').value = color || '#95a5a6';
            document.getElementById('category-modal-overlay').classList.add('open');
        }
        function closeCatModal() {
            document.getElementById('category-modal-overlay').classList.remove('open');
        }

        document.getElementById('open-add-category').addEventListener('click', function() {
            openCatModal('add');
        });
        document.getElementById('category-modal-close').addEventListener('click', closeCatModal);
        document.getElementById('category-modal-cancel').addEventListener('click', closeCatModal);

        document.querySelectorAll('.cat-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openCatModal('edit', this.dataset.id, this.dataset.name, this.dataset.color);
            });
        });

        document.querySelectorAll('.cat-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Delete category "' + this.dataset.name + '"? Transactions will become Uncategorised.')) return;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'backend.php';
                [['csrf_token', window.csrfToken], ['action', 'category_delete'], ['category_id', this.dataset.id]].forEach(function(pair) {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = pair[0];
                    inp.value = pair[1];
                    form.appendChild(inp);
                });
                document.body.appendChild(form);
                form.submit();
            });
        });
    })();
    </script>

<?php elseif ($view === 'recurring'): ?>
    <!-- RECURRING RULES MANAGEMENT VIEW -->
    <div class="action-bar">
        <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">← Home</a>
        <button id="open-add-rule" class="btn" type="button">➕ Add Recurring Rule</button>
    </div>

    <?php if ($recurringPosted > 0): ?>
    <div class="flash-error" style="background:#e8f5e9;color:#27ae60;border-color:#a5d6a7;" role="status">
        ✅ <?= $recurringPosted ?> recurring transaction<?= $recurringPosted > 1 ? 's' : '' ?> posted automatically today.
    </div>
    <?php endif; ?>

    <h2>Recurring Rules</h2>
    <?php if (empty($recurringRules)): ?>
    <p class="empty-state">No recurring rules yet. Add one to auto-post regular transactions.</p>
    <?php else: ?>
    <div class="recurring-list">
        <?php foreach ($recurringRules as $rule): ?>
        <div class="recurring-item<?= !$rule['active'] ? ' recurring-item--paused' : '' ?>">
            <div class="recurring-item-left">
                <div class="recurring-item-title">
                    <?= htmlspecialchars($rule['title']) ?>
                    <?php if (!$rule['active']): ?>
                    <span class="recurring-paused-badge">Paused</span>
                    <?php endif; ?>
                </div>
                <div class="recurring-item-meta">
                    <span class="type-badge <?= $rule['type'] ?>" style="font-size:0.7rem;height:20px;padding:0 7px;"><?= ucfirst($rule['type']) ?></span>
                    <strong>₹ <?= number_format($rule['amount'], 2) ?></strong>
                    · <?= ucfirst($rule['frequency']) ?>
                    · Next: <strong><?= htmlspecialchars($rule['next_due']) ?></strong>
                    <?php if ($rule['bank_name']): ?>
                    · 🏦 <?= htmlspecialchars($rule['bank_name']) ?>
                    <?php endif; ?>
                    <?php if ($rule['wallet_name']): ?>
                    · 💳 <?= htmlspecialchars($rule['wallet_name']) ?>
                    <?php endif; ?>
                    <?php if ($rule['category_name']): ?>
                    · <span style="display:inline-block;padding:1px 7px;border-radius:8px;font-size:0.75rem;font-weight:600;color:#fff;background:<?= htmlspecialchars($rule['category_color']) ?>;"><?= htmlspecialchars($rule['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($rule['note']): ?>
                    · <em style="color:var(--muted)"><?= htmlspecialchars($rule['note']) ?></em>
                    <?php endif; ?>
                </div>
            </div>
            <div class="recurring-item-actions">
                <button class="btn-small rule-edit-btn"
                    data-id="<?= htmlspecialchars($rule['id']) ?>"
                    data-title="<?= htmlspecialchars($rule['title']) ?>"
                    data-type="<?= htmlspecialchars($rule['type']) ?>"
                    data-amount="<?= htmlspecialchars($rule['amount']) ?>"
                    data-wallet="<?= htmlspecialchars($rule['wallet_id'] ?? '') ?>"
                    data-bank="<?= htmlspecialchars($rule['payment_bank_id'] ?? '') ?>"
                    data-category="<?= htmlspecialchars($rule['category_id'] ?? '') ?>"
                    data-note="<?= htmlspecialchars($rule['note'] ?? '') ?>"
                    data-frequency="<?= htmlspecialchars($rule['frequency']) ?>"
                    data-next-due="<?= htmlspecialchars($rule['next_due']) ?>">✏️ Edit</button>
                <button class="btn-small rule-toggle-btn <?= $rule['active'] ? 'btn-pause' : 'btn-resume' ?>"
                    data-id="<?= htmlspecialchars($rule['id']) ?>"
                    data-active="<?= $rule['active'] ? '1' : '0' ?>">
                    <?= $rule['active'] ? '⏸ Pause' : '▶ Resume' ?>
                </button>
                <button class="btn-small btn-danger rule-delete-btn"
                    data-id="<?= htmlspecialchars($rule['id']) ?>"
                    data-title="<?= htmlspecialchars($rule['title']) ?>">🗑️ Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add/Edit Recurring Rule Modal -->
    <div id="rule-modal-overlay" class="modal-overlay" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="rule-modal-title">
            <div class="modal-header">
                <h3 id="rule-modal-title">Add Recurring Rule</h3>
                <button id="rule-modal-close" class="modal-close-btn">✕</button>
            </div>
            <form id="rule-form" action="backend.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" id="rule-id" name="rule_id" value="">
                <input type="hidden" id="rule-action" name="action" value="recurring_add">

                <div class="form-group">
                    <label for="rule-title">Title</label>
                    <input id="rule-title" type="text" name="title" required placeholder="e.g., Rent, Salary, Netflix" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="rule-type">Type</label>
                    <select id="rule-type" name="type" required>
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-amount">Amount (₹)</label>
                    <input id="rule-amount" type="number" step="0.01" name="amount" required min="0.01" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="rule-frequency">Frequency</label>
                    <select id="rule-frequency" name="frequency" required>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly" selected>Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-next-due">First / Next Due Date</label>
                    <input id="rule-next-due" type="date" name="next_due" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="rule-bank">Bank (payment method)</label>
                    <select id="rule-bank" name="payment_bank_id">
                        <option value="">-- None --</option>
                        <?php $rbBanks = $queries->getAllBanks(); while($rb = $rbBanks->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rb['id']) ?>"><?= htmlspecialchars($rb['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-wallet">Wallet</label>
                    <select id="rule-wallet" name="wallet_id">
                        <option value="">-- None (Bank Direct) --</option>
                        <?php $rbWallets = $queries->getAllWallets(); while($rw = $rbWallets->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rw['id']) ?>"><?= htmlspecialchars($rw['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-category">Category</label>
                    <select id="rule-category" name="category_id">
                        <option value="">-- Uncategorised --</option>
                        <?php $rbCats = $queries->getAllCategories(); while($rc = $rbCats->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rc['id']) ?>"><?= htmlspecialchars($rc['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-note">Note</label>
                    <input id="rule-note" type="text" name="note" placeholder="Optional note…" autocomplete="off">
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="submit">💾 Save Rule</button>
                    <button type="button" id="rule-modal-cancel" class="back-link">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        function openRuleModal(mode, data) {
            document.getElementById('rule-modal-title').textContent = mode === 'edit' ? 'Edit Recurring Rule' : 'Add Recurring Rule';
            document.getElementById('rule-action').value  = mode === 'edit' ? 'recurring_edit' : 'recurring_add';
            document.getElementById('rule-id').value      = data.id       || '';
            document.getElementById('rule-title').value   = data.title    || '';
            document.getElementById('rule-type').value    = data.type     || 'expense';
            document.getElementById('rule-amount').value  = data.amount   || '';
            document.getElementById('rule-frequency').value = data.frequency || 'monthly';
            document.getElementById('rule-next-due').value  = data.next_due || '';
            document.getElementById('rule-bank').value    = data.bank     || '';
            document.getElementById('rule-wallet').value  = data.wallet   || '';
            document.getElementById('rule-category').value= data.category || '';
            document.getElementById('rule-note').value    = data.note     || '';
            document.getElementById('rule-modal-overlay').classList.add('open');
        }
        function closeRuleModal() {
            document.getElementById('rule-modal-overlay').classList.remove('open');
        }

        // Default next-due to today
        const nd = document.getElementById('rule-next-due');
        if (nd && !nd.value) nd.valueAsDate = new Date();

        document.getElementById('open-add-rule').addEventListener('click', function() {
            openRuleModal('add', {});
        });
        document.getElementById('rule-modal-close').addEventListener('click', closeRuleModal);
        document.getElementById('rule-modal-cancel').addEventListener('click', closeRuleModal);

        document.querySelectorAll('.rule-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openRuleModal('edit', {
                    id:        this.dataset.id,
                    title:     this.dataset.title,
                    type:      this.dataset.type,
                    amount:    this.dataset.amount,
                    frequency: this.dataset.frequency,
                    next_due:  this.dataset.nextDue,
                    bank:      this.dataset.bank,
                    wallet:    this.dataset.wallet,
                    category:  this.dataset.category,
                    note:      this.dataset.note,
                });
            });
        });

        document.querySelectorAll('.rule-toggle-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const newActive = this.dataset.active === '1' ? '0' : '1';
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'backend.php';
                [['csrf_token', window.csrfToken], ['action', 'recurring_toggle'],
                 ['rule_id', this.dataset.id], ['active', newActive]].forEach(function(p) {
                    const inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = p[0]; inp.value = p[1];
                    form.appendChild(inp);
                });
                document.body.appendChild(form); form.submit();
            });
        });

        document.querySelectorAll('.rule-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Delete recurring rule "' + this.dataset.title + '"?')) return;
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'backend.php';
                [['csrf_token', window.csrfToken], ['action', 'recurring_delete'],
                 ['rule_id', this.dataset.id]].forEach(function(p) {
                    const inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = p[0]; inp.value = p[1];
                    form.appendChild(inp);
                });
                document.body.appendChild(form); form.submit();
            });
        });
    })();
    </script>

<?php elseif ($view === 'reminders'): ?>
    <!-- BILL REMINDERS MANAGEMENT VIEW -->
    <div class="action-bar">
        <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">← Home</a>
        <button id="open-add-reminder" class="btn" type="button">➕ Add Reminder</button>
    </div>

    <h2>Bill Reminders</h2>
    <p style="color:var(--muted);font-size:0.9rem;margin-top:-8px;margin-bottom:16px;">
        Configure bills to remind you when they're due each month or year.
        The notification will appear on the home screen from the configured day.
    </p>

    <?php if (empty($billReminders)): ?>
    <p class="empty-state">No bill reminders yet. Add one above.</p>
    <?php else: ?>
    <div class="recurring-list">
        <?php foreach ($billReminders as $br): ?>
        <div class="recurring-item<?= !$br['active'] ? ' recurring-item--paused' : '' ?>">
            <div class="recurring-item-left">
                <div class="recurring-item-title">
                    🔔 <?= htmlspecialchars($br['title']) ?>
                    <?php if (!$br['active']): ?>
                    <span class="recurring-paused-badge">Paused</span>
                    <?php endif; ?>
                </div>
                <div class="recurring-item-meta">
                    <span class="type-badge <?= $br['type'] ?>" style="font-size:0.7rem;height:20px;padding:0 7px;"><?= ucfirst($br['type']) ?></span>
                    <?php if ($br['default_amount'] > 0): ?>
                    <strong>₹ <?= number_format($br['default_amount'], 2) ?></strong>
                    <?php endif; ?>
                    · <?= $br['frequency'] === 'yearly' ? 'Yearly' : 'Monthly' ?>
                    · Notify from day <strong><?= htmlspecialchars($br['notify_day']) ?></strong>
                    <?php if ($br['frequency'] === 'yearly'): ?>
                    · Month <strong><?= date('F', mktime(0,0,0,$br['notify_month'],1)) ?></strong>
                    <?php endif; ?>
                    <?php if ($br['bank_name']): ?>
                    · 🏦 <?= htmlspecialchars($br['bank_name']) ?>
                    <?php endif; ?>
                    <?php if ($br['wallet_name']): ?>
                    · 💳 <?= htmlspecialchars($br['wallet_name']) ?>
                    <?php endif; ?>
                    <?php if ($br['category_name']): ?>
                    · <span style="display:inline-block;padding:1px 7px;border-radius:8px;font-size:0.75rem;font-weight:600;color:#fff;background:<?= htmlspecialchars($br['category_color']) ?>;"><?= htmlspecialchars($br['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($br['note']): ?>
                    · <em style="color:var(--muted)"><?= htmlspecialchars($br['note']) ?></em>
                    <?php endif; ?>
                </div>
            </div>
            <div class="recurring-item-actions">
                <button class="btn-small reminder-edit-btn"
                    data-id="<?= htmlspecialchars($br['id']) ?>"
                    data-title="<?= htmlspecialchars($br['title']) ?>"
                    data-type="<?= htmlspecialchars($br['type']) ?>"
                    data-default-amount="<?= htmlspecialchars($br['default_amount']) ?>"
                    data-wallet="<?= htmlspecialchars($br['wallet_id'] ?? '') ?>"
                    data-bank="<?= htmlspecialchars($br['payment_bank_id'] ?? '') ?>"
                    data-category="<?= htmlspecialchars($br['category_id'] ?? '') ?>"
                    data-note="<?= htmlspecialchars($br['note'] ?? '') ?>"
                    data-frequency="<?= htmlspecialchars($br['frequency']) ?>"
                    data-notify-day="<?= htmlspecialchars($br['notify_day']) ?>"
                    data-notify-month="<?= htmlspecialchars($br['notify_month']) ?>">✏️ Edit</button>
                <button class="btn-small reminder-toggle-btn <?= $br['active'] ? 'btn-pause' : 'btn-resume' ?>"
                    data-id="<?= htmlspecialchars($br['id']) ?>"
                    data-active="<?= $br['active'] ? '1' : '0' ?>">
                    <?= $br['active'] ? '⏸ Pause' : '▶ Resume' ?>
                </button>
                <button class="btn-small btn-danger reminder-delete-btn"
                    data-id="<?= htmlspecialchars($br['id']) ?>"
                    data-title="<?= htmlspecialchars($br['title']) ?>">🗑️ Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add/Edit Bill Reminder Modal -->
    <div id="reminder-modal-overlay" class="modal-overlay" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reminder-modal-title">
            <div class="modal-header">
                <h3 id="reminder-modal-title">Add Bill Reminder</h3>
                <button id="reminder-modal-close" class="modal-close-btn">✕</button>
            </div>
            <form id="reminder-form" action="backend.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" id="reminder-id" name="reminder_id" value="">
                <input type="hidden" id="reminder-action" name="action" value="reminder_add">

                <div class="form-group">
                    <label for="reminder-title">Bill Name</label>
                    <input id="reminder-title" type="text" name="title" required placeholder="e.g., Credit Card Bill, Water Bill, Netflix" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="reminder-type">Type</label>
                    <select id="reminder-type" name="type" required>
                        <option value="expense" selected>Expense</option>
                        <option value="income">Income</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-default-amount">Typical Amount (₹) <span style="color:var(--muted);font-weight:normal;">Optional — used as hint</span></label>
                    <input id="reminder-default-amount" type="number" step="0.01" name="default_amount" min="0" placeholder="0.00" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="reminder-frequency">Frequency</label>
                    <select id="reminder-frequency" name="frequency" required>
                        <option value="monthly" selected>Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-notify-day">Notify from day-of-month</label>
                    <select id="reminder-notify-day" name="notify_day" required>
                        <?php for ($d = 1; $d <= 28; $d++): ?>
                        <option value="<?= $d ?>"><?= $d ?><?= $d===1?'st':($d===2?'nd':($d===3?'rd':'th')) ?> of the month</option>
                        <?php endfor; ?>
                    </select>
                    <small style="color:var(--muted);margin-top:4px;display:block;">
                        Notification appears on this day (or later) each cycle.
                    </small>
                </div>
                <div class="form-group" id="reminder-month-group" style="display:none;">
                    <label for="reminder-notify-month">Notify in month (yearly only)</label>
                    <select id="reminder-notify-month" name="notify_month">
                        <?php
                        $monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                        foreach ($monthNames as $mi => $mn): ?>
                        <option value="<?= $mi+1 ?>"><?= $mn ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-bank">Bank (payment method)</label>
                    <select id="reminder-bank" name="payment_bank_id">
                        <option value="">-- None --</option>
                        <?php $rmBanks = $queries->getAllBanks(); while($rmb = $rmBanks->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rmb['id']) ?>"><?= htmlspecialchars($rmb['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-wallet">Wallet</label>
                    <select id="reminder-wallet" name="wallet_id">
                        <option value="">-- None (Bank Direct) --</option>
                        <?php $rmWallets = $queries->getAllWallets(); while($rmw = $rmWallets->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rmw['id']) ?>"><?= htmlspecialchars($rmw['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-category">Category</label>
                    <select id="reminder-category" name="category_id">
                        <option value="">-- Uncategorised --</option>
                        <?php $rmCats = $queries->getAllCategories(); while($rmc = $rmCats->fetchArray(SQLITE3_ASSOC)): ?>
                        <option value="<?= htmlspecialchars($rmc['id']) ?>"><?= htmlspecialchars($rmc['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reminder-note">Note</label>
                    <input id="reminder-note" type="text" name="note" placeholder="Optional note…" autocomplete="off">
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="submit">💾 Save Reminder</button>
                    <button type="button" id="reminder-modal-cancel" class="back-link">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        function openReminderModal(mode, data) {
            data = data || {};
            document.getElementById('reminder-modal-title').textContent = mode === 'edit' ? 'Edit Reminder' : 'Add Bill Reminder';
            document.getElementById('reminder-action').value       = mode === 'edit' ? 'reminder_edit' : 'reminder_add';
            document.getElementById('reminder-id').value          = data.id            || '';
            document.getElementById('reminder-title').value       = data.title         || '';
            document.getElementById('reminder-type').value        = data.type          || 'expense';
            document.getElementById('reminder-default-amount').value = data.default_amount || '';
            document.getElementById('reminder-frequency').value   = data.frequency     || 'monthly';
            document.getElementById('reminder-notify-day').value  = data.notify_day    || '1';
            document.getElementById('reminder-notify-month').value = data.notify_month || '1';
            document.getElementById('reminder-bank').value        = data.bank          || '';
            document.getElementById('reminder-wallet').value      = data.wallet        || '';
            document.getElementById('reminder-category').value    = data.category      || '';
            document.getElementById('reminder-note').value        = data.note          || '';
            // Show/hide yearly month field
            toggleYearlyField();
            document.getElementById('reminder-modal-overlay').classList.add('open');
        }
        function closeReminderModal() {
            document.getElementById('reminder-modal-overlay').classList.remove('open');
        }
        function toggleYearlyField() {
            const freq = document.getElementById('reminder-frequency').value;
            document.getElementById('reminder-month-group').style.display = freq === 'yearly' ? '' : 'none';
        }

        document.getElementById('reminder-frequency').addEventListener('change', toggleYearlyField);

        document.getElementById('open-add-reminder').addEventListener('click', function() {
            openReminderModal('add', {});
        });
        document.getElementById('reminder-modal-close').addEventListener('click', closeReminderModal);
        document.getElementById('reminder-modal-cancel').addEventListener('click', closeReminderModal);

        // Delete buttons on the list
        document.querySelectorAll('.reminder-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Delete bill reminder "' + this.dataset.title + '"?\nThis cannot be undone.')) return;
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'backend.php';
                [['csrf_token', window.csrfToken], ['action', 'reminder_delete'], ['reminder_id', this.dataset.id]].forEach(function(p) {
                    const inp = document.createElement('input'); inp.type='hidden'; inp.name=p[0]; inp.value=p[1];
                    form.appendChild(inp);
                });
                document.body.appendChild(form); form.submit();
            });
        });

        document.querySelectorAll('.reminder-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openReminderModal('edit', {
                    id:             this.dataset.id,
                    title:          this.dataset.title,
                    type:           this.dataset.type,
                    default_amount: this.dataset.defaultAmount,
                    frequency:      this.dataset.frequency,
                    notify_day:     this.dataset.notifyDay,
                    notify_month:   this.dataset.notifyMonth,
                    bank:           this.dataset.bank,
                    wallet:         this.dataset.wallet,
                    category:       this.dataset.category,
                    note:           this.dataset.note,
                });
            });
        });

        document.querySelectorAll('.reminder-toggle-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const newActive = this.dataset.active === '1' ? '0' : '1';
                const form = document.createElement('form');
                form.method = 'POST'; form.action = 'backend.php';
                [['csrf_token', window.csrfToken], ['action', 'reminder_toggle'],
                 ['reminder_id', this.dataset.id], ['active', newActive]].forEach(function(p) {
                    const inp = document.createElement('input'); inp.type='hidden'; inp.name=p[0]; inp.value=p[1];
                    form.appendChild(inp);
                });
                document.body.appendChild(form); form.submit();
            });
        });
    })();
    </script>

<?php elseif ($view === 'audit'): ?>
    <!-- AUDIT LOG VIEW -->
    <?php
    $auditFilter = isset($_GET['etype']) ? trim($_GET['etype']) : '';
    $validEtypes = ['transaction', 'bank', 'wallet', 'transfer', 'recurring', 'category', 'bill_reminder'];
    if (!in_array($auditFilter, $validEtypes)) $auditFilter = '';
    $auditRows = $queries->getAuditLog(200, $auditFilter);

    $entityIcons = [
        'transaction'   => '💳',
        'bank'          => '🏦',
        'wallet'        => '👛',
        'transfer'      => '🔄',
        'recurring'     => '🔁',
        'category'      => '🏷️',
        'bill_reminder' => '🔔',
    ];
    $entityLabels = [
        'transaction'   => 'Transaction',
        'bank'          => 'Bank',
        'wallet'        => 'Wallet',
        'transfer'      => 'Transfer',
        'recurring'     => 'Recurring',
        'category'      => 'Category',
        'bill_reminder' => 'Bill Reminder',
    ];
    $actionColors = [
        'add'    => '#27ae60',
        'edit'   => '#3b82d4',
        'delete' => '#e74c3c',
        'toggle' => '#e67e22',
    ];
    ?>
    <div class="action-bar">
        <a href="index.php" class="btn btn-ghost" style="text-decoration:none;">← Home</a>
    </div>

    <h2>Audit Log</h2>

    <!-- Filter by entity type -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center;">
        <span style="font-size:0.88rem;color:var(--muted);">Filter:</span>
        <a href="?view=audit" class="btn btn-small<?= $auditFilter === '' ? ' filter-active' : ' btn-outline' ?>" style="text-decoration:none;">All</a>
        <?php foreach ($validEtypes as $et): ?>
        <a href="?view=audit&etype=<?= $et ?>"
           class="btn btn-small<?= $auditFilter === $et ? ' filter-active' : ' btn-outline' ?>"
           style="text-decoration:none;"><?= ($entityIcons[$et] ?? '') . ' ' . ($entityLabels[$et] ?? ucfirst($et)) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($auditRows)): ?>
    <p class="empty-state">No audit entries yet.</p>
    <?php else: ?>
    <div class="audit-list">
        <?php foreach ($auditRows as $entry):
            // Determine colour from action suffix
            $actionSuffix = substr($entry['action'], strrpos($entry['action'], '_') + 1);
            $dotColor = $actionColors[$actionSuffix] ?? '#95a5a6';
            $icon = $entityIcons[$entry['entity_type']] ?? '📝';
        ?>
        <div class="audit-item">
            <span class="audit-dot" style="background:<?= $dotColor ?>;"></span>
            <div class="audit-body">
                <span class="audit-icon"><?= $icon ?></span>
                <span class="audit-summary"><?= htmlspecialchars($entry['summary']) ?></span>
                <span class="audit-action-badge" style="background:<?= $dotColor ?>;"><?= htmlspecialchars($entry['action']) ?></span>
            </div>
            <span class="audit-time"><?= htmlspecialchars($entry['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Pay Bill Modal (opened from the notification banner) -->
<div id="pay-bill-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pay-bill-modal-title">
        <div class="modal-header">
            <h3 id="pay-bill-modal-title">Pay Bill</h3>
            <button id="pay-bill-modal-close" class="modal-close-btn" type="button">✕</button>
        </div>
        <form id="pay-bill-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="tx_add">
            <input type="hidden" id="pay-bill-reminder-id" name="reminder_id" value="">

            <!-- Read-only context chip showing wallet/bank -->
            <div id="pay-bill-context" class="pay-bill-context" style="display:none;"></div>

            <div class="form-group">
                <label for="pay-bill-date">Date</label>
                <input id="pay-bill-date" type="date" name="date" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="pay-bill-title">Title</label>
                <input id="pay-bill-title" type="text" name="title" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="pay-bill-type">Type</label>
                <select id="pay-bill-type" name="type" required>
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pay-bill-amount">
                    Amount (₹)
                    <span id="pay-bill-amount-hint" class="pay-bill-amount-hint" style="display:none;"></span>
                </label>
                <input id="pay-bill-amount" type="number" step="0.01" name="amount" required min="0.01" autocomplete="off">
            </div>
            <input type="hidden" id="pay-bill-payment-method" name="payment_method" value="">
            <input type="hidden" id="pay-bill-wallet" name="wallet" value="">
            <input type="hidden" id="pay-bill-category" name="category_id" value="">
            <div class="form-group">
                <label for="pay-bill-note">Note</label>
                <input id="pay-bill-note" type="text" name="note" placeholder="Optional note…" autocomplete="off">
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn">✅ Mark as Paid</button>
                <button type="button" id="pay-bill-modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var overlay = document.getElementById('pay-bill-modal-overlay');
    if (!overlay) return;

    function openPayBillModal(data) {
        // Set today's date
        var today = new Date().toISOString().slice(0,10);
        document.getElementById('pay-bill-date').value    = today;
        document.getElementById('pay-bill-title').value   = data.title  || '';
        document.getElementById('pay-bill-type').value    = data.type   || 'expense';
        document.getElementById('pay-bill-amount').value  = '';
        document.getElementById('pay-bill-payment-method').value = data.bankId   || '';
        document.getElementById('pay-bill-wallet').value         = data.walletId || '';
        document.getElementById('pay-bill-category').value       = data.categoryId || '';
        document.getElementById('pay-bill-note').value           = data.note || '';
        document.getElementById('pay-bill-reminder-id').value    = data.reminderId || '';

        // Build context chip
        var ctx = document.getElementById('pay-bill-context');
        var chips = [];
        if (data.bankName)   chips.push('🏦 ' + data.bankName);
        if (data.walletName) chips.push('💳 ' + data.walletName);
        if (chips.length) {
            ctx.textContent = chips.join('  ·  ');
            ctx.style.display = '';
        } else {
            ctx.style.display = 'none';
        }

        // Hide amount hint initially
        var hint = document.getElementById('pay-bill-amount-hint');
        hint.style.display = 'none';

        // Fetch last-payment amount asynchronously
        if (data.reminderId) {
            fetch('backend.php?action=get_last_reminder_amount&reminder_id=' + encodeURIComponent(data.reminderId))
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    var suggestAmt = null;
                    if (json.amount !== null && json.amount !== undefined) {
                        suggestAmt = json.amount;
                        hint.textContent = 'Last payment: ₹' + parseFloat(json.amount).toLocaleString('en-IN', {minimumFractionDigits:2});
                    } else if (json.default_amount > 0) {
                        suggestAmt = json.default_amount;
                        hint.textContent = 'Typical: ₹' + parseFloat(json.default_amount).toLocaleString('en-IN', {minimumFractionDigits:2});
                    }
                    if (suggestAmt !== null) {
                        document.getElementById('pay-bill-amount').value = suggestAmt;
                        hint.style.display = 'inline';
                    }
                })
                .catch(function() {});
        }

        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closePayBillModal() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = 'auto';
    }

    document.getElementById('pay-bill-modal-close').addEventListener('click', closePayBillModal);
    document.getElementById('pay-bill-modal-cancel').addEventListener('click', closePayBillModal);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closePayBillModal(); });

    // Wire up notification banner items
    document.querySelectorAll('.bill-notif-item').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openPayBillModal({
                reminderId:  this.dataset.reminderId,
                title:       this.dataset.title,
                type:        this.dataset.type,
                bankId:      this.dataset.bankId,
                bankName:    this.dataset.bankName,
                walletId:    this.dataset.walletId,
                walletName:  this.dataset.walletName,
                categoryId:  this.dataset.categoryId,
                note:        this.dataset.note,
            });
        });
    });
})();
</script>

<!-- Add/Edit Transaction Modal -->
<div id="modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <h3 id="modal-title">Add Transaction</h3>
            <button id="modal-close" class="modal-close-btn">✕</button>
        </div>
        <form id="tx-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="tx-id" name="tx_id" value="">
            <input type="hidden" id="tx-action" name="action" value="tx_add">
            <input type="hidden" id="tx-wallet-id" name="wallet_id" value="<?= ($view === 'wallet' && $id > 0) ? htmlspecialchars($id) : '' ?>">
            <input type="hidden" id="tx-bank-id" name="bank_id" value="<?= ($view === 'bank' && $id > 0) ? htmlspecialchars($id) : '' ?>">
            <input type="hidden" id="tx-budget-month" name="budget_month" value="<?= isset($_GET['month']) ? htmlspecialchars($_GET['month']) : (isset($_GET['budget_month']) ? htmlspecialchars($_GET['budget_month']) : '') ?>">

            <div class="form-group">
                <label for="m_date">Date</label>
                <input id="m_date" type="date" name="date" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="m_title">Title/Name</label>
                <input id="m_title" type="text" name="title" placeholder="e.g., Grocery, Fuel, Salary" required autocomplete="off">
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
                <input id="m_amount" type="number" step="0.01" name="amount" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="m_payment_method">
                    <span id="payment-method-label">From:</span>
                </label>
                <select id="m_payment_method" name="payment_method" required>
                    <option value="">-- Select Bank --</option>
                    <?php
                    // Get all banks from database — value is bank id (integer)
                    $allBanks = $queries->getAllBanks();
                    while($bank = $allBanks->fetchArray(SQLITE3_ASSOC)) {
                        $selected = ($view === 'bank' && $currentBank && $bank['id'] === $currentBank['id']) ? ' selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($bank['id']) ?>"<?= $selected ?>><?= htmlspecialchars($bank['name']) ?></option>
                    <?php } ?>
                </select>
                <small style="color: var(--muted); margin-top: 4px; display: block;" id="payment-method-hint">
                    Where the payment will be deducted from
                </small>
                <small style="color: #3498db; margin-top: 2px; display: block; font-size: 0.8rem;">
                    💡 Tip: Each transaction can use a different bank, even within the same wallet
                </small>
            </div>
            <?php if ($view === 'wallet'): ?>
            <!-- When in wallet view, wallet is auto-selected via hidden input -->
            <input type="hidden" id="m_wallet" name="wallet" value="<?= htmlspecialchars($id) ?>">
            <?php else: ?>
            <div class="form-group">
                <label for="m_wallet">
                    Wallet
                    <?php if ($view === 'bank'): ?>
                    <span style="color: var(--muted); font-weight: normal; font-size: 0.9rem;">(Optional — leave blank for bank-direct entries like salary, interest)</span>
                    <?php endif; ?>
                </label>
                <select id="m_wallet" name="wallet">
                    <option value="">-- None (Bank Direct) --</option>
                    <?php
                    $allWallets = $queries->getAllWallets();
                    while($w = $allWallets->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($w['id']) ?>"><?= htmlspecialchars($w['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="m_category">Category</label>
                <select id="m_category" name="category_id">
                    <option value="">-- Uncategorised --</option>
                    <?php
                    $allCats = $queries->getAllCategories();
                    while($cat = $allCats->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($cat['id']) ?>" data-color="<?= htmlspecialchars($cat['color']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="m_note">Note</label>
                <input id="m_note" type="text" name="note" placeholder="Optional notes..." autocomplete="off">
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
            <button id="wallet-modal-close" class="modal-close-btn">✕</button>
        </div>
        <form id="wallet-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="wallet-id" name="wallet_id" value="">
            <input type="hidden" id="wallet-action" name="action" value="wallet_add">
            <input type="hidden" id="wallet-return-to" name="return_to" value="">

            <div class="form-group">
                <label for="wallet-name">Wallet Name</label>
                <input id="wallet-name" type="text" name="name" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="wallet-description">Description</label>
                <input id="wallet-description" type="text" name="description" placeholder="Optional description..." autocomplete="off">
            </div>
            <div class="form-group">
                <label for="wallet-opening-balance">Opening Balance (₹)</label>
                <input id="wallet-opening-balance" type="number" step="0.01" name="opening_balance" value="0" min="0" autocomplete="off">
                <small style="color: var(--muted); margin-top: 4px; display: block;">
                    Existing balance in this wallet before you started tracking. Leave 0 for new wallets.
                </small>
            </div>
            <div class="form-group">
                <label for="wallet-bank">Default Bank/Payment Method (Optional)</label>
                <select id="wallet-bank" name="bank_id">
                    <option value="">-- None (Choose per transaction) --</option>
                    <?php
                    $allBanksForSelect = $queries->getAllBanks();
                    while($b = $allBanksForSelect->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($b['id']) ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php } ?>
                </select>
                <small style="color: var(--muted); margin-top: 4px; display: block;">
                    Set a default bank for convenience, but you can override it for each transaction. <strong>Wallets can contain transactions from multiple banks.</strong>
                </small>
            </div>
            <div class="form-group">
                <label for="wallet-type">Wallet Type</label>
                <select id="wallet-type" name="wallet_type" required>
                    <option value="balance">💳 Running Balance Wallet (tracks money over months)</option>
                    <option value="budget">📊 Monthly Budget Tracker (budget vs spending each month)</option>
                </select>
                <small style="color: var(--muted); margin-top: 4px; display: block;">
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
            <button id="bank-modal-close" class="modal-close-btn">✕</button>
        </div>
        <form id="bank-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="bank-id" name="bank_id" value="">
            <input type="hidden" id="bank-action" name="action" value="bank_add">

            <div class="form-group">
                <label for="bank-name">Bank Name</label>
                <input id="bank-name" type="text" name="name" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bank-description">Description</label>
                <input id="bank-description" type="text" name="description" placeholder="Optional description..." autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bank-opening-balance">Opening Balance (₹)</label>
                <input id="bank-opening-balance" type="number" step="0.01" name="opening_balance" value="0" min="0" autocomplete="off">
                <small style="color: var(--muted); margin-top: 4px; display: block;">
                    Existing balance in this account before you started tracking. Leave 0 for new accounts.
                </small>
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
            <button id="budget-modal-close" class="modal-close-btn">✕</button>
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
                <span style="display:block;font-size:0.95rem;font-weight:600;color:var(--text);margin-bottom:6px;">Month & Year</span>
                <div style="display: flex; gap: 10px; width: 100%;">
                    <div style="flex: 1;">
                        <label for="budget-month-select" style="font-size: 0.9rem; display: block; margin-bottom: 5px;">Month</label>
                        <select id="budget-month-select" required style="width: 100%;">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="budget-year-select" style="font-size: 0.9rem; display: block; margin-bottom: 5px;">Year</label>
                        <select id="budget-year-select" required style="width: 100%;">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="budget-expected-income">Expected Income (₹) <span style="color: var(--muted); font-weight: normal;">Optional</span></label>
                <input id="budget-expected-income" type="number" step="0.01" name="expected_income" placeholder="Leave blank if not needed..." autocomplete="off">
            </div>

            <div class="form-group">
                <label for="budget-expected-expense">Expected Expense (₹) <span style="color: var(--muted); font-weight: normal;">Required</span></label>
                <input id="budget-expected-expense" type="number" step="0.01" name="expected_expense" value="0" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="budget-notes">Notes</label>
                <input id="budget-notes" type="text" name="notes" placeholder="e.g., Planned shopping, medical bills expected..." autocomplete="off">
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
            <button type="button" class="modal-close-btn" onclick="document.getElementById('bank-monthly-statement-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadBankMonthlyStatement(event)">
            <div class="form-group">
                <label for="bank-month-select">Select Month & Year</label>
                <select id="bank-month-select" required>
                    <option value="">-- Select Month --</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn">📥 PDF</button>
                <button type="button" class="btn btn-csv" onclick="downloadBankMonthlyCsv(event)">📋 CSV</button>
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
            <button type="button" class="modal-close-btn" onclick="document.getElementById('bank-custom-range-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadBankCustomRange(event)">
            <div class="form-group">
                <label for="bank-from-date">From Date</label>
                <input id="bank-from-date" type="date" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bank-to-date">To Date</label>
                <input id="bank-to-date" type="date" required autocomplete="off">
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn">📥 PDF</button>
                <button type="button" class="btn btn-csv" onclick="downloadBankCustomRangeCsv(event)">📋 CSV</button>
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
            <button type="button" class="modal-close-btn" onclick="document.getElementById('wallet-monthly-statement-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadWalletMonthlyStatement(event)">
            <div class="form-group">
                <label for="wallet-month-select">Select Month & Year</label>
                <select id="wallet-month-select" required>
                    <option value="">-- Select Month --</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn">📥 PDF</button>
                <button type="button" class="btn btn-csv" onclick="downloadWalletMonthlyCsv(event)">📋 CSV</button>
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
            <button type="button" class="modal-close-btn" onclick="document.getElementById('wallet-custom-range-modal').classList.remove('open');">✕</button>
        </div>
        <form onsubmit="downloadWalletCustomRange(event)">
            <div class="form-group">
                <label for="wallet-from-date">From Date</label>
                <input id="wallet-from-date" type="date" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="wallet-to-date">To Date</label>
                <input id="wallet-to-date" type="date" required autocomplete="off">
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn">📥 PDF</button>
                <button type="button" class="btn btn-csv" onclick="downloadWalletCustomRangeCsv(event)">📋 CSV</button>
                <button type="button" class="back-link" onclick="document.getElementById('wallet-custom-range-modal').classList.remove('open');">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transfer-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="transfer-modal-title">
        <div class="modal-header">
            <h3 id="transfer-modal-title">🔄 Bank Transfer</h3>
            <button id="transfer-modal-close" class="modal-close-btn">✕</button>
        </div>
        <form id="transfer-form" action="backend.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="transfer_add">
            <!-- ref_bank_id: used to redirect back to the originating bank view -->
            <input type="hidden" id="transfer-ref-bank" name="ref_bank_id" value="<?= ($view === 'bank' && $currentBank) ? htmlspecialchars($currentBank['id']) : '' ?>">

            <div class="form-group">
                <label for="transfer-date">Date</label>
                <input id="transfer-date" type="date" name="date" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="transfer-title">Title / Reference</label>
                <input id="transfer-title" type="text" name="title" value="Transfer" placeholder="e.g., Transfer, NEFT, IMPS" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="transfer-from">From Bank</label>
                <select id="transfer-from" name="from_bank_id" required>
                    <option value="">-- Select source bank --</option>
                    <?php
                    $transferBanks = $queries->getAllBanks();
                    while($tb = $transferBanks->fetchArray(SQLITE3_ASSOC)) {
                        $sel = ($view === 'bank' && $currentBank && $tb['id'] === $currentBank['id']) ? ' selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($tb['id']) ?>"<?= $sel ?>><?= htmlspecialchars($tb['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="transfer-to">To Bank</label>
                <select id="transfer-to" name="to_bank_id" required>
                    <option value="">-- Select destination bank --</option>
                    <?php
                    $transferBanks2 = $queries->getAllBanks();
                    while($tb2 = $transferBanks2->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= htmlspecialchars($tb2['id']) ?>"><?= htmlspecialchars($tb2['name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label for="transfer-amount">Amount (₹)</label>
                <input id="transfer-amount" type="number" step="0.01" name="amount" required min="0.01" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="transfer-note">Note</label>
                <input id="transfer-note" type="text" name="note" placeholder="Optional reference / note..." autocomplete="off">
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit">💾 Record Transfer</button>
                <button type="button" id="transfer-modal-cancel" class="back-link">Cancel</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>

<script src="resource/js/theme.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/modal.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/transactions.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/wallets.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/statements.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/banks.js?v=<?= htmlspecialchars($version) ?>"></script>
<script src="resource/js/budget.js?v=<?= htmlspecialchars($version) ?>"></script>
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

    // Filter bar toggle
    const filterToggleBtn = document.getElementById('filter-toggle');
    const filterBar = document.getElementById('filter-bar');
    if (filterToggleBtn && filterBar) {
        filterToggleBtn.addEventListener('click', function() {
            filterBar.classList.toggle('filter-bar--open');
            filterToggleBtn.classList.toggle('filter-active', filterBar.classList.contains('filter-bar--open'));
        });
        // Keep button highlighted if already open (active filter on page load)
        if (filterBar.classList.contains('filter-bar--open')) {
            filterToggleBtn.classList.add('filter-active');
        }
    }

    // Transfer modal wiring
    const transferDateInput = document.getElementById('transfer-date');
    if (transferDateInput) {
        transferDateInput.valueAsDate = new Date();
    }
    const openTransferBtn = document.getElementById('open-transfer');
    if (openTransferBtn) {
        openTransferBtn.addEventListener('click', function() {
            document.getElementById('transfer-modal-overlay').classList.add('open');
        });
    }
    const closeTransferBtn = document.getElementById('transfer-modal-close');
    if (closeTransferBtn) {
        closeTransferBtn.addEventListener('click', function() {
            document.getElementById('transfer-modal-overlay').classList.remove('open');
        });
    }
    const cancelTransferBtn = document.getElementById('transfer-modal-cancel');
    if (cancelTransferBtn) {
        cancelTransferBtn.addEventListener('click', function() {
            document.getElementById('transfer-modal-overlay').classList.remove('open');
        });
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


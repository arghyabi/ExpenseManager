<?php
require 'dbcon.php';
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
# Balance
# -----------------------
$balance = $db->querySingle("
    SELECT IFNULL(SUM(
        CASE WHEN type='income' THEN amount ELSE -amount END
    ),0)
    FROM transactions
");

# -----------------------
# Monthly summary
# -----------------------
$monthly = $db->query("
    SELECT * FROM monthly_summary ORDER BY year DESC, month DESC
");

# -----------------------
# Transactions (paginated)
# -----------------------
$perPage = 10;
$page = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;
$totalCount = $db->querySingle("SELECT COUNT(*) FROM transactions");
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare(
    "SELECT t.*, c.name as category, a.name as account
     FROM transactions t
     LEFT JOIN categories c ON t.category_id=c.id
     LEFT JOIN accounts a ON t.account_id=a.id
     ORDER BY date DESC
     LIMIT ? OFFSET ?"
);
$stmt->bindValue(1, $perPage, SQLITE3_INTEGER);
$stmt->bindValue(2, $offset, SQLITE3_INTEGER);
$tx = $stmt->execute();

# categories/accounts for modal
$cats = $db->query("SELECT * FROM categories");
$accs = $db->query("SELECT * FROM accounts");

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Manager</title>
    <link rel="stylesheet" href="resource/css/style.css">
</head>
<body>

<div class="balance-card">
<div class="app-header">
    <h1>💰 Expense Manager <?php if($version) echo '<span class="version">' . htmlspecialchars($version) . '</span>'; ?></h1>
    <div>
        <button id="theme-toggle" class="theme-toggle">🌙</button>
    </div>
</div>

<div class="balance-card">
    <h2>Current Balance</h2>
    <div class="balance-amount">₹ <?= number_format($balance,2) ?></div>
</div>

<button id="open-add" class="btn" type="button">➕ Add Transaction</button>

<!-- Add/Edit Transaction Modal -->
<div id="modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <h3 id="modal-title">Add Transaction</h3>
            <button id="modal-close" class="theme-toggle">✕</button>
        </div>
        <form id="tx-form" action="backend.php" method="post">
            <input type="hidden" id="tx-id" name="tx_id" value="">
            <input type="hidden" id="tx-action" name="action" value="add">
            <div class="form-group">
                <label for="m_date">Date</label>
                <input id="m_date" type="date" name="date" required>
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
                <label for="m_category">Category</label>
                <select id="m_category" name="category" required>
                    <option value="">-- Select Category --</option>
                    <?php
                    // Reset cats result set for re-use
                    $catsRes = $db->query("SELECT * FROM categories");
                    while($c = $catsRes->fetchArray(SQLITE3_ASSOC)) {
                    ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
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

<hr>

<h2>Monthly Summary</h2>
<div class="grid monthly-grid">
    <?php while($r = $monthly->fetchArray(SQLITE3_ASSOC)) { ?>
    <div class="card month-card">
        <div class="card-header"><?= htmlspecialchars($r['month']) ?> <?= htmlspecialchars($r['year']) ?></div>
        <div class="card-body">
            <div class="card-row"><span class="label">Income</span><span class="value">₹ <?= number_format($r['income'],2) ?></span></div>
            <div class="card-row"><span class="label">Expense</span><span class="value">₹ <?= number_format($r['expense'],2) ?></span></div>
            <div class="card-row"><span class="label">Net</span><span class="value">₹ <?= number_format($r['net'],2) ?></span></div>
        </div>
    </div>
    <?php } ?>
</div>

<hr>

<h2>Recent Transactions</h2>
<div class="grid tx-grid">
    <?php while($r = $tx->fetchArray(SQLITE3_ASSOC)) { ?>
    <div class="card tx-card">
        <div class="tx-actions">
            <div class="tx-menu-container">
                <button class="tx-menu-btn" data-id="<?= htmlspecialchars($r['id']) ?>" data-date="<?= htmlspecialchars($r['date']) ?>" data-type="<?= htmlspecialchars($r['type']) ?>" data-amount="<?= htmlspecialchars($r['amount']) ?>" data-category="<?= htmlspecialchars($r['category_id']) ?>" data-note="<?= htmlspecialchars($r['note']) ?>" data-desc="<?= htmlspecialchars($r['date'] . ' - ' . $r['category']) ?>">⋮</button>
                <div class="tx-menu-dropdown">
                    <button class="tx-menu-item tx-edit-option">✏️ Edit</button>
                    <button class="tx-menu-item tx-delete-option">🗑️ Delete</button>
                </div>
            </div>
        </div>
        <div class="tx-row">
            <div class="tx-left">
                <div class="tx-date"><?= htmlspecialchars($r['date']) ?></div>
                <?php if(!empty($r['note'])): ?>
                <div class="tx-note"><?= htmlspecialchars($r['note']) ?></div>
                <?php endif; ?>
            </div>
            <div class="tx-right">
                <div class="type-badge <?= $r['type'] ?>"><?= ucfirst($r['type']) ?></div>
                <div class="tx-amount">₹ <?= number_format($r['amount'],2) ?></div>
            </div>
        </div>
        <div class="tx-meta">
            <span class="meta-item"><?= htmlspecialchars($r['category']) ?></span>
            <span class="meta-sep">·</span>
            <span class="meta-item"><?= htmlspecialchars($r['account']) ?></span>
        </div>
    </div>
    <?php } ?>
</div>

</body>
</html>

<script>
// Theme toggle with persistence
(function(){
    const toggle = document.getElementById('theme-toggle');
    const root = document.documentElement;
    const stored = localStorage.getItem('theme');
    function applyTheme(name){
        if(name === 'dark') root.classList.add('dark-theme'); else root.classList.remove('dark-theme');
        toggle.textContent = name === 'dark' ? '☀️' : '🌙';
    }
    applyTheme(stored === 'dark' ? 'dark' : 'light');
    toggle.addEventListener('click', ()=>{
        const current = root.classList.contains('dark-theme') ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem('theme', next);
    });
})();
</script>

<script>
// Modal open/close
(function(){
    const overlay = document.getElementById('modal-overlay');
    const openBtn = document.getElementById('open-add');
    const closeBtn = document.getElementById('modal-close');
    const cancelBtn = document.getElementById('modal-cancel');
    const form = document.getElementById('tx-form');
    const modalTitle = document.getElementById('modal-title');
    const actionInput = document.getElementById('tx-action');

    function openModal(){
        if(overlay){
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden','false');
            document.body.style.overflow='hidden';
        }
    }
    function closeModal(){
        if(overlay){
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden','true');
            document.body.style.overflow='auto';
            // Reset form
            form.reset();
            document.getElementById('tx-id').value = '';
            actionInput.value = 'add';
            modalTitle.textContent = 'Add Transaction';
        }
    }

    // Add transaction
    openBtn && openBtn.addEventListener('click', openModal);

    // Menu toggle
    document.querySelectorAll('.tx-menu-btn').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.stopPropagation();
            const dropdown = this.nextElementSibling;
            const allDropdowns = document.querySelectorAll('.tx-menu-dropdown');

            // Close all other dropdowns
            allDropdowns.forEach(d => {
                if(d !== dropdown) d.classList.remove('open');
            });

            // Toggle current dropdown
            dropdown.classList.toggle('open');
        });
    });

    // Edit transaction
    document.querySelectorAll('.tx-edit-option').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const menuBtn = this.closest('.tx-menu-container').querySelector('.tx-menu-btn');
            const id = menuBtn.dataset.id;
            const date = menuBtn.dataset.date;
            const type = menuBtn.dataset.type;
            const amount = menuBtn.dataset.amount;
            const categoryId = menuBtn.dataset.category;
            const note = menuBtn.dataset.note;

            // Close dropdown
            this.closest('.tx-menu-dropdown').classList.remove('open');

            // Populate form
            document.getElementById('tx-id').value = id;
            document.getElementById('m_date').value = date;
            document.getElementById('m_type').value = type;
            document.getElementById('m_amount').value = amount;
            document.getElementById('m_category').value = categoryId;
            document.getElementById('m_note').value = note;

            // Set action to edit
            actionInput.value = 'edit';
            modalTitle.textContent = 'Edit Transaction';
            openModal();
        });
    });

    // Delete transaction
    document.querySelectorAll('.tx-delete-option').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const menuBtn = this.closest('.tx-menu-container').querySelector('.tx-menu-btn');
            const id = menuBtn.dataset.id;
            const desc = menuBtn.dataset.desc;

            // Close dropdown
            this.closest('.tx-menu-dropdown').classList.remove('open');

            if(confirm('Delete transaction: ' + desc + '?')){
                // Create form and submit
                const delForm = document.createElement('form');
                delForm.method = 'POST';
                delForm.action = 'backend.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'tx_id';
                idInput.value = id;

                delForm.appendChild(actionInput);
                delForm.appendChild(idInput);
                document.body.appendChild(delForm);
                delForm.submit();
            }
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if(!e.target.closest('.tx-menu-container')){
            document.querySelectorAll('.tx-menu-dropdown').forEach(d => d.classList.remove('open'));
        }
    });

    // Close handlers
    closeBtn && closeBtn.addEventListener('click', closeModal);
    cancelBtn && cancelBtn.addEventListener('click', closeModal);
    overlay && overlay.addEventListener('click', (e)=>{ if(e.target === overlay) closeModal(); });
    document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeModal(); });
})();
</script>

<div class="pagination-wrap">
    <div class="pagination">
        <?php if($page > 1): ?>
            <a class="btn" href="?page=<?= $page-1 ?>">← Prev</a>
        <?php endif; ?>

        <span class="page-info"><?= $page ?> of <?= $totalPages ?> (<?= $totalCount ?>)</span>

        <?php if($page < $totalPages): ?>
            <a class="btn" href="?page=<?= $page+1 ?>">Next →</a>
        <?php endif; ?>
    </div>
</div>

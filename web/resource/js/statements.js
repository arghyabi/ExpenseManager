// Statement Download Handlers

// Get current bank ID from page
function getBankId() {
    const menuBtn = document.querySelector('.bank-menu-btn');
    return window.currentBankId || null;
}

// Get current wallet ID from page
function getWalletId() {
    const menuBtn = document.querySelector('.wallet-menu-btn');
    return window.currentWalletId || null;
}

// Toggle bank menu dropdown
document.addEventListener('click', function(e) {
    if (e.target.closest('.bank-menu-btn')) {
        const dropdown = e.target.closest('.bank-menu-btn').nextElementSibling;
        dropdown.classList.toggle('open');
        e.stopPropagation();
    } else if (e.target.closest('.wallet-menu-btn')) {
        const dropdown = e.target.closest('.wallet-menu-btn').nextElementSibling;
        dropdown.classList.toggle('open');
        e.stopPropagation();
    } else {
        document.querySelectorAll('.bank-menu-dropdown, .wallet-menu-dropdown').forEach(d => {
            d.classList.remove('open');
        });
    }
});

// Bank Menu Options
document.addEventListener('click', function(e) {
    if (e.target.closest('.bank-edit-option')) {
        const btn = e.target.closest('.bank-edit-option');
        const id = btn.getAttribute('data-id');
        const name = btn.getAttribute('data-name');
        const description = btn.getAttribute('data-description');

        document.getElementById('bank-id').value = id;
        document.getElementById('bank-name').value = name;
        document.getElementById('bank-description').value = description;
        document.getElementById('bank-modal-overlay').classList.add('open');

        document.querySelectorAll('.bank-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.bank-full-statement-btn')) {
        downloadFullBankStatement();
        document.querySelectorAll('.bank-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.bank-monthly-statement-btn')) {
        openBankMonthlyModal();
        document.querySelectorAll('.bank-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.bank-custom-range-btn')) {
        document.getElementById('bank-custom-range-modal').classList.add('open');
        document.querySelectorAll('.bank-menu-dropdown').forEach(d => d.classList.remove('open'));
    }
});

// Wallet Menu Options
document.addEventListener('click', function(e) {
    if (e.target.closest('.wallet-edit-option')) {
        const btn = e.target.closest('.wallet-edit-option');
        const id = btn.getAttribute('data-id');
        const name = btn.getAttribute('data-name');
        const walletType = btn.getAttribute('data-wallet-type');
        const description = btn.getAttribute('data-description');
        const bankId = btn.getAttribute('data-bank-id');

        window.openWalletModal('edit', id, name, description, walletType, bankId);

        document.querySelectorAll('.wallet-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.wallet-full-statement-btn')) {
        downloadFullWalletStatement();
        document.querySelectorAll('.wallet-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.wallet-monthly-statement-btn')) {
        openWalletMonthlyModal();
        document.querySelectorAll('.wallet-menu-dropdown').forEach(d => d.classList.remove('open'));
    }

    if (e.target.closest('.wallet-custom-range-btn')) {
        document.getElementById('wallet-custom-range-modal').classList.add('open');
        document.querySelectorAll('.wallet-menu-dropdown').forEach(d => d.classList.remove('open'));
    }
});

// Get bank ID from URL or data attribute
function getCurrentBankId() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('id');
}

// Get wallet ID from URL or data attribute
function getCurrentWalletId() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('id');
}

// Download Bank Full Statement
function downloadFullBankStatement() {
    const bankId = getCurrentBankId();
    if (bankId) {
        window.location.href = `gen_statement.php?view=bank&id=${bankId}&type=full`;
    }
}

// Download Wallet Full Statement
function downloadFullWalletStatement() {
    const walletId = getCurrentWalletId();
    if (walletId) {
        window.location.href = `gen_statement.php?view=wallet&id=${walletId}&type=full`;
    }
}

// Open Bank Monthly Statement Modal
function openBankMonthlyModal() {
    const bankId = getCurrentBankId();
    const select = document.getElementById('bank-month-select');

    // Fetch and populate months
    fetch(`backend.php?action=get_bank_months&id=${bankId}`)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">-- Select Month --</option>';
            data.forEach(m => {
                const option = document.createElement('option');
                option.value = m.year + '-' + String(m.month).padStart(2, '0');
                option.textContent = m.display;
                select.appendChild(option);
            });
            document.getElementById('bank-monthly-statement-modal').classList.add('open');
        });
}

// Open Wallet Monthly Statement Modal
function openWalletMonthlyModal() {
    const walletId = getCurrentWalletId();
    const select = document.getElementById('wallet-month-select');

    // Fetch and populate months
    fetch(`backend.php?action=get_wallet_months&id=${walletId}`)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">-- Select Month --</option>';
            data.forEach(m => {
                const option = document.createElement('option');
                option.value = m.year + '-' + String(m.month).padStart(2, '0');
                option.textContent = m.display;
                select.appendChild(option);
            });
            document.getElementById('wallet-monthly-statement-modal').classList.add('open');
        });
}

// Download Bank Monthly Statement
function downloadBankMonthlyStatement(e) {
    e.preventDefault();
    const bankId = getCurrentBankId();
    const month = document.getElementById('bank-month-select').value;

    if (bankId && month) {
        window.location.href = `gen_statement.php?view=bank&id=${bankId}&type=monthly&month=${month}`;
        document.getElementById('bank-monthly-statement-modal').classList.remove('open');
    }
}

// Download Wallet Monthly Statement
function downloadWalletMonthlyStatement(e) {
    e.preventDefault();
    const walletId = getCurrentWalletId();
    const month = document.getElementById('wallet-month-select').value;

    if (walletId && month) {
        window.location.href = `gen_statement.php?view=wallet&id=${walletId}&type=monthly&month=${month}`;
        document.getElementById('wallet-monthly-statement-modal').classList.remove('open');
    }
}

// Download Bank Custom Range Statement
function downloadBankCustomRange(e) {
    e.preventDefault();
    const bankId = getCurrentBankId();
    const fromDate = document.getElementById('bank-from-date').value;
    const toDate = document.getElementById('bank-to-date').value;

    if (bankId && fromDate && toDate) {
        if (new Date(fromDate) > new Date(toDate)) {
            alert('From Date must be before To Date');
            return;
        }
        window.location.href = `gen_statement.php?view=bank&id=${bankId}&type=custom&from_date=${fromDate}&to_date=${toDate}`;
        document.getElementById('bank-custom-range-modal').classList.remove('open');
    }
}

// Download Wallet Custom Range Statement
function downloadWalletCustomRange(e) {
    e.preventDefault();
    const walletId = getCurrentWalletId();
    const fromDate = document.getElementById('wallet-from-date').value;
    const toDate = document.getElementById('wallet-to-date').value;

    if (walletId && fromDate && toDate) {
        if (new Date(fromDate) > new Date(toDate)) {
            alert('From Date must be before To Date');
            return;
        }
        window.location.href = `gen_statement.php?view=wallet&id=${walletId}&type=custom&from_date=${fromDate}&to_date=${toDate}`;
        document.getElementById('wallet-custom-range-modal').classList.remove('open');
    }
}

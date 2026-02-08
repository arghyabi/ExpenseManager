/**
 * Wallet Management
 * Handles wallet operations (view, add, edit, delete)
 */
(function() {
    const walletModal = document.getElementById('wallet-modal-overlay');
    const walletForm = document.getElementById('wallet-form');
    const walletTitle = document.getElementById('wallet-modal-title');
    const walletActionInput = document.getElementById('wallet-action');

    window.openWalletModal = function(mode = 'add', walletId = null, walletName = '', bankId = '', description = '') {
        if (!walletModal) return;

        document.getElementById('wallet-id').value = walletId || '';
        document.getElementById('wallet-name').value = walletName;
        document.getElementById('wallet-bank').value = bankId;
        document.getElementById('wallet-description').value = description;
        walletActionInput.value = mode === 'add' ? 'wallet_add' : 'wallet_edit';
        walletTitle.textContent = mode === 'add' ? 'Add Wallet' : 'Edit Wallet';

        walletModal.classList.add('open');
        walletModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    window.closeWalletModal = function() {
        if (walletModal) {
            walletModal.classList.remove('open');
            walletModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
            if (walletForm) walletForm.reset();
        }
    };

    // Close wallet modal handlers
    const walletCloseBtn = document.getElementById('wallet-modal-close');
    const walletCancelBtn = document.getElementById('wallet-modal-cancel');

    if (walletCloseBtn) walletCloseBtn.addEventListener('click', window.closeWalletModal);
    if (walletCancelBtn) walletCancelBtn.addEventListener('click', window.closeWalletModal);

    if (walletModal) {
        walletModal.addEventListener('click', (e) => {
            if (e.target === walletModal) window.closeWalletModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && walletModal && walletModal.classList.contains('open')) {
            window.closeWalletModal();
        }
    });

    // Click-to-view: Navigate when clicking card body
    document.addEventListener('click', function(e) {
        const clickable = e.target.closest('.wallet-card .card-body.card-clickable');
        if (clickable && !e.target.closest('.card-menu-container')) {
            const href = clickable.dataset.href;
            if (href) window.location.href = href;
        }
    });

    // 3-dot menu toggle for wallets
    document.addEventListener('click', function(e) {
        if (e.target.closest('.wallet-card .card-menu-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.card-menu-btn');
            const dropdown = btn.closest('.card-menu-container').querySelector('.card-menu-dropdown');

            // Close all other menus
            document.querySelectorAll('.card-menu-dropdown.open').forEach(d => {
                if (d !== dropdown) d.classList.remove('open');
            });

            dropdown.classList.toggle('open');
        }
    });

    // Close menu when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.card-menu-container')) {
            document.querySelectorAll('.card-menu-dropdown.open').forEach(d => d.classList.remove('open'));
        }
    });

    // Edit wallet
    document.addEventListener('click', function(e) {
        if (e.target.closest('.wallet-edit-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.wallet-edit-btn');
            const walletId = btn.dataset.id;
            const walletName = btn.dataset.name;
            const bankId = btn.dataset.bank;
            const description = btn.dataset.description || '';
            window.openWalletModal('edit', walletId, walletName, bankId, description);
        }
    });

    // Delete wallet
    document.addEventListener('click', function(e) {
        if (e.target.closest('.wallet-delete-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.wallet-delete-btn');
            const walletId = btn.dataset.id;
            const walletName = btn.dataset.name;

            if (confirm('Delete wallet "' + walletName + '"? All transactions will be lost.')) {
                const delForm = document.createElement('form');
                delForm.method = 'POST';
                delForm.action = 'backend.php';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.csrfToken;

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'wallet_delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'wallet_id';
                idInput.value = walletId;

                delForm.appendChild(csrfInput);
                delForm.appendChild(actionInput);
                delForm.appendChild(idInput);
                document.body.appendChild(delForm);
                delForm.submit();
            }
        }
    });
})();

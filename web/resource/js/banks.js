/**
 * Bank Management
 * Handles bank operations (view, add, edit, delete)
 */
(function() {
    const bankModal = document.getElementById('bank-modal-overlay');
    const bankForm = document.getElementById('bank-form');
    const bankTitle = document.getElementById('bank-modal-title');
    const bankActionInput = document.getElementById('bank-action');

    window.openBankModal = function(mode = 'add', bankId = null, bankName = '', description = '') {
        if (!bankModal) return;

        document.getElementById('bank-id').value = bankId || '';
        document.getElementById('bank-name').value = bankName;
        document.getElementById('bank-description').value = description;
        bankActionInput.value = mode === 'add' ? 'bank_add' : 'bank_edit';
        bankTitle.textContent = mode === 'add' ? 'Add Bank' : 'Edit Bank';

        bankModal.classList.add('open');
        bankModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    window.closeBankModal = function() {
        if (bankModal) {
            bankModal.classList.remove('open');
            bankModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
            if (bankForm) bankForm.reset();
        }
    };

    // Close bank modal handlers
    const bankCloseBtn = document.getElementById('bank-modal-close');
    const bankCancelBtn = document.getElementById('bank-modal-cancel');

    if (bankCloseBtn) bankCloseBtn.addEventListener('click', window.closeBankModal);
    if (bankCancelBtn) bankCancelBtn.addEventListener('click', window.closeBankModal);

    if (bankModal) {
        bankModal.addEventListener('click', (e) => {
            if (e.target === bankModal) window.closeBankModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && bankModal && bankModal.classList.contains('open')) {
            window.closeBankModal();
        }
    });

    // Click-to-view: Navigate when clicking card body
    document.addEventListener('click', function(e) {
        const clickable = e.target.closest('.bank-card .card-body.card-clickable');
        if (clickable && !e.target.closest('.card-menu-container')) {
            const href = clickable.dataset.href;
            if (href) window.location.href = href;
        }
    });

    // 3-dot menu toggle for banks
    document.addEventListener('click', function(e) {
        if (e.target.closest('.bank-card .card-menu-btn')) {
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

    // Edit bank
    document.addEventListener('click', function(e) {
        if (e.target.closest('.bank-edit-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.bank-edit-btn');
            const bankId = btn.dataset.id;
            const bankName = btn.dataset.name;
            const description = btn.dataset.description || '';
            window.openBankModal('edit', bankId, bankName, description);
        }
    });

    // Delete bank
    document.addEventListener('click', function(e) {
        if (e.target.closest('.bank-delete-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.bank-delete-btn');
            const bankId = btn.dataset.id;
            const bankName = btn.dataset.name;

            const dependentWallets = document.querySelectorAll('[data-bank-id="' + bankId + '"]').length;
            if (dependentWallets > 0) {
                alert('Cannot delete bank "' + bankName + '". It has dependent wallets. Please delete or reassign them first.');
                return;
            }

            if (confirm('Delete bank "' + bankName + '"?')) {
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
                actionInput.value = 'bank_delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'bank_id';
                idInput.value = bankId;

                delForm.appendChild(csrfInput);
                delForm.appendChild(actionInput);
                delForm.appendChild(idInput);
                document.body.appendChild(delForm);
                delForm.submit();
            }
        }
    });
})();

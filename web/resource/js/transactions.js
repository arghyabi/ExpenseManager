/**
 * Transaction Management
 * Handles edit and delete transaction operations
 */
(function() {
    const actionInput = document.getElementById('tx-action');
    const modalTitle = document.getElementById('modal-title');

    // Edit transaction
    document.addEventListener('click', function(e) {
        if (e.target.closest('.tx-edit-option')) {
            e.preventDefault();
            const menuBtn = e.target.closest('.tx-menu-container').querySelector('.tx-menu-btn');
            const id = menuBtn.dataset.id;
            const date = menuBtn.dataset.date;
            const type = menuBtn.dataset.type;
            const amount = menuBtn.dataset.amount;
            const walletId = menuBtn.dataset.wallet;
            const accountId = menuBtn.dataset.account;
            const note = menuBtn.dataset.note;
            const title = menuBtn.dataset.title;
            const paymentMethod = menuBtn.dataset.paymentMethod || '';

            // Close dropdown
            e.target.closest('.tx-menu-dropdown').classList.remove('open');

            // Populate form
            document.getElementById('tx-id').value = id;
            document.getElementById('m_date').value = date;
            document.getElementById('m_title').value = title;
            document.getElementById('m_type').value = type;
            document.getElementById('m_amount').value = amount;

            // Update payment method label based on type
            const methodLabel = document.getElementById('payment-method-label');
            const methodHint = document.getElementById('payment-method-hint');
            if (type === 'income') {
                methodLabel.textContent = 'To:';
                methodHint.textContent = 'Where the payment will be received';
            } else {
                methodLabel.textContent = 'From:';
                methodHint.textContent = 'Where the payment will be deducted from';
            }

            // Set wallet (if the dropdown exists)
            const walletField = document.getElementById('m_wallet');
            if (walletField) {
                walletField.value = walletId;
            }

            document.getElementById('m_account').value = accountId;
            document.getElementById('m_note').value = note;
            document.getElementById('m_payment_method').value = paymentMethod;

            // Set action to edit
            actionInput.value = 'tx_edit';
            modalTitle.textContent = 'Edit Transaction';
            window.openTransactionModal();
        }
    });

    // Delete transaction
    document.addEventListener('click', function(e) {
        if (e.target.closest('.tx-delete-option')) {
            e.preventDefault();
            const menuBtn = e.target.closest('.tx-menu-container').querySelector('.tx-menu-btn');
            const id = menuBtn.dataset.id;
            const desc = menuBtn.dataset.desc;

            // Close dropdown
            e.target.closest('.tx-menu-dropdown').classList.remove('open');

            if (confirm('Delete transaction: ' + desc + '?')) {
                // Create form and submit
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
                actionInput.value = 'tx_delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'tx_id';
                idInput.value = id;

                delForm.appendChild(csrfInput);
                delForm.appendChild(actionInput);
                delForm.appendChild(idInput);

                // Detect current view context and include relevant ID
                const urlParams = new URLSearchParams(window.location.search);
                const view = urlParams.get('view');
                const contextId = urlParams.get('id');
                const budgetMonth = urlParams.get('budget_month');

                if (view === 'wallet' && contextId) {
                    const walletInput = document.createElement('input');
                    walletInput.type = 'hidden';
                    walletInput.name = 'wallet_id';
                    walletInput.value = contextId;
                    delForm.appendChild(walletInput);

                    // Preserve budget_month if present
                    if (budgetMonth) {
                        const monthInput = document.createElement('input');
                        monthInput.type = 'hidden';
                        monthInput.name = 'budget_month';
                        monthInput.value = budgetMonth;
                        delForm.appendChild(monthInput);
                    }
                } else if (view === 'bank' && contextId) {
                    const bankInput = document.createElement('input');
                    bankInput.type = 'hidden';
                    bankInput.name = 'bank_id';
                    bankInput.value = contextId;
                    delForm.appendChild(bankInput);
                }

                document.body.appendChild(delForm);
                delForm.submit();
            }
        }
    });

    // Menu toggle
    document.addEventListener('click', function(e) {
        const menuBtn = e.target.closest('.tx-menu-btn');
        if (menuBtn) {
            e.stopPropagation();
            const dropdown = menuBtn.nextElementSibling;
            const card = menuBtn.closest('.tx-card');
            const allDropdowns = document.querySelectorAll('.tx-menu-dropdown');
            const allCards = document.querySelectorAll('.tx-card');

            // Close all other dropdowns and reset their card z-index
            allDropdowns.forEach(d => {
                if (d !== dropdown) {
                    d.classList.remove('open');
                    d.closest('.tx-card').style.zIndex = '';
                }
            });

            // Toggle current dropdown
            dropdown.classList.toggle('open');

            // Adjust z-index of current card
            if (dropdown.classList.contains('open')) {
                card.style.zIndex = '1000';
            } else {
                card.style.zIndex = '';
            }
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.tx-menu-container')) {
            document.querySelectorAll('.tx-menu-dropdown').forEach(d => {
                d.classList.remove('open');
                d.closest('.tx-card').style.zIndex = '';
            });
        }
    });

    // Update payment method label when transaction type changes
    const typeField = document.getElementById('m_type');
    if (typeField) {
        typeField.addEventListener('change', function() {
            const methodLabel = document.getElementById('payment-method-label');
            const methodHint = document.getElementById('payment-method-hint');
            if (this.value === 'income') {
                methodLabel.textContent = 'To:';
                methodHint.textContent = 'Where the payment will be received';
            } else {
                methodLabel.textContent = 'From:';
                methodHint.textContent = 'Where the payment will be deducted from';
            }
        });
    }
})();

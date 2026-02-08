/**
 * Transaction Modal
 * Handles add/edit transaction modal open/close
 */
(function() {
    const overlay = document.getElementById('modal-overlay');
    const openBtn = document.getElementById('open-add');
    const closeBtn = document.getElementById('modal-close');
    const cancelBtn = document.getElementById('modal-cancel');
    const form = document.getElementById('tx-form');
    const modalTitle = document.getElementById('modal-title');
    const actionInput = document.getElementById('tx-action');

    window.openTransactionModal = function() {
        if (overlay) {
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeTransactionModal = function() {
        if (overlay) {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
            // Reset form
            if (form) form.reset();
            document.getElementById('tx-id').value = '';
            actionInput.value = 'tx_add';
            modalTitle.textContent = 'Add Transaction';
        }
    };

    // Add transaction button
    if (openBtn) {
        openBtn.addEventListener('click', window.openTransactionModal);
    }

    // Close handlers
    if (closeBtn) closeBtn.addEventListener('click', window.closeTransactionModal);
    if (cancelBtn) cancelBtn.addEventListener('click', window.closeTransactionModal);

    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) window.closeTransactionModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) {
            window.closeTransactionModal();
        }
    });
})();

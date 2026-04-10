/**
 * Budget Management
 * Handles budget modal open/close and form operations
 */
(function() {
    const budgetModal = document.getElementById('budget-modal-overlay');
    const budgetForm = document.getElementById('budget-form');
    const budgetTitle = document.getElementById('budget-modal-title');
    const budgetActionInput = document.getElementById('budget-action');

    window.openBudgetModal = function(mode = 'add', budgetId = null, walletId = null, year = null, month = null, expectedIncome = 0, expectedExpense = 0, notes = '') {
        if (!budgetModal) return;

        // Get current year/month if not provided
        const now = new Date();
        year = year || now.getFullYear();
        month = month || (now.getMonth() + 1);

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                           'July', 'August', 'September', 'October', 'November', 'December'];

        document.getElementById('budget-id').value = budgetId || '';
        document.getElementById('budget-wallet-id').value = walletId || '';
        document.getElementById('budget-year').value = year;
        document.getElementById('budget-month').value = month;
        // Set budget_month parameter for redirect (YYYY-MM format)
        const monthPadded = String(month).padStart(2, '0');
        document.getElementById('budget-month-param').value = year + '-' + monthPadded;
        // Only populate if non-zero, otherwise leave blank for optional field
        document.getElementById('budget-expected-income').value = expectedIncome && expectedIncome > 0 ? expectedIncome : '';
        document.getElementById('budget-expected-expense').value = expectedExpense || 0;
        document.getElementById('budget-notes').value = notes || '';

        // Display month/year
        const monthDisplay = document.getElementById('budget-month-display');
        monthDisplay.textContent = monthNames[month - 1] + ' ' + year;

        budgetActionInput.value = mode === 'add' ? 'budget_add' : 'budget_edit';
        budgetTitle.textContent = mode === 'add' ? 'Set Budget' : 'Edit Budget';

        budgetModal.classList.add('open');
        budgetModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    window.closeBudgetModal = function() {
        if (budgetModal) {
            budgetModal.classList.remove('open');
            budgetModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
            if (budgetForm) budgetForm.reset();
        }
    };

    // Close budget modal handlers
    const budgetCloseBtn = document.getElementById('budget-modal-close');
    const budgetCancelBtn = document.getElementById('budget-modal-cancel');

    if (budgetCloseBtn) budgetCloseBtn.addEventListener('click', window.closeBudgetModal);
    if (budgetCancelBtn) budgetCancelBtn.addEventListener('click', window.closeBudgetModal);

    // Add budget button (for selected month)
    document.addEventListener('click', function(e) {
        if (e.target.id === 'budget-add-btn' || e.target.closest('#budget-add-btn')) {
            e.preventDefault();
            e.stopPropagation();

            // Get wallet ID from the page context
            const urlParams = new URLSearchParams(window.location.search);
            const walletId = urlParams.get('id');

            if (walletId) {
                // Check if a specific budget month is selected, otherwise use current month
                const budgetMonthParam = urlParams.get('budget_month');
                let year = new Date().getFullYear();
                let month = new Date().getMonth() + 1;

                if (budgetMonthParam) {
                    const parts = budgetMonthParam.split('-');
                    if (parts.length === 2) {
                        year = parseInt(parts[0]);
                        month = parseInt(parts[1]);
                    }
                }

                window.openBudgetModal('add', null, walletId, year, month);
            }
        }
    });

    // Edit budget button
    document.addEventListener('click', function(e) {
        if (e.target.id === 'budget-edit-btn' || e.target.closest('#budget-edit-btn')) {
            e.preventDefault();
            e.stopPropagation();

            // Get wallet ID from page context
            const urlParams = new URLSearchParams(window.location.search);
            const walletId = urlParams.get('id');

            if (walletId) {
                // Get the budget data from the displayed card
                const budgetCard = document.querySelector('.budget-card');
                const budgetHeaderText = budgetCard.querySelector('.card-header strong').textContent;

                // Extract month and year from header like "📊 Budget: January 2026"
                const monthYearMatch = budgetHeaderText.match(/([A-Za-z]+)\s(\d{4})/);
                if (monthYearMatch) {
                    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                                       'July', 'August', 'September', 'October', 'November', 'December'];
                    const month = monthNames.indexOf(monthYearMatch[1]) + 1;
                    const year = parseInt(monthYearMatch[2]);

                    // Get values from data attributes for reliability
                    let expectedIncome = 0;
                    let expectedExpense = 0;

                    // Check for expense-only budget card
                    const budgetLimitCol = budgetCard.querySelector('[data-budget-type="limit"]');
                    if (budgetLimitCol) {
                        // Expense-only budget
                        expectedExpense = parseFloat(budgetLimitCol.dataset.budgetExpectedExpense) || 0;
                        expectedIncome = parseFloat(budgetLimitCol.dataset.budgetExpectedIncome) || 0;
                    } else {
                        // Check for full budget card (income + expense)
                        const expectedCol = budgetCard.querySelector('[data-budget-type="expected"]');
                        if (expectedCol) {
                            expectedIncome = parseFloat(expectedCol.dataset.budgetExpectedIncome) || 0;
                            expectedExpense = parseFloat(expectedCol.dataset.budgetExpectedExpense) || 0;
                        }
                    }

                    // Try to get budget ID from a data attribute if available
                    const budgetId = budgetCard.dataset.budgetId || null;

                    window.openBudgetModal('edit', budgetId, walletId, year, month, expectedIncome, expectedExpense);
                }
            }
        }
    });
})();

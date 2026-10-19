/**
 * Record Payment form: cascading customer -> agreement -> payment target,
 * with automatic amount suggestion.
 */
(function () {
    'use strict';

    var customerSel = document.getElementById('customer_id');
    var agreementSel = document.getElementById('agreement_id');
    var installmentWrap = document.getElementById('installmentWrap');
    var targetsEl = document.getElementById('paymentTargets');
    var installmentIdEl = document.getElementById('installment_id');
    var balanceHint = document.getElementById('balanceHint');
    var amountInput = document.getElementById('amountInput');

    if (!customerSel || !agreementSel) {
        return;
    }

    var DATA = window.PAYMENT_DATA || {};
    var balances = DATA.balances || {};
    var installments = DATA.installments || {};
    var old = DATA.old || {};
    var allAgreements = Array.prototype.slice.call(agreementSel.options);

    function money(x) {
        return Number(x || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function rowsFor(agId) {
        return (agId && installments[agId]) ? installments[agId] : [];
    }

    function balanceOfAgreement(id) {
        return (id && balances[id] !== undefined) ? Number(balances[id]) : null;
    }

    function filterAgreements() {
        var customerId = customerSel.value;
        var previous = agreementSel.value;

        while (agreementSel.options.length > 1) {
            agreementSel.remove(1);
        }
        allAgreements.forEach(function (opt, idx) {
            if (idx === 0) { return; }
            if (!customerId || opt.getAttribute('data-customer') === customerId) {
                agreementSel.add(opt.cloneNode(true));
            }
        });

        var found = false;
        for (var i = 0; i < agreementSel.options.length; i++) {
            if (agreementSel.options[i].value === previous && previous !== '') {
                agreementSel.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) { agreementSel.value = ''; }
    }

    function updateBalance() {
        if (!balanceHint) { return; }
        var bal = balanceOfAgreement(agreementSel.value);
        balanceHint.textContent = (bal !== null) ? 'Outstanding balance: ' + money(bal) : '';
    }

    function addTarget(value, id, title, meta, checked) {
        var wrap = document.createElement('div');
        wrap.className = 'form-check';

        var input = document.createElement('input');
        input.type = 'radio';
        input.className = 'form-check-input';
        input.name = 'payment_target';
        input.value = value;
        input.id = id;
        if (checked) { input.checked = true; }

        var label = document.createElement('label');
        label.className = 'form-check-label';
        label.setAttribute('for', id);
        label.textContent = title;
        if (meta) {
            var span = document.createElement('span');
            span.className = 'text-muted small ms-1';
            span.textContent = meta;
            label.appendChild(span);
        }

        wrap.appendChild(input);
        wrap.appendChild(label);
        targetsEl.appendChild(wrap);
    }

    function buildTargets(preferredTarget) {
        if (!targetsEl) { return; }
        var rows = rowsFor(agreementSel.value);
        targetsEl.innerHTML = '';
        if (!rows.length) {
            installmentWrap.style.display = 'none';
            installmentIdEl.value = '';
            return;
        }
        installmentWrap.style.display = '';

        var first = rows[0];
        var target = preferredTarget || 'auto';

        addTarget(
            'auto', 'pt_auto',
            'Auto - oldest unpaid installment',
            '(#' + first.no + ' due ' + first.due + ' - ' + money(first.balance) + ')',
            target === 'auto'
        );

        rows.forEach(function (r) {
            addTarget(
                String(r.id), 'pt_inst_' + r.id,
                'Installment #' + r.no,
                '- due ' + r.due + ' - ' + money(r.balance),
                target === String(r.id)
            );
        });

        addTarget(
            'custom', 'pt_custom',
            'Custom amount',
            '- enter any amount (applied oldest-first)',
            target === 'custom'
        );
    }

    function selectedTarget() {
        var el = targetsEl ? targetsEl.querySelector('input[name="payment_target"]:checked') : null;
        return el ? el.value : 'auto';
    }

    function amountForTarget(value) {
        var rows = rowsFor(agreementSel.value);
        if (!rows.length) { return null; }
        if (value === 'auto') { return Number(rows[0].balance); }
        var id = parseInt(value, 10);
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].id === id) { return Number(rows[i].balance); }
        }
        return null;
    }

    function applyTarget() {
        var value = selectedTarget();
        if (value === 'custom') {
            installmentIdEl.value = '';
            if (amountInput) {
                amountInput.readOnly = false;
                amountInput.focus();
            }
            return;
        }

        installmentIdEl.value = (value === 'auto') ? '' : value;
        var amt = amountForTarget(value);
        if (amountInput) {
            amountInput.readOnly = true;
            if (amt !== null) { amountInput.value = amt.toFixed(2); }
        }
    }

    function initForAgreement(preferredTarget) {
        updateBalance();
        buildTargets(preferredTarget);
        if (selectedTarget() === 'custom') {
            installmentIdEl.value = '';
            if (amountInput) { amountInput.readOnly = false; }
        } else {
            applyTarget();
        }
    }

    customerSel.addEventListener('change', function () {
        filterAgreements();
        initForAgreement('auto');
    });
    agreementSel.addEventListener('change', function () {
        initForAgreement('auto');
    });
    if (targetsEl) {
        targetsEl.addEventListener('change', function (e) {
            if (e.target && e.target.name === 'payment_target') {
                applyTarget();
            }
        });
    }

    /* ----- Initial load (restore a re-posted form if present) ----- */
    filterAgreements();

    var preferred = old.target || 'auto';
    var keepCustomAmount = '';
    if (preferred === 'custom') {
        keepCustomAmount = old.amount || '';
    } else if (old.installment) {
        preferred = String(old.installment);
    }

    updateBalance();
    buildTargets(preferred);
    if (selectedTarget() === 'custom') {
        installmentIdEl.value = '';
        if (amountInput) {
            amountInput.readOnly = false;
            amountInput.value = keepCustomAmount;
        }
    } else {
        applyTarget();
    }
})();

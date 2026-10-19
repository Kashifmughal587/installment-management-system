/* Agreement form - dynamic products, totals, schedule preview. */
(function () {
    'use strict';

    function round2(x) { return Math.round((Number(x) || 0) * 100) / 100; }
    function fmt(x) { return (round2(x)).toFixed(2); }

    function addFrequency(startStr, n, freq, dueDay) {
        var p = (startStr || '').split('-').map(Number);
        if (p.length !== 3 || !p[0]) { return ''; }
        var y = p[0], m = p[1], d = p[2];
        if (freq === 'weekly' || freq === 'biweekly') {
            var dt = new Date(Date.UTC(y, m - 1, d));
            dt.setUTCDate(dt.getUTCDate() + n * (freq === 'weekly' ? 7 : 14));
            return dt.toISOString().slice(0, 10);
        }
        var year = y, month = (m - 1) + n;
        year += Math.floor(month / 12);
        month = ((month % 12) + 12) % 12;
        var day = dueDay > 0 ? dueDay : d;
        var lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        var useDay = Math.min(day, lastDay);
        return year + '-' + String(month + 1).padStart(2, '0') + '-' + String(useDay).padStart(2, '0');
    }

    function equalSchedule(balance, count, firstDue, freq, dueDay) {
        var rows = [];
        count = parseInt(count, 10) || 0;
        if (count <= 0 || !firstDue) { return rows; }
        balance = round2(balance);
        var base = round2(balance / count);
        var acc = 0;
        for (var i = 1; i <= count; i++) {
            var amt = (i < count) ? base : round2(balance - acc);
            acc = round2(acc + amt);
            rows.push({ date: addFrequency(firstDue, i - 1, freq, dueDay), amount: amt });
        }
        return rows;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('agreementForm');
        if (!form) { return; }

        var productsBody = document.getElementById('productsBody');
        var productsTpl = document.getElementById('productRowTemplate');
        var guarantorsBody = document.getElementById('guarantorsBody');
        var guarantorsTpl = document.getElementById('guarantorRowTemplate');
        var guarantorsTable = document.getElementById('guarantorsTable');
        var guarantorEmpty = document.getElementById('guarantorEmpty');
        var customBody = document.getElementById('customScheduleBody');
        var customTpl = document.getElementById('scheduleRowTemplate');

        var agreedPrice = document.getElementById('agreedPrice');
        var addCharges = document.getElementById('addCharges');
        var discount = document.getElementById('discount');
        var advance = document.getElementById('advance');
        var installmentCount = document.getElementById('installmentCount');
        var firstDue = document.getElementById('firstDue');
        var frequency = document.getElementById('frequency');
        var dueDay = document.getElementById('dueDay');
        var financedDisplay = document.getElementById('financedDisplay');
        var totalCashEl = document.getElementById('totalCash');
        var schedulePreview = document.getElementById('schedulePreview');
        var scheduleTotal = document.getElementById('scheduleTotal');
        var customTotal = document.getElementById('customScheduleTotal');
        var useCustomHidden = document.getElementById('useCustomSchedule');
        var customToggle = document.getElementById('customScheduleToggle');
        var equalArea = document.getElementById('equalScheduleArea');
        var customArea = document.getElementById('customScheduleArea');

        function financedAmount() {
            return round2(
                (Number(agreedPrice.value) || 0)
                + (Number(addCharges.value) || 0)
                - (Number(discount.value) || 0)
                - (Number(advance.value) || 0)
            );
        }

        function currentSchedule() {
            return equalSchedule(
                financedAmount(),
                installmentCount.value,
                firstDue.value,
                frequency.value,
                parseInt(dueDay.value, 10) || 0
            );
        }

        function recalcProducts() {
            var total = 0;
            productsBody.querySelectorAll('.prod-row').forEach(function (row) {
                var qty = Number(row.querySelector('.qty').value) || 0;
                var unit = Number(row.querySelector('.unit').value) || 0;
                var line = round2(qty * unit);
                row.querySelector('.line-total').textContent = fmt(line);
                total = round2(total + line);
            });
            if (totalCashEl) { totalCashEl.textContent = fmt(total); }
            return total;
        }

        function recalcFinanced() {
            var fin = financedAmount();
            financedDisplay.value = fmt(fin);
            return fin;
        }

        function renderEqualPreview() {
            recalcFinanced();
            var rows = currentSchedule();
            var html = '';
            var total = 0;
            rows.forEach(function (r, i) {
                total = round2(total + r.amount);
                html += '<tr><td>' + (i + 1) + '</td><td>' + r.date + '</td><td class="text-end">' + fmt(r.amount) + '</td></tr>';
            });
            if (!rows.length) {
                html = '<tr><td colspan="3" class="text-muted text-center">Set the amount, count and first due date to preview.</td></tr>';
            }
            schedulePreview.innerHTML = html;
            scheduleTotal.textContent = fmt(total);
        }

        function renumberCustom() {
            var total = 0;
            customBody.querySelectorAll('tr').forEach(function (row, i) {
                row.querySelector('.row-no').textContent = (i + 1);
                var amt = Number(row.querySelector('.sched-amt').value) || 0;
                total = round2(total + amt);
            });
            customTotal.textContent = fmt(total);
        }

        function addCustomRow(date, amount) {
            var node = customTpl.content.cloneNode(true);
            if (date) { node.querySelector('input[type=date]').value = date; }
            if (amount !== undefined) { node.querySelector('.sched-amt').value = fmt(amount); }
            customBody.appendChild(node);
            renumberCustom();
        }

        function fillCustomFromEqual() {
            customBody.innerHTML = '';
            currentSchedule().forEach(function (r) { addCustomRow(r.date, r.amount); });
        }

        /* ----- Events ----- */
        document.getElementById('addProductBtn').addEventListener('click', function () {
            productsBody.appendChild(productsTpl.content.cloneNode(true));
            recalcProducts();
        });
        productsBody.addEventListener('input', function (e) {
            if (e.target.classList.contains('qty') || e.target.classList.contains('unit')) {
                recalcProducts();
            }
        });
        productsBody.addEventListener('click', function (e) {
            if (e.target.closest('.remove-product')) {
                if (productsBody.querySelectorAll('.prod-row').length > 1) {
                    e.target.closest('tr').remove();
                    recalcProducts();
                }
            }
        });

        document.getElementById('addGuarantorBtn').addEventListener('click', function () {
            guarantorsBody.appendChild(guarantorsTpl.content.cloneNode(true));
            guarantorsTable.classList.remove('d-none');
            guarantorEmpty.classList.add('d-none');
        });
        guarantorsBody.addEventListener('click', function (e) {
            if (e.target.closest('.remove-guarantor')) {
                e.target.closest('tr').remove();
                if (!guarantorsBody.querySelectorAll('tr').length) {
                    guarantorsTable.classList.add('d-none');
                    guarantorEmpty.classList.remove('d-none');
                }
            }
        });

        document.getElementById('addScheduleRow').addEventListener('click', function () {
            addCustomRow('', 0);
        });
        customBody.addEventListener('input', function (e) {
            if (e.target.classList.contains('sched-amt')) { renumberCustom(); }
        });
        customBody.addEventListener('click', function (e) {
            if (e.target.closest('.remove-schedule')) {
                e.target.closest('tr').remove();
                renumberCustom();
            }
        });
        document.getElementById('generateFromEqual').addEventListener('click', fillCustomFromEqual);

        customToggle.addEventListener('change', function () {
            var on = customToggle.checked;
            useCustomHidden.value = on ? '1' : '0';
            equalArea.classList.toggle('d-none', on);
            customArea.classList.toggle('d-none', !on);
            if (on && !customBody.querySelectorAll('tr').length) {
                fillCustomFromEqual();
            }
        });

        [agreedPrice, addCharges, discount, advance, installmentCount, firstDue, frequency, dueDay].forEach(function (el) {
            el.addEventListener('input', function () {
                if (!customToggle.checked) { renderEqualPreview(); }
                else { recalcFinanced(); }
            });
        });

        /* ----- Init ----- */
        recalcProducts();
        if (customToggle.checked) {
            useCustomHidden.value = '1';
            equalArea.classList.add('d-none');
            customArea.classList.remove('d-none');
            renumberCustom();
        } else {
            renderEqualPreview();
        }
    });
})();

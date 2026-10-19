/* Installment Management System - global UI behaviour */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        /* ---------- Sidebar toggle (mobile) ---------- */
        var sidebar = document.getElementById('appSidebar');
        var backdrop = document.getElementById('sidebarBackdrop');
        var toggle = document.getElementById('sidebarToggle');

        function openSidebar() {
            if (sidebar) sidebar.classList.add('open');
            if (backdrop) backdrop.classList.add('show');
        }
        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('show');
        }
        if (toggle) toggle.addEventListener('click', openSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        /* ---------- Show / hide password ---------- */
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                if (!input) return;
                var isPwd = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPwd ? 'text' : 'password');
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bi-eye', !isPwd);
                    icon.classList.toggle('bi-eye-slash', isPwd);
                }
            });
        });

        /* ---------- Confirmation for destructive actions ---------- */
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (!window.confirm(el.getAttribute('data-confirm'))) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });

        /* ---------- Auto-dismiss success alerts ---------- */
        document.querySelectorAll('.alert-success.auto-dismiss').forEach(function (el) {
            setTimeout(function () {
                el.classList.remove('show');
            }, 6000);
        });

        /* ---------- CNIC mask + validation (any [data-cnic-mask] input) ---------- */
        document.addEventListener('input', function (e) {
            var el = e.target;
            if (!el || !el.matches || !el.matches('[data-cnic-mask]')) return;
            var digits = el.value.replace(/\D/g, '').slice(0, 13);
            var out = digits;
            if (digits.length > 5) out = digits.slice(0, 5) + '-' + digits.slice(5);
            if (digits.length > 12) out = digits.slice(0, 5) + '-' + digits.slice(5, 12) + '-' + digits.slice(12);
            el.value = out;
            el.classList.remove('is-invalid');
        });
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('[data-cnic-validate]')) return;
            var ok = true;
            var firstBad = null;
            form.querySelectorAll('[data-cnic-mask]').forEach(function (el) {
                el.classList.remove('is-invalid');
                var digits = el.value.replace(/\D/g, '');
                var mustBeFull = el.hasAttribute('required') || digits.length > 0;
                if (mustBeFull && digits.length !== 13) {
                    el.classList.add('is-invalid');
                    if (!firstBad) firstBad = el;
                    ok = false;
                }
            });
            if (!ok) {
                e.preventDefault();
                if (firstBad) firstBad.focus();
            }
        });

        /* ---------- Image / document lightbox ---------- */
        var lightboxModal = null;
        function getLightbox() {
            if (lightboxModal) return lightboxModal;
            var wrap = document.createElement('div');
            wrap.innerHTML =
                '<div class="modal fade" id="appLightbox" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-dialog-centered modal-lg">' +
                '<div class="modal-content">' +
                '<div class="modal-header py-2">' +
                '<h6 class="modal-title small"></h6>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '</div>' +
                '<div class="modal-body text-center p-2"></div>' +
                '</div></div></div>';
            document.body.appendChild(wrap.firstChild);
            lightboxModal = new bootstrap.Modal(document.getElementById('appLightbox'));
            return lightboxModal;
        }
        document.querySelectorAll('[data-lightbox]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var url = el.getAttribute('href');
                var title = el.getAttribute('data-lightbox-title') || 'Preview';
                var modal = getLightbox();
                var box = document.getElementById('appLightbox');
                box.querySelector('.modal-title').textContent = title;
                var body = box.querySelector('.modal-body');
                body.innerHTML = '';
                var content;
                if (/\.pdf(\?|#|$)/i.test(url)) {
                    content = document.createElement('iframe');
                    content.style.cssText = 'width:100%;height:75vh;border:0;';
                } else {
                    content = document.createElement('img');
                    content.style.cssText = 'max-width:100%;max-height:80vh;border-radius:6px;';
                }
                content.src = url;
                content.alt = title;
                body.appendChild(content);
                modal.show();
            });
        });
    });
})();

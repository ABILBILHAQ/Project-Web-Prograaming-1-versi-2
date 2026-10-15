/**
 * Skrip Frontend Utama - Sistem Evaluasi PPKMB Berbasis Analisis Sentimen
 * Mengelola loading indicator, toggle sidebar mobile, pencarian tabel cepat,
 * serta konfirmasi pengiriman formulir evaluasi.
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Toggle Sidebar pada layar Tablet/Smartphone
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebarEl = document.getElementById('appSidebar');
    if (sidebarToggleBtn && sidebarEl) {
        sidebarToggleBtn.addEventListener('click', function () {
            sidebarEl.classList.toggle('show');
        });
    }

    // 2. Global Loading Indicator untuk form dengan atribut data-loading
    const loadingOverlay = document.getElementById('globalLoadingOverlay');
    const loadingText = document.getElementById('globalLoadingText');

    window.showLoading = function (message) {
        if (loadingOverlay) {
            if (loadingText && message) {
                loadingText.textContent = message;
            }
            loadingOverlay.classList.add('active');
        }
    };

    window.hideLoading = function () {
        if (loadingOverlay) {
            loadingOverlay.classList.remove('active');
        }
    };

    document.querySelectorAll('form[data-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (form.checkValidity()) {
                const msg = form.getAttribute('data-loading') || 'Sedang memproses data dan menganalisis sentimen...';
                window.showLoading(msg);
            }
        });
    });

    // 3. Penghitung karakter komentar pada formulir evaluasi
    document.querySelectorAll('[data-char-counter]').forEach(function (textarea) {
        const targetId = textarea.getAttribute('data-char-counter');
        const counterEl = document.getElementById(targetId);
        if (counterEl) {
            const updateCount = function () {
                const len = textarea.value.trim().length;
                const words = textarea.value.trim() ? textarea.value.trim().split(/\s+/).length : 0;
                counterEl.textContent = len + ' karakter (' + words + ' kata)';
            };
            textarea.addEventListener('input', updateCount);
            updateCount();
        }
    });
});

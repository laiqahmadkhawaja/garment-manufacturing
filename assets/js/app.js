/**
 * Garment Manufacturing ERP - Core Frontend Scripts (Native Vanilla JS)
 */

document.addEventListener('DOMContentLoaded', function() {
    // Mobile sidebar toggle
    const sidebar = document.querySelector('.app-sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggles = document.querySelectorAll('#sidebarToggle, #sidebarMobileClose');

    toggles.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (sidebar) sidebar.classList.toggle('show');
            if (backdrop) backdrop.classList.toggle('d-none');
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            if (sidebar) sidebar.classList.remove('show');
            this.classList.add('d-none');
        });
    }

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);

    // Number formatting helper
    window.formatMoney = function(amount) {
        return 'Rs. ' + parseFloat(amount || 0).toLocaleString('en-PK', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    // Print helper
    document.querySelectorAll('.btn-print-trigger').forEach(btn => {
        btn.addEventListener('click', () => window.print());
    });

    // Hash-based Tab Switching
    function activateTabFromHash() {
        const hash = window.location.hash;
        if (hash) {
            const cleanHash = hash.replace('#', '');
            const targetButton = document.querySelector(`[data-bs-target="#${cleanHash}Tab"]`) || 
                                 document.querySelector(`[data-bs-target="#${cleanHash}"]`) ||
                                 document.querySelector(`[href="#${cleanHash}"]`);
            if (targetButton && window.bootstrap && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(targetButton).show();
            }
        }
    }
    activateTabFromHash();
    window.addEventListener('hashchange', activateTabFromHash);
});

/**
 * Toast notifications
 */
function showToast(message, type = 'success') {
    const icon = type === 'success' ? 'bi-check-circle-fill text-success' : 
                 type === 'danger' ? 'bi-exclamation-triangle-fill text-danger' : 
                 type === 'warning' ? 'bi-exclamation-circle-fill text-warning' : 'bi-info-circle-fill text-primary';
                 
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1090';
        document.body.appendChild(container);
    }

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-light border-0 shadow-lg mb-2';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.setAttribute('data-bs-delay', '4000');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi ${icon} fs-5"></i>
                <span class="fw-medium">${message}</span>
            </div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    container.appendChild(toastEl);
    if (window.bootstrap && bootstrap.Toast) {
        const bsToast = new bootstrap.Toast(toastEl);
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }
}

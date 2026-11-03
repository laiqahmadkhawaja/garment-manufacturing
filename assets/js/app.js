/**
 * Garment Manufacturing ERP - Core Frontend Scripts
 */

$(document).ready(function() {
    // Mobile sidebar toggle
    $('#sidebarToggle, #sidebarMobileClose').on('click', function(e) {
        e.preventDefault();
        $('.app-sidebar').toggleClass('show');
        $('#sidebarBackdrop').toggleClass('d-none');
    });

    $('#sidebarBackdrop').on('click', function() {
        $('.app-sidebar').removeClass('show');
        $(this).addClass('d-none');
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert-dismissible').fadeOut('slow');
    }, 5000);

    // Number formatting helper
    window.formatMoney = function(amount) {
        return 'Rs. ' + parseFloat(amount || 0).toLocaleString('en-PK', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    // Print helper
    $('.btn-print-trigger').on('click', function() {
        window.print();
    });

    // Global AJAX Setup for CSRF
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
        }
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
    $(window).on('hashchange', activateTabFromHash);
});

/**
 * Toast notifications
 */
function showToast(message, type = 'success') {
    const icon = type === 'success' ? 'bi-check-circle-fill text-success' : 
                 type === 'danger' ? 'bi-exclamation-triangle-fill text-danger' : 
                 type === 'warning' ? 'bi-exclamation-circle-fill text-warning' : 'bi-info-circle-fill text-primary';
                 
    const toastHtml = `
        <div class="toast align-items-center text-bg-light border-0 shadow-lg mb-2" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi ${icon} fs-5"></i>
                    <span class="fw-medium">${message}</span>
                </div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    let $container = $('#toastContainer');
    if ($container.length === 0) {
        $('body').append('<div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;"></div>');
        $container = $('#toastContainer');
    }
    const $toast = $(toastHtml).appendTo($container);
    const bsToast = new bootstrap.Toast($toast[0]);
    bsToast.show();
    $toast.on('hidden.bs.toast', function() {
        $(this).remove();
    });
}

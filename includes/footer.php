<?php
/**
 * Garment Manufacturing ERP - Footer Partial
 */
$flashMessages = get_flash_messages();
?>
    <footer class="app-footer py-3 px-4 border-top bg-white text-muted small text-center text-md-start d-flex flex-column flex-md-row justify-content-between align-items-center">
        <div>
            &copy; <?= date('Y') ?> <strong><?= clean(get_setting('company_name', 'Garment ERP')) ?></strong>. All rights reserved.
        </div>
        <div class="mt-2 mt-md-0 text-secondary">
            <span>Garment Production & Labour Management System v<?= APP_VERSION ?></span>
        </div>
    </footer>
</div> <!-- end app-main -->
</div> <!-- end app-wrapper -->

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;"></div>

<!-- Offline Local Scripts (Native Modern JS) -->
<script src="<?= BASE_URL ?>assets/js/bootstrap.bundle.min.js"></script>
<?php if (($activeNav ?? '') === 'dashboard'): ?>
<script src="<?= BASE_URL ?>assets/js/chart.min.js"></script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>

<!-- Trigger Flash Messages as Toasts or Alerts -->
<?php if (!empty($flashMessages)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php foreach ($flashMessages as $msg): ?>
            showToast(<?= json_encode($msg['message']) ?>, <?= json_encode($msg['type']) ?>);
        <?php endforeach; ?>
    });
</script>
<?php endif; ?>

</body>
</html>

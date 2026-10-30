<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/icons.php';

require_login();

$pageTitle = 'Review Pipeline Preview';
require_once __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 800px; margin: 40px auto; padding: 24px; text-align: center;">
    <h2 style="margin-bottom: 8px; font-weight: 700; color: var(--ink);">Multi-Stage Review Pipeline Preview</h2>
    <p style="color: var(--muted); margin-bottom: 24px; font-size: 13.5px;">Click below to test the horizontal audit-timeline modal with live colleagues.</p>

    <button type="button" class="btn btn-primary" onclick="window.openTieredWorkflowModal()" style="padding: 10px 22px; font-size: 14px; gap: 8px; display: inline-flex; align-items: center;">
        <?php echo render_icon('files'); ?>
        <span>Open Review Pipeline</span>
    </button>
</div>

<?php 
// Include the modal specimen
include __DIR__ . '/../includes/workflow-modal.php'; 
?>

<script>
// Automatically open the modal on page load for convenience
document.addEventListener("DOMContentLoaded", function() {
    setTimeout(function() {
        if (typeof window.openTieredWorkflowModal === "function") {
            window.openTieredWorkflowModal();
        }
    }, 200);

    window.onTieredWorkflowConfirmed = function(stages) {
        alert("Pipeline confirmed with " + stages.length + " stages! Ready for backend coupling.");
        console.log("Confirmed stages:", stages);
        if (typeof window.closeTieredWorkflowModal === "function") {
            window.closeTieredWorkflowModal();
        }
    };
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

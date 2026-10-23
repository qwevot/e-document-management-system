<?php
// Shared flash-message renderer. Reads ?success / ?error from the query
// string and the optional $dbError variable.
$dbError = $dbError ?? '';

if (isset($_GET['success'])): ?>
    <div class="flash success"><?php echo $flashSuccess ?? e($_GET['success']); ?></div>
<?php endif;

if (isset($_GET['erro
r'])): ?>
    <div class="flash error"><?php echo e($_GET['error']); ?><?php echo $flashAfterError ?? ''; ?></div>
<?php endif;

if ($dbError !== ''): ?>
    <div class="flash error"><?php echo e($dbError); ?></div>
<?php endif; ?>

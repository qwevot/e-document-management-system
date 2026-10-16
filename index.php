<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (current_user() === null) {
    header('Location: ' . BASE_URL . '/login.php');
} else {
    header('Location: ' . BASE_URL . '/pages/my-workspace.php');
}
exit;

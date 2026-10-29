<?php
// Configuration & Global Environment Constants

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'edms_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', 'uploads/');

define('BASE_URL', '/edms');
define('APP_VERSION', '1.0.0');

date_default_timezone_set('Asia/Manila');

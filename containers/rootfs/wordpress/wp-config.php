<?php

define('WP_CONTENT_DIR', '/var/www/wp-content');
define('WP_AUTO_UPDATE_CORE', false);
define('WP_ENVIRONMENT_TYPE', 'production');

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');
$table_prefix  = getenv('TABLE_PREFIX') ?: 'wp_';

foreach ($_ENV as $key => $value) {
    $capitalized = strtoupper($key);
    if (!defined($capitalized)) {
        // Convert string boolean values to actual booleans
        if (in_array($value, ['true', 'false'])) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        define($capitalized, $value);
    }
}

// $_SERVER['HTTPS'] = getenv('FORCE_HTTPS') ?: 'off';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

$secret_file = ABSPATH . 'wp-content/wp-secrets.php';
file_exists($secret_file) && require_once($secret_file);

require_once(ABSPATH . 'wp-settings.php');

<?php

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

# 禁止覆盖 环境变量
define('WP_AUTO_UPDATE_CORE', false);

define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins');
define('WPMU_PLUGIN_DIR', ABSPATH . 'system-mu-plugins');
$x_protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$x_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
define('WP_CONTENT_URL', $x_protocol . $x_host . '/wp-content');
define('WP_PLUGIN_URL', $x_protocol . $x_host . '/wp-content/plugins');
define('WPMU_PLUGIN_URL', $x_protocol . $x_host . '/system-mu-plugins');

$env_vars = !empty($_ENV) ? $_ENV : getenv();
foreach ($env_vars as $key => $value) {
		if (!is_string($key)) {
		continue;
	}

	$capitalized = strtoupper($key);

	if (!defined($capitalized)) {
		$string_value = trim((string)$value);
		$lower_value = strtolower($string_value);
		if ($lower_value === 'true') {
			$value = true;
		} elseif ($lower_value === 'false') {
			$value = false;
		} elseif ($lower_value === 'null') {
			$value = null;
		}
		define($capitalized, $value);
	}
}

# 可覆盖 环境变量
// 核心环境与数据库
defined('WP_ENVIRONMENT_TYPE') || define('WP_ENVIRONMENT_TYPE', 'production');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('DB_COLLATE') || define('DB_COLLATE', 'utf8mb4_unicode_ci');
$table_prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'wp_';
// 性能与静态资源优化 (外部网关处理)
defined('COMPRESS_SCRIPTS') || define('COMPRESS_SCRIPTS', false);
defined('COMPRESS_CSS') || define('COMPRESS_CSS', false);
defined('CONCATENATE_SCRIPTS') || define('CONCATENATE_SCRIPTS', false);
// 错误输出与安全边界
defined('WP_DEBUG') || define('WP_DEBUG', false);
defined('WP_DEBUG_DISPLAY') || define('WP_DEBUG_DISPLAY', false);
// 文件系统权直写
defined('FS_METHOD') || define('FS_METHOD', 'direct');

$secret_file = ABSPATH . 'wp-content/wp-secrets.php';
if (is_file($secret_file)) {
	require_once $secret_file;
}

require_once ABSPATH . 'wp-settings.php';
<?php
$wpContentDir = '/app/wp-content';
$srcContentDir = '/usr/src/wordpress/wp-content';
$secretsFile = $wpContentDir . '/wp-secrets.php';

/**
 * 递归复制目录（纯 PHP 实现，无需系统 cp 命令）
 */
/**
 * 递归复制目录（纯 PHP 实现，自动创建父级目录结构）
 */
function recursiveCopy($src, $dst) {
    if (!is_dir($src)) return;
    
    // 确保当前目标目录存在
    if (!is_dir($dst)) {
        @mkdir($dst, 0755, true);
    }
    
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if ($file !== '.' && $file !== '..') {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                // 递归遍历子目录
                recursiveCopy($srcPath, $dstPath);
            } else {
                // 确保文件所在的父级目录已存在，再执行 copy
                $parentDir = dirname($dstPath);
                if (!is_dir($parentDir)) {
                    @mkdir($parentDir, 0755, true);
                }
                @copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
}

// 1. 如果 wp-content 为空（比如首次挂载了空 volume），用原生 PHP 复制模版
if (is_dir($wpContentDir) && count(scandir($wpContentDir)) <= 2) {
    echo "Setting up wp-content volume...\n";
    recursiveCopy($srcContentDir, $wpContentDir);
}

// 2. 生成密钥（使用纯 PHP 流上下文忽略证书，或直接用本地伪随机密码生成，规避外网依赖）
$envKeys = [
    'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'
];

$hasEnvSecrets = false;
foreach ($envKeys as $key) {
    if (getenv($key)) {
        $hasEnvSecrets = true;
        break;
    }
}

if (!file_exists($secretsFile) && !$hasEnvSecrets) {
    echo "Generating wp-secrets.php...\n";
    
    // 增加 stream_context 支持 Distroless 下的 HTTPS 请求
    $opts = [
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false,
        ],
        "http" => [
            "timeout" => 5
        ]
    ];
    $context = stream_context_create($opts);
    $secretsContent = @file_get_contents('https://api.wordpress.org/secret-key/1.1/salt/', false, $context);
    
    // 如果网络请求依然失败，退回到本地离线生成高强度 Secret，保证系统可靠性
    if (!$secretsContent) {
        echo "Fetch from wordpress.org failed, generating locally...\n";
        $secretsContent = "";
        foreach ($envKeys as $keyName) {
            $randomKey = bin2hex(random_bytes(32));
            $secretsContent .= "define('{$keyName}', '{$randomKey}');\n";
        }
    }
    
    file_put_contents($secretsFile, "<?php\n" . $secretsContent);
}

// 3. 替换当前进程为 FrankenPHP 运行主程序
pcntl_exec('/usr/local/bin/frankenphp', ['run', '--config', '/etc/caddy/Caddyfile']);
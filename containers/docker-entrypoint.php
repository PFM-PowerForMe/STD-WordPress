<?php
/**
 * STD-WordPress 容器入口脚本 (FrankenPHP + Distroless).
 *
 * 流程:
 *   1. 一次性准备: 初始化 wp-content 卷 -> 目录 -> 生成 php.ini (由 CR_PHP_* 推导,
 *      与旧版 s6-debian-php 基础镜像的 10-init-php 同公式) -> 生成 wp-secrets.php;
 *   2. pcntl_exec 把当前进程替换成 `frankenphp run`, 由 Caddy 作为 PID 1 自己处理
 *      SIGTERM 优雅退出 (WordPress 没有常驻队列 / 定时进程, 不需要额外托管).
 */
declare(strict_types=1);

const APP_DIR      = '/app';
const WP_CONTENT   = APP_DIR . '/wp-content';
const SRC_CONTENT  = '/usr/src/wordpress/wp-content';
const SECRETS_FILE = WP_CONTENT . '/wp-secrets.php';
const FRANKENPHP   = '/usr/local/bin/frankenphp';
const WP_CLI       = '/usr/local/bin/wp';
const CADDYFILE    = '/etc/caddy/Caddyfile';
const PHP_INI      = '/usr/local/etc/php/conf.d/zzz-pfm.ini';

const SECRET_KEYS = [
    'AUTH_KEY',
    'SECURE_AUTH_KEY',
    'LOGGED_IN_KEY',
    'NONCE_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_SALT',
    'NONCE_SALT',
];

// ---------------------------------------------------------------- 基础工具

function out(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function err(string $message): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function env_str(string $name, string $default = ''): string
{
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
}

function clamp_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function env_bool(string $name, bool $default): bool
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return ! in_array(strtolower(trim($value)), ['false', '0', 'no', 'off'], true);
}

function exit_code(int $status): int
{
    if (pcntl_wifexited($status)) {
        return pcntl_wexitstatus($status);
    }
    if (pcntl_wifsignaled($status)) {
        return 128 + pcntl_wtermsig($status);
    }

    return -1;
}

/**
 * 递归复制目录 (纯 PHP 实现, distroless 里没有 cp).
 */
function recursive_copy(string $src, string $dst): void
{
    if (! is_dir($src)) {
        return;
    }
    if (! is_dir($dst)) {
        @mkdir($dst, 0755, true);
    }

    $handle = opendir($src);
    if ($handle === false) {
        return;
    }

    while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;

        if (is_dir($srcPath)) {
            recursive_copy($srcPath, $dstPath);
            continue;
        }

        $parent = dirname($dstPath);
        if (! is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        @copy($srcPath, $dstPath);
    }
    closedir($handle);
}

// ---------------------------------------------------------------- 一次性准备

/**
 * wp-content 是卷: 首次挂载 (空卷) 时用 WordPress 原生 wp-content 初始化.
 */
function init_wp_content(): void
{
    if (! is_dir(WP_CONTENT) || ! is_dir(SRC_CONTENT)) {
        return;
    }
    if (count((array) scandir(WP_CONTENT)) > 2) {
        return;
    }

    out('初始化 wp-content 卷 (复制 /usr/src 模板)');
    recursive_copy(SRC_CONTENT, WP_CONTENT);
}

function ensure_directories(): void
{
    $directories = [
        WP_CONTENT . '/uploads',
        WP_CONTENT . '/upgrade',
        '/tmp/php/sessions',
        '/tmp/caddy',
        '/tmp/wp-cli-cache',
    ];

    foreach ($directories as $directory) {
        if (is_dir($directory)) {
            continue;
        }
        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            err("无法创建目录 {$directory}");
            continue;
        }
        out("已创建目录 {$directory}");
    }
}

/**
 * 生成 php.ini, 公式与旧版 s6-debian-php 基础镜像的 10-init-php 完全一致.
 */
function write_php_ini(): void
{
    $total       = clamp_int((int) env_str('CR_PHP_TOTAL_MEM', '512'), 128, 1048576);
    $memoryLimit = clamp_int(intdiv($total, 4), 128, 512);
    $postMax     = env_str('CR_PHP_POST_MAX_SIZE', '1024M');
    $uploadMax   = env_str('CR_PHP_UPLOAD_MAX_FILESIZE', '1024M');
    $maxExecTime = (int) env_str('CR_PHP_MAX_EXECUTION_TIME', '300');
    $maxInTime   = (int) env_str('CR_PHP_MAX_INPUT_TIME', '300');
    $maxInVars   = (int) env_str('CR_PHP_MAX_INPUT_VARS', '9999');
    $opcacheVal  = (int) env_str('CR_PHP_OPCACHE_VALIDATE', '1');
    $timezone    = env_str('CR_PHP_TIMEZONE', env_str('TZ', 'Asia/Shanghai'));
    // distroless 里没有 shell: 这些命令执行函数本来就跑不起来, 一并关掉.
    // 关掉 exec 还能让 WordPress 站点健康跳过 `gs --version` 探测 (class-wp-debug-data.php 会先判 function_exists).
    $disableFunctions = env_str('CR_PHP_DISABLE_FUNCTIONS', 'exec,shell_exec,system,passthru,popen');

    // 让入口脚本自身的日志也跟随应用时区 (本进程启动时读的是镜像里的 php.ini)
    date_default_timezone_set($timezone);

    $opcacheMem = clamp_int(intdiv($total, 8), 64, 256);
    $opcacheStr = clamp_int(intdiv($opcacheMem, 8), 8, 32);
    $realpath   = clamp_int($total * 2, 256, 4096);
    $jitMem     = $total >= 1024 ? min(intdiv($total, 16), 64) : 0;

    // 旧版 FPM 的并发预算: 每个 worker 约 WORKER_SIZE MB, 扣掉 opcache 与 JIT 后的可用内存决定进程数.
    $workerSize  = 40 + intdiv($memoryLimit, 8);
    $maxChildren = max(2, intdiv($total - $opcacheMem - $jitMem, $workerSize));

    // Caddyfile 的 {$CR_PHP_NUM_THREADS:8} / {$CR_PHP_MAX_THREADS:8} 取这里的推导值 (显式设置优先).
    if (env_str('CR_PHP_NUM_THREADS') === '') {
        putenv('CR_PHP_NUM_THREADS=' . $maxChildren);
    }
    if (env_str('CR_PHP_MAX_THREADS') === '') {
        putenv('CR_PHP_MAX_THREADS=' . $maxChildren);
    }

    $jit = $jitMem > 0
        ? "opcache.jit = tracing\nopcache.jit_buffer_size = {$jitMem}M"
        : 'opcache.jit = disable';

    $content = <<<INI
; 由容器入口脚本生成, 每次启动覆盖. 参数由 CR_PHP_* 环境变量推导.
[PHP]
date.timezone = "{$timezone}"
error_log = "/dev/stderr"
variables_order = "EGPCS"
expose_php = Off
display_errors = Off
display_startup_errors = Off
disable_functions = {$disableFunctions}

session.gc_probability = 0
session.save_path = "/tmp/php/sessions"

memory_limit = {$memoryLimit}M
post_max_size = {$postMax}
upload_max_filesize = {$uploadMax}
max_execution_time = {$maxExecTime}
max_input_time = {$maxInTime}
max_input_vars = {$maxInVars}
realpath_cache_size = {$realpath}K

[opcache]
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = {$opcacheMem}
opcache.interned_strings_buffer = {$opcacheStr}
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = {$opcacheVal}
opcache.save_comments = 1
{$jit}

INI;

    $dir = dirname(PHP_INI);
    if (! is_dir($dir) || ! is_writable($dir)) {
        err("php.ini 目录不可写, 跳过生成: {$dir}");
        return;
    }

    if (@file_put_contents(PHP_INI, $content) === false) {
        err('写入 php.ini 失败: ' . PHP_INI);
        return;
    }

    out("已生成 php.ini (memory_limit={$memoryLimit}M, threads={$maxChildren}, opcache={$opcacheMem}M, jit={$jitMem}M)");

    if ($disableFunctions !== '') {
        out("已禁用命令执行函数: {$disableFunctions}");
    }
}

/**
 * 从 wordpress.org 取密钥串: 先按证书校验取, 不行再关掉校验, 都不行由调用方本地生成.
 */
function fetch_salts(): ?string
{
    $url = 'https://api.wordpress.org/secret-key/1.1/salt/';

    foreach ([true, false] as $verify) {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
            ],
            'http' => [
                'timeout' => 5,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if (is_string($content) && trim($content) !== '') {
            return $content;
        }
    }

    return null;
}

/**
 * 密钥优先级: 容器环境变量 > 已有 wp-secrets.php > wordpress.org > 本地随机生成.
 */
function ensure_secrets(): void
{
    if (is_file(SECRETS_FILE)) {
        return;
    }

    foreach (SECRET_KEYS as $key) {
        if (getenv($key)) {
            out('密钥来自容器环境变量, 跳过 wp-secrets.php');
            return;
        }
    }

    out('生成 wp-secrets.php');

    $content = fetch_salts();

    if ($content === null) {
        out('wordpress.org 不可达, 本地生成随机密钥');
        $content = '';
        foreach (SECRET_KEYS as $key) {
            $content .= "define('{$key}', '" . bin2hex(random_bytes(32)) . "');\n";
        }
    }

    if (@file_put_contents(SECRETS_FILE, "<?php\n" . $content) === false) {
        err('写入 wp-secrets.php 失败: ' . SECRETS_FILE);
        return;
    }

    @chmod(SECRETS_FILE, 0640);
}

// ---------------------------------------------------------------- 定时任务

/**
 * 跑一次 `wp cron event run --due-now`.
 *
 * 用 proc_open 的数组形式 (distroless 里没有 shell), 子进程的 stdout/stderr 全部收下来交给调用方;
 * 超过 $timeout 秒没结束就强制结束, 避免某个插件卡死拖住整个 cron 循环.
 *
 * @return array{0: int, 1: string} [退出码, 输出]
 */
function run_wp_cron(int $timeout = 300): array
{
    $process = @proc_open(
        [WP_CLI, '--quiet', 'cron', 'event', 'run', '--due-now'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (! is_resource($process)) {
        return [127, 'proc_open 失败'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output  = '';
    $code    = -1;
    $timeoutHit = false;
    $deadline   = microtime(true) + $timeout;

    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (! $status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) > $deadline) {
            $timeoutHit = true;
            @proc_terminate($process, SIGKILL);

            $killDeadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $killDeadline) {
                usleep(100000);
            }

            $code = 124;
            break;
        }

        usleep(200000);
    }

    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    @proc_close($process);

    $output = trim($output);
    if ($timeoutHit) {
        $output = "超过 {$timeout} 秒未结束, 已强制结束. {$output}";
    }

    return [$code, $output];
}

/**
 * 容器托管的 WordPress 定时任务.
 *
 * 镜像默认 DISABLE_WP_CRON=true (关掉请求触发的 wp-cron), 改由这里每分钟跑一次
 * `wp cron event run --due-now`: 单实例顺序执行, 低流量站点定时也准时, 而且不会走
 * wp-cron.php 那条在并发 / 重复排期下会刷 "could_not_set" 的代码路径.
 */
function start_cron_loop(): void
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        err('定时任务 fork 失败, 容器内定时任务不会运行');
        return;
    }
    if ($pid > 0) {
        return;  // 父进程继续 pcntl_exec 到 Caddy, 保持 Caddy 为 PID 1
    }

    out('定时任务已启动 (每分钟 wp cron event run --due-now)');

    $lastSlot = null;
    $lastCode = 0;

    while (true) {
        $slot = intdiv(time(), 60);

        if ($slot !== $lastSlot) {
            $lastSlot = $slot;
            [$code, $output] = run_wp_cron();

            if ($code !== 0) {
                // 只在状态变化时记一行: 新装站点数据库还没好时不会每分钟刷屏
                if ($code !== $lastCode) {
                    err("定时任务失败 (退出码 {$code}): " . ($output === '' ? '(无输出)' : $output));
                }
            } elseif ($output !== '') {
                // 插件 / 任务自己打出来的东西照旧进容器日志 (与旧镜像里 wp-cron.php 的输出一致)
                out('[cron] ' . $output);
            }

            $lastCode = $code;
        }

        usleep(500000);
    }
}

// ---------------------------------------------------------------- 入口

function main(): int
{
    if (! function_exists('pcntl_exec')) {
        err('缺少 pcntl 扩展, 无法启动 frankenphp');
        return 1;
    }
    if (! is_file(CADDYFILE)) {
        err('缺少 Caddyfile: ' . CADDYFILE);
        return 1;
    }

    out('STD-WordPress 启动 (PHP ' . PHP_VERSION . ')');

    init_wp_content();
    ensure_directories();
    write_php_ini();
    ensure_secrets();

    // 定时任务: 默认关掉请求触发的 wp-cron (spawn_cron / wp-cron.php), 由容器内循环托管
    $wpCronDisabled = env_bool('DISABLE_WP_CRON', true);
    if (env_str('DISABLE_WP_CRON') === '') {
        putenv('DISABLE_WP_CRON=' . ($wpCronDisabled ? 'true' : 'false'));
    }

    if ($wpCronDisabled) {
        if (! function_exists('proc_open')) {
            err('缺少 proc_open, 容器内定时任务不会运行');
        } else {
            start_cron_loop();
        }
    } else {
        out('DISABLE_WP_CRON=false: 定时任务由请求触发, 容器内不启动 cron 循环');
    }

    out('启动 frankenphp run');
    pcntl_exec(FRANKENPHP, ['run', '--config', CADDYFILE]);

    err('frankenphp 启动失败');
    return 1;
}

exit(main());

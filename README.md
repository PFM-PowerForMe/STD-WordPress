### 📦 环境变量配置说明

| 变量名 | 类型 | 默认值 | 示例 | 功能说明 |
| :--- | :---: | :---: | :--- | :--- |
| **CR_CADDY_REAL_IP** | String | `X-Forwarded-For` | `CF-Connecting-IP` | 设置反代时识别真实 IP 的请求头（如使用 Cloudflare 时修改） |
| **CR_FPM_PM** | String | `static` | `dynamic` / `ondemand` | PHP-FPM 的进程管理模式 |
| **CR_PHP_TOTAL_MEM** | Integer | `512` | `1024` | PHP 与 FPM 可用的总内存基准大小 (MB)，用于自动计算各缓存与进程参数 |
| **CR_PHP_POST_MAX_SIZE** | String | `1024M` | `50M` | PHP 允许接收的 POST 数据最大体积 (`post_max_size`) |
| **CR_PHP_UPLOAD_MAX_FILESIZE** | String | `1024M` | `50M` | PHP 允许上传的最大单文件体积 (`upload_max_filesize`) |
| **CR_PHP_MAX_EXECUTION_TIME** | Integer | `300` | `60` | PHP 脚本最大执行超时时间，单位秒 (`max_execution_time`) |
| **CR_PHP_MAX_INPUT_TIME** | Integer | `300` | `60` | PHP 接收并解析输入数据的最大时间，单位秒 (`max_input_time`) |
| **CR_PHP_MAX_INPUT_VARS** | Integer | `9999` | `3000` | PHP 允许接收的最大表单变量数量 (`max_input_vars`) |
| **CR_PHP_OPCACHE_VALIDATE** | Integer | `0` | `1` | Opcache 是否检查文件更新。生产环境建议为 0 (最高性能)，开发环境设为 1 |
| **DB_HOST** | String | | `...` | 数据库主机地址 |
| **DB_NAME** | String | | `...` | 数据库名称 |
| **DB_USER** | String | | `...` | 数据库用户名 |
| **DB_PASSWORD** | String | | `..` | 数据库密码 |
| **AUTH_KEY** | String | | `a91a4471b...` | WordPress 身份验证密钥 |
| **SECURE_AUTH_KEY** | String | | `bfbd2baaa...` | WordPress 安全身份验证密钥 |
| **LOGGED_IN_KEY** | String | | `e8f61d26a...` | WordPress 登录身份验证密钥 |
| **NONCE_KEY** | String | | `badc392ce...` | WordPress Nonce 密钥 |
| **AUTH_SALT** | String | | `5b52ebe39...` | WordPress 身份验证盐值 |
| **SECURE_AUTH_SALT** | String | | `376cfa678...` | WordPress 安全身份验证盐值 |
| **LOGGED_IN_SALT** | String | | `6f89b8a51...` | WordPress 登录盐值 |
| **NONCE_SALT** | String | | `d2fd1d445...` | WordPress Nonce 盐值 |
| **WP_REDIS_HOST** | String | | `..` | Redis 服务器地址 |
| **WP_REDIS_PORT** | Integer | | `6379` | Redis 服务器端口 |
| **WP_REDIS_TIMEOUT** | Integer | | `15` | Redis 连接超时时间（秒） |
| **WP_REDIS_DATABASE** | Integer | | `0` | Redis 数据库索引 |
| **WP_REDIS_PREFIX** | String | | `wp_` | Redis 键名前缀 |
| **WP_SITEURL** | String | | `https://..` | WordPress 核心文件所在 URL |
| **WP_HOME** | String | | `https://..` | WordPress 站点首页 URL |
| **FS_METHOD** | String | | `direct` | 文件系统写入方法（推荐 direct，跳过 FTP 凭据验证） |
| **COMPRESS_SCRIPTS** | Boolean | | `false` | 是否开启 JavaScript 脚本压缩 |
| **COMPRESS_CSS** | Boolean | | `false` | 是否开启 CSS 样式表压缩 |
| **CONCATENATE_SCRIPTS** | Boolean | | `false` | 是否强制合并后台脚本以减少请求 |
| **WP_MEMORY_LIMIT** | String | | `256M` | WordPress 前台基础内存限制 |
| **WP_MAX_MEMORY_LIMIT** | String | | `512M` | WordPress 后台管理最大内存限制 |
| **FORCE_SSL_ADMIN** | Boolean | | `true` | 是否强制后台管理页面使用 HTTPS |
| **FORCE_SSL_LOGIN** | Boolean | | `true` | 是否强制登录页面使用 HTTPS |
| **WP_DEBUG** | Boolean | | `false` | 是否开启 WordPress 调试模式 |
| **WP_DEBUG_DISPLAY** | Boolean | | `false` | 是否在页面上显示调试错误信息 |
| **WP_CACHE** | Boolean | | `false` | 是否开启 WordPress 高级对象缓存（配合 Redis 使用） |

---

<?php
/**
 * 配置文件模板
 *
 * 这是系统配置文件的模板，安装过程中会基于此文件创建config.php
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 数据库配置
define('DB_HOST', '{{DB_HOST}}');      // 数据库主机
define('DB_NAME', '{{DB_NAME}}');  // 数据库名
define('DB_USER', '{{DB_USER}}');       // 数据库用户名
define('DB_PASSWORD', '{{DB_PASSWORD}}');   // 数据库密码
define('DB_CHARSET', 'utf8mb4');     // 数据库字符集
define('DB_COLLATE', '');            // 数据库排序规则
define('TABLE_PREFIX', '{{TABLE_PREFIX}}');      // 数据表前缀

// 安全密钥
define('AUTH_KEY',         '{{AUTH_KEY}}');
define('SECURE_AUTH_KEY',  '{{SECURE_AUTH_KEY}}');
define('LOGGED_IN_KEY',    '{{LOGGED_IN_KEY}}');
define('NONCE_KEY',        '{{NONCE_KEY}}');
define('AUTH_SALT',        '{{AUTH_SALT}}');
define('SECURE_AUTH_SALT', '{{SECURE_AUTH_SALT}}');
define('LOGGED_IN_SALT',   '{{LOGGED_IN_SALT}}');
define('NONCE_SALT',       '{{NONCE_SALT}}');

// 后台登录标题
if (!defined('TODO_ADMIN_LOGIN_TITLE')) {
    define('TODO_ADMIN_LOGIN_TITLE', 'TodoCMS');
}

// 后台登录邮箱验证开关（true开启，false关闭）
if (!defined('TODO_ADMIN_LOGIN_EMAIL_VERIFY')) {
    define('TODO_ADMIN_LOGIN_EMAIL_VERIFY', false);
}

// 调试模式（统一入口）
if (!defined('TODO_DEBUG')) {
    define('TODO_DEBUG', false); // 如需开启调试，将 false 改为 true
}

// 性能监控开关
if (!defined('TODO_ENABLE_PERF_MONITOR')) {
    define('TODO_ENABLE_PERF_MONITOR', false); // 强制开启性能监控以调试
}

// AI Agent 主题在线编辑安全开关（服务器端物理防篡改硬锁），默认 false
if (!defined('TODO_AGENT_ALLOW_THEME_EDIT')) {
    define('TODO_AGENT_ALLOW_THEME_EDIT', false);
}
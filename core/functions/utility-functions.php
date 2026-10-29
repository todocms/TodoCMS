<?php
/**
 * TodoCMS 辅助工具函数
 * 中低优先级缺失函数修复
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (file_exists(__DIR__ . '/anti-fraud.php')) {
    require_once __DIR__ . '/anti-fraud.php';
}

if (!function_exists('todo_is_stream')) {
    function todo_is_stream($path)
    {
        $scheme_separator = strpos($path, '://');

        if ($scheme_separator === false) {
            return false;
        }

        $stream = substr($path, 0, $scheme_separator);
        return in_array($stream, stream_get_wrappers(), true);
    }
}

if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        $wrapper = null;

        if (todo_is_stream($target)) {
            list($wrapper, $target) = explode('://', $target, 2);
        }

        $target = str_replace('//', '/', $target);

        if ($wrapper !== null) {
            $target = $wrapper . '://' . $target;
        }

        $target = rtrim($target, '/');
        if ($target === '') {
            $target = '/';
        }

        if (file_exists($target)) {
            return @is_dir($target);
        }

        $target_parent = dirname($target);
        while ($target_parent !== '.' && !is_dir($target_parent) && dirname($target_parent) !== $target_parent) {
            $target_parent = dirname($target_parent);
        }

        if ($stat = @stat($target_parent)) {
            $dir_perms = $stat['mode'] & 0007777;
        } else {
            $dir_perms = 0755;
        }

        if (@mkdir($target, $dir_perms, true)) {
            return true;
        }

        $target_parent = dirname($target);
        if ($target_parent !== $target && todo_mkdir_p($target_parent)) {
            return @mkdir($target, $dir_perms);
        }

        return false;
    }
}

if (!function_exists('todo_has_update_authorization')) {
    function todo_has_update_authorization()
    {
        try {
            if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'check_runtime_license'])) {
                return (bool) call_user_func(['Todo_Core_Kernel', 'check_runtime_license']);
            }
        } catch (Throwable $e) {
        }

        $token_path = defined('ABSPATH') ? (rtrim((string) ABSPATH, '/') . '/license.token') : '';
        $token_ok = ($token_path !== '' && is_file($token_path) && trim((string) @file_get_contents($token_path)) !== '');
        $pub_ok = (defined('TODO_LICENSE_PUBKEY') && (string) constant('TODO_LICENSE_PUBKEY') !== '');

        if (!$token_ok || !$pub_ok) {
            return false;
        }

        $lic_expires = 0;
        if (function_exists('todo_get_option')) {
            $lic_expires = (int) todo_get_option('todo_license_expires', 0);
        }
        if ($lic_expires > 0 && $lic_expires < time()) {
            return false;
        }

        return true;
    }
}

/**
 * 插件安装函数
 * @param string $plugin_path
 * @return bool
 */
function todo_plugin_install($plugin_path = null)
{
    // 兼容运行插件脚本中无参调用
    if ($plugin_path === null) {
        return null;
    }

    if (!file_exists($plugin_path)) {
        return false;
    }

    $plugin_dir = ABSPATH . 'content/plugins/';
    if (!is_dir($plugin_dir)) {
        mkdir($plugin_dir, 0755, true);
    }

    $plugin_name = basename($plugin_path, '.zip');
    $extract_path = $plugin_dir . $plugin_name;

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive;
        if ($zip->open($plugin_path) === TRUE) {
            if (!is_dir($extract_path)) {
                mkdir($extract_path, 0755, true);
            }
            // 安全解压：过滤绝对路径、路径穿越与反斜杠
            $safeEntries = array();
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $name = str_replace('\\', '/', $name);
                if ($name === '') {
                    continue;
                }
                if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    continue;
                }
                if (preg_match('#^([A-Za-z]:|/)#', $name)) {
                    continue;
                }
                $safeEntries[] = $name;
            }
            if (!empty($safeEntries)) {
                $zip->extractTo($extract_path, $safeEntries);
            }
            $zip->close();
            // 移除可能的符号链接，防止绕过
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extract_path, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $info) {
                $p = $info->getPathname();
                if (is_link($p)) {
                    @unlink($p);
                }
            }
            return true;
        }
    }

    return false;
}

/**
 * 通过ZIP安装插件（兼容管理页面调用）
 * @param string $zip_file
 * @return bool|TODO_Error 成功返回true，失败返回错误对象
 */
function install_plugin_from_zip($zip_file)
{
    if (!file_exists($zip_file)) {
        return new TODO_Error('file_missing', '上传的ZIP文件不存在');
    }
    if (strtolower(pathinfo($zip_file, PATHINFO_EXTENSION)) !== 'zip') {
        return new TODO_Error('invalid_type', '文件类型不是ZIP');
    }
    $result = todo_plugin_install($zip_file);
    if ($result !== true) {
        return new TODO_Error('install_failed', '插件安装失败');
    }
    return true;
}

/**
 * 获取文件扩展名
 * @param string $filename
 * @return string
 */
function get_file_extension($filename)
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * 检查文件类型是否允许
 * @param string $filename
 * @param array $allowed_types
 * @return bool
 */
function is_allowed_file_type($filename, $allowed_types = [])
{
    if (empty($allowed_types)) {
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'zip'];
    }

    $extension = get_file_extension($filename);
    return in_array($extension, $allowed_types);
}

/**
 * 格式化文件大小
 * @param int $bytes
 * @param int $precision
 * @return string
 */
function format_file_size($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * 验证邮箱格式
 * @param string $email
 * @return bool
 */
function is_valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 验证URL格式
 * @param string $url
 * @return bool
 */
function is_valid_url($url)
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * 转义HTML特殊字符
 * @param string $string
 * @return string
 */
function escape_html($string)
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * 截取字符串
 * @param string $string
 * @param int $length
 * @param string $suffix
 * @return string
 */
function truncate_string($string, $length = 100, $suffix = '...')
{
    $string = (string) ($string ?? '');
    $length = (int) $length;
    if ($length <= 0) {
        return '';
    }

    $use_mb = function_exists('mb_strlen') && function_exists('mb_substr');
    if ($use_mb) {
        if (mb_strlen($string, 'UTF-8') <= $length) {
            return $string;
        }
        return mb_substr($string, 0, $length, 'UTF-8') . $suffix;
    }

    if (strlen($string) <= $length) {
        return $string;
    }
    return substr($string, 0, $length) . $suffix;
}

/**
 * 获取客户端IP地址
 * @return string
 */
function get_client_ip()
{
    $server = isset($_SERVER) ? $_SERVER : [];
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $server) === true) {
            foreach (explode(',', $server[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }

    return $server['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 发送邮件
 * @param string $to
 * @param string $subject
 * @param string $message
 * @param array $headers
 * @return bool
 */
function todo_send_mail($to, $subject, $message, $headers = [])
{
    $default_headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . get_option('admin_email', 'noreply@' . todo_get_http_host())
    ];

    $headers = array_merge($default_headers, $headers);
    $headers_string = implode("\r\n", $headers);

    return mail($to, $subject, $message, $headers_string);
}

/**
 * 设置系统选项
 * @param string $option_name
 * @param mixed $option_value
 * @return bool
 */
if (!function_exists('update_option')) {
    function update_option($option_name, $option_value)
    {
        // 使用CMS_Option类处理选项更新
        if (class_exists('CMS_Option')) {
            return CMS_Option::update($option_name, $option_value);
        }

        // 如果CMS_Option类不存在，记录错误
        todo_log('error', 'CMS_Option class not found for update_option');
        return false;
    }
}

if (!function_exists('todo_regions_normalize_code')) {
    function todo_regions_normalize_code($code)
    {
        $code = preg_replace('/\D+/', '', (string) ($code ?? ''));
        if ($code === '') {
            return '';
        }
        if (strlen($code) === 6) {
            return $code . '000000';
        }
        return $code;
    }
}

if (!function_exists('todo_regions_get_json_path')) {
    function todo_regions_get_json_path($level)
    {
        $level = (int) $level;
        $map = [
            1 => '01-province.json',
            2 => '02-city.json',
            3 => '03-district.json',
            4 => '04-street.json'
        ];
        if (!isset($map[$level])) {
            return '';
        }
        $base = defined('ABSPATH') ? ABSPATH : '';
        if ($base === '') {
            return '';
        }
        $path = rtrim($base, "/\\") . '/public/static/json/' . $map[$level];
        return $path;
    }
}

if (!function_exists('todo_regions_get_cache_dir')) {
    function todo_regions_get_cache_dir()
    {
        $dir = '';
        if (function_exists('get_cache_dir')) {
            $dir = (string) get_cache_dir('data');
        }
        if ($dir === '' && defined('ABSPATH')) {
            $dir = rtrim(ABSPATH, "/\\") . '/content/cache/data/';
        }
        if ($dir === '') {
            $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
            $dir = rtrim($tmp, "/\\") . '/todocms-cache/data/';
        }
        $dir = rtrim($dir, "/\\") . '/regions/';
        if (function_exists('todo_mkdir_p')) {
            todo_mkdir_p($dir);
        } else {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('todo_regions_get_json_mtime_map')) {
    function todo_regions_get_json_mtime_map()
    {
        $map = [];
        for ($i = 1; $i <= 4; $i++) {
            $p = todo_regions_get_json_path($i);
            $map[$i] = ($p !== '' && is_file($p)) ? (int) @filemtime($p) : 0;
        }
        return $map;
    }
}

if (!function_exists('todo_regions_cleanup_cache')) {
    function todo_regions_cleanup_cache($force = false)
    {
        static $did = false;
        if ($did && !$force) {
            return;
        }
        $cache_dir = todo_regions_get_cache_dir();
        if ($cache_dir === '' || !is_dir($cache_dir)) {
            return;
        }

        $now = time();
        $flag = rtrim($cache_dir, "/\\") . '/.cleanup_ts';
        $last = is_file($flag) ? (int) @file_get_contents($flag) : 0;
        if (!$force && $last > 0 && ($now - $last) < 3600) {
            $did = true;
            return;
        }

        $mtimes = todo_regions_get_json_mtime_map();
        $files = @glob(rtrim($cache_dir, "/\\") . '/*.php');
        if (!is_array($files)) {
            $files = [];
        }

        foreach ($files as $f) {
            if (!is_string($f) || $f === '') {
                continue;
            }
            $base = basename($f);
            if (preg_match('/^provinces_(\d+)\.php$/', $base, $m)) {
                $t = (int) ($m[1] ?? 0);
                if ($t > 0 && (int) ($mtimes[1] ?? 0) > 0 && $t !== (int) $mtimes[1]) {
                    @unlink($f);
                }
                continue;
            }
            if (preg_match('/^(children|name)_l(\d+)_.*_(\d+)\.php$/', $base, $m)) {
                $lvl = (int) ($m[2] ?? 0);
                $t = (int) ($m[3] ?? 0);
                if ($lvl >= 1 && $lvl <= 5 && (int) ($mtimes[$lvl] ?? 0) > 0 && $t > 0 && $t !== (int) $mtimes[$lvl]) {
                    @unlink($f);
                }
                continue;
            }
        }

        $tmps = @glob(rtrim($cache_dir, "/\\") . '/*.tmp_*');
        if (is_array($tmps)) {
            foreach ($tmps as $t) {
                $age = (int) (@filemtime($t) ?: 0);
                if ($age > 0 && ($now - $age) > 86400) {
                    @unlink($t);
                }
            }
        }

        @file_put_contents($flag, (string) $now);
        $did = true;
    }
}

if (!function_exists('todo_regions_load_level_rows')) {
    function todo_regions_load_level_rows($level)
    {
        static $rows_cache = [];
        $level = (int) $level;
        if (isset($rows_cache[$level])) {
            return $rows_cache[$level];
        }
        if ($level === 5) {
            $rows_cache[$level] = [];
            return $rows_cache[$level];
        }
        $path = todo_regions_get_json_path($level);
        if ($path === '' || !is_file($path)) {
            $rows_cache[$level] = [];
            return $rows_cache[$level];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            $rows_cache[$level] = [];
            return $rows_cache[$level];
        }
        $decoded = json_decode($raw, true);
        $rows_cache[$level] = is_array($decoded) ? $decoded : [];
        return $rows_cache[$level];
    }
}

if (!function_exists('todo_regions_scan_pretty_json_children')) {
    function todo_regions_scan_pretty_json_children($path, $parent_code)
    {
        $parent_code = todo_regions_normalize_code($parent_code);
        if ($parent_code === '' || $path === '' || !is_file($path)) {
            return [];
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [];
        }
        $out = [];
        $cur_code = '';
        $cur_name = '';
        $cur_parent = '';
        while (($line = fgets($fh)) !== false) {
            $t = trim($line);
            if ($t === '{') {
                $cur_code = '';
                $cur_name = '';
                $cur_parent = '';
                continue;
            }
            if (preg_match('/"code"\s*:\s*"([^"]+)"/u', $t, $m)) {
                $cur_code = (string) ($m[1] ?? '');
                continue;
            }
            if (preg_match('/"name"\s*:\s*"([^"]+)"/u', $t, $m)) {
                $cur_name = (string) ($m[1] ?? '');
                continue;
            }
            if (preg_match('/"parent_code"\s*:\s*"([^"]+)"/u', $t, $m)) {
                $cur_parent = (string) ($m[1] ?? '');
                continue;
            }
            if ($t === '}' || $t === '},') {
                $p = todo_regions_normalize_code($cur_parent);
                if ($p === $parent_code) {
                    $c = todo_regions_normalize_code($cur_code);
                    if ($c !== '' && $cur_name !== '') {
                        $out[$c] = $cur_name;
                    }
                }
                $cur_code = '';
                $cur_name = '';
                $cur_parent = '';
            }
        }
        fclose($fh);
        return $out;
    }
}

if (!function_exists('todo_regions_scan_pretty_json_name')) {
    function todo_regions_scan_pretty_json_name($path, $code)
    {
        $code = todo_regions_normalize_code($code);
        if ($code === '' || $path === '' || !is_file($path)) {
            return '';
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        $cur_code = '';
        $cur_name = '';
        while (($line = fgets($fh)) !== false) {
            $t = trim($line);
            if ($t === '{') {
                $cur_code = '';
                $cur_name = '';
                continue;
            }
            if ($cur_code === '' && preg_match('/"code"\s*:\s*"([^"]+)"/u', $t, $m)) {
                $cur_code = (string) ($m[1] ?? '');
                continue;
            }
            if ($cur_name === '' && preg_match('/"name"\s*:\s*"([^"]+)"/u', $t, $m)) {
                $cur_name = (string) ($m[1] ?? '');
                continue;
            }
            if ($t === '}' || $t === '},') {
                $c = todo_regions_normalize_code($cur_code);
                if ($c === $code) {
                    fclose($fh);
                    return $cur_name;
                }
                $cur_code = '';
                $cur_name = '';
            }
        }
        fclose($fh);
        return '';
    }
}

if (!function_exists('todo_regions_get_provinces')) {
    function todo_regions_get_provinces()
    {
        if (function_exists('todo_regions_cleanup_cache')) {
            todo_regions_cleanup_cache(false);
        }
        $path = todo_regions_get_json_path(1);
        if ($path === '' || !is_file($path)) {
            return [];
        }
        $mtime = (int) @filemtime($path);
        $cache_file = todo_regions_get_cache_dir() . 'provinces_' . $mtime . '.php';
        if (is_file($cache_file)) {
            $data = @include $cache_file;
            return is_array($data) ? $data : [];
        }

        $rows = todo_regions_load_level_rows(1);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = todo_regions_normalize_code($row['code'] ?? '');
            $name = isset($row['name']) ? (string) $row['name'] : '';
            if ($code === '' || $name === '') {
                continue;
            }
            $out[$code] = $name;
        }

        $php = '<?php return ' . var_export($out, true) . ';';
        $tmp = $cache_file . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmp, $php) !== false) {
            @rename($tmp, $cache_file);
        } else {
            @unlink($tmp);
        }

        return $out;
    }
}

if (!function_exists('todo_regions_get_children')) {
    function todo_regions_get_children($parent_code, $child_level = null)
    {
        if (function_exists('todo_regions_cleanup_cache')) {
            todo_regions_cleanup_cache(false);
        }
        $parent_code = todo_regions_normalize_code($parent_code);
        if ($parent_code === '') {
            return [];
        }

        $levels = [];
        if ($child_level !== null) {
            $lvl = (int) $child_level;
            if ($lvl >= 2 && $lvl <= 5) {
                $levels = [$lvl];
            }
        } else {
            $levels = [2, 3, 4, 5];
        }

        foreach ($levels as $level) {
            $path = todo_regions_get_json_path($level);
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            $cache_file = todo_regions_get_cache_dir() . 'children_l' . $level . '_p' . $parent_code . '_' . $mtime . '.php';
            if (is_file($cache_file)) {
                $data = @include $cache_file;
                if (is_array($data)) {
                    if (!empty($data) || $child_level !== null) {
                        return $data;
                    }
                }
            }

            if ($level === 5) {
                $out = function_exists('todo_regions_scan_pretty_json_children')
                    ? todo_regions_scan_pretty_json_children($path, $parent_code)
                    : [];
            } else {
                $rows = todo_regions_load_level_rows($level);
                $out = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $p = todo_regions_normalize_code($row['parent_code'] ?? '');
                    if ($p !== $parent_code) {
                        continue;
                    }
                    $code = todo_regions_normalize_code($row['code'] ?? '');
                    $name = isset($row['name']) ? (string) $row['name'] : '';
                    if ($code === '' || $name === '') {
                        continue;
                    }
                    $out[$code] = $name;
                }
            }

            $php = '<?php return ' . var_export($out, true) . ';';
            $tmp = $cache_file . '.' . uniqid('tmp_', true);
            if (@file_put_contents($tmp, $php) !== false) {
                @rename($tmp, $cache_file);
            } else {
                @unlink($tmp);
            }

            if (!empty($out) || $child_level !== null) {
                return $out;
            }
        }

        return [];
    }
}

if (!function_exists('todo_regions_find_name')) {
    function todo_regions_find_name($code)
    {
        if (function_exists('todo_regions_cleanup_cache')) {
            todo_regions_cleanup_cache(false);
        }
        $code = todo_regions_normalize_code($code);
        if ($code === '') {
            return '';
        }
        $suffix = substr($code, -10);
        $level = 5;
        if ($suffix === '0000000000') {
            $level = 1;
        } elseif (substr($code, -8) === '00000000') {
            $level = 2;
        } elseif (substr($code, -6) === '000000') {
            $level = 3;
        } elseif (substr($code, -3) === '000') {
            $level = 4;
        }
        $path = todo_regions_get_json_path($level);
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $mtime = (int) @filemtime($path);
        $cache_file = todo_regions_get_cache_dir() . 'name_l' . $level . '_c' . $code . '_' . $mtime . '.php';
        if (is_file($cache_file)) {
            $val = @include $cache_file;
            return is_string($val) ? $val : '';
        }
        if ($level === 5) {
            $name = function_exists('todo_regions_scan_pretty_json_name')
                ? todo_regions_scan_pretty_json_name($path, $code)
                : '';
        } else {
            $rows = todo_regions_load_level_rows($level);
            $name = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $c = todo_regions_normalize_code($row['code'] ?? '');
                if ($c !== $code) {
                    continue;
                }
                $name = isset($row['name']) ? (string) $row['name'] : '';
                break;
            }
        }
        $php = '<?php return ' . var_export($name, true) . ';';
        $tmp = $cache_file . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmp, $php) !== false) {
            @rename($tmp, $cache_file);
        } else {
            @unlink($tmp);
        }
        return $name;
    }
}

if (!function_exists('todo_db_table_exists')) {
    function todo_db_table_exists($table_name)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $full = $prefix . ltrim($table_name, '_');
        
        $cache_key = 'table_exists_' . md5($full);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = (bool) $tododb->table_exists($full);
            } else {
                $val = $tododb->get_var(
                    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
                    [$full]
                );
                $exists = !empty($val);
            }
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_db_column_exists')) {
    function todo_db_column_exists($table_name, $column_name)
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $full = $prefix . ltrim($table_name, '_');
        
        $cache_key = 'column_exists_' . md5($full . '_' . $column_name);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'database', 'global');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        try {
            $val = $tododb->get_var(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1",
                [$full, $column_name]
            );
            $exists = !empty($val);
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $exists, 86400, 'database', 'global');
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_db_add_column_if_missing')) {
    function todo_db_add_column_if_missing($table_name, $column_name, $column_definition, $after_column = '')
    {
        global $tododb;
        if (!$tododb) {
            return false;
        }
        if (!todo_db_table_exists($table_name)) {
            return false;
        }
        if (todo_db_column_exists($table_name, $column_name)) {
            return true;
        }
        $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $full = $prefix . ltrim($table_name, '_');
        $sql = "ALTER TABLE `{$full}` ADD COLUMN `{$column_name}` {$column_definition}";
        if ($after_column !== '') {
            $sql .= " AFTER `{$after_column}`";
        }
        try {
            return $tododb->query($sql) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_get_db_version')) {
    function todo_get_db_version()
    {
        if (class_exists('CMS_Option')) {
            return (string) CMS_Option::get('db_version', '0.0.0');
        }
        global $tododb;
        if (!$tododb) {
            return '0.0.0';
        }
        if (!todo_db_table_exists('system_config')) {
            return '0.0.0';
        }
        try {
            $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
            $row = $tododb->get_var(
                "SELECT config_value FROM " . $prefix . "system_config WHERE config_key = 'db_version' LIMIT 1"
            );
            return $row !== null ? (string) $row : '0.0.0';
        } catch (Throwable $e) {
            return '0.0.0';
        }
    }
}

if (!function_exists('todo_set_db_version')) {
    function todo_set_db_version($version)
    {
        $version = trim((string) $version);
        if ($version === '') {
            return false;
        }
        if (class_exists('CMS_Option')) {
            return (bool) CMS_Option::update('db_version', $version);
        }
        global $tododb;
        if (!$tododb) {
            return false;
        }
        if (!todo_db_table_exists('system_config')) {
            return false;
        }
        try {
            $prefix = isset($tododb->prefix) ? (string) $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
            $tododb->query(
                "INSERT INTO " . $prefix . "system_config (config_key, config_value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE config_value = ?",
                [$version, $version]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('todo_get_required_db_version')) {
    function todo_get_required_db_version()
    {
        if (class_exists('Todo_DB_Migrator')) {
            return Todo_DB_Migrator::get_target_version();
        }
        return defined('TODO_VERSION') ? (string) TODO_VERSION : '1.0.0';
    }
}

if (!function_exists('todo_needs_db_update')) {
    function todo_needs_db_update()
    {
        if (class_exists('Todo_DB_Migrator')) {
            $current = Todo_DB_Migrator::get_current_db_version();
            $target = Todo_DB_Migrator::get_target_version();
            return version_compare($current, $target, '<');
        }
        $current = todo_get_db_version();
        $required = todo_get_required_db_version();
        return version_compare($current, $required, '<');
    }
}

if (!function_exists('todo_db_schema_sync')) {
    function todo_db_schema_sync()
    {
        $migrator_file = ABSPATH . 'core/classes/class-db-migrator.php';
        if (is_file($migrator_file)) {
            require_once $migrator_file;
        }

        if (class_exists('Todo_DB_Migrator')) {
            return Todo_DB_Migrator::check_and_run();
        }

        return false;
    }
}

if (!function_exists('todo_run_db_updates')) {
    function todo_run_db_updates($target_version = '')
    {
        $migrator_file = ABSPATH . 'core/classes/class-db-migrator.php';
        if (is_file($migrator_file)) {
            require_once $migrator_file;
        }

        if (class_exists('Todo_DB_Migrator')) {
            $from = Todo_DB_Migrator::get_current_db_version();
            $to = !empty($target_version) ? $target_version : Todo_DB_Migrator::get_target_version();
            $ok = Todo_DB_Migrator::run_migration($from, $to);
            return ['ok' => $ok, 'from' => $from, 'to' => $to, 'ran' => [$to]];
        }

        return ['ok' => false, 'error' => 'migrator_missing'];
    }
}

if (!function_exists('todo_maybe_run_db_updates')) {
    function todo_maybe_run_db_updates()
    {
        if (defined('INSTALLING') && INSTALLING) {
            return ['ok' => true, 'skipped' => 'installing'];
        }
        if (!todo_needs_db_update()) {
            return ['ok' => true, 'skipped' => 'up_to_date'];
        }

        return todo_run_db_updates();
    }
}

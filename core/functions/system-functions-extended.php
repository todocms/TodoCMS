<?php
/**
 * TodoCMS 系统扩展函数
 * 
 * 包含升级包管理、资源管理、错误处理等系统级扩展函数
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * ========================================
 * 升级包管理函数
 * ========================================
 */

/**
 * 更新升级包
 * 
 * @param int $package_id 升级包ID
 * @param array $data 更新数据
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_update_upgrade_package($package_id, $data)
{
    global $tododb;

    try {
        // 验证包ID
        if (!is_numeric($package_id) || $package_id <= 0) {
            return array('error' => '无效的升级包ID');
        }

        // 验证数据
        $allowed_fields = array(
            'package_name',
            'version',
            'description',
            'file_path',
            'file_size',
            'status',
            'release_date',
            'requirements'
        );

        $update_data = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $update_data[$key] = todo_sanitize_text($value);
            }
        }

        if (empty($update_data)) {
            return array('error' => '没有有效的更新数据');
        }

        // 添加更新时间
        $update_data['updated_at'] = current_time('mysql');

        // 构建更新SQL
        $set_clauses = array();
        $values = array();

        foreach ($update_data as $field => $value) {
            $set_clauses[] = "`{$field}` = ?";
            $values[] = $value;
        }

        $values[] = $package_id;

        $sql = "UPDATE `" . TABLE_PREFIX . "upgrade_packages` 
                SET " . implode(', ', $set_clauses) . " 
                WHERE `id` = ?";

        $result = tododb_query($sql, $values);
        if ($result) {
            // 记录日志
            todo_log_action('upgrade_package_updated', array(
                'package_id' => $package_id,
                'updated_fields' => array_keys($update_data)
            ));

            return true;
        }

        return array('error' => '数据库更新失败');

    } catch (Exception $e) {
        todo_log_error('升级包更新失败: ' . $e->getMessage());
        return array('error' => '更新过程中发生错误');
    }
}

/**
 * 删除升级包
 * 
 * @param int $package_id 升级包ID
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_delete_upgrade_package($package_id)
{
    global $tododb;

    try {
        // 验证包ID
        if (!is_numeric($package_id) || $package_id <= 0) {
            return array('error' => '无效的升级包ID');
        }

        // 获取包信息
        $package = $tododb->get_row(
            "SELECT * FROM `" . TABLE_PREFIX . "upgrade_packages` WHERE `id` = ?",
            array($package_id)
        );

        if (!$package) {
            return array('error' => '升级包不存在');
        }

        // 检查是否有依赖
        $dependencies = $tododb->get_var(
            "SELECT COUNT(*) FROM `" . TABLE_PREFIX . "upgrade_dependencies` 
             WHERE `required_package_id` = ?",
            array($package_id)
        );

        if ($dependencies > 0) {
            return array('error' => '该升级包被其他包依赖，无法删除');
        }

        // 删除相关文件
        if (!empty($package->file_path) && file_exists($package->file_path)) {
            if (!unlink($package->file_path)) {
                todo_log_error('无法删除升级包文件: ' . $package->file_path);
            }
        }

        // 删除数据库记录
        $result = $tododb->delete(
            TABLE_PREFIX . 'upgrade_packages',
            ['id' => $package_id]
        );

        if ($result) {
            // 删除相关依赖记录
            $tododb->delete(
                TABLE_PREFIX . 'upgrade_dependencies',
                ['package_id' => $package_id]
            );

            // 记录日志
            todo_log_action('upgrade_package_deleted', array(
                'package_id' => $package_id,
                'package_name' => $package->package_name
            ));

            return true;
        }

        return array('error' => '数据库删除失败');

    } catch (Exception $e) {
        todo_log_error('升级包删除失败: ' . $e->getMessage());
        return array('error' => '删除过程中发生错误');
    }
}

/**
 * 处理升级包订单
 * 
 * @param int $order_id 订单ID
 * @param array $packages 升级包列表
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_process_package_order($order_id, $packages)
{
    global $tododb;

    try {
        // 验证订单ID
        if (!is_numeric($order_id) || $order_id <= 0) {
            return array('error' => '无效的订单ID');
        }

        // 验证包列表
        if (!is_array($packages) || empty($packages)) {
            return array('error' => '升级包列表为空');
        }

        // 开始事务
        $tododb->begin_transaction();

        $total_amount = 0;
        $processed_packages = array();

        foreach ($packages as $package_data) {
            $package_id = intval($package_data['package_id']);
            $quantity = intval($package_data['quantity']);

            // 获取包信息
            $package = $tododb->get_row(
                "SELECT * FROM `" . TABLE_PREFIX . "upgrade_packages` 
                 WHERE `id` = ? AND `status` = 'active'",
                array($package_id)
            );

            if (!$package) {
                $tododb->rollback();
                return array('error' => "升级包 {$package_id} 不存在或已停用");
            }

            // 计算金额
            $package_total = $package->price * $quantity;
            $total_amount += $package_total;

            // 记录订单项
            $result = $tododb->insert(
                TABLE_PREFIX . 'order_items',
                [
                    'order_id' => $order_id,
                    'package_id' => $package_id,
                    'package_name' => $package->package_name,
                    'price' => $package->price,
                    'quantity' => $quantity,
                    'total' => $package_total
                ]
            );

            if (!$result) {
                $tododb->rollback();
                return array('error' => '订单项创建失败');
            }

            $processed_packages[] = array(
                'package_id' => $package_id,
                'name' => $package->package_name,
                'quantity' => $quantity,
                'total' => $package_total
            );
        }

        // 更新订单总金额
        $result = $tododb->update(
            TABLE_PREFIX . 'orders',
            [
                'total_amount' => $total_amount,
                'status' => 'pending',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $order_id]
        );

        if (!$result) {
            $tododb->rollback();
            return array('error' => '订单更新失败');
        }

        // 提交事务
        $tododb->commit();

        // 记录日志
        todo_log_action('package_order_processed', array(
            'order_id' => $order_id,
            'packages' => $processed_packages,
            'total_amount' => $total_amount
        ));

        return array(
            'success' => true,
            'order_id' => $order_id,
            'total_amount' => $total_amount,
            'packages' => $processed_packages
        );

    } catch (Exception $e) {
        $tododb->rollback();
        todo_log_error('升级包订单处理失败: ' . $e->getMessage());
        return array('error' => '订单处理过程中发生错误');
    }
}

/**
 * ========================================
 * 资源管理函数
 * ========================================
 */

/**
 * 注册样式文件
 * 
 * @param string $handle 样式句柄
 * @param string $src 样式文件路径
 * @param array $deps 依赖项
 * @param string $ver 版本号
 * @param string $media 媒体类型
 * @return bool 注册是否成功
 */
function todo_register_style($handle, $src, $deps = array(), $ver = false, $media = 'all')
{
    global $todo_styles;

    if (!isset($todo_styles)) {
        $todo_styles = array();
    }

    // 验证参数
    if (empty($handle) || empty($src)) {
        return false;
    }

    // 处理版本号
    if ($ver === false) {
        $ver = TODO_VERSION;
    }

    // 处理依赖项
    if (!is_array($deps)) {
        $deps = array();
    }

    // 注册样式
    $todo_styles[$handle] = array(
        'handle' => $handle,
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
        'media' => $media,
        'registered' => true,
        'enqueued' => false
    );

    return true;
}

/**
 * 注册脚本文件
 * 
 * @param string $handle 脚本句柄
 * @param string $src 脚本文件路径
 * @param array $deps 依赖项
 * @param string $ver 版本号
 * @param bool $in_footer 是否在页脚加载
 * @return bool 注册是否成功
 */
function todo_register_script($handle, $src, $deps = array(), $ver = false, $in_footer = false)
{
    global $todo_scripts;

    if (!isset($todo_scripts)) {
        $todo_scripts = array();
    }

    // 验证参数
    if (empty($handle) || empty($src)) {
        return false;
    }

    // 处理版本号
    if ($ver === false) {
        $ver = TODO_VERSION;
    }

    // 处理依赖项
    if (!is_array($deps)) {
        $deps = array();
    }

    // 注册脚本
    $todo_scripts[$handle] = array(
        'handle' => $handle,
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
        'in_footer' => $in_footer,
        'registered' => true,
        'enqueued' => false,
        'localized' => array()
    );

    return true;
}

/**
 * ========================================
 * 错误处理函数
 * ========================================
 */

/**
 * 显示404错误页面
 * 
 * @param string $message 自定义错误消息
 * @param bool $exit 是否退出执行
 */
function todo_404($message = '', $exit = true)
{
    // 设置HTTP状态码
    if (!headers_sent()) {
        header('HTTP/1.1 404 Not Found');
        header('Status: 404 Not Found');
    }

    // 记录404错误
    todo_log_error('404 Error: ' . $_SERVER['REQUEST_URI'] .
        ($message ? ' - ' . $message : ''));

    // 尝试加载主题的404页面
    $theme_404 = todo_get_theme_path() . '/404.php';
    if (file_exists($theme_404)) {
        include $theme_404;
    } else {
        // 默认404页面
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <title>页面未找到 - <?php echo todo_get_option('site_title', 'TodoCMS'); ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 50px;
                }

                .error-404 {
                    max-width: 600px;
                    margin: 0 auto;
                }

                h1 {
                    color: #e74c3c;
                    font-size: 72px;
                    margin: 0;
                }

                h2 {
                    color: #333;
                    margin: 20px 0;
                }

                p {
                    color: #666;
                    line-height: 1.6;
                }

                .back-link {
                    display: inline-block;
                    margin-top: 20px;
                    padding: 10px 20px;
                    background: #3498db;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                }
            </style>
        </head>

        <body>
            <div class="error-404">
                <h1>404</h1>
                <h2>页面未找到</h2>
                <p><?php echo $message ? todo_esc_html($message) : '抱歉，您访问的页面不存在。'; ?></p>
                <a href="<?php echo todo_get_home_url(); ?>" class="back-link">返回首页</a>
            </div>
        </body>

        </html>
        <?php
    }

    if ($exit) {
        exit;
    }
}

/**
 * ========================================
 * AJAX安全函数
 * ========================================
 */

/**
 * 检查AJAX引用
 * 
 * @param string $action 动作名称
 * @param string $query_arg 查询参数名
 * @param bool $die 验证失败是否退出
 * @return bool|int 验证结果
 */
function check_ajax_referer($action = -1, $query_arg = '_ajax_nonce', $die = true)
{
    $nonce = '';

    // 从不同位置获取nonce
    if (isset($_REQUEST[$query_arg])) {
        $nonce = $_REQUEST[$query_arg];
    } elseif (isset($_REQUEST['_todononce'])) {
        $nonce = $_REQUEST['_todononce'];
    }

    // 验证nonce
    $result = Todo_Security_Manager::verify_request_csrf($action, $nonce);

    if (!$result && $die) {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            todo_die_json(array('error' => 'AJAX引用验证失败'));
        } else {
            todo_die('AJAX引用验证失败');
        }
    }

    return $result;
}

/**
 * ========================================
 * 数据库结构更新函数
 * ========================================
 */

/**
 * 执行数据库表结构更新
 * 
 * @param string $sql SQL语句
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_delta($sql)
{
    global $tododb;

    try {
        // 验证SQL语句
        if (empty($sql)) {
            return array('error' => 'SQL语句为空');
        }

        // 分割多个SQL语句
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $results = array();

        foreach ($statements as $statement) {
            if (empty($statement)) {
                continue;
            }

            // 执行SQL语句
            $result = $tododb->query($statement);

            if ($result === false) {
                $error = $tododb->last_error();
                todo_log_error('数据库更新失败: ' . $error . ' - SQL: ' . $statement);
                return array('error' => '数据库更新失败: ' . $error);
            }

            $results[] = array(
                'sql' => $statement,
                'result' => $result
            );
        }

        // 记录成功日志
        todo_log_action('database_updated', array(
            'statements_count' => count($statements),
            'results' => $results
        ));

        return true;

    } catch (Exception $e) {
        todo_log_error('数据库更新异常: ' . $e->getMessage());
        return array('error' => '数据库更新过程中发生异常');
    }
}

/**
 * ========================================
 * 辅助函数
 * ========================================
 */

/**
 * 获取当前主题路径
 * 
 * @return string 主题路径
 */
function todo_get_theme_path()
{
    $theme = get_theme_name();
    return THEMES_DIR . '/' . $theme;
}

/**
 * JSON格式退出
 * 
 * @param mixed $data 要输出的数据
 */
function todo_die_json($data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * 获取网站首页URL
 * 
 * @return string 首页URL
 */
function todo_get_home_url()
{
    // 获取协议
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';

    // 获取主机名
    $host = todo_get_http_host();

    // 获取脚本路径
    $script_name = $_SERVER['SCRIPT_NAME'];
    $path = dirname($script_name);

    // 如果在子目录中，需要去掉admin等路径
    $path = preg_replace('/\/admin.*$/', '', $path);
    $path = rtrim($path, '/');

    return $protocol . $host . $path . '/';
}

/**
 * 邮箱地址清理函数
 * 
 * @param string $email 邮箱地址
 * @return string 清理后的邮箱地址
 */
if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        // 移除空白字符
        $email = trim($email);

        // 转换为小写
        $email = strtolower($email);

        // 使用PHP内置的邮箱验证过滤器
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        // 验证邮箱格式
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return '';
    }
}
/**
 * 日志记录函数
 * 记录系统操作日志
 */
if (!function_exists('todo_log_action')) {
    function todo_log_action($action, $details = '', $user_id = null)
    {
        global $tododb;

        if (!$user_id) {
            $user_id = todo_get_current_user_id();
        }

        $log_data = array(
            'action' => sanitize_text_field($action),
            'details' => sanitize_textarea_field($details),
            'user_id' => intval($user_id),
            'ip_address' => todo_get_user_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'created_at' => current_time('mysql')
        );

        // 如果数据库可用，记录到数据库（使用已存在的 activity_log 表），同时将详情写入文件，避免信息丢失
        if ($tododb) {
            try {
                $activity_data = array(
                    'user_id' => $log_data['user_id'],
                    'action' => $log_data['action'],
                    'object_type' => 'system',
                    'object_id' => 0,
                    'result' => 'success',
                    'ip_address' => $log_data['ip_address'],
                    'user_agent' => $log_data['user_agent'],
                    'created_at' => $log_data['created_at'],
                );
                $tododb->insert("{$tododb->prefix}activity_log", $activity_data);
                if (!empty($log_data['details'])) {
                    error_log("TodoCMS Action Details: {$log_data['action']} - {$log_data['details']} (User: {$log_data['user_id']})");
                }
            } catch (Exception $e) {
                // 如果数据库记录失败，记录到文件并带上错误信息
                error_log("TodoCMS Action Log DB Error: {$e->getMessage()} | {$log_data['action']} - {$log_data['details']} (User: {$log_data['user_id']})");
            }
        } else {
            // 记录到错误日志
            error_log("TodoCMS Action Log: {$log_data['action']} - {$log_data['details']} (User: {$log_data['user_id']})");
        }

        return true;
    }
}

/**
 * 获取所有HTTP头信息的兼容性函数
 * 在某些服务器环境中getallheaders函数可能不存在
 */
if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = array();

        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        return $headers;
    }
}

/**
 * 获取用户IP地址
 */
if (!function_exists('todo_get_user_ip')) {
    function todo_get_user_ip()
    {
        // 检查是否有代理IP
        if (todo_server_string('HTTP_CLIENT_IP') !== '') {
            $ip = todo_server_string('HTTP_CLIENT_IP');
        } elseif (todo_server_string('HTTP_X_FORWARDED_FOR') !== '') {
            $ip = todo_server_string('HTTP_X_FORWARDED_FOR');
        } elseif (todo_server_string('HTTP_X_FORWARDED') !== '') {
            $ip = todo_server_string('HTTP_X_FORWARDED');
        } elseif (todo_server_string('HTTP_FORWARDED_FOR') !== '') {
            $ip = todo_server_string('HTTP_FORWARDED_FOR');
        } elseif (todo_server_string('HTTP_FORWARDED') !== '') {
            $ip = todo_server_string('HTTP_FORWARDED');
        } else {
            $ip = todo_server_string('REMOTE_ADDR');
        }

        // 如果有多个IP，取第一个
        if ($ip !== '' && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}

/**
 * 创建类构造函数 - Todo_Logger
 */
if (!function_exists('Todo_Logger')) {
    function Todo_Logger($config = array())
    {
        return new Todo_Logger_Class($config);
    }
}

/**
 * Todo_Logger 类
 */
if (!class_exists('Todo_Logger_Class')) {
    class Todo_Logger_Class
    {
        private $log_file;
        private $log_level;

        public function __construct($config = array())
        {
            $this->log_file = isset($config['file']) ? $config['file'] : ABSPATH . 'logs/system.log';
            $this->log_level = isset($config['level']) ? $config['level'] : 'info';
        }

        public function log($level, $message, $context = array())
        {
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] {$level}: {$message}";

            if (!empty($context)) {
                $log_entry .= ' ' . json_encode($context);
            }

            $log_entry .= PHP_EOL;

            // 确保日志目录存在
            $log_dir = dirname($this->log_file);
            if (!is_dir($log_dir)) {
                todo_mkdir_p($log_dir);
            }

            file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }

        public function info($message, $context = array())
        {
            $this->log('INFO', $message, $context);
        }

        public function error($message, $context = array())
        {
            $this->log('ERROR', $message, $context);
        }

        public function warning($message, $context = array())
        {
            $this->log('WARNING', $message, $context);
        }

        public function debug($message, $context = array())
        {
            $this->log('DEBUG', $message, $context);
        }
    }
}

/**
 * 创建类构造函数 - Todo_Cache_Redis
 */
if (!function_exists('Todo_Cache_Redis')) {
    function Todo_Cache_Redis($config = array())
    {
        return new Todo_Cache_Redis_Class($config);
    }
}

/**
 * Todo_Cache_Redis 类
 */
if (!class_exists('Todo_Cache_Redis_Class')) {
    class Todo_Cache_Redis_Class
    {
        private $redis;
        private $connected = false;

        public function __construct($config = array())
        {
            $redisClass = '\\Redis';
            if (class_exists($redisClass)) {
                $this->redis = new $redisClass();
                $host = isset($config['host']) ? $config['host'] : '127.0.0.1';
                $port = isset($config['port']) ? $config['port'] : 6379;

                try {
                    $this->connected = $this->redis->connect($host, $port);
                    if (isset($config['password'])) {
                        $this->redis->auth($config['password']);
                    }
                } catch (Exception $e) {
                    $this->connected = false;
                }
            }
        }

        public function get($key)
        {
            if (!$this->connected)
                return false;
            return $this->redis->get($key);
        }

        public function set($key, $value, $ttl = 3600)
        {
            if (!$this->connected)
                return false;
            return $this->redis->setex($key, $ttl, $value);
        }

        public function delete($key)
        {
            if (!$this->connected)
                return false;
            return $this->redis->del($key);
        }

        public function flush()
        {
            if (!$this->connected)
                return false;
            return $this->redis->flushDB();
        }

        public function increment($key, $value = 1)
        {
            if (!$this->connected)
                return false;
            return $this->redis->incrBy($key, $value);
        }
    }
}

/**
 * 创建类构造函数 - Todo_Cache_File
 */
if (!function_exists('Todo_Cache_File')) {
    function Todo_Cache_File($config = array())
    {
        return new Todo_Cache_File_Class($config);
    }
}

/**
 * Todo_Cache_File 类
 */
if (!class_exists('Todo_Cache_File_Class')) {
    class Todo_Cache_File_Class
    {
        private $cache_dir;

        public function __construct($config = array())
        {
            $this->cache_dir = isset($config['dir']) ? $config['dir'] : TODO_CACHE_DIR . '/file';

            // 确保缓存目录存在
            if (!is_dir($this->cache_dir)) {
                todo_mkdir_p($this->cache_dir);
            }
        }

        public function get($key)
        {
            $file = $this->get_cache_file($key);

            if (!file_exists($file)) {
                return false;
            }

            $data = file_get_contents($file);
            $cache_data = unserialize($data);

            // 检查是否过期
            if ($cache_data['expires'] > 0 && $cache_data['expires'] < time()) {
                unlink($file);
                return false;
            }

            return $cache_data['data'];
        }

        public function set($key, $value, $ttl = 3600)
        {
            $file = $this->get_cache_file($key);
            $expires = $ttl > 0 ? time() + $ttl : 0;

            $cache_data = array(
                'data' => $value,
                'expires' => $expires
            );

            return file_put_contents($file, serialize($cache_data), LOCK_EX) !== false;
        }

        public function delete($key)
        {
            $file = $this->get_cache_file($key);

            if (file_exists($file)) {
                return unlink($file);
            }

            return true;
        }

        public function flush()
        {
            $files = glob($this->cache_dir . '/*.cache');

            foreach ($files as $file) {
                unlink($file);
            }

            return true;
        }

        private function get_cache_file($key)
        {
            $hash = md5($key);
            return $this->cache_dir . '/' . $hash . '.cache';
        }
    }
}/**
 * 创建类构造函数 - Todo_Cache_Memcached
 */
if (!function_exists('Todo_Cache_Memcached')) {
    function Todo_Cache_Memcached($config = array())
    {
        return new Todo_Cache_Memcached_Class($config);
    }
}

/**
 * Todo_Cache_Memcached 类
 */
if (!class_exists('Todo_Cache_Memcached_Class')) {
    class Todo_Cache_Memcached_Class
    {
        private $memcached;
        private $connected = false;

        public function __construct($config = array())
        {
            $memcachedClass = '\\Memcached';
            if (class_exists($memcachedClass)) {
                $this->memcached = new $memcachedClass();
                $host = isset($config['host']) ? $config['host'] : '127.0.0.1';
                $port = isset($config['port']) ? $config['port'] : 11211;

                try {
                    $this->connected = $this->memcached->addServer($host, $port);
                } catch (Exception $e) {
                    $this->connected = false;
                }
            }
        }

        public function get($key)
        {
            if (!$this->connected)
                return false;
            return $this->memcached->get($key);
        }

        public function set($key, $value, $ttl = 3600)
        {
            if (!$this->connected)
                return false;
            return $this->memcached->set($key, $value, $ttl);
        }

        public function delete($key)
        {
            if (!$this->connected)
                return false;
            return $this->memcached->delete($key);
        }

        public function flush()
        {
            if (!$this->connected)
                return false;
            return $this->memcached->flush();
        }

        public function increment($key, $value = 1)
        {
            if (!$this->connected)
                return false;
            return $this->memcached->increment($key, $value);
        }
    }
}

/**
 * 创建类构造函数 - Todo_Cache_Database
 */
if (!function_exists('Todo_Cache_Database')) {
    function Todo_Cache_Database($config = array())
    {
        return new Todo_Cache_Database_Class($config);
    }
}

/**
 * Todo_Cache_Database 类
 */
if (!class_exists('Todo_Cache_Database_Class')) {
    class Todo_Cache_Database_Class
    {
        private $db;
        private $table_name;

        public function __construct($config = array())
        {
            global $tododb;
            $this->db = $tododb;
            $this->table_name = isset($config['table']) ? $config['table'] : 'todo_cache';
        }

        public function get($key)
        {
            if (!$this->db)
                return false;

            $sql = "SELECT cache_value FROM {$this->table_name} 
                    WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())";

            try {
                $result = $this->db->get_row($sql, [$key]);

                if ($result) {
                    return unserialize($result->cache_value);
                }
            } catch (Exception $e) {
                error_log("Cache get error: " . $e->getMessage());
            }

            return false;
        }

        public function set($key, $value, $ttl = 3600)
        {
            if (!$this->db)
                return false;

            $expires_at = $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null;
            $serialized_value = serialize($value);

            $sql = "INSERT INTO {$this->table_name} (cache_key, cache_value, expires_at) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE cache_value = ?, expires_at = ?";

            try {
                return Todo_DB_Prepared::query($sql, [$key, $serialized_value, $expires_at, $serialized_value, $expires_at]);
            } catch (Exception $e) {
                error_log("Cache set error: " . $e->getMessage());
                return false;
            }
        }

        public function delete($key)
        {
            if (!$this->db)
                return false;

            $sql = "DELETE FROM {$this->table_name} WHERE cache_key = ?";

            try {
                return Todo_DB_Prepared::query($sql, [$key]);
            } catch (Exception $e) {
                error_log("Cache delete error: " . $e->getMessage());
                return false;
            }
        }

        public function flush()
        {
            if (!$this->db)
                return false;

            $sql = "DELETE FROM {$this->table_name}";

            try {
                return $this->db->query($sql);
            } catch (Exception $e) {
                error_log("Cache flush error: " . $e->getMessage());
                return false;
            }
        }

        public function cleanup()
        {
            if (!$this->db)
                return false;

            $sql = "DELETE FROM {$this->table_name} WHERE expires_at IS NOT NULL AND expires_at < NOW()";

            try {
                return $this->db->query($sql);
            } catch (Exception $e) {
                error_log("Cache cleanup error: " . $e->getMessage());
                return false;
            }
        }
    }
}

/**
 * 创建类构造函数 - Todo_Cart_Manager
 */
if (!function_exists('Todo_Cart_Manager')) {
    function Todo_Cart_Manager($config = array())
    {
        return new Todo_Cart_Manager_Class($config);
    }
}

/**
 * Todo_Cart_Manager 类
 */
if (!class_exists('Todo_Cart_Manager_Class')) {
    class Todo_Cart_Manager_Class
    {
        private $session_key = 'todo_cart';
        private $user_id;

        public function __construct($config = array())
        {
            $this->user_id = todo_get_current_user_id();
            $this->session_key = isset($config['session_key']) ? $config['session_key'] : 'todo_cart';

            // 启动会话
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
        }

        public function add_item($product_id, $quantity = 1, $variation = array())
        {
            $cart = $this->get_cart();
            $item_key = $this->generate_item_key($product_id, $variation);

            if (isset($cart[$item_key])) {
                $cart[$item_key]['quantity'] += $quantity;
            } else {
                $cart[$item_key] = array(
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'variation' => $variation,
                    'added_at' => time()
                );
            }

            $this->save_cart($cart);
            return true;
        }

        public function remove_item($item_key)
        {
            $cart = $this->get_cart();

            if (isset($cart[$item_key])) {
                unset($cart[$item_key]);
                $this->save_cart($cart);
                return true;
            }

            return false;
        }

        public function update_quantity($item_key, $quantity)
        {
            $cart = $this->get_cart();

            if (isset($cart[$item_key])) {
                if ($quantity <= 0) {
                    unset($cart[$item_key]);
                } else {
                    $cart[$item_key]['quantity'] = $quantity;
                }
                $this->save_cart($cart);
                return true;
            }

            return false;
        }

        public function get_cart()
        {
            return isset($_SESSION[$this->session_key]) ? $_SESSION[$this->session_key] : array();
        }

        public function get_cart_count()
        {
            $cart = $this->get_cart();
            $count = 0;

            foreach ($cart as $item) {
                $count += $item['quantity'];
            }

            return $count;
        }

        public function get_cart_total()
        {
            $cart = $this->get_cart();
            $total = 0;

            foreach ($cart as $item) {
                $product = get_post($item['product_id']);
                if ($product) {
                    $price = get_post_meta($item['product_id'], 'product_price', true);
                    $total += floatval($price) * $item['quantity'];
                }
            }

            return $total;
        }

        public function clear_cart()
        {
            $_SESSION[$this->session_key] = array();
            return true;
        }

        private function save_cart($cart)
        {
            $_SESSION[$this->session_key] = $cart;
        }

        private function generate_item_key($product_id, $variation = array())
        {
            $key_data = array('product_id' => $product_id);

            if (!empty($variation)) {
                ksort($variation);
                $key_data['variation'] = $variation;
            }

            return md5(serialize($key_data));
        }
    }
}

/**
 * 创建类构造函数 - PostFieldsManager
 */
if (!function_exists('PostFieldsManager')) {
    function PostFieldsManager($post_id = null)
    {
        return new PostFieldsManager_Class($post_id);
    }
}

/**
 * PostFieldsManager 类
 */
if (!class_exists('PostFieldsManager_Class')) {
    class PostFieldsManager_Class
    {
        private $post_id;
        private $fields_cache = array();

        public function __construct($post_id = null)
        {
            $this->post_id = $post_id;
        }

        public function set_post_id($post_id)
        {
            $this->post_id = $post_id;
            $this->fields_cache = array(); // 清空缓存
        }

        public function get_field($field_name, $default = '')
        {
            if (!$this->post_id)
                return $default;

            if (!isset($this->fields_cache[$field_name])) {
                $this->fields_cache[$field_name] = get_post_meta($this->post_id, $field_name, true);
            }

            return $this->fields_cache[$field_name] ? $this->fields_cache[$field_name] : $default;
        }

        public function update_field($field_name, $value)
        {
            if (!$this->post_id)
                return false;

            $result = update_post_meta($this->post_id, $field_name, $value);

            // 更新缓存
            $this->fields_cache[$field_name] = $value;

            return $result;
        }

        public function delete_field($field_name)
        {
            if (!$this->post_id)
                return false;

            $result = delete_post_meta($this->post_id, $field_name);

            // 清除缓存
            unset($this->fields_cache[$field_name]);

            return $result;
        }

        public function get_all_fields()
        {
            if (!$this->post_id)
                return array();

            return get_post_meta($this->post_id);
        }

        public function get_custom_fields()
        {
            if (!$this->post_id)
                return array();

            // 使用 Post Fields Manager
            $fields_manager = Todo_Post_Fields_Manager::get_instance();
            
            // 获取文章的模型ID进行智能判断
            $model_id = $fields_manager->get_post_model_id($this->post_id);

            $all_fields = $this->get_all_fields();
            $custom_fields = array();

            foreach ($all_fields as $key => $value) {
                // 使用智能判断过滤自定义字段
                if ($fields_manager->is_custom_field($key, $model_id)) {
                    $custom_fields[$key] = is_array($value) && count($value) == 1 ? $value[0] : $value;
                }
            }

            return $custom_fields;
        }

        public function validate_field($field_name, $value, $field_type = 'text')
        {
            switch ($field_type) {
                case 'email':
                    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                case 'url':
                    return filter_var($value, FILTER_VALIDATE_URL) !== false;
                case 'number':
                    return is_numeric($value);
                case 'integer':
                    return filter_var($value, FILTER_VALIDATE_INT) !== false;
                case 'boolean':
                    return in_array($value, array(true, false, 1, 0, '1', '0', 'true', 'false'));
                default:
                    return true; // 文本字段默认通过验证
            }
        }

        public function sanitize_field($value, $field_type = 'text')
        {
            switch ($field_type) {
                case 'email':
                    return sanitize_email($value);
                case 'url':
                    return esc_url_raw($value);
                case 'textarea':
                    return sanitize_textarea_field($value);
                case 'html':
                    return todo_kses_post($value);
                case 'number':
                case 'integer':
                    return floatval($value);
                case 'boolean':
                    return (bool) $value;
                default:
                    return sanitize_text_field($value);
            }
        }
    }
}
/**
 * 创建类构造函数 - Todo_Lazy_Object
 */
if (!function_exists('Todo_Lazy_Object')) {
    function Todo_Lazy_Object($class_name, $args = array())
    {
        return new Todo_Lazy_Object_Class($class_name, $args);
    }
}

/**
 * Todo_Lazy_Object 类
 */
if (!class_exists('Todo_Lazy_Object_Class')) {
    class Todo_Lazy_Object_Class
    {
        private $class_name;
        private $args;
        private $instance;

        public function __construct($class_name, $args = array())
        {
            $this->class_name = $class_name;
            $this->args = $args;
            $this->instance = null;
        }

        public function getInstance()
        {
            if ($this->instance === null) {
                if (class_exists($this->class_name)) {
                    $reflection = new ReflectionClass($this->class_name);
                    $this->instance = $reflection->newInstanceArgs($this->args);
                } else {
                    throw new Exception("Class {$this->class_name} not found");
                }
            }
            return $this->instance;
        }

        public function __call($method, $args)
        {
            return call_user_func_array(array($this->getInstance(), $method), $args);
        }
    }
}

/**
 * 创建类构造函数 - TODO_Screen
 */
if (!function_exists('TODO_Screen')) {
    function TODO_Screen($screen_id = '')
    {
        return new TODO_Screen_Class($screen_id);
    }
}

/**
 * TODO_Screen 类
 */
if (!class_exists('TODO_Screen_Class')) {
    class TODO_Screen_Class
    {
        private $screen_id;
        private $columns;
        private $meta_boxes;

        public function __construct($screen_id = '')
        {
            $this->screen_id = $screen_id;
            $this->columns = 2;
            $this->meta_boxes = array();
        }

        public function get_columns()
        {
            return $this->columns;
        }

        public function set_columns($columns)
        {
            $this->columns = intval($columns);
        }

        public function add_meta_box($id, $title, $callback, $context = 'normal', $priority = 'default')
        {
            $this->meta_boxes[$id] = array(
                'title' => $title,
                'callback' => $callback,
                'context' => $context,
                'priority' => $priority
            );
        }

        public function get_meta_boxes()
        {
            return $this->meta_boxes;
        }
    }
}

/**
 * 创建类构造函数 - Todo_Order_Statistics
 */
if (!function_exists('Todo_Order_Statistics')) {
    function Todo_Order_Statistics()
    {
        return new Todo_Order_Statistics_Class();
    }
}

/**
 * Todo_Order_Statistics 类
 */
if (!class_exists('Todo_Order_Statistics_Class')) {
    class Todo_Order_Statistics_Class
    {
        private $db;

        public function __construct()
        {
            global $tododb;
            $this->db = $tododb;
        }

        public function get_total_orders($start_date = '', $end_date = '')
        {
            $where = "WHERE status != 'deleted'";
            if ($start_date && $end_date) {
                $where .= " AND created_at BETWEEN '$start_date' AND '$end_date'";
            }

            $sql = "SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "orders $where";
            $result = $this->db->query($sql);
            if ($result) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                return $row ? $row['total'] : 0;
            }
            return 0;
        }

        public function get_total_revenue($start_date = '', $end_date = '')
        {
            $where = "WHERE status = 'completed'";
            if ($start_date && $end_date) {
                $where .= " AND created_at BETWEEN '$start_date' AND '$end_date'";
            }

            $sql = "SELECT SUM(total_amount) as revenue FROM " . TABLE_PREFIX . "orders $where";
            $result = $this->db->query($sql);
            if ($result) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                return $row ? floatval($row['revenue']) : 0;
            }
            return 0;
        }

        public function get_orders_by_status()
        {
            $sql = "SELECT status, COUNT(*) as count FROM " . TABLE_PREFIX . "orders GROUP BY status";
            $result = $this->db->query($sql);
            $stats = array();

            if ($result) {
                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    $stats[$row['status']] = $row['count'];
                }
            }

            return $stats;
        }
    }
}

/**
 * 创建类构造函数 - Todo_Category_Manager
 */
if (!function_exists('Todo_Category_Manager')) {
    function Todo_Category_Manager()
    {
        return new Todo_Category_Manager_Class();
    }
}

/**
 * Todo_Category_Manager 类
 */
if (!class_exists('Todo_Category_Manager_Class')) {
    class Todo_Category_Manager_Class
    {
        private $db;

        public function __construct()
        {
            global $tododb;
            $this->db = $tododb;
        }

        public function get_categories($parent_id = 0, $model_id = 0)
        {
            $where = "WHERE parent_id = " . intval($parent_id);
            if ($model_id > 0) {
                $where .= " AND model_id = " . intval($model_id);
            }

            $sql = "SELECT * FROM " . TABLE_PREFIX . "categories $where ORDER BY sort_order ASC, id ASC";
            $result = $this->db->query($sql);
            $categories = array();

            if ($result) {
                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    $categories[] = $row;
                }
            }

            return $categories;
        }

        public function get_category_tree($model_id = 0)
        {
            $categories = $this->get_categories(0, $model_id);
            foreach ($categories as &$category) {
                $category['children'] = $this->get_category_children($category['id'], $model_id);
            }
            return $categories;
        }

        private function get_category_children($parent_id, $model_id = 0)
        {
            $children = $this->get_categories($parent_id, $model_id);
            foreach ($children as &$child) {
                $child['children'] = $this->get_category_children($child['id'], $model_id);
            }
            return $children;
        }

        public function create_category($data)
        {
            $required_fields = array('name', 'model_id');
            foreach ($required_fields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return false;
                }
            }

            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');

            $fields = implode(',', array_keys($data));
            $values = "'" . implode("','", array_values($data)) . "'";

            $sql = "INSERT INTO " . TABLE_PREFIX . "categories ($fields) VALUES ($values)";
            return $this->db->query($sql);
        }
    }
}

/**
 * 创建类构造函数 - TodoCMS_Media_Handler
 */
if (!function_exists('TodoCMS_Media_Handler')) {
    function TodoCMS_Media_Handler()
    {
        return new TodoCMS_Media_Handler_Class();
    }
}

/**
 * TodoCMS_Media_Handler 类
 */
if (!class_exists('TodoCMS_Media_Handler_Class')) {
    class TodoCMS_Media_Handler_Class
    {
        private $upload_dir;
        private $allowed_types;
        private $max_file_size;

        public function __construct()
        {
            $this->upload_dir = (defined('TODO_CONTENT_DIR') ? TODO_CONTENT_DIR . '/uploads' : (defined('UPLOAD_DIR') ? UPLOAD_DIR : 'content/uploads'));
            $this->allowed_types = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'zip');
            $this->max_file_size = 10 * 1024 * 1024;
            if (class_exists('TodoUploadConfig')) {
                $this->max_file_size = (int) TodoUploadConfig::get_max_upload_size();
            } elseif (function_exists('get_option')) {
                $mb = get_option('max_upload_size', null);
                if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                    $this->max_file_size = (int) $mb * 1024 * 1024;
                }
            }
        }

        public function upload_file($file, $options = array())
        {
            if (function_exists('get_option') && !get_option('enable_upload', true)) {
                return array('error' => '文件上传功能已被管理员禁用');
            }
            if ((int) $this->max_file_size <= 0) {
                return array('error' => '文件上传功能已被管理员禁用');
            }
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                return array('error' => '无效的文件上传');
            }

            // 检查文件大小
            if ($file['size'] > $this->max_file_size) {
                return array('error' => '文件大小超过限制');
            }

            // 检查文件类型
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $this->allowed_types)) {
                return array('error' => '不支持的文件类型');
            }

            // 生成唯一文件名
            $new_filename = uniqid() . '.' . $file_ext;
            $upload_path = $this->upload_dir . '/' . date('Y/m');

            // 创建目录
            if (!file_exists($upload_path)) {
                mkdir($upload_path, 0755, true);
            }
            // realpath 前缀校验，确保上传路径位于 /content/uploads 下
            $uploads_base = rtrim((defined('ABSPATH') ? ABSPATH : (defined('TODO_ROOT') ? TODO_ROOT : '/')), '/') . '/content/uploads';
            $base_real = realpath($uploads_base);
            $dir_real = realpath($upload_path);
            if ($base_real === false || $dir_real === false || strpos($dir_real, $base_real) !== 0) {
                return array('error' => '非法上传目录');
            }

            $full_path = $upload_path . '/' . $new_filename;
            // 再次校验目标父目录
            $dest_parent_real = realpath(dirname($full_path));
            if ($dest_parent_real === false || strpos($dest_parent_real, $base_real) !== 0) {
                return array('error' => '非法目标路径');
            }

            // 移动文件
            if (move_uploaded_file($file['tmp_name'], $full_path)) {
                @chmod($full_path, 0644);
                $rel_start = strpos(str_replace('\\', '/', $full_path), '/content/uploads');
                $rel_url = $rel_start !== false ? substr(str_replace('\\', '/', $full_path), $rel_start) : '/content/uploads/' . date('Y/m') . '/' . $new_filename;
                return array(
                    'success' => true,
                    'file_path' => $full_path,
                    'file_url' => rtrim(todo_get_site_url(), '/') . $rel_url,
                    'file_name' => $new_filename,
                    'original_name' => $file['name'],
                    'file_size' => $file['size'],
                    'file_type' => $file_ext
                );
            } else {
                return array('error' => '文件上传失败');
            }
        }

        public function delete_file($file_path)
        {
            if (file_exists($file_path)) {
                return unlink($file_path);
            }
            return false;
        }

        public function get_file_info($file_path)
        {
            if (!file_exists($file_path)) {
                return false;
            }

            return array(
                'size' => filesize($file_path),
                'modified' => filemtime($file_path),
                'type' => mime_content_type($file_path),
                'extension' => pathinfo($file_path, PATHINFO_EXTENSION)
            );
        }
    }
}

// ============================================================================
// 数据库版本管理函数
// ============================================================================

/**
 * 获取数据库版本
 */
if (!function_exists('get_db_version')) {
    function get_db_version()
    {
        // 使用CMS_Option类获取数据库版本
        if (class_exists('CMS_Option')) {
            return CMS_Option::get('db_version', '1.0.0');
        }

        // 如果CMS_Option类不存在，尝试直接从system_config表获取
        global $tododb;

        try {
            $result = $tododb->get_row("SELECT config_value FROM " . TABLE_PREFIX . "system_config WHERE config_key = 'db_version'");

            return $result ? $result->config_value : '1.0.0';
        } catch (Exception $e) {
            todo_log_action('get_db_version_error', array('error' => $e->getMessage()));
            return '1.0.0';
        }
    }
}

/**
 * 检查是否需要数据库更新
 */
if (!function_exists('needs_db_update')) {
    function needs_db_update()
    {
        $current_version = get_db_version();
        $required_version = defined('TODO_DB_VERSION') ? constant('TODO_DB_VERSION') : '1.0.0';

        return version_compare($current_version, $required_version, '<');
    }
}

/**
 * 更新数据库版本
 */
if (!function_exists('update_db_version')) {
    function update_db_version($version)
    {
        // 使用CMS_Option类更新数据库版本
        if (class_exists('CMS_Option')) {
            $result = CMS_Option::update('db_version', $version);
            if ($result) {
                todo_log_action('db_version_updated', array('version' => $version));
            } else {
                todo_log_action('update_db_version_error', array('version' => $version));
            }
            return $result;
        }

        // 如果CMS_Option类不存在，尝试直接更新system_config表
        global $tododb;

        try {
            tododb_query(
                "
                INSERT INTO " . TABLE_PREFIX . "system_config (config_key, config_value) 
                VALUES ('db_version', ?) 
                ON DUPLICATE KEY UPDATE config_value = ?
            ", [$version, $version]);

            todo_log_action('db_version_updated', array('version' => $version));
            return true;
        } catch (Exception $e) {
            todo_log_action('update_db_version_error', array('error' => $e->getMessage()));
            return false;
        }
    }
}

// ============================================================================
// 管理器类构造函数
// ============================================================================

/**
 * 创建类构造函数 - TodoUpgradePackageManager
 */
if (!function_exists('TodoUpgradePackageManager')) {
    function TodoUpgradePackageManager($config = array())
    {
        return new TodoUpgradePackageManager_Class($config);
    }
}

/**
 * TodoUpgradePackageManager 类
 */
if (!class_exists('TodoUpgradePackageManager_Class')) {
    class TodoUpgradePackageManager_Class
    {
        private $package_dir;
        private $backup_dir;
        private $temp_dir;

        public function __construct($config = array())
        {
            $this->package_dir = isset($config['package_dir']) ? $config['package_dir'] : TODO_CONTENT_DIR . '/packages';
            $this->backup_dir = isset($config['backup_dir']) ? $config['backup_dir'] : TODO_CONTENT_DIR . '/backups';
            $this->temp_dir = isset($config['temp_dir']) ? $config['temp_dir'] : TODO_CONTENT_DIR . '/temp';
        }

        public function install_package($package_file)
        {
            // 安装升级包逻辑
            todo_log_action('package_install_start', array('package' => $package_file));

            try {
                // 创建备份
                $this->create_backup();

                // 解压升级包
                $extracted_path = $this->extract_package($package_file);

                // 应用升级
                $this->apply_upgrade($extracted_path);

                todo_log_action('package_install_success', array('package' => $package_file));
                return true;
            } catch (Exception $e) {
                todo_log_action('package_install_error', array('error' => $e->getMessage()));
                return false;
            }
        }

        private function create_backup()
        {
            // 创建系统备份
            if (!is_dir($this->backup_dir)) {
                todo_mkdir_p($this->backup_dir);
            }

            $backup_name = 'backup_' . date('Y-m-d_H-i-s');
            $backup_path = $this->backup_dir . '/' . $backup_name;

            // 备份核心文件
            todo_copy_directory((defined('TODO_ROOT') ? TODO_ROOT : ABSPATH), $backup_path);

            return $backup_path;
        }

        private function extract_package($package_file)
        {
            // 解压升级包
            $extract_path = $this->temp_dir . '/upgrade_' . time();

            if (!is_dir($extract_path)) {
                todo_mkdir_p($extract_path);
            }

            // 使用ZipArchive解压（安全过滤）
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                if ($zip->open($package_file) === TRUE) {
                    // 安全解压：过滤路径穿越与绝对路径
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
                    // 移除符号链接以防绕过
                    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extract_path, FilesystemIterator::SKIP_DOTS));
                    foreach ($iter as $info) {
                        $p = $info->getPathname();
                        if (is_link($p)) {
                            @unlink($p);
                        }
                    }
                    return $extract_path;
                }
            }

            throw new Exception('无法解压升级包');
        }

        private function apply_upgrade($source_path)
        {
            // 应用升级文件
            todo_copy_directory($source_path, (defined('TODO_ROOT') ? TODO_ROOT : ABSPATH));

            // 清理临时文件
            todo_remove_directory($source_path);
        }
    }
}

/**
 * 创建类构造函数 - TodoUserLevelManager
 */
if (!function_exists('TodoUserLevelManager')) {
    function TodoUserLevelManager($config = array())
    {
        return new TodoUserLevelManager_Class($config);
    }
}

/**
 * TodoUserLevelManager 类
 */
if (!class_exists('TodoUserLevelManager_Class')) {
    class TodoUserLevelManager_Class
    {
        private $levels;
        private $default_level;

        public function __construct($config = array())
        {
            $this->default_level = isset($config['default_level']) ? $config['default_level'] : 'member';
            $this->load_user_levels();
        }

        private function load_user_levels()
        {
            // 加载用户等级配置
            $this->levels = array(
                'guest' => array(
                    'name' => '游客',
                    'permissions' => array('read'),
                    'discount' => 0
                ),
                'member' => array(
                    'name' => '会员',
                    'permissions' => array('read', 'comment'),
                    'discount' => 5
                ),
                'vip' => array(
                    'name' => 'VIP会员',
                    'permissions' => array('read', 'comment', 'download'),
                    'discount' => 10
                ),
                'premium' => array(
                    'name' => '高级会员',
                    'permissions' => array('read', 'comment', 'download', 'upload'),
                    'discount' => 15
                ),
                'admin' => array(
                    'name' => '管理员',
                    'permissions' => array('all'),
                    'discount' => 20
                )
            );
        }

        public function get_user_level($user_id)
        {
            global $tododb;

            try {
                $result = $tododb->get_row("SELECT user_level FROM " . TABLE_PREFIX . "users WHERE id = ?", [$user_id]);

                return $result ? $result->user_level : $this->default_level;
            } catch (Exception $e) {
                todo_log_action('get_user_level_error', array('error' => $e->getMessage()));
                return $this->default_level;
            }
        }

        public function set_user_level($user_id, $level)
        {
            global $tododb;

            if (!isset($this->levels[$level])) {
                return false;
            }

            try {
                $tododb->update(TABLE_PREFIX . 'users', ['user_level' => $level], ['id' => $user_id]);

                todo_log_action('user_level_updated', array('user_id' => $user_id, 'level' => $level));
                return true;
            } catch (Exception $e) {
                todo_log_action('set_user_level_error', array('error' => $e->getMessage()));
                return false;
            }
        }

        public function get_level_permissions($level)
        {
            return isset($this->levels[$level]) ? $this->levels[$level]['permissions'] : array();
        }

        public function get_level_discount($level)
        {
            return isset($this->levels[$level]) ? $this->levels[$level]['discount'] : 0;
        }

        public function user_has_permission($user_id, $permission)
        {
            $level = $this->get_user_level($user_id);
            $permissions = $this->get_level_permissions($level);

            return in_array('all', $permissions) || in_array($permission, $permissions);
        }
    }
}

// ============================================================================
// 业务逻辑函数
// ============================================================================

/**
 * 记录成功日志
 */
if (!function_exists('log_success')) {
    function log_success($message, $context = array())
    {
        $logger = Todo_Logger();
        $logger->info($message, $context);

        // 同时记录到系统日志
        todo_log_action('success', array('message' => $message, 'context' => $context));
    }
}

/**
 * 获取文件大小（格式化）
 */
if (!function_exists('get_filesize')) {
    function get_filesize($file_path, $format = true)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $size = filesize($file_path);

        if (!$format) {
            return $size;
        }

        // 格式化文件大小
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $unit_index = 0;

        while ($size >= 1024 && $unit_index < count($units) - 1) {
            $size /= 1024;
            $unit_index++;
        }

        return round($size, 2) . ' ' . $units[$unit_index];
    }
}

/**
 * 更新字段值
 */
if (!function_exists('todoUpdateFieldValue')) {
    function todoUpdateFieldValue($post_id, $field_name, $field_value)
    {
        global $tododb;

        try {
            // 检查字段是否存在
            $existing = $tododb->get_row("
                SELECT meta_id FROM {$tododb->prefix}postmeta 
                WHERE post_id = ? AND meta_key = ?
            ", [$post_id, $field_name]);

            if ($existing) {
                // 更新现有字段
                $tododb->update(
                    $tododb->prefix . 'postmeta',
                    ['meta_value' => $field_value],
                    ['post_id' => $post_id, 'meta_key' => $field_name]
                );
            } else {
                // 插入新字段
                $tododb->insert($tododb->prefix . 'postmeta', [
                    'post_id' => $post_id,
                    'meta_key' => $field_name,
                    'meta_value' => $field_value
                ]);
            }

            todo_log_action('field_value_updated', array(
                'post_id' => $post_id,
                'field_name' => $field_name,
                'field_value' => $field_value
            ));

            return true;
        } catch (Exception $e) {
            todo_log_action('update_field_value_error', array('error' => $e->getMessage()));
            return false;
        }
    }
}

/**
 * 删除字段文件
 */
if (!function_exists('todoRemoveFieldFile')) {
    function todoRemoveFieldFile($post_id, $field_name, $file_path = null)
    {
        global $tododb;

        try {
            // 如果没有提供文件路径，从数据库获取
            if (!$file_path) {
                $result = $tododb->get_row("
                    SELECT meta_value FROM {$tododb->prefix}postmeta 
                    WHERE post_id = ? AND meta_key = ?
                ", [$post_id, $field_name]);

                if (!$result) {
                    return false;
                }

                $file_path = $result->meta_value;
            }

            // 删除物理文件
            if (file_exists($file_path)) {
                unlink($file_path);
            }

            // 删除数据库记录
            $tododb->delete($tododb->prefix . 'postmeta', [
                'post_id' => $post_id,
                'meta_key' => $field_name
            ]);

            todo_log_action('field_file_removed', array(
                'post_id' => $post_id,
                'field_name' => $field_name,
                'file_path' => $file_path
            ));

            return true;
        } catch (Exception $e) {
            todo_log_action('remove_field_file_error', array('error' => $e->getMessage()));
            return false;
        }
    }
}

// ============================================================================
// 辅助工具函数
// ============================================================================

/**
 * 复制目录
 */
if (!function_exists('todo_copy_directory')) {
    function todo_copy_directory($source, $destination)
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            todo_mkdir_p($destination);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $srcNorm = str_replace('\\', '/', rtrim($source, '/'));
            $itemPath = str_replace('\\', '/', $item->getPathname());
            $rel = ltrim(substr($itemPath, strlen($srcNorm)), '/');
            $target = $destination . DIRECTORY_SEPARATOR . $rel;

            if ($item->isDir()) {
                todo_mkdir_p($target);
            } else {
                copy($item, $target);
            }
        }

        return true;
    }
}

/**
 * 删除目录
 */
if (!function_exists('todo_remove_directory')) {
    function todo_remove_directory($directory)
    {
        if (!is_dir($directory)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($directory);
        return true;
    }
}

// ============================================================================
// TodoCMS 兼容函数
// ============================================================================

/**
 * 判断路径是否为流
 */
if (!function_exists('todo_is_stream')) {
    function todo_is_stream($path)
    {
        $scheme_separator = strpos($path, '://');

        if (false === $scheme_separator) {
            return false;
        }

        $stream = substr($path, 0, $scheme_separator);

        return in_array($stream, stream_get_wrappers(), true);
    }
}

/**
 * TodoCMS兼容：递归创建目录
 */
if (!function_exists('todo_mkdir_p')) {
    function todo_mkdir_p($target)
    {
        $wrapper = null;

        // Strip the protocol
        if (todo_is_stream($target)) {
            list($wrapper, $target) = explode('://', $target, 2);
        }

        // From php.net/mkdir user contributed notes
        $target = str_replace('//', '/', $target);

        // Put the wrapper back on the target
        if ($wrapper !== null) {
            $target = $wrapper . '://' . $target;
        }

        /*
         * Safe mode fails with a trailing slash under certain PHP versions.
         * Use rtrim() instead of untrailingslashit to avoid formatting.php dependency.
         */
        $target = rtrim($target, '/');
        if (empty($target)) {
            $target = '/';
        }

        if (file_exists($target)) {
            return @is_dir($target);
        }

        // We need to find the permissions of the parent folder that exists and inherit that.
        $target_parent = dirname($target);
        while ('.' != $target_parent && !is_dir($target_parent) && dirname($target_parent) !== $target_parent) {
            $target_parent = dirname($target_parent);
        }

        // Get the permission bits.
        if ($stat = @stat($target_parent)) {
            $dir_perms = $stat['mode'] & 0007777;
        } else {
            $dir_perms = 0755;
        }

        if (@mkdir($target, $dir_perms, true)) {
            /*
             * If a umask is set that modifies $dir_perms, we'll have to re-set
             * the $dir_perms correctly with chmod()
             */
            if (function_exists('chmod') && ($dir_perms & ~umask()) != $dir_perms) {
                $folder_parts = explode('/', trim($target, '/'));
                for ($i = 1, $c = count($folder_parts); $i <= $c; $i++) {
                    $dir = '/' . implode('/', array_slice($folder_parts, 0, $i));

                    // Skip directories that already existed (parent path)
                    if (strlen($dir) <= strlen($target_parent)) {
                        continue;
                    }

                    if (@is_dir($dir)) {
                        @chmod($dir, $dir_perms);
                    }
                }
            }

            return true;
        }

        return false;
    }
}

// ============================================================================
// TodoCMS 参数解析函数
// ============================================================================

/**
 * 解析参数数组，TodoCMS的参数解析函数
 */
function todo_parse_args($args, $defaults = array())
{
    if (is_object($args)) {
        $parsed_args = get_object_vars($args);
    } elseif (is_array($args)) {
        $parsed_args = &$args;
    } else {
        parse_str($args, $parsed_args);
    }

    if (is_array($defaults) && $defaults) {
        return array_merge($defaults, $parsed_args);
    }
    return $parsed_args;
}

/**
 * 安全化SQL排序字段
 */
function sanitize_sql_orderby($orderby)
{
    if (!$orderby) {
        return '';
    }

    // 移除危险字符
    $orderby = preg_replace('/[^a-zA-Z0-9_\-\s,.]/', '', $orderby);

    // 验证排序方向
    $orderby = preg_replace('/\b(ASC|DESC)\b/i', '$1', $orderby);

    return trim($orderby);
}

/**
 * 获取数据库列数据
 */
function get_col($query, $x = 0)
{
    global $tododb;

    if (!$tododb) {
        return array();
    }

    try {
        $results = $tododb->get_results($query);

        $column_values = array();
        foreach ($results as $row) {
            $row_array = (array) $row;
            $values = array_values($row_array);
            if (isset($values[$x])) {
                $column_values[] = $values[$x];
            }
        }

        return $column_values;
    } catch (Exception $e) {
        todo_log_error('get_col error: ' . $e->getMessage());
        return array();
    }
}

// ============================================================================
// 字符转换和国际化函数
// ============================================================================

/**
 * 字符音译转换（简化版）
 * 仅在函数不存在时定义
 */
if (!function_exists('transliterator_transliterate')) {
    function transliterator_transliterate($transliterator_id, $string, $start = null, $end = null)
    {
        // 简化的音译实现
        $transliterations = array(
            // 中文转拼音（简化）
            '中' => 'zhong',
            '文' => 'wen',
            '测' => 'ce',
            '试' => 'shi',
            // 可以根据需要扩展更多字符
        );

        $result = $string;
        foreach ($transliterations as $from => $to) {
            $result = str_replace($from, $to, $result);
        }

        // 如果指定了范围，只处理指定部分
        if ($start !== null && $end !== null) {
            $before = substr($string, 0, $start);
            $middle = substr($string, $start, $end - $start);
            $after = substr($string, $end);

            foreach ($transliterations as $from => $to) {
                $middle = str_replace($from, $to, $middle);
            }

            $result = $before . $middle . $after;
        }

        return $result;
    }
}

// ============================================================================
// 文件和媒体处理函数
// ============================================================================

/**
 * 获取文件大小（人类可读格式）
 */
function get_filesize($file_path, $format = true)
{
    if (!file_exists($file_path)) {
        return false;
    }

    $size = filesize($file_path);

    if (!$format) {
        return $size;
    }

    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $unit_index = 0;

    while ($size >= 1024 && $unit_index < count($units) - 1) {
        $size /= 1024;
        $unit_index++;
    }

    return round($size, 2) . ' ' . $units[$unit_index];
}

// ============================================================================
// 数据库扩展函数
// ============================================================================

/**
 * 获取数据库表的列信息
 */
function get_table_columns($table_name)
{
    global $tododb;

    if (!$tododb) {
        return array();
    }

    // Persistent cache using Todo_Cache_Manager
    $cache_key = 'table_columns_' . $table_name;
    if (class_exists('Todo_Cache_Manager')) {
        $cached = Todo_Cache_Manager::get($cache_key, 'database');
        if ($cached !== false) {
            return $cached;
        }
    }

    try {
        $results = $tododb->get_results("SHOW COLUMNS FROM `$table_name`");

        $columns = array();
        foreach ($results as $row) {
            $columns[] = $row->Field;
        }

        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, $columns, 300, 'database'); // 5 min cache
        }
        return $columns;
    } catch (Exception $e) {
        todo_log_error('get_table_columns error: ' . $e->getMessage());
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set($cache_key, array(), 60, 'database'); // Cache empty result for 1 min
        }
        return array();
    }
}

/**
 * 检查表是否存在
 */
function table_exists($table_name)
{
    static $checked_tables = [];
    if (isset($checked_tables[$table_name])) {
        return $checked_tables[$table_name];
    }
    
    // Persistent cache check
    if (class_exists('Todo_Cache_Manager')) {
        $cache_key = 'table_exists_' . md5($table_name);
        $cached = Todo_Cache_Manager::get($cache_key, 'database');
        if ($cached !== false) {
            $checked_tables[$table_name] = $cached;
            return $cached;
        }
    }

    // 优先使用预处理类，若未加载则尝试加载
    if (!class_exists('Todo_DB_Prepared')) {
        $prepared_path = ABSPATH . 'core/classes/class-db-prepared.php';
        if (file_exists($prepared_path)) {
            require_once $prepared_path;
        }
    }
    if (class_exists('Todo_DB_Prepared')) {
        $exists = Todo_DB_Prepared::table_exists($table_name);
        $checked_tables[$table_name] = $exists;
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::set('table_exists_' . md5($table_name), $exists, 86400, 'database');
        }
        return $exists;
    }

    // 备用方案：使用全局 PDO 连接（Extended_PDO）
    global $tododb;
    if (!$tododb instanceof PDO) {
        $tododb = get_database_connection();
        if (!$tododb instanceof PDO) {
            $checked_tables[$table_name] = false;
            return false;
        }
    }

    // SHOW TABLES LIKE 不支持预处理参数，使用直接查询
    $escaped_table = $tododb->quote($table_name);
    $sql = "SHOW TABLES LIKE $escaped_table";

    // 防御性重试：处理“2006 MySQL server has gone away”
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $stmt = $tododb->query($sql);
            if ($stmt instanceof PDOStatement) {
                // 通过fetchColumn判断是否有返回行，避免SELECT下rowCount不可靠
                $hasRow = $stmt->fetchColumn() !== false;
                $stmt->closeCursor();
                $checked_tables[$table_name] = $hasRow;
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set('table_exists_' . md5($table_name), $hasRow, 86400, 'database');
                }
                return $hasRow;
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $goneAway = (strpos($msg, 'server has gone away') !== false) || (strpos($msg, '2006') !== false);
            if ($goneAway && $attempt === 0) {
                // 重新建立连接后重试一次
                $tododb = get_database_connection();
                continue;
            }
            $checked_tables[$table_name] = false;
            return false;
        } catch (Exception $e) {
            $checked_tables[$table_name] = false;
            return false;
        }
    }

    $checked_tables[$table_name] = false;
    return false;
}

/**
 * 判断系统是否已安装（安全版）
 * - 配置文件存在
 * - 数据库连接可用
 * - 存在 system_config 表且 installed=1
 */
function todo_is_installed()
{
    static $is_installed = null;

    if ($is_installed !== null) {
        return $is_installed;
    }

    // 配置文件不存在，视为未安装
    if (!defined('ABSPATH') || !file_exists(ABSPATH . 'config.php')) {
        $is_installed = false;
        return false;
    }

    // 正在安装过程中
    if (function_exists('todo_installing') && todo_installing()) {
        $is_installed = false;
        return false;
    }

    // 优先获取统一的 PDO 连接（Extended_PDO），并准备 CMS_DB 作为后备
    global $tododb, $tododb;
    if (!$tododb instanceof PDO) {
        // 尝试初始化 Extended_PDO 连接
        if (function_exists('get_database_connection')) {
            $tododb = get_database_connection();
        }
    }
    // 如果 Extended_PDO 不可用，尝试使用 CMS_DB
    if (!$tododb instanceof PDO && !$tododb) {
        if (class_exists('CMS_DB')) {
            try {
                $tododb = new CMS_DB();
            } catch (Exception $e) {
                $tododb = null;
            }
        }
    }
    if (!$tododb instanceof PDO && !$tododb) {
        // 两种连接都不可用
        $is_installed = false;
        return false;
    }

    // 检查关键表是否存在
    $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (defined('DB_PREFIX') ? DB_PREFIX : 'todo_');
    $system_config_table = $prefix . 'system_config';

    // 优先使用 CMS_Option 内存缓存，避免重复 SQL 查询
    if (class_exists('CMS_Option')) {
        $opt_val = CMS_Option::get('installed', null);
        if ($opt_val !== null) {
            $is_installed = ($opt_val == '1' || $opt_val === true);
            return $is_installed;
        }
    }

    // 后备：直接尝试查询 installed 配置
    try {
        $val = null;
        if ($tododb instanceof PDO) {
            $stmt = $tododb->query("SELECT config_value FROM {$system_config_table} WHERE config_key = 'installed' LIMIT 1");
            if ($stmt) {
                $val = $stmt->fetchColumn();
                $stmt->closeCursor();
            }
        } elseif (is_object($tododb) && method_exists($tododb, 'get_var')) {
            $val = $tododb->get_var("SELECT config_value FROM {$system_config_table} WHERE config_key = 'installed' LIMIT 1");
        }

        if ($val !== null && $val !== false) {
            $is_installed = ($val == '1');
        } else {
            $is_installed = false;
        }
    } catch (Exception $e) {
        // 查询失败（可能是表不存在）
        $is_installed = false;
    }

    return $is_installed;
}

// 兼容函数：如果外部代码调用 is_cms_installed，则代理到 todo_is_installed
if (!function_exists('is_cms_installed')) {
    function is_cms_installed()
    {
        return todo_is_installed();
    }
}

// 全局守卫：系统未安装或数据库/关键表不可用时，立即退出，避免访问其它表
if (!function_exists('todo_require_installed_or_exit')) {
    function todo_require_installed_or_exit()
    {
        // 安装流程中放行
        if (function_exists('todo_installing') && todo_installing()) {
            return;
        }
        // 安装入口脚本放行（双保险）
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script) {
            $base = strtolower(basename($script));
            if ($base === 'install.php') {
                return;
            }
        }
        // 判断安装状态
        $installed = function_exists('todo_is_installed') ? todo_is_installed() : false;
        if (!$installed) {
            // 当根目录存在 install.php 则跳转安装（优先跳转，避免先发送503状态导致跳转失败）
            if (file_exists(ABSPATH . 'install.php')) {
                if (!headers_sent()) {
                    header('Location: ' . site_url('/install.php'), true, 302);
                    header('Content-Type: text/html; charset=utf-8');
                }
                exit;
            }

            // 未安装且无安装入口，返回503并输出提示
            if (!headers_sent()) {
                header('HTTP/1.1 503 Service Unavailable');
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'TodoCMS 未就绪：系统未安装或数据库/关键表不可用。请运行安装程序。';
            exit;
        }
    }
}

// ============================================================================
// 系统工具函数
// ============================================================================

/**
 * 记录成功日志
 */
function log_success($message, $context = array())
{
    if (function_exists('todo_log')) {
        todo_log('success', $message, $context);
    } else {
        error_log('[SUCCESS] ' . $message);
    }
}

/**
 * 获取系统信息
 */
function get_system_info()
{
    return array(
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'mysql_version' => get_mysql_version(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size')
    );
}

/**
 * 获取MySQL版本
 */
function get_mysql_version()
{
    global $tododb;

    if (!$tododb) {
        return 'Unknown';
    }

    try {
        $stmt = $tododb->query("SELECT VERSION()");
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 'Unknown';
    }
}

if (!function_exists('todo_get_mysql_version')) {
    function todo_get_mysql_version()
    {
        return get_mysql_version();
    }
}

// ============================================================================
// 缓存辅助函数
// ============================================================================

/**
 * 清理过期缓存
 */
function cleanup_expired_cache()
{
    $cache_dir = TODO_CONTENT_DIR . '/cache';

    if (!is_dir($cache_dir)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir)
    );

    $cleaned = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'cache') {
            // 检查文件修改时间，删除超过24小时的缓存
            if (time() - $file->getMTime() > 86400) {
                if (unlink($file->getPathname())) {
                    $cleaned++;
                }
            }
        }
    }

    return $cleaned;
}

/**
 * 获取缓存统计信息
 */
function get_cache_stats()
{
    $cache_dir = TODO_CONTENT_DIR . '/cache';

    if (!is_dir($cache_dir)) {
        return array(
            'total_files' => 0,
            'total_size' => 0,
            'oldest_file' => null,
            'newest_file' => null
        );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir)
    );

    $stats = array(
        'total_files' => 0,
        'total_size' => 0,
        'oldest_file' => null,
        'newest_file' => null
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $stats['total_files']++;
            $stats['total_size'] += $file->getSize();

            $mtime = $file->getMTime();
            if ($stats['oldest_file'] === null || $mtime < $stats['oldest_file']) {
                $stats['oldest_file'] = $mtime;
            }
            if ($stats['newest_file'] === null || $mtime > $stats['newest_file']) {
                $stats['newest_file'] = $mtime;
            }
        }
    }

    return $stats;
}

/**
 * 获取处理器类名
 * @param string $handler_type 处理器类型
 * @return string
 */
function handler_class($handler_type)
{
    $handlers = [
        'ajax' => 'Todo_Ajax_Handler',
        'media' => 'Todo_Media_Handler',
        'upload' => 'Todo_Upload_Handler',
        'order' => 'Todo_Order_Handler',
        'user' => 'Todo_User_Handler',
        'comment' => 'Todo_Comment_Handler',
        'plugin' => 'Todo_Plugin_Handler',
        'theme' => 'Todo_Theme_Handler',
        'cache' => 'Todo_Cache_Handler',
        'security' => 'Todo_Security_Handler'
    ];

    return isset($handlers[$handler_type]) ? $handlers[$handler_type] : 'Todo_Base_Handler';
}

/**
 * 获取系统处理器实例
 * @param string $type 处理器类型
 * @return object|null
 */
function get_handler_instance($type)
{
    static $handlers = [];

    if (isset($handlers[$type])) {
        return $handlers[$type];
    }

    $class_name = handler_class($type);

    if (class_exists($class_name)) {
        $handlers[$type] = new $class_name();
        return $handlers[$type];
    }

    return null;
}

/**
 * 检查处理器是否可用
 * @param string $type 处理器类型
 * @return bool
 */
function is_handler_available($type)
{
    $class_name = handler_class($type);
    return class_exists($class_name);
}

function todo_get_fragment_cache_ttl()
{
    $mode = (function_exists('todo_get_option') ? todo_get_option('page_mode', 'dynamic') : (function_exists('get_option') ? get_option('page_mode', 'dynamic') : 'dynamic'));
    if ($mode === 'dynamic') {
        $ttl = (function_exists('todo_get_option') ? (int) todo_get_option('dynamic_template_cache_ttl', 120) : (function_exists('get_option') ? (int) get_option('dynamic_template_cache_ttl', 120) : 120));
        if ($ttl === 0) {
            return 0;
        }
        if ($ttl < 30) {
            return 30;
        }
        if ($ttl > 3600) {
            return 3600;
        }
        return $ttl;
    }
    return 31536000;
}

function todo_should_fragment_cache()
{
    $mode = (function_exists('todo_get_option') ? todo_get_option('page_mode', 'dynamic') : (function_exists('get_option') ? get_option('page_mode', 'dynamic') : 'dynamic'));
    if ($mode === 'dynamic') {
        return todo_get_fragment_cache_ttl() !== 0;
    }
    return true;
}

// ==========================================
// 系统完整性验证函数
// ==========================================

/**
 * 验证系统完整性
 * @return array
 */
function todo_verify_system_integrity()
{
    $results = [
        'status' => 'success',
        'message' => 'TodoCMS系统完整性验证通过',
        'details' => [],
        'stats' => []
    ];

    // 检查核心文件
    $core_files = [
        'core/bootstrap.php',
        'core/functions/system-functions-extended.php',
        'core/classes/class-cms-db.php',
        'admin/admin.php',
        'index.php'
    ];

    $missing_files = [];
    foreach ($core_files as $file) {
        $rootBase = rtrim((defined('TODO_ROOT') ? TODO_ROOT : ABSPATH), '/');
        if (!file_exists($rootBase . '/' . $file)) {
            $missing_files[] = $file;
        }
    }

    if (!empty($missing_files)) {
        $results['status'] = 'warning';
        $results['details']['missing_files'] = $missing_files;
    }

    // 检查数据库连接
    global $tododb;
    try {
        $tododb->query("SELECT 1");
        $results['details']['database'] = 'connected';
    } catch (Exception $e) {
        $results['status'] = 'error';
        $results['details']['database'] = 'connection_failed';
        $results['message'] = '数据库连接失败';
    }

    // 统计函数数量
    $defined_functions = get_defined_functions();
    $user_functions = array_filter($defined_functions['user'], function ($func) {
        return strpos($func, 'todo') === 0 || strpos($func, 'Todo') === 0;
    });

    $results['stats'] = [
        'total_user_functions' => count($defined_functions['user']),
        'todo_functions' => count($user_functions),
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ];

    return $results;
}

/**
 * 生成系统状态报告
 * @return string
 */
function todo_generate_status_report()
{
    $integrity = todo_verify_system_integrity();

    $report = "# TodoCMS 系统状态报告\n\n";
    $report .= "生成时间: " . date('Y-m-d H:i:s') . "\n\n";

    $report .= "## 系统状态\n";
    $report .= "状态: " . $integrity['status'] . "\n";
    $report .= "消息: " . $integrity['message'] . "\n\n";

    if (!empty($integrity['stats'])) {
        $report .= "## 统计信息\n";
        foreach ($integrity['stats'] as $key => $value) {
            $report .= "- " . $key . ": " . $value . "\n";
        }
        $report .= "\n";
    }

    if (!empty($integrity['details'])) {
        $report .= "## 详细信息\n";
        foreach ($integrity['details'] as $key => $value) {
            $report .= "### " . $key . "\n";
            if (is_array($value)) {
                foreach ($value as $item) {
                    $report .= "- " . $item . "\n";
                }
            } else {
                $report .= $value . "\n";
            }
            $report .= "\n";
        }
    }

    return $report;
}

// ==========================================
// 最终系统标记
// ==========================================

// 标记系统函数修复完成
if (!defined('TODO_FUNCTIONS_REPAIR_COMPLETED')) {
    define('TODO_FUNCTIONS_REPAIR_COMPLETED', true);
    define('TODO_FUNCTIONS_REPAIR_VERSION', '1.0.0');
    define('TODO_FUNCTIONS_REPAIR_DATE', date('Y-m-d H:i:s'));
}

/**
 * 获取函数修复信息
 * @return array
 */
function todo_get_repair_info()
{
    return [
        'completed' => TODO_FUNCTIONS_REPAIR_COMPLETED,
        'version' => TODO_FUNCTIONS_REPAIR_VERSION,
        'date' => TODO_FUNCTIONS_REPAIR_DATE,
        'total_functions' => count(get_defined_functions()['user']),
        'status' => 'production_ready'
    ];
}

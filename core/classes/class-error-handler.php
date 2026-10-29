<?php
/**
 * 统一错误处理机制
 * 提供标准化的错误处理、日志记录和调试功能
 *
 * @package TodoCMS
 * @version 2.0.0
 */

class Todo_Error_Handler {
    
    /**
     * 错误级别常量
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * 是否已初始化
     * @var bool
     */
    private static $initialized = false;
    
    /**
     * 错误日志文件路径
     * @var string
     */
    private static $log_file = '';
    
    /**
     * 是否启用调试模式
     * @var bool
     */
    private static $debug_mode = false;
    
    /**
     * 错误统计
     * @var array
     */
    private static $error_stats = array(
        'debug' => 0,
        'info' => 0,
        'warning' => 0,
        'error' => 0,
        'critical' => 0
    );
    
    /**
     * 错误处理器列表
     * @var array
     */
    private static $handlers = array();
    
    /**
     * 初始化错误处理器
     * 
     * @param array $config 配置数组
     */
    public static function init($config = array()) {
        if (self::$initialized) {
            return;
        }
        
        // 初始化错误处理系统
        self::initSystem($config);
    }
    
    /**
     * 初始化错误处理系统
     * 
     * @param array $config 配置数组
     */
    private static function initSystem($config = array()) {
        // 设置默认配置
        $default_config = array(
            'log_file' => ABSPATH . 'logs/error.log',
            'debug_mode' => defined('TODO_DEBUG') ? TODO_DEBUG : false,
            'display_errors' => false,
            'log_errors' => true,
            'max_log_size' => 10 * 1024 * 1024, // 10MB
        );
        
        $config = array_merge($default_config, $config);
        
        // 设置配置
        self::$log_file = $config['log_file'];
        self::$debug_mode = $config['debug_mode'];
        
        // 确保日志目录存在
        $log_dir = dirname(self::$log_file);
        if (!is_dir($log_dir)) {
            if (function_exists('todo_mkdir_p')) {
                todo_mkdir_p($log_dir);
            } else {
                mkdir($log_dir, 0777, true);
            }
        }
        
        // 设置PHP错误处理
        if ($config['log_errors']) {
            // ini_set('log_errors', 1);
            // ini_set('error_log', self::$log_file);
        }
        
        if ($config['display_errors']) {
            // ini_set('display_errors', 1);
        } else {
            // ini_set('display_errors', 0);
        }
        
        // 注册错误处理函数
        set_error_handler(array(__CLASS__, 'handle_php_error'));
        set_exception_handler(array(__CLASS__, 'handle_exception'));
        register_shutdown_function(array(__CLASS__, 'handle_fatal_error'));
        
        // 检查日志文件大小
        self::check_log_size($config['max_log_size']);
        
        self::$initialized = true;
    }
    
    /**
     * 记录日志
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别
     * @param array $context 上下文信息
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = array()) {
        if (!self::$initialized) {
            self::init();
        }
        
        // 添加CSRF错误特殊处理
        if (strpos($message, 'CSRF token') !== false) {
            $level = self::LEVEL_CRITICAL;
            $context['security'] = true;
        }
        
        // 更新统计
        if (isset(self::$error_stats[$level])) {
            self::$error_stats[$level]++;
        }
        
        // 格式化日志消息
        $timestamp = date('Y-m-d H:i:s');
        $level_upper = strtoupper($level);
        $context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        
        $log_entry = "[{$timestamp}] {$level_upper}: {$message}{$context_str}" . PHP_EOL;
        
        // 写入日志文件（受 TODO_DEBUG 控制）
        if (defined('TODO_DEBUG') && TODO_DEBUG && self::$log_file && is_writable(dirname(self::$log_file))) {
            // 确保日志文件存在且具有正确权限
            if (!file_exists(self::$log_file)) {
                // 创建文件并设置权限
                touch(self::$log_file);
                chmod(self::$log_file, 0666);
            }
            file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        // 调试模式下输出到屏幕（仅在安全环境下）
        if (self::$debug_mode && ($level === self::LEVEL_ERROR || $level === self::LEVEL_CRITICAL)) {
            // 检查是否在安全的调试环境
            $current_page = $_SERVER['REQUEST_URI'] ?? '';
            $is_ajax_handler = strpos($current_page, '/ajax/') !== false;
            $is_media_page = strpos($current_page, 'media.php') !== false;
            
            // 只在非关键页面显示调试信息
            if (!$is_ajax_handler && !$is_media_page && defined('TODO_DEBUG') && TODO_DEBUG) {
                echo '<div class="bug">';
                echo '<strong>' . $level_upper . ':</strong> ' . htmlspecialchars($message);
                if (!empty($context)) {
                    echo '<br><small>Context: ' . htmlspecialchars(json_encode($context)) . '</small>';
                }
                echo '</div>';
            }
        }
        
        // 触发自定义处理器
        foreach (self::$handlers as $handler) {
            if (is_callable($handler)) {
                call_user_func($handler, $message, $level, $context);
            }
        }
        
        // 发送邮件通知（仅对严重错误）
        if ($level === self::LEVEL_CRITICAL) {
            self::send_critical_error_notification($message, $context);
        }
    }
    
    /**
     * 处理PHP错误
     * 
     * @param int $errno 错误号
     * @param string $errstr 错误消息
     * @param string $errfile 错误文件
     * @param int $errline 错误行号
     * @return bool
     */
    public static function handle_php_error($errno, $errstr, $errfile, $errline) {
        // 如果使用了 @ 错误抑制符，不记录日志
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // 根据错误级别确定日志级别
        $level = self::LEVEL_ERROR;
        switch ($errno) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $level = self::LEVEL_CRITICAL;
                break;
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                $level = self::LEVEL_WARNING;
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $level = self::LEVEL_INFO;
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $level = self::LEVEL_DEBUG;
                break;
            default:
                // Handle E_STRICT for PHP < 8.0 (deprecated in PHP 8.0+)
                if (defined('E_STRICT') && $errno === E_STRICT) {
                    $level = self::LEVEL_DEBUG;
                    break;
                }
                $level = self::LEVEL_ERROR;
                break;
        }
        
        $context = array(
            'errno' => $errno,
            'file' => $errfile,
            'line' => $errline,
            'backtrace' => self::get_formatted_backtrace()
        );
        
        self::log($errstr, $level, $context);
        
        // 只在开发环境且安全页面显示HTML错误
        if (!self::is_ajax_request() && !self::is_url_generation() && defined('TODO_DEBUG') && TODO_DEBUG) {
            $current_page = $_SERVER['REQUEST_URI'] ?? '';
            $is_admin_page = strpos($current_page, '/admin/') !== false;
            $is_media_page = strpos($current_page, 'media.php') !== false;
            $is_ajax_handler = strpos($current_page, '/ajax/') !== false;
            
            // 只在非关键页面显示调试信息
            if ($is_admin_page && !$is_media_page && !$is_ajax_handler) {
                echo "<div class='exbug'>";
                echo "<h4>PHP Error (Debug Mode)</h4>";
                echo "<p><strong>Error:</strong> [$errno] $errstr</p>";
                echo "<p><strong>File:</strong> $errfile</p>";
                echo "<p><strong>Line:</strong> $errline</p>";
                echo "</div>";
            }
        }
        
        // 不阻止PHP默认错误处理
        return false;
    }
    
    /**
     * 处理未捕获的异常
     * 
     * @param Exception $exception 异常对象
     */
    public static function handle_exception($exception) {
        $context = array(
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString()
        );
        
        self::log('Uncaught exception: ' . $exception->getMessage(), self::LEVEL_CRITICAL, $context);
        
        // 只在开发环境且非生产模式下显示详细错误
        if (!self::is_ajax_request() && defined('TODO_DEBUG') && TODO_DEBUG) {
            // 检查是否在管理后台且不是媒体页面等特殊页面
            $current_page = $_SERVER['REQUEST_URI'] ?? '';
            $is_admin_page = strpos($current_page, '/admin/') !== false;
            $is_media_page = strpos($current_page, 'media.php') !== false;
            $is_ajax_handler = strpos($current_page, '/ajax/') !== false;
            
            // 只在非关键页面显示调试信息
            if ($is_admin_page && !$is_media_page && !$is_ajax_handler) {
                echo "<div class='exbug'>";
                echo "<h4>PHP Exception (Debug Mode)</h4>";
                echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
                echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
                echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
                echo "<p><strong>Stack trace:</strong></p>";
                echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
                echo "</div>";
            }
        }
    }
    
    /**
     * 处理致命错误
     */
    public static function handle_fatal_error() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR))) {
            $context = array(
                'type' => $error['type'],
                'file' => $error['file'],
                'line' => $error['line']
            );
            
            self::log('Fatal error: ' . $error['message'], self::LEVEL_CRITICAL, $context);
        }
    }
    
    /**
     * 添加自定义错误处理器
     * 
     * @param callable $handler 处理器函数
     */
    public static function add_handler($handler) {
        if (is_callable($handler)) {
            self::$handlers[] = $handler;
        }
    }
    
    /**
     * 获取格式化的调用栈
     * 
     * @param int $limit 限制层数
     * @return array 调用栈数组
     */
    private static function get_formatted_backtrace($limit = 10) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
        $formatted = array();
        
        foreach ($backtrace as $trace) {
            $file = isset($trace['file']) ? basename($trace['file']) : 'unknown';
            $line = isset($trace['line']) ? $trace['line'] : 'unknown';
            $function = isset($trace['function']) ? $trace['function'] : 'unknown';
            $class = isset($trace['class']) ? $trace['class'] . '::' : '';
            
            $formatted[] = "{$class}{$function}() in {$file}:{$line}";
        }
        
        return $formatted;
    }
    
    /**
     * 检查日志文件大小并轮转
     * 
     * @param int $max_size 最大文件大小（字节）
     */
    private static function check_log_size($max_size) {
        if (!file_exists(self::$log_file)) {
            return;
        }
        
        if (filesize(self::$log_file) > $max_size) {
            $backup_file = self::$log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename(self::$log_file, $backup_file);
            
            // 只保留最近5个备份文件
            $log_dir = dirname(self::$log_file);
            $backup_files = glob($log_dir . '/*.bak');
            if (count($backup_files) > 5) {
                usort($backup_files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                for ($i = 0; $i < count($backup_files) - 5; $i++) {
                    unlink($backup_files[$i]);
                }
            }
        }
    }
    
    /**
     * 发送严重错误通知邮件
     * 
     * @param string $message 错误消息
     * @param array $context 上下文信息
     */
    /**
     * 检查是否为AJAX请求
     * @return bool
     */
    private static function is_ajax_request() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * 检查是否在URL生成过程中
     * @return bool
     */
    private static function is_url_generation() {
        // 检查调用堆栈中是否包含URL生成相关的函数
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $url_functions = array('admin_url', 'site_url', 'get_admin_url', 'get_site_url');
        
        foreach ($trace as $frame) {
            if (isset($frame['function']) && in_array($frame['function'], $url_functions)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function send_critical_error_notification($message, $context) {
        // 简化邮件通知功能，仅记录日志
        self::log('Critical error notification skipped - mail system not available', 
                 self::LEVEL_WARNING, 
                 array('original_message' => $message));
    }
    
    /**
     * 显示友好的错误页面
     */
    private static function display_error_page() {
        if (headers_sent()) {
            return;
        }
        
        http_response_code(500);
        
        // 检查是否为AJAX请求
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || 
                   (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        if ($is_ajax) {
            // AJAX请求返回JSON格式错误
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => '服务器内部错误',
                'error_code' => 500
            ]);
        } else {
            // 普通请求返回HTML错误页面
            echo '<!DOCTYPE html>';
            echo '<html><head><title>网站暂时不可用</title>';
            echo '<meta charset="utf-8">';
            echo '<style>body{font-family:Arial,sans-serif;text-align:center;padding:50px;background:#f5f5f5}'
               . '.error-container{max-width:500px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}'
               . 'h1{color:#e74c3c;margin-bottom:20px}p{color:#666;line-height:1.6}</style>';
            echo '</head><body>';
            echo '<div class="error-container">';
            echo '<h1>网站暂时不可用</h1>';
            echo '<p>抱歉，网站遇到了技术问题，我们正在努力修复中。</p>';
            echo '<p>请稍后再试，或联系网站管理员。</p>';
            echo '</div></body></html>';
        }
        
        exit;
    }
    
    /**
     * 获取错误统计信息
     * 
     * @return array 统计信息
     */
    public static function get_error_stats() {
        return self::$error_stats;
    }
    
    /**
     * 重置错误统计
     */
    public static function reset_error_stats() {
        self::$error_stats = array(
            'debug' => 0,
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'critical' => 0
        );
    }
    
    /**
     * 设置调试模式
     * 
     * @param bool $enabled 是否启用
     */
    public static function set_debug_mode($enabled) {
        self::$debug_mode = (bool) $enabled;
    }
    
    /**
     * 获取日志文件内容
     * 
     * @param int $lines 读取行数
     * @return array 日志行数组
     */
    public static function get_log_content($lines = 100) {
        if (!file_exists(self::$log_file)) {
            return array();
        }
        
        $content = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines > 0 && count($content) > $lines) {
            return array_slice($content, -$lines);
        }
        
        return $content;
    }
    
    /**
     * 清空日志文件
     */
    public static function clear_log() {
        if (file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, '');
        }
        
        self::reset_error_stats();
    }
}

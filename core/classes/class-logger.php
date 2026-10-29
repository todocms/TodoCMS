<?php
/**
 * 统一日志记录器
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Logger {
    
    // 日志级别常量
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';
    
    /**
     * 日志级别优先级映射
     */
    private static $levels = [
        self::EMERGENCY => 0,
        self::ALERT     => 1,
        self::CRITICAL  => 2,
        self::ERROR     => 3,
        self::WARNING   => 4,
        self::NOTICE    => 5,
        self::INFO      => 6,
        self::DEBUG     => 7
    ];
    
    /**
     * 日志配置
     */
    private static $config = [
        'enabled' => true,
        'level' => self::INFO,
        'file_path' => '',
        'max_file_size' => 10485760, // 10MB
        'max_files' => 5,
        'date_format' => 'Y-m-d H:i:s',
        'include_trace' => false
    ];
    
    /**
     * 单例实例
     */
    private static $instance = null;
    
    /**
     * 初始化日志系统
     */
    public static function init() {
        self::getInstance();
        return true;
    }
    
    /**
     * 获取单例实例
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 私有构造函数
     */
    private function __construct() {
        $this->initConfig();
    }
    
    /**
     * 初始化配置
     */
    private function initConfig() {
        // 设置默认日志文件路径
        if (empty(self::$config['file_path'])) {
            $log_dir = defined('TODO_LOG_DIR') ? constant('TODO_LOG_DIR') : ABSPATH . 'logs';
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }
            self::$config['file_path'] = $log_dir . '/todocms.log';
        }
        
        // 从配置文件读取设置
        if (function_exists('todo_get_option')) {
            self::$config['enabled'] = todo_get_option('log_enabled', true);
            self::$config['level'] = todo_get_option('log_level', self::INFO);
        }

        // 开发环境启用调试日志
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            self::$config['level'] = self::DEBUG;
            self::$config['include_trace'] = true;
        }
    }
    
    /**
     * 记录紧急日志
     */
    public static function emergency($message, array $context = []) {
        return self::log(self::EMERGENCY, $message, $context);
    }
    
    /**
     * 记录警报日志
     */
    public static function alert($message, array $context = []) {
        return self::log(self::ALERT, $message, $context);
    }
    
    /**
     * 记录严重错误日志
     */
    public static function critical($message, array $context = []) {
        return self::log(self::CRITICAL, $message, $context);
    }
    
    /**
     * 记录错误日志
     */
    public static function error($message, array $context = []) {
        return self::log(self::ERROR, $message, $context);
    }
    
    /**
     * 记录警告日志
     */
    public static function warning($message, array $context = []) {
        return self::log(self::WARNING, $message, $context);
    }
    
    /**
     * 记录通知日志
     */
    public static function notice($message, array $context = []) {
        return self::log(self::NOTICE, $message, $context);
    }
    
    /**
     * 记录信息日志
     */
    public static function info($message, array $context = []) {
        return self::log(self::INFO, $message, $context);
    }
    
    /**
     * 记录调试日志
     */
    public static function debug($message, array $context = []) {
        return self::log(self::DEBUG, $message, $context);
    }
    
    /**
     * 通用日志记录方法
     */
    public static function log($level, $message, array $context = []) {
        // 检查是否在 DEBUG 模式下
        if (!defined('TODO_DEBUG') || !TODO_DEBUG) {
            return false;
        }

        $instance = self::getInstance();
        
        // 检查是否启用日志
        if (!self::$config['enabled']) {
            return false;
        }
        
        // 检查日志级别
        if (!self::shouldLog($level)) {
            return false;
        }
        
        // 格式化日志消息
        $formatted_message = $instance->formatMessage($level, $message, $context);
        
        // 写入日志文件
        return $instance->writeToFile($formatted_message);
    }
    
    /**
     * 检查是否应该记录该级别的日志
     */
    private static function shouldLog($level) {
        $current_level = self::$levels[self::$config['level']] ?? 7;
        $message_level = self::$levels[$level] ?? 7;
        
        return $message_level <= $current_level;
    }
    
    /**
     * 格式化日志消息
     */
    private function formatMessage($level, $message, array $context = []) {
        $timestamp = date(self::$config['date_format']);
        $level = strtoupper($level);
        
        // 获取调用者信息
        $caller = $this->getCaller();
        
        // 构建基本消息
        $formatted = "[{$timestamp}] {$level}: {$message}";
        
        // 添加调用者信息
        if ($caller) {
            $formatted .= " [{$caller}]";
        }
        
        // 添加上下文信息
        if (!empty($context)) {
            $formatted .= " Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        // 添加堆栈跟踪（仅在调试模式下）
        if (self::$config['include_trace'] && in_array($level, ['ERROR', 'CRITICAL', 'EMERGENCY'])) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $formatted .= "\n  Stack trace: " . $this->formatTrace($trace);
        }
        
        return $formatted . "\n";
    }
    
    /**
     * 获取调用者信息
     */
    private function getCaller() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        
        // 跳过Logger类的方法
        foreach ($trace as $frame) {
            if (isset($frame['class']) && $frame['class'] === __CLASS__) {
                continue;
            }
            
            $file = isset($frame['file']) ? basename($frame['file']) : 'unknown';
            $line = isset($frame['line']) ? $frame['line'] : 'unknown';
            $function = isset($frame['function']) ? $frame['function'] : 'unknown';
            
            return "{$file}:{$line} {$function}()";
        }
        
        return null;
    }
    
    /**
     * 格式化堆栈跟踪
     */
    private function formatTrace($trace) {
        $formatted = [];
        
        foreach ($trace as $i => $frame) {
            if (isset($frame['class']) && $frame['class'] === __CLASS__) {
                continue;
            }
            
            $file = isset($frame['file']) ? basename($frame['file']) : 'unknown';
            $line = isset($frame['line']) ? $frame['line'] : 'unknown';
            $function = isset($frame['function']) ? $frame['function'] : 'unknown';
            
            $formatted[] = "#{$i} {$file}({$line}): {$function}()";
        }
        
        return implode("\n    ", $formatted);
    }
    
    /**
     * 写入日志文件
     */
    private function writeToFile($message) {
        $file_path = self::$config['file_path'];
        
        // 检查文件大小，必要时轮转
        if (file_exists($file_path) && filesize($file_path) > self::$config['max_file_size']) {
            $this->rotateLogFile($file_path);
        }
        
        // 写入日志
        $result = @file_put_contents($file_path, $message, FILE_APPEND | LOCK_EX);
        
        // 如果写入失败，尝试写入系统日志
        if ($result === false) {
            // 在AJAX请求中不输出错误日志，避免污染JSON响应
            if (!defined('DOING_AJAX') || !DOING_AJAX) {
                error_log("TodoCMS Logger: Failed to write to log file. Message: " . trim($message));
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * 轮转日志文件
     */
    private function rotateLogFile($file_path) {
        $max_files = self::$config['max_files'];
        
        // 删除最旧的文件
        $oldest_file = $file_path . '.' . $max_files;
        if (file_exists($oldest_file)) {
            unlink($oldest_file);
        }
        
        // 重命名现有文件
        for ($i = $max_files - 1; $i >= 1; $i--) {
            $old_file = $file_path . '.' . $i;
            $new_file = $file_path . '.' . ($i + 1);
            
            if (file_exists($old_file)) {
                rename($old_file, $new_file);
            }
        }
        
        // 重命名当前文件
        if (file_exists($file_path)) {
            rename($file_path, $file_path . '.1');
        }
    }
    
    /**
     * 设置配置
     */
    public static function setConfig($key, $value) {
        if (array_key_exists($key, self::$config)) {
            self::$config[$key] = $value;
        }
    }
    
    /**
     * 获取配置
     */
    public static function getConfig($key = null) {
        if ($key === null) {
            return self::$config;
        }
        
        return self::$config[$key] ?? null;
    }
    
    /**
     * 清空日志文件
     */
    public static function clearLogs() {
        self::getInstance();
        $file_path = self::$config['file_path'];
        
        if (file_exists($file_path)) {
            file_put_contents($file_path, '');
        }
        
        // 删除轮转的日志文件
        for ($i = 1; $i <= self::$config['max_files']; $i++) {
            $rotated_file = $file_path . '.' . $i;
            if (file_exists($rotated_file)) {
                unlink($rotated_file);
            }
        }
        
        self::info('日志文件已清空');
    }
    
    /**
     * 获取日志文件内容
     */
    public static function getLogContent($lines = 100) {
        self::getInstance();
        $file_path = self::$config['file_path'];
        
        if (!file_exists($file_path)) {
            return '';
        }
        
        // 读取最后N行
        $file = new SplFileObject($file_path, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();
        
        $start_line = max(0, $total_lines - $lines);
        $content = [];
        
        $file->seek($start_line);
        while (!$file->eof()) {
            $content[] = $file->current();
            $file->next();
        }
        
        return implode('', $content);
    }
}

// 便捷函数
if (!function_exists('todo_log')) {
    function todo_log($message, $level = 'info', $context = []) {
        // 如果第三个参数是字符串，将其转换为数组
        if (is_string($context)) {
            $context = ['tag' => $context];
        }
        return Todo_Logger::log($level, $message, $context);
    }
}

if (!function_exists('todo_log_error')) {
    function todo_log_error($message, $context = []) {
        if (is_string($context)) {
            $context = ['context' => $context];
        }
        return Todo_Logger::error($message, $context);
    }
}

if (!function_exists('todo_log_warning')) {
    function todo_log_warning($message, $context = []) {
        if (is_string($context)) {
            $context = ['context' => $context];
        }
        return Todo_Logger::warning($message, $context);
    }
}

if (!function_exists('todo_log_info')) {
    function todo_log_info($message, $context = []) {
        if (is_string($context)) {
            $context = ['context' => $context];
        }
        return Todo_Logger::info($message, $context);
    }
}

if (!function_exists('todo_log_debug')) {
    function todo_log_debug($message, $context = []) {
        return Todo_Logger::debug($message, $context);
    }
}

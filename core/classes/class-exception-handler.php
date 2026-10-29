<?php
/**
 * 统一异常处理器
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Exception_Handler {
    
    /**
     * 是否已初始化
     */
    private static $initialized = false;
    
    /**
     * 错误类型映射
     */
    private static $error_types = [
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated'
    ];
    
    /**
     * 初始化异常处理器
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        // 设置异常处理器
        set_exception_handler([__CLASS__, 'handleException']);
        
        // 设置错误处理器
        set_error_handler([__CLASS__, 'handleError']);
        
        // 设置致命错误处理器
        register_shutdown_function([__CLASS__, 'handleShutdown']);
        
        self::$initialized = true;
        
        Todo_Logger::info('异常处理器已初始化');
    }
    
    /**
     * 处理未捕获的异常
     */
    public static function handleException($exception) {
        $error_data = [
            'type' => 'Exception',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code' => $exception->getCode()
        ];
        
        // 记录日志
        Todo_Logger::critical('未捕获的异常', $error_data);
        
        // 发送错误响应
        self::sendErrorResponse($error_data);
    }
    
    /**
     * 处理PHP错误
     */
    public static function handleError($severity, $message, $file, $line) {
        // 检查错误报告级别
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $error_data = [
            'type' => 'PHP Error',
            'severity' => self::$error_types[$severity] ?? 'Unknown',
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'trace' => self::getBacktrace()
        ];
        
        // 根据错误级别选择日志级别
        $log_level = self::getLogLevel($severity);
        Todo_Logger::log($log_level, "PHP错误: {$message}", $error_data);
        
        // 对于致命错误，发送错误响应
        if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            self::sendErrorResponse($error_data);
        }
        
        return true;
    }
    
    /**
     * 处理致命错误
     */
    public static function handleShutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $error_data = [
                'type' => 'Fatal Error',
                'severity' => self::$error_types[$error['type']] ?? 'Unknown',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ];
            
            // 记录致命错误
            Todo_Logger::emergency('致命错误导致脚本终止', $error_data);
            
            // 发送错误响应
            self::sendErrorResponse($error_data);
        }
    }
    
    /**
     * 根据错误级别获取日志级别
     */
    private static function getLogLevel($severity) {
        switch ($severity) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return Todo_Logger::ERROR;
                
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return Todo_Logger::WARNING;
                
            case E_NOTICE:
            case E_USER_NOTICE:
                return Todo_Logger::NOTICE;
                
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return Todo_Logger::INFO;
                
            default:
                return Todo_Logger::DEBUG;
        }
    }
    
    /**
     * 获取回溯信息
     */
    private static function getBacktrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
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
        
        return implode("\n", $formatted);
    }
    
    /**
     * 发送错误响应
     */
    private static function sendErrorResponse($error_data) {
        // 防止重复输出
        if (headers_sent()) {
            return;
        }
        
        // 清理输出缓冲区
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 检查是否是AJAX请求 - 多种检测方式
        $is_ajax = false;
        
        // 方式1: 检查 X-Requested-With 头
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $is_ajax = true;
        }
        
        // 方式2: 检查 DOING_AJAX 常量
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $is_ajax = true;
        }
        
        // 方式3: 检查 Content-Type 是否期望JSON
        $accept_header = todo_server_string('HTTP_ACCEPT');
        if ($accept_header !== '' && 
            strpos($accept_header, 'application/json') !== false) {
            $is_ajax = true;
        }
        
        // 方式4: 检查请求路径是否包含ajax
        $request_uri = todo_server_string('REQUEST_URI');
        if ($request_uri !== '' && 
            strpos($request_uri, '/ajax/') !== false) {
            $is_ajax = true;
        }
        
        if ($is_ajax) {
            self::sendAjaxErrorResponse($error_data);
        } else {
            self::sendHtmlErrorResponse($error_data);
        }
        
        exit;
    }
    
    /**
     * 发送AJAX错误响应
     */
    private static function sendAjaxErrorResponse($error_data) {
        http_response_code(500);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => '服务器内部错误',
            'timestamp' => time()
        ];
        
        // 在调试模式下包含详细错误信息
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            $response['debug'] = $error_data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 发送HTML错误响应
     */
    private static function sendHtmlErrorResponse($error_data) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        
        $is_debug = defined('TODO_DEBUG') && TODO_DEBUG;
        
        echo self::getErrorPageHtml($error_data, $is_debug);
    }
    
    /**
     * 获取错误页面HTML
     */
    private static function getErrorPageHtml($error_data, $show_details = false) {
        $title = '系统错误';
        $message = '抱歉，系统遇到了一个错误。请稍后再试。';
        
        if ($show_details) {
            $details = htmlspecialchars(print_r($error_data, true));
        } else {
            $details = '';
        }
        
        return "
<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <meta name=\"generator\" content=\"TodoCMS\">
    <title>{$title} - TodoCMS</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f1f1;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .error-icon {
            font-size: 64px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 24px;
            color: #333;
            margin-bottom: 15px;
        }
        .error-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        .error-details {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
            text-align: left;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            overflow-x: auto;
        }
        .back-button {
            display: inline-block;
            background: #007cba;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .back-button:hover {
            background: #005a87;
        }
    </style>
</head>
<body>
    <div class='error-container'>
        <div class='error-icon'>⚠️</div>
        <h1 class='error-title'>{$title}</h1>
        <p class='error-message'>{$message}</p>
        <a href='javascript:history.back()' class='back-button'>返回上一页</a>
        " . ($show_details ? "<div class='error-details'>{$details}</div>" : '') . "
    </div>
</body>
</html>
        ";
    }
    
    /**
     * 手动触发异常处理
     */
    public static function handleCustomException($message, $code = 0, $file = null, $line = null) {
        $exception = new Exception($message, $code);
        
        // 如果提供了文件和行号，创建自定义异常数据
        if ($file && $line) {
            $error_data = [
                'type' => 'Custom Exception',
                'class' => 'Exception',
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'trace' => self::getBacktrace(),
                'code' => $code
            ];
            
            Todo_Logger::error('自定义异常', $error_data);
            self::sendErrorResponse($error_data);
        } else {
            self::handleException($exception);
        }
    }
    
    /**
     * 获取错误统计信息
     */
    public static function getErrorStats() {
        // 这里可以实现错误统计功能
        // 比如从日志文件中统计不同类型错误的数量
        return [
            'total_errors' => 0,
            'fatal_errors' => 0,
            'warnings' => 0,
            'notices' => 0
        ];
    }
    
    /**
     * 重置异常处理器
     */
    public static function reset() {
        restore_exception_handler();
        restore_error_handler();
        self::$initialized = false;
    }
}

// 便捷函数
if (!function_exists('todo_handle_error')) {
    function todo_handle_error($message, $code = 0, $file = null, $line = null) {
        Todo_Exception_Handler::handleCustomException($message, $code, $file, $line);
    }
}

if (!function_exists('todo_die')) {
    function todo_die($message, $code = 500) {
        Todo_Logger::error('脚本终止', ['message' => $message, 'code' => $code]);
        
        if (!headers_sent()) {
            http_response_code($code);
        }
        
        // 检查是否是AJAX请求
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $message,
                'code' => $code
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo "<h1>错误</h1><p>{$message}</p>";
        }
        
        exit;
    }
}

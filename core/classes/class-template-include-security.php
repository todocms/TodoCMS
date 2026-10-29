<?php
/**
 * 模板Include标签安全验证类
 * 
 * 基于ZanCms安全机制，实现严格的安全验证
 * 防止路径穿越、危险目录访问、非法文件包含等安全问题
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 模板Include安全验证器
 */
class Todo_Template_Include_Security {
    
    /**
     * 允许的文件扩展名
     * @var array
     */
    private static $allowed_extensions = ['html', 'htm'];
    
    /**
     * 禁止的危险目录列表
     * @var array
     */
    private static $dangerous_directories = [
        'uploads/', '/uploads/', 'uploads\\',
        'data/', '/data/', 'data\\',
        'vendor/', '/vendor/', 'vendor\\',
        'core/', '/core/', 'core\\',
        'admin/', '/admin/', 'admin\\',
        'includes/', '/includes/', 'includes\\',
        'config/', '/config/', 'config\\',
        'database/', '/database/', 'database\\',
        'cache/', '/cache/', 'cache\\',
        'logs/', '/logs/', 'logs\\',
        'system/', '/system/', 'system\\',
        'application/', '/application/', 'application\\',
        '.git/', '/.git/', '.git\\',
        '.svn/', '/.svn/', '.svn\\',
    ];
    
    /**
     * 最大路径长度（防止路径过长攻击）
     * @var int
     */
    private static $max_path_length = 255;
    
    /**
     * 验证结果
     * @var array
     */
    private static $validation_result = [
        'valid' => false,
        'normalized_path' => '',
        'error_code' => '',
        'error_message' => ''
    ];
    
    /**
     * 验证include文件路径安全性
     * 
     * 基于ZanCms的安全机制，实现严格的多层安全验证：
     * 1. 路径规范化（防止路径穿越）
     * 2. 扩展名限制（只允许模板文件）
     * 3. 危险目录检查
     * 4. 路径长度限制
     * 5. 危险内容检测
     * 
     * @param string $file_path 文件路径
     * @param string $base_template_path 基础模板路径（用于相对路径解析）
     * @return array 验证结果 ['valid' => bool, 'normalized_path' => string, 'error_code' => string, 'error_message' => string]
     */
    public static function validate($file_path, $base_template_path = '') {
        // 重置验证结果
        self::$validation_result = [
            'valid' => false,
            'normalized_path' => '',
            'error_code' => '',
            'error_message' => ''
        ];
        
        // 1. 基础验证：检查参数
        if (empty($file_path) || !is_string($file_path)) {
            return self::set_error('INVALID_PARAM', '文件路径不能为空');
        }
        
        $original_path = $file_path;
        
        // 2. 路径规范化（防止路径穿越攻击）
        $normalized_path = self::normalize_path($file_path);
        if ($normalized_path === false) {
            return self::set_error('PATH_TRAVERSAL', '检测到路径穿越攻击：' . $original_path);
        }
        
        // 3. 路径长度限制
        if (strlen($normalized_path) > self::$max_path_length) {
            return self::set_error('PATH_TOO_LONG', '文件路径过长（最大' . self::$max_path_length . '字符）');
        }
        
        // 4. 文件扩展名验证（严格限制：只允许 .html 和 .htm）
        $path_info = pathinfo($normalized_path);
        $extension = isset($path_info['extension']) ? strtolower($path_info['extension']) : '';
        
        // 如果没有扩展名，默认添加 .html（允许无扩展名的情况，但最终需要验证）
        if (empty($extension)) {
            $normalized_path .= '.html';
            $extension = 'html';
        }
        
        // 严格验证扩展名
        if (!in_array($extension, self::$allowed_extensions, true)) {
            return self::set_error('INVALID_EXTENSION', '不允许的文件扩展名：' . $extension . '（只允许 ' . implode(', ', self::$allowed_extensions) . '）');
        }
        
        // 5. 危险目录检查（白名单机制）
        $is_dangerous = self::check_dangerous_directory($normalized_path);
        if ($is_dangerous) {
            return self::set_error('DANGEROUS_DIRECTORY', '禁止包含危险目录下的文件：' . $normalized_path);
        }
        
        // 6. 危险内容检测（防止注入PHP代码、脚本等）
        if (self::contains_dangerous_content($normalized_path)) {
            return self::set_error('DANGEROUS_CONTENT', '文件路径包含危险内容：' . $normalized_path);
        }
        
        // 7. Windows路径安全检查（防止Windows驱动器路径）
        if (self::is_windows_drive_path($normalized_path)) {
            return self::set_error('WINDOWS_DRIVE_PATH', '禁止使用Windows驱动器路径：' . $normalized_path);
        }
        
        // 8. 相对路径规范化（确保路径规范）
        $normalized_path = self::clean_path($normalized_path);
        
        // 验证通过
        return self::set_success($normalized_path);
    }
    
    /**
     * 路径规范化（防止路径穿越攻击）
     * 
     * 参考ZanCms实现：
     * - 统一路径分隔符（转换为 /）
     * - 移除开头的 ./ 和 ../../ 等路径遍历字符
     * - 检测路径穿越攻击
     * 
     * @param string $path 原始路径
     * @return string|false 规范化后的路径，失败返回false
     */
    private static function normalize_path($path) {
        // 统一路径分隔符为 /
        $path = str_replace('\\', '/', $path);
        
        // 移除开头的 ./ 和多余的 .
        $path = preg_replace('/^(\.+\/)+/', '', $path);
        $path = preg_replace('/^\.+/', '', $path);
        
        // 移除 ../ 路径遍历字符（ZanCms方式）
        // 注意：这里使用更严格的验证，防止通过编码等方式绕过
        $path = preg_replace('/\.(\.*)\//', '', $path);
        $path = preg_replace('/\.(\.*)$/', '', $path);
        
        // 检测是否仍包含路径遍历字符（多种形式）
        $traversal_patterns = [
            '/\.\./',           // ../ 标准形式
            '/\.\.\\\\/',       // ..\ 反斜杠形式
            '/\.\.%2F/',        // URL编码形式
            '/\.\.%5C/',        // URL编码反斜杠形式
            '/\.\.\x2F/',       // 十六进制编码
            '/\.\.\x5C/',       // 十六进制编码反斜杠
        ];
        
        foreach ($traversal_patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return false;
            }
        }
        
        // 检测连续的点号（可能用于绕过检测）
        if (preg_match('/\.{2,}/', $path)) {
            return false;
        }
        
        // 移除开头的斜杠（统一为相对路径）
        $path = ltrim($path, '/');
        
        return $path;
    }
    
    /**
     * 检查危险目录
     * 
     * 参考ZanCms实现，禁止包含危险目录下的文件
     * 
     * @param string $path 文件路径
     * @return bool 是否为危险目录
     */
    private static function check_dangerous_directory($path) {
        // 统一路径分隔符
        $normalized = '/' . ltrim(str_replace('\\', '/', $path), '/');
        
        foreach (self::$dangerous_directories as $danger_dir) {
            $danger_normalized = '/' . trim(str_replace('\\', '/', $danger_dir), '/');
            
            // 检查路径是否包含危险目录
            // 使用严格匹配，防止误判
            if (stripos($normalized, $danger_normalized . '/') !== false || 
                stripos($normalized, $danger_normalized) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检测路径中是否包含危险内容
     * 
     * 防止通过路径注入PHP代码、脚本等
     * 
     * @param string $path 文件路径
     * @return bool 是否包含危险内容
     */
    private static function contains_dangerous_content($path) {
        $dangerous_patterns = [
            '/<\?php/i',           // PHP开始标签
            '/<\?=/i',             // PHP短标签
            '/<script/i',          // 脚本标签
            '/eval\s*\(/i',        // eval函数
            '/exec\s*\(/i',        // exec函数
            '/system\s*\(/i',      // system函数
            '/shell_exec/i',       // shell_exec函数
            '/passthru/i',         // passthru函数
            '/proc_open/i',        // proc_open函数
            '/popen/i',            // popen函数
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否为Windows驱动器路径
     * 
     * 防止使用 C:\, D:\ 等Windows驱动器路径
     * 
     * @param string $path 文件路径
     * @return bool 是否为Windows驱动器路径
     */
    private static function is_windows_drive_path($path) {
        // 检查 C:\, D:\ 等格式
        if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return true;
        }
        
        // 检查 : 在第二个字符位置（Windows驱动器标识）
        $normalized = str_replace('\\', '/', $path);
        if (strlen($normalized) >= 2 && $normalized[1] === ':') {
            return true;
        }
        
        return false;
    }
    
    /**
     * 清理路径（移除多余字符）
     * 
     * @param string $path 文件路径
     * @return string 清理后的路径
     */
    private static function clean_path($path) {
        // 统一路径分隔符
        $path = str_replace('\\', '/', $path);
        
        // 移除开头的斜杠
        $path = ltrim($path, '/');
        
        // 合并多个连续的斜杠
        $path = preg_replace('/\/+/', '/', $path);
        
        // 移除末尾的斜杠（文件名不应该以斜杠结尾）
        $path = rtrim($path, '/');
        
        return $path;
    }
    
    /**
     * 设置错误结果
     * 
     * @param string $error_code 错误代码
     * @param string $error_message 错误消息
     * @return array 验证结果
     */
    private static function set_error($error_code, $error_message) {
        self::$validation_result = [
            'valid' => false,
            'normalized_path' => '',
            'error_code' => $error_code,
            'error_message' => $error_message
        ];
        
        // 记录安全日志
        error_log("TodoCMS Template Include Security: [{$error_code}] {$error_message}");
        
        return self::$validation_result;
    }
    
    /**
     * 设置成功结果
     * 
     * @param string $normalized_path 规范化后的路径
     * @return array 验证结果
     */
    private static function set_success($normalized_path) {
        self::$validation_result = [
            'valid' => true,
            'normalized_path' => $normalized_path,
            'error_code' => '',
            'error_message' => ''
        ];
        
        return self::$validation_result;
    }
    
    /**
     * 获取最后的验证结果
     * 
     * @return array
     */
    public static function get_last_result() {
        return self::$validation_result;
    }
    
    /**
     * 获取允许的文件扩展名列表
     * 
     * @return array
     */
    public static function get_allowed_extensions() {
        return self::$allowed_extensions;
    }
    
    /**
     * 获取危险目录列表
     * 
     * @return array
     */
    public static function get_dangerous_directories() {
        return self::$dangerous_directories;
    }
    
    /**
     * 验证文件是否存在于安全的模板目录中
     * 
     * @param string $normalized_path 规范化后的路径
     * @param string $template_dir 模板目录
     * @return array ['exists' => bool, 'full_path' => string, 'error' => string]
     */
    public static function verify_file_exists($normalized_path, $template_dir) {
        // 构建完整路径
        $full_path = rtrim($template_dir, '/\\') . '/' . ltrim($normalized_path, '/\\');
        
        // 规范化路径（使用realpath解析符号链接等）
        $real_path = realpath($full_path);
        
        if ($real_path === false) {
            // 文件不存在，尝试多种扩展名
            $path_without_ext = preg_replace('/\.(html|htm)$/i', '', $full_path);
            foreach (self::$allowed_extensions as $ext) {
                $try_path = $path_without_ext . '.' . $ext;
                $try_real_path = realpath($try_path);
                if ($try_real_path !== false) {
                    // 验证路径是否在模板目录内（防止符号链接攻击）
                    $template_real_dir = realpath($template_dir);
                    if ($template_real_dir !== false && strpos($try_real_path, $template_real_dir) === 0) {
                        return [
                            'exists' => true,
                            'full_path' => $try_real_path,
                            'error' => ''
                        ];
                    }
                }
            }
            
            return [
                'exists' => false,
                'full_path' => $full_path,
                'error' => '文件不存在'
            ];
        }
        
        // 验证路径是否在模板目录内（防止符号链接攻击和路径穿越）
        $template_real_dir = realpath($template_dir);
        if ($template_real_dir === false) {
            return [
                'exists' => false,
                'full_path' => $full_path,
                'error' => '模板目录不存在'
            ];
        }
        
        // 严格检查：realpath必须在模板目录内
        if (strpos($real_path, $template_real_dir) !== 0) {
            error_log("TodoCMS Template Include Security: Path traversal detected - {$real_path} is outside {$template_real_dir}");
            return [
                'exists' => false,
                'full_path' => $full_path,
                'error' => '路径不在模板目录内（可能存在路径穿越）'
            ];
        }
        
        // 验证是否为文件（不是目录）
        if (!is_file($real_path)) {
            return [
                'exists' => false,
                'full_path' => $full_path,
                'error' => '指定路径不是文件'
            ];
        }
        
        return [
            'exists' => true,
            'full_path' => $real_path,
            'error' => ''
        ];
    }
}


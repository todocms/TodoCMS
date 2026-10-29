<?php
/**
 * TodoCMS 主题管理函数
 * 
 * 专注于主题管理功能
 * 
 * @package TodoCMS
 * @version 2.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

/**
 * 安装主题
 *
 * @param string $theme_package 主题包路径
 * @return string|array 成功返回主题目录名，失败返回错误信息
 */
function todo_theme_install($theme_package) {
    $themes_dir = TODO_ROOT . '/content/themes';
    
    // 创建临时目录
    $temp_dir = sys_get_temp_dir() . '/todo_theme_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        return array('error' => '无法创建临时目录');
    }
    
    try {
        // 解压主题包（安全解压）
        $zip = new ZipArchive();
        if ($zip->open($theme_package) !== TRUE) {
            throw new Exception('无法打开主题包');
        }
        // 过滤路径穿越与绝对路径
        $safeEntries = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $name = str_replace('\\', '/', $name);
            if ($name === '') { continue; }
            if (preg_match('#(^|/)\.\.(/|$)#', $name)) { continue; }
            if (preg_match('#^([A-Za-z]:|/)#', $name)) { continue; }
            $safeEntries[] = $name;
        }
        if (empty($safeEntries)) {
            $zip->close();
            throw new Exception('主题包为空或不合法');
        }
        $zip->extractTo($temp_dir, $safeEntries);
        $zip->close();
        // 移除符号链接，避免绕过
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            $p = $info->getPathname();
            if (is_link($p)) { @unlink($p); }
        }
        
        // 查找主题目录
        $extracted_dirs = scandir($temp_dir);
        $theme_dir = null;
        foreach ($extracted_dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($temp_dir . '/' . $dir)) {
                $theme_dir = $dir;
                break;
            }
        }
        
        if (!$theme_dir) {
            throw new Exception('主题包格式不正确');
        }
        
        // 验证主题
        $style_css = $temp_dir . '/' . $theme_dir . '/style.css';
        if (!file_exists($style_css)) {
            throw new Exception('主题缺少style.css文件');
        }
        
        // 移动到主题目录
        $target_dir = $themes_dir . '/' . $theme_dir;
        if (is_dir($target_dir)) {
            throw new Exception('主题已存在');
        }
        
        if (!rename($temp_dir . '/' . $theme_dir, $target_dir)) {
            throw new Exception('无法移动主题文件');
        }
        
        todo_log('theme_installed', "主题已安装: $theme_dir");
        return $theme_dir;
        
    } catch (Exception $e) {
        // 清理临时文件
        todo_delete_directory($temp_dir);
        return array('error' => $e->getMessage());
    }
}

/**
 * 激活主题
 *
 * @param string $theme_name 主题名称
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_theme_activate($theme_name) {
    $theme_dir = TODO_ROOT . '/content/themes/' . $theme_name;
    
    if (!is_dir($theme_dir)) {
        return array('error' => '主题不存在');
    }
    
    // 检查主题文件
    if (!file_exists($theme_dir . '/style.css')) {
        return array('error' => '无效的主题');
    }
    
    // 调用主题激活钩子
    $functions_file = $theme_dir . '/functions.php';
    if (file_exists($functions_file)) {
        include_once $functions_file;
        
        if (function_exists($theme_name . '_theme_activation')) {
            call_user_func($theme_name . '_theme_activation');
        }
    }
    
    // 使用统一主题管理器进行激活（仅更新 themes 表，不写 options）
    activate_theme($theme_name);
    todo_log('theme_activated', "主题已激活: $theme_name");
    
    return true;
}

/**
 * 获取当前激活的主题
 *
 * @return string 当前激活的主题名称
 */
function todo_get_active_theme() {
    return get_theme_name();
}

/**
 * 获取主题信息
 *
 * @param string $theme_name 主题名称
 * @return array|false 主题信息，失败返回false
 */
function todo_get_theme_info($theme_name) {
    $theme_dir = TODO_ROOT . '/content/themes/' . $theme_name;
    $style_css = $theme_dir . '/style.css';
    
    if (!file_exists($style_css)) {
        return false;
    }
    
    $theme_data = get_file_data($style_css, array(
        'Name' => 'Theme Name',
        'Version' => 'Version',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'ThemeURI' => 'Theme URI'
    ));
    
    $theme_data['Directory'] = $theme_name;
    $theme_data['Active'] = (todo_get_active_theme() === $theme_name);
    $theme_data['Screenshot'] = file_exists($theme_dir . '/screenshot.png') ? 
        todo_get_site_url() . '/content/themes/' . $theme_name . '/screenshot.png' : '';
    
    return $theme_data;
}

/**
 * 获取所有主题
 *
 * @return array 主题列表
 */
function todo_get_themes() {
    $themes_dir = TODO_ROOT . '/content/themes';
    $themes = array();
    
    if (!is_dir($themes_dir)) {
        return $themes;
    }
    
    $dirs = scandir($themes_dir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..' || !is_dir($themes_dir . '/' . $dir)) {
            continue;
        }
        
        $theme_info = todo_get_theme_info($dir);
        if ($theme_info) {
            $themes[$dir] = $theme_info;
        }
    }
    
    return $themes;
}

/**
 * 删除主题
 *
 * @param string $theme_name 主题名称
 * @return bool|array 成功返回true，失败返回错误信息
 */
function todo_theme_delete($theme_name) {
    if ($theme_name === 'default') {
        return array('error' => '不能删除默认主题');
    }
    
    if (todo_get_active_theme() === $theme_name) {
        return array('error' => '不能删除当前激活的主题');
    }
    
    $theme_dir = TODO_ROOT . '/content/themes/' . $theme_name;
    if (!is_dir($theme_dir)) {
        return array('error' => '主题不存在');
    }
    
    // 调用主题删除钩子
    $functions_file = $theme_dir . '/functions.php';
    if (file_exists($functions_file)) {
        include_once $functions_file;
        
        if (function_exists($theme_name . '_theme_deletion')) {
            call_user_func($theme_name . '_theme_deletion');
        }
    }
    
    todo_delete_directory($theme_dir);
    todo_log('theme_deleted', "主题已删除: $theme_name");
    
    return true;
}

/**
 * 获取主题URL
 *
 * @param string $theme_name 主题名称，为空则使用当前主题
 * @return string 主题URL
 */
function todo_theme_url($theme_name = null) {
    if (!$theme_name) {
        $theme_name = todo_get_active_theme();
    }
    return todo_get_site_url() . '/content/themes/' . $theme_name . '/';
}

/**
 * 获取主题目录路径
 *
 * @param string $theme_name 主题名称，为空则使用当前主题
 * @return string 主题路径
 */
function todo_theme_path($theme_name = null) {
    if (!$theme_name) {
        $theme_name = todo_get_active_theme();
    }
    return TODO_ROOT . '/content/themes/' . $theme_name . '/';
}

/**
 * 加载主题模板文件
 *
 * @param string $template_name 模板文件名
 * @param array $variables 传递给模板的变量
 * @return bool 是否成功加载
 */
function todo_load_template($template_name, $variables = array()) {
    $theme_name = todo_get_active_theme();
    $template_file = TODO_ROOT . '/content/themes/' . $theme_name . '/' . $template_name;

    if (!file_exists($template_file)) {
        // 严格模式：不回退到默认主题，记录日志并返回 false
        if (function_exists('todo_log')) {
            todo_log('template_missing', "当前主题($theme_name)缺少模板文件: $template_name");
        }
        return false;
    }

    // 提取变量到当前作用域
    if (!empty($variables) && is_array($variables)) {
        extract($variables);
    }
    include $template_file;
    return true;
}

/**
 * 获取主题模板内容
 *
 * @param string $template_name 模板文件名
 * @param array $variables 传递给模板的变量
 * @return string 模板内容
 */
function todo_get_template($template_name, $variables = array()) {
    ob_start();
    todo_load_template($template_name, $variables);
    return ob_get_clean();
}

// ============================================================================
// 管理菜单函数
// ============================================================================

/**
 * 添加管理菜单页面
 *
 * @param string $page_title 页面标题
 * @param string $menu_title 菜单标题
 * @param string $capability 权限要求
 * @param string $menu_slug 菜单标识
 * @param callable $callback 回调函数
 * @param string $icon_url 图标URL
 * @param int $position 位置
 */
function todo_add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url = '', $position = null) {
    global $todo_admin_menu;
    
    if (!isset($todo_admin_menu)) {
        $todo_admin_menu = array();
    }
    
    $todo_admin_menu[$menu_slug] = array(
        'page_title' => $page_title,
        'menu_title' => $menu_title,
        'capability' => $capability,
        'menu_slug' => $menu_slug,
        'callback' => $callback,
        'icon_url' => $icon_url,
        'position' => $position
    );
}

// ============================================================================
// 工具函数
// ============================================================================

/**
 * 创建目录（递归）
 *
 * @param string $target 目标目录
 * @return bool 是否成功
 */
function todo_mkdir_p($target) {
    if (file_exists($target)) {
        return @is_dir($target);
    }
    
    // 尝试创建目录
    if (@mkdir($target, 0755, true)) {
        return true;
    }
    
    // 如果失败，尝试逐级创建
    $parent = dirname($target);
    if ($parent !== $target && todo_mkdir_p($parent)) {
        return @mkdir($target, 0755);
    }
    
    return false;
}

/**
 * 从文件头部获取数据
 *
 * @param string $file 文件路径
 * @param array $headers 要提取的头部信息
 * @return array 提取的数据
 */
function get_file_data($file, $headers) {
    $file_data = array();
    
    if (!file_exists($file)) {
        return $file_data;
    }
    
    $file_content = file_get_contents($file, false, null, 0, 8192);
    
    foreach ($headers as $key => $header) {
        $pattern = '/^[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi';
        if (preg_match($pattern, $file_content, $matches)) {
            $file_data[$key] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $matches[1]));
        } else {
            $file_data[$key] = '';
        }
    }
    
    return $file_data;
}

/**
 * 递归删除目录
 *
 * @param string $dir 目录路径
 * @return bool 是否成功
 */
function todo_delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            todo_delete_directory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * 检查是否为Todo错误对象
 *
 * @param mixed $thing 要检查的对象
 * @return bool 是否为错误对象
 */
function is_todo_error($thing) {
    return ($thing instanceof Todo_Error);
}
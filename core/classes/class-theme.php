<?php
/**
 * 主题类
 *
 * 处理主题的加载、激活和管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('THEMES_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME']);

    // 如果在 admin 目录中,移除 /admin 路径
    $path = str_replace('/admin', '', $path);

    $path = ($path === '/' || $path === '\\') ? '' : $path;
    $base = $protocol . $host . $path;
    define('THEMES_URL', rtrim($base, '/') . '/content/themes');
}

// 确保钩子函数已加载
if (!function_exists('todo_do_action')) {
    require_once ABSPATH . 'core/functions/todo-hooks.php';
}

class CMS_Theme
{
    /**
     * 主题目录
     *
     * @var string
     */
    private $theme_dir;

    /**
     * 主题URL
     *
     * @var string
     */
    private $theme_url;

    /**
     * 主题名称
     *
     * @var string
     */
    private $name;

    /**
     * 主题数据
     *
     * @var array
     */
    private $data = [];

    /**
     * 已加载的主题数据缓存
     * @var array
     */
    private static $_themes_data_cache = [];

    /**
     * 当前激活主题名称缓存
     * @var string|null
     */
    private static $_active_theme_name_cache = null;

    /**
     * 设备类型缓存（PC/Mobile）
     * @var string|null
     */
    private static $_device_type_cache = null;

    /**
     * 父主题名称
     * @var string|null
     */
    private $parent_theme = null;

    /**
     * 父主题对象
     * @var CMS_Theme|null
     */
    private $parent_theme_object = null;

    /**
     * 主题模板路径缓存（PC/Mobile）
     * @var array
     */
    private static $_template_path_cache = [];

    /**
     * 构造函数
     *
     * @param string $theme_name 主题名称
     */
    public function __construct($theme_name = '')
    {
        if (empty($theme_name)) {
            // 统一从主题系统获取当前激活主题（支持 active_theme 过滤器）
            $theme_name = function_exists('get_theme_name') ? get_theme_name() : 'default';
        }

        $this->name = $theme_name;
        $this->theme_dir = ABSPATH . THEMES_DIR . '/' . $theme_name;
        $this->theme_url = THEMES_URL . '/' . $theme_name;

        // 加载主题数据
        $this->load();
    }

    /**
     * 加载主题数据
     *
     * @return bool 是否成功加载
     */
    public function load()
    {
        // 检查内存缓存
        if (isset(self::$_themes_data_cache[$this->name])) {
            $this->data = self::$_themes_data_cache[$this->name];
            return true;
        }

        // 检查主题信息文件是否存在（支持根目录和pc目录）
        $theme_info_file = null;
        $possible_paths = array(
            $this->theme_dir . '/theme.json',           // 主题根目录
            $this->theme_dir . '/pc/theme.json',        // PC目录（支持PC/移动端分离结构）
            $this->theme_dir . '/mobile/theme.json'     // 移动端目录（备用）
        );
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $theme_info_file = $path;
                break;
            }
        }
        
        if (!$theme_info_file) {
            return false;
        }
        
        // 获取文件修改时间（用于缓存失效检测）
        $theme_file_mtime = filemtime($theme_info_file);
        
        // 检查持久化缓存（性能优化：避免重复读取文件）
        if (class_exists('Todo_Cache_Manager')) {
            // 先尝试使用最新缓存键
            $latest_cache_key = Todo_Cache_Manager::get('theme_data_latest_' . $this->name, 'themes');
            if ($latest_cache_key !== false) {
                $cached_data = Todo_Cache_Manager::get($latest_cache_key, 'themes');
                if ($cached_data !== false && is_array($cached_data)) {
                    // 验证缓存是否仍然有效（检查文件修改时间）
                    $cache_mtime = isset($cached_data['_cache_file_mtime']) ? $cached_data['_cache_file_mtime'] : 0;
                    if ($cache_mtime >= $theme_file_mtime) {
                        $this->data = $cached_data;
                        // 恢复父主题信息
                        if (isset($cached_data['parent'])) {
                            $this->parent_theme = $cached_data['parent'];
                        }
                        self::$_themes_data_cache[$this->name] = $cached_data;
                        return true;
                    }
                }
            }
            
            // 回退：尝试使用旧的缓存键格式（向后兼容）
            $cache_key = 'theme_data_' . $this->name;
            $cached_data = Todo_Cache_Manager::get($cache_key, 'themes');
            if ($cached_data !== false && is_array($cached_data)) {
                $cache_mtime = isset($cached_data['_cache_file_mtime']) ? $cached_data['_cache_file_mtime'] : 0;
                if ($cache_mtime >= $theme_file_mtime) {
                    $this->data = $cached_data;
                    if (isset($cached_data['parent'])) {
                        $this->parent_theme = $cached_data['parent'];
                    }
                    self::$_themes_data_cache[$this->name] = $cached_data;
                    return true;
                }
            }
        }

        // 深度优化：使用延迟加载器预加载关键资源
        if (class_exists('Todo_Theme_Lazy_Loader')) {
            Todo_Theme_Lazy_Loader::preload_critical($this->name);
        }

        // 检查主题目录是否存在
        if (!is_dir($this->theme_dir)) {
            return false;
        }

        // 读取主题信息（优先从延迟加载器获取）
        if (class_exists('Todo_Theme_Lazy_Loader')) {
            $theme_info = Todo_Theme_Lazy_Loader::load_resource($this->name, 'theme.json', true);
            if ($theme_info === null) {
                $theme_info = file_get_contents($theme_info_file);
            }
        } else {
            $theme_info = file_get_contents($theme_info_file);
        }
        
        $this->data = json_decode($theme_info, true);

        if (!$this->data) {
            return false;
        }

        // 加载父主题信息（如果存在）
        if (isset($this->data['parent']) && !empty($this->data['parent'])) {
            $this->parent_theme = $this->data['parent'];
        }

        // 添加缓存元数据（文件修改时间）
        $this->data['_cache_file_mtime'] = $theme_file_mtime;

        // 写入内存缓存
        self::$_themes_data_cache[$this->name] = $this->data;
        
        // 写入持久化缓存（TTL设为24小时，主题数据变化不频繁）
        // 使用文件修改时间作为缓存键的一部分，确保配置文件更新后缓存失效
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'theme_data_' . $this->name . '_' . $theme_file_mtime;
            Todo_Cache_Manager::set($cache_key, $this->data, 86400, 'themes'); // 24小时
            
            // 同时保存最新的缓存键引用（用于快速查找）
            Todo_Cache_Manager::set('theme_data_latest_' . $this->name, $cache_key, 86400, 'themes');
        }

        return true;
    }

    /**
     * 获取主题数据
     *
     * @param string $key 数据键名
     * @param mixed $default 默认值
     * @return mixed 主题数据
     */
    public function get_data($key = null, $default = null)
    {
        if ($key === null) {
            return $this->data;
        }

        return isset($this->data[$key]) ? $this->data[$key] : $default;
    }

    /**
     * 获取主题名称
     *
     * @return string 主题名称
     */
    public function get_name()
    {
        // 优先返回theme.json中的title，如果没有则返回目录名
        return $this->get_data('title', $this->name);
    }

    /**
     * 获取主题样式表名称（目录名）
     *
     * @return string 主题样式表名称
     */
    public function get_stylesheet()
    {
        return $this->name;
    }

    /**
     * Get theme tags
     * 
     * @return array
     */
    public function get_tags()
    {
        return isset($this->data['tags']) ? (array) $this->data['tags'] : array();
    }

    /**
     * 获取主题标题
     *
     * @return string 主题标题
     */
    public function get_title()
    {
        return $this->get_data('title', $this->name);
    }

    /**
     * 获取主题描述
     *
     * @return string 主题描述
     */
    public function get_description()
    {
        return $this->get_data('description', '');
    }

    /**
     * 获取主题版本
     *
     * @return string 主题版本
     */
    public function get_version()
    {
        return $this->get_data('version', '1.0.0');
    }

    /**
     * 获取主题作者
     *
     * @return string 主题作者
     */
    public function get_author()
    {
        return $this->get_data('author', '');
    }

    /**
     * 获取主题作者URL
     *
     * @return string 主题作者URL
     */
    public function get_author_url()
    {
        return $this->get_data('author_url', '');
    }

    /**
     * 获取主题URL
     *
     * @return string 主题URL
     */
    public function get_theme_url()
    {
        return $this->theme_url;
    }

    /**
     * 获取主题目录路径
     *
     * @return string 主题目录路径
     */
    public function get_theme_dir()
    {
        return $this->theme_dir;
    }

    /**
     * 获取主题模板目录路径（支持PC/移动端分离和主题继承）
     *
     * @param string|null $style 模板风格：'pc' 或 'mobile'，为空时自动检测
     * @return string 主题模板目录路径
     */
    public function get_template_directory($style = null)
    {
        // 自动检测设备类型
        if ($style === null) {
            $style = $this->detect_device_style();
        }

        // 缓存键
        $cache_key = $this->name . '_dir_' . $style;

        // 检查缓存
        if (isset(self::$_template_path_cache[$cache_key])) {
            return self::$_template_path_cache[$cache_key];
        }

        // 确定模板目录路径
        $template_dir = $this->theme_dir;
        
        // 如果存在PC/移动端分离目录结构，则使用对应目录
        if ($style === 'mobile') {
            $mobile_dir = $this->theme_dir . '/mobile';
            if (is_dir($mobile_dir)) {
                $template_dir = $mobile_dir;
            } else {
                // 当 mobile 目录不存在时，回退到 pc 目录（如果存在）
                $pc_dir = $this->theme_dir . '/pc';
                if (is_dir($pc_dir)) {
                    $template_dir = $pc_dir;
                }
            }
        } else {
            // PC端优先查找pc目录，如果不存在则使用根目录
            $pc_dir = $this->theme_dir . '/pc';
            if (is_dir($pc_dir)) {
                $template_dir = $pc_dir;
            }
        }

        // 写入缓存
        self::$_template_path_cache[$cache_key] = $template_dir;

        return $template_dir;
    }

    /**
     * 获取主题模板目录URL（支持PC/移动端分离）
     *
     * @param string|null $style 模板风格：'pc' 或 'mobile'，为空时自动检测
     * @return string 主题模板目录URL
     */
    public function get_template_directory_uri($style = null)
    {
        // 自动检测设备类型
        if ($style === null) {
            $style = $this->detect_device_style();
        }

        $dir = $this->get_template_directory($style);
        
        // 检查目录路径是否以 /mobile 或 /pc 结尾，并相应追加到URL
        $last_dir = basename($dir);
        
        if ($last_dir === 'mobile') {
             return $this->theme_url . '/mobile';
        } elseif ($last_dir === 'pc') {
             return $this->theme_url . '/pc';
        }

        return $this->theme_url;
    }

    /**
     * 检测设备类型（PC或Mobile）- 增强版：支持Cookie和URL参数强制切换
     *
     * @return string 'pc' 或 'mobile'
     */
    private function detect_device_style()
    {
        // 检查缓存
        if (self::$_device_type_cache !== null) {
            return self::$_device_type_cache;
        }

        $device_style = null;

        // 1. 优先检查URL参数（最高优先级）：?device=pc 或 ?device=mobile
        if (isset($_GET['device']) && in_array($_GET['device'], ['pc', 'mobile'], true)) {
            $device_style = $_GET['device'];
            
            // 将选择保存到Cookie（有效期30天）
            if (!headers_sent()) {
                setcookie('todo_device_style', $device_style, time() + (30 * 24 * 60 * 60), '/');
                $_COOKIE['todo_device_style'] = $device_style; // 立即生效
            }
        }
        // 2. 检查Cookie（次优先级）
        elseif (isset($_COOKIE['todo_device_style']) && in_array($_COOKIE['todo_device_style'], ['pc', 'mobile'], true)) {
            $device_style = $_COOKIE['todo_device_style'];
        }
        // 3. 自动检测设备类型（最低优先级）
        else {
            if (function_exists('todo_is_mobile')) {
                $is_mobile = todo_is_mobile();
            } else {
                // 备用检测逻辑
                $is_mobile = false;
                if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                    $is_mobile = (bool) preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Opera Mobi|Silk|Kindle|Mobile/i', $_SERVER['HTTP_USER_AGENT']);
                }
            }
            $device_style = $is_mobile ? 'mobile' : 'pc';
        }

        // 缓存结果
        self::$_device_type_cache = $device_style;

        if (!headers_sent()) {
            @header('Vary: User-Agent', false);
        }

        return self::$_device_type_cache;
    }
    
    /**
     * 设置设备类型（用于测试或强制切换）
     * 
     * @param string $style 'pc' 或 'mobile'
     */
    public static function set_device_style($style)
    {
        if (in_array($style, ['pc', 'mobile'], true)) {
            self::$_device_type_cache = $style;
        }
    }
    
    /**
     * 清除设备类型缓存（强制重新检测）
     */
    public static function clear_device_cache()
    {
        self::$_device_type_cache = null;
    }

    /**
     * 获取当前设备类型
     *
     * @return string 'pc' 或 'mobile'
     */
    public function get_device_style()
    {
        return $this->detect_device_style();
    }

    /**
     * 获取主题截图URL
     *
     * @return string 主题截图URL
     */
    public function get_screenshot_url()
    {
        // 支持多种截图格式
        $formats = ['png', 'jpg', 'jpeg', 'svg'];

        foreach ($formats as $format) {
            $screenshot_file = $this->theme_dir . '/pc/screenshot.' . $format;
            if (file_exists($screenshot_file)) {
                return $this->theme_url . '/pc/screenshot.' . $format;
            }
        }

        // 如果没有找到截图，返回默认值
        return $this->theme_url . '/screenshot.png';
    }

    /**
     * 检查主题是否支持特定功能
     *
     * @param string $feature 功能名称
     * @return bool 是否支持
     */
    public function supports($feature)
    {
        $supports = $this->get_data('supports', []);
        return in_array($feature, $supports);
    }

    /**
     * 获取父主题对象
     *
     * @return CMS_Theme|null
     */
    public function get_parent_theme()
    {
        if ($this->parent_theme_object !== null) {
            return $this->parent_theme_object;
        }

        if (empty($this->parent_theme)) {
            return null;
        }

        try {
            $this->parent_theme_object = new CMS_Theme($this->parent_theme);
            return $this->parent_theme_object;
        } catch (Exception $e) {
            error_log("TodoCMS Theme: Failed to load parent theme '{$this->parent_theme}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取主题模板文件路径（支持PC/移动端分离、主题继承和多种扩展名）
     *
     * @param string $template 模板名称（不含扩展名）
     * @param string|null $style 模板风格：'pc' 或 'mobile'，为空时自动检测
     * @return string 模板文件路径，不存在则返回空字符串
     */
    public function get_template_path($template, $style = null)
    {
        // 缓存键
        $cache_key = $this->name . '_' . ($style ?: $this->detect_device_style()) . '_' . $template;

        // 检查缓存
        if (isset(self::$_template_path_cache[$cache_key])) {
            $cached_path = self::$_template_path_cache[$cache_key];
            // 验证缓存的文件是否仍存在
            if ($cached_path && file_exists($cached_path)) {
                return $cached_path;
            }
        }

        // 自动检测设备类型
        if ($style === null) {
            $style = $this->detect_device_style();
        }

        // 获取模板目录
        $template_dir = $this->get_template_directory($style);

        // 支持的模板文件扩展名（优先级顺序）
        $extensions = ['html', 'htm', 'php'];

        // 尝试查找模板文件
        foreach ($extensions as $ext) {
            $template_file = $template_dir . '/' . $template . '.' . $ext;
            if (file_exists($template_file)) {
                // 写入缓存
                self::$_template_path_cache[$cache_key] = $template_file;
                return $template_file;
            }
        }

        // 如果PC/移动端分离目录中未找到，尝试在根目录查找（兼容旧主题）
        if ($template_dir !== $this->theme_dir) {
            foreach ($extensions as $ext) {
                $template_file = $this->theme_dir . '/' . $template . '.' . $ext;
                if (file_exists($template_file)) {
                    // 写入缓存
                    self::$_template_path_cache[$cache_key] = $template_file;
                    return $template_file;
                }
            }
        }

        // 如果当前主题未找到，尝试从父主题加载（主题继承机制）
        $parent_theme = $this->get_parent_theme();
        if ($parent_theme) {
            $parent_path = $parent_theme->get_template_path($template, $style);
            if (!empty($parent_path)) {
                // 缓存父主题的路径（使用特殊键名标记，避免覆盖当前主题的缓存）
                $parent_cache_key = 'parent_' . $cache_key;
                self::$_template_path_cache[$parent_cache_key] = $parent_path;
                return $parent_path;
            }
        }

        // 未找到模板文件
        return '';
    }

    /**
     * 加载主题函数文件
     *
     * @return bool 是否成功加载
     */
    public function load_functions()
    {
        $functions_file = $this->theme_dir . '/functions.php';

        if (file_exists($functions_file)) {
            require_once $functions_file;
            return true;
        }

        return false;
    }

    /**
     * 激活主题
     *
     * @return bool 是否成功激活
     */
    public function activate()
    {
        // 检查主题是否存在
        if (!is_dir($this->theme_dir)) {
            return false;
        }

        // 更新激活主题选项
        Todo_Config_Manager::set('active_theme', $this->name);

        // 执行主题激活钩子
        todo_do_action('theme_activated', $this->name);

        return true;
    }

    /**
     * 获取所有可用主题
     *
     * @return array 主题列表
     */
    public static function get_themes()
    {
        $themes = [];
        $themes_dir = ABSPATH . THEMES_DIR;

        if (!is_dir($themes_dir)) {
            return $themes;
        }

        // 获取主题目录列表
        $theme_dirs = scandir($themes_dir);

        foreach ($theme_dirs as $theme_name) {
            // 跳过特殊目录
            if ($theme_name === '.' || $theme_name === '..') {
                continue;
            }

            $theme_path = $themes_dir . '/' . $theme_name;

            // 确保是目录
            if (!is_dir($theme_path)) {
                continue;
            }

            // 创建主题对象
            $theme = new CMS_Theme($theme_name);

            // 如果主题加载成功，添加到列表
            if ($theme->get_data()) {
                $themes[$theme_name] = [
                    'name' => $theme_name,
                    'title' => $theme->get_title(),
                    'description' => $theme->get_description(),
                    'version' => $theme->get_version(),
                    'author' => $theme->get_author(),
                    'author_url' => $theme->get_author_url(),
                    'screenshot' => $theme->get_screenshot_url(),
                ];
            }
        }

        return $themes;
    }

    /**
     * 获取当前激活的主题
     *
     * @return CMS_Theme 主题对象
     */
    public static function get_active_theme()
    {
        // 缓存 key 包含当前 locale，防止持久化 worker 进程跨请求污染
        // 同时防御旧格式（字符串）残留：旧代码存储为 string，新代码需要 array
        if (!is_array(self::$_active_theme_name_cache)) {
            self::$_active_theme_name_cache = [];
        }
        $locale = (function_exists('current_locale') ? current_locale() : 'zh-CN');
        if (isset(self::$_active_theme_name_cache[$locale])) {
            return new CMS_Theme(self::$_active_theme_name_cache[$locale]);
        }

        // 检查持久化缓存（性能优化：避免重复查询数据库）
        // 注意：缓存仅应保存“基础激活主题”（DB中的 active），不能跳过 active_theme 过滤器，
        // 否则会导致预览/按语言映射等“请求级主题”失效。
        $base_theme = null;
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'active_theme_name';
            $cached_theme = Todo_Cache_Manager::get($cache_key, 'themes');
            if ($cached_theme !== false && is_string($cached_theme)) {
                $base_theme = $cached_theme;
            }
        }

        global $tododb;
        $db_active_theme = $base_theme ?: 'default';

        // 首先尝试从数据库themes表获取激活的主题
        if ($base_theme === null && $tododb) {
            $active_theme_from_db = $tododb->get_var(
                "SELECT directory FROM " . TABLE_PREFIX . "themes WHERE status = 'active' LIMIT 1"
            );
            if ($active_theme_from_db) {
                $db_active_theme = $active_theme_from_db;
            }
        }

        // 通过 active_theme 过滤器应用按语言映射/预览等请求级主题
        $active_theme = $db_active_theme;
        if (function_exists('apply_filters')) {
            $active_theme = apply_filters('active_theme', $active_theme);
        }

        self::$_active_theme_name_cache[$locale] = $active_theme;
        
        // 写入持久化缓存（TTL设为24小时，主题配置变化不频繁）
        // 预览模式下不应写入缓存，避免“预览主题”污染全站缓存。
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'active_theme_name';
            $is_preview = !empty($GLOBALS['preview_theme_name']) || (isset($_GET['preview']) && $_GET['preview'] == '1');
            if (!$is_preview) {
                Todo_Cache_Manager::set($cache_key, $db_active_theme, 86400, 'themes'); // 24小时
            }
        }

        // 统一以 themes 表为准，但允许过滤器覆盖以支持语言映射
        return new CMS_Theme($active_theme);
    }

    /**
     * 重置当前激活主题缓存
     */
    public static function reset_active_theme_cache()
    {
        self::$_active_theme_name_cache = null;
        self::$_template_path_cache = [];
        self::$_device_type_cache = null;

        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::delete('active_theme_name', 'themes');
        }
    }

    /**
     * 清除模板路径缓存
     */
    public static function clear_template_path_cache()
    {
        self::$_template_path_cache = [];
        self::$_device_type_cache = null;
    }

    /**
     * 清除主题数据缓存
     * 
     * @param string|null $theme_name 主题名称，为空则清除所有主题缓存
     */
    public static function clear_theme_cache($theme_name = null)
    {
        if ($theme_name !== null) {
            // 清除指定主题的缓存
            unset(self::$_themes_data_cache[$theme_name]);
            
            if (class_exists('Todo_Cache_Manager')) {
                // 清除持久化缓存
                Todo_Cache_Manager::delete('theme_data_latest_' . $theme_name, 'themes');
                // 清除所有相关的缓存键（遍历可能的缓存键）
                // 注意：由于无法精确匹配通配符，这里清除常见的缓存键格式
                for ($i = 0; $i < 100; $i++) {
                    $possible_key = 'theme_data_' . $theme_name . '_' . $i;
                    Todo_Cache_Manager::delete($possible_key, 'themes');
                }
            }
        } else {
            // 清除所有主题缓存
            self::$_themes_data_cache = [];
            
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::flush('themes');
            }
        }
        
        // 同时清除模板路径缓存
        self::clear_template_path_cache();
    }

    /**
     * 获取父主题名称
     *
     * @return string|null
     */
    public function get_parent_theme_name()
    {
        return $this->parent_theme;
    }

    /**
     * 检查是否有父主题
     *
     * @return bool
     */
    public function has_parent_theme()
    {
        return !empty($this->parent_theme);
    }

    /**
     * 加载主题资源
     */
    public function enqueue_assets()
    {
        // 加载样式表
        $style_file = $this->theme_dir . '/style.css';
        if (file_exists($style_file)) {
            echo '<link rel="stylesheet" href="' . $this->theme_url . '/style.css?ver=' . $this->get_version() . '" type="text/css" media="all" />';
        }

        // 加载脚本
        $script_file = $this->theme_dir . '/script.js';
        if (file_exists($script_file)) {
            echo '<script src="' . $this->theme_url . '/script.js?ver=' . $this->get_version() . '" type="text/javascript"></script>';
        }

        // 执行资源加载钩子
        todo_do_action('theme_enqueue_assets');
    }

    /**
     * 渲染模板
     *
     * @param string $template 模板名称
     * @param array $args 模板参数
     * @return bool 是否成功渲染
     */
    public function render_template($template, $args = [])
    {
        $template_file = $this->get_template_path($template);

        if (empty($template_file)) {
            return false;
        }

        // 提取参数到变量
        if (!empty($args)) {
            extract($args);
        }

        // 包含模板文件
        include $template_file;

        // 获取缓冲内容并清除缓冲
        $content = ob_get_clean();

        // 输出内容
        echo $content;

        return true;
    }

    /**
     * 获取模板部分
     *
     * @param string $part 部分名称
     * @param array $args 模板参数
     * @return bool 是否成功获取
     */
    public function get_template_part($part, $args = [])
    {
        $part_file = $this->theme_dir . '/parts/' . $part . '.php';

        if (!file_exists($part_file)) {
            return false;
        }

        // 提取参数到变量
        if (!empty($args)) {
            extract($args);
        }

        // 包含部分文件
        include $part_file;

        return true;
    }

    /**
     * 获取主题选项
     *
     * @param string $option 选项名称
     * @param mixed $default 默认值
     * @return mixed 选项值
     */
    public function get_theme_option($option, $default = null)
    {
        if (class_exists('Todo_Config_Manager')) {
            $theme_options = Todo_Config_Manager::get('theme_' . $this->name . '_options', []);
        } elseif (class_exists('CMS_Option')) {
            $theme_options = CMS_Option::get('theme_' . $this->name . '_options', []);
        } else {
            $theme_options = [];
        }
        return isset($theme_options[$option]) ? $theme_options[$option] : $default;
    }

    /**
     * 更新主题选项
     *
     * @param string $option 选项名称
     * @param mixed $value 选项值
     * @return bool 是否成功更新
     */
    public function update_theme_option($option, $value)
    {
        if (class_exists('Todo_Config_Manager')) {
            $theme_options = Todo_Config_Manager::get('theme_' . $this->name . '_options', []);
            $theme_options[$option] = $value;
            return Todo_Config_Manager::set('theme_' . $this->name . '_options', $theme_options);
        } elseif (class_exists('CMS_Option')) {
            $theme_options = CMS_Option::get('theme_' . $this->name . '_options', []);
            $theme_options[$option] = $value;
            return CMS_Option::update('theme_' . $this->name . '_options', $theme_options);
        }
        return false;
    }

    /**
     * 删除主题选项
     *
     * @param string $option 选项名称
     * @return bool 是否成功删除
     */
    public function delete_theme_option($option)
    {
        if (class_exists('Todo_Config_Manager')) {
            $theme_options = Todo_Config_Manager::get('theme_' . $this->name . '_options', []);
            if (isset($theme_options[$option])) {
                unset($theme_options[$option]);
                return Todo_Config_Manager::set('theme_' . $this->name . '_options', $theme_options);
            }
        } elseif (class_exists('CMS_Option')) {
            $theme_options = CMS_Option::get('theme_' . $this->name . '_options', []);
            if (isset($theme_options[$option])) {
                unset($theme_options[$option]);
                return CMS_Option::update('theme_' . $this->name . '_options', $theme_options);
            }
        }

        return false;
    }

    /**
     * 获取主题菜单位置
     *
     * @return array 菜单位置
     */
    public function get_menu_locations()
    {
        return $this->get_data('menu_locations', []);
    }

    /**
     * 获取主题小工具区域
     *
     * @return array 小工具区域
     */
    public function get_widget_areas()
    {
        return $this->get_data('widget_areas', []);
    }

    /**
     * 获取主题自定义设置
     *
     * @return array 自定义设置
     */
    public function get_customizer_settings()
    {
        return $this->get_data('customizer', []);
    }
}

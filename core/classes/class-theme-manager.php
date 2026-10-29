<?php
/**
 * TodoCMS 主题管理器
 * 
 * 管理主题的安装、激活、配置和模板渲染
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Theme_Manager
{

    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;

    /**
     * 主题目录
     */
    private static $themes_dir = THEMES_DIR;

    /**
     * 当前主题
     * @var array
     */
    private static $current_theme = null;

    /**
     * 主题缓存
     * @var array
     */
    private static $themes_cache = array();

    /**
     * 模板变量
     * @var array
     */
    private static $template_vars = array();

    /**
     * 主题状态常量
     */
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 0;

    /**
     * 初始化主题管理器
     */
    public static function init()
    {
        global $tododb;
        self::$tododb = $tododb;

        // 加载当前主题
        self::load_current_theme();

        // 注册模板函数
        self::register_template_functions();
    }

    /**
     * 获取所有主题
     * 
     * @param bool $include_inactive 是否包含未激活的主题
     * @return array
     */
    public static function get_themes($include_inactive = true)
    {
        $themes = array();

        // 检查themes表是否存在，防止在系统安装前查询不存在的表
        if (self::$tododb->table_exists(TABLE_PREFIX . 'themes')) {
            // 从数据库获取主题信息
            $where_sql = $include_inactive ? '' : 'WHERE status = 1';
            $query = "SELECT * FROM " . TABLE_PREFIX . "themes {$where_sql} ORDER BY name";
            $results = self::$tododb->get_results($query);
        } else {
            $results = array();
        }

        if ($results) {
            foreach ($results as $row) {
                // 获取主题配置
                $theme_data = (array) $row;
                $theme_data['config'] = self::get_theme_config($row->directory);
                $theme_data['meta'] = self::get_theme_meta($row->id);
                $themes[$row->directory] = $theme_data;
            }
        }

        // 扫描主题目录，查找未安装的主题
        $themes_path = ABSPATH . self::$themes_dir;
        if (is_dir($themes_path)) {
            $dirs = scandir($themes_path);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir($themes_path . '/' . $dir)) {
                    continue;
                }

                if (!isset($themes[$dir])) {
                    // 未安装的主题
                    $config = self::get_theme_config($dir);
                    if ($config) {
                        $themes[$dir] = array(
                            'id' => 0,
                            'name' => $config['name'] ?? $dir,
                            'directory' => $dir,
                            'version' => $config['version'] ?? '1.0.0',
                            'author' => $config['author'] ?? '',
                            'description' => $config['description'] ?? '',
                            'status' => 0,
                            'installed' => false,
                            'config' => $config,
                            'meta' => array()
                        );
                    }
                }
            }
        }

        return $themes;
    }

    /**
     * 获取主题信息
     * 
     * @param string $theme_dir 主题目录名
     * @return array|null
     */
    public static function get_theme($theme_dir)
    {
        if (isset(self::$themes_cache[$theme_dir])) {
            return self::$themes_cache[$theme_dir];
        }

        // 尝试从持久化缓存获取
        if (class_exists('Todo_Cache_Manager')) {
            $cache_key = 'theme_data_' . $theme_dir;
            $cached_data = Todo_Cache_Manager::get($cache_key, 'themes');
            if ($cached_data !== false && is_array($cached_data)) {
                self::$themes_cache[$theme_dir] = $cached_data;
                return $cached_data;
            }
        }

        // 检查themes表是否存在，防止在系统安装前查询不存在的表
        if (self::$tododb->table_exists(TABLE_PREFIX . 'themes')) {
            // 使用参数化查询以兼容 Extended_PDO 与 CMS_DB 两种实现
            $query = "SELECT * FROM " . TABLE_PREFIX . "themes WHERE directory = ?";
            $row = self::$tododb->get_row($query, [$theme_dir]);
        } else {
            $row = null;
        }

        if ($row) {
            // 获取主题配置和元数据
            $theme_data = (array) $row;
            $theme_data['config'] = self::get_theme_config($theme_dir);
            $theme_data['meta'] = self::get_theme_meta($row->id);

            self::$themes_cache[$theme_dir] = $theme_data;

            // 保存到持久化缓存
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set('theme_data_' . $theme_dir, $theme_data, 0, 'themes');
            }

            return $theme_data;
        }

        // 检查是否存在未安装的主题
        $config = self::get_theme_config($theme_dir);
        if ($config) {
            return array(
                'id' => 0,
                'name' => $config['name'] ?? $theme_dir,
                'directory' => $theme_dir,
                'version' => $config['version'] ?? '1.0.0',
                'author' => $config['author'] ?? '',
                'description' => $config['description'] ?? '',
                'status' => 0,
                'installed' => false,
                'config' => $config,
                'meta' => array()
            );
        }

        return null;
    }

    /**
     * 安装主题
     * 
     * @param string $theme_dir 主题目录名
     * @return bool|array 成功返回true，失败返回错误信息
     */
    public static function install_theme($theme_dir)
    {
        // 检查主题是否存在
        $config = self::get_theme_config($theme_dir);
        if (!$config) {
            return array('error' => '主题不存在或配置文件无效');
        }

        // 检查是否已安装
        $existing = self::get_theme($theme_dir);
        if ($existing && isset($existing['installed']) && $existing['installed']) {
            return array('error' => '主题已安装');
        }

        // 验证主题结构
        $validation = self::validate_theme_structure($theme_dir);
        if ($validation !== true) {
            return array('error' => $validation);
        }

        // 安装主题到数据库
        $theme_id = self::$tododb->insert(
            TABLE_PREFIX . 'themes',
            array(
                'name' => $config['name'],
                'directory' => $theme_dir,
                'version' => $config['version'],
                'author' => $config['author'],
                'description' => $config['description'],
                'status' => 0,
                'installed_at' => current_time('mysql')
            )
        );

        if ($theme_id) {

            // 保存主题配置
            if (!empty($config['settings'])) {
                foreach ($config['settings'] as $key => $value) {
                    self::save_theme_meta($theme_id, $key, $value);
                }
            }

            // 运行主题安装脚本
            self::run_theme_install_script($theme_dir);

            // 清除缓存
            unset(self::$themes_cache[$theme_dir]);

            return true;
        }

        return array('error' => '安装失败');
    }

    /**
     * 激活主题
     * 
     * @param string $theme_dir 主题目录名
     * @return bool|array
     */
    public static function activate_theme($theme_dir)
    {
        $theme = self::get_theme($theme_dir);
        if (!$theme) {
            return array('error' => '主题不存在');
        }

        if (!isset($theme['installed']) || !$theme['installed']) {
            // 先安装主题
            $install_result = self::install_theme($theme_dir);
            if ($install_result !== true) {
                return $install_result;
            }
            $theme = self::get_theme($theme_dir);
        }

        // 开始事务
        self::$tododb->begin_transaction();

        try {
            // 停用当前主题
            $deactivate_query = "UPDATE " . TABLE_PREFIX . "themes SET status = 0";
            self::$tododb->query($deactivate_query);

            // 激活新主题
            $updated = self::$tododb->update(
                TABLE_PREFIX . 'themes',
                array('status' => 1),
                array('directory' => $theme_dir)
            );

            if (!$updated) {
                throw new Exception('Failed to activate theme');
            }

            // 不再写入 system_config 或 options 的 active_theme，改为仅更新 themes 表状态
            // 读取统一走 CMS_Theme::get_active_theme() 与 get_theme_name()

            // 提交事务
            self::$tododb->commit();

            // 运行主题激活脚本
            self::run_theme_activation_script($theme_dir);

            // 清除缓存
            self::$themes_cache = array();
            self::$current_theme = null;

            return true;

        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Theme activation failed: ' . $e->getMessage());
            return array('error' => '激活失败');
        }
    }

    /**
     * 停用主题
     * 
     * @param string $theme_dir 主题目录名
     * @return bool|array
     */
    public static function deactivate_theme($theme_dir)
    {
        $theme = self::get_theme($theme_dir);
        if (!$theme || $theme['status'] != self::STATUS_ACTIVE) {
            return array('error' => '主题未激活');
        }

        // 不能停用当前唯一的主题
        $active_themes = self::get_themes(false);
        if (count($active_themes) <= 1) {
            return array('error' => '不能停用唯一的主题');
        }

        $updated = self::$tododb->update(
            TABLE_PREFIX . 'themes',
            array('status' => 0),
            array('directory' => $theme_dir)
        );

        if ($updated) {
            // 运行主题停用脚本
            self::run_theme_deactivation_script($theme_dir);

            // 清除缓存
            unset(self::$themes_cache[$theme_dir]);

            return true;
        }

        return array('error' => '停用失败');
    }

    /**
     * 卸载主题
     * 
     * @param string $theme_dir 主题目录名
     * @param bool $delete_files 是否删除文件
     * @return bool|array
     */
    public static function uninstall_theme($theme_dir, $delete_files = false)
    {
        $theme = self::get_theme($theme_dir);
        if (!$theme) {
            return array('error' => '主题不存在');
        }

        if ($theme['status'] == self::STATUS_ACTIVE) {
            return array('error' => '不能卸载激活的主题');
        }

        // 开始事务
        self::$tododb->begin_transaction();

        try {
            // 删除主题元数据
            self::$tododb->delete(
                TABLE_PREFIX . 'theme_meta',
                array('theme_id' => $theme['id'])
            );

            // 删除主题记录
            $deleted = self::$tododb->delete(
                TABLE_PREFIX . 'themes',
                array('id' => $theme['id'])
            );

            if (!$deleted) {
                throw new Exception('Failed to uninstall theme');
            }

            // 运行主题卸载脚本
            self::run_theme_uninstall_script($theme_dir);

            // 删除主题文件
            if ($delete_files) {
                $theme_path = ABSPATH . self::$themes_dir . '/' . $theme_dir;
                if (is_dir($theme_path)) {
                    self::delete_directory($theme_path);
                }
            }

            // 提交事务
            self::$tododb->commit();

            // 清除缓存
            unset(self::$themes_cache[$theme_dir]);

            return true;

        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Theme uninstallation failed: ' . $e->getMessage());
            return array('error' => '卸载失败');
        }
    }

    /**
     * 获取当前主题
     * 
     * @return array|null
     */
    public static function get_current_theme()
    {
        if (self::$current_theme === null) {
            self::load_current_theme();
        }

        return self::$current_theme;
    }

    /**
     * 渲染模板
     * 
     * @param string $template 模板名称
     * @param array $vars 模板变量
     * @param bool $return 是否返回内容
     * @return string|void
     */
    public static function render_template($template, $vars = array(), $return = false)
    {
        $current_theme = self::get_current_theme();
        if (!$current_theme) {
            if ($return) {
                return '<!-- No active theme -->';
            }
            echo '<!-- No active theme -->';
            return;
        }

        // 合并模板变量
        $template_vars = array_merge(self::$template_vars, $vars);

        // 查找模板文件（优先 HTML/HTM，再 PHP）
        $template_file = self::locate_template($template, $current_theme['directory']);
        if (!$template_file) {
            $error_msg = "<!-- Template '{$template}' not found -->";
            if ($return) {
                return $error_msg;
            }
            echo $error_msg;
            return;
        }

        $ext = strtolower(pathinfo($template_file, PATHINFO_EXTENSION));
        if (in_array($ext, array('html', 'htm'))) {
            // HTML 模板使用模板解析器
            if (function_exists('parse_html_template')) {
                $output = parse_html_template($template_file, $template_vars);
            } else {
                $output = file_get_contents($template_file);
            }
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }

        // PHP 模板
        extract($template_vars, EXTR_SKIP);
        if ($return) {
            ob_start();
            include $template_file;
            return ob_get_clean();
        }
        include $template_file;
    }

    /**
     * 查找模板文件
     * 
     * @param string $template 模板名称
     * @param string $theme_dir 主题目录
     * @return string|false
     */
    public static function locate_template($template, $theme_dir = null)
    {
        if (!$theme_dir) {
            $current_theme = self::get_current_theme();
            $theme_dir = $current_theme ? $current_theme['directory'] : null;
        }

        if (!$theme_dir) {
            return false;
        }

        $theme_path = ABSPATH . self::$themes_dir . '/' . $theme_dir;

        // 可能的模板文件路径（优先 HTML/HTM，再 PHP）
        $possible_files = array(
            $theme_path . '/' . $template . '.html',
            $theme_path . '/' . $template . '.php',
        );

        foreach ($possible_files as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }

        return false;
    }

    /**
     * 设置模板变量
     * 
     * @param string|array $key 变量名或变量数组
     * @param mixed $value 变量值
     */
    public static function set_template_var($key, $value = null)
    {
        if (is_array($key)) {
            self::$template_vars = array_merge(self::$template_vars, $key);
        } else {
            self::$template_vars[$key] = $value;
        }
    }

    /**
     * 获取模板变量
     * 
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get_template_var($key, $default = null)
    {
        return isset(self::$template_vars[$key]) ? self::$template_vars[$key] : $default;
    }

    /**
     * 获取主题配置
     * 
     * @param string $theme_dir 主题目录名
     * @return array|null
     */
    private static function get_theme_config($theme_dir)
    {
        $theme_base_path = TODO_ROOT . '/' . self::$themes_dir . '/' . $theme_dir;
        
        // 按优先级查找 theme.json 文件
        $json_config_paths = array(
            $theme_base_path . '/theme.json',           // 主题根目录
            $theme_base_path . '/pc/theme.json',        // PC目录（支持PC/移动端分离结构）
            $theme_base_path . '/mobile/theme.json'     // 移动端目录（备用）
        );

        foreach ($json_config_paths as $config_file) {
            if (file_exists($config_file)) {
                $config_content = file_get_contents($config_file);
                $config = json_decode($config_content, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $config;
                }
            }
        }

        // 尝试读取PHP配置文件（按优先级）
        $php_config_paths = array(
            $theme_base_path . '/theme.php',            // 主题根目录
            $theme_base_path . '/pc/theme.php',         // PC目录
            $theme_base_path . '/mobile/theme.php'      // 移动端目录
        );

        foreach ($php_config_paths as $php_config_file) {
            if (file_exists($php_config_file)) {
                $config = include $php_config_file;
                if (is_array($config)) {
                    return $config;
                }
            }
        }

        return null;
    }

    /**
     * 验证主题结构
     * 
     * @param string $theme_dir 主题目录名
     * @return bool|string
     */
    private static function validate_theme_structure($theme_dir)
    {
        $theme_path = ABSPATH . self::$themes_dir . '/' . $theme_dir;

        if (!is_dir($theme_path)) {
            return '主题目录不存在';
        }

        // 检查必需文件：允许 index.html 或 index.php（支持根目录和pc目录）
        $has_index = (file_exists($theme_path . '/index.html') || 
                     file_exists($theme_path . '/index.php') ||
                     file_exists($theme_path . '/pc/index.html') || 
                     file_exists($theme_path . '/pc/index.php'));
        if (!$has_index) {
            return '缺少必需文件: index.html 或 index.php';
        }
        
        // 检查 style.css（支持根目录和pc目录下的assets/css目录）
        $has_style = (file_exists($theme_path . '/style.css') ||
                     file_exists($theme_path . '/pc/assets/css/style.css') ||
                     file_exists($theme_path . '/assets/css/style.css'));
        if (!$has_style) {
            return '缺少必需文件: style.css';
        }

        // 检查配置文件（支持根目录和pc目录）
        $config_exists = file_exists($theme_path . '/theme.json') ||
            file_exists($theme_path . '/theme.php') ||
            file_exists($theme_path . '/pc/theme.json') ||
            file_exists($theme_path . '/pc/theme.php');

        if (!$config_exists) {
            return '缺少主题配置文件 (theme.json 或 theme.php)';
        }

        return true;
    }

    /**
     * 加载当前主题
     */
    private static function load_current_theme()
    {
        $active_theme_dir = get_theme_name();

        if ($active_theme_dir) {
            self::$current_theme = self::get_theme($active_theme_dir);
        }

        // 如果没有激活的主题，尝试从数据库获取
        if (!self::$current_theme) {
            // 尝试从缓存获取 fallback 主题
            if (class_exists('Todo_Cache_Manager')) {
                $cached_fallback = Todo_Cache_Manager::get('active_theme_fallback', 'themes');
                if ($cached_fallback !== false && is_array($cached_fallback)) {
                    self::$current_theme = $cached_fallback;
                    return;
                }
            }

            if (!self::$tododb->table_exists(TABLE_PREFIX . 'themes')) {
                return null;
            }
            $query = "SELECT * FROM " . TABLE_PREFIX . "themes WHERE status = 1 LIMIT 1";
            $row = self::$tododb->get_row($query);

            if ($row) {
                $theme_data = (array) $row;
                $theme_data['config'] = self::get_theme_config($row->directory);
                $theme_data['meta'] = self::get_theme_meta($row->id);
                self::$current_theme = $theme_data;

                // 保存到缓存
                if (class_exists('Todo_Cache_Manager')) {
                    Todo_Cache_Manager::set('active_theme_fallback', $theme_data, 0, 'themes');
                }

                // 这里不再写入 active_theme 到 system_config/options，保持以 themes 表为唯一来源
            }
        }
    }

    /**
     * 保存主题元数据
     * 
     * @param int $theme_id 主题ID
     * @param string $meta_key 元数据键
     * @param mixed $meta_value 元数据值
     * @return bool
     */
    public static function save_theme_meta($theme_id, $meta_key, $meta_value)
    {
        $meta_value_json = is_array($meta_value) ? json_encode($meta_value) : $meta_value;

        // 先尝试更新
        $updated = self::$tododb->update(
            TABLE_PREFIX . 'theme_meta',
            array(
                'meta_value' => $meta_value_json,
                'updated_at' => date('Y-m-d H:i:s')
            ),
            array(
                'theme_id' => $theme_id,
                'meta_key' => $meta_key
            )
        );

        // 如果更新失败（记录不存在），则插入新记录
        if (!$updated) {
            return self::$tododb->insert(
                TABLE_PREFIX . 'theme_meta',
                array(
                    'theme_id' => $theme_id,
                    'meta_key' => $meta_key,
                    'meta_value' => $meta_value_json,
                    'created_at' => date('Y-m-d H:i:s')
                )
            );
        }

        return true;
    }

    /**
     * 获取主题元数据
     * 
     * @param int $theme_id 主题ID
     * @param string $meta_key 元数据键（可选）
     * @return array|string|null
     */
    public static function get_theme_meta($theme_id, $meta_key = null)
    {
        if ($meta_key) {
            $query = "SELECT meta_value FROM " . TABLE_PREFIX . "theme_meta 
                      WHERE theme_id = ? AND meta_key = ?";
            $row = self::$tododb->get_row($query, array($theme_id, $meta_key));

            if ($row) {
                $value = $row->meta_value;
                $decoded = json_decode($value, true);
                return $decoded !== null ? $decoded : $value;
            }

            return null;
        } else {
            $query = "SELECT meta_key, meta_value FROM " . TABLE_PREFIX . "theme_meta WHERE theme_id = ?";
            $results = self::$tododb->get_results($query, array($theme_id));

            $meta = array();
            if ($results) {
                foreach ($results as $row) {
                    $value = $row->meta_value;
                    $decoded = json_decode($value, true);
                    $meta[$row->meta_key] = $decoded !== null ? $decoded : $value;
                }
            }

            return $meta;
        }
    }

    /**
     * 删除主题元数据
     * 
     * @param int $theme_id 主题ID
     * @param string $meta_key 元数据键（可选）
     * @return bool
     */
    public static function delete_theme_meta($theme_id, $meta_key = null)
    {
        if ($meta_key) {
            return self::$tododb->delete(
                TABLE_PREFIX . 'theme_meta',
                array(
                    'theme_id' => $theme_id,
                    'meta_key' => $meta_key
                )
            );
        } else {
            return self::$tododb->delete(
                TABLE_PREFIX . 'theme_meta',
                array('theme_id' => $theme_id)
            );
        }
    }

    /**
     * 运行主题安装脚本
     * 
     * @param string $theme_dir 主题目录名
     */
    private static function run_theme_install_script($theme_dir)
    {
        $install_script = TODO_ROOT . '/' . self::$themes_dir . '/' . $theme_dir . '/install.php';

        if (file_exists($install_script)) {
            try {
                include_once $install_script;

                if (function_exists('todo_theme_install')) {
                    todo_theme_install();
                }
            } catch (Exception $e) {
                todo_log_error('Theme install script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行主题激活脚本
     * 
     * @param string $theme_dir 主题目录名
     */
    private static function run_theme_activation_script($theme_dir)
    {
        $activation_script = TODO_ROOT . '/' . self::$themes_dir . '/' . $theme_dir . '/functions.php';

        if (file_exists($activation_script)) {
            try {
                include_once $activation_script;

                if (function_exists('todo_theme_activate')) {
                    todo_theme_activate();
                }
            } catch (Exception $e) {
                todo_log_error('Theme activation script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行主题停用脚本
     * 
     * @param string $theme_dir 主题目录名
     */
    private static function run_theme_deactivation_script($theme_dir)
    {
        $functions_file = TODO_ROOT . '/' . self::$themes_dir . '/' . $theme_dir . '/functions.php';

        if (file_exists($functions_file)) {
            try {
                include_once $functions_file;

                if (function_exists('todo_theme_deactivate')) {
                    todo_theme_deactivate();
                }
            } catch (Exception $e) {
                todo_log_error('Theme deactivation script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 运行主题卸载脚本
     * 
     * @param string $theme_dir 主题目录名
     */
    private static function run_theme_uninstall_script($theme_dir)
    {
        $uninstall_script = TODO_ROOT . '/' . self::$themes_dir . '/' . $theme_dir . '/uninstall.php';

        if (file_exists($uninstall_script)) {
            try {
                include_once $uninstall_script;

                if (function_exists('todo_theme_uninstall')) {
                    todo_theme_uninstall();
                }
            } catch (Exception $e) {
                todo_log_error('Theme uninstall script error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 删除目录及其内容
     * 
     * @param string $dir 目录路径
     * @return bool
     */
    private static function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                self::delete_directory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * 注册模板函数
     */
    private static function register_template_functions()
    {
        // 这里可以注册全局模板函数
        // 例如：注册Twig扩展、Smarty插件等
    }

    /**
     * 获取主题URL
     * 
     * @param string $theme_dir 主题目录名
     * @param string $path 相对路径
     * @return string
     */
    public static function get_theme_url($theme_dir = null, $path = '')
    {
        if (!$theme_dir) {
            $current_theme = self::get_current_theme();
            $theme_dir = $current_theme ? $current_theme['directory'] : '';
        }
        $theme_url = THEMES_URL . '/' . $theme_dir;

        if ($path) {
            $theme_url .= '/' . ltrim($path, '/');
        }

        return $theme_url;
    }

    /**
     * 获取主题路径
     * 
     * @param string $theme_dir 主题目录名
     * @param string $path 相对路径
     * @return string
     */
    public static function get_theme_path($theme_dir = null, $path = '')
    {
        if (!$theme_dir) {
            $current_theme = self::get_current_theme();
            $theme_dir = $current_theme ? $current_theme['directory'] : '';
        }

        $theme_path = ABSPATH . self::$themes_dir . '/' . $theme_dir;

        if ($path) {
            $theme_path .= '/' . ltrim($path, '/');
        }

        return $theme_path;
    }

    /**
     * 获取主题统计
     * 
     * @return array
     */
    public static function get_theme_stats()
    {
        $stats = array(
            'total' => 0,
            'active' => 0,
            'inactive' => 0
        );

        // 检查themes表是否存在，防止在系统安装前查询不存在的表
        if (self::$tododb->table_exists(DB_PREFIX . 'themes')) {
            $query = "SELECT status, COUNT(*) as count FROM " . DB_PREFIX . "themes GROUP BY status";
            $results = self::$tododb->get_results($query);
        } else {
            $results = array();
        }

        if ($results) {
            foreach ($results as $row) {
                $stats['total'] += $row->count;

                if ($row->status == self::STATUS_ACTIVE) {
                    $stats['active'] = $row->count;
                } else {
                    $stats['inactive'] += $row->count;
                }
            }
        }

        return $stats;
    }
}

// 便捷函数
if (!function_exists('todo_get_themes')) {
    /**
     * 获取所有主题
     * 
     * @param bool $include_inactive 是否包含未激活的主题
     * @return array
     */
    function todo_get_themes($include_inactive = true)
    {
        return Todo_Theme_Manager::get_themes($include_inactive);
    }
}

if (!function_exists('todo_get_current_theme')) {
    /**
     * 获取当前主题
     * 
     * @return array|null
     */
    function todo_get_current_theme()
    {
        return Todo_Theme_Manager::get_current_theme();
    }
}

if (!function_exists('todo_render_template')) {
    /**
     * 渲染模板
     * 
     * @param string $template 模板名称
     * @param array $vars 模板变量
     * @param bool $return 是否返回内容
     * @return string|void
     */
    function todo_render_template($template, $vars = array(), $return = false)
    {
        return Todo_Theme_Manager::render_template($template, $vars, $return);
    }
}

if (!function_exists('todo_set_template_var')) {
    /**
     * 设置模板变量
     * 
     * @param string|array $key 变量名或变量数组
     * @param mixed $value 变量值
     */
    function todo_set_template_var($key, $value = null)
    {
        Todo_Theme_Manager::set_template_var($key, $value);
    }
}

if (!function_exists('todo_get_template_var')) {
    /**
     * 获取模板变量
     * 
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    function todo_get_template_var($key, $default = null)
    {
        return Todo_Theme_Manager::get_template_var($key, $default);
    }
}

if (!function_exists('todo_get_theme_url')) {
    /**
     * 获取主题URL
     * 
     * @param string $theme_dir 主题目录名
     * @param string $path 相对路径
     * @return string
     */
    function todo_get_theme_url($theme_dir = null, $path = '')
    {
        return Todo_Theme_Manager::get_theme_url($theme_dir, $path);
    }
}

if (!function_exists('todo_get_theme_path')) {
    /**
     * 获取主题路径
     * 
     * @param string $theme_dir 主题目录名
     * @param string $path 相对路径
     * @return string
     */
    function todo_get_theme_path($theme_dir = null, $path = '')
    {
        return Todo_Theme_Manager::get_theme_path($theme_dir, $path);
    }
}

if (!function_exists('todo_activate_theme')) {
    /**
     * 激活主题
     * 
     * @param string $theme_dir 主题目录名
     * @return bool|array
     */
    function todo_activate_theme($theme_dir)
    {
        return Todo_Theme_Manager::activate_theme($theme_dir);
    }
}

?>
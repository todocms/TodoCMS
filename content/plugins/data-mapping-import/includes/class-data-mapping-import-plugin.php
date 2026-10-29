<?php
/**
 * 标对导入插件主类
 * 
 * @package DataMappingImport
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

class DataMappingImportPlugin {
    
    private static $instance = null;
    private $plugin_path;
    private $plugin_url;
    private $version = '1.0.0';
    
    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        $this->plugin_path = dirname(dirname(__FILE__)) . '/';
        $this->plugin_url = todo_get_plugin_url('data-mapping-import');
        
        // 初始化插件
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // 加载依赖文件
        $this->load_dependencies();
    }
    
    /**
     * 插件激活
     */
    public static function activate() {
        $instance = self::get_instance();
        $instance->create_tables();
        $instance->create_upload_directory();
    }
    
    /**
     * 插件停用
     */
    public static function deactivate() {
        // 清理临时文件
        $upload_dir = ABSPATH . 'content/uploads/data-mapping-import/';
        if (is_dir($upload_dir)) {
            $files = glob($upload_dir . 'temp_*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * 插件卸载
     */
    public static function uninstall() {
        // 调用卸载脚本
        if (file_exists(dirname(dirname(__FILE__)) . '/uninstall.php')) {
            include_once dirname(dirname(__FILE__)) . '/uninstall.php';
            if (function_exists('data_mapping_import_uninstall')) {
                data_mapping_import_uninstall();
            }
        }
    }
    
    /**
     * 插件注册入口（供插件管理器调用）
     */
    public static function register() {
        // 插件已通过 get_instance() 初始化，这里可以添加额外的注册逻辑
        // 目前不需要额外操作，因为构造函数已经处理了初始化
    }
    
    /**
     * 初始化插件
     */
    public function init() {
        // 加载文本域
        load_plugin_textdomain('data-mapping-import', false);
        
        // 注册AJAX处理器
        $this->register_ajax_handlers();
        
        // 确保所需数据表已创建
        $this->ensure_tables_initialized();
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_menu_page(
            '标对导入',
            '标对导入', 
            'manage_options',
            'data-mapping-import',
            array($this, 'admin_page'),
            30
        );
    }
    
    /**
     * 管理页面回调
     */
    public static function admin_page() {
        include dirname(dirname(__FILE__)) . '/admin/settings.php';
    }
    
    /**
     * 加载脚本和样式
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_data-mapping-import') {
            return;
        }
        
        // 加载标准版样式
        todo_enqueue_style(
            'data-mapping-import-css',
            $this->plugin_url . 'assets/css/data-mapping-import.css',
            array(),
            $this->version
        );
        
        // 加载标准版脚本
        todo_enqueue_script(
            'data-mapping-import-js',
            $this->plugin_url . 'assets/js/data-mapping-import.js',
            array('jquery', 'todo-modal', 'todo-request'),
            $this->version,
            true
        );
        
        // 本地化标准版脚本
        todo_localize_script('data-mapping-import-js', 'dataMappingConfig', $this->get_localize_data());
    }
    
    /**
     * 获取本地化数据
     */
    private function get_localize_data() {
        return array(
            'ajaxurl' => todo_admin_url('admin-ajax.php'),
            'nonces' => array(
                'parse_sql_file' => todo_create_nonce('parse_sql_file'),
                'get_database_structure' => todo_create_nonce('get_database_structure'),
                'save_mapping_template' => todo_create_nonce('save_mapping_template'),
                'load_mapping_template' => todo_create_nonce('load_mapping_template'),
                'execute_import' => todo_create_nonce('execute_import'),
                'get_import_progress' => todo_create_nonce('get_import_progress'),
                'get_import_logs' => todo_create_nonce('get_import_logs'),
                'clear_import_logs' => todo_create_nonce('clear_import_logs'),
                'sync_db_schema' => todo_create_nonce('sync_db_schema')
            ),
            'messages' => array(
                'upload_success' => __('文件上传成功', 'data-mapping-import'),
                'upload_error' => __('文件上传失败', 'data-mapping-import'),
                'parse_error' => __('文件解析失败', 'data-mapping-import'),
                'import_success' => __('数据导入成功', 'data-mapping-import'),
                'import_error' => __('数据导入失败', 'data-mapping-import'),
                'confirm_delete' => __('确定删除该导入任务', 'data-mapping-import'),
                'mapping_success' => __('字段映射创建成功', 'data-mapping-import'),
                'mapping_error' => __('字段映射创建失败', 'data-mapping-import'),
                'type_incompatible' => __('字段类型可能不兼容', 'data-mapping-import')
            ),
            'settings' => array(
                'animation_speed' => 300
            )
        );
    }
    
    /**
     * 加载依赖文件
     */
    private function load_dependencies() {
        $dependencies = array(
            'sql-parser.php',
            'data-importer.php'
        );
        
        foreach ($dependencies as $file) {
            $file_path = dirname(__FILE__) . '/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * 注册AJAX处理器
     */
    private function register_ajax_handlers() {
        // 加载AJAX处理器文件
        if (file_exists(dirname(__FILE__) . '/ajax-handlers.php')) {
            require_once dirname(__FILE__) . '/ajax-handlers.php';
            // 实例化AJAX处理器
            new DataMappingAjaxHandlers();
        }
    }
    
    /**
     * 创建数据库表（幂等）
     */
    public function create_tables() {
        global $tododb;
        if (!$tododb) {
            error_log('DataMappingImportPlugin: 数据库连接不可用，无法创建表');
            return;
        }

        $charset_collate = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // 导入任务表
        try {
            $table_name = $tododb->prefix . 'plugin_data_import_jobs';
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                job_name varchar(255) NOT NULL,
                source_file varchar(500) DEFAULT NULL,
                target_table varchar(100) NOT NULL,
                field_mapping text,
                status enum('pending','processing','completed','failed') DEFAULT 'pending',
                total_records int(11) DEFAULT 0,
                processed_records int(11) DEFAULT 0,
                error_message text,
                action_user int(11) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY created_at (created_at)
            ) $charset_collate COMMENT='导入任务表';";
            $tododb->query($sql);
        } catch (Exception $e) {
            error_log('DataMappingImportPlugin: 创建 plugin_data_import_jobs 表失败 - ' . $e->getMessage());
        }

        // 映射模板表
        try {
            $table_name = $tododb->prefix . 'plugin_data_mapping_templates';
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                template_name varchar(255) NOT NULL,
                source_table varchar(100) NOT NULL,
                target_table varchar(100) NOT NULL,
                field_mapping text NOT NULL,
                description text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY template_name (template_name),
                KEY source_table (source_table),
                KEY target_table (target_table)
            ) $charset_collate COMMENT='映射模板表';";
            $tododb->query($sql);
        } catch (Exception $e) {
            error_log('DataMappingImportPlugin: 创建 plugin_data_mapping_templates 表失败 - ' . $e->getMessage());
        }
    }

    /**
     * 确保所需数据表已初始化（仅检查一次）
     */
    private function ensure_tables_initialized() {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;

        if (!function_exists('table_exists')) {
            // 无法检查时，尽量创建
            $this->create_tables();
            return;
        }

        global $tododb;
        $prefix = ($tododb && isset($tododb->prefix)) ? $tododb->prefix : 'todo_';
        $tables = array(
            $prefix . 'plugin_data_import_jobs',
            $prefix . 'plugin_data_mapping_templates'
        );

        $missing = false;
        foreach ($tables as $t) {
            if (!table_exists($t)) { $missing = true; break; }
        }

        if ($missing) {
            $this->create_tables();
        }
    }
    
    /**
     * 获取插件专用上传目录
     * 优先使用 TodoCMS 提供的上传目录API，回退到常量/默认路径
     */
    public function get_plugin_upload_dir($ensure_exists = true) {
        // 优先使用 todo_upload_dir(false) 获取基础上传目录（避免创建按日子目录）
        if (function_exists('todo_upload_dir')) {
            $info = todo_upload_dir(false);
            $base = isset($info['basedir']) ? $info['basedir'] : null;
        }
        if (empty($base)) {
            if (defined('CONTENT_DIR')) {
                $base = rtrim(CONTENT_DIR, '/') . '/uploads';
            } else {
                $base = rtrim(ABSPATH, '/') . '/content/uploads';
            }
        }
        $dir = rtrim($base, '/') . '/data-mapping-import';
        if ($ensure_exists && !file_exists($dir)) {
            if (function_exists('todo_mkdir_p')) {
                todo_mkdir_p($dir);
            } else {
                if (!function_exists('todo_mkdir_p')) {
                    // 简易创建（递归）
                    @mkdir($dir, 0755, true);
                } else {
                    todo_mkdir_p($dir);
                }
            }
        }
        return $dir;
    }

    /**
     * 创建上传目录
     */
    private function create_upload_directory() {
        $upload_dir = rtrim($this->get_plugin_upload_dir(true), '/') . '/';
        
        // 创建.htaccess文件保护上传目录
        $htaccess_file = $upload_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "# 保护上传文件\nOrder deny,allow\nDeny from all\n<Files ~ \"\\.(sql|csv|json)$\">\n    Allow from all\n</Files>";
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        // 创建index.php文件
        $index_file = $upload_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
    }
    
    /**
     * 获取插件路径
     */
    public function get_plugin_path() {
        return $this->plugin_path;
    }
    
    /**
     * 获取插件URL
     */
    public function get_plugin_url() {
        return $this->plugin_url;
    }
    
    /**
     * 获取版本号
     */
    public function get_version() {
        return $this->version;
    }
}

// 初始化插件
DataMappingImportPlugin::get_instance();
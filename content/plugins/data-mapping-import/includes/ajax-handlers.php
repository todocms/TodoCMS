<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../../');
}

/**
 * AJAX处理器
 * 数据映射导入插件的AJAX请求处理
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

class DataMappingAjaxHandlers
{

    public function __construct()
    {
        // 注册AJAX处理器
        add_action('todo_ajax_parse_sql_file', array($this, 'handle_parse_sql_file'));
        add_action('todo_ajax_get_database_structure', array($this, 'handle_get_database_structure'));
        add_action('todo_ajax_save_mapping_template', array($this, 'handle_save_mapping_template'));
        add_action('todo_ajax_load_mapping_template', array($this, 'handle_load_mapping_template'));
        add_action('todo_ajax_delete_mapping_template', array($this, 'handle_delete_mapping_template'));
        add_action('todo_ajax_execute_import', array($this, 'handle_execute_import'));
        add_action('todo_ajax_get_import_progress', array($this, 'handle_get_import_progress'));
        add_action('todo_ajax_get_import_logs', array($this, 'handle_get_import_logs'));
        add_action('todo_ajax_clear_import_logs', array($this, 'handle_clear_import_logs'));
        add_action('todo_ajax_sync_db_schema', array($this, 'handle_sync_db_schema'));
    }

    /**
     * 解析SQL文件
     */
    public function handle_parse_sql_file()
    {
        new ParseSqlFileAjax();
    }

    /**
     * 获取数据库结构
     */
    public function handle_get_database_structure()
    {
        new GetDatabaseStructureAjax();
    }

    /**
     * 保存映射模板
     */
    public function handle_save_mapping_template()
    {
        new SaveMappingTemplateAjax();
    }

    /**
     * 加载映射模板
     */
    public function handle_load_mapping_template()
    {
        new LoadMappingTemplateAjax();
    }

    /**
     * 删除映射模板
     */
    public function handle_delete_mapping_template()
    {
        new DeleteMappingTemplateAjax();
    }

    /**
     * 执行数据导入
     */
    public function handle_execute_import()
    {
        new ExecuteImportAjax();
    }

    /**
     * 获取导入进度
     */
    public function handle_get_import_progress()
    {
        new GetImportProgressAjax();
    }

    /**
     * 获取导入日志
     */
    public function handle_get_import_logs()
    {
        new GetImportLogsAjax();
    }

    /**
     * 清空导入日志
     */
    public function handle_clear_import_logs()
    {
        new ClearImportLogsAjax();
    }

    /**
     * 一键初始化/全量同步数据库结构
     */
    public function handle_sync_db_schema()
    {
        new SyncDbSchemaAjax();
    }
}

/**
 * 解析SQL文件AJAX处理器
 */
class ParseSqlFileAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'parse_sql_file',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        if (!isset($_FILES['sql_file'])) {
            $this->add_error('未找到上传文件');
            return $this->error_exit();
        }

        $file = $_FILES['sql_file'];

        // 更详细的上传错误检查
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error_code = isset($file['error']) ? $file['error'] : -1;
            $error_map = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器允许的最大上传大小（upload_max_filesize）',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单允许的最大大小（MAX_FILE_SIZE）',
                UPLOAD_ERR_PARTIAL => '文件只上传了部分，请重试',
                UPLOAD_ERR_NO_FILE => '未选择任何文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录丢失（upload_tmp_dir 未配置）',
                UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件，请检查磁盘权限',
                UPLOAD_ERR_EXTENSION => '上传被PHP扩展中止，请检查安全扩展设置'
            ];
            $msg = isset($error_map[$error_code]) ? $error_map[$error_code] : ('未知上传错误代码: ' . $error_code);
            $this->add_error('上传失败：' . $msg);
            return $this->error_exit();
        }

        // 验证文件类型（大小写不敏感）
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            $this->add_error('只支持SQL文件');
            return $this->error_exit();
        }

        if (function_exists('get_option') && !get_option('enable_upload', true)) {
            $this->add_error('文件上传功能已被管理员禁用');
            return $this->error_exit();
        }

        $max_upload_size_bytes = null;
        if (class_exists('TodoUploadConfig')) {
            $max_upload_size_bytes = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $max_upload_size_mb = get_option('max_upload_size', null);
            if ($max_upload_size_mb !== null && is_numeric($max_upload_size_mb) && (int) $max_upload_size_mb > 0) {
                $max_upload_size_bytes = (int) $max_upload_size_mb * 1024 * 1024;
            }
        }
        if ($max_upload_size_bytes === null || (int) $max_upload_size_bytes <= 0) {
            $this->add_error('文件上传功能已被管理员禁用');
            return $this->error_exit();
        }
        
        // 验证文件大小
        if ($file['size'] > $max_upload_size_bytes) {
            $max_size_mb = round($max_upload_size_bytes / (1024 * 1024), 2);
            $this->add_error("文件大小不能超过 {$max_size_mb}MB");
            return $this->error_exit();
        }

        try {
            // 移动文件到上传目录
            $upload_dir = DataMappingImportPlugin::get_instance()->get_plugin_upload_dir(true);
            if (!file_exists($upload_dir)) {
                todo_mkdir_p($upload_dir);
            }

            $filename = 'sql_' . time() . '_' . sanitize_file_name($file['name']);
            $filepath = $upload_dir . '/' . $filename;

            // realpath 前缀校验，确保写入到 uploads 下
            $uploads_real = realpath(dirname($upload_dir));
            $dest_parent_real = realpath(dirname($filepath));
            if ($uploads_real === false || $dest_parent_real === false || strpos($dest_parent_real, $uploads_real) !== 0) {
                $this->add_error('非法上传目录');
                return $this->error_exit();
            }

            if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->add_error('文件上传失败');
                return $this->error_exit();
            }
            @chmod($filepath, 0644);

            // 解析SQL文件（大文件放宽执行时间）
            @set_time_limit(300);
            require_once dirname(__FILE__) . '/sql-parser.php';
            $parser = new SQLStructureParser();
            $structure = $parser->parse($filepath);

            if (empty($structure)) {
                $this->add_error('SQL文件解析失败或文件为空');
                return $this->error_exit();
            }

            $this->success_response(array(
                'structure' => $structure,
                'filename' => $filename,
                'filepath' => $filepath
            ));

        } catch (Exception $e) {
            $this->add_error('解析失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 获取数据库结构AJAX处理器
 */
class GetDatabaseStructureAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        // 配置CSRF验证
        $config = [
            'csrf_action' => 'get_database_structure',
            'require_login' => true,
            'require_csrf' => true
        ];

        parent::__construct($config);
    }

    protected function handle_request()
    {
        global $tododb;

        try {
            $structure = array();

            // 获取当前数据库名
            $db_name = $tododb->get_var("SELECT DATABASE()");

            if (!$db_name) {
                $this->add_error('无法获取数据库信息');
                return $this->error_exit();
            }

            $structure[$db_name] = array(
                'name' => $db_name,
                'tables' => array()
            );

            // 获取所有表（TodoCMS：使用 get_col 获取第一列的表名列表）
            $tables = $tododb->get_col("SHOW TABLES");

            // 设置最大执行时间
            set_time_limit(120);

            $processed = 0;
            $total = count($tables);

            foreach ($tables as $table_row) {
                $table_name = $table_row;

                // 排除临时表（以 temp_ 开头）
                if (strpos($table_name, 'temp_') === 0) {
                    continue;
                }

                // 获取表字段信息
                $columns = $tododb->get_results("DESCRIBE `{$table_name}`");

                $fields = array();
                foreach ($columns as $column) {
                    $fields[] = array(
                        'name' => $column->Field,
                        'type' => $column->Type,
                        'null' => $column->Null === 'YES',
                        'key' => $column->Key,
                        'default' => $column->Default,
                        'extra' => $column->Extra
                    );
                }

                $structure[$db_name]['tables'][$table_name] = array(
                    'name' => $table_name,
                    'fields' => $fields
                );

                $processed++;

                // 每处理10个表输出一次进度（防止超时）
                if ($processed % 10 == 0) {
                    // 刷新输出缓冲区
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            }

            // 统一响应结构，前端期望 data.structure
            $this->success_response(['structure' => $structure]);

        } catch (Exception $e) {
            $this->add_error('获取数据库结构失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 保存映射模板AJAX处理器
 */
class SaveMappingTemplateAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'save_mapping_template',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        // 兼容前端参数：template_name（主）与 name（兼容）
        $name = sanitize_text_field($_POST['template_name'] ?? ($_POST['name'] ?? ''));
        // 描述字段兼容 template_description（可选）
        $description = sanitize_textarea_field($_POST['description'] ?? ($_POST['template_description'] ?? ''));
        $source_structure = $_POST['source_structure'] ?? '';
        $target_structure = $_POST['target_structure'] ?? '';
        $mapping_config = $_POST['mapping_config'] ?? '';

        if (empty($name) || empty($mapping_config)) {
            $this->add_error('模板名称和映射配置不能为空');
            return $this->error_exit();
        }

        try {
            // 统一使用带前缀的插件表 plugin_data_mapping_templates
            $table = $tododb->prefix . 'plugin_data_mapping_templates';
            // 规范化映射配置为JSON字符串
            $mapping_json = is_string($mapping_config) ? $mapping_config : json_encode($mapping_config, JSON_UNESCAPED_UNICODE);
            $config_arr = is_string($mapping_config) ? json_decode($mapping_config, true) : (is_array($mapping_config) ? $mapping_config : array());

            // 兼容新版与旧版：优先从顶层 source_table/target_table 读取；若为空则尝试从 mappings 推断
            $src_table = isset($config_arr['source_table']) ? sanitize_text_field($config_arr['source_table']) : '';
            $tgt_table = isset($config_arr['target_table']) ? sanitize_text_field($config_arr['target_table']) : '';
            if ((empty($src_table) || empty($tgt_table)) && isset($config_arr['mappings']) && is_array($config_arr['mappings']) && !empty($config_arr['mappings'])) {
                $first = $config_arr['mappings'][0];
                if (isset($first['source_table']) || isset($first['target_table'])) {
                    $src_table = $src_table ?: sanitize_text_field($first['source_table'] ?? '');
                    $tgt_table = $tgt_table ?: sanitize_text_field($first['target_table'] ?? '');
                } elseif (isset($first['source']) && isset($first['target'])) {
                    $src_table = $src_table ?: sanitize_text_field($first['source']['table'] ?? '');
                    $tgt_table = $tgt_table ?: sanitize_text_field($first['target']['table'] ?? '');
                }
            }

            // 使用 UPSERT 保证同名模板自动更新，避免唯一索引冲突
            $sql = "INSERT INTO {$table} (template_name, description, source_table, target_table, field_mapping, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        description = VALUES(description),
                        source_table = VALUES(source_table),
                        target_table = VALUES(target_table),
                        field_mapping = VALUES(field_mapping),
                        updated_at = NOW()";
            // 使用 TodoCMS 的 query 执行参数化 SQL，返回受影响行数
            $affected = $tododb->query($sql, [$name, $description, $src_table, $tgt_table, $mapping_json]);

            if ($affected === false) {
                $this->add_error('保存模板失败');
                return $this->error_exit();
            }

            // 获取模板ID（兼容插入与更新两种情况）
            $msg = '模板保存成功';
            if ($affected === 2) {
                $msg = '模板已更新';
            } elseif ($affected === 0) {
                $msg = '模板无变更';
            }

            // 兼容插入与更新：按模板名获取ID
            $tplId = (int) $tododb->get_var("SELECT id FROM {$table} WHERE template_name = ?", [$name]);

            $this->success_response(array(
                'template_id' => $tplId,
                'message' => $msg
            ));

        } catch (Exception $e) {
            $this->add_error('保存模板失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 加载映射模板AJAX处理器
 */
class LoadMappingTemplateAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'load_mapping_template',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        $template_id = intval($_POST['template_id'] ?? 0);

        if ($template_id <= 0) {
            // 获取所有模板列表（统一使用带前缀的 plugin_data_mapping_templates 并做字段别名兼容现有前端）
            $table = $tododb->prefix . 'plugin_data_mapping_templates';
            $templates = $tododb->get_results(
                "SELECT id, template_name AS name, description, created_at AS created_time, updated_at AS updated_time 
                 FROM {$table}
                 ORDER BY updated_at DESC"
            );

            $this->success_response(array('templates' => $templates));
        } else {
            // 获取指定模板（使用带前缀的 plugin_data_mapping_templates，字段别名与前端保持兼容）
            $table = $tododb->prefix . 'plugin_data_mapping_templates';
            $template = $tododb->get_row(
                "SELECT id,
                        template_name AS name,
                        description,
                        source_table,
                        target_table,
                        field_mapping AS mapping_config,
                        created_at AS created_time,
                        updated_at AS updated_time
                 FROM {$table}
                 WHERE id = ?",
                [$template_id],
                'ARRAY_A'
            );

            if (!$template) {
                $this->add_error('模板不存在');
                return $this->error_exit();
            }

            $this->success_response(array('template' => $template));
        }
    }
}

/**
 * 删除映射模板AJAX处理器
 */
class DeleteMappingTemplateAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'delete_mapping_template',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        $template_id = intval($_POST['template_id'] ?? 0);

        if ($template_id <= 0) {
            $this->add_error('模板ID无效');
            return $this->error_exit();
        }

        try {
            $table = $tododb->prefix . 'plugin_data_mapping_templates';
            $affected = $tododb->query("DELETE FROM `{$table}` WHERE id = ?", [$template_id]);
            if ($affected <= 0) {
                $this->add_error('删除失败或模板不存在');
                return $this->error_exit();
            }
            $this->success_response(['message' => '模板已删除']);
        } catch (Exception $e) {
            $this->add_error('删除失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 执行数据导入AJAX处理器
 */
class ExecuteImportAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'execute_import',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        $source_file = sanitize_text_field($_POST['source_file'] ?? '');
        $mapping_config = $_POST['mapping_config'] ?? '';
        $import_config = $_POST['import_config'] ?? array();

        if (empty($source_file) || empty($mapping_config)) {
            $this->add_error('源文件和映射配置不能为空');
            return $this->error_exit();
        }

        try {
            require_once dirname(__FILE__) . '/data-importer.php';
            $importer = new DataMappingImporter();

            // 组装导入配置（TodoCMS）
            $config = is_string($mapping_config) ? json_decode($mapping_config, true) : $mapping_config;
            if (!is_array($config)) {
                throw new Exception('映射配置格式无效');
            }
            if (is_string($import_config)) {
                $extra = json_decode($import_config, true);
                if (is_array($extra)) {
                    $config['import_config'] = $extra;
                }
            } elseif (is_array($import_config)) {
                $config['import_config'] = $import_config;
            }
            $config['source_file'] = $source_file;

            // 创建导入任务
            $task_id = $importer->create_import_job($config);

            // 启动导入（异步）
            $importer->start_import($task_id);

            $this->success_response(array(
                'task_id' => $task_id,
                'message' => '导入任务已启动'
            ));

        } catch (Exception $e) {
            $this->add_error('启动导入失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 获取导入进度AJAX处理器
 */
class GetImportProgressAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'get_import_progress',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        $task_id = intval($_POST['task_id'] ?? 0);

        if ($task_id <= 0) {
            $this->add_error('任务ID无效');
            return $this->error_exit();
        }

        $table = $tododb->prefix . 'plugin_data_import_jobs';
        // 如果任务表不存在，尝试自动创建一次（避免 42S02 异常）
        $exists = false;
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = $tododb->table_exists('plugin_data_import_jobs');
            } elseif (function_exists('table_exists')) {
                $exists = table_exists($table);
            }
            if (!$exists && class_exists('DataMappingImportPlugin')) {
                $plugin = DataMappingImportPlugin::get_instance();
                if (method_exists($plugin, 'create_tables')) {
                    $plugin->create_tables();
                    // 重新检查
                    if (method_exists($tododb, 'table_exists')) {
                        $exists = $tododb->table_exists('plugin_data_import_jobs');
                    } elseif (function_exists('table_exists')) {
                        $exists = table_exists($table);
                    }
                }
            }
        } catch (Throwable $e) {
            // 保守处理：不抛异常，交由后续逻辑判断
        }
        if (!$exists) {
            $this->add_error('导入任务表未初始化');
            return $this->error_exit();
        }
        $task = $tododb->get_row(
            "SELECT * FROM `{$table}` WHERE id = ?",
            [$task_id],
            'ARRAY_A'
        );

        if (!$task) {
            $this->add_error('任务不存在');
            return $this->error_exit();
        }

        $total = isset($task['total_records']) ? (int) $task['total_records'] : 0;
        $imported = isset($task['processed_records']) ? (int) $task['processed_records'] : 0;

        // 根据状态确定状态文本
        $status_text = '';
        if ($task['status'] === 'processing') {
            // 处理中：显示错误信息字段中的当前步骤描述
            $status_text = !empty($task['error_message']) ? $task['error_message'] : '正在处理...';
        } elseif ($task['status'] === 'completed') {
            // 已完成：显示成功消息
            $status_text = '导入完成！';
        } elseif ($task['status'] === 'failed') {
            // 失败：显示错误信息
            $status_text = !empty($task['error_message']) ? $task['error_message'] : '导入失败';
        }

        $this->success_response(array(
            'status' => $task['status'],
            'total_records' => $total,
            'imported_records' => $imported,
            'error_message' => $task['error_message'] ?? '',
            'status_text' => $status_text
        ));
    }
}

/**
 * 获取导入日志AJAX处理器
 */
class GetImportLogsAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'get_import_logs',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        // 分页参数
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(1, min(100, intval($_POST['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;

        // 返回导入任务摘要作为“导入日志”列表，更符合前端展示（状态、记录数等）
        $jobs_table = $tododb->prefix . 'plugin_data_import_jobs';
        // 如果任务表不存在，尝试自动创建一次（避免 42S02 异常）
        $exists = false;
        try {
            if (method_exists($tododb, 'table_exists')) {
                $exists = $tododb->table_exists('plugin_data_import_jobs');
            } elseif (function_exists('table_exists')) {
                $exists = table_exists($jobs_table);
            }
            if (!$exists && class_exists('DataMappingImportPlugin')) {
                $plugin = DataMappingImportPlugin::get_instance();
                if (method_exists($plugin, 'create_tables')) {
                    $plugin->create_tables();
                    // 重新检查
                    if (method_exists($tododb, 'table_exists')) {
                        $exists = $tododb->table_exists('plugin_data_import_jobs');
                    } elseif (function_exists('table_exists')) {
                        $exists = table_exists($jobs_table);
                    }
                }
            }
        } catch (Throwable $e) {
            // 保守处理：不抛异常
        }
        if (!$exists) {
            return $this->success_response(array(
                'logs' => array(),
                'total' => 0,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => 0
            ));
        }

        // 总数
        $total = 0;
        $rows = array();
        try {
            $total = (int) $tododb->get_var("SELECT COUNT(*) FROM `{$jobs_table}`");
            // 列表数据（按创建时间倒序）
            $rows = $tododb->get_results(
                "SELECT id, status, total_records, processed_records, created_at 
                 FROM `{$jobs_table}` 
                 ORDER BY created_at DESC 
                 LIMIT {$per_page} OFFSET {$offset}",
                array(),
                'ARRAY_A'
            ) ?: array();
        } catch (Throwable $e) {
            // 若仍发生异常，返回空列表，避免向前端抛 PDO 异常
            return $this->success_response(array(
                'logs' => array(),
                'total' => 0,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => 0
            ));
        }

        // 适配前端期望字段命名
        $logs = array();
        foreach ($rows as $r) {
            $logs[] = array(
                'id' => (int) $r['id'],
                'created_time' => $r['created_at'],
                'import_status' => $r['status'],
                'total_records' => (int) $r['total_records'],
                'imported_records' => (int) $r['processed_records']
            );
        }

        $this->success_response(array(
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page)
        ));
    }
}
/**
 * 清空导入日志AJAX处理器
 */
class ClearImportLogsAjax extends Todo_Ajax_Base
{

    public function __construct()
    {
        parent::__construct([
            'csrf_action' => 'clear_import_logs',
            'require_login' => true,
            'require_csrf' => true
        ]);
    }

    protected function handle_request()
    {
        global $tododb;

        try {
            $table = $tododb->prefix . 'plugin_data_import_jobs';
            
            // 检查表是否存在
            $exists = false;
            if (method_exists($tododb, 'table_exists')) {
                $exists = $tododb->table_exists('plugin_data_import_jobs');
            } elseif (function_exists('table_exists')) {
                $exists = table_exists($table);
            }
            
            if (!$exists) {
                $this->success_response(['message' => '日志已清空']);
                return;
            }

            // 清空表数据
            $tododb->query("TRUNCATE TABLE `{$table}`");

            $this->success_response(['message' => '日志已清空']);
        } catch (Exception $e) {
            $this->add_error('清空日志失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}

/**
 * 一键初始化/全量同步数据库结构AJAX处理器
 */
class SyncDbSchemaAjax extends Todo_Ajax_Base
{
    public function __construct()
    {
        parent::__construct([
            'csrf_action'   => 'sync_db_schema',
            'require_login' => true,
            'require_csrf'  => true
        ]);
    }

    protected function handle_request()
    {
        try {
            if (!class_exists('Todo_DB_Migrator') && file_exists(ABSPATH . 'core/classes/class-db-migrator.php')) {
                require_once ABSPATH . 'core/classes/class-db-migrator.php';
            }

            if (!class_exists('Todo_DB_Migrator')) {
                $this->add_error('无法加载 Todo_DB_Migrator 核心迁移类');
                return $this->error_exit();
            }

            $table_prefix = sanitize_text_field($_POST['table_prefix'] ?? '');

            // 调用 Migrator 执行全量 Schema 载入与 dbDelta 无损增量比对补全（支持按前缀过滤）
            Todo_DB_Migrator::run_migration('0.0.0', Todo_DB_Migrator::get_target_version(), $table_prefix);

            $msg = $table_prefix !== '' ? "表前缀 '{$table_prefix}' 结构对齐成功！" : "数据库结构对齐成功！";
            $this->success_response([
                'message' => $msg
            ]);
        } catch (Exception $e) {
            $this->add_error('数据库结构对齐失败: ' . $e->getMessage());
            return $this->error_exit();
        }
    }
}


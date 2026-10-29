<?php
/**
 * 数据导入器
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

class DataMappingImporter
{

    private $job_id;
    private $config;
    private $source_db;
    private $target_db;
    private $target_pdo; // 专用目标库连接
    private $import_config = array();
    private $truncated_tables = array();
    private $table_schema_cache = array();

    // 进度追踪
    private $total_records = 0;
    private $imported_records = 0;
    private $failed_records = 0;

    /**
     * 创建导入任务
     */
    public function create_import_job($config)
    {
        global $tododb;

        // 写入导入任务到任务表（todo_plugin_data_import_jobs）
        $table = $tododb->prefix . 'plugin_data_import_jobs';
        $job_name = 'Import ' . date('Ymd-His');
        $target_table = isset($config['target_table']) && $config['target_table'] ? $config['target_table'] : 'multiple';

        // 组装插入数据（兼容可能存在的审计字段）
        $data = array(
            'job_name' => $job_name,
            'source_file' => isset($config['source_file']) ? $config['source_file'] : null,
            'target_table' => $target_table,
            'field_mapping' => json_encode($config),
            'status' => 'pending',
            'total_records' => 0,
            'processed_records' => 0,
            'error_message' => null
        );

        // 确保导入任务表已存在
        $jobs_table_exists = false;
        try {
            if (method_exists($tododb, 'table_exists')) {
                $jobs_table_exists = $tododb->table_exists('plugin_data_import_jobs');
            }
            
            if (!$jobs_table_exists && class_exists('DataMappingImportPlugin')) {
                $plugin = DataMappingImportPlugin::get_instance();
                if (method_exists($plugin, 'create_tables')) {
                    $plugin->create_tables();
                    // 重新检查
                    if (method_exists($tododb, 'table_exists')) {
                        $jobs_table_exists = $tododb->table_exists('plugin_data_import_jobs');
                    }
                }
            }
        } catch (Throwable $e) {
            // 忽略建表过程中的异常，后续会根据 exists 决定是否继续
        }

        // 如果任务表存在 action_user 字段，则记录当前用户ID（无则回退为0）
        if ($jobs_table_exists) {
            try {
                // MySQL 原生预处理不支持 SHOW 语句中的占位符，这里使用直接查询并限定常量值
                $sql = "SHOW COLUMNS FROM `{$table}` LIKE 'action_user'";
                $row = $tododb->get_row($sql, [], 'ARRAY_A');
                if (!empty($row)) {
                    $current_uid = 0;
                    if (function_exists('todo_get_current_user_id')) {
                        $current_uid = intval(todo_get_current_user_id());
                    }
                    if ($current_uid <= 0) {
                        $current_uid = 0;
                    }
                    $data['action_user'] = $current_uid; // 避免出现 '' 导致的整型错误
                }
            } catch (Exception $e) {
                // 忽略结构探测错误，不影响任务创建
            }
        }

        if (!$jobs_table_exists) {
            throw new Exception('导入任务表未初始化或创建失败');
        }
        $result = $tododb->insert($table, $data);

        if ($result === false) {
            throw new Exception('创建导入任务失败');
        }

        return $result;
    }

    /**
     * 启动导入
     */
    public function start_import($job_id)
    {
        $this->job_id = $job_id;

        // 设置执行时间限制（0表示无限制，但受服务器配置限制）
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        // 关闭Session写入，释放锁，允许前端轮询进度（防止 AJAX 请求阻塞）
        if (session_id()) {
            session_write_close();
        }

        // 获取任务配置
        $this->load_job_config();

        // 更新状态为处理中
        $this->update_job_status('processing');

        try {
            // 建立源数据库连接
            $this->connect_source_database();

            // 执行数据导入
            $this->execute_import();

            // 更新状态为完成
            $this->update_job_status('completed');

        } catch (Exception $e) {
            // 记录错误并更新状态（不再抛出，避免“server has gone away”冒泡为致命错误）
            $error_msg = $e->getMessage();
            $this->update_job_status('failed', $error_msg);
            if (method_exists($this, 'append_job_error')) {
                @$this->append_job_error($error_msg);
            }
            // 记录到错误日志
            error_log("数据导入失败 (Job ID: {$this->job_id}): " . $error_msg);
        } catch (Throwable $e) {
            // 捕获所有错误，包括致命错误
            $error_msg = $e->getMessage();
            $this->update_job_status('failed', $error_msg);
            if (method_exists($this, 'append_job_error')) {
                @$this->append_job_error($error_msg);
            }
            error_log("数据导入失败 (Job ID: {$this->job_id}): " . $error_msg);
        } finally {
            // 清理资源
            $this->cleanup();
        }
    }

    /**
     * 加载任务配置
     */
    private function load_job_config()
    {
        global $tododb;

        $table = $tododb->prefix . 'plugin_data_import_jobs';
        $job = $tododb->get_row("SELECT * FROM `{$table}` WHERE id = ?", [$this->job_id], 'ARRAY_A');

        if (!$job) {
            throw new Exception('导入任务不存在');
        }

        $cfg = json_decode($job['field_mapping'], true);
        if (!is_array($cfg)) {
            throw new Exception('任务配置无效：field_mapping 不是有效的 JSON 对象');
        }

        if (!isset($cfg['mappings'])) {
            throw new Exception('任务配置无效：缺少 mappings');
        }

        $this->config = $cfg;
        // 解析额外导入配置（批量大小、去重/合并、是否清空表等）
        if (isset($cfg['import_config']) && is_array($cfg['import_config'])) {
            $this->import_config = $cfg['import_config'];
        } else {
            $this->import_config = array();
        }
    }

    /**
     * 检查并重连源数据库
     */
    private function ensure_source_db_connected()
    {
        if (!$this->source_db || !($this->source_db instanceof mysqli)) {
            $this->reconnect_source_database();
            return;
        }

        // 检查连接是否有效
        if (!@$this->source_db->ping()) {
            $this->reconnect_source_database();
        }
    }

    /**
     * 重连源数据库
     */
    private function reconnect_source_database()
    {
        if ($this->source_db && $this->source_db instanceof mysqli) {
            @$this->source_db->close();
        }

        $this->source_db = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME
        );

        if ($this->source_db->connect_error) {
            throw new Exception('重连源数据库失败: ' . $this->source_db->connect_error);
        }

        $this->source_db->set_charset('utf8mb4');
        @$this->source_db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        // 增加超时设置
        @$this->source_db->query("SET SESSION wait_timeout=28800");
        @$this->source_db->query("SET SESSION interactive_timeout=28800");
        @$this->source_db->query("SET SESSION net_write_timeout=600");
        @$this->source_db->query("SET SESSION net_read_timeout=600");
    }

    /**
     * 连接源数据库
     */
    private function connect_source_database()
    {
        // 如果是SQL文件，需要先导入到临时数据库
        if (isset($this->config['source_file'])) {
            $this->import_sql_to_temp_database();
        }

        // 建立源数据库连接
        $this->source_db = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME
        );

        if ($this->source_db->connect_error) {
            throw new Exception('连接源数据库失败: ' . $this->source_db->connect_error);
        }

        $this->source_db->set_charset('utf8mb4');
        @$this->source_db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        // 增加超时设置，防止连接断开
        @$this->source_db->query("SET SESSION wait_timeout=28800");
        @$this->source_db->query("SET SESSION interactive_timeout=28800");
        @$this->source_db->query("SET SESSION net_write_timeout=600");
        @$this->source_db->query("SET SESSION net_read_timeout=600");

        // 目标数据库使用当前连接（用于元数据查询）
        global $tododb;
        $this->target_db = $tododb;

        // 建立专用的目标数据库PDO连接（用于批量插入，避免全局连接超时）
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $this->target_pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            // 设置超时
            $this->target_pdo->exec("SET SESSION wait_timeout=28800");
            $this->target_pdo->exec("SET SESSION interactive_timeout=28800");
            $this->target_pdo->exec("SET SESSION net_write_timeout=600");
            $this->target_pdo->exec("SET SESSION net_read_timeout=600");
        } catch (PDOException $e) {
            throw new Exception('连接目标数据库失败: ' . $e->getMessage());
        }
    }

    /**
     * 导入SQL文件到临时表（使用当前数据库，添加临时表前缀）
     * 改进：使用流式读取处理大文件，并仅创建映射所需的表
     */
    private function import_sql_to_temp_database()
    {
        $source_file = $this->config['source_file'];
        if (!file_exists($source_file)) {
            throw new Exception('源SQL文件不存在: ' . $source_file);
        }

        // 更新进度：开始导入SQL文件
        $this->update_job_status('processing', '正在导入SQL文件到临时表...');

        // 生成临时表前缀（使用稳定的 job_id 前缀，确保重试时能清理旧表）
        $temp_table_prefix = 'temp_import_' . $this->job_id . '_';
        $this->config['temp_table_prefix'] = $temp_table_prefix;

        // 连接到当前数据库
        $temp_conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($temp_conn->connect_error) {
            throw new Exception('连接数据库失败: ' . $temp_conn->connect_error);
        }
        $temp_conn->set_charset('utf8mb4');
        @$temp_conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // ------------------ 改进：正式开始前清理旧的临时表 ------------------
        $like_prefix = strtr($temp_table_prefix, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
        $like_prefix = $temp_conn->real_escape_string($like_prefix);
        $old_tables_res = $temp_conn->query("SHOW TABLES LIKE '{$like_prefix}%'");
        if ($old_tables_res) {
            $tables_to_drop = array();
            while ($row = $old_tables_res->fetch_array()) {
                $tables_to_drop[] = $row[0];
            }
            $old_tables_res->free();
            
            if (!empty($tables_to_drop)) {
                error_log("[Import Debug] Cleaning up old temporary tables from previous run: " . implode(', ', $tables_to_drop));
                foreach ($tables_to_drop as $tbl) {
                    $temp_conn->query("DROP TABLE IF EXISTS `{$tbl}`");
                }
            }
        }
        // ------------------------------------------------------------------

        // 获取本次映射需要用到的源表列表（白名单）
        $allowed_tables = array();
        if (isset($this->config['mappings']) && is_array($this->config['mappings'])) {
            foreach ($this->config['mappings'] as $map) {
                if (isset($map['source_table'])) {
                    $allowed_tables[$map['source_table']] = true;
                } elseif (isset($map['source']) && isset($map['source']['table'])) {
                    // 兼容 source.table 格式
                    $allowed_tables[$map['source']['table']] = true;
                }
            }
        }

        // 放宽SQL模式
        @$temp_conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@SESSION.sql_mode,'STRICT_TRANS_TABLES',''))");
        @$temp_conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@SESSION.sql_mode,'STRICT_ALL_TABLES',''))");
        @$temp_conn->query("SET SESSION net_write_timeout=600");
        @$temp_conn->query("SET SESSION net_read_timeout=600");

        // 初始化进度：使用文件大小作为总进度基准（因为记录数未知）
        $this->total_records = filesize($source_file);
        $this->imported_records = 0;
        $this->update_progress($this->total_records, 0, 0, '正在准备导入SQL文件...');

        // 尝试从文件综合生成表结构（针对数据-only SQL 或 结构解析失败的情况）
        // 这确保了即使SQL文件中没有 CREATE TABLE 语句，也能创建出临时表（使用 LONGTEXT 类型）
        $synthesized_sql = $this->synthesize_tables_from_sql_file($source_file, $temp_table_prefix, $allowed_tables);

        // ------------------ 进度优化：预扫描记录数 ------------------
        require_once dirname(__FILE__) . '/sql-parser.php';
        $parser = new SQLStructureParser();
        $this->update_job_status('processing', '正在扫描文件计算总记录数...');
        $table_counts = $parser->scan_insert_counts($source_file);
        
        // 计算白名单表及其临时表的总记录数
        $total_rows = 0;
        foreach ($table_counts as $t => $c) {
            // 检查该表是否在白名单中
            if (isset($allowed_tables[$t])) {
                $total_rows += $c;
            }
        }
        
        // 如果成功扫描到记录数，切换为记录数进度模式
        $use_record_progress = false;
        if ($total_rows > 0) {
            $this->total_records = $total_rows;
            $this->imported_records = 0;
            $use_record_progress = true;
            error_log("[Import Debug] Total records to import: " . $total_rows);
        }
        // ---------------------------------------------------------

        if (!empty($synthesized_sql)) {
            error_log("[Import Debug] Synthesized table structure SQL length: " . strlen($synthesized_sql));
            // 执行综合的建表语句
            if ($temp_conn->multi_query($synthesized_sql)) {
                while ($temp_conn->more_results() && $temp_conn->next_result()) {
                    ;
                } // flush results
                error_log("[Import Debug] Synthesized tables created successfully.");
            } else {
                $error_msg = "[Import Debug] Synthesized table creation failed: " . $temp_conn->error;
                error_log($error_msg);
                if (method_exists($this, 'append_job_error')) {
                    $this->append_job_error($error_msg);
                }
            }
        } else {
            error_log("[Import Debug] No table structure synthesized from file.");
        }

        // 使用流式读取处理大文件
        $handle = fopen($source_file, "r");
        if (!$handle) {
            throw new Exception('无法打开SQL文件');
        }

        $buffer = '';
        $delimiter = ';';
        $in_string = false;
        $string_char = '';
        $escaped = false;

        // 进度更新阈值（每 50KB 更新一次数据库，确保进度条流畅）
        $bytes_processed = 0;
        $last_update_bytes = 0;
        $update_interval = 50 * 1024;

        // 统计信息
        $stats = array(
            'total_statements' => 0,
            'insert_statements' => 0,
            'insert_allowed' => 0,
            'insert_executed' => 0,
            'create_table_statements' => 0,
            'lines_read' => 0,
            'lines_with_semicolon' => 0,
            'inserted_rows' => 0  // 累计插入的记录数
        );
        
        // 进度更新计数器（每插入一定数量记录后更新一次进度）
        $last_progress_update_rows = 0;
        $progress_update_interval = 1; // 每插入1条记录更新一次进度（确保实时反馈）

        try {
            while (($line = fgets($handle)) !== false) {
                $stats['lines_read']++;
                $bytes_processed += strlen($line);
                if (!$use_record_progress) {
                    $this->imported_records = $bytes_processed;
                } else {
                    $this->imported_records = $stats['inserted_rows'];
                }

                $line_trimmed = trim($line);

                // 调试：记录前10行的内容
                if ($stats['lines_read'] <= 10) {
                    $line_preview = substr($line, 0, 100);
                    $has_semi = (strpos($line_trimmed, ';') !== false) ? 'YES' : 'NO';
                    error_log("[Import Debug] Line #{$stats['lines_read']}: " . addcslashes($line_preview, "\n\r\t") . " (has semicolon: {$has_semi})");
                }

                // 定期更新进度（基于文件读取字节数）
                if (($bytes_processed - $last_update_bytes) > $update_interval) {
                    if ($use_record_progress) {
                         // 在记录数模式下，imported_records 就是 inserted_rows
                         $status_text = sprintf('正在导入SQL文件... (%d/%d 行)', $this->imported_records, $this->total_records);
                         $this->update_progress($this->total_records, $this->imported_records, 0, $status_text);
                    } else {
                        // 显示已处理字节大小和已插入行数
                        $processed_mb = round($this->imported_records / 1024 / 1024, 2);
                        $total_mb = round($this->total_records / 1024 / 1024, 2);
                        $status_text = sprintf('正在导入SQL文件... (%sMB/%sMB, %d 行已插入)', $processed_mb, $total_mb, $stats['inserted_rows']);
                        $this->update_progress($this->total_records, $this->imported_records, 0, $status_text);
                    }
                    $last_update_bytes = $bytes_processed;
                }

                // 跳过空行和注释行（仅当 buffer 为空时）
                if ($buffer === '' && ($line_trimmed === '' || strpos($line_trimmed, '--') === 0 || strpos($line_trimmed, '/*') === 0 || strpos($line_trimmed, '#') === 0)) {
                    continue;
                }

                // 改进的分号检测：检查行尾是否有分号（可能后面有空格或换行）
                $buffer .= $line;

                // 检查当前行是否包含分号（可能在行尾，也可能后面有空格）
                $has_semicolon = false;
                if (preg_match('/;\s*$/', $line_trimmed)) {
                    $has_semicolon = true;
                    $stats['lines_with_semicolon']++;
                }

                if ($has_semicolon) {
                    $sql_stmt = trim($buffer);
                    $buffer = '';

                    if ($sql_stmt === '')
                        continue;

                    // 统计和调试
                    $stats['total_statements']++;
                    $stmt_type = 'UNKNOWN';
                    if (stripos($sql_stmt, 'CREATE TABLE') !== false) {
                        $stmt_type = 'CREATE TABLE';
                        $stats['create_table_statements']++;
                    } elseif (stripos($sql_stmt, 'INSERT') !== false) {
                        $stmt_type = 'INSERT';
                        $stats['insert_statements']++;
                    } elseif (stripos($sql_stmt, 'REPLACE') !== false) {
                        $stmt_type = 'REPLACE';
                    } elseif (stripos($sql_stmt, 'UPDATE') !== false) {
                        $stmt_type = 'UPDATE';
                    } elseif (stripos($sql_stmt, 'DELETE') !== false) {
                        $stmt_type = 'DELETE';
                    } elseif (stripos($sql_stmt, 'SET') !== false) {
                        $stmt_type = 'SET';
                    }

                    // 调试：记录前几条 SQL 语句的类型
                    if ($stats['total_statements'] <= 10) {
                        error_log("[Import Debug] SQL Statement #{$stats['total_statements']}: {$stmt_type} (length: " . strlen($sql_stmt) . ")");
                        if ($stmt_type === 'INSERT' && $stats['insert_statements'] <= 3) {
                            error_log("[Import Debug] First INSERT statement preview: " . substr($sql_stmt, 0, 300));
                        }
                    }

                    // 提取表名并检查是否在白名单中
                    $is_allowed = $this->is_table_allowed($sql_stmt, $allowed_tables);
                    if ($is_allowed) {
                        if ($stmt_type === 'INSERT') {
                            $stats['insert_allowed']++;
                        }
                        // 规范化SQL（添加前缀等）
                        $sanitized_sql = $this->sanitize_sql_for_temp_db($sql_stmt, $temp_table_prefix);

                        // 调试日志：记录即将执行的SQL
                        if (stripos($sanitized_sql, 'CREATE TABLE') !== false) {
                            error_log("[Import Debug] Creating table with SQL: " . substr($sanitized_sql, 0, 200) . "...");
                        } elseif (stripos($sanitized_sql, 'INSERT') !== false) {
                            // 记录INSERT语句的执行（每100条记录一次，避免日志过多）
                            static $insert_count = 0;
                            $insert_count++;
                            if ($insert_count % 100 == 1 || $insert_count <= 5) {
                                if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_]+)`?/i', $sanitized_sql, $matches)) {
                                    $table_name = $matches[1];
                                    error_log("[Import Debug] Executing INSERT into table: {$table_name} (count: {$insert_count})");
                                }
                            }
                        }

                        // 执行
                        if (!$temp_conn->query($sanitized_sql)) {
                            // 忽略表已存在等非致命错误
                            $err = $temp_conn->error;
                            $errno = $temp_conn->errno;
                            error_log("[Import Debug] SQL Execution Failed: Error $errno - $err. SQL: " . substr($sanitized_sql, 0, 200));

                            if ($errno !== 1050 && $errno !== 1007) { // 1050: Table exists, 1007: DB exists
                                $error_msg = "SQL执行警告: " . $err . " [SQL片段: " . substr($sql_stmt, 0, 50) . "...]";
                                error_log($error_msg);
                                if (method_exists($this, 'append_job_error')) {
                                    $this->append_job_error($error_msg);
                                }
                            }
                        } else {
                            if (stripos($sanitized_sql, 'CREATE TABLE') !== false) {
                                // 提取表名用于日志
                                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sanitized_sql, $matches)) {
                                    $created_table = $matches[1];
                                    error_log("[Import Debug] Table created successfully: {$created_table}");
                                } else {
                                    error_log("[Import Debug] Table created successfully.");
                                }
                            } elseif (stripos($sanitized_sql, 'INSERT') !== false) {
                                $stats['insert_executed']++;
                                
                                // 估算INSERT语句中的记录数（通过统计VALUES的数量）
                                $estimated_rows = 1; // 默认1条
                                if (preg_match('/VALUES\s*\(/i', $sanitized_sql)) {
                                    // 统计VALUES的数量（简单估算：统计 '),(' 或 '), (' 的数量）
                                    $values_count = preg_match_all('/\)\s*,\s*\(/i', $sanitized_sql) + 1;
                                    $estimated_rows = max(1, $values_count);
                                }
                                
                                // 获取INSERT语句影响的行数（使用affected_rows，更准确）
                                $affected_rows = $temp_conn->affected_rows;
                                if ($affected_rows > 0) {
                                    $rows_to_add = $affected_rows;
                                } else {
                                    // 如果affected_rows为0或不可用，使用估算值
                                    $rows_to_add = $estimated_rows;
                                }
                                
                                // 如果估算值也为0，至少算1条（避免进度不更新）
                                if ($rows_to_add == 0) {
                                    $rows_to_add = 1;
                                }
                                
                                $stats['inserted_rows'] += $rows_to_add;
                                // 移除冲突的行数进度更新，统一使用文件读取字节进度更新
                            }
                        }
                    } else {
                        // 调试日志：记录被跳过的语句
                        if (stripos($sql_stmt, 'CREATE TABLE') !== false) {
                            $msg = "[Debug] Skipped CREATE TABLE: " . substr($sql_stmt, 0, 50) . "...";
                            error_log($msg);
                            $this->append_job_error($msg);
                        } elseif (stripos($sql_stmt, 'INSERT') !== false) {
                            // 记录被跳过的INSERT语句（前10条）
                            static $skipped_insert_count = 0;
                            $skipped_insert_count++;
                            if ($skipped_insert_count <= 10) {
                                if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_]+)`?/i', $sql_stmt, $matches)) {
                                    $table_name = $matches[1];
                                    $msg = "[Debug] Skipped INSERT into table '{$table_name}' (not in allowed list)";
                                    error_log($msg);
                                    if ($skipped_insert_count == 1) {
                                        $this->append_job_error("INSERT语句被跳过，因为表 '{$table_name}' 不在允许列表中。");
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            fclose($handle);
            throw $e;
        }

        // 处理文件末尾可能剩余的 SQL 语句（没有分号结尾的情况）
        if (!empty($buffer)) {
            $sql_stmt = trim($buffer);
            if ($sql_stmt !== '') {
                error_log("[Import Debug] Processing remaining SQL statement (no semicolon at end): " . substr($sql_stmt, 0, 100));
                $is_allowed = $this->is_table_allowed($sql_stmt, $allowed_tables);
                if ($is_allowed) {
                    $sanitized_sql = $this->sanitize_sql_for_temp_db($sql_stmt, $temp_table_prefix);
                    if (!$temp_conn->query($sanitized_sql)) {
                        $err = $temp_conn->error;
                        $errno = $temp_conn->errno;
                        error_log("[Import Debug] Final SQL execution failed: Error $errno - $err");
                    }
                }
            }
        }

        fclose($handle);

        // 输出统计信息
        error_log("[Import Debug] SQL Import Statistics:");
        error_log("[Import Debug]   Lines read: {$stats['lines_read']}");
        error_log("[Import Debug]   Lines with semicolon: {$stats['lines_with_semicolon']}");
        error_log("[Import Debug]   Total statements: {$stats['total_statements']}");
        error_log("[Import Debug]   CREATE TABLE statements: {$stats['create_table_statements']}");
        error_log("[Import Debug]   INSERT statements found: {$stats['insert_statements']}");
        error_log("[Import Debug]   INSERT statements allowed: {$stats['insert_allowed']}");
        error_log("[Import Debug]   INSERT statements executed: {$stats['insert_executed']}");

        // 验证所需的临时表是否创建成功
        $this->verify_temp_tables_created($temp_conn, $temp_table_prefix, $allowed_tables);

        // 统计每个临时表的记录数，并计算总记录数
        $total_temp_records = 0;
        foreach ($allowed_tables as $table_name => $_) {
            $full_table_name = $temp_table_prefix . $table_name;
            $like = strtr($full_table_name, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
            $like = $temp_conn->real_escape_string($like);
            $result = $temp_conn->query("SELECT COUNT(*) as cnt FROM `{$full_table_name}`");
            if ($result) {
                $row = $result->fetch_assoc();
                $count = intval($row['cnt']);
                $total_temp_records += $count;
                error_log("[Import Debug] Temporary table '{$full_table_name}' has {$count} rows after SQL import.");
                $result->free();
            }
        }

        // 阶段转换：从字节进度切换到记录数进度
        // 无论如何都更新总记录数为临时表的实际记录数
        if ($total_temp_records > 0) {
            $this->total_records = $total_temp_records;
        } else {
            // 如果没查到记录（可能是空表），但为了后续逻辑正常，重置为0或保持（如果是异常）
            // 如果之前是字节数，这里必须重置，否则后续会显示字节数
             $this->total_records = 0;
        }
        
        // 重置已导入记录数（为写入目标表做准备）
        $this->imported_records = 0;
        
        $temp_conn->close();
        
        // 更新进度：临时表创建完成
        if ($this->total_records > 0) {
            $status_text = sprintf('临时表创建完成，共 %d 条记录，正在准备写入目标表...', $this->total_records);
            // 此时 imported_records 为 0，进度条会重置，这是正常的阶段切换
            $this->update_progress($this->total_records, 0, 0, $status_text);
        } else {
            $this->update_progress(0, 0, 0, '临时表创建完成，正在准备写入目标表...');
        }
    }

    /**
     * 验证临时表是否创建成功
     */
    private function verify_temp_tables_created($conn, $temp_table_prefix, $allowed_tables)
    {
        if (empty($allowed_tables)) {
            error_log("[Import Debug] No allowed tables to verify.");
            return;
        }

        // 列出所有已创建的临时表（用于诊断）
        $like_prefix = strtr($temp_table_prefix, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
        $like_prefix = $conn->real_escape_string($like_prefix);
        $all_temp_tables = array();
        $result = $conn->query("SHOW TABLES LIKE '{$like_prefix}%'");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $all_temp_tables[] = $row[0];
            }
            $result->free();
        }
        error_log("[Import Debug] All temporary tables found: " . (empty($all_temp_tables) ? 'None' : implode(', ', $all_temp_tables)));

        $missing_tables = array();
        foreach ($allowed_tables as $table_name => $_) {
            $full_table_name = $temp_table_prefix . $table_name;
            $like = strtr($full_table_name, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
            $like = $conn->real_escape_string($like);

            $result = $conn->query("SHOW TABLES LIKE '{$like}'");
            if (!$result || $result->num_rows === 0) {
                $missing_tables[] = $table_name . " (期望表名: {$full_table_name})";
                error_log("[Import Debug] Temporary table not found: {$full_table_name}");
            } else {
                error_log("[Import Debug] Temporary table verified: {$full_table_name}");
            }
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }

        if (!empty($missing_tables)) {
            $msg = "临时表创建失败，以下表未找到: " . implode(', ', $missing_tables);
            if (!empty($all_temp_tables)) {
                $msg .= "。已创建的临时表: " . implode(', ', $all_temp_tables);
            } else {
                $msg .= "。未找到任何临时表，可能是SQL文件解析失败或表名不匹配。";
            }
            error_log("[Import Error] " . $msg);
            if (method_exists($this, 'append_job_error')) {
                $this->append_job_error($msg);
            }
            throw new Exception($msg);
        }

        error_log("[Import Debug] All required temporary tables verified successfully.");
    }

    /**
     * 检查SQL语句操作的表是否在允许列表中
     * 优化：只处理SQL语句的开头部分，避免内存耗尽（对于大型INSERT语句）
     */
    private function is_table_allowed($sql, $allowed_tables)
    {
        if (empty($allowed_tables))
            return false; // 如果没有映射配置，不创建任何临时表，避免创建无用表

        // 只处理SQL语句的前500个字符来提取表名，避免处理大型INSERT语句时内存耗尽
        // 对于INSERT语句，表名总是在语句开头，不需要处理后面的VALUES数据
        $sql_preview = substr($sql, 0, 500);

        // 移除SQL语句中的换行和多余空白，便于匹配（只处理预览部分）
        $sql_normalized = preg_replace('/\s+/', ' ', trim($sql_preview));

        // 匹配 CREATE TABLE `xxx`, INSERT [IGNORE] INTO `xxx` 以及带库名前缀的情况 `db`.`xxx`
        // 改进：支持多行SQL，正确处理反引号包裹的表名
        $patterns = array(
            // 匹配 CREATE TABLE `table_name` 或 CREATE TABLE IF NOT EXISTS `table_name`
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            // 匹配 INSERT INTO `table_name` 或 INSERT IGNORE INTO `table_name`
            '/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            // 匹配 REPLACE INTO `table_name`
            '/REPLACE\s+INTO\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            // 匹配 UPDATE `table_name`
            '/UPDATE\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            // 匹配 ALTER TABLE `table_name`
            '/ALTER\s+TABLE\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            // 匹配 DROP TABLE `table_name`
            '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i'
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql_normalized, $matches)) {
                $table = isset($matches[1]) ? $matches[1] : '';
                if ($table !== '') {
                    $is_allowed = isset($allowed_tables[$table]);
                    // 调试：记录前几条INSERT语句的匹配情况
                    if (stripos($sql_normalized, 'INSERT') !== false) {
                        static $debug_count = 0;
                        $debug_count++;
                        if ($debug_count <= 5) {
                            error_log("[Import Debug] is_table_allowed: INSERT into '{$table}' - " . ($is_allowed ? 'ALLOWED' : 'BLOCKED'));
                        }
                    }
                    return $is_allowed;
                }
            }
        }

        // 匹配 SET, COMMIT 等非表操作语句，放行（只检查开头）
        $sql_start = trim(substr($sql, 0, 50));
        if (preg_match('/^(?:SET|COMMIT|START|BEGIN|ROLLBACK|USE|LOCK|UNLOCK)/i', $sql_start)) {
            return true;
        }

        return false;
    }

    /**
     * 执行数据导入（从临时表到目标表）
     */
    private function execute_import()
    {
        $mappings = isset($this->config['mappings']) ? $this->config['mappings'] : array();
        if (empty($mappings)) {
            throw new Exception('任务配置缺少 mappings 或格式不正确');
        }

        // 兼容前端以字段级映射（mappingRelations）提交的情况：按表进行归组
        if (isset($mappings[0]) && is_array($mappings[0]) && !isset($mappings[0]['source_table']) && isset($mappings[0]['source']) && isset($mappings[0]['target'])) {
            $grouped = array();
            foreach ($mappings as $item) {
                if (!is_array($item) || !isset($item['source']) || !isset($item['target'])) {
                    continue;
                }
                $src = $item['source'];
                $tgt = $item['target'];
                $src_table = isset($src['table']) ? (string) $src['table'] : '';
                $tgt_table = isset($tgt['table']) ? (string) $tgt['table'] : '';
                $src_field = isset($src['field']) ? (string) $src['field'] : '';
                $tgt_field = isset($tgt['field']) ? (string) $tgt['field'] : '';
                if ($src_table === '' || $tgt_table === '' || $src_field === '' || $tgt_field === '') {
                    continue;
                }
                $key = $src_table . '|' . $tgt_table;
                if (!isset($grouped[$key])) {
                    $grouped[$key] = array(
                        'source_table' => $src_table,
                        'target_table' => $tgt_table,
                        'field_mappings' => array()
                    );
                }
                $fm = array('source_field' => $src_field, 'target_field' => $tgt_field);
                if (isset($tgt['type']) && is_string($tgt['type'])) {
                    $fm['target_type'] = $tgt['type'];
                }
                // 注意：不要将数据库 schema 的 DEFAULT 值（如 ''、0 等）误当作 source_default
                // source_default 仅应由用户在 UI 输入框中显式填写的固定值/正则替换值决定
                // 否则会导致所有行的实际数据被无差别替换为 schema 默认值（如标题全部变成 ''）
                if (isset($src['user_default']) && $src['user_default'] !== '') {
                    $fm['source_default'] = $src['user_default'];
                }
                $grouped[$key]['field_mappings'][] = $fm;
            }
            $mappings = array_values($grouped);
            $this->config['mappings'] = $mappings;
        }

        // 不再重置进度计数，继续使用临时表创建阶段的进度
        // 如果总记录数还未设置（异常情况），则从临时表查询
        if ($this->total_records == 0) {
            $this->update_job_status('processing', '正在计算数据总量...');
            
            // 从临时表查询总记录数
            foreach ($mappings as $mapping) {
                $source_table = '';
                // 提取源表名
                if (isset($mapping['source']['table'])) {
                    $source_table = $mapping['source']['table'];
                } elseif (isset($mapping['source_table'])) {
                    $source_table = $mapping['source_table'];
                }

                if ($source_table) {
                    // 添加临时表前缀
                    $temp_table_prefix = isset($this->config['temp_table_prefix']) ? $this->config['temp_table_prefix'] : '';
                    $full_source_table = $temp_table_prefix . $source_table;

                    // 检查表是否存在
                    $check = $this->source_db->query("SHOW TABLES LIKE '{$full_source_table}'");
                    if ($check && $check->num_rows > 0) {
                        $count_res = $this->source_db->query("SELECT COUNT(*) as cnt FROM `{$full_source_table}`");
                        if ($count_res) {
                            $row = $count_res->fetch_assoc();
                            $this->total_records += intval($row['cnt']);
                            $count_res->free();
                        }
                    }
                    if ($check instanceof mysqli_result) {
                        $check->free();
                    }
                }
            }
        }
        
        // 目标表写入阶段：重新开始进度计算（从0到总记录数）
        // 确保已导入记录数为0
        $this->imported_records = 0;
        $this->failed_records = 0;

        // 更新初始进度
        error_log("[Import Debug] Total records to import: " . $this->total_records);
        $this->update_job_status('processing', '正在写入数据到目标表...');

        $target_written_records = 0; // 统计写入目标表的记录数（仅用于日志或调试，实际进度使用 imported_records）

        $mapping_index = 0;
        $total_mappings = count($mappings);
        foreach ($mappings as $mapping) {
            $mapping_index++;
            try {
                // 在处理每个映射前检查连接
                $this->ensure_source_db_connected();

                // 传递映射索引信息用于状态显示
                // 注意：这里不再传递 total_imported 等累加值给 import_table_mapping 的 update_progress，
                // 而是让 import_table_mapping 直接更新全局的 $this->imported_records 和 $this->failed_records
                $result = $this->import_table_mapping($mapping, $mapping_index, $total_mappings);

                // 累加结果（注意：import_table_mapping 内部已经更新了全局计数器）
                // 这里不需要再手动累加到 imported_records

            } catch (Exception $e) {
                error_log('表映射导入失败: ' . $e->getMessage());
                if (method_exists($this, 'append_job_error')) {
                    $this->append_job_error('表映射导入失败: ' . $e->getMessage());
                }
                $this->failed_records++;
            }
        }

        return array(
            'total' => $this->total_records,
            'imported' => $this->imported_records,
            'failed' => $this->failed_records
        );
    }

    /**
     * 处理单个表的映射导入
     */
    private function import_table_mapping($mapping, $mapping_index = 1, $total_mappings = 1)
    {
        if (!is_array($mapping)) {
            throw new Exception('表映射配置格式错误');
        }
        $source_table = isset($mapping['source_table']) ? trim($mapping['source_table']) : '';
        $target_table = isset($mapping['target_table']) ? trim($mapping['target_table']) : '';

        // 更新状态：显示当前处理的表（在方法开始时）
        if ($source_table && $target_table) {
            $status_text = sprintf('正在写入数据到目标表 %s (%d/%d)...', $target_table, $mapping_index, $total_mappings);
            $this->update_job_status('processing', $status_text);
        }

        // 如果配置了临时表前缀，确保源表名包含前缀
        if (isset($this->config['temp_table_prefix']) && !empty($this->config['temp_table_prefix'])) {
            $temp_table_prefix = $this->config['temp_table_prefix'];
            // 如果源表名不包含临时前缀，添加前缀
            if (strpos($source_table, $temp_table_prefix) !== 0) {
                $source_table = $temp_table_prefix . $source_table;
            }
        }

        // 兼容多种字段映射格式
        $field_mappings = isset($mapping['field_mappings']) ? $mapping['field_mappings'] : null;
        if ($field_mappings === null && isset($mapping['fields'])) {
            $field_mappings = $mapping['fields']; // 兼容字段键为 fields 的旧格式
        }
        if ($field_mappings === null && isset($mapping['field_mapping'])) {
            $field_mappings = $mapping['field_mapping']; // 兼容单数命名
        }

        // 归一化字段映射：
        // 支持以下输入形式：
        // 1) [ {source_field: 'a', target_field: 'b', target_type: 'int'} , ... ]
        // 2) { 'a': 'b', 'c': 'd' } （键值对）
        // 3) [ {source: 'a', target: 'b'} , ... ]
        if (is_array($field_mappings) && !empty($field_mappings)) {
            $normalized = array();
            // 判断是否为关联映射（a=>b）
            $is_assoc_map = false;
            $i = 0;
            foreach ($field_mappings as $k => $v) {
                $i++;
                if (!is_int($k)) {
                    $is_assoc_map = true;
                    break;
                }
                if ($i > 1)
                    break;
            }
            if ($is_assoc_map) {
                foreach ($field_mappings as $src => $tgt) {
                    if (is_string($src) && (is_string($tgt) || is_numeric($tgt))) {
                        $normalized[] = array('source_field' => $src, 'target_field' => (string) $tgt);
                    } elseif (is_array($tgt)) {
                        if (isset($tgt['source_field']) && isset($tgt['target_field'])) {
                            // 保留 source_default 字段（如果存在）
                            if (isset($tgt['source_default'])) {
                                $normalized[] = $tgt;
                            } else {
                                $normalized[] = $tgt;
                            }
                        } elseif (isset($tgt['source']) && isset($tgt['target'])) {
                            $tgt['source_field'] = $tgt['source'];
                            $tgt['target_field'] = $tgt['target'];
                            // 保留 source_default 字段（如果存在）
                            if (isset($tgt['source_default'])) {
                                // 已经存在，保持不变
                            }
                            $normalized[] = $tgt;
                        }
                    }
                }
            } else {
                foreach ($field_mappings as $item) {
                    if (is_array($item)) {
                        if (isset($item['source_field']) && isset($item['target_field'])) {
                            // 保留所有字段，包括 source_default
                            $normalized[] = $item;
                        } elseif (isset($item['source']) && isset($item['target'])) {
                            $item['source_field'] = $item['source'];
                            $item['target_field'] = $item['target'];
                            // 保留 source_default 字段（如果存在）
                            if (isset($item['source_default'])) {
                                // 已经存在，保持不变
                            }
                            $normalized[] = $item;
                        } elseif (count($item) === 1) {
                            $src = array_key_first($item);
                            $tgt = $item[$src];
                            if (is_string($src) && (is_string($tgt) || is_numeric($tgt))) {
                                $normalized[] = array('source_field' => $src, 'target_field' => (string) $tgt);
                            }
                        }
                    }
                }
            }
            $field_mappings = $normalized;
        }

        if ($source_table === '' || $target_table === '' || !is_array($field_mappings) || empty($field_mappings)) {
            throw new Exception('表映射配置不完整：缺少 source_table/target_table/field_mappings');
        }

        // 优先保证主键列排在前面（避免某些场景下依赖主键的触发器/外键检查导致的插入失败）
        $pk_columns = $this->get_primary_key_columns($target_table);
        if (!empty($pk_columns)) {
            $pk_first = array();
            $others = array();
            foreach ($field_mappings as $fm) {
                $tgt = isset($fm['target_field']) ? (string) $fm['target_field'] : '';
                if ($tgt !== '' && in_array(strtolower($tgt), $pk_columns, true)) {
                    $pk_first[] = $fm;
                } else {
                    $others[] = $fm;
                }
            }
            // 仅当确实有主键映射时才重排
            if (!empty($pk_first)) {
                $field_mappings = array_merge($pk_first, $others);
            }
        }

        // 构建查询语句
        $source_fields = array();
        $target_fields = array();
        $mapped_target_field_names = array();

        foreach ($field_mappings as $field_mapping) {
            if (!isset($field_mapping['source_field']) || !isset($field_mapping['target_field'])) {
                throw new Exception('字段映射缺少 source_field 或 target_field');
            }
            $source_fields[] = '`' . $field_mapping['source_field'] . '`';
            $target_fields[] = '`' . $field_mapping['target_field'] . '`';
            $mapped_target_field_names[] = $field_mapping['target_field'];
        }

        // 检测目标表中未映射但为 NOT NULL 且无默认值的字段，自动填充默认值，防止 1364 错误（排除主键列）
        $mapped_target_field_names_lower = array_map('strtolower', $mapped_target_field_names);
        $additional_defaults = $this->detect_and_prepare_required_defaults($target_table, $mapped_target_field_names_lower, $pk_columns);
        $additional_default_fields = array_keys($additional_defaults);
        if (!empty($additional_default_fields)) {
            foreach ($additional_default_fields as $f) {
                $target_fields[] = '`' . $f . '`';
            }
        }
        $default_values_ordered = array_values($additional_defaults);

        // 确保源数据库连接有效
        $this->ensure_source_db_connected();

        // 确保源临时库存在所需的源表；若缺失则抛出异常终止该映射，避免创建空表造成错误导入
        if ($this->source_db instanceof mysqli) {
            // 使用 LIKE 并转义通配符，避免误匹配
            $like = strtr($source_table, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
            $like = $this->source_db->real_escape_string($like);
            $chk = @$this->source_db->query("SHOW TABLES LIKE '{$like}'");

            // 如果查询失败且是连接错误，尝试重连
            if (!$chk && ($this->source_db->errno === 2006 || strpos($this->source_db->error, 'gone away') !== false)) {
                $this->reconnect_source_database();
                $like = $this->source_db->real_escape_string($like);
                $chk = @$this->source_db->query("SHOW TABLES LIKE '{$like}'");
            }

            $exists = $chk && $chk->num_rows > 0;
            if ($chk instanceof mysqli_result) {
                $chk->free();
            }

            // 调试日志：记录表存在性检查结果
            error_log("[Import Debug] Checking table existence: '$source_table' (LIKE '$like'). Exists: " . ($exists ? 'Yes' : 'No'));

            if (!$exists) {
                // 记录到任务错误信息，并终止该映射
                $msg = "源临时库缺少表 {$source_table}，已终止导入";
                if (method_exists($this, 'append_job_error')) {
                    $this->append_job_error($msg);
                }
                throw new Exception($msg);
            }
        }

        // 查询源数据
        $source_query = "SELECT " . implode(', ', $source_fields) . " FROM `{$source_table}`";
        $source_result = $this->source_db->query($source_query);

        // 如果查询失败且是连接错误，尝试重连后重试
        if (!$source_result && ($this->source_db->errno === 2006 || strpos($this->source_db->error, 'gone away') !== false)) {
            $this->reconnect_source_database();
            $source_result = $this->source_db->query($source_query);
        }

        if (!$source_result) {
            throw new Exception('查询源表失败: ' . $this->source_db->error);
        }

        // 可选：清空目标表（仅当 import_config 指定），位置已调整为在确认源表存在且查询成功之后再执行，避免源表缺失时误清空目标表
        $do_truncate = !empty($this->import_config['truncate_before_import']);
        if ($do_truncate && $target_table && empty($this->truncated_tables[$target_table])) {
            try {
                $this->target_db->query("SET FOREIGN_KEY_CHECKS = 0");
                $this->target_db->query("TRUNCATE TABLE `{$target_table}`");
                $this->target_db->query("SET FOREIGN_KEY_CHECKS = 1");
                $this->truncated_tables[$target_table] = true;
            } catch (Exception $e) {
                // 尝试恢复外键检查
                try {
                    $this->target_db->query("SET FOREIGN_KEY_CHECKS = 1");
                } catch (Exception $ex) {
                }
                // 不中断流程，仅记录
                error_log('TRUNCATE 失败: ' . $e->getMessage());
            }
        }

        $total = $source_result->num_rows;
        error_log("[Import Debug] Source table '$source_table' has $total rows.");
        $imported = 0;
        $failed = 0;

        // 提取目标端正则处理映射（以 |/ 开头），在数据拷贝完成后对目标表字段进行正则替换
        $target_regex_mappings = array();
        foreach ($field_mappings as $i => $fm) {
            if (isset($fm['source_default']) && $fm['source_default'] !== '' && strpos($fm['source_default'], '|/') === 0) {
                $target_regex_mappings[] = $fm;
            }
        }

        // 准备插入/合并语句
        $insert_ignore = !empty($this->import_config['ignore_duplicates']) ? ' IGNORE' : '';
        $insert_prefix = "INSERT{$insert_ignore} INTO `{$target_table}` (" . implode(', ', $target_fields) . ") VALUES ";

        // 根据唯一键准备合并更新子句（当不选择 ignore_duplicates 时生效）
        $update_clause = '';
        if (empty($this->import_config['ignore_duplicates'])) {
            $unique_keys = $this->get_unique_key_columns($target_table);
            $updatable_cols = array();
            foreach ($field_mappings as $fm) {
                $col = isset($fm['target_field']) ? (string) $fm['target_field'] : '';
                if ($col !== '' && !isset($unique_keys[strtolower($col)])) {
                    $updatable_cols[] = $col;
                }
            }
            if (!empty($updatable_cols)) {
                $assigns = array();
                foreach ($updatable_cols as $c) {
                    $assigns[] = "`{$c}` = VALUES(`{$c}`)";
                }
                $update_clause = ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assigns);
            }
        }

        $target_query = $insert_prefix;
        $placeholders = '(' . implode(', ', array_fill(0, count($target_fields), '?')) . ')';

        // 批量插入
        $batch_size = isset($this->import_config['batch_size']) ? max(1, intval($this->import_config['batch_size'])) : 100;
        $batch_data = array();
        $batch_count = 0;

        while ($row = $source_result->fetch_assoc()) {
            $values = array();
            foreach ($field_mappings as $field_mapping) {
                $source_field = $field_mapping['source_field'];
                
                // 获取源字段值
                $value = isset($row[$source_field]) ? $row[$source_field] : null;
                
                // 检查是否有默认值或正则表达式处理（source.default）
                // 目标端正则（以 |/ 开头）在数据拷贝完成后单独处理，此处跳过
                $is_target_regex = isset($field_mapping['source_default']) && strpos($field_mapping['source_default'], '|/') === 0;
                if (isset($field_mapping['source_default']) && $field_mapping['source_default'] !== '' && !$is_target_regex) {
                    $default_value = $field_mapping['source_default'];
                    
                    // 检测是否为正则表达式格式：/pattern#replacement/ 或 /pattern#replacement/flags
                    // 使用 # 作为分隔符，避免 replacement 中包含 / 时的问题
                    // 格式示例：/^(\/uploads)#/content$1/ 会将 /uploads 替换为 /content/uploads
                    // 检测规则：以 / 开头，包含 # 分隔符，以 / 结尾（可选 flags）
                    // 使用字符串函数进行检测，避免正则表达式检测中的转义问题
                    $is_regex_format = false;
                    $pattern = '';
                    $replacement = '';
                    $flags = '';
                    
                    if (strlen($default_value) >= 3 && $default_value[0] === '/') {
                        // 查找第一个未被转义的 # 分隔符（跳过 \#）
                        // 计算 \# 的数量来判断哪个 # 是真正的分隔符
                        $hash_pos = false;
                        $len = strlen($default_value);
                        for ($i = 1; $i < $len; $i++) {
                            if ($default_value[$i] === '#') {
                                // 统计 # 前面连续反斜杠的数量
                                $bs_count = 0;
                                for ($j = $i - 1; $j >= 0 && $default_value[$j] === '\\'; $j--) {
                                    $bs_count++;
                                }
                                // 偶数个反斜杠（含 0 个）表示 # 未被转义，是真正的分隔符
                                if ($bs_count % 2 === 0) {
                                    $hash_pos = $i;
                                    break;
                                }
                            }
                        }
                        if ($hash_pos !== false && $hash_pos > 1) {
                            // 提取 pattern（从第二个字符到 # 之前），并将转义的 \# 还原为 #
                            $pattern = substr($default_value, 1, $hash_pos - 1);
                            // 还原 pattern 中转义的 \# → #，\\ → \
                            $pattern = preg_replace('/\\\\([#\\\\])/', '$1', $pattern);
                            // 提取 replacement 和 flags（从 # 之后）
                            $after_hash = substr($default_value, $hash_pos + 1);
                            // 查找最后一个 / 的位置（可能是 flags 的结束符）
                            $last_slash_pos = strrpos($after_hash, '/');
                            if ($last_slash_pos !== false) {
                                // 有 flags：replacement 在最后一个 / 之前，flags 在最后一个 / 之后
                                $replacement = substr($after_hash, 0, $last_slash_pos);
                                $flags = substr($after_hash, $last_slash_pos + 1);
                                // 检查 flags 是否为有效的正则表达式标志（仅包含字母）
                                if (preg_match('/^[a-zA-Z]*$/', $flags)) {
                                    $is_regex_format = true;
                                }
                            } elseif ($last_slash_pos === false) {
                                // 没有 flags，整个 after_hash 都是 replacement，但必须以 / 结尾
                                // 实际上，如果没有最后一个 /，那么这不是有效的正则格式
                                // 但为了兼容，如果 after_hash 不为空，我们也认为是正则格式
                                if (!empty($after_hash)) {
                                    $replacement = $after_hash;
                                    $flags = '';
                                    $is_regex_format = true;
                                }
                            }
                        }
                    }
                    
                    if ($is_regex_format) {
                        // 正则表达式格式：对源字段值进行替换处理
                        // 如果源字段值为空，跳过正则处理（不应用正则表达式）
                        if ($value === null || $value === '') {
                            // 源字段值为空，不处理，保持为 null 或空字符串
                            // 后续会在 transform_field_value 中根据字段类型提供默认值
                        } else {
                            try {
                                // 构建完整的正则表达式
                                // 使用 # 作为分隔符，避免 pattern 中的 /（包括转义的 \/）与分隔符冲突
                                // 例如 pattern "^(\/uploads)" 若用 / 包围会变成 /^(\/uploads)// 导致非法修饰符错误
                                $regex = '#' . $pattern . '#' . $flags;
                                // 使用 preg_replace 进行替换
                                $replaced_value = @preg_replace($regex, $replacement, $value);
                                // 检查是否有错误
                                $preg_error = preg_last_error();
                                if ($preg_error === PREG_NO_ERROR && $replaced_value !== null) {
                                    $value = $replaced_value;
                                } else {
                                    // 如果 preg_replace 失败，记录错误并保持原值
                                    $error_msg = [
                                        PREG_INTERNAL_ERROR => '内部错误',
                                        PREG_BACKTRACK_LIMIT_ERROR => '回溯限制错误',
                                        PREG_RECURSION_LIMIT_ERROR => '递归限制错误',
                                        PREG_BAD_UTF8_ERROR => 'UTF-8 错误',
                                        PREG_BAD_UTF8_OFFSET_ERROR => 'UTF-8 偏移错误',
                                        PREG_JIT_STACKLIMIT_ERROR => 'JIT 堆栈限制错误'
                                    ];
                                    $error_name = isset($error_msg[$preg_error]) ? $error_msg[$preg_error] : '未知错误';
                                    error_log("[Import] 正则表达式替换失败 ({$error_name}): pattern={$pattern}, replacement={$replacement}, original_value={$value}, regex={$regex}");
                                }
                            } catch (Exception $e) {
                                // 正则表达式错误，记录但不中断导入
                                error_log("[Import] 正则表达式处理异常: " . $e->getMessage() . ", pattern={$pattern}, value={$value}");
                            }
                        }
                    } else {
                        // 普通字符串：如果填写了默认值，则直接使用默认值替换源字段值（替换所有，不判断源字段是否为空）
                        $value = $default_value;
                    }
                }

                // 数据转换
                $value = $this->transform_field_value($value, $field_mapping, $target_table);
                $values[] = $value;
            }

            // 为未映射但必须字段追加默认值
            if (!empty($default_values_ordered)) {
                $values = array_merge($values, $default_values_ordered);
            }

            $batch_data[] = $values;
            $batch_count++;

            // 达到批量大小或最后一批
            if ($batch_count >= $batch_size || $source_result->num_rows == ($imported + $failed + $batch_count)) {
                try {
                    // 在执行批量插入前检查连接
                    $this->ensure_source_db_connected();

                    $this->execute_batch_insert($target_query, $placeholders, $batch_data, $update_clause);
                    $imported += $batch_count;
                    
                    // 更新全局进度
                    $this->imported_records += $batch_count;
                    
                    $status_text = sprintf('正在写入数据到目标表 %s... (已写入 %d/%d 条记录)', $target_table, $this->imported_records, $this->total_records);
                    $this->update_progress($this->total_records, $this->imported_records, $this->failed_records, $status_text);

                } catch (Exception $e) {
                    error_log('批量插入失败: ' . $e->getMessage());
                    // 如果是连接错误，尝试重连后继续
                    if (strpos($e->getMessage(), 'gone away') !== false || strpos($e->getMessage(), '2006') !== false) {
                        try {
                            $this->reconnect_source_database();
                            $this->execute_batch_insert($target_query, $placeholders, $batch_data, $update_clause);
                            $imported += $batch_count;

                            // 更新全局进度
                            $this->imported_records += $batch_count;
                            $status_text = sprintf('正在写入数据到目标表 %s... (已写入 %d/%d 条记录)', $target_table, $this->imported_records, $this->total_records);
                            $this->update_progress($this->total_records, $this->imported_records, $this->failed_records, $status_text);

                        } catch (Exception $e2) {
                            error_log('重连后批量插入仍然失败: ' . $e2->getMessage());
                            $failed += $batch_count;
                            $this->failed_records += $batch_count;
                            // 更新失败数
                            $this->update_progress($this->total_records, $this->imported_records, $this->failed_records);
                        }
                    } else {
                        $failed += $batch_count;
                        $this->failed_records += $batch_count;
                        // 更新失败数
                        $status_text = sprintf('正在写入数据到目标表 %s... (已写入 %d/%d 条记录)', $target_table, $this->imported_records, $this->total_records);
                        $this->update_progress($this->total_records, $this->imported_records, $this->failed_records, $status_text);
                    }
                }

                $batch_data = array();
                $batch_count = 0;
            }
        }

        $source_result->free();

        // 执行目标端正则后处理（|/pattern#replacement/）
        if (!empty($target_regex_mappings)) {
            $this->post_process_target_regex($target_table, $target_regex_mappings);
        }

        return array(
            'total' => $total,
            'imported' => $imported,
            'failed' => $failed
        );
    }

    /**
     * 对目标表字段执行正则替换后处理（|/pattern#replacement/ 格式）
     * 在数据从源表拷贝到目标表完成后，对目标表中的数据直接进行正则替换
     * 使用目标表的主键作为唯一索引定位行
     */
    private function post_process_target_regex($target_table, $target_regex_mappings)
    {
        // 获取主键列
        $pk_columns = $this->get_primary_key_columns($target_table);
        if (empty($pk_columns)) {
            error_log("[Import] 目标端正则处理失败：{$target_table} 没有主键，无法执行正则替换");
            $this->append_job_error("目标端正则处理：{$target_table} 没有主键，无法执行正则替换");
            return;
        }

        // 收集需要查询的字段（目标字段 + 主键）
        $select_fields = $pk_columns;
        $field_updates = array();
        foreach ($target_regex_mappings as $fm) {
            $target_field = isset($fm['target_field']) ? $fm['target_field'] : '';
            if ($target_field !== '') {
                $select_fields[] = $target_field;
                $field_updates[] = $fm;
            }
        }

        if (empty($field_updates)) {
            return;
        }

        // 去重字段列表
        $select_fields = array_unique($select_fields);
        $select_cols = array_map(function($col) {
            return '`' . $col . '`';
        }, $select_fields);
        $select_sql = "SELECT " . implode(', ', $select_cols) . " FROM `{$target_table}`";

        try {
            $rows = $this->target_db->get_results($select_sql, [], 'ARRAY_A');
            if (empty($rows)) {
                return;
            }

            $processed = 0;
            $total_rows = count($rows);

            foreach ($rows as $row) {
                $updates = array();
                foreach ($field_updates as $fm) {
                    $target_field = $fm['target_field'];
                    $default_value = $fm['source_default']; // e.g. |/pattern#replacement/

                    // 去掉 | 前缀得到 /pattern#replacement/ 格式
                    $regex_value = substr($default_value, 1);

                    // 解析正则格式
                    $parsed = $this->parse_regex_pattern($regex_value);
                    if ($parsed === false) {
                        continue;
                    }

                    list($pattern, $replacement, $flags) = $parsed;

                    $current_value = isset($row[$target_field]) ? $row[$target_field] : '';
                    if ($current_value === null || $current_value === '') {
                        continue;
                    }

                    // 应用正则替换
                    $regex = '#' . $pattern . '#' . $flags;
                    $replaced_value = @preg_replace($regex, $replacement, $current_value);

                    if (preg_last_error() === PREG_NO_ERROR && $replaced_value !== null && $replaced_value !== $current_value) {
                        $updates[$target_field] = $replaced_value;
                    }
                }

                if (!empty($updates)) {
                    // 使用 PDO 执行 UPDATE（安全可靠）
                    $set_clauses = array();
                    $params = array();
                    foreach ($updates as $field => $val) {
                        $set_clauses[] = "`{$field}` = ?";
                        $params[] = $val;
                    }

                    $where_clauses = array();
                    foreach ($pk_columns as $pk) {
                        $where_clauses[] = "`{$pk}` = ?";
                        $params[] = $row[$pk];
                    }

                    $update_sql = "UPDATE `{$target_table}` SET " . implode(', ', $set_clauses) .
                                  " WHERE " . implode(' AND ', $where_clauses);

                    $stmt = $this->target_pdo->prepare($update_sql);
                    if ($stmt) {
                        $stmt->execute($params);
                        $processed++;
                    }
                }
            }

            $status_text = sprintf('目标端正则处理完成：%s (处理 %d/%d 条记录)', $target_table, $processed, $total_rows);
            $this->imported_records += $processed;
            $this->update_progress($this->total_records, $this->imported_records, $this->failed_records, $status_text);

            if ($processed > 0) {
                error_log("[Import] 目标端正则处理完成：{$target_table}，成功处理 {$processed}/{$total_rows} 行");
            }
        } catch (Exception $e) {
            error_log("[Import] 目标端正则处理失败: " . $e->getMessage());
            $this->append_job_error("目标端正则处理失败：{$e->getMessage()}");
        }
    }

    /**
     * 解析正则表达式格式 /pattern#replacement/ 或 /pattern#replacement/flags
     * 使用 # 作为分隔符，避免 pattern/replacement 中包含 / 时的问题
     * @param string $value 正则表达式字符串
     * @return array|false [pattern, replacement, flags] 或 false（格式无效）
     */
    private function parse_regex_pattern($value)
    {
        $len = strlen($value);
        if ($len < 3 || $value[0] !== '/') {
            return false;
        }

        // 查找第一个未被转义的 # 分隔符
        $hash_pos = false;
        for ($i = 1; $i < $len; $i++) {
            if ($value[$i] === '#') {
                $bs_count = 0;
                for ($j = $i - 1; $j >= 0 && $value[$j] === '\\'; $j--) {
                    $bs_count++;
                }
                if ($bs_count % 2 === 0) {
                    $hash_pos = $i;
                    break;
                }
            }
        }

        if ($hash_pos === false || $hash_pos <= 1) {
            return false;
        }

        // 提取 pattern，还原转义的 # 和 \
        $pattern = substr($value, 1, $hash_pos - 1);
        $pattern = preg_replace('/\\\\([#\\\\])/', '$1', $pattern);

        // 提取 replacement 和 flags
        $after_hash = substr($value, $hash_pos + 1);
        $last_slash_pos = strrpos($after_hash, '/');

        if ($last_slash_pos !== false) {
            $replacement = substr($after_hash, 0, $last_slash_pos);
            $flags = substr($after_hash, $last_slash_pos + 1);
            // 校验 flags 是否为正则标志（仅含字母）
            if (!preg_match('/^[a-zA-Z]*$/', $flags)) {
                $flags = '';
                $replacement = $after_hash;
            }
        } elseif (!empty($after_hash)) {
            $replacement = $after_hash;
            $flags = '';
        } else {
            return false;
        }

        return array($pattern, $replacement, $flags);
    }

    /**
     * 检测目标表必填字段并生成默认值
     * - 查找 NOT NULL 且无默认值/非自增/非自动时间戳 的字段
     * - 常见字段名提供语义化默认值（如 title -> '未命名'）
     */
    private function detect_and_prepare_required_defaults($target_table, $mapped_target_field_names, $pk_columns = array())
    {
        $defaults = array();
        $db = $this->target_db; // PDO 实例
        if (!$db) {
            return $defaults;
        }

        // 查询表结构（使用 CMS_DB）
        $columns = $db->get_results("SHOW FULL COLUMNS FROM `{$target_table}`", [], 'ARRAY_A');
        if (empty($columns)) {
            return $defaults;
        }

        foreach ($columns as $col) {
            $field = $col['Field'];
            $null = strtoupper($col['Null']) === 'YES';
            $default = $col['Default'];
            $extra = strtolower((string) $col['Extra']);
            $type = strtolower((string) $col['Type']);

            // 已经映射的字段跳过（不区分大小写）
            if (in_array(strtolower($field), $mapped_target_field_names, true)) {
                continue;
            }
            // 主键列跳过（避免无意默认填充，不区分大小写）
            if (!empty($pk_columns) && in_array(strtolower($field), $pk_columns, true)) {
                continue;
            }
            // 可为 NULL 的跳过
            if ($null) {
                continue;
            }
            // 有默认值的跳过（包括 CURRENT_TIMESTAMP 等函数默认值）
            if ($default !== null) {
                continue;
            }
            // 自增跳过
            if (strpos($extra, 'auto_increment') !== false) {
                continue;
            }
            // on update CURRENT_TIMESTAMP 的更新时间字段跳过
            if (strpos($extra, 'on update') !== false) {
                continue;
            }

            // 推断类型并给出合适默认（确保非 NULL，避免 1364 错误）
            $val = '';
            if (preg_match('/int|decimal|float|double|bigint|smallint|tinyint/', $type)) {
                $val = 0;
            } elseif (preg_match('/datetime|timestamp/', $type)) {
                $val = date('Y-m-d H:i:s');
            } elseif (preg_match('/date/', $type)) {
                $val = date('Y-m-d');
            } elseif (preg_match('/time/', $type)) {
                $val = date('H:i:s');
            } elseif (preg_match('/year/', $type)) {
                $val = date('Y');
            } elseif (preg_match('/json/', $type)) {
                $val = '{}';
            } elseif (preg_match('/char|text|enum|set/', $type)) {
                $val = '';
            } else {
                $val = '';
            }

            // 语义化字段名默认
            if ($field === 'title' || $field === 'post_title' || $field === 'name') {
                $val = '未命名';
            }
            if ($field === 'status' || $field === 'post_status') {
                // 文本型给 'draft'，数值型给 0
                if (preg_match('/int|decimal|float|double|bigint|smallint|tinyint/', $type)) {
                    $val = 0;
                } else {
                    $val = 'draft';
                }
            }

            $defaults[$field] = $val;
        }

        return $defaults;
    }

    // 获取主键列名（按顺序）
    private function get_primary_key_columns($table_name)
    {
        $pk = array();
        try {
            $all_indexes = $this->target_db->get_results("SHOW INDEX FROM `{$table_name}`", [], 'ARRAY_A');
            if (empty($all_indexes) || !is_array($all_indexes)) {
                return $pk;
            }
            
            $indexes = array();
            foreach ($all_indexes as $idx) {
                $idx_lower = array_change_key_case($idx, CASE_LOWER);
                $key_name = $idx_lower['key_name'] ?? '';
                if (strcasecmp($key_name, 'PRIMARY') === 0) {
                    $indexes[] = $idx_lower;
                }
            }

            if (!empty($indexes)) {
                // 根据 Seq_in_index 排序，确保复合主键顺序正确
                usort($indexes, function ($a, $b) {
                    $sa = intval($a['Seq_in_index'] ?? 0);
                    $sb = intval($b['Seq_in_index'] ?? 0);
                    return $sa <=> $sb;
                });
                foreach ($indexes as $idx) {
                    // 规范化键名为小写
                    $idx = array_change_key_case($idx, CASE_LOWER);
                    $col = $idx['column_name'] ?? '';
                    if ($col !== '') {
                        $pk[] = strtolower($col);
                    }
                }
            }
        } catch (Exception $e) {
            // 忽略失败，返回空数组
        }
        return $pk;
    }

    /**
     * 获取目标表的唯一键（含主键和唯一索引）涉及的列集合
     * 返回形如 ['id' => true, 'slug' => true]
     */
    private function get_unique_key_columns($table_name)
    {
        $cols = array();
        try {
            // 使用 CMS_DB 的 get_results 以数组形式获取索引信息，避免对非 SELECT 语句返回行数导致的 fetch 错误
            $indexes = $this->target_db->get_results("SHOW INDEX FROM `{$table_name}`", [], 'ARRAY_A');
            if (empty($indexes) || !is_array($indexes)) {
                return $cols;
            }
            foreach ($indexes as $idx) {
                // 规范化键名为小写
                $idx = array_change_key_case($idx, CASE_LOWER);
                // non_unique 为 0 表示唯一索引（包含主键）
                if (isset($idx['non_unique']) && intval($idx['non_unique']) === 0) {
                    $col = isset($idx['column_name']) ? $idx['column_name'] : '';
                    if ($col !== '') {
                        $cols[strtolower($col)] = true;
                    }
                }
            }
        } catch (Exception $e) {
            // 忽略索引探测错误
        }
        return $cols;
    }

    /**
     * 执行批量插入
     */
    private function execute_batch_insert($base_query, $placeholder, $batch_data, $update_clause = '')
    {
        if (empty($batch_data)) {
            return;
        }

        // 自适应分片，避免一次发送过大导致 Broken pipe（32KB）或 2006 错误
        $max_sql_len = 30000; // 预留安全阈值
        $start = 0;
        $total = count($batch_data);

        while ($start < $total) {
            $placeholders = array();
            $all_values = array();
            $query_prefix = $base_query;
            $sql_len = strlen($query_prefix);

            $i = 0;
            while (($start + $i) < $total) {
                $ph = ($i === 0) ? $placeholder : ', ' . $placeholder;
                $candidate_len = $sql_len + strlen($ph) + strlen($update_clause);
                if ($candidate_len > $max_sql_len && $i > 0) {
                    break; // 达到阈值，停止扩展本次语句
                }
                // 即使首行超过阈值，也至少发送一行，避免死循环
                $placeholders[] = $placeholder;
                $all_values = array_merge($all_values, $batch_data[$start + $i]);
                $sql_len = $candidate_len;
                $i++;
            }

            $query = $query_prefix . implode(', ', $placeholders) . ($update_clause ? $update_clause : '');

            try {
                // 使用专用 PDO 连接执行插入
                if (!$this->target_pdo) {
                    throw new Exception('目标数据库连接丢失');
                }
                $stmt = $this->target_pdo->prepare($query);
                if (!is_object($stmt)) {
                    $err = is_string($stmt) ? $stmt : 'Prepare failed';
                    throw new Exception('数据库预处理失败: ' . $err);
                }
                $stmt->execute($all_values);
            } catch (Exception $e) {
                $msg = strtolower($e->getMessage());
                // 针对 Broken pipe / server has gone away，进行一次降批重试
                if (strpos($msg, 'broken pipe') !== false || strpos($msg, 'server has gone away') !== false) {
                    try {
                        // 尝试重连
                        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                        $this->target_pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                        ]);
                        $this->target_pdo->exec("SET SESSION net_write_timeout=600");
                        $this->target_pdo->exec("SET SESSION net_read_timeout=600");
                    } catch (Exception $ee) {
                    }
                    $half = max(1, intdiv($i, 2));
                    if ($half < $i) {
                        $subA = array_slice($batch_data, $start, $half);
                        $subB = array_slice($batch_data, $start + $half, $i - $half);
                        // 递归按更小分片执行
                        $this->execute_batch_insert($base_query, $placeholder, $subA, $update_clause);
                        $this->execute_batch_insert($base_query, $placeholder, $subB, $update_clause);
                        $start += $i;
                        continue; // 进入下一轮
                    }
                }
                throw new Exception('执行插入失败: ' . $e->getMessage());
            }

            $start += $i;
        }
    }

    /**
     * 字段值转换 - 支持根据目标数据库字段类型智能填充默认值
     */
    private function transform_field_value($value, $field_mapping, $target_table = '')
    {
        $target_field = isset($field_mapping['target_field']) ? $field_mapping['target_field'] : '';

        // 特殊字段处理：featured_image
        if ($target_field === 'featured_image') {
            if ($value === null || $value === '') {
                return null;
            }
            // 如果值看起来像正则表达式格式（包含 # 分隔符），说明正则处理可能失败了
            // 记录警告但不处理，避免将正则表达式字符串当作URL
            if (is_string($value) && preg_match('@^/.+#.+?/@', $value)) {
                error_log("[Import] 警告：featured_image 字段接收到疑似正则表达式字符串: {$value}，这可能是正则处理失败导致的，跳过转换");
                return null;
            }
            return $this->convert_featured_image($value);
        }

        // 处理空值：根据目标字段的数据库类型提供合适的默认值
        if ($value === null || $value === '') {
            // 如果是主键或唯一键字段，且源数据为空，不要返回 0，否则会触发 MySQL 的 AUTO_INCREMENT 导致追加新记录
            // 返回 null 让数据库决定（通常会报错或触发自增，但在 UPSERT 场景下我们希望它能匹配）
            $unique_keys = $this->get_unique_key_columns($target_table);
            if (isset($unique_keys[strtolower($target_field)])) {
                return null;
            }
            return $this->get_default_value_for_field($target_table, $target_field, $field_mapping);
        }

        // 根据目标字段类型进行转换
        $target_type = $field_mapping['target_type'] ?? null;

        // 若未显式指定 target_type，则根据数据库字段类型自动推断转换类型
        if ($target_type === null && $target_table !== '' && $target_field !== '') {
            $db_type = $this->get_column_type($target_table, $target_field);
            if (is_string($db_type) && $db_type !== '') {
                $t = strtolower($db_type);
                if (preg_match('/int|bigint|smallint|tinyint/', $t)) {
                    $target_type = 'int';
                } elseif (preg_match('/decimal|float|double/', $t)) {
                    $target_type = 'float';
                } elseif (preg_match('/datetime|timestamp/', $t)) {
                    $target_type = 'datetime';
                } elseif (preg_match('/date/', $t)) {
                    $target_type = 'date';
                } elseif (preg_match('/bool/', $t)) {
                    $target_type = 'boolean';
                } else {
                    $target_type = 'string';
                }
            }
        }
        if ($target_type === null) {
            $target_type = 'string';
        }

        switch ($target_type) {
            case 'int':
            case 'integer':
                return intval($value);

            case 'float':
            case 'decimal':
                return floatval($value);

            case 'datetime':
            case 'timestamp':
                return $this->convert_datetime($value);

            case 'date':
                return $this->convert_date($value);

            case 'boolean':
            case 'bool':
                return $this->convert_boolean($value);

            default:
                return strval($value);
        }
    }

    /**
     * 转换日期时间
     */
    private function convert_datetime($value)
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 转换日期
     */
    private function convert_date($value)
    {
        if (empty($value) || $value === '0000-00-00') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * 获取字段的默认值（当源数据为空时使用）
     * 规则：
     * - 目标非空数字类型 -> 0
     * - 目标非空字符类型 -> ''
     * - JSON/对象类型 -> '{}'（或 '[]' 见下）
     * - 文本/可空字段 -> NULL
     */
    private function get_default_value_for_field($target_table, $target_field, $field_mapping)
    {
        // 优先使用映射中显式指定的默认
        if (isset($field_mapping['default_on_empty'])) {
            return $field_mapping['default_on_empty'];
        }

        $type = $this->get_column_type($target_table, $target_field);
        if ($type === null) {
            // 回退：根据 target_type 估算
            $t = strtolower($field_mapping['target_type'] ?? 'string');
            if (in_array($t, ['int', 'integer', 'float', 'double', 'decimal'])) {
                return 0;
            }
            if (in_array($t, ['json', 'object', 'array'])) {
                return '{}';
            }
            return '';
        }
        $type = strtolower($type);

        // 数字类型
        if (preg_match('/int|decimal|float|double|bigint|smallint|tinyint/', $type)) {
            return 0;
        }
        // JSON 类型
        if (strpos($type, 'json') !== false) {
            return '{}';
        }
        // 文本/字符
        if (preg_match('/char|text|enum|set|varchar/', $type)) {
            return '';
        }
        // 日期时间：默认给当前时间避免 NOT NULL 报错
        if (preg_match('/datetime|timestamp/', $type)) {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/date/', $type)) {
            return date('Y-m-d');
        }
        if (preg_match('/time/', $type)) {
            return date('H:i:s');
        }
        if (preg_match('/year/', $type)) {
            return date('Y');
        }

        // 其他未知类型：按空串处理
        return '';
    }

    /**
     * 获取目标表某列的类型定义，并缓存
     */
    private function get_column_type($table, $column)
    {
        if (!$table || !$column) {
            return null;
        }
        $key = $table . '|' . $column;
        if (isset($this->table_schema_cache[$key])) {
            return $this->table_schema_cache[$key];
        }
        try {
            // 注意：MySQL 不支持对 SHOW 语句使用占位符参数，这会导致 1064 语法错误
            // 因此这里改为获取整张表的列信息然后在本地过滤匹配字段名，避免使用 LIKE ?
            $columns = $this->target_db->get_results("SHOW FULL COLUMNS FROM `{$table}`", [], 'ARRAY_A');
            if (!empty($columns)) {
                foreach ($columns as $col) {
                    if (isset($col['Field']) && strcasecmp($col['Field'], $column) === 0) {
                        $this->table_schema_cache[$key] = $col['Type'];
                        return $col['Type'];
                    }
                }
            }
        } catch (Exception $e) {
            // 忽略
        }
        $this->table_schema_cache[$key] = null;
        return null;
    }

    /**
     * 转换布尔值
     */
    private function convert_boolean($value)
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $value = strtolower(trim($value));
        return in_array($value, array('1', 'true', 'yes', 'on')) ? 1 : 0;
    }

    /**
     * 转换特色图片字段（featured_image）到JSON格式
     * - 输入可以是：纯URL字符串、JSON字符串、数组（对象或数组列表）
     * - 输出：JSON格式的字符串，存储在 posts.featured_image 字段
     * - 格式：[{"id":"","url":"/content/uploads/...jpg","title":"","index":0}]
     * - 按需求：id 与 title 保持留空，index 从 0 开始
     */
    private function convert_featured_image($value)
    {
        // JSON 字符串情况：直接解析
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
                $decoded = json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        $images = array();

        // 归一化为图片条目数组
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc && (isset($value['url']) || isset($value['src']))) {
                $value = array($value);
            }
            $idx = 0;
            foreach ($value as $item) {
                if (is_string($item)) {
                    $url = trim($item);
                } elseif (is_array($item)) {
                    $url = isset($item['url']) ? $item['url'] : (isset($item['src']) ? $item['src'] : '');
                    $url = trim((string) $url);
                } else {
                    continue;
                }
                if ($url === '') {
                    continue;
                }
                $images[] = array(
                    'id' => '',      // 按需求保持留空
                    'url' => $url,
                    'title' => '',   // 按需求保持留空
                    'index' => $idx
                );
                $idx++;
            }
        } elseif (is_string($value)) {
            $str = trim($value);
            if ($str === '') {
                return null;
            }
            // 支持逗号分隔的多URL（可选）
            $parts = strpos($str, ',') !== false ? array_filter(array_map('trim', explode(',', $str))) : array($str);
            $idx = 0;
            foreach ($parts as $url) {
                if ($url === '') {
                    continue;
                }
                $images[] = array('id' => '', 'url' => $url, 'title' => '', 'index' => $idx++);
            }
        } else {
            return null;
        }

        if (empty($images)) {
            return null;
        }

        // 返回JSON格式字符串（统一新格式）
        return json_encode($images, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 更新任务状态
     */
    private function update_job_status($status, $error_message = null)
    {
        global $tododb;

        $data = array('status' => $status);
        if ($error_message !== null) {
            // 如果提供了错误消息，更新它
            $data['error_message'] = $error_message;
        } elseif ($status === 'completed') {
            // 如果状态为完成且没有错误消息，清除之前的错误信息（设置为空字符串）
            $data['error_message'] = '';
        }
        // 如果 status 不是 completed 且 error_message 为 null，则不更新 error_message 字段

        $tododb->update(
            $tododb->prefix . 'plugin_data_import_jobs',
            $data,
            array('id' => $this->job_id)
        );
    }

    /**
     * 追加写入任务错误信息（供前端查看）
     * @param string $message 错误消息
     * @return void
     */
    private function append_job_error(string $message): void
    {
        if ($message === '') {
            return;
        }
        global $tododb;
        try {
            $table = $tododb->prefix . 'plugin_data_import_jobs';
            $current = $tododb->get_var("SELECT error_message FROM `{$table}` WHERE id = ?", [$this->job_id]);
            $ts = date('Y-m-d H:i:s');
            $combined = trim(($current ? $current . "\n" : '') . "[{$ts}] " . $message);
            // 防止过长
            if (strlen($combined) > 4000) {
                $combined = substr($combined, -4000);
            }
            $tododb->update($table, array('error_message' => $combined), array('id' => $this->job_id));
        } catch (Exception $e) {
            // 忽略错误日志写入失败
        }
    }

    /**
     * 更新进度
     * @param int $total 总记录数
     * @param int $imported 已导入记录数
     * @param int $failed 失败记录数
     * @param string $status_text 可选的状态文本
     */
    private function update_progress($total, $imported, $failed, $status_text = null)
    {
        global $tododb;

        $data = array(
            'total_records' => $total,
            'processed_records' => $imported
        );

        // 如果提供了状态文本，同时更新状态文本
        if ($status_text !== null) {
            $data['error_message'] = $status_text; // 使用 error_message 字段存储状态文本
        }

        $tododb->update(
            $tododb->prefix . 'plugin_data_import_jobs',
            $data,
            array('id' => $this->job_id)
        );
    }

    /**
     * 将SQL内容规范化到临时表，移除库名限定并添加临时表前缀
     * 优化：对于大型INSERT语句，只处理表名部分，避免内存耗尽
     */
    private function sanitize_sql_for_temp_db($sql, $temp_table_prefix)
    {
        if (!is_string($sql) || $sql === '') {
            return $sql;
        }

        $sql_len = strlen($sql);
        $is_large_sql = $sql_len > 1024 * 1024; // 大于1MB认为是大型SQL

        // 对于大型INSERT语句，只处理表名部分（在语句开头），避免处理整个语句导致内存耗尽
        if ($is_large_sql && (stripos($sql, 'INSERT') === 0 || stripos($sql, 'REPLACE') === 0)) {
            // 只处理前500个字符来修改表名
            $sql_header = substr($sql, 0, 500);
            $sql_rest = substr($sql, 500);

            // 处理表名部分
            $patterns = array(
                // 匹配带库名前缀的情况：INSERT INTO `db`.`table`
                '/\b(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\.`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
                // 匹配不带库名前缀的情况：INSERT INTO `table`
                '/(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`([A-Za-z_][A-Za-z0-9_]*)`/i'
            );

            foreach ($patterns as $p) {
                if (preg_match($p, $sql_header, $matches)) {
                    if (isset($matches[3])) {
                        // 带库名前缀的情况
                        $table_name = $matches[3];
                        $replacement = $matches[1] . ' `' . $temp_table_prefix . $table_name . '`';
                        $sql_header = preg_replace($p, $replacement, $sql_header, 1);
                    } elseif (isset($matches[2])) {
                        // 不带库名前缀的情况
                        $table_name = $matches[2];
                        if (strpos($table_name, $temp_table_prefix) !== 0) {
                            $sql_header = str_replace('`' . $table_name . '`', '`' . $temp_table_prefix . $table_name . '`', $sql_header);
                        }
                    }
                    break; // 只处理第一个匹配
                }
            }

            return $sql_header . $sql_rest;
        }

        // 对于小型SQL语句，使用原来的完整处理逻辑
        // 去掉常见语句中的库名前缀，如 dbname.table
        $patterns = array(
            '/\b(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?\.`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
            '/\b(UPDATE|DELETE\s+FROM|LOCK\s+TABLES)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\.`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
            '/\b(FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\.`?([A-Za-z_][A-Za-z0-9_]*)`?/i'
        );
        foreach ($patterns as $p) {
            $sql = preg_replace($p, '$1 `' . $temp_table_prefix . '$3`', $sql);
        }

        // 处理没有库名前缀的表名（直接添加临时前缀）
        // 匹配 CREATE TABLE `table_name` 或 INSERT INTO `table_name` 等
        $table_patterns = array(
            '/(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([A-Za-z_][A-Za-z0-9_]*)`/i',
            '/(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)\s+(?:IF\s+EXISTS\s+)?`([A-Za-z_][A-Za-z0-9_]*)`/i',
            '/(FROM|JOIN)\s+`([A-Za-z_][A-Za-z0-9_]*)`/i'
        );
        foreach ($table_patterns as $p) {
            // 只处理不包含临时前缀的表名
            $sql = preg_replace_callback($p, function ($matches) use ($temp_table_prefix) {
                $table_name = $matches[count($matches) - 1];
                // 如果表名已经包含临时前缀，跳过
                if (strpos($table_name, $temp_table_prefix) === 0) {
                    return $matches[0];
                }
                // 添加临时前缀
                return str_replace('`' . $table_name . '`', '`' . $temp_table_prefix . $table_name . '`', $matches[0]);
            }, $sql);
        }

        // 防御性：在合理大小下压缩多余空白；大文件跳过以避免额外内存复制
        $maxCompressBytes = 8 * 1024 * 1024; // 8MB
        if ($sql_len <= $maxCompressBytes) {
            $sql = preg_replace("/\r?\n+\s*/", "\n", $sql);
        }
        return $sql;
    }

    /**
     * 基于上传SQL中的 INSERT 列清单，为临时表综合创建所需表（用于数据-only SQL）
     * 列类型统一使用 LONGTEXT 以最大兼容性，后续映射阶段再做类型适配
     * @param string $filepath SQL文件路径
     * @param string $temp_table_prefix 临时表前缀
     * @param array $allowed_tables 允许的表名白名单（键为表名）
     */
    private function synthesize_tables_from_sql_file($filepath, $temp_table_prefix, $allowed_tables = array())
    {
        if (!is_string($filepath) || $filepath === '' || !file_exists($filepath)) {
            return '';
        }
        // 复用解析器提取库/表/字段信息
        require_once dirname(__FILE__) . '/sql-parser.php';
        try {
            $parser = new SQLStructureParser();
            
            // 定义进度回调
            $that = $this;
            $progress_callback = function($bytes_processed, $total_bytes) use ($that) {
                // 更新 imported_records (虽然这里是解析进度，但为了让进度条动起来)
                // 我们可以假设解析阶段占总进度的 5% - 10%，或者直接显示解析进度
                // 这里我们简单更新进度条文本
                $processed_mb = round($bytes_processed / 1024 / 1024, 2);
                $total_mb = round($total_bytes / 1024 / 1024, 2);
                $status_text = sprintf('正在解析表结构... (%sMB/%sMB)', $processed_mb, $total_mb);
                
                // 注意：这里我们不改变 total_records，它应该已经是文件大小
                // 我们更新 imported_records 为 bytes_processed
                $that->imported_records = $bytes_processed;
                $that->update_progress($total_bytes, $bytes_processed, 0, $status_text);
            };
            
            $structure = $parser->parse($filepath, $progress_callback);
        } catch (Exception $e) {
            error_log("[Import Debug] SQL parser failed: " . $e->getMessage());
            return '';
        }
        if (empty($structure) || !is_array($structure)) {
            error_log("[Import Debug] SQL structure is empty or invalid.");
            return '';
        }
        $stmts = array();
        foreach ($structure as $db) {
            if (!isset($db['tables']) || !is_array($db['tables']))
                continue;
            foreach ($db['tables'] as $table) {
                $tname = isset($table['name']) ? (string) $table['name'] : '';
                if ($tname === '')
                    continue;

                // 如果白名单为空或者表不在白名单中，跳过
                if (empty($allowed_tables) || !isset($allowed_tables[$tname])) {
                    // error_log("[Import Debug] Skipping table '{$tname}' (not in allowed list)");
                    continue;
                }

                // 添加临时表前缀
                $temp_tname = $temp_table_prefix . $tname;
                $cols = array();
                if (isset($table['fields']) && is_array($table['fields'])) {
                    foreach ($table['fields'] as $field) {
                        $fname = isset($field['name']) ? (string) $field['name'] : '';
                        if ($fname === '')
                            continue;
                        $cols[] = "`{$fname}` LONGTEXT NULL";
                    }
                }
                if (empty($cols)) {
                    error_log("[Import Debug] Table '{$tname}' has no fields, skipping.");
                    continue;
                }
                $stmts[] = "CREATE TABLE IF NOT EXISTS `{$temp_tname}` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                error_log("[Import Debug] Will create synthesized table: {$temp_tname} with " . count($cols) . " columns");
            }
        }
        return empty($stmts) ? '' : (implode(";\n", $stmts) . ";\n");
    }

    /**
     * 清理资源
     */
    private function cleanup()
    {
        // 关闭数据库连接
        if ($this->source_db && $this->source_db instanceof mysqli) {
            $this->source_db->close();
        }
        // 关闭目标PDO连接
        $this->target_pdo = null;

        // 删除临时表（虚拟主机环境，使用临时表而不是临时数据库）
        if (isset($this->config['temp_table_prefix'])) {
            $temp_table_prefix = $this->config['temp_table_prefix'];
            $cleanup_conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if (!$cleanup_conn->connect_error) {
                // 查找所有以临时前缀开头的表
                $result = $cleanup_conn->query("SHOW TABLES LIKE '{$temp_table_prefix}%'");
                if ($result) {
                    while ($row = $result->fetch_array()) {
                        $table_name = $row[0];
                        $cleanup_conn->query("DROP TABLE IF EXISTS `{$table_name}`");
                    }
                    $result->free();
                }
                $cleanup_conn->close();
            }
        }
    }
}
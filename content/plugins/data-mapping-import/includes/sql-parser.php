<?php
/**
 * SQL结构解析器
 */

// 防止直接访问
if (!defined('TODO_VERSION')) {
    exit('Direct access denied');
}

class SQLStructureParser {
    
    private $structure = array();
    private $current_database = null;
    
    /**
     * 解析SQL文件
     * @param string $filepath 文件路径
     * @param callable|null $progress_callback 进度回调 function($bytes_processed, $total_bytes)
     */
    public function parse($filepath, $progress_callback = null) {
        if (!file_exists($filepath)) {
            throw new Exception('SQL文件不存在');
        }
        
        // 对超大文件优先使用流式回退解析，避免正则在大文本上的回溯开销
        $fsize = @filesize($filepath);
        if ($fsize !== false && $fsize > 20 * 1024 * 1024) { // >20MB 先用流式解析
            $this->fallback_streaming_parse_create_tables($filepath, $progress_callback);
            if (!$this->is_structure_empty()) {
                return $this->structure;
            }
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new Exception('无法读取SQL文件');
        }
        
        // 如果读取了整个文件，立即调用一次进度（100%）
        if (is_callable($progress_callback) && $fsize !== false) {
            call_user_func($progress_callback, $fsize, $fsize);
        }
        
        // 清理注释和空行
        $content = $this->clean_sql_content($content);
        
        // 分割SQL语句
        $statements = $this->split_sql_statements($content);
        
        // 解析每个语句
        foreach ($statements as $statement) {
            $this->parse_statement($statement);
        }
        
        // 如果未解析出任何表，尝试回退解析（直接全局扫描 CREATE TABLE）
        if ($this->is_structure_empty()) {
            $this->fallback_parse_create_tables($content);
        }
        
        // 如果仍为空，最后再尝试一次流式解析（适配未命中场景）
        if ($this->is_structure_empty()) {
            $this->fallback_streaming_parse_create_tables($filepath);
        }
        
        return $this->structure;
    }
    
    /**
     * 清理SQL内容
     */
    private function clean_sql_content($content) {
        // 移除单行注释（以 -- 开头，忽略字符串中的 --）
        $content = preg_replace('/^\s*--.*$/m', '', $content);
        
        // 移除MySQL版本注释 /*! ... */ 与常规多行注释
        $content = preg_replace('/\/\*!.*?\*\//s', '', $content);
        $content = preg_replace('/\/\*[^!].*?\*\//s', '', $content);
        
        // 保留原有换行与空白（避免影响复杂语句解析），仅做首尾trim
        return trim($content);
    }
    
    /**
     * 分割SQL语句
     */
    private function split_sql_statements($content) {
        // 简单的分号分割，忽略字符串内的分号
        $statements = array();
        $current_statement = '';
        $in_string = false;
        $string_delimiter = null;
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            $prev_char = $i > 0 ? $content[$i - 1] : null;
            
            if (!$in_string && ($char === '"' || $char === "'")) {
                $in_string = true;
                $string_delimiter = $char;
            } elseif ($in_string && $char === $string_delimiter && $prev_char !== '\\') {
                $in_string = false;
                $string_delimiter = null;
            } elseif (!$in_string && $char === ';') {
                $statement = trim($current_statement);
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
                $current_statement = '';
                continue;
            }
            
            $current_statement .= $char;
        }
        
        // 添加最后一个语句
        $statement = trim($current_statement);
        if (!empty($statement)) {
            $statements[] = $statement;
        }
        
        return $statements;
    }
    
    /**
     * 解析单个SQL语句
     */
    private function parse_statement($statement) {
        $statement = trim($statement);
        if (empty($statement)) {
            return;
        }
        
        // 转换为大写以便匹配
        $upper_statement = strtoupper($statement);
        
        if (strpos($upper_statement, 'CREATE DATABASE') === 0) {
            $this->parse_create_database($statement);
        } elseif (strpos($upper_statement, 'USE ') === 0) {
            $this->parse_use_database($statement);
        } elseif (strpos($upper_statement, 'CREATE TABLE') === 0) {
            $this->parse_create_table($statement);
        } elseif (strpos($upper_statement, 'INSERT ') === 0) {
            // 兼容 INSERT [IGNORE] INTO 语句，用于从仅包含数据的SQL中推断表结构
            $this->parse_insert_statement($statement);
        }
    }
    
    /**
     * 解析CREATE DATABASE语句
     */
    private function parse_create_database($statement) {
        if (preg_match('/CREATE\s+DATABASE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s]+)`?/i', $statement, $matches)) {
            $database_name = $matches[1];
            $this->structure[$database_name] = array(
                'name' => $database_name,
                'tables' => array()
            );
            $this->current_database = $database_name;
        }
    }
    
    /**
     * 解析USE语句
     */
    private function parse_use_database($statement) {
        if (preg_match('/USE\s+`?([^`\s]+)`?/i', $statement, $matches)) {
            $database_name = $matches[1];
            if (!isset($this->structure[$database_name])) {
                $this->structure[$database_name] = array(
                    'name' => $database_name,
                    'tables' => array()
                );
            }
            $this->current_database = $database_name;
        }
    }
    
    /**
     * 解析CREATE TABLE语句
     */
    private function parse_create_table($statement) {
        // 如果没有当前数据库，使用默认数据库名
        if (!$this->current_database) {
            $this->current_database = 'default_database';
            $this->structure[$this->current_database] = array(
                'name' => $this->current_database,
                'tables' => array()
            );
        }
        
        // 提取表名（支持可选的数据库限定名，例如 `db`.`table`）
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[^`\s]+`?\.)?`?([^`\s(]+)`?\s*\(/i', $statement, $matches)) {
            $table_name = $matches[1];
            
            // 提取字段定义部分：兼容更多表选项（ENGINE/DEFAULT/COMMENT/ROW_FORMAT/CHARSET/COLLATE/AUTO_INCREMENT等）
            if (preg_match('/\((.+)\)\s*(?:ENGINE|DEFAULT|COMMENT|ROW_FORMAT|CHARSET|COLLATE|AUTO_INCREMENT|CHECKSUM|DELAY_KEY_WRITE|PACK_KEYS|STATS_PERSISTENT|TABLESPACE|UNION|PARTITION|WITH|WITHOUT|ENCRYPTION|COMPRESSION|STORAGE|DATA|INDEX|CONNECTION|;|$)/is', $statement, $content_matches)) {
                $fields_content = $content_matches[1];
                $fields = $this->parse_table_fields($fields_content);
                
                $this->structure[$this->current_database]['tables'][$table_name] = array(
                    'name' => $table_name,
                    'fields' => $fields
                );
            }
        }
    }
    
    /**
     * 解析表字段
     */
    private function parse_table_fields($fields_content) {
        $fields = array();
        
        // 分割字段定义，考虑括号内的逗号
        $field_definitions = $this->split_field_definitions($fields_content);
        
        foreach ($field_definitions as $field_def) {
            $field_def = trim($field_def);
            
            // 跳过约束定义
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN\s+KEY)/i', $field_def)) {
                continue;
            }
            
            $field = $this->parse_field_definition($field_def);
            if ($field) {
                $fields[] = $field;
            }
        }
        
        return $fields;
    }
    
    /**
     * 分割字段定义
     */
    private function split_field_definitions($content) {
        $definitions = array();
        $current_def = '';
        $paren_level = 0;
        $in_string = false;
        $string_delimiter = null;
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            $prev_char = $i > 0 ? $content[$i - 1] : null;
            
            if (!$in_string && ($char === '"' || $char === "'")) {
                $in_string = true;
                $string_delimiter = $char;
            } elseif ($in_string && $char === $string_delimiter && $prev_char !== '\\') {
                $in_string = false;
                $string_delimiter = null;
            } elseif (!$in_string) {
                if ($char === '(') {
                    $paren_level++;
                } elseif ($char === ')') {
                    $paren_level--;
                } elseif ($char === ',' && $paren_level === 0) {
                    $def = trim($current_def);
                    if (!empty($def)) {
                        $definitions[] = $def;
                    }
                    $current_def = '';
                    continue;
                }
            }
            
            $current_def .= $char;
        }
        
        // 添加最后一个定义
        $def = trim($current_def);
        if (!empty($def)) {
            $definitions[] = $def;
        }
        
        return $definitions;
    }
    
    /**
     * 解析单个字段定义
     */
    private function parse_field_definition($field_def) {
        // 提取字段名
        if (preg_match('/^`?([^`\s]+)`?\s+(.+)$/i', $field_def, $matches)) {
            $field_name = $matches[1];
            $field_spec = $matches[2];
            
            // 解析字段类型
            $type_info = $this->parse_field_type($field_spec);
            
            return array(
                'name' => $field_name,
                'type' => $type_info['type'],
                'length' => $type_info['length'],
                'nullable' => !preg_match('/NOT\s+NULL/i', $field_spec),
                'default' => $this->extract_default_value($field_spec),
                'auto_increment' => preg_match('/AUTO_INCREMENT/i', $field_spec) ? true : false,
                'primary_key' => preg_match('/PRIMARY\s+KEY/i', $field_spec) ? true : false,
                'comment' => $this->extract_comment($field_spec)
            );
        }
        
        return null;
    }
    
    /**
     * 解析字段类型
     */
    private function parse_field_type($field_spec) {
        $type = 'unknown';
        $length = null;
        
        // 匹配类型和长度
        if (preg_match('/^(\w+)(?:\((.*?)\))?/i', $field_spec, $matches)) {
            $type = strtolower($matches[1]);
            if (isset($matches[2]) && !empty($matches[2])) {
                $length = $matches[2];
            }
        }
        
        return array(
            'type' => $type,
            'length' => $length
        );
    }
    
    /**
     * 提取默认值
     */
    private function extract_default_value($field_spec) {
        if (preg_match('/DEFAULT\s+([^\s]+)/i', $field_spec, $matches)) {
            $default = $matches[1];
            // 移除引号（使用 .* 替代 .+ 以支持空字符串默认值如 DEFAULT ''）
            if (preg_match('/^["\'](.*)["\']$/', $default, $quote_matches)) {
                return $quote_matches[1];
            }
            return $default;
        }
        return null;
    }
    
    /**
     * 提取注释
     */
    private function extract_comment($field_spec) {
        if (preg_match('/COMMENT\s+["\']([^"\']*)["\']/i', $field_spec, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 快速扫描文件统计各表的 INSERT 记录数
     * @param string $filepath
     * @return array ['table_name' => row_count]
     */
    public function scan_insert_counts($filepath) {
        $handle = @fopen($filepath, 'r');
        if (!$handle) return array();

        $counts = array();
        
        while (($line = fgets($handle)) !== false) {
            $trim_line = trim($line);
            if ($trim_line === '' || strpos($trim_line, '--') === 0 || strpos($trim_line, '/*') === 0) continue;

            // 简单匹配 INSERT INTO table
            if (stripos($trim_line, 'INSERT') !== false) {
                 if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:`?([^`\.\s]+)`?\.)?`?([^`\s\(]+)`?/i', $trim_line, $m)) {
                    $table = $m[2]; // 表名
                    
                    // 估算行数
                    $rows = 1;
                    // 统计 VALUES 后的 ),( 数量
                    // 使用正则匹配更准确，支持可选空格
                    $rows += preg_match_all('/\)\s*,\s*\(/', $trim_line);
                    
                    if (!isset($counts[$table])) {
                        $counts[$table] = 0;
                    }
                    $counts[$table] += $rows;
                 }
            }
        }
        fclose($handle);
        return $counts;
    }

    /**
     * 解析INSERT语句（仅用于推断表及字段）
     */
    private function parse_insert_statement($statement) {
        // 匹配 INSERT [IGNORE] INTO [db.]table [(col1, col2, ...)] ...
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(?:`?([^`\.\s]+)`?\.)?`?([^`\s\(]+)`?\s*(?:\(([^\)]*)\))?/i', $statement, $m)) {
            $db = isset($m[1]) && $m[1] !== '' ? $m[1] : null;
            $table = $m[2];

            // 确定数据库
            if ($db) {
                $this->current_database = $db;
            }
            if (!$this->current_database) {
                $this->current_database = 'default_database';
            }
            if (!isset($this->structure[$this->current_database])) {
                $this->structure[$this->current_database] = array(
                    'name' => $this->current_database,
                    'tables' => array()
                );
            }

            // 解析列
            $columns = array();
            if (isset($m[3]) && trim($m[3]) !== '') {
                $columns = $this->parse_insert_columns($m[3]);
            }

            // 若无列名，无法从VALUES推断，登记空表占位并返回
            if (empty($columns)) {
                if (!isset($this->structure[$this->current_database]['tables'][$table])) {
                    $this->structure[$this->current_database]['tables'][$table] = array(
                        'name' => $table,
                        'fields' => array()
                    );
                }
                return;
            }

            // 合并或创建表定义
            if (!isset($this->structure[$this->current_database]['tables'][$table])) {
                $this->structure[$this->current_database]['tables'][$table] = array(
                    'name' => $table,
                    'fields' => $columns
                );
            } else {
                // 合并缺失字段
                $existing = &$this->structure[$this->current_database]['tables'][$table]['fields'];
                $existNames = array();
                foreach ($existing as $f) {
                    $existNames[$f['name']] = true;
                }
                foreach ($columns as $f) {
                    if (!isset($existNames[$f['name']])) {
                        $existing[] = $f;
                    }
                }
            }
        }
    }

    /**
     * 解析INSERT列名列表
     */
    private function parse_insert_columns($columns_str) {
        $cols = array();
        // 简单按逗号拆分（列名段不包含括号）
        $parts = preg_split('/\s*,\s*/', $columns_str);
        foreach ($parts as $col) {
            $col = trim($col);
            if ($col === '') continue;
            // 去除包裹符
            if ($col[0] === '`' && substr($col, -1) === '`') {
                $col = substr($col, 1, -1);
            } elseif (($col[0] === '"' && substr($col, -1) === '"') || ($col[0] === "'" && substr($col, -1) === "'")) {
                $col = substr($col, 1, -1);
            }
            $cols[] = array(
                'name' => $col,
                'type' => 'unknown',
                'length' => null,
                'nullable' => true,
                'default' => null,
                'auto_increment' => false,
                'primary_key' => false,
                'comment' => null
            );
        }
        return $cols;
    }

    /**
     * 判断结构是否为空（无任何数据库或表）
     */
    private function is_structure_empty() {
        if (empty($this->structure)) return true;
        foreach ($this->structure as $db) {
            if (!empty($db['tables'])) return false;
        }
        return true;
    }

    /**
     * 回退解析：全局扫描 CREATE TABLE 片段，避免被复杂注释或分隔影响
     */
    private function fallback_parse_create_tables($content) {
        // 扫描所有 CREATE TABLE 片段（宽松匹配，直到分号结束）
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\(]+)\((.+)\)\s*(?:ENGINE|DEFAULT|COMMENT|ROW_FORMAT|CHARSET|COLLATE|AUTO_INCREMENT|CHECKSUM|DELAY_KEY_WRITE|PACK_KEYS|STATS_PERSISTENT|TABLESPACE|UNION|PARTITION|WITH|WITHOUT|ENCRYPTION|COMPRESSION|STORAGE|DATA|INDEX|CONNECTION|;|$)/is', $content, $matches, PREG_SET_ORDER)) {
            if (!$this->current_database) {
                $this->current_database = 'default_database';
                $this->structure[$this->current_database] = array(
                    'name' => $this->current_database,
                    'tables' => array()
                );
            }
            foreach ($matches as $m) {
                $table_full = trim($m[1]);
                // 取表名（可能包含 db.`table` 或 `table`）
                if (preg_match('/(?:`?[^`\s]+`?\.)?`?([^`\s]+)`?/i', $table_full, $tm)) {
                    $tname = $tm[1];
                } else {
                    $tname = preg_replace('/^[^\w]*|[^\w]*$/', '', $table_full);
                }
                $fields_content = $m[2];
                $fields = $this->parse_table_fields($fields_content);
                $this->structure[$this->current_database]['tables'][$tname] = array(
                    'name' => $tname,
                    'fields' => $fields
                );
            }
        }
    }

    /**
     * 回退解析（流式）：逐块扫描 CREATE TABLE，适合大文件，降低内存与回溯风险
     */
    private function fallback_streaming_parse_create_tables($filepath, $progress_callback = null) {
        $handle = @fopen($filepath, 'r');
        if (!$handle) return;
        
        $fsize = @filesize($filepath);
        $total_bytes = ($fsize !== false) ? $fsize : 0;
        $bytes_processed = 0;
        $last_callback_bytes = 0;
        $callback_interval = 1024 * 1024; // 每1MB回调一次
        
        if (!$this->current_database) {
            $this->current_database = 'default_database';
            $this->structure[$this->current_database] = array(
                'name' => $this->current_database,
                'tables' => array()
            );
        }
        $buffer = '';
        $inCreate = false;
        $parenDepth = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            $buffer .= $chunk;
            $chunk_len = strlen($chunk);
            $bytes_processed += $chunk_len;
            
            // 进度回调
            if (is_callable($progress_callback) && ($bytes_processed - $last_callback_bytes > $callback_interval)) {
                call_user_func($progress_callback, $bytes_processed, $total_bytes);
                $last_callback_bytes = $bytes_processed;
            }
            
            // 逐步消费 buffer
            while (true) {
                if (!$inCreate) {
                    // 查找 CREATE TABLE 起点
                    if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                        // 保留末尾可能不完整的边界
                        $buffer = substr($buffer, max(0, strlen($buffer) - 50));
                        break;
                    }
                    $pos = $m[0][1];
                    // 丢弃之前内容
                    $buffer = substr($buffer, $pos);
                    $inCreate = true;
                    $parenDepth = 0;
                }
                // 寻找第一个 '(' 开始字段区
                if ($parenDepth === 0) {
                    $p = strpos($buffer, '(');
                    if ($p === false) {
                        // 需要更多数据
                        if (strlen($buffer) > 1024*1024) {
                            // 防御性截断，避免无界增长
                            $buffer = substr($buffer, -2048);
                        }
                        break;
                    }
                    $header = substr($buffer, 0, $p); // 包含表名
                    // 提取表名
                    $tname = null;
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[^`\s]+`?\.)?`?([^`\s\(]+)`?/i', $header, $tm)) {
                        $tname = $tm[1];
                    } else {
                        // 无法识别表名，则重置状态
                        $inCreate = false;
                        // 丢弃已消费部分
                        $buffer = substr($buffer, $p + 1);
                        continue;
                    }
                    // 进入括号区域
                    $buffer = substr($buffer, $p + 1);
                    $parenDepth = 1;
                    $fieldsSql = '';
                    // 将表名暂存
                    $currentTable = $tname;
                }
                // 聚合直到括号配平并遇到分号或行尾
                $i = 0; $len = strlen($buffer);
                for (; $i < $len; $i++) {
                    $ch = $buffer[$i];
                    $fieldsSql .= $ch;
                    if ($ch === '(') $parenDepth++;
                    elseif ($ch === ')') {
                        $parenDepth--;
                        if ($parenDepth === 0) {
                            // 括号闭合，后面可有表选项直到分号
                            $after = substr($buffer, $i + 1);
                            $semiPos = strpos($after, ';');
                            if ($semiPos !== false) {
                                // 消费到分号
                                $buffer = substr($after, $semiPos + 1);
                            } else {
                                // 没有分号也接受一条定义
                                $buffer = $after;
                            }
                            // 去掉尾部表选项影响，仅保留括号内字段语句
                            $fieldsContent = preg_replace('/\)\s*$/', '', $fieldsSql);
                            // 解析字段
                            $fields = $this->parse_table_fields($fieldsContent);
                            $this->structure[$this->current_database]['tables'][$currentTable] = array(
                                'name' => $currentTable,
                                'fields' => $fields
                            );
                            // 重置状态，准备下一次
                            $inCreate = false;
                            $parenDepth = 0;
                            $fieldsSql = '';
                            break; // 跳出 for，继续 while(true) 消费剩余
                        }
                    }
                }
                if ($i >= $len) {
                    // 消费完当前 buffer，需要更多数据
                    $buffer = '';
                    break;
                }
            }
        }
        if (is_resource($handle)) fclose($handle);
    }

}

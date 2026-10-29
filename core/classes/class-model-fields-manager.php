<?php
/**
 * 模型字段管理器 - 三层架构版本
 * 管理9大数据模型的三层字段存储和操作
 * 
 * 三层架构设计：
 * 第一层：通用基础字段（posts表）- 所有模型共用的基础字段
 * 第二层：模型固定字段（模型专用表）- 每个模型特有的业务字段
 * 第三层：用户自定义字段（post_custom_fields表）- 用户后期添加的扩展字段
 * 
 * @package TodoCMS
 * @version 3.0.0 - 三层架构版本
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

// 引入datetime助手函数
require_once ABSPATH . 'core/includes/datetime-helper.php';

class Todo_Model_Fields_Manager
{

    /**
     * 数据库连接实例
     * @var CMS_DB
     */
    private $db;

    /**
     * 支持的数据模型
     * @var array
     */
    private $supported_models = [
        'article',
        'product',
        'images',
        'download',
        'media',
        'single',
        'special',
        'guestbook',
        'recruit'
    ];

    /**
     * 第一层：通用基础字段（存储在posts表中）
     * 所有模型都共用的基础字段
     * @var array
     */
    private $base_fields = [
        'ID',               // 文章唯一标识ID
        'post_title',       // 标题
        'post_content',     // 内容
        'post_excerpt',     // 摘要
        'post_status',      // 状态
        'post_author',      // 作者ID
        'category_id',      // 分类ID
        'featured_image',   // 特色图片
        'view_count',       // 浏览次数
        'sort_order',       // 排序

        'model_id',         // 模型ID
        'post_type',        // 内容类型

        'seo_title',        // SEO标题
        'seo_keywords',     // SEO关键词
        'seo_description',  // SEO描述

        'like_count',       // 点赞
        'comment_status',   // 评论状态
        'comment_count',    // 评论数
        'custom_url',       // 自定义URL
        'post_name',        // 文章别名
        'read_permission',  // 阅读权限
        'view_template',    // 视图模板

        'created_at',       // 文章创建时间
        'updated_at',       // 文章更新时间
        'post_date',        // 文章发布时间（本地时间）
        'post_date_gmt',    // 文章发布时间（GMT时间）
        'post_modified',    // 文章最后修改时间（本地时间）
        'post_modified_gmt' // 文章最后修改时间（GMT时间）
    ];

    /**
     * 第二层：模型固定字段配置（存储在各模型专用表中）
     * 每个模型特有的业务字段
     * @var array
     */
    private $model_fixed_fields = [
        'article' => [
            'field_id',         // 字段数据唯一标识ID
            'post_id',          // 关联的文章ID
            'article_source',   // 文章来源
            'is_original'      // 是否原创
        ],
        'product' => [
            'marketing_feature',    // 营销特写
            'product_video',        // 产品视频
            'product_type',         // 产品类型（实物/虚拟）
            'product_services',     // 产品服务（JSON）
            'spec_type',           // 规格类型
            'price',               // 现售价
            'unit_qty',            // 单位数量（用于重量/容量等单位）
            'unit',                // 单位
            'original_price',      // 原价
            'discount_type',       // 折扣类型
            'discount_config',     // 折扣配置（JSON）
            'product_code',        // 商品编码
            'stock',               // 库存
            'show_stock',          // 显示库存
            'virtual_sales',       // 虚拟销量
            'actual_sales',        // 实际销量
            'spec_config',         // 规格配置（JSON）
            'spec_details',        // 规格详情（JSON）
            'sale_status',         // 发售状态
            'delivery_type',       // 交付类型
            'delivery_express_note',     // 快递发货说明
            'delivery_download_url',     // 下载地址
            'delivery_download_code',    // 提取码
            'delivery_download_password', // 解压密码
            'delivery_download_note',    // 下载说明
            'delivery_content_note',     // 内容说明
            'memo_before_pay',     // 支付前说明
            'memo_after_pay',      // 支付后说明
            'memo_after_confirm',  // 确认收货后说明
            'custom_params'        // 产品自定义参数（JSON）
        ],
        'images' => [
        ],
        'download' => [
            'download_config',      // 下载配置（JSON）
            'download_note'        // 下载说明
        ],
        'media' => [
        ],
        'single' => [
        ],
        'special' => [
        ],
        'guestbook' => [
        ],
        'recruit' => [
        ]
    ];

    /**
     * 构造函数
     * @param CMS_DB $db 数据库连接实例
     */
    public function __construct($db = null)
    {
        if ($db && is_object($db)) {
            $this->db = $db;
        } else {
            global $tododb;

            // 确保数据库连接存在
            if (!$tododb) {
                if (class_exists('CMS_DB')) {
                    $tododb = new CMS_DB();
                } else {
                    throw new Exception('数据库连接类不存在');
                }
            }

            $this->db = $tododb;
        }

        if (!$this->db || !is_object($this->db)) {
            throw new Exception('无法建立数据库连接');
        }
    }

    /**
     * 内部助手：获取PDO连接（兼容CMS_DB与Extended_PDO/PDO）
     */
    private function get_pdo()
    {
        if ($this->db instanceof CMS_DB) {
            return $this->db->get_connection();
        }
        if ($this->db instanceof PDO) {
            return $this->db;
        }
        if (isset($GLOBALS['tododb'])) {
            if ($GLOBALS['tododb'] instanceof CMS_DB) {
                return $GLOBALS['tododb']->get_connection();
            }
            if ($GLOBALS['tododb'] instanceof PDO) {
                return $GLOBALS['tododb'];
            }
        }
        throw new Exception('数据库连接不可用');
    }

    /**
     * 内部助手：PDO预处理
     */
    private function pdo_prepare($sql)
    {
        $pdo = $this->get_pdo();
        return $pdo->prepare($sql);
    }

    private function resolve_table_prefix()
    {
        if (is_object($this->db) && isset($this->db->prefix) && is_string($this->db->prefix) && $this->db->prefix !== '') {
            return $this->db->prefix;
        }
        if (defined('TABLE_PREFIX') && is_string(TABLE_PREFIX) && TABLE_PREFIX !== '') {
            return TABLE_PREFIX;
        }
        if (defined('DB_PREFIX') && is_string(DB_PREFIX) && DB_PREFIX !== '') {
            return DB_PREFIX;
        }
        if (isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb']) && isset($GLOBALS['tododb']->prefix) && is_string($GLOBALS['tododb']->prefix) && $GLOBALS['tododb']->prefix !== '') {
            return $GLOBALS['tododb']->prefix;
        }
        return 'todo_';
    }

    // 事务助手：开始事务（兼容CMS_DB与PDO）
    private function begin_transaction()
    {
        try {
            if ($this->db instanceof CMS_DB) {
                return $this->db->begin_transaction();
            }
            $pdo = $this->get_pdo();
            return $pdo->beginTransaction();
        } catch (Exception $e) {
            error_log('开始事务失败: ' . $e->getMessage());
            return false;
        }
    }

    // 事务助手：提交事务
    private function commit_transaction()
    {
        try {
            if ($this->db instanceof CMS_DB) {
                return $this->db->commit();
            }
            $pdo = $this->get_pdo();
            return $pdo->commit();
        } catch (Exception $e) {
            error_log('提交事务失败: ' . $e->getMessage());
            return false;
        }
    }

    // 事务助手：回滚事务
    private function rollback_transaction()
    {
        try {
            if ($this->db instanceof CMS_DB) {
                return $this->db->rollback();
            }
            $pdo = $this->get_pdo();
            return $pdo->rollBack();
        } catch (Exception $e) {
            error_log('回滚事务失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 内部助手：执行非SELECT语句（INSERT/UPDATE/DELETE）
     * 兼容CMS_DB::query($sql, $params)与PDO/Extended_PDO
     */
    private function execute_non_select($sql, $params = [])
    {
        if ($this->db instanceof CMS_DB) {
            // 使用CMS_DB以保留datetime参数处理逻辑
            return $this->db->query($sql, $params);
        }
        try {
            if (!is_array($params)) {
                $params = empty($params) ? [] : [$params];
            }
            $stmt = $this->pdo_prepare($sql);
            $ok = $stmt->execute($params);
            return $ok ? $stmt->rowCount() : false;
        } catch (Exception $e) {
            error_log('执行SQL失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查模型是否支持
     * @param string $model_key 模型标识
     * @return bool
     */
    public function is_supported_model($model_key)
    {
        // 先检查内置支持的模型
        if (in_array($model_key, $this->supported_models)) {
            return true;
        }
        // 动态检查：models表是否已注册该模型
        $model_id = $this->get_model_id($model_key);
        return !empty($model_id);
    }

    /**
     * 检查字段属于哪一层
     * @param string $field_key 字段键名
     * @param string $model_key 模型标识
     * @return string 'base'|'model'|'custom'
     */
    public function get_field_layer($field_key, $model_key = '')
    {
        // 移除可能的前缀
        $clean_field_key = str_replace('model_field_', '', $field_key);

        // 检查是否为通用基础字段
        if (in_array($clean_field_key, $this->base_fields)) {
            return 'base';
        }

        // 检查是否为模型固定字段 - 使用动态获取的字段配置
        if ($model_key) {
            $model_class_name = 'CMS_Post_Type_' . ucfirst($model_key);
            if (class_exists($model_class_name) && method_exists($model_class_name, 'get_model_fixed_fields')) {
                $field_configs = call_user_func([$model_class_name, 'get_model_fixed_fields']);
                $configured_fields = [];
                foreach ($field_configs as $config) {
                    if (isset($config['field_key'])) {
                        $configured_fields[] = $config['field_key'];
                    }
                }
                if (in_array($clean_field_key, $configured_fields)) {
                    return 'model';
                }
            }

            // 备用：检查硬编码的字段配置
            if (isset($this->model_fixed_fields[$model_key])) {
                if (in_array($clean_field_key, $this->model_fixed_fields[$model_key])) {
                    return 'model';
                }
            }
        }

        // 默认为用户自定义字段
        return 'custom';
    }

    /**
     * 获取模型专用表名
     * @param string $model_key 模型标识
     * @return string
     */
    public function get_model_table($model_key)
    {
        if (!$this->is_supported_model($model_key)) {
            throw new Exception("不支持的模型: {$model_key}");
        }

        return $this->db->prefix . 'model_fields_' . $model_key;
    }

    /**
     * 获取模型固定字段列表
     * @param string $model_key 模型标识
     * @return array
     */
    public function get_model_fixed_fields($model_key)
    {
        if (!isset($this->model_fixed_fields[$model_key])) {
            return [];
        }

        $fields = $this->model_fixed_fields[$model_key];
        if (!empty($fields)) {
            return $fields;
        }

        if (class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_class')) {
            $model_class = Todo_Model_Manager::get_model_class($model_key);
            if ($model_class && class_exists($model_class) && method_exists($model_class, 'get_model_fixed_fields')) {
                try {
                    $configs = $model_class::get_model_fixed_fields();
                    $derived = [];
                    if (is_array($configs)) {
                        foreach ($configs as $k => $cfg) {
                            if (is_array($cfg) && isset($cfg['field_key']) && $cfg['field_key'] !== '') {
                                $derived[] = (string) $cfg['field_key'];
                                continue;
                            }
                            if (is_array($cfg) && is_string($k) && isset($cfg['field_key']) && $cfg['field_key'] !== '') {
                                $derived[] = (string) $cfg['field_key'];
                                continue;
                            }
                        }
                    }
                    $derived = array_values(array_unique(array_filter($derived, function ($v) {
                        return is_string($v) && $v !== '';
                    })));
                    $this->model_fixed_fields[$model_key] = $derived;
                    return $derived;
                } catch (Throwable $e) {
                    return [];
                }
            }
        }

        return [];
    }

    /**
     * 在保存前确保模型专用表具备所需列（动态补齐）
     * - 仅对非保留系统列进行检测与添加
     * - 新列位置：AFTER `post_id`
     * @param string $model_key
     * @param array $model_fields 传入的字段键值对
     */
    private function ensure_model_table_columns($model_key, array $model_fields)
    {
        try {
            $table_name = $this->get_model_table($model_key);
            // 获取现有列
            $existing_columns = $this->get_existing_columns($table_name);

            foreach ($model_fields as $field_key => $field_value) {
                // 跳过系统列
                if (in_array($field_key, ['field_id', 'post_id', 'created_at', 'updated_at'])) {
                    continue;
                }
                // 列名安全校验（仅允许字母、数字、下划线）
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $field_key)) {
                    error_log("非法字段名，忽略列创建: {$field_key}");
                    continue;
                }
                // 已存在则跳过
                if (in_array($field_key, $existing_columns)) {
                    continue;
                }

                // 计算列类型
                $type_sql = $this->get_mysql_column_type_for_field($model_key, $field_key, $field_value);
                // 添加列（AFTER post_id）
                $this->add_column_to_table($table_name, $field_key, $type_sql);

                // 更新现有列缓存，避免重复添加
                $existing_columns[] = $field_key;
            }
        } catch (Exception $e) {
            error_log("ensure_model_table_columns异常: " . $e->getMessage());
        }
    }

    /**
     * 获取表现有列名称列表
     */
    private function get_existing_columns($table_name)
    {
        try {
            // 不强制返回数组，让DB默认行为决定；下方兼容对象/数组两种形式
            $rows = $this->db->get_results("SHOW COLUMNS FROM `{$table_name}`");
            $cols = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (is_array($r) && isset($r['Field'])) {
                        $cols[] = $r['Field'];
                    } elseif (is_object($r) && isset($r->Field)) {
                        $cols[] = $r->Field;
                    } elseif (is_array($r) && isset($r['field'])) {
                        $cols[] = $r['field'];
                    } elseif (is_object($r) && isset($r->field)) {
                        $cols[] = $r->field;
                    }
                }
            }
            return $cols;
        } catch (Exception $e) {
            error_log("获取现有列失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取通用基础字段列表
     * @return array
     */
    public function get_base_fields()
    {
        return $this->base_fields;
    }

    /**
     * 根据模型和字段推断MySQL列类型定义SQL
     * - 尽量为数值类提供NOT NULL + DEFAULT
     * - 文本类（TEXT/LONGTEXT）不设置默认值（MySQL不支持）
     * - 结合模型固定字段的语义做优先匹配
     * @param string $model_key
     * @param string $field_key
     * @param mixed  $field_value
     * @return string 例如：DECIMAL(10,2) NOT NULL DEFAULT '0.00'
     */
    private function get_mysql_column_type_for_field($model_key, $field_key, $field_value)
    {
        $fk = strtolower($field_key);

        // 明确的JSON/长文本字段
        $json_long_fields = [
            'product_services',
            'spec_config',
            'spec_details',
            'discount_config',
            'custom_params',
            'download_config'
        ];
        if (in_array($fk, $json_long_fields, true)) {
            return 'LONGTEXT';
        }

        // 小数价格类
        if (in_array($fk, ['price', 'original_price'], true)) {
            return "DECIMAL(10,2) NOT NULL DEFAULT '0.00'";
        }

        // 整数计数类
        if (in_array($fk, ['stock', 'virtual_sales', 'actual_sales', 'show_stock', 'reading_time'], true)) {
            return 'INT(11) NOT NULL DEFAULT 0';
        }

        // 布尔/开关类
        if (in_array($fk, ['is_original'], true)) {
            return 'TINYINT(1) NOT NULL DEFAULT 0';
        }

        // 类型/状态类（短字符串）
        if (in_array($fk, ['product_type', 'spec_type', 'discount_type', 'sale_status', 'delivery_type'], true)) {
            return "VARCHAR(20) NOT NULL DEFAULT ''";
        }

        // 编码/标识类
        if (in_array($fk, ['product_code'], true)) {
            return "VARCHAR(64) NOT NULL DEFAULT ''";
        }

        // 文章/下载等常用短文本
        if (in_array($fk, ['article_source', 'article_author'], true)) {
            return "VARCHAR(100) NOT NULL DEFAULT ''";
        }

        // URL/密码/提取码等
        if (in_array($fk, ['delivery_download_url'], true)) {
            return "VARCHAR(255) NOT NULL DEFAULT ''";
        }
        if (in_array($fk, ['delivery_download_code', 'delivery_download_password'], true)) {
            return "VARCHAR(64) NOT NULL DEFAULT ''";
        }

        // 备注/说明类（长文本）
        if (preg_match('/(_note$|^memo_)/', $fk)) {
            return 'LONGTEXT';
        }

        // 其他通用匹配
        if (preg_match('/(_url$|_code$|_password$)/', $fk)) {
            return "VARCHAR(255) NOT NULL DEFAULT ''";
        }
        if (preg_match('/(_title$|_author$|_source$)/', $fk)) {
            return "VARCHAR(100) NOT NULL DEFAULT ''";
        }

        // 根据当前值进行兜底推断
        if (is_bool($field_value)) {
            return 'TINYINT(1) NOT NULL DEFAULT 0';
        }
        if (is_int($field_value)) {
            return 'INT(11) NOT NULL DEFAULT 0';
        }
        if (is_float($field_value)) {
            return "DECIMAL(10,2) NOT NULL DEFAULT '0.00'";
        }
        if (is_array($field_value) || is_object($field_value)) {
            return 'LONGTEXT';
        }
        if (is_string($field_value)) {
            $len = strlen($field_value);
            if ($len > 0 && $len <= 255) {
                return "VARCHAR(255) NOT NULL DEFAULT ''";
            }
        }

        // 默认文本类型
        return 'TEXT';
    }

    /**
     * 为模型专用表添加新列，位置在`post_id`之后
     * @param string $table_name
     * @param string $column_name
     * @param string $type_sql 列类型定义SQL
     * @return bool
     */
    private function add_column_to_table($table_name, $column_name, $type_sql)
    {
        try {
            $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` {$type_sql} AFTER `post_id`";
            $result = $this->db->query($sql);
            if ($result === false) {
                throw new Exception("添加列失败: {$column_name} -> {$table_name}");
            }
            return true;
        } catch (Exception $e) {
            error_log('添加列异常: ' . $e->getMessage());
            return false;
        }
    }
    public function ensure_model_columns_for_config($model_key)
    {
        try {
            $table_name = $this->get_model_table($model_key);
            $fields = [];
            $model_class_name = 'CMS_Post_Type_' . ucfirst($model_key);
            if (class_exists($model_class_name) && method_exists($model_class_name, 'get_model_fixed_fields')) {
                $configs = call_user_func([$model_class_name, 'get_model_fixed_fields']);
                foreach ((array) $configs as $cfg) {
                    if (isset($cfg['field_key'])) {
                        $fields[] = $cfg['field_key'];
                    }
                }
            } elseif (isset($this->model_fixed_fields[$model_key])) {
                $fields = (array) $this->model_fixed_fields[$model_key];
            }

            $fields = array_values(array_unique(array_filter($fields, function ($v) {
                return is_string($v) && $v !== '';
            })));

            // 性能优化：使用Transient缓存避免每次读取都检查Schema
            // 缓存值使用字段签名，字段列表变化时自动失效
            $cache_key = 'todo_model_schema_check_' . $model_key;
            $sig = md5(implode('|', $fields));
            if (function_exists('get_transient')) {
                $cached = get_transient($cache_key);
                if ($cached && is_string($cached) && $cached === $sig) {
                    return true;
                }
            }

            $table_exists = false;
            try {
                if (function_exists('table_exists')) {
                    $table_exists = table_exists($table_name);
                } else {
                    $cnt = $this->db->get_var(
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                        [$table_name]
                    );
                    $table_exists = ($cnt && intval($cnt) > 0);
                }
            } catch (Throwable $e) {
                $table_exists = false;
            }
            if (!$table_exists) {
                try {
                    $this->create_model_table($model_key);
                } catch (Throwable $e) {
                }
                // 重新检查表是否已创建：自定义模型可能无固定字段，create_model_table 不会建表
                try {
                    if (function_exists('table_exists')) {
                        $table_exists = table_exists($table_name);
                    } else {
                        $cnt = $this->db->get_var(
                            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                            [$table_name]
                        );
                        $table_exists = ($cnt && intval($cnt) > 0);
                    }
                } catch (Throwable $e) {
                    $table_exists = false;
                }
                // 表仍然不存在（且无固定字段需迁入），直接跳过
                if (!$table_exists) {
                    return true;
                }
            }

            $existing = $this->get_existing_columns($table_name);
            foreach ($fields as $fk) {
                if (in_array($fk, ['field_id', 'post_id', 'created_at', 'updated_at'])) {
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $fk)) {
                    continue;
                }
                if (in_array($fk, $existing)) {
                    continue;
                }
                $type_sql = $this->get_mysql_column_type_for_field($model_key, $fk, '');
                $this->add_column_to_table($table_name, $fk, $type_sql);
                $existing[] = $fk;
            }

            // 检查完成后设置缓存
            if (function_exists('set_transient')) {
                set_transient($cache_key, $sig, DAY_IN_SECONDS);
            }

            return true;
        } catch (Exception $e) {
            error_log('ensure_model_columns_for_config失败: ' . $e->getMessage());
            return false;
        }
    }
    /**
     * 根据模型key获取模型ID
     * @param string $model_key 模型标识
     * @return int|null
     */
    private function get_model_id($model_key)
    {
        $model_key = is_string($model_key) ? trim($model_key) : '';
        if ($model_key === '') {
            return null;
        }

        try {
            $mid = $this->get_model_id_by_key($model_key);
            if ($mid) {
                return (int) $mid;
            }

            $prefix = $this->resolve_table_prefix();
            $table = $prefix . 'models';
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $table = 'todo_models';
            }
            $stmt = $this->pdo_prepare("SELECT model_id FROM `{$table}` WHERE model_key = ? LIMIT 1");
            $stmt->execute([$model_key]);
            $row = $stmt->fetchColumn();
            try {
                $stmt->closeCursor();
            } catch (Throwable $e) {
            }
            if ($row) {
                return (int) $row;
            }

            if (defined('TABLE_PREFIX') && class_exists('Todo_Model_Manager') && method_exists('Todo_Model_Manager', 'get_model_id_by_key')) {
                $mid = Todo_Model_Manager::get_model_id_by_key($model_key);
                return $mid ? (int) $mid : null;
            }

            return null;
        } catch (Exception $e) {
            error_log('获取模型ID失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 保存模型字段数据（三层架构）
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @param array $field_data 字段数据
     * @param bool $preserve_unrelated_fields 是否保留不相关字段（模型迁移时使用）
     * @return bool
     */
    public function save_model_fields($model_key, $post_id, $field_data, $preserve_unrelated_fields = false)
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                throw new Exception("不支持的模型: {$model_key}");
            }

            $model_id = $this->get_model_id($model_key);
            if (!$model_id) {
                throw new Exception("未找到模型: {$model_key}");
            }

            // 开始事务
            $this->begin_transaction();

            // 分离三层字段数据
            $base_fields = [];
            $model_fields = [];
            $custom_fields = [];

            foreach ($field_data as $field_key => $field_value) {
                $clean_field_key = str_replace('model_field_', '', $field_key);
                $layer = $this->get_field_layer($clean_field_key, $model_key);

                switch ($layer) {
                    case 'base':
                        $base_fields[$clean_field_key] = $field_value;
                        break;
                    case 'model':
                        $model_fields[$clean_field_key] = $field_value;
                        break;
                    case 'custom':
                        $custom_fields[$field_key] = $field_value;
                        break;
                }
            }

            // 保存第一层：通用基础字段到posts表
            if (!empty($base_fields)) {
                $this->save_base_fields($post_id, $base_fields);
            }

            // 保存第二层：模型固定字段到模型专用表
            if (!empty($model_fields)) {
                $this->save_model_fixed_fields($model_key, $post_id, $model_fields);
            }

            // 保存第三层：用户自定义字段 - 委托给post-fields-manager
            if (!empty($custom_fields)) {
                $post_fields_manager = Todo_Post_Fields_Manager::get_instance();
                $model_id = $this->get_model_id_by_key($model_key);
                $post_fields_manager->save_custom_fields($post_id, $model_id, $custom_fields, 'post');
            }

            // 如果不是保留模式，清理不相关的模型固定字段和自定义字段
            if (!$preserve_unrelated_fields) {
                $this->clean_unrelated_fields($model_key, $post_id);
            }

            // 提交事务
            $this->commit_transaction();

            error_log("模型字段保存成功 - 模型: {$model_key}, 文章ID: {$post_id}, 保留模式: " . ($preserve_unrelated_fields ? '是' : '否'));
            return true;

        } catch (Exception $e) {
            // 回滚事务
            $this->rollback_transaction();
            error_log("保存模型字段失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 保存第一层：通用基础字段到posts表
     * @param int $post_id 文章ID
     * @param array $base_fields 基础字段数据
     * @return bool
     */
    public function save_base_fields($post_id, $base_fields)
    {
        if (empty($base_fields)) {
            return true;
        }

        try {
            // 移除强制默认值逻辑，防止在更新时覆盖未传递的字段（如post_status、post_type等）
            // 原逻辑会强制将未传递的post_status重置为draft，导致发布状态丢失
            /*
            // 确保必需字段有默认值
            $required_defaults = [
                'seo_title' => '',
                'seo_keywords' => '',
                'seo_description' => '',
                'custom_url' => '',
                'post_name' => '',
                'post_type' => 'article',
                'post_status' => 'draft',
                'comment_status' => 'open',
                'read_permission' => 'public',
                'view_template' => ''
            ];

            // 合并默认值，但不覆盖已有值
            foreach ($required_defaults as $field => $default_value) {
                if (!isset($base_fields[$field]) || $base_fields[$field] === null || $base_fields[$field] === '') {
                    $base_fields[$field] = $default_value;
                }
            }
            */

            $set_clauses = [];
            $values = [];

            foreach ($base_fields as $field_key => $field_value) {
                // 直接使用字段名作为数据库字段名
                $db_field_name = $field_key;

                // 处理字段值
                $processed_value = $this->process_field_value($field_value, $field_key);
                $set_clauses[] = "`{$db_field_name}` = ?";
                $values[] = $processed_value;
            }

            $values[] = $post_id;

            $sql = "UPDATE {$this->db->prefix}posts SET " . implode(', ', $set_clauses) . " WHERE id = ?";
            $result = $this->execute_non_select($sql, $values);

            if ($result === false) {
                throw new Exception("更新posts表失败");
            }

            return true;

        } catch (Exception $e) {
            error_log("保存基础字段失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 保存第二层：模型固定字段到模型专用表
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @param array $model_fields 模型字段数据
     * @return bool
     */
    private function save_model_fixed_fields($model_key, $post_id, $model_fields)
    {
        if (empty($model_fields)) {
            return true;
        }

        // 检查配置字段的值是否为空，排除post_id字段
        $has_non_empty_fields = false;
        foreach ($model_fields as $field_key => $field_value) {
            // 跳过系统自动生成的字段如post_id
            if ($field_key === 'post_id') {
                continue;
            }
            // 检查配置中定义的字段
            if (!empty($field_value) || $field_value === '0') {
                $has_non_empty_fields = true;
                break;
            }
        }

        // 检查记录是否已存在
        $table_name = $this->get_model_table($model_key);
        $check_sql = "SELECT COUNT(*) as count FROM `{$table_name}` WHERE post_id = ?";
        $count = $this->db->get_var($check_sql, [$post_id]);
        $record_exists = intval($count) > 0;

        // 如果记录存在且所有配置字段都为空，删除记录
        if ($record_exists && !$has_non_empty_fields) {
            try {
                $delete_sql = "DELETE FROM `{$table_name}` WHERE post_id = ?";
                $result = $this->execute_non_select($delete_sql, [$post_id]);

                if ($result === false) {
                    error_log("删除空模型固定字段记录失败: {$table_name}, post_id: {$post_id}");
                    return false;
                }

                return true;
            } catch (Exception $e) {
                error_log("删除空模型固定字段记录异常: " . $e->getMessage());
                return false;
            }
        }

        // 如果记录不存在且所有配置字段都为空，直接返回成功（不插入空记录）
        if (!$record_exists && !$has_non_empty_fields) {
            error_log("跳过插入空记录 - 模型: {$model_key}, 文章ID: {$post_id}");
            return true;
        }

        try {
            // 尝试调用具体模型类的save_fields方法
            $model_class_name = 'CMS_Post_Type_' . ucfirst($model_key);
            
            // 添加调试日志
            error_log("保存模型字段 - Model: $model_key, Class: $model_class_name, Post ID: $post_id, Fields: " . json_encode(array_keys($model_fields), JSON_UNESCAPED_UNICODE));

            if (class_exists($model_class_name)) {
                // 优先尝试调用实例方法 save_fields_unified
                if (method_exists($model_class_name, 'save_fields_unified')) {
                    error_log("调用 $model_class_name::save_fields_unified()");
                    $model_instance = new $model_class_name();
                    $result = $model_instance->save_fields_unified($post_id, $model_fields);
                    error_log("$model_class_name::save_fields_unified() 返回: " . ($result ? 'true' : 'false'));
                    if (!$result) {
                        error_log("模型字段保存失败 - 模型: {$model_key}, 文章ID: {$post_id}");
                    }
                    return $result;
                }
                // 回退到静态方法 save_fields
                elseif (method_exists($model_class_name, 'save_fields')) {
                    error_log("调用 $model_class_name::save_fields() (静态方法)");
                    $result = call_user_func([$model_class_name, 'save_fields'], $post_id, $model_fields);
                    error_log("$model_class_name::save_fields() 返回: " . ($result ? 'true' : 'false'));
                    return $result;
                } else {
                    error_log("$model_class_name 类存在，但没有 save_fields_unified 或 save_fields 方法");
                }
            } else {
                error_log("模型类不存在: $model_class_name");
            }

            // 如果模型类不存在或没有save_fields方法，使用通用保存逻辑
            error_log("使用通用保存逻辑保存模型字段: {$model_key}");
            $table_name = $this->get_model_table($model_key);

            // 动态补齐缺失列，避免 Unknown column 错误
            $this->ensure_model_table_columns($model_key, $model_fields);

            // 使用 INSERT ... ON DUPLICATE KEY UPDATE 确保记录存在
            $field_keys = array_keys($model_fields);

            // 确保post_id字段存在且不重复
            if (!in_array('post_id', $field_keys)) {
                $field_keys[] = 'post_id';
            }

            $values = [];
            foreach ($model_fields as $field_key => $field_value) {
                $values[] = $this->process_field_value($field_value, $field_key);
            }

            // 只有当post_id不在model_fields中时才添加其值
            if (!array_key_exists('post_id', $model_fields)) {
                $values[] = $post_id;
            }

            // 构建INSERT语句
            $placeholders = str_repeat('?,', count($values) - 1) . '?';
            $fields_str = '`' . implode('`, `', $field_keys) . '`';

            // 构建UPDATE子句
            $update_clauses = [];
            foreach ($model_fields as $field_key => $field_value) {
                $update_clauses[] = "`{$field_key}` = VALUES(`{$field_key}`)";
            }

            $sql = "INSERT INTO `{$table_name}` ({$fields_str}) VALUES ({$placeholders})";
            if (!empty($update_clauses)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $update_clauses);
            }

            // 使用 PDO 预处理执行，避免误用 PDO::query 的第二参数（fetchMode）
            $stmt = $this->pdo_prepare($sql);
            $ok = $stmt->execute($values);

            if (!$ok) {
                throw new Exception("操作模型专用表失败: {$table_name}");
            }

            return true;

        } catch (Exception $e) {
            error_log("保存模型固定字段失败: " . $e->getMessage());
            throw $e;
        }
    }
    /**
     * 获取模型ID通过模型标识
     * @param string $model_key 模型标识
     * @return int 模型ID
     */
    private function get_model_id_by_key($model_key)
    {
        try {
            $model_key = is_string($model_key) ? trim($model_key) : '';
            if ($model_key === '') {
                return 0;
            }

            $prefix = $this->resolve_table_prefix();
            $table = $prefix . 'models';
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $table = 'todo_models';
            }

            $result = null;
            if (is_object($this->db) && method_exists($this->db, 'get_var')) {
                $sql = "SELECT model_id FROM `{$table}` WHERE model_key = ? LIMIT 1";
                $result = $this->db->get_var($sql, [$model_key]);
            }

            if (!$result) {
                $stmt = $this->pdo_prepare("SELECT model_id FROM `{$table}` WHERE model_key = ? LIMIT 1");
                $stmt->execute([$model_key]);
                $result = $stmt->fetchColumn();
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                }
            }

            return $result ? intval($result) : 0;
        } catch (Exception $e) {
            error_log("获取模型ID失败: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 保存模型预设字段（兼容旧版本调用）
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @param array $field_data 字段数据
     * @return bool
     */
    public function save_model_preset_fields($model_key, $post_id, $field_data)
    {
        try {
            // 调用新的统一保存方法
            return $this->save_model_fields($model_key, $post_id, $field_data, false);
        } catch (Exception $e) {
            error_log("保存模型预设字段失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取模型字段数据（三层合并）
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return array
     */
    public function get_model_fields($model_key, $post_id)
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                throw new Exception("不支持的模型: {$model_key}");
            }

            $all_fields = [];

            // 获取第一层：通用基础字段
            $base_fields = $this->get_base_fields_data($post_id);
            if ($base_fields && is_array($base_fields)) {
                $all_fields = array_merge($all_fields, $base_fields);
            }

            // 获取第二层：模型固定字段
            $model_fields = $this->get_model_fixed_fields_data($model_key, $post_id);
            if ($model_fields) {
                // 确保是数组（兼容对象返回）
                if (is_object($model_fields)) {
                    $model_fields = (array) $model_fields;
                }
                if (is_array($model_fields)) {
                    $all_fields = array_merge($all_fields, $model_fields);
                }
            }

            // 获取第三层：用户自定义字段
            $custom_fields = $this->get_custom_fields_data($model_key, $post_id);
            if ($custom_fields && is_array($custom_fields)) {
                $all_fields = array_merge($all_fields, $custom_fields);
            }

            return $all_fields;

        } catch (Exception $e) {
            error_log("获取模型字段数据失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取第一层：通用基础字段数据
     * @param int $post_id 文章ID
     * @return array
     */
    private function get_base_fields_data($post_id)
    {
        try {
            // 构建数据库字段名数组
            $db_fields = [];
            foreach ($this->base_fields as $field_key) {
                $db_fields[] = $field_key;
            }

            $fields_str = '`' . implode('`, `', $db_fields) . '`';
            $sql = "SELECT {$fields_str} FROM {$this->db->prefix}posts WHERE ID = ?";
            $result = $this->db->get_row($sql, [$post_id], 'ARRAY_A');

            if (!$result) {
                return [];
            }

            // 确保 $result 是数组（兼容对象返回）
            if (is_object($result)) {
                $result = (array) $result;
            }

            // 将数据库字段名转换回前端字段名
            $mapped_result = [];
            foreach ($this->base_fields as $field_key) {
                if (isset($result[$field_key])) {
                    $mapped_result[$field_key] = $result[$field_key];
                }
            }

            return $mapped_result;

        } catch (Exception $e) {
            error_log("获取基础字段数据失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取第二层：模型固定字段数据
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return array
     */
    private function get_model_fixed_fields_data($model_key, $post_id)
    {
        try {
            // 读取前先确保模型表具备配置中的字段列，避免 Unknown column
            $this->ensure_model_columns_for_config($model_key);
            $model_fields = $this->get_model_fixed_fields($model_key);
            if (empty($model_fields)) {
                return [];
            }

            $table_name = $this->get_model_table($model_key);
            $existing = $this->get_existing_columns($table_name);
            $select_fields = array_values(array_filter($model_fields, function ($f) use ($existing) {
                return is_string($f) && $f !== '' && in_array($f, $existing, true);
            }));

            $result = null;
            if (!empty($select_fields)) {
                $fields_str = '`' . implode('`, `', $select_fields) . '`';
                $sql = "SELECT {$fields_str} FROM `{$table_name}` WHERE post_id = ?";
                $result = $this->db->get_row($sql, [$post_id], 'ARRAY_A');
            }

            // 如果没有找到记录，返回空数组（不自动创建记录）
            if (!$result) {
                // 返回包含默认值的数组，但不插入数据库
                $default_values = [];
                foreach ($model_fields as $field_name) {
                    if ($field_name !== 'field_id' && $field_name !== 'post_id' && $field_name !== 'created_at' && $field_name !== 'updated_at') {
                        // 根据模型类型和字段名设置默认值
                        $default_values[$field_name] = $this->get_field_default_value($model_key, $field_name);
                    }
                }
                return $default_values;
            }

            // 确保 $result 是数组（兼容对象返回）
            if (is_object($result)) {
                $result = (array) $result;
            }

            if ($model_key === 'product' && is_array($result)) {
                if (isset($result['memo_before_pay']) && !isset($result['before_payment_note'])) {
                    $result['before_payment_note'] = $result['memo_before_pay'];
                }
                if (isset($result['memo_after_pay']) && !isset($result['after_payment_note'])) {
                    $result['after_payment_note'] = $result['memo_after_pay'];
                }
                if (isset($result['memo_after_confirm']) && !isset($result['confirm_receipt_note'])) {
                    $result['confirm_receipt_note'] = $result['memo_after_confirm'];
                }
            }

            return $result ? $result : [];

        } catch (Exception $e) {
            error_log("获取模型固定字段数据失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取第三层：用户自定义字段数据
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return array
     */
    private function get_custom_fields_data($model_key, $post_id)
    {
        try {
            $model_id = $this->get_model_id($model_key);
            if (!$model_id) {
                return [];
            }

            $sql = "SELECT field_key, field_value FROM {$this->db->prefix}post_custom_fields 
                    WHERE post_id = ? AND model_id = ?";
            $results = $this->db->get_results($sql, [$post_id, $model_id], 'ARRAY_A');

            // 确保结果是数组格式
            if ($results && is_array($results)) {
                // 检查第一个元素是否为对象，如果是则转换为数组
                if (!empty($results) && is_object($results[0])) {
                    $results = array_map(function ($obj) {
                        return (array) $obj;
                    }, $results);
                }
            }

            $custom_fields = [];
            if ($results) {
                foreach ($results as $row) {
                    // 为自定义字段添加model_field_前缀，保持与前端一致
                    $custom_fields['model_field_' . $row['field_key']] = $row['field_value'];
                }
            }

            return $custom_fields;

        } catch (Exception $e) {
            error_log("获取自定义字段数据失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 根据模型类型和字段名获取字段默认值
     * @param string $model_key 模型标识
     * @param string $field_name 字段名
     * @return mixed
     */
    private function get_field_default_value($model_key, $field_name)
    {
        // 特殊处理datetime字段，避免空字符串导致的数据库错误
        if (strpos($field_name, '_time') !== false || strpos($field_name, '_date') !== false) {
            return null;
        }
        // 根据模型类型设置字段默认值
        switch ($model_key) {
            case 'article':
                switch ($field_name) {
                    case 'article_source':
                        return '';
                    case 'article_author':
                        return '';
                    case 'reading_time':
                        return 0;
                    case 'is_original':
                        return 1;
                    default:
                        return '';
                }
                break;

            case 'product':
                switch ($field_name) {
                    case 'product_video':
                    case 'product_type':
                    case 'product_code':
                    case 'delivery_type':
                    case 'delivery_config':
                    case 'spec_type':
                    case 'discount_type':
                    case 'sale_status':
                        return '';
                    case 'stock':
                    case 'virtual_sales':
                    case 'actual_sales':
                    case 'show_stock':
                        return 0;
                    case 'price':
                    case 'original_price':
                        return '0.00';
                    case 'is_original':
                        return 1;
                    default:
                        return '';
                }
                break;

            case 'images':
            case 'download':
            case 'media':
            case 'single':
            case 'special':
            case 'guestbook':
            case 'recruit':
                // 其他模型的默认值处理
                switch ($field_name) {
                    case 'is_original':
                        return 1;
                    default:
                        return '';
                }
                break;

            default:
                // 未知模型的默认处理
                switch ($field_name) {
                    case 'is_original':
                        return 1;
                    default:
                        return '';
                }
        }
    }

    /**
     * 清理不相关的字段（仅清理模型固定字段和自定义字段，保留基础字段）
     * @param string $model_key 当前模型标识
     * @param int $post_id 文章ID
     * @return bool
     */
    private function clean_unrelated_fields($model_key, $post_id)
    {
        try {
            // 清理其他模型的固定字段（保留当前模型的）
            foreach ($this->supported_models as $other_model) {
                if ($other_model !== $model_key) {
                    $this->delete_model_fixed_fields($other_model, $post_id);
                }
            }

            // 清理其他模型的自定义字段（保留当前模型的）
            $current_model_id = $this->get_model_id($model_key);
            if ($current_model_id) {
                $sql = "DELETE FROM {$this->db->prefix}post_custom_fields WHERE post_id = ? AND model_id != ?";
                $this->execute_non_select($sql, [$post_id, $current_model_id]);
            }

            return true;

        } catch (Exception $e) {
            error_log("清理不相关字段失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 模型迁移专用方法：保留所有基础字段，只更新传入的字段
     * @param string $from_model_key 原模型标识
     * @param string $to_model_key 目标模型标识
     * @param int $post_id 文章ID
     * @param array $field_data 新模型的字段数据
     * @return bool
     */
    public function migrate_model_fields($from_model_key, $to_model_key, $post_id, $field_data)
    {
        try {
            if (!$this->is_supported_model($from_model_key) || !$this->is_supported_model($to_model_key)) {
                throw new Exception("不支持的模型: {$from_model_key} -> {$to_model_key}");
            }

            // 开始事务
            $this->begin_transaction();

            // 1. 保存新模型的字段数据（保留不相关字段模式）
            $this->save_model_fields($to_model_key, $post_id, $field_data, true);

            // 2. 清理原模型的固定字段和自定义字段（但保留基础字段）
            if ($from_model_key !== $to_model_key) {
                $this->delete_model_fixed_fields($from_model_key, $post_id);

                $from_model_id = $this->get_model_id($from_model_key);
                if ($from_model_id) {
                    $prefix = $this->resolve_table_prefix();
                    $sql = "DELETE FROM {$prefix}post_custom_fields WHERE post_id = ? AND model_id = ?";
                    $this->execute_non_select($sql, [$post_id, $from_model_id]);
                }
            }

            // 提交事务
            $this->commit_transaction();

            error_log("模型迁移成功 - 从 {$from_model_key} 到 {$to_model_key}, 文章ID: {$post_id}");
            return true;

        } catch (Exception $e) {
            // 回滚事务
            $this->rollback_transaction();
            error_log("模型迁移失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 删除模型字段数据（三层清理）
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @param bool $preserve_base_fields 是否保留基础字段（模型迁移时使用）
     * @return bool
     */
    public function delete_model_fields($model_key, $post_id, $preserve_base_fields = false)
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                throw new Exception("不支持的模型: {$model_key}");
            }

            // 开始事务
            $this->begin_transaction();

            // 清理第一层：通用基础字段（仅在不保留模式下清理）
            if (!$preserve_base_fields) {
                $this->clear_base_fields($post_id);
            }

            // 清理第二层：模型固定字段
            $this->delete_model_fixed_fields($model_key, $post_id);

            // 清理第三层：用户自定义字段
            $this->delete_custom_fields($model_key, $post_id);

            // 提交事务
            $this->commit_transaction();

            error_log("模型字段删除成功 - 模型: {$model_key}, 文章ID: {$post_id}, 保留基础字段: " . ($preserve_base_fields ? '是' : '否'));
            return true;

        } catch (Exception $e) {
            // 回滚事务
            $this->rollback_transaction();
            error_log("删除模型字段失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清理第一层：通用基础字段（设为默认值）
     * @param int $post_id 文章ID
     * @return bool
     */
    private function clear_base_fields($post_id)
    {
        try {
            // 只清理可选的基础字段，保留必要字段
            $clearable_fields = [
                'excerpt' => '',
                'featured_image' => '',
                'seo_title' => '',
                'seo_keywords' => '',
                'seo_description' => '',
                'custom_url' => '',
                'view_template' => ''
            ];

            $set_clauses = [];
            $values = [];

            foreach ($clearable_fields as $field_key => $default_value) {
                $set_clauses[] = "`{$field_key}` = ?";
                $values[] = $default_value;
            }

            $values[] = $post_id;

            $sql = "UPDATE {$this->db->prefix}posts SET " . implode(', ', $set_clauses) . " WHERE id = ?";
            $result = $this->execute_non_select($sql, $values);

            if ($result === false) {
                throw new Exception("清理基础字段失败");
            }

            return true;

        } catch (Exception $e) {
            error_log("清理基础字段失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 删除第二层：模型固定字段
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return bool
     */
    private function delete_model_fixed_fields($model_key, $post_id)
    {
        try {
            $table_name = $this->get_model_table($model_key);

            $sql = "DELETE FROM `{$table_name}` WHERE post_id = ?";
            $result = $this->execute_non_select($sql, [$post_id]);

            if ($result === false) {
                throw new Exception("删除模型固定字段失败: {$table_name}");
            }

            return true;

        } catch (Exception $e) {
            error_log("删除模型固定字段失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 删除第三层：用户自定义字段
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return bool
     */
    private function delete_custom_fields($model_key, $post_id)
    {
        try {
            $model_id = $this->get_model_id($model_key);
            if (!$model_id) {
                return true; // 模型不存在，无需删除
            }

            $sql = "DELETE FROM {$this->db->prefix}post_custom_fields WHERE post_id = ? AND model_id = ?";
            $result = $this->execute_non_select($sql, [$post_id, $model_id]);

            if ($result === false) {
                throw new Exception("删除自定义字段失败");
            }

            return true;

        } catch (Exception $e) {
            error_log("删除自定义字段失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 处理字段值（格式化和验证）
     * @param mixed $field_value 字段值
     * @param string $field_key 字段键名（可选，用于特殊处理）
     * @return string
     */
    public function process_field_value($field_value, $field_key = '')
    {
        if (is_array($field_value) || is_object($field_value)) {
            // 对多图/文件结构数组中的 url 进行规范化处理
            $array_val = json_decode(json_encode($field_value), true);
            if (is_array($array_val) && function_exists('todo_media_url_to_relative')) {
                array_walk_recursive($array_val, function (&$v, $k) {
                    if ($k === 'url' && is_string($v) && $v !== '') {
                        $v = todo_media_url_to_relative($v);
                    }
                });
                $field_value = $array_val;
            }
            return json_encode($field_value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($field_value)) {
            return $field_value ? '1' : '0';
        }

        if (is_null($field_value) || $field_value === '') {
            // 对于decimal类型字段，空值应该设置为0.00
            if (in_array($field_key, ['price', 'original_price'])) {
                return '0.00';
            }
            // 对于整数类型字段，空值应该设置为0
            if (in_array($field_key, ['stock', 'virtual_sales', 'actual_sales', 'show_stock', 'reading_time'])) {
                return '0';
            }
            // 对于SEO相关字段，确保不为null
            if (in_array($field_key, ['seo_title', 'seo_keywords', 'seo_description', 'custom_url', 'post_name'])) {
                return '';
            }
            // 对于其他NOT NULL字段，返回空字符串
            return '';
        }

        if (is_string($field_key) && $field_key !== '') {
            $k = strtolower(trim($field_key));
            if ($k === 'email' || str_ends_with($k, '_email')) {
                $email = trim((string) $field_value);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return '';
                }
            }
        }

        $str_val = (string) $field_value;
        // 如果是图片/文件字段，或是带有 JSON 结构的媒体数据，进行规范化入库转换
        if ($str_val !== '' && function_exists('todo_media_url_to_relative')) {
            $k = strtolower(trim($field_key));
            $is_media_field = in_array($k, ['post_images', 'featured_image', 'cover_image', 'thumb', 'images', 'post_featured_image', 'category_image', 'cat_image', 'logo', 'company_logo'], true) || str_ends_with($k, '_image') || str_ends_with($k, '_images') || str_ends_with($k, '_file') || str_ends_with($k, '_files');
            
            if ($str_val[0] === '[' || $str_val[0] === '{') {
                $raw_json = $str_val;
                $was_slashed = (strpos($raw_json, '\"') !== false || strpos($raw_json, "\'") !== false);
                if ($was_slashed) {
                    $raw_json = stripslashes($raw_json);
                }
                $decoded = json_decode($raw_json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    array_walk_recursive($decoded, function (&$v, $key_name) {
                        if (is_string($v) && $v !== '') {
                            if ($key_name === 'url' || strpos($v, 'content/uploads/') !== false || strpos($v, '/content/') !== false || preg_match('#^https?://#i', $v)) {
                                $v = todo_media_url_to_relative($v);
                            }
                        }
                    });
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            } elseif ($is_media_field) {
                return todo_media_url_to_relative($str_val);
            }
        }

        return $str_val;
    }

    /**
     * 创建模型专用表
     * @param string $model_key 模型标识
     * @return bool
     */
    public function create_model_table($model_key)
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                throw new Exception("不支持的模型: {$model_key}");
            }

            $table_name = $this->get_model_table($model_key);
            $model_fields = $this->get_model_fixed_fields($model_key);

            if (empty($model_fields)) {
                error_log("模型 {$model_key} 没有固定字段，跳过表创建");
                return true;
            }

            // 构建字段定义
            $field_definitions = [];
            foreach ($model_fields as $field_name) {
                $field_definitions[] = "`{$field_name}` TEXT";
            }

            $fields_sql = implode(",\n    ", $field_definitions);

            $charset_collate = method_exists($this->db, 'get_charset_collate')
                ? $this->db->get_charset_collate()
                : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

            $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
    `field_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
    {$fields_sql},
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`field_id`),
    UNIQUE KEY `post_id` (`post_id`),
    KEY `idx_post_id` (`post_id`)
) {$charset_collate}";

            $result = $this->db->query($sql);

            if ($result === false) {
                throw new Exception("创建模型表失败: {$table_name}");
            }

            error_log("模型表创建成功: {$table_name}");
            return true;

        } catch (Exception $e) {
            error_log("创建模型表失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 创建所有模型专用表
     * @return array 创建结果
     */
    public function create_all_model_tables()
    {
        $results = [];

        foreach ($this->supported_models as $model_key) {
            $results[$model_key] = $this->create_model_table($model_key);
        }

        return $results;
    }

    /**
     * 创建模型字段记录
     * @param string $model_key 模型标识
     * @param int $post_id 文章ID
     * @return bool
     */
    private function create_model_field_record($model_key, $post_id)
    {
        try {
            $model_fields = $this->get_model_fixed_fields($model_key);
            if (empty($model_fields)) {
                return true;
            }

            $table_name = $this->get_model_table($model_key);

            // 构建插入字段和值
            $insert_fields = ['post_id'];
            $insert_values = [$post_id];
            $placeholders = ['?'];

            // 为每个模型字段设置默认值
            foreach ($model_fields as $field_name) {
                if ($field_name !== 'field_id' && $field_name !== 'post_id' && $field_name !== 'created_at' && $field_name !== 'updated_at') {
                    $insert_fields[] = $field_name;
                    $placeholders[] = '?';

                    // 使用统一的默认值获取方法
                    if (in_array($field_name, ['product_services', 'discount_config', 'spec_config', 'spec_details'])) {
                        $insert_values[] = '{}';
                    } else {
                        $insert_values[] = $this->get_field_default_value($model_key, $field_name);
                    }
                }
            }

            $fields_str = '`' . implode('`, `', $insert_fields) . '`';
            $placeholders_str = implode(', ', $placeholders);

            $sql = "INSERT INTO `{$table_name}` ({$fields_str}) VALUES ({$placeholders_str})";
            $result = $this->db->query($sql, $insert_values);

            return $result !== false;

        } catch (Exception $e) {
            error_log("创建模型字段记录失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查模型表是否存在
     * @param string $model_key 模型标识
     * @return bool
     */
    public function model_table_exists($model_key)
    {
        try {
            $table_name = $this->get_model_table($model_key);
            $sql = "SHOW TABLES LIKE '{$table_name}'";
            $result = $this->db->get_var($sql);

            return !empty($result);

        } catch (Exception $e) {
            error_log("检查模型表存在性失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取字段配置（用于兼容model-fields-component.php）
     * @param string $model_key 模型标识
     * @param array $category_ids 栏目ID数组
     * @return array 字段配置数组
     */
    public function get_field_configs($model_key, $category_ids = array())
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                return array();
            }

            // 获取自定义字段配置
            // 这里暂时返回空数组，因为自定义字段功能还未完全实现
            // 未来可以从todo_post_custom_fields表中获取字段配置
            $custom_fields = array();

            return $custom_fields;

        } catch (Exception $e) {
            error_log("获取字段配置失败: " . $e->getMessage());
            return array();
        }
    }

    /**
     * 检查字段是否为模型预设字段
     * @param string $model_key 模型标识
     * @param string $field_key 字段名
     * @return bool
     */
    public function is_model_preset_field($model_key, $field_key)
    {
        try {
            if (!$this->is_supported_model($model_key)) {
                return false;
            }

            // 检查是否为基础字段
            if (in_array($field_key, $this->base_fields)) {
                return true;
            }

            // 检查是否为模型固定字段
            $model_fields = $this->get_model_fixed_fields($model_key);
            if (in_array($field_key, $model_fields)) {
                // 添加调试日志
                if ($field_key === 'product_video') {
                    error_log("is_model_preset_field: $field_key 在模型固定字段列表中 (model_key: $model_key)");
                }
                return true;
            }
            $model_class_name = 'CMS_Post_Type_' . ucfirst($model_key);
            if (class_exists($model_class_name) && method_exists($model_class_name, 'get_model_fixed_fields')) {
                $configs = call_user_func([$model_class_name, 'get_model_fixed_fields']);
                if (is_array($configs)) {
                    foreach ($configs as $cfg) {
                        if (is_array($cfg) && isset($cfg['field_key']) && $cfg['field_key'] === $field_key) {
                            // 添加调试日志
                            if ($field_key === 'product_video') {
                                error_log("is_model_preset_field: $field_key 在模型类固定字段配置中 (model_key: $model_key)");
                            }
                            return true;
                        }
                    }
                }
            }

            // 添加调试日志
            if ($field_key === 'product_video') {
                error_log("is_model_preset_field: $field_key 不是模型预设字段 (model_key: $model_key, model_fields: " . json_encode($model_fields) . ")");
            }

            return false;

        } catch (Exception $e) {
            error_log("检查模型预设字段失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取文章的所有字段数据（基础字段、模型字段、自定义字段）
     * @param int $post_id 文章ID
     * @param string $model_key 模型标识
     * @return array 字段数据数组
     */
    public function get_post_field_values($post_id, $model_key)
    {
        global $tododb;

        try {
            $post_id = intval($post_id);
            if (!$post_id || !$this->is_supported_model($model_key)) {
                return array();
            }

            $field_values = array();

            // 1. 获取基础字段数据（从posts表）
            $post_query = "SELECT * FROM {$tododb->prefix}posts WHERE id = ?";
            $post_data = $tododb->get_row($post_query, [$post_id], 'ARRAY_A');

            if ($post_data) {
                // 提取基础字段
                foreach ($this->base_fields as $base_field) {
                    if (isset($post_data[$base_field])) {
                        $field_values[$base_field] = $post_data[$base_field];
                    }
                }
            }

            // 2. 获取模型固定字段数据
            $model_table = $tododb->prefix . 'model_fields_' . $model_key;
            $table_exists = $tododb->get_var("SHOW TABLES LIKE '{$model_table}'");

            if ($table_exists) {
                $model_query = "SELECT * FROM `{$model_table}` WHERE `post_id` = ?";
                $model_data = $tododb->get_row($model_query, [$post_id], 'ARRAY_A');

                if ($model_data) {
                    // 排除系统字段
                    foreach ($model_data as $field_key => $field_value) {
                        if (!in_array($field_key, ['id', 'post_id', 'created_at', 'updated_at'])) {
                            $field_values['model_field_' . $field_key] = $field_value;
                        }
                    }
                }
            }

            // 3. 获取自定义字段数据
            $model_id = $this->get_model_id($model_key);
            if ($model_id) {
                $custom_query = "SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ? AND model_id = ?";
                $custom_results = $tododb->get_results($custom_query, [$post_id, $model_id], 'ARRAY_A');
                // 确保结果是数组格式
                if (is_array($custom_results) && !empty($custom_results) && is_object($custom_results[0])) {
                    $custom_results = array_map(function ($obj) {
                        return (array) $obj;
                    }, $custom_results);
                }
            } else {
                $custom_results = array();
            }

            if ($custom_results) {
                foreach ($custom_results as $custom_field) {
                    // 避免重复添加custom_field_前缀
                    $field_key = $custom_field['field_key'];
                    if (strpos($field_key, 'custom_field_') !== 0) {
                        $field_key = 'custom_field_' . $field_key;
                    }
                    $field_values[$field_key] = $custom_field['field_value'];
                }
            }

            return $field_values;

        } catch (Exception $e) {
            error_log("获取文章字段数据失败: " . $e->getMessage());
            return array();
        }
    }

}

// 文件结束

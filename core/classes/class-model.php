<?php
/**
 * 数据模型类
 *
 * 处理数据模型的创建、更新、查询等操作
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保加载数据库预处理类（包含便捷函数 tododb_insert, tododb_update, tododb_delete 等）
if (!function_exists('tododb_insert')) {
    $prepared_path = ABSPATH . 'core/classes/class-db-prepared.php';
    if (file_exists($prepared_path)) {
        require_once $prepared_path;
    }
}

class CMS_Model
{
    /**
     * 模型ID
     *
     * @var int
     */
    private $id = 0;

    /**
     * 模型数据
     *
     * @var array
     */
    private $data = [];

    /**
     * 模型字段
     *
     * @var array
     */
    private $fields = [];

    /**
     * 模型列表缓存（性能优化）
     *
     * @var array
     */
    private static $models_cache = [];

    /**
     * 字段数量缓存（性能优化）
     *
     * @var array
     */
    private static $fields_count_cache = [];

    /**
     * 构造函数
     *
     * @param int|array $id_or_data 模型ID或模型数据
     */
    public function __construct($id_or_data = 0)
    {
        if ($id_or_data) {
            $this->init($id_or_data);
        }
    }

    /**
     * 初始化模型
     *
     * @param int|array $id_or_data 模型ID或模型数据
     * @return bool 是否成功初始化
     */
    public function init($id_or_data)
    {
        if (is_array($id_or_data)) {
            $this->data = $id_or_data;
            $this->id = isset($id_or_data['model_id']) ? (int) $id_or_data['model_id'] : 0;
            $this->load_fields();
            return true;
        }

        if (is_numeric($id_or_data)) {
            return $this->load_by_id((int) $id_or_data);
        }

        return false;
    }

    /**
     * 将数据库查询结果标准化为关联数组
     *
     * @param array $results
     * @return array
     */
    private static function normalize_results_to_array($results)
    {
        if (!is_array($results)) {
            return [];
        }
        return array_map(function ($row) {
            if (is_object($row)) {
                return (array) $row;
            }
            return is_array($row) ? $row : [];
        }, $results);
    }

    /**
     * 根据ID加载模型
     *
     * @param int $id 模型ID
     * @return bool 是否成功加载
     */
    public function load_by_id($id)
    {
        global $tododb;

        $query = "SELECT * FROM " . TABLE_PREFIX . "models WHERE model_id = ? LIMIT 1";
        // 修正：CMS_DB仅支持两个参数，这里将结果标准化为数组
        $result = $tododb->get_results($query, [$id]);
        $result = self::normalize_results_to_array($result);

        if (!$result || empty($result)) {
            return false;
        }

        $this->data = $result[0];
        $this->id = (int) $this->data['model_id'];
        $this->load_fields();

        return true;
    }

    /**
     * 根据模型标识加载模型
     *
     * @param string $key 模型标识
     * @return bool 是否成功加载
     */
    public function load_by_key($key)
    {
        global $tododb;

        $query = "SELECT * FROM " . TABLE_PREFIX . "models WHERE model_key = ? LIMIT 1";
        // 修正：标准化为数组
        $result = $tododb->get_results($query, [$key]);
        $result = self::normalize_results_to_array($result);

        if (!$result) {
            return false;
        }

        $this->data = $result[0];
        $this->id = (int) $this->data['model_id'];
        $this->load_fields();

        return true;
    }

    /**
     * 加载模型字段
     */
    public function load_fields()
    {
        $model_data = $this->get_data();
        $model_id = isset($model_data['model_id']) ? (int) $model_data['model_id'] : 0;

        if (!$model_id) {
            return;
        }

        global $tododb;

        $query = "SELECT * FROM " . TABLE_PREFIX . "model_fields WHERE model_id = ? AND field_status = 'active' ORDER BY field_order ASC";
        // 修正：标准化为数组
        $result = $tododb->get_results($query, [$model_id]);
        $result = self::normalize_results_to_array($result);

        if ($result) {
            $this->fields = $result;
        }
    }

    /**
     * 创建模型
     *
     * @param array $model_data 模型数据
     * @return bool|int 成功返回模型ID，失败返回false
     */
    public function create($model_data)
    {
        global $tododb;

        // 开始事务
        if ($tododb->begin_transaction() === false) {
            return false;
        }

        try {
            // 插入模型数据（统一使用 tododb_insert，避免旧用法触发 PDO::query 第二参类型错误）
            $model_id = tododb_insert(TABLE_PREFIX . "models", [
                'model_name' => $model_data['model_name'],
                'model_key' => $model_data['model_key'],
                'model_type' => $model_data['model_type'] ?? 'article',
                'model_description' => $model_data['model_description'] ?? '',
                'model_status' => $model_data['model_status'] ?? 'active',
                'list_template' => $model_data['list_template'] ?? '',
                'view_template' => $model_data['view_template'] ?? ''
            ]);

            if ($model_id === false) {
                throw new Exception('创建模型失败');
            }

            // 创建字段
            if (isset($model_data['fields']) && is_array($model_data['fields'])) {
                foreach ($model_data['fields'] as $field) {
                    // 使用统一预处理封装插入字段
                    $insert_id = tododb_insert(TABLE_PREFIX . "model_fields", [
                        'model_id' => $model_id,
                        'field_name' => $field['field_name'],
                        'field_key' => $field['field_key'],
                        'field_type' => $field['field_type'],
                        'field_label' => $field['field_label'] ?? $field['field_name'],
                        'field_description' => $field['field_description'] ?? '',
                        'field_default' => $field['field_default'] ?? '',
                        'field_options' => $field['field_options'] ?? '',
                        'is_required' => $field['is_required'] ?? 0,
                        'field_order' => $field['field_order'] ?? 0
                    ]);

                    if ($insert_id === false) {
                        throw new Exception('创建字段失败');
                    }
                }
            }

            // 提交事务
            if ($tododb->commit() === false) {
                throw new Exception('提交事务失败');
            }

            // 清除缓存
            self::clear_cache();

            return $model_id;
        } catch (Exception $e) {
            error_log('创建模型失败: ' . $e->getMessage());
            $tododb->rollback();
            return false;
        }
    }

    /**
     * 更新模型
     *
     * @param array $model_data 模型数据
     * @return bool 是否成功更新
     */
    public function update($model_data)
    {
        if (!$this->id) {
            return false;
        }

        global $tododb;

        // 开始事务
        if ($tododb->begin_transaction() === false) {
            return false;
        }

        try {
            $sqlUpd = "UPDATE " . TABLE_PREFIX . "models 
                 SET model_name = ?, model_key = ?, model_type = ?, model_description = ?, model_status = ?, list_template = ?, view_template = ? 
                 WHERE model_id = ?";
            $paramsUpd = [
                $model_data['model_name'],
                $model_data['model_key'],
                $model_data['model_type'] ?? 'article',
                $model_data['model_description'] ?? '',
                $model_data['model_status'] ?? $this->data['model_status'] ?? 'active',
                $model_data['list_template'] ?? '',
                $model_data['view_template'] ?? '',
                $this->id
            ];
            $okUpd = false;
            if ($tododb instanceof PDO) {
                $stmtUpd = $tododb->prepare($sqlUpd);
                $okUpd = $stmtUpd->execute($paramsUpd);
            } else {
                $okUpd = (tododb_query($sqlUpd, $paramsUpd) !== false);
            }

            if (!$okUpd) {
                throw new Exception('更新模型失败');
            }

            // 更新字段
            if (isset($model_data['fields']) && is_array($model_data['fields'])) {
                $deleted = tododb_delete(TABLE_PREFIX . "model_fields", ['model_id' => $this->id]);

                // 添加新字段
                foreach ($model_data['fields'] as $field) {
                    $okInsId = tododb_insert(TABLE_PREFIX . "model_fields", [
                        'model_id' => $this->id,
                        'field_name' => $field['field_name'],
                        'field_key' => $field['field_key'],
                        'field_type' => $field['field_type'],
                        'field_label' => $field['field_label'] ?? $field['field_name'],
                        'field_description' => $field['field_description'] ?? '',
                        'field_default' => $field['field_default'] ?? '',
                        'field_options' => $field['field_options'] ?? '',
                        'is_required' => $field['is_required'] ?? 0,
                        'field_order' => $field['field_order'] ?? 0
                    ]);
                    $okIns = ($okInsId !== false);

                    if (!$okIns) {
                        throw new Exception('更新字段失败');
                    }
                }
            }

            // 提交事务
            if ($tododb->commit() === false) {
                throw new Exception('提交事务失败');
            }

            return true;
        } catch (Exception $e) {
            error_log('更新模型失败: ' . $e->getMessage());
            $tododb->rollback();
            return false;
        }
    }

    /**
     * 删除模型
     *
     * @return bool 是否成功删除
     */
    public function delete()
    {
        if (!$this->id) {
            return false;
        }

        global $tododb;

        // 开始事务
        if ($tododb->begin_transaction() === false) {
            return false;
        }

        try {
            $okFields = tododb_delete(TABLE_PREFIX . "model_fields", ['model_id' => $this->id]);
            if ($okFields === false) {
                throw new Exception('删除字段失败');
            }
            $okModel = tododb_delete(TABLE_PREFIX . "models", ['model_id' => $this->id]);
            if ($okModel === false) {
                throw new Exception('删除模型失败');
            }

            // 提交事务
            if ($tododb->commit() === false) {
                throw new Exception('提交事务失败');
            }

            // 清除缓存
            self::clear_cache();

            return true;
        } catch (Exception $e) {
            error_log('删除模型失败: ' . $e->getMessage());
            $tododb->rollback();
            return false;
        }
    }

    /**
     * 获取模型数据
     *
     * @return array 模型数据
     */
    public function get_data()
    {
        return $this->data;
    }

    /**
     * 设置模型数据
     *
     * @param array $data 模型数据
     * @return void
     */
    public function set_data($data)
    {
        $this->data = array_merge($this->data, $data);
    }

    /**
     * 保存模型数据
     * 
     * @return bool|int 成功返回模型ID，失败返回false
     */
    public function save()
    {
        if ($this->id) {
            // 更新现有模型
            return $this->update($this->data);
        } else {
            // 创建新模型
            $model_id = $this->create($this->data);
            if ($model_id) {
                $this->id = $model_id;
                return $model_id;
            }
            return false;
        }
    }

    /**
     * 获取模型字段
     *
     * @return array 模型字段
     */
    public function get_fields()
    {
        return $this->fields;
    }

    /**
     * 获取模型字段数量
     *
     * @return int 字段数量
     */
    public function get_fields_count()
    {
        return count($this->fields);
    }

    /**
     * 获取模型字段数量（静态方法，带缓存）
     *
     * @param int $model_id 模型ID
     * @return int 字段数量
     */
    public static function count_fields($model_id)
    {
        // 检查缓存
        if (isset(self::$fields_count_cache[$model_id])) {
            return self::$fields_count_cache[$model_id];
        }

        global $tododb;

        $query = "SELECT COUNT(*) as count FROM " . TABLE_PREFIX . "model_fields WHERE model_id = ? AND field_status != 'trash'";
        $count = $tododb->get_var($query, [$model_id]);

        $result = $count !== null ? (int) $count : 0;

        // 缓存结果
        self::$fields_count_cache[$model_id] = $result;

        return $result;
    }

    /**
     * 批量获取多个模型的字段数量（性能优化，解决N+1查询问题）
     *
     * @param array $model_ids 模型ID数组
     * @return array 关联数组 [model_id => count]
     */
    public static function get_fields_counts($model_ids)
    {
        return Todo_Performance_Monitor::measure('CMS_Model::get_fields_counts', function () use ($model_ids) {
            if (empty($model_ids)) {
                return [];
            }

            global $tododb;

            // 检查哪些ID已经在缓存中
            $uncached_ids = [];
            $results = [];

            foreach ($model_ids as $model_id) {
                // 1. 检查静态缓存
                if (isset(self::$fields_count_cache[$model_id])) {
                    $results[$model_id] = self::$fields_count_cache[$model_id];
                    continue;
                }

                // 2. 检查持久化缓存
                if (class_exists('Todo_Cache_Manager')) {
                    $cache_key = 'model_field_count_' . $model_id;
                    $cached_count = Todo_Cache_Manager::get($cache_key, 'models', 'global');
                    if ($cached_count !== false) {
                        $results[$model_id] = $cached_count;
                        self::$fields_count_cache[$model_id] = $cached_count;
                        continue;
                    }
                }

                $uncached_ids[] = $model_id;
            }

            // 如果所有ID都已缓存，直接返回
            if (empty($uncached_ids)) {
                return $results;
            }

            // 批量查询未缓存的ID
            // 当未缓存模型数较多 (> 20) 时，全表 GROUP BY 比生成数百个绑定占位符更加高效
            if (count($uncached_ids) > 20) {
                $query = "SELECT model_id, COUNT(*) as count 
                          FROM " . TABLE_PREFIX . "model_fields 
                          WHERE field_status != 'trash'
                          GROUP BY model_id";
                $rows = $tododb->get_results($query);
            } else {
                $placeholders = implode(',', array_fill(0, count($uncached_ids), '?'));
                $query = "SELECT model_id, COUNT(*) as count 
                          FROM " . TABLE_PREFIX . "model_fields 
                          WHERE model_id IN ($placeholders) AND field_status != 'trash'
                          GROUP BY model_id";
                $rows = $tododb->get_results($query, $uncached_ids);
            }

            // 处理查询结果
            $db_counts = [];
            foreach ($rows as $row) {
                $row_array = is_object($row) ? (array) $row : $row;
                $model_id = (int) $row_array['model_id'];
                $count = (int) $row_array['count'];
                $db_counts[$model_id] = $count;
            }

            // 填充结果并缓存
            foreach ($uncached_ids as $model_id) {
                $count = isset($db_counts[$model_id]) ? $db_counts[$model_id] : 0;
                $results[$model_id] = $count;
                self::$fields_count_cache[$model_id] = $count;

                // 设置持久化缓存
                if (class_exists('Todo_Cache_Manager')) {
                    $cache_key = 'model_field_count_' . $model_id;
                    Todo_Cache_Manager::set($cache_key, $count, 3600, 'models', 'global');
                }
            }

            return $results;
        }, 'Count fields for ' . count($model_ids) . ' models');
    }

    /**
     * 添加字段
     *
     * @param array $field_data 字段数据
     * @return bool|int 成功返回字段ID，失败返回false
     */
    public function add_field($field_data)
    {
        if (!$this->id) {
            return false;
        }

        global $tododb;

        try {
            $insert_id = tododb_insert(TABLE_PREFIX . "model_fields", [
                'model_id' => $this->id,
                'field_name' => $field_data['field_name'],
                'field_key' => $field_data['field_key'],
                'field_type' => $field_data['field_type'],
                'field_label' => $field_data['field_label'] ?? $field_data['field_name'],
                'field_description' => $field_data['field_description'] ?? '',
                'field_default' => $field_data['field_default'] ?? '',
                'field_options' => $field_data['field_options'] ?? '',
                'is_required' => $field_data['is_required'] ?? 0,
                'category_only' => $field_data['category_only'] ?? 0,
                'field_order' => $field_data['field_order'] ?? 0
            ]);
            if ($insert_id === false) {
                return false;
            }
            $field_id = $insert_id;

            // 重新加载字段
            $this->load_fields();

            return $field_id;
        } catch (Exception $e) {
            error_log('添加字段失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取所有模型（带缓存）
     *
     * @param array $args 查询参数
     * @return array 模型列表
     */
    public static function get_models($args = [])
    {
        return Todo_Performance_Monitor::measure('CMS_Model::get_models', function () use ($args) {
            // 生成缓存键
            $cache_key = 'cms_models_' . md5(serialize($args));

            // 检查静态缓存
            if (isset(self::$models_cache[$cache_key])) {
                return self::$models_cache[$cache_key];
            }

            // 检查持久化缓存
            if (class_exists('Todo_Cache_Manager')) {
                $cached = Todo_Cache_Manager::get($cache_key, 'models', 'global');
                if ($cached !== false) {
                    self::$models_cache[$cache_key] = $cached;
                    return $cached;
                }
            }

            global $tododb;

            $where = [];
            $where_values = [];

            if (isset($args['model_type'])) {
                $where[] = 'model_type = ?';
                $where_values[] = $args['model_type'];
            }

            if (isset($args['is_system'])) {
                $where[] = 'is_system = ?';
                $where_values[] = $args['is_system'];
            }

            if (isset($args['model_status'])) {
                if ($args['model_status'] === 'active') {
                    $where[] = 'model_status = ?';
                    $where_values[] = 'active';
                } elseif ($args['model_status'] === 'inactive') {
                    $where[] = 'model_status = ?';
                    $where_values[] = 'inactive';
                }
            }

            if (isset($args['s']) && !empty($args['s'])) {
                $where[] = '(model_name LIKE ? OR model_key LIKE ?)';
                $where_values[] = '%' . $args['s'] . '%';
                $where_values[] = '%' . $args['s'] . '%';
            }

            $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // 排序 - 使用白名单验证字段名
            $allowed_orderby = ['model_id', 'model_name', 'model_type', 'created_at', 'updated_at'];
            $orderby = isset($args['orderby']) && in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'model_id';
            
            // 验证ORDER方向
            $order = isset($args['order']) ? strtoupper($args['order']) : 'ASC';
            $order = ($order === 'DESC') ? 'DESC' : 'ASC';

            // 分页 - 使用占位符
            $limit_sql = '';
            $limit_params = [];
            if (isset($args['per_page']) && isset($args['paged'])) {
                $per_page = (int) $args['per_page'];
                $offset = ((int) $args['paged'] - 1) * $per_page;
                $limit_sql = "LIMIT ?, ?";
                $limit_params = [$offset, $per_page];
            }

            $query = "SELECT * FROM " . TABLE_PREFIX . "models " . $where_sql . " ORDER BY $orderby $order " . $limit_sql;
            $rows = $tododb->get_results($query, array_merge($where_values, $limit_params));

            $result = $rows ?: [];

            // 缓存结果
            self::$models_cache[$cache_key] = $result;

            // 设置持久化缓存 (1小时)
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, $result, 3600, 'models', 'global');
            }

            // 返回对象数组以兼容现有后台模板（使用 $model->model_id 等属性访问）
            return $result;
        }, 'Get models');
    }

    /**
     * 清除模型缓存
     */
    public static function clear_cache()
    {
        self::$models_cache = [];
        if (class_exists('Todo_Cache_Manager')) {
            Todo_Cache_Manager::flush_group('models');
        }
    }

    /**
     * 根据模型标识获取模型
     *
     * @param string $key 模型标识
     * @return CMS_Model|null 成功返回模型对象，失败返回null
     */
    public static function get_by_key($key)
    {
        global $tododb;

        $query = "SELECT * FROM " . TABLE_PREFIX . "models WHERE model_key = ? LIMIT 1";
        $rows = $tododb->get_results($query, [$key]);
        $rows = self::normalize_results_to_array($rows);

        if (!$rows) {
            return null;
        }

        return new CMS_Model($rows[0]);
    }

    /**
     * 统计模型数量
     *
     * @param array $args 查询参数
     * @return object 包含总数和各状态数量的对象
     */
    public static function count_models($args = [])
    {
        global $tododb;

        // 查询条件
        $where = [];
        $where_values = [];

        if (isset($args['model_type'])) {
            $where[] = 'model_type = ?';
            $where_values[] = $args['model_type'];
        }

        if (isset($args['is_system'])) {
            $where[] = 'is_system = ?';
            $where_values[] = $args['is_system'];
        }

        if (isset($args['s']) && !empty($args['s'])) {
            $where[] = '(model_name LIKE ? OR model_key LIKE ?)';
            $where_values[] = '%' . $args['s'] . '%';
            $where_values[] = '%' . $args['s'] . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // 获取总数
        $query = "SELECT COUNT(*) as total FROM " . TABLE_PREFIX . "models " . $where_sql;
        $rows = $tododb->get_results($query, $where_values);
        $rows = self::normalize_results_to_array($rows);
        $total = $rows && isset($rows[0]['total']) ? (int) $rows[0]['total'] : 0;

        // 获取启用状态的数量
        $active_where = $where;
        $active_where[] = "model_status = 'active'";
        $active_where_sql = 'WHERE ' . implode(' AND ', $active_where);
        $query = "SELECT COUNT(*) as active FROM " . TABLE_PREFIX . "models " . $active_where_sql;
        $rows = $tododb->get_results($query, $where_values);
        $rows = self::normalize_results_to_array($rows);
        $active = $rows && isset($rows[0]['active']) ? (int) $rows[0]['active'] : 0;

        // 获取禁用状态的数量
        $inactive_where = $where;
        $inactive_where[] = "model_status = 'inactive'";
        $inactive_where_sql = 'WHERE ' . implode(' AND ', $inactive_where);
        $query = "SELECT COUNT(*) as inactive FROM " . TABLE_PREFIX . "models " . $inactive_where_sql;
        $rows = $tododb->get_results($query, $where_values);
        $rows = self::normalize_results_to_array($rows);
        $inactive = $rows && isset($rows[0]['inactive']) ? (int) $rows[0]['inactive'] : 0;

        // 返回结果对象
        $counts = new stdClass();
        $counts->total = $total;
        $counts->active = $active;
        $counts->inactive = $inactive;

        return $counts;
    }

    /**
     * 获取单个字段信息
     *
     * @param int $field_id 字段ID
     * @return array|false 字段信息数组或失败返回false
     */
    public function get_field($field_id)
    {
        // 引入字段方法类
        return CMS_Model_Field_Methods::get_field($this, $field_id);
    }

    /**
     * 更新字段信息
     *
     * @param int $field_id 字段ID
     * @param array $field_data 字段数据
     * @return bool 是否成功更新
     */
    public function update_field($field_id, $field_data)
    {
        // 引入字段方法类
        return CMS_Model_Field_Methods::update_field($this, $field_id, $field_data);
    }

    /**
     * 删除字段
     *
     * @param int $field_id 字段ID
     * @return bool 是否成功删除
     */
    public function delete_field($field_id)
    {
        // 引入字段方法类
        return CMS_Model_Field_Methods::delete_field($this, $field_id);
    }

    /**
     * 将字段移至回收站
     *
     * @param int $field_id 字段ID
     * @return bool 是否成功移至回收站
     */
    public function trash_field($field_id)
    {
        return CMS_Model_Field_Methods::trash_field($this, $field_id);
    }

    /**
     * 从回收站还原字段
     *
     * @param int $field_id 字段ID
     * @return bool 是否成功还原
     */
    public function untrash_field($field_id)
    {
        return CMS_Model_Field_Methods::untrash_field($this, $field_id);
    }
}
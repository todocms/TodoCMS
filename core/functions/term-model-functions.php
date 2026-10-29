<?php
/**
 * 栏目模型关联函数
 *
 * 提供栏目与数据模型关联的相关函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 数据库查询兼容封装（支持 CMS_DB 或 原生 PDO）
if (!function_exists('todo_db_select')) {
    function todo_db_select($query, $params = [])
    {
        global $tododb;
        try {
            // CMS_DB: 优先使用 get_results（返回数组）
            if (is_object($tododb)) {
                if (method_exists($tododb, 'get_results')) {
                    return $tododb->get_results($query, $params, 'ARRAY_A');
                }
                // CMS_DB: 回退使用 query（SELECT时返回结果集数组）—仅当非原生 PDO 对象
                if (method_exists($tododb, 'query') && !($tododb instanceof PDO)) {
                    return $tododb->query($query, $params);
                }
                // 原生 PDO
                if ($tododb instanceof PDO) {
                    $stmt = $tododb->prepare($query);
                    if (!is_array($params)) {
                        $params = empty($params) ? [] : [$params];
                    }
                    $stmt->execute($params);
                    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    try {
                        $stmt->closeCursor();
                    } catch (Throwable $e) {
                    }
                    return $result;
                }
            }
        } catch (Throwable $e) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('todo_db_select error: ' . $e->getMessage());
            }
        }
        return [];
    }
}

if (!function_exists('todo_db_exec')) {
    function todo_db_exec($query, $params = [])
    {
        global $tododb;
        try {
            // CMS_DB
            if (is_object($tododb) && method_exists($tododb, 'query') && !($tododb instanceof PDO)) {
                return $tododb->query($query, $params);
            }
            // 原生 PDO
            if ($tododb instanceof PDO) {
                $stmt = $tododb->prepare($query);
                if (!is_array($params)) {
                    $params = empty($params) ? [] : [$params];
                }
                $stmt->execute($params);
                $rowCount = (int) $stmt->rowCount();
                try {
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                }
                return $rowCount;
            }
        } catch (Throwable $e) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log('todo_db_exec error: ' . $e->getMessage());
            }
        }
        return false;
    }
}

/**
 * 获取栏目关联的模型
 *
 * @param int $term_id 栏目ID
 * @return array|null 模型数据或null
 */
function get_term_model($term_id)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return null;
    }

    // 检查缓存
    if (_todo_term_model_cache_manager('has', $term_id)) {
        return _todo_term_model_cache_manager('get', $term_id);
    }

    // 查询数据库获取栏目关联的模型
    $query = "SELECT m.* FROM " . TABLE_PREFIX . "term_models tm "
        . "JOIN " . TABLE_PREFIX . "models m ON tm.model_id = m.model_id "
        . "WHERE tm.term_id = ? LIMIT 1";

    $model = todo_db_select($query, [$term_id]);

    $result = empty($model) ? null : $model[0];

    // 设置缓存
    _todo_term_model_cache_manager('set', $term_id, $result);

    return $result;
}

/**
 * 批量预加载栏目模型缓存
 *
 * @param array|int $term_ids 栏目ID或ID数组
 * @return array 成功加载的模型数据
 */
function update_term_model_cache($term_ids)
{
    global $tododb;

    if (empty($term_ids)) {
        return [];
    }

    if (!is_array($term_ids)) {
        $term_ids = preg_split('/[\s,]+/', $term_ids, -1, PREG_SPLIT_NO_EMPTY);
    }

    $term_ids = array_map('intval', $term_ids);
    $term_ids = array_unique(array_filter($term_ids));

    if (empty($term_ids)) {
        return [];
    }

    // 检查哪些尚未缓存
    $ids_to_query = [];
    foreach ($term_ids as $id) {
        if (!_todo_term_model_cache_manager('has', $id)) {
            $ids_to_query[] = $id;
        }
    }

    if (empty($ids_to_query)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
    $query = "SELECT tm.term_id, m.* FROM " . TABLE_PREFIX . "term_models tm "
        . "JOIN " . TABLE_PREFIX . "models m ON tm.model_id = m.model_id "
        . "WHERE tm.term_id IN ($placeholders)";

    $results = todo_db_select($query, $ids_to_query);

    $loaded = [];
    // 初始化这些ID的缓存为null（防止查询不到时重复查询）
    foreach ($ids_to_query as $id) {
        _todo_term_model_cache_manager('set', $id, null);
    }

    foreach ($results as $row) {
        $term_id = $row['term_id'];
        unset($row['term_id']); // 移除 term_id，保留模型数据
        _todo_term_model_cache_manager('set', $term_id, $row);
        $loaded[$term_id] = $row;
    }

    return $loaded;
}

/**
 * 内部函数：管理栏目模型缓存
 *
 * @param string $action 操作类型 (get, set, delete, has, clear)
 * @param int $term_id 栏目ID
 * @param mixed $data 数据
 * @return mixed
 */
function _todo_term_model_cache_manager($action, $term_id = null, $data = null)
{
    static $cache = [];

    switch ($action) {
        case 'get':
            return isset($cache[$term_id]) ? $cache[$term_id] : null;
        case 'set':
            $cache[$term_id] = $data;
            break;
        case 'delete':
            unset($cache[$term_id]);
            break;
        case 'has':
            return array_key_exists($term_id, $cache);
        case 'clear':
            $cache = [];
            break;
    }
}

/**
 * 设置栏目关联的模型
 *
 * @param int $term_id 栏目ID
 * @param int $model_id 模型ID
 * @return bool 是否成功
 */
function set_term_model($term_id, $model_id)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    $model_id = intval($model_id);

    if ($term_id <= 0 || $model_id <= 0) {
        return false;
    }

    // 检查栏目是否存在
    $term = get_term($term_id, 'category');
    if (!$term) {
        return false;
    }

    // 检查模型是否存在
    $model_exists = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "models WHERE model_id = ?", [$model_id]);
    if (!$model_exists) {
        return false;
    }

    // 开始事务
    if (method_exists($tododb, 'begin_transaction')) {
        $tododb->begin_transaction();
    } elseif ($tododb instanceof PDO) {
        $tododb->beginTransaction();
    }

    try {
        // 删除现有关联
        todo_db_exec("DELETE FROM " . TABLE_PREFIX . "term_models WHERE term_id = ?", [$term_id]);

        // 创建新关联
        $result = todo_db_exec(
            "INSERT INTO " . TABLE_PREFIX . "term_models (term_id, model_id) VALUES (?, ?)",
            [$term_id, $model_id]
        );

        // 提交事务
        if (method_exists($tododb, 'commit')) {
            $tododb->commit();
        } elseif ($tododb instanceof PDO) {
            $tododb->commit();
        }

        // 清除缓存
        _todo_term_model_cache_manager('delete', $term_id);

        return $result;
    } catch (Exception $e) {
        // 回滚事务
        if (method_exists($tododb, 'rollback')) {
            $tododb->rollback();
        } elseif ($tododb instanceof PDO) {
            $tododb->rollBack();
        }
        error_log('设置栏目模型关联失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 删除栏目关联的模型
 *
 * @param int $term_id 栏目ID
 * @return bool 是否成功
 */
function delete_term_model($term_id)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return false;
    }

    // 删除关联
    $result = todo_db_exec("DELETE FROM " . TABLE_PREFIX . "term_models WHERE term_id = ?", [$term_id]);

    if ($result) {
        _todo_term_model_cache_manager('delete', $term_id);
    }

    return $result;
}

/**
 * 获取使用指定模型的所有栏目
 *
 * @param int $model_id 模型ID
 * @return array 栏目列表
 */
function get_terms_by_model($model_id)
{
    global $tododb;

    // 验证参数
    $model_id = intval($model_id);
    if ($model_id <= 0) {
        return [];
    }

    // 查询数据库获取使用指定模型的所有栏目
    $query = "SELECT t.*, tt.* FROM " . TABLE_PREFIX . "terms t "
        . "JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id "
        . "JOIN " . TABLE_PREFIX . "term_models tm ON t.term_id = tm.term_id "
        . "WHERE tm.model_id = ? AND tt.taxonomy = 'category'";

    $terms = todo_db_select($query, [$model_id]);

    return $terms;
}

/**
 * 获取所有可用的模型列表
 *
 * @param array $args 查询参数
 * @return array 模型列表
 */
function get_available_models($args = [])
{
    global $tododb;

    // 默认参数
    $defaults = [
        'status' => 'active',
        'orderby' => 'model_name',
        'order' => 'ASC'
    ];

    // 合并参数
    $args = array_merge($defaults, $args);

    // 构建查询
    $query = "SELECT * FROM " . TABLE_PREFIX . "models";
    $where = [];
    $params = [];

    // 处理状态
    if (!empty($args['status'])) {
        $where[] = "model_status = ?";
        $params[] = $args['status'];
    }

    // 添加WHERE子句
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }

    // 处理排序
    if (!empty($args['orderby'])) {
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
    }

    // 执行查询
    $models = todo_db_select($query, $params);

    return $models;
}

/**
 * 设置栏目字段可见性
 *
 * @param int $term_id 栏目ID
 * @param int $field_id 字段ID
 * @param bool $is_visible 是否可见
 * @return bool 是否成功
 */
function set_term_field_visibility($term_id, $field_id, $is_visible = true)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    $field_id = intval($field_id);
    $is_visible = (bool) $is_visible;

    if ($term_id <= 0 || $field_id <= 0) {
        return false;
    }

    // 检查栏目是否存在
    $term = get_term($term_id, 'category');
    if (!$term) {
        return false;
    }

    // 检查字段是否存在
    $field_exists = $tododb->get_var("SELECT COUNT(*) FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ?", [$field_id]);
    if (!$field_exists) {
        return false;
    }

    // 检查是否已存在记录
    $exists = $tododb->get_var(
        "SELECT visibility_id FROM " . TABLE_PREFIX . "term_field_visibility WHERE term_id = ? AND field_id = ?",
        [$term_id, $field_id]
    );

    if ($exists) {
        // 更新现有记录
        $result = todo_db_exec(
            "UPDATE " . TABLE_PREFIX . "term_field_visibility SET is_visible = ? WHERE term_id = ? AND field_id = ?",
            [$is_visible ? 1 : 0, $term_id, $field_id]
        );
    } else {
        // 创建新记录
        $result = todo_db_exec(
            "INSERT INTO " . TABLE_PREFIX . "term_field_visibility (term_id, field_id, is_visible) VALUES (?, ?, ?)",
            [$term_id, $field_id, $is_visible ? 1 : 0]
        );
    }

    return $result;
}

/**
 * 获取栏目字段可见性
 *
 * @param int $term_id 栏目ID
 * @param int $field_id 字段ID
 * @return bool 是否可见
 */
function get_term_field_visibility($term_id, $field_id)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    $field_id = intval($field_id);

    if ($term_id <= 0 || $field_id <= 0) {
        return true; // 默认可见
    }

    // 查询数据库获取字段可见性
    $is_visible = $tododb->get_var(
        "SELECT is_visible FROM " . TABLE_PREFIX . "term_field_visibility WHERE term_id = ? AND field_id = ?",
        [$term_id, $field_id]
    );

    // 如果没有记录，默认为可见
    if ($is_visible === null) {
        return true;
    }

    return (bool) $is_visible;
}

/**
 * 获取栏目所有字段的可见性
 *
 * @param int $term_id 栏目ID
 * @return array 字段可见性数组，键为字段ID，值为是否可见
 */
function get_term_fields_visibility($term_id)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return [];
    }

    // 查询数据库获取所有字段可见性
    $query = "SELECT field_id, is_visible FROM " . TABLE_PREFIX . "term_field_visibility WHERE term_id = ?";
    $results = todo_db_select($query, [$term_id]);

    $visibility = [];
    foreach ($results as $row) {
        $visibility[$row['field_id']] = (bool) $row['is_visible'];
    }

    return $visibility;
}

/**
 * 获取模型字段列表
 *
 * @param int $model_id 模型ID
 * @param bool $category_only 是否仅获取分类字段
 * @return array 字段列表
 */
function get_model_fields($model_id, $category_only = false)
{
    global $tododb;

    // 验证参数
    $model_id = intval($model_id);
    if ($model_id <= 0) {
        return [];
    }

    // 构建查询
    $query = "SELECT * FROM " . TABLE_PREFIX . "model_fields WHERE model_id = ? AND field_status = 'active'";
    $params = [$model_id];

    // 如果仅获取分类字段，添加条件
    if ($category_only) {
        $query .= " AND category_only = 1";
    }

    // 按排序字段排序
    $query .= " ORDER BY field_order ASC, field_id ASC";

    // 执行查询
    $fields = todo_db_select($query, $params);

    return $fields ? $fields : [];
}

/**
 * 根据模型获取标签
 *
 * @param string $model_key 模型标识
 * @return array 标签数组
 */
function get_tags_by_model($model_key)
{
    global $tododb;

    // 验证参数
    if (empty($model_key)) {
        return [];
    }

    // 获取模型信息
    $query = "SELECT model_id FROM " . TABLE_PREFIX . "models WHERE model_key = ? LIMIT 1";
    $model = todo_db_select($query, [$model_key]);

    if (empty($model)) {
        return [];
    }

    $model_id = $model[0]['model_id'];

    // 获取该模型关联的所有分类
    $query = "SELECT DISTINCT tm.term_id FROM " . TABLE_PREFIX . "term_models tm WHERE tm.model_id = ?";
    $terms = todo_db_select($query, [$model_id]);

    if (empty($terms)) {
        return [];
    }

    // 提取分类ID
    $term_ids = array_column($terms, 'term_id');
    $placeholders = str_repeat('?,', count($term_ids) - 1) . '?';

    // 获取这些分类下的所有标签
    $query = "SELECT DISTINCT t.term_id, t.name, t.slug 
              FROM " . TABLE_PREFIX . "terms t 
              INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
              WHERE tt.taxonomy = 'post_tag' AND tt.parent IN ($placeholders)
              ORDER BY t.name ASC";

    $tags = todo_db_select($query, $term_ids);

    return $tags ? $tags : [];
}

/**
 * 获取分类的可见字段
 *
 * @param int $term_id 分类ID
 * @param bool $category_only 是否仅获取分类字段
 * @return array 可见字段列表
 */
function get_term_visible_fields($term_id, $category_only = false)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return [];
    }

    // 获取分类关联的模型
    $model = get_term_model($term_id);
    if (!$model || !isset($model['model_id'])) {
        return [];
    }

    // 构建查询，联合模型字段表和字段可见性表
    $query = "SELECT mf.* FROM " . TABLE_PREFIX . "model_fields mf 
              INNER JOIN " . TABLE_PREFIX . "term_field_visibility tfv ON mf.field_id = tfv.field_id 
              WHERE mf.model_id = ? AND tfv.term_id = ? AND tfv.is_visible = 1 AND mf.field_status = 'active'";

    $params = [$model['model_id'], $term_id];

    // 如果仅获取分类字段，添加条件
    if ($category_only) {
        $query .= " AND mf.category_only = 1";
    }

    // 按排序字段排序
    $query .= " ORDER BY mf.field_order ASC, mf.field_id ASC";

    // 执行查询
    $fields = todo_db_select($query, $params);

    return $fields ? $fields : [];
}

/**
 * 获取分类的自定义属性
 *
 * @param int $term_id 分类ID
 * @param string $field_key 字段键名（可选，如果指定则只返回该字段的值）
 * @param bool $single 是否返回单个值（当指定field_key时有效）
 * @return mixed 属性数据或指定字段的值
 */
function get_term_attributes($term_id, $field_key = null, $single = false)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return $field_key ? ($single ? '' : null) : [];
    }

    // 检查缓存
    if (_todo_term_meta_cache_manager('has', $term_id)) {
        $attributes = _todo_term_meta_cache_manager('get', $term_id);
    } else {
        // 查询分类属性数据
        $query = "SELECT attributes FROM " . TABLE_PREFIX . "term_attributes WHERE term_id = ? LIMIT 1";
        $result = todo_db_select($query, [$term_id]);

        if (empty($result) || !isset($result[0]['attributes'])) {
            $attributes = [];
        } else {
            // 解析JSON属性数据
            $attributes = json_decode($result[0]['attributes'], true);
            if (!is_array($attributes)) {
                $attributes = [];
            }
        }

        // 设置缓存
        _todo_term_meta_cache_manager('set', $term_id, $attributes);
    }

    // 动态检查有效时间段，超出则视为隐藏（不影响底层持久化缓存）
    if (!empty($attributes['hidden_start']) || !empty($attributes['hidden_end'])) {
        $now = time();
        $start = !empty($attributes['hidden_start']) ? strtotime($attributes['hidden_start']) : 0;
        $end = !empty($attributes['hidden_end']) ? strtotime($attributes['hidden_end']) : PHP_INT_MAX;
        if (($start > 0 || $end < PHP_INT_MAX) && ($now < $start || $now > $end)) {
            $attributes['is_hidden'] = 1;
        }
    }

    // 如果指定了字段键名，返回该字段的值
    if ($field_key !== null) {
        if (isset($attributes[$field_key])) {
            return $single ? $attributes[$field_key] : $attributes[$field_key];
        }
        return $single ? '' : null;
    }

    // 返回所有属性
    return $attributes;
}

/**
 * 更新分类的自定义属性
 *
 * @param int $term_id 分类ID
 * @param string $field_key 字段键名
 * @param mixed $value 字段值
 * @return bool 是否更新成功
 */
function update_term_attributes($term_id, $field_key, $value)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0 || empty($field_key)) {
        return false;
    }

    // 获取现有属性
    $existing_attributes = get_term_attributes($term_id);
    if (!is_array($existing_attributes)) {
        $existing_attributes = [];
    }

    // 更新属性值
    $existing_attributes[$field_key] = $value;

    // 转换为JSON
    $attributes_json = json_encode($existing_attributes, JSON_UNESCAPED_UNICODE);

    // 使用 INSERT ... ON DUPLICATE KEY UPDATE 来避免竞态条件
    // 由于 term_id 有唯一索引，如果存在则更新，不存在则插入
    $result = todo_db_exec(
        "INSERT INTO " . TABLE_PREFIX . "term_attributes (term_id, attributes) 
         VALUES (?, ?) 
         ON DUPLICATE KEY UPDATE attributes = VALUES(attributes)",
        [$term_id, $attributes_json]
    );

    return $result !== false;
}

/**
 * 批量预加载分类元数据缓存
 *
 * @param array|int $term_ids 分类ID或ID数组
 * @return array 成功加载的元数据
 */
function update_term_meta_cache($term_ids)
{
    global $tododb;

    if (empty($term_ids)) {
        return [];
    }

    if (!is_array($term_ids)) {
        $term_ids = preg_split('/[\s,]+/', $term_ids, -1, PREG_SPLIT_NO_EMPTY);
    }

    $term_ids = array_map('intval', $term_ids);
    $term_ids = array_unique(array_filter($term_ids));

    if (empty($term_ids)) {
        return [];
    }

    // 检查哪些尚未缓存
    $ids_to_query = [];
    foreach ($term_ids as $id) {
        if (!_todo_term_meta_cache_manager('has', $id)) {
            $ids_to_query[] = $id;
        }
    }

    if (empty($ids_to_query)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids_to_query), '?'));
    $query = "SELECT term_id, attributes FROM " . TABLE_PREFIX . "term_attributes WHERE term_id IN ($placeholders)";

    $results = todo_db_select($query, $ids_to_query);

    $loaded = [];
    // 初始化这些ID的缓存为空数组（防止查询不到时重复查询）
    foreach ($ids_to_query as $id) {
        _todo_term_meta_cache_manager('set', $id, []);
    }

    foreach ($results as $row) {
        $attributes = json_decode($row['attributes'], true);
        if (!is_array($attributes)) {
            $attributes = [];
        }
        _todo_term_meta_cache_manager('set', $row['term_id'], $attributes);
        $loaded[$row['term_id']] = $attributes;
    }

    return $loaded;
}

/**
 * 内部函数：管理分类元数据缓存
 *
 * @param string $action 操作类型 (get, set, delete, has, clear)
 * @param int $term_id 分类ID
 * @param mixed $data 数据
 * @return mixed
 */
function _todo_term_meta_cache_manager($action, $term_id = null, $data = null)
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        global $tododb;
        if ($tododb && method_exists($tododb, 'table_exists') && $tododb->table_exists('term_attributes')) {
            $table = (defined('TABLE_PREFIX') ? TABLE_PREFIX : $tododb->prefix) . 'term_attributes';
            $rows = $tododb->get_results("SELECT term_id, attributes FROM {$table}");
            if ($rows && is_array($rows)) {
                foreach ($rows as $r) {
                    $attrs = json_decode($r->attributes, true);
                    $cache[(int)$r->term_id] = is_array($attrs) ? $attrs : [];
                }
            }
        }
    }

    switch ($action) {
        case 'get':
            return isset($cache[$term_id]) ? $cache[$term_id] : false;
        case 'set':
            $cache[$term_id] = $data;
            break;
        case 'delete':
            unset($cache[$term_id]);
            break;
        case 'has':
            return isset($cache[$term_id]);
        case 'clear':
            $cache = [];
            break;
    }
}

/**
 * 更新分类元数据
 *
 * @param int $term_id 分类ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值
 * @return bool 是否更新成功
 */
function update_term_meta($term_id, $meta_key, $meta_value)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0 || empty($meta_key)) {
        return false;
    }

    // 检查分类是否存在
    $term_exists = $tododb->get_var(
        "SELECT COUNT(*) FROM " . TABLE_PREFIX . "terms WHERE term_id = ?",
        [$term_id]
    );

    if (!$term_exists) {
        return false;
    }

    try {
        // 尝试从缓存获取，如果没缓存则从数据库获取
        if (_todo_term_meta_cache_manager('has', $term_id)) {
            $attributes = _todo_term_meta_cache_manager('get', $term_id);
        } else {
            // 获取现有的 attributes JSON 数据
            $current_attributes = $tododb->get_var(
                "SELECT attributes FROM " . TABLE_PREFIX . "term_attributes WHERE term_id = ? LIMIT 1",
                [$term_id]
            );

            // 解析现有属性
            $attributes = [];
            if ($current_attributes) {
                $attributes = json_decode($current_attributes, true);
                if (!is_array($attributes)) {
                    $attributes = [];
                }
            }
        }

        // 更新属性值
        $attributes[$meta_key] = $meta_value;

        // 更新缓存
        _todo_term_meta_cache_manager('set', $term_id, $attributes);

        // 转换为 JSON
        $json_attributes = json_encode($attributes, JSON_UNESCAPED_UNICODE);

        // 使用 INSERT ... ON DUPLICATE KEY UPDATE 来避免竞态条件
        // 由于 term_id 有唯一索引，如果存在则更新，不存在则插入
        $result = todo_db_exec(
            "INSERT INTO " . TABLE_PREFIX . "term_attributes (term_id, attributes) 
             VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE attributes = VALUES(attributes)",
            [$term_id, $json_attributes]
        );

        return $result !== false;

    } catch (Exception $e) {
        error_log('update_term_meta error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 获取分类元数据
 *
 * @param int $term_id 分类ID
 * @param string $meta_key 元数据键名（可选）
 * @param bool $single 是否返回单个值
 * @return mixed 元数据值
 */
function get_term_meta($term_id, $meta_key = '', $single = false)
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0) {
        return $single ? '' : [];
    }

    try {
        $attributes = [];

        // 检查缓存
        if (_todo_term_meta_cache_manager('has', $term_id)) {
            $attributes = _todo_term_meta_cache_manager('get', $term_id);
        } else {
            // 获取 attributes JSON 数据
            $attributes_json = $tododb->get_var(
                "SELECT attributes FROM " . TABLE_PREFIX . "term_attributes WHERE term_id = ? LIMIT 1",
                [$term_id]
            );

            if ($attributes_json) {
                // 解析 JSON 数据
                $attributes = json_decode($attributes_json, true);
                if (!is_array($attributes)) {
                    $attributes = [];
                }
            }

            // 设置缓存
            _todo_term_meta_cache_manager('set', $term_id, $attributes);
        }

        // 动态检查有效时间段，超出则视为隐藏（不影响底层持久化缓存）
        if (!empty($attributes['hidden_start']) || !empty($attributes['hidden_end'])) {
            $now = time();
            $start = !empty($attributes['hidden_start']) ? strtotime($attributes['hidden_start']) : 0;
            $end = !empty($attributes['hidden_end']) ? strtotime($attributes['hidden_end']) : PHP_INT_MAX;
            if (($start > 0 || $end < PHP_INT_MAX) && ($now < $start || $now > $end)) {
                $attributes['is_hidden'] = 1;
            }
        }

        if (empty($meta_key)) {
            // 返回所有属性
            return $attributes;
        } else {
            // 返回指定键的属性
            if ($single) {
                return isset($attributes[$meta_key]) ? $attributes[$meta_key] : '';
            } else {
                return isset($attributes[$meta_key]) ? [$attributes[$meta_key]] : [];
            }
        }

    } catch (Exception $e) {
        error_log('get_term_meta error: ' . $e->getMessage());
        return $single ? '' : [];
    }
}

/**
 * 删除分类元数据
 *
 * @param int $term_id 分类ID
 * @param string $meta_key 元数据键名
 * @param mixed $meta_value 元数据值（可选，用于删除特定值）
 * @return bool 是否删除成功
 */
function delete_term_meta($term_id, $meta_key, $meta_value = '')
{
    global $tododb;

    // 验证参数
    $term_id = intval($term_id);
    if ($term_id <= 0 || empty($meta_key)) {
        return false;
    }

    try {
        // 尝试从缓存获取
        if (_todo_term_meta_cache_manager('has', $term_id)) {
            $attributes = _todo_term_meta_cache_manager('get', $term_id);
        } else {
            // 获取现有的 attributes JSON 数据
            $current_attributes = $tododb->get_var(
                "SELECT attributes FROM " . TABLE_PREFIX . "term_attributes WHERE term_id = ? LIMIT 1",
                [$term_id]
            );

            if (!$current_attributes) {
                return false; // 没有找到记录
            }

            // 解析现有属性
            $attributes = json_decode($current_attributes, true);
            if (!is_array($attributes)) {
                return false;
            }
        }

        // 检查键是否存在
        if (!isset($attributes[$meta_key])) {
            return false; // 键不存在
        }

        // 如果指定了值，检查值是否匹配
        if ($meta_value !== '' && $attributes[$meta_key] !== $meta_value) {
            return false; // 值不匹配
        }

        // 删除指定的键
        unset($attributes[$meta_key]);

        // 更新缓存
        _todo_term_meta_cache_manager('set', $term_id, $attributes);

        // 如果属性为空，删除整个记录
        if (empty($attributes)) {
            $result = todo_db_exec(
                "DELETE FROM " . TABLE_PREFIX . "term_attributes WHERE term_id = ?",
                [$term_id]
            );
        } else {
            // 更新 JSON 数据
            $json_attributes = json_encode($attributes, JSON_UNESCAPED_UNICODE);
            $result = todo_db_exec(
                "UPDATE " . TABLE_PREFIX . "term_attributes SET attributes = ? WHERE term_id = ?",
                [$json_attributes, $term_id]
            );
        }

        return $result !== false;

    } catch (Exception $e) {
        error_log('delete_term_meta error: ' . $e->getMessage());
        return false;
    }
}

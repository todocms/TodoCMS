<?php
/**
 * 文档属性相关函数
 *
 * @package TodoCMS
 * @subpackage Administration
 */

// 防止直接访问（但允许AJAX请求）
if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied.');
}

if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION') && !defined('DOING_AJAX')) {
    exit('Direct access not permitted');
}

// 定义数据库查询常量
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * 获取所有文档属性
 */
function get_doc_properties()
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    // 如果表不存在，说明系统未正确安装
    if (!$tododb->get_var("SHOW TABLES LIKE '$table_name'")) {
        error_log('文档属性表不存在，请重新安装系统');
        return array();
    }

    $results = $tododb->get_results(
        "SELECT * FROM $table_name ORDER BY sort_order ASC, id ASC",
        [],
        'ARRAY_A'
    );

    // 确保所有元素都是数组格式（兼容对象返回）
    if ($results && is_array($results)) {
        $formatted_results = [];
        foreach ($results as $row) {
            if (is_object($row)) {
                $formatted_results[] = (array) $row;
            } elseif (is_array($row)) {
                $formatted_results[] = $row;
            }
        }
        return $formatted_results;
    }

    return $results ? $results : array();
}

/**
 * 获取文章已关联的文档属性
 * @param int $post_id 文章ID
 * @return array 文档属性ID数组
 */
function get_post_doc_properties($post_id)
{
    global $tododb;
    
    // 从 posts 表的 doc_properties 字段读取数据
    $post = $tododb->get_row(
        "SELECT doc_properties FROM {$tododb->prefix}posts WHERE ID = ?",
        [intval($post_id)],
        ARRAY_A
    );
    
    if (!$post || empty($post['doc_properties'])) {
        return array();
    }
    
    // doc_properties 字段存储的是逗号分隔的ID字符串，需要转换为数组
    $properties_string = trim($post['doc_properties']);
    if (empty($properties_string)) {
        return array();
    }
    
    // 将逗号分隔的字符串转换为数组，并过滤空值
    $properties = array_filter(
        array_map('intval', explode(',', $properties_string)),
        function($id) {
            return $id > 0;
        }
    );
    
    return array_values($properties);
}

/**
 * 获取单个文档属性
 */
function get_doc_property($id)
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    $result = $tododb->get_results(
        "SELECT * FROM $table_name WHERE id = ?",
        [intval($id)],
        ARRAY_A
    );

    return $result ? $result[0] : null;
}

/**
 * 获取前台显示的文档属性
 */
function get_display_doc_properties()
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    // 如果表不存在，说明系统未正确安装
    if (!$tododb->get_var("SHOW TABLES LIKE '$table_name'")) {
        error_log('文档属性表不存在，请重新安装系统');
        return array();
    }

    $results = $tododb->get_results(
        "SELECT * FROM $table_name WHERE is_active = 1 ORDER BY sort_order ASC, id ASC",
        ARRAY_A
    );

    if (!$results) {
        return array();
    }

    // 确保所有元素都是数组格式（兼容对象返回）
    $formatted_results = [];
    foreach ($results as $row) {
        if (is_object($row)) {
            $formatted_results[] = (array) $row;
        } elseif (is_array($row)) {
            $formatted_results[] = $row;
        }
    }

    return $formatted_results;
}

/**
 * 添加文档属性
 */
function add_doc_property($name, $value, $display = 1, $sort_order = 0)
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    // 检查属性值是否已存在
    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE value = ?",
        [$value]
    );

    if ($exists > 0) {
        return false;
    }

    $result = $tododb->insert(
        $table_name,
        array(
            'name' => $name,
            'display_name' => $name,
            'value' => $value,
            'is_active' => $display,
            'sort_order' => $sort_order,
            'created_at' => current_time('mysql')
        )
    );

    return $result !== false;
}

/**
 * 更新文档属性
 */
function update_doc_property($id, $name, $value, $display = 1, $sort_order = 0)
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    // 检查属性值是否已被其他记录使用
    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE value = ? AND id != ?",
        [$value, intval($id)]
    );

    if ($exists > 0) {
        return false;
    }

    $sql = "UPDATE $table_name SET name = ?, display_name = ?, value = ?, is_active = ?, sort_order = ?, updated_at = ? WHERE id = ?";
    $params = [$name, $name, $value, intval($display), intval($sort_order), current_time('mysql'), intval($id)];
    if ($tododb instanceof PDO) {
        $stmt = $tododb->prepare($sql);
        $stmt->execute($params);
        $result = (int) $stmt->rowCount();
        try {
            $stmt->closeCursor();
        } catch (Throwable $e) {
        }
    } else {
        $result = $tododb->query($sql, $params);
    }

    return $result !== false;
}

/**
 * 删除文档属性
 */
function delete_doc_property($id)
{
    global $tododb;

    $table_name = $tododb->prefix . 'doc_properties';

    $sql = "DELETE FROM $table_name WHERE id = ?";
    $params = [intval($id)];
    if ($tododb instanceof PDO) {
        $stmt = $tododb->prepare($sql);
        $stmt->execute($params);
        $result = (int) $stmt->rowCount();
        try {
            $stmt->closeCursor();
        } catch (Throwable $e) {
        }
    } else {
        $result = $tododb->query($sql, $params);
    }

    return $result !== false;
}

/**
 * 检查文档属性值是否存在
 * 
 * @param string $value 属性值
 * @param int $exclude_id 排除的ID
 * @return bool 是否存在
 */
function doc_property_exists($value, $exclude_id = null) {
    global $tododb;
    $table_name = $tododb->prefix . 'doc_properties';
    
    $sql = "SELECT COUNT(*) FROM $table_name WHERE value = ?";
    $params = [$value];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = intval($exclude_id);
    }
    
    return intval($tododb->get_var($sql, $params)) > 0;
}

/**
 * 获取各文档属性关联的文章数量统计
 * @return array 以 属性ID 为键，文章数量 为值的数组
 */
function get_doc_properties_post_counts()
{
    global $tododb;
    $counts = array();
    $results = $tododb->get_results(
        "SELECT doc_properties FROM {$tododb->prefix}posts WHERE doc_properties IS NOT NULL AND doc_properties != ''",
        [],
        'ARRAY_A'
    );
    if ($results && is_array($results)) {
        foreach ($results as $row) {
            $raw = is_array($row) ? ($row['doc_properties'] ?? '') : (is_object($row) ? ($row->doc_properties ?? '') : '');
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            $ids = array_filter(array_map('intval', explode(',', $raw)), function($id) { return $id > 0; });
            foreach (array_unique($ids) as $pid) {
                if (!isset($counts[$pid])) {
                    $counts[$pid] = 0;
                }
                $counts[$pid]++;
            }
        }
    }
    return $counts;
}

?>
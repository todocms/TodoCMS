<?php
/**
 * 模型字段方法扩展
 *
 * 提供对模型字段的获取、更新和删除操作
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 为CMS_Model类添加字段相关方法
 */
class CMS_Model_Field_Methods {
    
    /**
     * 获取单个字段信息
     *
     * @param int $field_id 字段ID
     * @return array|false 字段信息数组或失败返回false
     */
    public static function get_field($model, $field_id) {
        $model_data = $model->get_data();
        $model_id = isset($model_data['model_id']) ? (int)$model_data['model_id'] : 0;
        
        if (!$model_id) {
            return false;
        }
        
        global $tododb;
        
        $query = "SELECT * FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ? AND model_id = ? LIMIT 1";
        $result = $tododb->get_results($query, [$field_id, $model_id]);
        
        return $result && !empty($result) ? (array)$result[0] : false;
    }
    
    /**
     * 更新字段信息
     *
     * @param int $field_id 字段ID
     * @param array $field_data 字段数据
     * @return bool 是否成功更新
     */
    public static function update_field($model, $field_id, $field_data) {
        $model_data = $model->get_data();
        $model_id = isset($model_data['model_id']) ? (int)$model_data['model_id'] : 0;
        
        if (!$model_id) {
            return false;
        }
        
        global $tododb;
        
        // 检查字段是否存在
        $existing_field = self::get_field($model, $field_id);
        if (!$existing_field) {
            return false;
        }
        
        try {
            // 开始事务
            $tododb->begin_transaction();
            
            // 更新字段数据
            $update_sql =
                "UPDATE " . TABLE_PREFIX . "model_fields 
                 SET field_name = ?, field_key = ?, field_type = ?, field_label = ?, 
                     field_description = ?, field_default = ?, field_options = ?, is_required = ?, category_only = ? 
                 WHERE field_id = ? AND model_id = ?";
            $update_params = [
                $field_data['field_name'],
                $field_data['field_key'],
                $field_data['field_type'],
                $field_data['field_label'] ?? $field_data['field_name'],
                $field_data['field_description'] ?? '',
                $field_data['field_default'] ?? '',
                $field_data['field_options'] ?? '',
                $field_data['is_required'] ?? 0,
                $field_data['category_only'] ?? 0,
                $field_id,
                $model_id
            ];

            $result = $tododb->query($update_sql, $update_params);
            
            // 只要不是false，就是执行成功（包括0行受影响）
            if ($result === false) {
                $tododb->rollback();
                return false;
            }
            
            // 处理字段分类绑定
            if (isset($field_data['field_categories'])) {
                // 先删除现有的分类绑定
                $delete_sql = "DELETE FROM " . TABLE_PREFIX . "term_field_visibility WHERE field_id = ?";
                $delete_params = [$field_id];
                
                $delete_result = $tododb->query($delete_sql, $delete_params);
                
                if ($delete_result === false) {
                    $tododb->rollback();
                    return false;
                }
                
                // 添加新的分类绑定 - 批量插入优化
                $field_categories = $field_data['field_categories'];
                if (!empty($field_categories) && is_array($field_categories)) {
                    // 确保没有重复值
                    $field_categories = array_unique($field_categories);
                    
                    $insert_values = [];
                    $insert_params = [];
                    
                    foreach ($field_categories as $term_id) {
                        $insert_values[] = "(?, ?, 1)";
                        $insert_params[] = (int)$term_id;
                        $insert_params[] = $field_id;
                    }
                    
                    if (!empty($insert_values)) {
                        $insert_sql = "INSERT INTO " . TABLE_PREFIX . "term_field_visibility (term_id, field_id, is_visible) VALUES " . implode(',', $insert_values);
                        
                        $insert_result = $tododb->query($insert_sql, $insert_params);
                        
                        if ($insert_result === false) {
                            $tododb->rollback();
                            return false;
                        }
                    }
                }
            }
            
            // 提交事务
            $tododb->commit();
            
            // 重新加载字段
            $model->load_fields();
            
            return true;
        } catch (Exception $e) {
            // 回滚事务
            $tododb->rollback();
            error_log('更新字段失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 将字段移至回收站
     *
     * @param CMS_Model $model 模型对象
     * @param int $field_id 字段ID
     * @return bool 是否成功移至回收站
     */
    public static function trash_field($model, $field_id) {
        $model_data = $model->get_data();
        $model_id = isset($model_data['model_id']) ? (int)$model_data['model_id'] : 0;
        
        if (!$model_id) {
            return false;
        }
        
        global $tododb;
        
        // 检查字段是否存在
        $existing_field = self::get_field($model, $field_id);
        if (!$existing_field) {
            return false;
        }
        
        // 检查是否为系统字段
        if (isset($existing_field['is_system']) && $existing_field['is_system']) {
            return false;
        }
        
        $sql = "UPDATE " . TABLE_PREFIX . "model_fields SET field_status = 'trash' WHERE field_id = ? AND model_id = ?";
        $result = $tododb->query($sql, [$field_id, $model_id]);
        
        if ($result !== false) {
            $model->load_fields();
            return true;
        }
        
        return false;
    }
    
    /**
     * 从回收站还原字段
     *
     * @param CMS_Model $model 模型对象
     * @param int $field_id 字段ID
     * @return bool 是否成功还原
     */
    public static function untrash_field($model, $field_id) {
        $model_data = $model->get_data();
        $model_id = isset($model_data['model_id']) ? (int)$model_data['model_id'] : 0;
        
        if (!$model_id) {
            return false;
        }
        
        global $tododb;
        
        $sql = "UPDATE " . TABLE_PREFIX . "model_fields SET field_status = 'active' WHERE field_id = ? AND model_id = ?";
        $result = $tododb->query($sql, [$field_id, $model_id]);
        
        if ($result !== false) {
            $model->load_fields();
            return true;
        }
        
        return false;
    }
    
    /**
     * 永久删除字段
     *
     * @param int $field_id 字段ID
     * @return bool 是否成功删除
     */
    public static function delete_field($model, $field_id) {
        $model_data = $model->get_data();
        $model_id = isset($model_data['model_id']) ? (int)$model_data['model_id'] : 0;
        
        if (!$model_id) {
            return false;
        }
        
        global $tododb;
        
        // 检查字段是否存在
        $existing_field = self::get_field($model, $field_id);
        if (!$existing_field) {
            return false;
        }
        
        // 检查是否为系统字段
        if (isset($existing_field['is_system']) && $existing_field['is_system']) {
            return false;
        }
        
        try {
            // 开始事务
            if ($tododb->begin_transaction() === false) { return false; }
            
            // 获取字段的field_key，用于删除term_attributes中的相关数据
            $field_key = $existing_field['field_key'] ?? '';
            
            // 删除字段与分类的关联
            $del_vis_sql = "DELETE FROM " . TABLE_PREFIX . "term_field_visibility WHERE field_id = ?";
            $tododb->query($del_vis_sql, [$field_id]);
            
            // 删除文章自定义字段中所有使用该字段key的数据
            if (!empty($field_key)) {
                $del_post_fields_sql = "DELETE FROM " . TABLE_PREFIX . "post_custom_fields WHERE field_key = ? AND model_id = ?";
                $tododb->query($del_post_fields_sql, [$field_key, $model_id]);
            }
            
            // 删除term_attributes中所有使用该字段key的数据
            if (!empty($field_key)) {
                // 从JSON属性中删除该字段
                // 使用 JSON 路径的 . 语法配合引号，确保兼容性
                // 括号语法 $[...] 在 MySQL 中通常仅用于数组索引
                $escaped_key = str_replace(['\\', '"'], ['\\\\', '\\"'], $field_key);
                $json_path = '$."' . $escaped_key . '"';
                $terms_with_field = $tododb->get_results(
                    "SELECT term_id, attributes FROM " . TABLE_PREFIX . "term_attributes WHERE JSON_EXTRACT(attributes, ?) IS NOT NULL",
                    [$json_path]
                );
                
                if ($terms_with_field) {
                    foreach ($terms_with_field as $term) {
                        $attributes = json_decode($term->attributes, true) ?: array();
                        unset($attributes[$field_key]);
                        $upd_sql = "UPDATE " . TABLE_PREFIX . "term_attributes SET attributes = ? WHERE term_id = ?";
                        $upd_params = [json_encode($attributes, JSON_UNESCAPED_UNICODE), $term->term_id];
                        $tododb->query($upd_sql, $upd_params);
                    }
                }
            }
            
            // 删除字段
            $del_field_sql = "DELETE FROM " . TABLE_PREFIX . "model_fields WHERE field_id = ? AND model_id = ?";
            $affected = $tododb->query($del_field_sql, [$field_id, $model_id]);

            if ($affected === false || $affected === 0) {
                throw new Exception('删除字段失败');
            }
            
            // 提交事务
            if ($tododb->commit() === false) {
                throw new Exception('提交事务失败');
            }
            
            // 重新加载字段
            $model->load_fields();
            
            return true;
        } catch (Exception $e) {
            error_log('删除字段失败: ' . $e->getMessage());
            $tododb->rollback();
            return false;
        }
    }
}
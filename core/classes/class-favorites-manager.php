<?php
/**
 * 收藏夹管理器
 * 处理用户收藏夹相关功能
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Favorites_Manager {
    
    /**
     * 添加到收藏夹
     */
    public static function add_to_favorites($product_id) {
        $user_id = todo_get_current_user_id();
        if (!$user_id) {
            return false;
        }
        
        try {
            $pdo = get_database_connection();
            
            global $tododb;
            $table_name = $tododb->prefix . 'member_favorites';
            
            // 检查是否已收藏
            $exists = $pdo->get_var(
                "SELECT id FROM {$table_name} WHERE user_id = ? AND object_id = ? AND object_type = 'product'",
                [$user_id, $product_id]
            );
            
            if ($exists) {
                return true; // 已经收藏了
            }
            
            // 添加收藏
            $result = $pdo->query(
                "INSERT INTO {$table_name} (user_id, object_id, object_type, created_at) VALUES (?, ?, 'product', NOW())",
                [$user_id, $product_id]
            );
            
            return $result !== false;
        } catch (Exception $e) {
            error_log('Add favorite failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从收藏夹移除
     */
    public static function remove_from_favorites($product_id) {
        $user_id = todo_get_current_user_id();
        if (!$user_id) {
            return false;
        }
        
        global $tododb;
        $table_name = $tododb->prefix . 'member_favorites';
        
        // 使用 query 方法删除，因为 delete 方法不支持多条件
        $result = $tododb->query(
            "DELETE FROM {$table_name} WHERE user_id = ? AND object_id = ? AND object_type = 'product'",
            [$user_id, $product_id]
        );
        
        return $result !== false;
    }
    
    /**
     * 检查是否已收藏
     */
    public static function is_favorited($product_id) {
        $user_id = todo_get_current_user_id();
        if (!$user_id) {
            return false;
        }
        
        try {
            $pdo = get_database_connection();
            global $tododb;
            $table_name = $tododb->prefix . 'member_favorites';
            
            $exists = $pdo->get_var(
                "SELECT id FROM {$table_name} WHERE user_id = ? AND object_id = ? AND object_type = 'product'",
                [$user_id, $product_id]
            );
            
            return !empty($exists);
        } catch (Exception $e) {
            error_log('Check favorite failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户收藏列表
     */
    public static function get_user_favorites($user_id = null, $limit = 20, $offset = 0) {
        if (!$user_id) {
            $user_id = todo_get_current_user_id();
        }
        
        if (!$user_id) {
            return [];
        }
        
        try {
            $pdo = get_database_connection();
            global $tododb;
            $table_name = $tododb->prefix . 'member_favorites';
            $posts_table = $tododb->posts;
            
            $favorites = $pdo->get_results(
                "SELECT f.*, p.post_title as title, p.ID as product_id
                 FROM {$table_name} f 
                 LEFT JOIN {$posts_table} p ON f.object_id = p.ID 
                 WHERE f.user_id = ? AND f.object_type = 'product'
                 ORDER BY f.created_at DESC 
                 LIMIT ? OFFSET ?",
                [$user_id, $limit, $offset]
            );
            
            return $favorites ?: [];
        } catch (Exception $e) {
            error_log('Get user favorites failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取收藏数量
     */
    public static function get_favorites_count($user_id = null) {
        if (!$user_id) {
            $user_id = todo_get_current_user_id();
        }
        
        if (!$user_id) {
            return 0;
        }
        
        try {
            $pdo = get_database_connection();
            global $tododb;
            $table_name = $tododb->prefix . 'member_favorites';
            
            $count = $pdo->get_var(
                "SELECT COUNT(*) FROM {$table_name} WHERE user_id = ? AND object_type = 'product'",
                [$user_id]
            );
            
            return intval($count);
        } catch (Exception $e) {
            error_log('Get favorites count failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 清空收藏夹
     */
    public static function clear_favorites($user_id = null) {
        if (!$user_id) {
            $user_id = todo_get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        global $tododb;
        $table_name = $tododb->prefix . 'member_favorites';
        
        $result = $tododb->query(
            "DELETE FROM {$table_name} WHERE user_id = ? AND object_type = 'product'",
            [$user_id]
        );
        
        return $result !== false;
    }
}

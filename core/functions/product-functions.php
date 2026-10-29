<?php
/**
 * 产品相关功能函数
 * Product Functions for TodoCMS
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 获取产品信息
 * 
 * @param int $product_id 产品ID
 * @return array|false 产品信息数组或false
 */
function get_product($product_id) {
    global $tododb;
    
    if (!$product_id) {
        return false;
    }
    
    $sql = "SELECT p.*, pm.* FROM posts p 
            LEFT JOIN post_custom_fields pm ON p.ID = pm.post_id 
            WHERE p.ID = ? AND p.post_type = 'product'";
    
    $result = $tododb->get_row($sql, [$product_id]);
    
    if ($result) {
        // 获取产品元数据
        $meta_sql = "SELECT field_key, field_value FROM post_custom_fields WHERE post_id = ?";
        $meta_results = $tododb->get_results($meta_sql, [$product_id]);
        
        $product_meta = [];
        if ($meta_results) {
            foreach ($meta_results as $meta) {
                $product_meta[$meta->field_key] = $meta->field_value;
            }
        }
        
        $result->meta = $product_meta;
        return $result;
    }
    
    return false;
}

/**
 * 获取产品列表
 * 
 * @param array $args 查询参数
 * @return array 产品列表
 */
function get_products($args = []) {
    global $tododb;
    
    $defaults = [
        'limit' => 10,
        'offset' => 0,
        'category' => '',
        'status' => 'publish',
        'orderby' => 'post_date',
        'order' => 'DESC'
    ];
    
    $args = array_merge($defaults, $args);
    
    $where_conditions = ["p.post_type = 'product'"];
    $params = [];
    
    if ($args['status']) {
        $where_conditions[] = "p.post_status = ?";
        $params[] = $args['status'];
    }
    
    if ($args['category']) {
        $where_conditions[] = "EXISTS (SELECT 1 FROM term_relationships tr 
                                      JOIN term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                      JOIN terms t ON tt.term_id = t.term_id 
                                      WHERE tr.object_id = p.ID AND t.slug = ?)";
        $params[] = $args['category'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 验证ORDER BY字段名
    $allowed_orderby = ['ID', 'post_title', 'post_date', 'post_modified', 'menu_order'];
    $orderby = isset($args['orderby']) && in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'post_date';
    
    // 验证ORDER方向
    $order = isset($args['order']) ? strtoupper($args['order']) : 'DESC';
    $order = ($order === 'ASC') ? 'ASC' : 'DESC';
    
    // LIMIT和OFFSET使用占位符
    $limit = isset($args['limit']) ? intval($args['limit']) : 10;
    $offset = isset($args['offset']) ? intval($args['offset']) : 0;
    
    $sql = "SELECT p.* FROM posts p 
            WHERE {$where_clause} 
            ORDER BY p.{$orderby} {$order} 
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    return $tododb->get_results($sql, $params);
}

/**
 * 获取产品价格
 * 
 * @param int $product_id 产品ID
 * @return array 价格信息
 */
function get_product_price($product_id) {
    global $tododb;
    
    $pcf = $tododb->prefix . 'post_custom_fields';
    
    $sql = "SELECT field_value FROM {$pcf} WHERE post_id = ? AND field_key = ? LIMIT 1";
    $curr_code = function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '';
    
    $price = $tododb->get_var($sql, [$product_id, 'price']);
    if ($price !== null && floatval($price) > 0) {
        $num = floatval($price);
        return [
            'regular_price' => $num,
            'sale_price' => 0,
            'currency' => $curr_code
        ];
    }
    
    $sql2 = "SELECT field_value FROM {$pcf} WHERE post_id = ? AND field_key = ? LIMIT 1";
    $price_json = $tododb->get_var($sql2, [$product_id, 'product_price']);
    if ($price_json) {
        $decoded = json_decode($price_json, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    
    $sale = $tododb->get_var($sql, [$product_id, '_sale_price']);
    $regular = $tododb->get_var($sql, [$product_id, '_regular_price']);
    $sale_num = ($sale !== null) ? floatval($sale) : 0;
    $regular_num = ($regular !== null) ? floatval($regular) : 0;
    if ($sale_num > 0 || $regular_num > 0) {
        return [
            'regular_price' => $regular_num,
            'sale_price' => $sale_num,
            'currency' => $curr_code
        ];
    }
    
    return [
        'regular_price' => 0,
        'sale_price' => 0,
        'currency' => $curr_code
    ];
}

/**
 * 更新产品价格
 * 
 * @param int $product_id 产品ID
 * @param array $price_data 价格数据
 * @return bool 是否成功
 */
function update_product_price($product_id, $price_data) {
    global $tododb;
    
    $price_json = json_encode($price_data);
    
    // 检查是否已存在
    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM post_custom_fields WHERE post_id = ? AND field_key = 'product_price'",
        [$product_id]
    );
    
    if ($exists) {
        $sql = "UPDATE post_custom_fields SET field_value = ? 
                WHERE post_id = ? AND field_key = 'product_price'";
        return $tododb->query($sql, [$price_json, $product_id]);
    } else {
        $sql = "INSERT INTO post_custom_fields (post_id, field_key, field_value) 
                VALUES (?, 'product_price', ?)";
        return $tododb->query($sql, [$product_id, $price_json]);
    }
}

/**
 * 获取产品库存
 * 
 * @param int $product_id 产品ID
 * @return int 库存数量
 */
function get_product_stock($product_id) {
    global $tododb;
    
    $sql = "SELECT field_value FROM post_custom_fields 
            WHERE post_id = ? AND field_key = 'product_stock'";
    
    $stock = $tododb->get_var($sql, [$product_id]);
    
    return $stock ? (int)$stock : 0;
}

/**
 * 更新产品库存
 * 
 * @param int $product_id 产品ID
 * @param int $stock 库存数量
 * @return bool 是否成功
 */
function update_product_stock($product_id, $stock) {
    global $tododb;
    
    // 检查是否已存在
    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM post_custom_fields WHERE post_id = ? AND field_key = 'product_stock'",
        [$product_id]
    );
    
    if ($exists) {
        $sql = "UPDATE post_custom_fields SET field_value = ? 
                WHERE post_id = ? AND field_key = 'product_stock'";
        return $tododb->query($sql, [$stock, $product_id]);
    } else {
        $sql = "INSERT INTO post_custom_fields (post_id, field_key, field_value) 
                VALUES (?, 'product_stock', ?)";
        return $tododb->query($sql, [$product_id, $stock]);
    }
}

/**
 * 获取产品销售状态（online 或 warehouse）
 * 
 * @param int $product_id 产品ID
 * @return string 'online'|'warehouse'
 */
function todo_get_product_sale_status($product_id) {
    global $tododb;
    $product_id = intval($product_id);
    if ($product_id <= 0) {
        return 'warehouse';
    }

    // 优先从 model_fields_product 表查询
    if (isset($tododb)) {
        $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
        $sale_status = $tododb->get_var("SELECT sale_status FROM {$table_name} WHERE post_id = ? LIMIT 1", [$product_id]);
        if ($sale_status !== null && $sale_status !== '') {
            return $sale_status;
        }
    }

    // 回退到 post_meta
    $meta_val = get_post_meta($product_id, 'sale_status', true);
    if ($meta_val !== '' && $meta_val !== false && $meta_val !== null) {
        return (string) $meta_val;
    }

    return 'online';
}

/**
 * 检查产品是否允许正常在前端售卖（发布状态且非仓库下架）
 * 
 * @param int|object $product 产品ID或文章对象
 * @return bool
 */
function todo_is_product_on_sale($product) {
    if (is_numeric($product)) {
        $product_id = intval($product);
        $post = get_post($product_id);
    } elseif (is_object($product)) {
        $post = $product;
        $product_id = intval($post->ID ?? 0);
    } else {
        return false;
    }

    if (!$post || ($post->post_status ?? '') !== 'publish') {
        return false;
    }

    // 定时发布未到期检查
    if (!empty($post->post_date_gmt)) {
        $now_gmt = current_time('mysql', true);
        if ($post->post_date_gmt > $now_gmt) {
            return false;
        }
    }

    $sale_status = todo_get_product_sale_status($product_id);
    return $sale_status !== 'warehouse';
}

/**
 * 检查产品是否有库存
 * 
 * @param int $product_id 产品ID
 * @param int $quantity 需要的数量
 * @return bool 是否有足够库存
 */
function has_product_stock($product_id, $quantity = 1) {
    $stock = get_product_stock($product_id);
    return $stock >= $quantity;
}

/**
 * 减少产品库存
 * 
 * @param int $product_id 产品ID
 * @param int $quantity 减少的数量
 * @return bool 是否成功
 */
function reduce_product_stock($product_id, $quantity) {
    $current_stock = get_product_stock($product_id);
    
    if ($current_stock >= $quantity) {
        $new_stock = $current_stock - $quantity;
        return update_product_stock($product_id, $new_stock);
    }
    
    return false;
}

/**
 * 获取产品规格
 * 
 * @param int $product_id 产品ID
 * @return array 规格信息
 */
function get_product_specifications($product_id) {
    global $tododb;
    
    $sql = "SELECT field_value FROM post_custom_fields 
            WHERE post_id = ? AND field_key = 'product_specifications'";
    
    $specs = $tododb->get_var($sql, [$product_id]);
    
    if ($specs) {
        return json_decode($specs, true);
    }
    
    return [];
}

/**
 * 获取产品图片
 * 
 * @param int $product_id 产品ID
 * @return array 图片URL数组
 */
function get_product_images($product_id) {
    global $tododb;
    
    $sql = "SELECT field_value FROM post_custom_fields 
            WHERE post_id = ? AND field_key = 'product_images'";
    
    $images = $tododb->get_var($sql, [$product_id]);
    
    if ($images) {
        return json_decode($images, true);
    }
    
    return [];
}

/**
 * 获取产品缩略图
 * 
 * @param int $product_id 产品ID
 * @return string 缩略图URL
 */
function get_product_thumbnail($product_id) {
    $images = get_product_images($product_id);
    
    if (!empty($images) && is_array($images)) {
        return $images[0];
    }
    
    return '';
}

/**
 * 检查产品是否为虚拟产品
 * 
 * @param int $product_id 产品ID
 * @return bool 是否为虚拟产品
 */
function is_virtual_product($product_id) {
    global $tododb;
    
    $sql = "SELECT field_value FROM post_custom_fields 
            WHERE post_id = ? AND field_key = 'product_type'";
    
    $type = $tododb->get_var($sql, [$product_id]);
    
    return $type === 'virtual';
}

/**
 * 获取产品销量
 * 
 * @param int $product_id 产品ID
 * @return int 销量
 */
function get_product_sales($product_id) {
    global $tododb;
    
    $sql = "SELECT field_value FROM post_custom_fields 
            WHERE post_id = ? AND field_key = 'product_sales'";
    
    $sales = $tododb->get_var($sql, [$product_id]);
    
    return $sales ? (int)$sales : 0;
}

/**
 * 增加产品销量
 * 
 * @param int $product_id 产品ID
 * @param int $quantity 增加的数量
 * @return bool 是否成功
 */
function increase_product_sales($product_id, $quantity = 1) {
    $current_sales = get_product_sales($product_id);
    $new_sales = $current_sales + $quantity;
    
    global $tododb;
    
    // 检查是否已存在
    $exists = $tododb->get_var(
        "SELECT COUNT(*) FROM post_custom_fields WHERE post_id = ? AND field_key = 'product_sales'",
        [$product_id]
    );
    
    if ($exists) {
        $sql = "UPDATE post_custom_fields SET field_value = ? 
                WHERE post_id = ? AND field_key = 'product_sales'";
        return $tododb->query($sql, [$new_sales, $product_id]);
    } else {
        $sql = "INSERT INTO post_custom_fields (post_id, field_key, field_value) 
                VALUES (?, 'product_sales', ?)";
        return $tododb->query($sql, [$product_id, $new_sales]);
    }
}

/**
 * 格式化产品价格显示
 * 
 * @param float $price 价格
 * @param string $currency 货币符号
 * @return string 格式化的价格
 */
function format_product_price($price, $currency = null) {
    if ($currency === null) {
        if (function_exists('todo_format_price')) {
            return todo_format_price($price);
        }
        $currency = function_exists('todo_get_currency_config') 
            ? ((todo_get_currency_config()['display_symbol'] ?? '') ?: (todo_get_currency_config()['symbol'] ?? '')) 
            : '';
    }
    return $currency . number_format($price, 2);
}

/**
 * 获取产品评论数量
 * 
 * @param int $product_id 产品ID
 * @return int 评论数量
 */
function get_product_comment_count($product_id) {
    global $tododb;
    
    $sql = "SELECT COUNT(*) FROM comments 
            WHERE comment_post_ID = ? AND comment_approved = '1'";
    
    return (int)$tododb->get_var($sql, [$product_id]);
}

/**
 * 获取产品平均评分
 * 
 * @param int $product_id 产品ID
 * @return float 平均评分
 */
function get_product_average_rating($product_id) {
    global $tododb;
    
    $sql = "SELECT AVG(CAST(meta_value AS DECIMAL(3,2))) 
            FROM comment_meta cm 
            JOIN comments c ON cm.comment_id = c.comment_ID 
            WHERE c.comment_post_ID = ? AND cm.meta_key = 'rating' 
            AND c.comment_approved = '1'";
    
    $rating = $tododb->get_var($sql, [$product_id]);
    
    return $rating ? round($rating, 1) : 0;
}

/**
 * 检查用户是否可以购买产品
 * 
 * @param int $product_id 产品ID
 * @param int $user_id 用户ID
 * @return bool 是否可以购买
 */
function can_user_buy_product($product_id, $user_id = 0) {
    // 检查产品状态
    $product = get_product($product_id);
    if (!$product || $product->post_status !== 'publish') {
        return false;
    }
    
    // 检查库存
    if (!has_product_stock($product_id)) {
        return false;
    }
    
    // 可以添加更多检查逻辑，如用户权限、产品限制等
    
    return true;
}

/**
 * 获取相关产品
 * 
 * @param int $product_id 产品ID
 * @param int $limit 返回数量限制
 * @return array 相关产品列表
 */
function get_related_products($product_id, $limit = 4) {
    global $tododb;
    
    // 获取当前产品的分类
    $sql = "SELECT tt.term_id FROM term_relationships tr 
            JOIN term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
            WHERE tr.object_id = ? AND tt.taxonomy = 'product_category'";
    
    $category_ids = $tododb->get_col($sql, [$product_id]);
    
    if (empty($category_ids)) {
        return [];
    }
    
    $category_placeholders = implode(',', array_fill(0, count($category_ids), '?'));
    
    $sql = "SELECT DISTINCT p.* FROM posts p 
            JOIN term_relationships tr ON p.ID = tr.object_id 
            JOIN term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish' 
            AND p.ID != ? 
            AND tt.term_id IN ({$category_placeholders}) 
            ORDER BY RAND() 
            LIMIT ?";
    
    $params = array_merge([$product_id], $category_ids, [$limit]);
    
    return $tododb->get_results($sql, $params);
}

if (!function_exists('todo_get_product_member_price')) {
    /**
     * 计算商品针对特定用户/会员等级的最终有效折扣价格
     * 1. 优先使用商品独立针对该等级设置的会员价 (level_prices[$level_id]) 或专属折扣率 (level_rates[$level_id])
     * 2. 若未单独填写该等级的会员价，且 discount_type 为 'auto' 或 'level'，自动回退使用会员等级设置的默认折扣率 (discount_rate) 进行核算
     * 3. 结果防套利：多个备选折扣价取最低单价
     *
     * @param int $product_id 商品ID
     * @param float $base_price 基础单价
     * @param int $user_id 用户ID（若未传则自动取当前登录用户）
     * @return float 计算后的有效价格
     */
    function todo_get_product_member_price($product_id, $base_price, $user_id = 0, $specs = array()) {
        $product_id = intval($product_id);
        $base_price = floatval($base_price);
        if ($base_price <= 0) {
            return 0.0;
        }

        if ($user_id <= 0) {
            $user_id = function_exists('todo_get_current_user_id') 
                ? intval(todo_get_current_user_id()) 
                : (function_exists('get_current_user_id') ? intval(get_current_user_id()) : 0);
        }

        if ($user_id <= 0) {
            return $base_price;
        }

        // 获取用户当前会员等级及其默认折扣率与名称
        $level_id = 0;
        $level_discount_rate = null;
        $user_level_slug = '';
        $user_level_name = '';

        if (function_exists('todo_get_user_level_manager')) {
            $lvl = todo_get_user_level_manager()->get_user_current_level($user_id);
            if ($lvl) {
                if (isset($lvl->level_id)) {
                    $level_id = intval($lvl->level_id);
                } elseif (isset($lvl->id)) {
                    $level_id = intval($lvl->id);
                }
                if (isset($lvl->discount_rate) && $lvl->discount_rate !== '') {
                    $level_discount_rate = floatval($lvl->discount_rate);
                }
                if (isset($lvl->level_slug)) {
                    $user_level_slug = strtolower((string)$lvl->level_slug);
                }
                if (isset($lvl->level_name)) {
                    $user_level_name = (string)$lvl->level_name;
                }
            }
        }
        if ($level_discount_rate === null && function_exists('todo_get_user_discount_rate')) {
            $rate_val = todo_get_user_discount_rate($user_id);
            if ($rate_val !== null && $rate_val !== '') {
                $level_discount_rate = floatval($rate_val);
            }
        }
        if ($level_discount_rate === null) {
            $level_discount_rate = 100.0;
        }

        $candidate_prices = array();

        // 1. 读取商品或规格在 model_fields_product 或 post_meta 中的 wholesale_price / wholesale_vip_price
        $wholesale_price = 0.0;
        $wholesale_vip_price = 0.0;

        if (!empty($specs) && function_exists('todo_get_product_spec_data')) {
            $spec_data = todo_get_product_spec_data($product_id, $specs);
            if ($spec_data) {
                $wholesale_price = floatval($spec_data['wholesale_price'] ?? 0);
                $wholesale_vip_price = floatval($spec_data['wholesale_vip_price'] ?? 0);
            }
        }
        if ($wholesale_price <= 0 && $wholesale_vip_price <= 0) {
            global $tododb;
            if ($tododb) {
                $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
                $row = $tododb->get_row("SELECT wholesale_price, wholesale_vip_price FROM {$table_name} WHERE post_id = ? LIMIT 1", [$product_id], 'ARRAY_A');
                if ($row) {
                    $wholesale_price = floatval($row['wholesale_price'] ?? 0);
                    $wholesale_vip_price = floatval($row['wholesale_vip_price'] ?? 0);
                }
            }
        }
        if ($wholesale_price <= 0) {
            $wholesale_price = floatval(get_post_meta($product_id, 'wholesale_price', true) ?: 0);
        }
        if ($wholesale_vip_price <= 0) {
            $wholesale_vip_price = floatval(get_post_meta($product_id, 'wholesale_vip_price', true) ?: 0);
        }

        // 重构：严格按照数据库 user_levels 表的 level_order 动态判断用户价格阶梯层级
        $tier = 'normal';
        if (function_exists('todo_get_user_level_manager')) {
            $tier = todo_get_user_level_manager()->get_user_price_tier($user_id);
        }
        $is_senior = ($tier === 'wholesale_vip');
        $is_wholesale = ($tier === 'wholesale_vip' || $tier === 'wholesale');

        if ($is_senior) {
            $tier_p = $wholesale_vip_price > 0 ? $wholesale_vip_price : ($wholesale_price > 0 ? $wholesale_price : 0.0);
            if ($tier_p > 0 && $tier_p < $base_price) {
                $candidate_prices[] = $tier_p;
            }
        } elseif ($is_wholesale) {
            $tier_p = $wholesale_price > 0 ? $wholesale_price : 0.0;
            if ($tier_p > 0 && $tier_p < $base_price) {
                $candidate_prices[] = $tier_p;
            }
        }

        // 2. 匹配商品独立给该等级设置的会员价 / 折扣配置 (discount_config)
        global $tododb;
        $discount_type = '';
        $discount_cfg_raw = '';
        if ($tododb) {
            $table_name = (isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_')) . 'model_fields_product';
            $disc_row = $tododb->get_row("SELECT discount_type, discount_config FROM {$table_name} WHERE post_id = ? LIMIT 1", [$product_id], 'ARRAY_A');
            if ($disc_row) {
                $discount_type = $disc_row['discount_type'] ?? '';
                $discount_cfg_raw = $disc_row['discount_config'] ?? '';
            }
        }
        if (empty($discount_type)) {
            $discount_type = get_post_meta($product_id, 'discount_type', true) ?: 'none';
        }

        // 当设置 discount_type === 'none' 时，表示该商品不参与任何会员等级折扣/折扣率
        if ($discount_type !== 'none') {
            if (empty($discount_cfg_raw)) {
                $discount_cfg_raw = get_post_meta($product_id, 'discount_config', true);
            }
            $discount_cfg = array();
            if (is_string($discount_cfg_raw) && $discount_cfg_raw !== '') {
                $tmp = json_decode($discount_cfg_raw, true);
                if (is_array($tmp)) {
                    $discount_cfg = $tmp;
                }
            } elseif (is_array($discount_cfg_raw)) {
                $discount_cfg = $discount_cfg_raw;
            }

            if (($discount_type === 'level' || !empty($discount_cfg)) && $level_id > 0) {
                $level_prices = isset($discount_cfg['level_prices']) && is_array($discount_cfg['level_prices']) ? $discount_cfg['level_prices'] : [];
                $level_rates = isset($discount_cfg['level_rates']) && is_array($discount_cfg['level_rates']) ? $discount_cfg['level_rates'] : [];
                
                $raw_lp = null;
                if (array_key_exists((string) $level_id, $level_prices)) {
                    $raw_lp = $level_prices[(string) $level_id];
                } elseif (array_key_exists($level_id, $level_prices)) {
                    $raw_lp = $level_prices[$level_id];
                }

                if ($raw_lp !== null && trim((string)$raw_lp) !== '') {
                    $clean_lp = preg_replace('/[^\d.]/', '', (string)$raw_lp);
                    if ($clean_lp !== '') {
                        $val = floatval($clean_lp);
                        if ($val > 0 && $val <= 1.0) {
                            $candidate_prices[] = round($base_price * $val, 2);
                        } elseif ($val >= 0) {
                            $candidate_prices[] = $val;
                        }
                    }
                }

                $raw_lr = null;
                if (array_key_exists((string) $level_id, $level_rates)) {
                    $raw_lr = $level_rates[(string) $level_id];
                } elseif (array_key_exists($level_id, $level_rates)) {
                    $raw_lr = $level_rates[$level_id];
                }

                if ($raw_lr !== null && trim((string)$raw_lr) !== '') {
                    $clean_lr = preg_replace('/[^\d.]/', '', (string)$raw_lr);
                    if ($clean_lr !== '') {
                        $rval = floatval($clean_lr);
                        if ($rval > 0 && $rval <= 100) {
                            $candidate_prices[] = round($base_price * ($rval / 100.0), 2);
                        }
                    }
                }
            }

            // 3. 如果未单独填写该等级的会员价，且 discount_type 为 'auto' 或 'level'，使用会员等级设置的默认折扣率核算
            if (empty($candidate_prices) && ($discount_type === 'auto' || $discount_type === 'level' || empty($discount_type))) {
                if ($level_discount_rate >= 0 && $level_discount_rate < 100) {
                    $candidate_prices[] = round($base_price * ($level_discount_rate / 100.0), 2);
                }
            }
        }

        if (!empty($candidate_prices)) {
            return max(0, min($candidate_prices));
        }

        return $base_price;
    }
}

if (!function_exists('todo_get_user_discount_label')) {
    /**
     * 获取用户当前会员等级对应的折扣名称（如 高级批发价 / 批发价 / 会员价）
     * 
     * @param int $user_id
     * @return string
     */
    function todo_get_user_discount_label($user_id = 0) {
        if ($user_id <= 0) {
            $user_id = function_exists('todo_get_current_user_id') 
                ? intval(todo_get_current_user_id()) 
                : (function_exists('get_current_user_id') ? intval(get_current_user_id()) : 0);
        }
        if ($user_id > 0 && function_exists('todo_get_user_level_manager')) {
            $lvl = todo_get_user_level_manager()->get_user_current_level($user_id);
            if ($lvl) {
                $slug = strtolower((string)($lvl->level_slug ?? ''));
                $name = (string)($lvl->level_name ?? '');
                if (in_array($slug, ['wholesale_vip', 'senior_vip', 'vip_senior'], true) || strpos($slug, 'senior') !== false || strpos($name, '高级') !== false) {
                    return __('高级批发价');
                }
                if (in_array($slug, ['wholesale', 'vip'], true) || strpos($slug, 'wholesale') !== false || strpos($name, '批发') !== false) {
                    return __('批发价');
                }
                if ($name !== '') {
                    return __($name);
                }
            }
        }
        return __('高级批发价');
    }
}

?>
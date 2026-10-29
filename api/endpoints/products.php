<?php
/**
 * 产品 API 端点
 *
 * 提供产品相关的 API 端点，支持根据用户身份返回不同价格
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 获取产品列表
 */
function api_get_products($params) {
    global $tododb;

    // 默认参数
    $defaults = [
        'page' => 1,
        'per_page' => 10,
        'category' => '',
        'search' => '',
        'orderby' => 'ID',
        'order' => 'DESC',
        'include_unavailable' => 0,
        'include_out_of_stock' => 1,
        'lang' => ''
    ];

    $args = array_merge($defaults, $params);
    $page = max(1, intval($args['page']));
    $per_page = max(1, min(100, intval($args['per_page'])));
    $offset = ($page - 1) * $per_page;
    $include_unavailable = !empty($args['include_unavailable']);
    $include_out_of_stock = !empty($args['include_out_of_stock']);
    $lang = !empty($args['lang']) ? sanitize_text_field($args['lang']) : (function_exists('get_current_language') ? get_current_language() : '');
    $now_gmt = current_time('mysql', true);

    // 获取当前用户身份
    $user_id = get_current_user_id();
    $user_level = $user_id ? get_user_meta($user_id, 'user_level', true) : 'retail';

    $valid_orderby = ['ID', 'post_date', 'post_modified', 'post_title', 'sort_order'];
    $orderby = in_array((string) $args['orderby'], $valid_orderby, true) ? (string) $args['orderby'] : 'ID';
    $order = strtoupper((string) $args['order']);
    $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

    $query = "SELECT p.*, mp.price, mp.unit_qty, mp.unit, mp.wholesale_price, mp.wholesale_vip_price, mp.original_price, mp.stock, mp.spec_type, mp.spec_details, mp.sale_status, mp.marketing_feature
              FROM " . TABLE_PREFIX . "posts p
              LEFT JOIN " . TABLE_PREFIX . "model_fields_product mp ON p.ID = mp.post_id
              WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_date_gmt <= ?";
    
    $query_params = [$now_gmt];
    if (!empty($args['category'])) {
        $category_id = intval($args['category']);
        $category_ids = [$category_id];

        // 如果开启递归模式，获取所有子分类 ID
        if (!empty($args['recursive']) && class_exists('Todo_Category_Manager')) {
            $children = Todo_Category_Manager::get_children($category_id, true);
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (isset($child['id'])) {
                        $category_ids[] = intval($child['id']);
                    }
                }
            }
        }
        
        $placeholders = implode(',', array_fill(0, count($category_ids), '?'));
        $query .= " AND p.ID IN (
            SELECT tr.object_id
            FROM " . TABLE_PREFIX . "term_relationships tr
            INNER JOIN " . TABLE_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id IN ({$placeholders}) AND tt.taxonomy = 'category'
        )";
        $query_params = array_merge($query_params, $category_ids);
    }

    if (!empty($args['search'])) {
        $search_term = '%' . $args['search'] . '%';
        $curr_lang = !empty($lang) ? $lang : (function_exists('get_current_language') ? get_current_language() : 'zh-CN');
        $canonical_lang = function_exists('canonical_locale') ? canonical_locale($curr_lang) : str_replace('_', '-', $curr_lang);
        $alt_lang = str_replace('-', '_', $canonical_lang);

        $has_trans_table = method_exists($tododb, 'table_exists') ? $tododb->table_exists('post_translations') : true;
        if ($has_trans_table) {
            $esc_lang1 = $tododb->esc_like($canonical_lang);
            $esc_lang2 = $tododb->esc_like($alt_lang);
            $query .= " LEFT JOIN " . TABLE_PREFIX . "post_translations ptr ON (ptr.post_id = p.ID AND (ptr.language_code = '{$esc_lang1}' OR ptr.language_code = '{$esc_lang2}'))";
            $query .= " AND (p.post_title LIKE ? OR p.post_content LIKE ? OR ptr.title LIKE ? OR ptr.content LIKE ? OR ptr.excerpt LIKE ? OR ptr.seo_title LIKE ? OR ptr.seo_keywords LIKE ? OR ptr.seo_description LIKE ?)";
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
            $query_params[] = $search_term;
        } else {
            $query .= " AND (p.post_title LIKE ? OR p.post_content LIKE ?)";
            $query_params[] = $search_term;
            $query_params[] = $search_term;
        }
    }

    if (!$include_unavailable) {
        $query .= " AND (mp.sale_status IS NULL OR mp.sale_status <> 'warehouse')";
    }
    if (!$include_out_of_stock) {
        $query .= " AND (mp.stock IS NULL OR mp.stock > 0)";
    }

    // 增强排序逻辑：支持根据文档属性（头条、推荐、加推）进行权重排序
    $orderby_sql = "p.{$orderby} {$order}";
    if ($orderby === 'ID' || $orderby === 'post_date' || $orderby === 'sort_order') {
        $orderby_sql = "
            (CASE WHEN p.doc_properties LIKE '%\"1\"%' OR p.doc_properties LIKE '%:\"1\"%' OR p.doc_properties LIKE '%,1,%' OR p.doc_properties = '1' THEN 1 ELSE 0 END) DESC,
            (CASE WHEN p.doc_properties LIKE '%\"2\"%' OR p.doc_properties LIKE '%:\"2\"%' OR p.doc_properties LIKE '%,2,%' OR p.doc_properties = '2' THEN 1 ELSE 0 END) DESC,
            (CASE WHEN p.doc_properties LIKE '%\"3\"%' OR p.doc_properties LIKE '%:\"3\"%' OR p.doc_properties LIKE '%,3,%' OR p.doc_properties = '3' THEN 1 ELSE 0 END) DESC,
            p.sort_order ASC,
            p.{$orderby} {$order}
        ";
    }

    $query .= " ORDER BY {$orderby_sql} LIMIT {$per_page} OFFSET {$offset}";

    $products = $tododb->get_results($query, $query_params);

    $translations = [];
    if (!empty($products) && function_exists('todo_get_posts_multilang_batch')) {
        $post_ids = [];
        foreach ($products as $p) {
            if (is_object($p) && isset($p->ID)) {
                $post_ids[] = intval($p->ID);
            }
        }
        if (!empty($post_ids)) {
            $translations = todo_get_posts_multilang_batch($post_ids, $lang, [
                'include_seo' => false,
                'fallback' => true,
            ]);
        }
    }

    $result = [];
    foreach ($products as $product) {
        $sale_status = isset($product->sale_status) && $product->sale_status !== '' ? $product->sale_status : 'online';
        $is_on_sale = ($sale_status !== 'warehouse');
        $stock = intval($product->stock);
        if (isset($product->spec_type) && ($product->spec_type === 'multiple' || $product->spec_type === 'multi')) {
            $details = json_decode($product->spec_details ?? '', true);
            if (is_array($details) && !empty($details)) {
                $sum_stock = 0;
                foreach ($details as $d) {
                    if (is_array($d)) {
                        $sum_stock += isset($d['stock_count']) ? intval($d['stock_count']) : (isset($d['stock']) ? intval($d['stock']) : 0);
                    }
                }
                $stock = $sum_stock;
            }
        }
        $is_in_stock = ($stock > 0);
        $title = $product->post_title;
        if (isset($translations[intval($product->ID)]) && is_array($translations[intval($product->ID)]) && isset($translations[intval($product->ID)]['title'])) {
            $title = $translations[intval($product->ID)]['title'];
        }
        $category_ids = [];
        $activities = [];
        if (function_exists('get_the_terms')) {
            $terms = get_the_terms($product->ID, 'category');
            if ($terms && is_array($terms)) {
                $now_ts = current_time('timestamp');
                foreach ($terms as $term) {
                    $tid = intval($term->term_id);
                    $category_ids[] = $tid;
                    
                    // 活动校验：非隐藏且在有效期内
                    $is_hidden = get_term_meta($tid, 'is_hidden', true);
                    if ($is_hidden) continue;
                    
                    $start = get_term_meta($tid, 'hidden_start', true);
                    $end = get_term_meta($tid, 'hidden_end', true);
                    
                    $has_start = ($start && trim($start) !== '');
                    $has_end = ($end && trim($end) !== '');
                    
                    // 必须具备活动时间设置才被视为“活动分类”
                    if (!$has_start && !$has_end) continue;

                    $is_active = true;
                    if ($has_start) {
                        $start_ts = strtotime($start);
                        if ($start_ts && $start_ts > $now_ts) $is_active = false;
                    }
                    if ($is_active && $has_end) {
                        $end_ts = strtotime($end);
                        if ($end_ts && $end_ts < $now_ts) $is_active = false;
                    }
                    
                    if ($is_active) {
                        $activities[] = [
                            'id' => $tid,
                            'name' => (string)$term->name
                        ];
                    }
                }
            }
        }

        $mf = isset($product->marketing_feature) ? trim((string) $product->marketing_feature) : '';
        if (function_exists('todo_parse_inline_translations')) {
            $mf = todo_parse_inline_translations($mf);
        }

        $item = [
            'id' => intval($product->ID),
            'title' => $title,
            'featured_image' => api_get_featured_image($product->ID),
            'stock' => $stock,
            'spec_type' => $product->spec_type,
            'unit_qty' => isset($product->unit_qty) ? (string)($product->unit_qty) : '1',
            'unit' => isset($product->unit) && $product->unit !== '' ? (string) $product->unit : 'piece',
            'sale_status' => $sale_status,
            'is_on_sale' => $is_on_sale,
            'is_in_stock' => $is_in_stock,
            'is_available' => ($is_on_sale && $is_in_stock),
            'marketing_feature' => $mf,
            'category_ids' => $category_ids,
            'activities' => $activities,
        ];

        // 根据身份返回价格
        if ($user_level === 'wholesale_vip') {
            $item['price'] = floatval($product->wholesale_vip_price > 0 ? $product->wholesale_vip_price : $product->wholesale_price);
            $item['price_type'] = 'wholesale_vip';
        } elseif ($user_level === 'wholesale') {
            $item['price'] = floatval($product->wholesale_price);
            $item['price_type'] = 'wholesale';
        } else {
            $item['price'] = floatval($product->price);
            $item['price_type'] = 'retail';
        }
        $item['original_price'] = floatval($product->original_price);

        $result[] = $item;
    }

    return [
        'products' => $result,
        'page' => $page,
        'per_page' => $per_page,
        'user_level' => $user_level
    ];
}

/**
 * 获取产品详情
 */
function api_get_product_detail($params) {
    global $tododb;

    if (!isset($params['id'])) {
        send_api_error(400, '缺少产品ID');
    }

    $product_id = intval($params['id']);
    $lang = !empty($params['lang']) ? sanitize_text_field($params['lang']) : (function_exists('get_current_language') ? get_current_language() : '');
    
    // 获取当前用户身份
    $user_id = get_current_user_id();
    $user_level = $user_id ? get_user_meta($user_id, 'user_level', true) : 'retail';

    $now_gmt = current_time('mysql', true);
    $query = "SELECT p.*, mp.* 
              FROM " . TABLE_PREFIX . "posts p
              LEFT JOIN " . TABLE_PREFIX . "model_fields_product mp ON p.ID = mp.post_id
              WHERE p.ID = ? AND p.post_type = 'product' AND p.post_status = 'publish' AND p.post_date_gmt <= ? LIMIT 1";
    
    $product = $tododb->get_row($query, [$product_id, $now_gmt]);

    if (!$product) {
        send_api_error(404, '产品不存在');
    }

    $sale_status = isset($product->sale_status) && $product->sale_status !== '' ? $product->sale_status : 'online';
    if ($sale_status === 'warehouse') {
        send_api_error(404, '商品已下架');
    }

    $stock = intval($product->stock);
    if (isset($product->spec_type) && $product->spec_type === 'multiple') {
        $details = json_decode($product->spec_details ?? '', true);
        if (is_array($details)) {
            $max_stock = 0;
            foreach ($details as $d) {
                if (is_array($d)) {
                    // 兼容 stock（旧）和 stock_count（统一库存字段名）
                    $stk = isset($d['stock']) ? intval($d['stock']) : intval($d['stock_count'] ?? 0);
                    $max_stock = max($max_stock, $stk);
                }
            }
            $stock = max($stock, $max_stock);
        }
    }
    $category_ids = [];
    $activities = [];
    if (function_exists('get_the_terms')) {
        $terms = get_the_terms($product_id, 'category');
        if ($terms && is_array($terms)) {
            $now_ts = current_time('timestamp');
            foreach ($terms as $term) {
                $tid = intval($term->term_id);
                $category_ids[] = $tid;

                $is_hidden = get_term_meta($tid, 'is_hidden', true);
                if ($is_hidden) continue;

                $start = get_term_meta($tid, 'hidden_start', true);
                $end = get_term_meta($tid, 'hidden_end', true);

                $has_start = ($start && trim($start) !== '');
                $has_end = ($end && trim($end) !== '');
                
                // 必须具备活动时间设置才被视为“活动分类”
                if (!$has_start && !$has_end) continue;

                $is_active = true;
                if ($has_start) {
                    $start_ts = strtotime($start);
                    if ($start_ts && $start_ts > $now_ts) $is_active = false;
                }
                if ($is_active && $has_end) {
                    $end_ts = strtotime($end);
                    if ($end_ts && $end_ts < $now_ts) $is_active = false;
                }

                if ($is_active) {
                    $activities[] = [
                        'id' => $tid,
                        'name' => (string)$term->name
                    ];
                }
            }
        }
    }

    $mf = isset($product->marketing_feature) ? trim((string) $product->marketing_feature) : '';
    if (function_exists('todo_parse_inline_translations')) {
        $mf = todo_parse_inline_translations($mf);
    }

    $result = [
        'id' => intval($product->ID),
        'title' => $product->post_title,
        'marketing_feature' => $mf,
        'content' => $product->post_content,
        'featured_image' => api_get_featured_image($product->ID),
        'featured_images' => function_exists('api_get_featured_images') ? api_get_featured_images($product->ID) : [],
        'stock' => $stock,
        'spec_type' => $product->spec_type,
        'unit_qty' => isset($product->unit_qty) ? (string)($product->unit_qty) : '1',
        'unit' => isset($product->unit) && $product->unit !== '' ? (string) $product->unit : 'piece',
        'user_level' => $user_level,
        'sale_status' => $sale_status,
        'is_on_sale' => true,
        'is_in_stock' => ($stock > 0),
        'is_available' => ($stock > 0),
        'category_ids' => $category_ids,
        'activities' => $activities
    ];

    $result['show_stock'] = !empty($product->show_stock);

    if (function_exists('todo_get_post_multilang')) {
        $ml = todo_get_post_multilang($product_id, $lang, ['include_seo' => false, 'fallback' => true]);
        if (is_array($ml)) {
            if (isset($ml['title']) && $ml['title'] !== '') {
                $result['title'] = $ml['title'];
            }
            if (isset($ml['content']) && $ml['content'] !== '') {
                $result['content'] = $ml['content'];
            }
        }
    }

    $service_options = [];
    if (function_exists('todo_get_option')) {
        $settings = todo_get_option('todo_service_settings', null);
        if (is_array($settings) && array_key_exists('product_service_items', $settings)) {
            $list = is_array($settings['product_service_items']) ? $settings['product_service_items'] : [];
            $seen = [];
            foreach ($list as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $code = isset($it['code']) ? todo_sanitize_key($it['code']) : '';
                $label = isset($it['label']) ? sanitize_text_field($it['label']) : '';
                if ($code === '' || $label === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $service_options[$code] = $label;
            }
        }
    }
    $service_codes = [];
    $raw_services = isset($product->product_services) ? $product->product_services : '';
    if (is_string($raw_services)) {
        $trim = trim($raw_services);
        if ($trim !== '') {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                $service_codes = $decoded;
            }
        }
    } elseif (is_array($raw_services)) {
        $service_codes = $raw_services;
    }
    $service_codes = array_values(array_filter(array_map(function ($v) {
        return todo_sanitize_key($v);
    }, is_array($service_codes) ? $service_codes : []), function ($v) {
        return is_string($v) && $v !== '';
    }));
    $result['product_services'] = $service_codes;
    $result['product_services_display'] = array_values(array_map(function ($code) use ($service_options) {
        return [
            'code' => $code,
            'label' => isset($service_options[$code]) ? $service_options[$code] : $code,
        ];
    }, $service_codes));

    $custom_params_out = [];
    $raw_params = isset($product->custom_params) ? $product->custom_params : '';
    if (is_string($raw_params)) {
        $trim = trim($raw_params);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                foreach ($decoded as $pid => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = isset($row['param_name']) ? trim((string) $row['param_name']) : '';
                    $val = isset($row['param_value']) ? trim((string) $row['param_value']) : '';
                    $sort = isset($row['sort_order']) ? intval($row['sort_order']) : 100;
                    if ($name === '' && $val === '') {
                        continue;
                    }
                    $custom_params_out[] = [
                        'id' => (string) $pid,
                        'sort_order' => $sort,
                        'name' => $name,
                        'value' => $val,
                    ];
                }
            }
        }
    }
    if (!empty($custom_params_out)) {
        usort($custom_params_out, function ($a, $b) {
            return intval($a['sort_order'] ?? 100) <=> intval($b['sort_order'] ?? 100);
        });
    }
    $result['custom_params'] = $custom_params_out;

    $result['discount_type'] = isset($product->discount_type) ? (string) $product->discount_type : '';
    $discount_cfg = isset($product->discount_config) ? $product->discount_config : '';
    $result['discount_config'] = $discount_cfg;
    if (is_string($discount_cfg)) {
        $trim = trim($discount_cfg);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                $result['discount_config'] = $decoded;
            }
        }
    }

    $video_url = '';
    $raw_video = isset($product->product_video) ? $product->product_video : '';
    $video_id = 0;
    if (is_string($raw_video)) {
        $trim = trim($raw_video);
        if ($trim !== '') {
            if ($trim[0] === '[' || $trim[0] === '{') {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $is_assoc = array_keys($decoded) !== range(0, count($decoded) - 1);
                    if ($is_assoc) {
                        if (isset($decoded['url']) && is_string($decoded['url']) && trim($decoded['url']) !== '') {
                            $video_url = trim((string) $decoded['url']);
                        }
                        if (isset($decoded['id']) && is_numeric($decoded['id'])) {
                            $video_id = intval($decoded['id']);
                        }
                    } else {
                        $first = $decoded[0] ?? null;
                        if (is_array($first)) {
                            if (isset($first['url']) && is_string($first['url']) && trim($first['url']) !== '') {
                                $video_url = trim((string) $first['url']);
                            }
                            if (isset($first['id']) && is_numeric($first['id'])) {
                                $video_id = intval($first['id']);
                            }
                        } elseif (is_numeric($first)) {
                            $video_id = intval($first);
                        }
                    }
                } elseif (is_numeric($trim)) {
                    $video_id = intval($trim);
                }
            } elseif (is_numeric($trim)) {
                $video_id = intval($trim);
            }
        }
    } elseif (is_numeric($raw_video)) {
        $video_id = intval($raw_video);
    }

    if ($video_url !== '' && function_exists('api_normalize_media_url')) {
        $video_url = (string) api_normalize_media_url($video_url);
    }

    if ($video_url === '' && $video_id > 0) {
        if (function_exists('todo_get_attachment_url')) {
            $video_url = (string) todo_get_attachment_url($video_id);
        } elseif (function_exists('todo_get_media_url')) {
            $video_url = (string) todo_get_media_url($video_id);
        }
        if ($video_url !== '' && function_exists('api_normalize_media_url')) {
            $video_url = (string) api_normalize_media_url($video_url);
        }
    }
    $result['product_video'] = $video_url;

    // 处理价格和规格
    if ($product->spec_type === 'multiple') {
        $specs = json_decode($product->spec_config, true);
        if (is_array($specs)) {
            foreach ($specs as &$group) {
                if (is_array($group)) {
                    foreach (['title', 'name'] as $title_key) {
                        if (isset($group[$title_key]) && is_string($group[$title_key])) {
                            if (function_exists('todo_parse_inline_translations')) {
                                $group[$title_key] = todo_parse_inline_translations($group[$title_key]);
                            }
                            if (function_exists('todo_translate')) {
                                $group[$title_key] = todo_translate($group[$title_key]);
                            }
                        }
                    }
                    if (!empty($group['values']) && is_array($group['values'])) {
                        foreach ($group['values'] as $vk => $raw_val_str) {
                            if (is_string($raw_val_str) && $raw_val_str !== '') {
                                $val_str = $raw_val_str;
                                if (function_exists('todo_parse_inline_translations')) {
                                    $val_str = todo_parse_inline_translations($val_str);
                                }
                                if (function_exists('todo_translate')) {
                                    $val_str = todo_translate($val_str);
                                }
                                $group['values'][$vk] = $val_str;

                                $raw_key   = function_exists('todo_spec_stable_key') ? todo_spec_stable_key([$raw_val_str]) : '';
                                $trans_key = function_exists('todo_spec_stable_key') ? todo_spec_stable_key([$val_str]) : '';

                                if (!empty($group['image_urls']) && is_array($group['image_urls'])) {
                                    if ($raw_key && $trans_key && isset($group['image_urls'][$raw_key]) && !isset($group['image_urls'][$trans_key])) {
                                        $group['image_urls'][$trans_key] = $group['image_urls'][$raw_key];
                                    }
                                    if (isset($group['image_urls'][$raw_val_str]) && !isset($group['image_urls'][$val_str])) {
                                        $group['image_urls'][$val_str] = $group['image_urls'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['image_sm_urls']) && is_array($group['image_sm_urls'])) {
                                    if ($raw_key && $trans_key && isset($group['image_sm_urls'][$raw_key]) && !isset($group['image_sm_urls'][$trans_key])) {
                                        $group['image_sm_urls'][$trans_key] = $group['image_sm_urls'][$raw_key];
                                    }
                                    if (isset($group['image_sm_urls'][$raw_val_str]) && !isset($group['image_sm_urls'][$val_str])) {
                                        $group['image_sm_urls'][$val_str] = $group['image_sm_urls'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['images_sm']) && is_array($group['images_sm'])) {
                                    if ($raw_key && $trans_key && isset($group['images_sm'][$raw_key]) && !isset($group['images_sm'][$trans_key])) {
                                        $group['images_sm'][$trans_key] = $group['images_sm'][$raw_key];
                                    }
                                    if (isset($group['images_sm'][$raw_val_str]) && !isset($group['images_sm'][$val_str])) {
                                        $group['images_sm'][$val_str] = $group['images_sm'][$raw_val_str];
                                    }
                                }

                                if (!empty($group['images']) && is_array($group['images'])) {
                                    if ($raw_key && $trans_key && isset($group['images'][$raw_key]) && !isset($group['images'][$trans_key])) {
                                        $group['images'][$trans_key] = $group['images'][$raw_key];
                                    }
                                    if (isset($group['images'][$raw_val_str]) && !isset($group['images'][$val_str])) {
                                        $group['images'][$val_str] = $group['images'][$raw_val_str];
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($group['image_urls']) && is_array($group['image_urls']) && function_exists('todo_media_url_to_runtime_absolute')) {
                        foreach ($group['image_urls'] as $k => $u) {
                            if (is_string($u) && $u !== '') {
                                $group['image_urls'][$k] = todo_media_url_to_runtime_absolute($u);
                            }
                        }
                    }
                    if (!empty($group['image_sm_urls']) && is_array($group['image_sm_urls']) && function_exists('todo_media_url_to_runtime_absolute')) {
                        foreach ($group['image_sm_urls'] as $k => $u) {
                            if (is_string($u) && $u !== '') {
                                $group['image_sm_urls'][$k] = todo_media_url_to_runtime_absolute($u);
                            }
                        }
                    }
                }
            }
            unset($group);
        }
        $details = json_decode($product->spec_details, true);
        
        // 转换规格明细中的价格
        if (is_array($details)) {
            foreach ($details as &$detail) {
                if (is_array($detail)) {
                    foreach (['v0', 'v1', 'v2'] as $vk) {
                        if (isset($detail[$vk]) && is_string($detail[$vk]) && $detail[$vk] !== '') {
                            if (function_exists('todo_parse_inline_translations')) {
                                $detail[$vk] = todo_parse_inline_translations($detail[$vk]);
                            }
                            if (function_exists('todo_translate')) {
                                $detail[$vk] = todo_translate($detail[$vk]);
                            }
                        }
                    }
                }
                $detail['original_price'] = floatval($detail['crossed_price'] ?? 0);
                if ($user_level === 'wholesale_vip') {
                    $detail['price'] = floatval($detail['wholesale_vip_price'] ?? ($detail['wholesale_price'] ?? $detail['users_price']));
                } elseif ($user_level === 'wholesale') {
                    $detail['price'] = floatval($detail['wholesale_price'] ?? $detail['users_price']);
                } else {
                    $detail['price'] = floatval($detail['users_price']);
                }
                // 优先用已有 stock，否则取 stock_count（统一库存字段名）
                $detail['stock'] = isset($detail['stock']) ? intval($detail['stock']) : intval($detail['stock_count'] ?? 0);
                unset($detail['wholesale_price'], $detail['wholesale_vip_price'], $detail['users_price']);
            }
        }
        
        $result['specs'] = $specs;
        $result['spec_details'] = $details;

        $variants = [];
        $min_price = null;
        $max_price = null;
        $min_original = null;
        $max_original = null;
        $stock_total = 0;
        if (is_array($details)) {
            foreach ($details as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $v0 = isset($d['v0']) ? (string) $d['v0'] : '';
                $v1 = isset($d['v1']) ? (string) $d['v1'] : '';
                $v2 = isset($d['v2']) ? (string) $d['v2'] : '';
                $price = isset($d['price']) ? floatval($d['price']) : 0.0;
                $orig = isset($d['original_price']) ? floatval($d['original_price']) : 0.0;
                $stk = isset($d['stock']) ? intval($d['stock']) : intval($d['stock_count'] ?? 0);
                $stock_total += max(0, $stk);
                $min_price = ($min_price === null) ? $price : min($min_price, $price);
                $max_price = ($max_price === null) ? $price : max($max_price, $price);
                if ($orig > 0) {
                    $min_original = ($min_original === null) ? $orig : min($min_original, $orig);
                    $max_original = ($max_original === null) ? $orig : max($max_original, $orig);
                }
                $variants[] = [
                    'v0' => $v0,
                    'v1' => $v1,
                    'v2' => $v2,
                    'price' => $price,
                    'original_price' => $orig,
                    'stock' => $stk,
                    'sku' => isset($d['goods_code']) ? (string) $d['goods_code'] : '',
                    'sales' => intval($d['spec_sales_num'] ?? 0),
                ];
            }
        }

        $result['variants'] = $variants;
        $result['price_range'] = [
            'min' => $min_price === null ? 0 : $min_price,
            'max' => $max_price === null ? 0 : $max_price,
        ];
        $result['original_price_range'] = [
            'min' => $min_original === null ? 0 : $min_original,
            'max' => $max_original === null ? 0 : $max_original,
        ];
        $result['stock'] = $stock_total;
        $result['is_in_stock'] = ($stock_total > 0);
        $result['is_available'] = ($stock_total > 0);
    } else {
        if ($user_level === 'wholesale_vip') {
            $result['price'] = floatval($product->wholesale_vip_price > 0 ? $product->wholesale_vip_price : $product->wholesale_price);
        } elseif ($user_level === 'wholesale') {
            $result['price'] = floatval($product->wholesale_price);
        } else {
            $result['price'] = floatval($product->price);
        }
        $result['original_price'] = floatval($product->original_price);
    }

    return $result;
}

// 注册路由
register_api_route('products', 'GET', 'api_get_products');
register_api_route('products/([0-9]+)', 'GET', 'api_get_product_detail');

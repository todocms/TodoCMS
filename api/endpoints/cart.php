<?php
/**
 * 购物车 API 端点
 *
 * 提供购物车相关的 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

function api_cart_sort_keys_recursive($value) {
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $k => $v) {
        $value[$k] = api_cart_sort_keys_recursive($v);
    }
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
    return $value;
}

function api_cart_decode_specs($specs_raw) {
    if (is_array($specs_raw)) {
        return empty($specs_raw) ? null : $specs_raw;
    }
    if (!is_string($specs_raw)) {
        return null;
    }
    $trim = trim($specs_raw);
    if ($trim === '' || strtolower($trim) === 'null') {
        return null;
    }
    $decoded = json_decode($trim, true);
    if (is_string($decoded)) {
        $inner = trim($decoded);
        if ($inner !== '' && ($inner[0] === '{' || $inner[0] === '[')) {
            $decoded = json_decode($inner, true);
        }
    }
    if (!is_array($decoded) || empty($decoded)) {
        return null;
    }
    return $decoded;
}

function api_cart_normalize_specs_json($specs_raw) {
    $arr = api_cart_decode_specs($specs_raw);
    if (!$arr) {
        return [null, null];
    }
    $sorted = api_cart_normalize_specs_array($arr);
    if (!$sorted) {
        return [null, null];
    }
    $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [$sorted, $json ?: null];
}

function api_cart_normalize_specs_array($specs_arr) {
    if (!is_array($specs_arr) || empty($specs_arr)) {
        return null;
    }

    $normalized = [];
    foreach ($specs_arr as $key => $value) {
        if (is_array($value) || is_object($value)) {
            return null;
        }

        $normalized_key = null;
        if (is_int($key) || ctype_digit((string) $key)) {
            $normalized_key = 'v' . intval($key);
        } elseif (is_string($key) && preg_match('/^v\d+$/', $key)) {
            $normalized_key = $key;
        } else {
            return null;
        }

        $normalized_value = trim((string) $value);
        if ($normalized_value === '') {
            return null;
        }

        $normalized[$normalized_key] = $normalized_value;
    }

    if (empty($normalized)) {
        return null;
    }

    uksort($normalized, function($a, $b) {
        return intval(substr($a, 1)) <=> intval(substr($b, 1));
    });

    return $normalized;
}

function api_cart_extract_detail_specs($detail) {
    if (!is_array($detail)) {
        return null;
    }

    $specs = [];
    foreach ($detail as $key => $value) {
        if (is_string($key) && preg_match('/^v\d+$/', $key)) {
            $specs[$key] = trim((string) $value);
        }
    }

    if (empty($specs)) {
        return null;
    }

    uksort($specs, function($a, $b) {
        return intval(substr($a, 1)) <=> intval(substr($b, 1));
    });

    return $specs;
}

function api_cart_match_spec_detail($details, $specs_arr) {
    $normalized_specs = api_cart_normalize_specs_array($specs_arr);
    if (!is_array($details) || !is_array($normalized_specs)) {
        return null;
    }

    foreach ($details as $idx => $detail) {
        if (!is_array($detail)) {
            continue;
        }

        $detail_specs = api_cart_extract_detail_specs($detail);
        if (!is_array($detail_specs)) {
            continue;
        }

        if (count($detail_specs) !== count($normalized_specs)) {
            continue;
        }

        if (array_keys($detail_specs) !== array_keys($normalized_specs)) {
            continue;
        }

        $matched = true;
        foreach ($normalized_specs as $nk => $nv) {
            if (!isset($detail_specs[$nk])) {
                $matched = false;
                break;
            }
            if (function_exists('todo_multilang_strings_match')) {
                if (!todo_multilang_strings_match($detail_specs[$nk], $nv)) {
                    $matched = false;
                    break;
                }
            } else {
                if ($detail_specs[$nk] !== $nv) {
                    $matched = false;
                    break;
                }
            }
        }

        if ($matched) {
            return [$idx, $detail];
        }
    }

    return null;
}

function api_cart_get_product_for_purchase($product_id) {
    global $tododb;

    $now_gmt = current_time('mysql', true);
    $product_table = TABLE_PREFIX . 'posts';
    $fields_table = TABLE_PREFIX . 'model_fields_product';

    $query = "SELECT p.ID, p.post_title, p.post_status, p.post_date_gmt, mp.stock, mp.spec_type, mp.spec_details, mp.sale_status
              FROM {$product_table} p
              LEFT JOIN {$fields_table} mp ON p.ID = mp.post_id
              WHERE p.ID = ? AND p.post_type = 'product' LIMIT 1";
    $row = $tododb->get_row($query, [intval($product_id)]);
    if (!$row) {
        return null;
    }

    if ($row->post_status !== 'publish' || (isset($row->post_date_gmt) && $row->post_date_gmt > $now_gmt)) {
        return null;
    }
    $sale_status = isset($row->sale_status) && $row->sale_status !== '' ? $row->sale_status : 'online';
    if ($sale_status === 'warehouse') {
        return null;
    }

    return $row;
}

function api_cart_require_stock($product_row, $quantity, $specs) {
    $qty = intval($quantity);
    if ($qty <= 0) {
        send_api_error(400, '数量不正确');
    }

    $spec_type = isset($product_row->spec_type) ? $product_row->spec_type : 'single';
    if ($spec_type === 'multiple') {
        $specs_arr = api_cart_decode_specs($specs);
        if (is_array($specs_arr)) {
            $specs_arr = api_cart_normalize_specs_array($specs_arr);
        }
        if (!is_array($specs_arr)) {
            send_api_error(400, '缺少规格');
        }

        $details = json_decode($product_row->spec_details ?? '', true);
        $matched = api_cart_match_spec_detail($details, $specs_arr);
        if (!$matched) {
            send_api_error(400, '规格不存在');
        }

        $detail = $matched[1];
        if (isset($detail['stock']) || isset($detail['stock_count'])) {
            $available = isset($detail['stock']) ? intval($detail['stock']) : intval($detail['stock_count']);
            if ($available < $qty) {
                send_api_error(400, '库存不足');
            }
            return;
        }
    }

    $stock = intval($product_row->stock ?? 0);
    if ($stock < $qty) {
        send_api_error(400, '库存不足');
    }
}

/**
 * 获取购物车
 */
function api_get_cart($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    $user_level = get_user_meta($user_id, 'user_level', true) ?: 'retail';

    $cart_table = TABLE_PREFIX . 'cart';
    $product_table = TABLE_PREFIX . 'posts';
    $fields_table = TABLE_PREFIX . 'model_fields_product';

    $query = "SELECT c.*, p.post_title, p.ID as product_id, mp.price, mp.unit_qty, mp.unit, mp.wholesale_price, mp.wholesale_vip_price, mp.original_price, mp.stock, mp.spec_type, mp.spec_details, mp.sale_status 
              FROM $cart_table c
              LEFT JOIN $product_table p ON c.product_id = p.ID
              LEFT JOIN $fields_table mp ON c.product_id = mp.post_id
              WHERE c.user_id = ?";
    
    $cart_items = $tododb->get_results($query, [$user_id]);

    $merged = [];
    $group = [];
    $dedupe_ops = [];
    foreach ($cart_items as $it) {
        $pid = isset($it->product_id) ? intval($it->product_id) : 0;
        [, $specs_json] = api_cart_normalize_specs_json($it->specs ?? null);
        $key = $pid . '|' . ($specs_json ?: '');
        if (!isset($group[$key])) {
            $group[$key] = [
                'keep_id' => intval($it->id),
                'product_id' => $pid,
                'specs_json' => $specs_json,
                'quantity' => intval($it->quantity),
                'rows' => [$it],
            ];
        } else {
            $group[$key]['quantity'] += intval($it->quantity);
            $group[$key]['rows'][] = $it;
        }
    }
    foreach ($group as $g) {
        $rows = $g['rows'];
        $keep = $rows[0];
        $keep_id = intval($keep->id);
        $merged[] = $keep;
        if (count($rows) > 1) {
            $dedupe_ops[] = [
                'keep_id' => $keep_id,
                'quantity' => $g['quantity'],
                'specs_json' => $g['specs_json'],
                'delete_ids' => array_values(array_map(function ($r) {
                    return intval($r->id);
                }, array_slice($rows, 1))),
            ];
        } elseif ($g['specs_json'] !== null && is_string($keep->specs) && $keep->specs !== $g['specs_json']) {
            $dedupe_ops[] = [
                'keep_id' => $keep_id,
                'quantity' => intval($keep->quantity),
                'specs_json' => $g['specs_json'],
                'delete_ids' => [],
            ];
        } elseif ($g['specs_json'] === null && is_string($keep->specs) && trim($keep->specs) !== '' && strtolower(trim($keep->specs)) !== 'null') {
            $dedupe_ops[] = [
                'keep_id' => $keep_id,
                'quantity' => intval($keep->quantity),
                'specs_json' => null,
                'delete_ids' => [],
            ];
        }
    }
    if (!empty($dedupe_ops)) {
        $cart_table = TABLE_PREFIX . 'cart';
        foreach ($dedupe_ops as $op) {
            $set = [
                'quantity' => intval($op['quantity']),
                'updated_at' => current_time('mysql'),
            ];
            $set['specs'] = $op['specs_json'];
            $tododb->update($cart_table, $set, ['id' => intval($op['keep_id']), 'user_id' => $user_id]);
            if (!empty($op['delete_ids'])) {
                foreach ($op['delete_ids'] as $did) {
                    $tododb->query("DELETE FROM {$cart_table} WHERE id = ? AND user_id = ?", [intval($did), $user_id]);
                }
            }
        }
        $cart_items = $merged;
    }

    $result = [];
    foreach ($cart_items as $item) {
        $sale_status = isset($item->sale_status) && $item->sale_status !== '' ? $item->sale_status : 'online';
        $stock = intval($item->stock);
        $is_on_sale = ($sale_status !== 'warehouse');
        $is_in_stock = ($stock > 0);
        // 获取分类ID以支持优惠券活动校验
        $category_ids = [];
        if (function_exists('get_the_terms')) {
            $terms = get_the_terms($item->product_id, 'category');
            if ($terms && is_array($terms)) {
                foreach ($terms as $term) {
                    $category_ids[] = intval($term->term_id);
                }
            }
        }

        $cart_item = [
            'id' => intval($item->id),
            'product_id' => intval($item->product_id),
            'title' => $item->post_title,
            'quantity' => intval($item->quantity),
            'specs' => api_cart_decode_specs($item->specs),
            'featured_image' => api_get_featured_image($item->product_id),
            'stock' => $stock,
            'unit_qty' => isset($item->unit_qty) ? (string)($item->unit_qty) : '1',
            'unit' => isset($item->unit) && $item->unit !== '' ? (string) $item->unit : 'piece',
            'sale_status' => $sale_status,
            'is_available' => ($is_on_sale && $is_in_stock),
            'category_ids' => $category_ids
        ];

        // 处理价格
        $cart_item['price'] = 0.0;
        if ($item->spec_type === 'multiple') {
            $details = json_decode($item->spec_details, true);
            $specs = api_cart_decode_specs($item->specs);
            if (is_array($specs)) {
                $specs = api_cart_normalize_specs_array($specs);
            }
            
            // 根据规格寻找价格
            $matched = api_cart_match_spec_detail($details, $specs);
            if ($matched) {
                $detail = $matched[1];
                $base_p = floatval($detail['users_price'] ?? ($detail['price'] ?? 0));
                $candidates = array();
                if (in_array($user_level, ['wholesale_vip', 'senior_vip'], true)) {
                    $tp = floatval($detail['wholesale_vip_price'] ?? ($detail['wholesale_price'] ?? 0));
                    if ($tp > 0) $candidates[] = $tp;
                } elseif (in_array($user_level, ['wholesale', 'vip'], true)) {
                    $tp = floatval($detail['wholesale_price'] ?? 0);
                    if ($tp > 0) $candidates[] = $tp;
                }
                $mem_p = function_exists('todo_get_product_member_price') ? todo_get_product_member_price($item->product_id, $base_p, $user_id) : $base_p;
                if ($mem_p > 0 && $mem_p < $base_p) {
                    $candidates[] = $mem_p;
                }
                $cart_item['price'] = !empty($candidates) ? min($candidates) : $base_p;
            } else {
                $cart_item['is_available'] = false;
                $cart_item['stock'] = 0;
            }
        } else {
            $base_p = floatval($item->price);
            $candidates = array();
            if (in_array($user_level, ['wholesale_vip', 'senior_vip'], true)) {
                $tp = floatval($item->wholesale_vip_price > 0 ? $item->wholesale_vip_price : $item->wholesale_price);
                if ($tp > 0) $candidates[] = $tp;
            } elseif (in_array($user_level, ['wholesale', 'vip'], true)) {
                $tp = floatval($item->wholesale_price);
                if ($tp > 0) $candidates[] = $tp;
            }
            $mem_p = function_exists('todo_get_product_member_price') ? todo_get_product_member_price($item->product_id, $base_p, $user_id) : $base_p;
            if ($mem_p > 0 && $mem_p < $base_p) {
                $candidates[] = $mem_p;
            }
            $cart_item['price'] = !empty($candidates) ? min($candidates) : $base_p;
        }

        $result[] = $cart_item;
    }

    return [
        'items' => $result,
        'total_count' => count($result),
        'user_level' => $user_level
    ];
}

/**
 * 添加到购物车
 */
function api_add_to_cart($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['product_id']) || !isset($params['quantity'])) {
        send_api_error(400, '缺少产品ID或数量');
    }

    $product_id = intval($params['product_id']);
    $quantity = intval($params['quantity']);
    $specs_obj = $params['specs'] ?? null;
    [$specs_arr, $specs] = api_cart_normalize_specs_json($specs_obj);

    $product_row = api_cart_get_product_for_purchase($product_id);
    if (!$product_row) {
        send_api_error(404, '商品已下架');
    }
    api_cart_require_stock($product_row, $quantity, $specs_arr);

    $cart_table = TABLE_PREFIX . 'cart';

    // 检查是否已存在
    $query = "SELECT id, quantity FROM $cart_table WHERE user_id = ? AND product_id = ?";
    if ($specs) {
        $query .= " AND specs = ?";
        $existing = $tododb->get_row($query, [$user_id, $product_id, $specs]);
    } else {
        $query .= " AND (specs IS NULL OR specs = '' OR specs = 'null')";
        $existing = $tododb->get_row($query, [$user_id, $product_id]);
    }

    if ($existing) {
        $new_quantity = $existing->quantity + $quantity;
        $tododb->update($cart_table, ['quantity' => $new_quantity, 'specs' => $specs, 'updated_at' => current_time('mysql')], ['id' => $existing->id]);
        $cart_id = $existing->id;
    } else {
        $tododb->insert($cart_table, [
            'user_id' => $user_id,
            'product_id' => $product_id,
            'quantity' => $quantity,
            'specs' => $specs,
            'created_at' => current_time('mysql')
        ]);
        $cart_id = $tododb->insert_id();
    }

    return [
        'id' => $cart_id,
        'message' => '已添加到购物车'
    ];
}

/**
 * 从购物车删除
 */
function api_delete_from_cart($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['id'])) {
        send_api_error(400, '缺少购物车项目ID');
    }

    $cart_id = intval($params['id']);
    $cart_table = TABLE_PREFIX . 'cart';

    $tododb->query("DELETE FROM $cart_table WHERE id = ? AND user_id = ?", [$cart_id, $user_id]);

    return [
        'message' => '已从购物车删除'
    ];
}

function api_update_cart($params) {
    global $tododb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }

    if (!isset($params['id'])) {
        send_api_error(400, '缺少购物车项目ID');
    }
    if (!isset($params['quantity'])) {
        send_api_error(400, '缺少数量');
    }

    $cart_id = intval($params['id']);
    $quantity = intval($params['quantity']);

    $cart_table = TABLE_PREFIX . 'cart';

    $existing = $tododb->get_row("SELECT id FROM {$cart_table} WHERE id = ? AND user_id = ? LIMIT 1", [$cart_id, $user_id]);
    if (!$existing) {
        send_api_error(404, '购物车项目不存在');
    }

    if ($quantity <= 0) {
        $tododb->query("DELETE FROM {$cart_table} WHERE id = ? AND user_id = ?", [$cart_id, $user_id]);
        return [
            'id' => $cart_id,
            'quantity' => 0,
            'message' => '已从购物车删除'
        ];
    }

    $row = $tododb->get_row("SELECT product_id, specs FROM {$cart_table} WHERE id = ? AND user_id = ? LIMIT 1", [$cart_id, $user_id]);
    if (!$row) {
        send_api_error(404, '购物车项目不存在');
    }
    $product_row = api_cart_get_product_for_purchase(intval($row->product_id));
    if (!$product_row) {
        send_api_error(404, '商品已下架');
    }
    api_cart_require_stock($product_row, $quantity, $row->specs);

    $tododb->update($cart_table, ['quantity' => $quantity, 'updated_at' => current_time('mysql')], ['id' => $cart_id, 'user_id' => $user_id]);

    return [
        'id' => $cart_id,
        'quantity' => $quantity,
        'message' => '已更新'
    ];
}

// 注册路由
register_api_route('cart', 'GET', 'api_get_cart');
register_api_route('cart', 'POST', 'api_add_to_cart');
register_api_route('cart', 'PUT', 'api_update_cart');
register_api_route('cart', 'DELETE', 'api_delete_from_cart');

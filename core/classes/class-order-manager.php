<?php
/**
 * TodoCMS 订单管理器
 * 
 * 管理强大的订单系统，具备基础订单处理，产品售后可以增加自定义字段
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH') || !defined('TODO_LOADED')) {
    exit('Direct access not permitted');
}

class Todo_Order_Manager {
    
    public static function restore_stock($product_id, $quantity, $specs = array()) {
        try {
            global $tododb;
            $product_id = intval($product_id);
            $quantity = intval($quantity);
            if ($product_id <= 0 || $quantity <= 0) {
                return false;
            }

            $fields_table = DB_PREFIX . 'model_fields_product';
            $product = $tododb->get_row("SELECT stock, spec_type, spec_details FROM {$fields_table} WHERE post_id = ? LIMIT 1", [$product_id]);
            if (!$product) {
                return false;
            }

            // 多规格还原
            if ($product->spec_type === 'multiple' && !empty($product->spec_details)) {
                $details = json_decode($product->spec_details, true);
                if (is_array($details) && !empty($details)) {
                    $matched_idx = null;

                    // 参数解析与标准化
                    $specs_arr = [];
                    if (is_array($specs)) {
                        $specs_arr = $specs;
                    } else if (is_string($specs) && trim($specs) !== '') {
                        $raw = trim($specs);
                        // 如果传入形如 "v0:红色 v1:XL" 或 JSON 字符串
                        if ($raw[0] === '{') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) $specs_arr = $decoded;
                        }
                        if (empty($specs_arr)) {
                            $parts = explode(' ', $raw);
                            foreach ($parts as $p) {
                                if (strpos($p, ':') !== false) {
                                    list($k, $v) = explode(':', $p, 2);
                                    $specs_arr[trim($k)] = trim($v);
                                }
                            }
                        }
                    }

                    if (function_exists('api_orders_match_spec_detail') && !empty($specs_arr)) {
                        $matched = api_orders_match_spec_detail($details, $specs_arr);
                        if ($matched) {
                            $matched_idx = $matched[0];
                        }
                    }

                    if ($matched_idx !== null && isset($details[$matched_idx])) {
                        if (function_exists('api_orders_adjust_spec_detail_stock')) {
                            api_orders_adjust_spec_detail_stock($product_id, $matched_idx, $quantity, $fields_table, (string)$product->spec_details);
                        } else {
                            $stock_key = isset($details[$matched_idx]['stock']) ? 'stock' : (isset($details[$matched_idx]['stock_count']) ? 'stock_count' : 'stock');
                            $details[$matched_idx][$stock_key] = intval($details[$matched_idx][$stock_key] ?? 0) + $quantity;
                            $new_spec_details = json_encode($details, JSON_UNESCAPED_UNICODE);
                            $tododb->update($fields_table, ['spec_details' => $new_spec_details], ['post_id' => $product_id]);
                        }
                    }
                }
            }

            // 无论单规格还是多规格，均恢复商品主表库存 stock
            if (function_exists('api_orders_adjust_product_inventory')) {
                api_orders_adjust_product_inventory($product_id, $quantity, -$quantity, $fields_table, false);
            } else {
                $tododb->query("UPDATE {$fields_table} SET stock = stock + ?, actual_sales = GREATEST(0, actual_sales - ?) WHERE post_id = ?", [$quantity, $quantity, $product_id]);
            }

            return true;

        } catch (Exception $e) {
            error_log('恢复库存失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 格式化规格名称
     * 
     * @param string $spec_name 规格名称 (例如 "v0:红色 v1:XL")
     * @return string 格式化后的名称 (例如 "红色 / XL")
     */
    public static function format_spec_name($spec_name) {
        if (empty($spec_name)) {
            return '';
        }
        
        $spec_name = trim((string)$spec_name);

        if (strpos($spec_name, ' / ') !== false) {
            return $spec_name;
        }

        if ((strpos($spec_name, ',') !== false || strpos($spec_name, '，') !== false) && (strpos($spec_name, ':') !== false || strpos($spec_name, '：') !== false)) {
            $vals = array();
            if (preg_match_all('/[:：]\s*([^,，]+)\s*/u', $spec_name, $m)) {
                foreach ($m[1] as $v) {
                    $v = trim((string) $v);
                    $v = trim($v, " \t\n\r\0\x0B,，/");
                    if ($v !== '') {
                        $vals[] = $v;
                    }
                }
            }
            if (!empty($vals)) {
                return implode(' / ', $vals);
            }
        }
        
        // 如果包含冒号，尝试解析 v0:Val1 v1:Val2 格式
        if (strpos($spec_name, ':') !== false) {
            $parts = explode(' ', $spec_name);
            $formatted_parts = array();
            foreach ($parts as $part) {
                $colon_pos = strpos($part, ':');
                if ($colon_pos !== false) {
                    $val = substr($part, $colon_pos + 1);
                    if ($val !== '') {
                        $formatted_parts[] = $val;
                    }
                } else {
                    $formatted_parts[] = $part;
                }
            }
            if (!empty($formatted_parts)) {
                $out = array();
                foreach ($formatted_parts as $v) {
                    $v = trim((string) $v);
                    $v = trim($v, " \t\n\r\0\x0B,，/");
                    if ($v !== '') {
                        $out[] = $v;
                    }
                }
                return implode(' / ', $out);
            }
        }
        
        return $spec_name;
    }

    public static function get_logistics_info($tracking_no, $express_company = '', $args = array()) {
        if (self::$tododb === null) {
            self::init();
        }

        $tracking_no = trim((string) $tracking_no);
        $express_company = trim((string) $express_company);
        if ($tracking_no === '') {
            return array(
                'success' => false,
                'message' => '运单号为空',
                'tracking_no' => $tracking_no,
                'express_company' => $express_company,
                'company_code' => '',
                'provider' => '',
                'status' => 'unknown',
                'status_text' => '',
                'traces' => array(),
                'last_query_at' => null,
            );
        }

        $args = is_array($args) ? $args : array();
        $force = !empty($args['force']);
        $persist = !empty($args['persist']);
        $order_id = isset($args['order_id']) ? intval($args['order_id']) : 0;
        $receiver_phone = isset($args['receiver_phone']) ? trim((string) $args['receiver_phone']) : '';

        $settings = self::get_logistics_tracking_settings();
        if (empty($settings['enabled'])) {
            return array(
                'success' => false,
                'message' => '物流查询未启用',
                'tracking_no' => $tracking_no,
                'express_company' => $express_company,
                'company_code' => self::resolve_shipping_company_code($express_company),
                'provider' => '',
                'status' => 'unknown',
                'status_text' => '',
                'traces' => array(),
                'last_query_at' => null,
            );
        }

        $provider = isset($settings['provider']) ? (string) $settings['provider'] : 'kuaidi100';
        $cache_minutes = isset($settings['cache_minutes']) ? intval($settings['cache_minutes']) : 10;
        if ($cache_minutes < 0) {
            $cache_minutes = 0;
        }

        $company_code = self::resolve_shipping_company_code($express_company);

        if (!$force && $cache_minutes > 0) {
            $cached = self::get_logistics_cache($order_id, $tracking_no, $company_code, $cache_minutes);
            if (!empty($cached)) {
                return $cached;
            }
        }

        $result = self::query_logistics_provider($provider, $settings, $company_code, $tracking_no, $receiver_phone);
        if (!is_array($result)) {
            $result = array('success' => false, 'message' => '物流查询失败');
        }
        $result = array_merge(array(
            'success' => false,
            'message' => '',
            'tracking_no' => $tracking_no,
            'express_company' => $express_company,
            'company_code' => $company_code,
            'provider' => $provider,
            'status' => 'unknown',
            'status_text' => '',
            'traces' => array(),
            'last_query_at' => current_time('mysql'),
        ), $result);

        if ($persist && $order_id > 0) {
            self::persist_logistics_cache($order_id, $result);
        }

        return $result;
    }

    public static function format_logistics_traces_text($traces) {
        if (empty($traces) || !is_array($traces)) {
            return '';
        }
        $lines = array();
        foreach ($traces as $tr) {
            if (is_array($tr)) {
                $time = $tr['time'] ?? ($tr['datetime'] ?? '');
                $status = $tr['status'] ?? ($tr['context'] ?? ($tr['remark'] ?? ''));
                $loc = !empty($tr['location']) ? ' [' . $tr['location'] . ']' : '';
                $line = trim($time . ' ' . $status . $loc);
                if ($line !== '') {
                    $lines[] = $line;
                }
            } elseif (is_string($tr) && trim($tr) !== '') {
                $lines[] = trim($tr);
            }
        }
        return implode("\n", $lines);
    }

    private static function get_logistics_tracking_settings() {
        if (!function_exists('todo_shop_get_service_settings')) {
            if (class_exists('Todo_Function_Loader')) {
                Todo_Function_Loader::load_function('shop-service-settings');
            } else {
                $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
                if ($p && file_exists($p)) {
                    require_once $p;
                }
            }
        }
        if (!function_exists('todo_shop_get_service_settings')) {
            return array('enabled' => 0);
        }
        $service = todo_shop_get_service_settings();
        $lt = isset($service['logistics_tracking']) && is_array($service['logistics_tracking']) ? $service['logistics_tracking'] : array('enabled' => 0);
        return $lt;
    }

    private static function ensure_logistics_schedule() {
        if (!class_exists('Todo_Scheduler')) {
            return;
        }
        $settings = self::get_logistics_tracking_settings();
        $hook = 'todo_logistics_poll';
        if (empty($settings['enabled']) || empty($settings['auto_poll'])) {
            Todo_Scheduler::clearHook($hook);
            return;
        }

        $minutes = isset($settings['poll_interval_minutes']) ? intval($settings['poll_interval_minutes']) : 60;
        if ($minutes < 5) {
            $minutes = 5;
        }

        $recurrence = 'hourly';
        if ($minutes <= 5) {
            $recurrence = 'five_minutes';
        } elseif ($minutes <= 10) {
            $recurrence = 'ten_minutes';
        } elseif ($minutes <= 60) {
            $recurrence = 'hourly';
        } elseif ($minutes <= 720) {
            $recurrence = 'twicedaily';
        } else {
            $recurrence = 'daily';
        }

        $next = Todo_Scheduler::nextScheduled($hook);
        if ($next <= 0) {
            Todo_Scheduler::scheduleEvent(time() + 60, $recurrence, $hook);
            return;
        }
        Todo_Scheduler::scheduleEvent($next, $recurrence, $hook);
    }

    /**
     * 维护自动确认收货定时任务
     */
    public static function ensure_auto_confirm_schedule() {
        if (!class_exists('Todo_Scheduler')) {
            return;
        }
        $hook = 'todo_auto_confirm_orders';
        $service = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
        $days = isset($service['auto_confirm_days']) ? intval($service['auto_confirm_days']) : 7;

        // 如果设置为 0，表示禁用自动确认收货
        if ($days <= 0) {
            Todo_Scheduler::clearHook($hook);
            return;
        }

        $next = Todo_Scheduler::nextScheduled($hook);
        if ($next <= 0) {
            // 每小时检查一次
            Todo_Scheduler::scheduleEvent(time() + 300, 'hourly', $hook);
            return;
        }
        Todo_Scheduler::scheduleEvent($next, 'hourly', $hook);
    }

    /**
     * 执行自动确认收货检查
     */
    public static function process_auto_confirm_orders() {
        if (self::$tododb === null) {
            self::init();
        }
        if (self::$tododb === null) {
            return;
        }

        $service = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
        $days = isset($service['auto_confirm_days']) ? intval($service['auto_confirm_days']) : 7;
        if ($days <= 0) {
            return;
        }

        $orders_table = DB_PREFIX . 'orders';
        $threshold_time = date('Y-m-d H:i:s', time() - ($days * 86400));

        // 查询处于已发货(shipped)或已送达(delivered)状态且未在售后处理中的超时订单
        // 优先根据 shipped_at 判定，若 shipped_at 为空则根据 updated_at 判定
        $query = "SELECT id, order_no, shipped_at, updated_at, status, payment_status 
                  FROM {$orders_table} 
                  WHERE status IN ('shipped', 'delivered') 
                    AND payment_status NOT IN ('refunding', 'refunded') 
                    AND status NOT IN ('refund', 'refunded', 'cancelled')
                    AND (
                        (shipped_at IS NOT NULL AND shipped_at <= ?)
                        OR (shipped_at IS NULL AND updated_at <= ?)
                    )
                  ORDER BY id ASC 
                  LIMIT 50";

        $overdue_orders = self::$tododb->get_results($query, [$threshold_time, $threshold_time], 'ARRAY_A');
        if (empty($overdue_orders) || !is_array($overdue_orders)) {
            return;
        }

        foreach ($overdue_orders as $ord) {
            $oid = intval($ord['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }

            // 更新订单状态为已完成，并记录系统自动确认日志
            $log_note = sprintf('发货超过 %d 天，系统自动确认收货', $days);
            self::update_order_status($oid, self::STATUS_COMPLETED, 0, $log_note);
        }
    }

    public static function poll_logistics() {
        if (self::$tododb === null) {
            self::init();
        }
        if (self::$tododb === null) {
            return;
        }

        $settings = self::get_logistics_tracking_settings();
        if (empty($settings['enabled']) || empty($settings['auto_poll'])) {
            return;
        }
        if (!(method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_logistics'))) {
            return;
        }

        $minutes = isset($settings['poll_interval_minutes']) ? intval($settings['poll_interval_minutes']) : 60;
        if ($minutes < 5) {
            $minutes = 5;
        }
        $threshold = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $table = DB_PREFIX . 'order_logistics';

        $rows = self::$tododb->get_results(
            "SELECT order_id, express_company, tracking_number, status, updated_at FROM {$table} WHERE tracking_number <> '' AND (status IS NULL OR status <> 'delivered') AND updated_at < ? ORDER BY updated_at ASC LIMIT 20",
            array($threshold),
            'ARRAY_A'
        );
        if (empty($rows) || !is_array($rows)) {
            return;
        }

        foreach ($rows as $r) {
            $oid = intval($r['order_id'] ?? 0);
            $tn = (string) ($r['tracking_number'] ?? '');
            $ec = (string) ($r['express_company'] ?? '');
            if ($oid <= 0 || $tn === '') {
                continue;
            }
            self::get_logistics_info($tn, $ec, array(
                'force' => true,
                'persist' => true,
                'order_id' => $oid,
            ));
        }
    }

    private static function resolve_shipping_company_code($express_company) {
        $express_company = trim((string) $express_company);
        if ($express_company === '' || self::$tododb === null) {
            return $express_company;
        }

        $table = DB_PREFIX . 'shipping_companies';
        try {
            $row = self::$tododb->get_row("SELECT code FROM {$table} WHERE code = ? LIMIT 1", array($express_company), 'ARRAY_A');
            if (!empty($row['code'])) {
                return (string) $row['code'];
            }
            $row = self::$tododb->get_row("SELECT code FROM {$table} WHERE name = ? LIMIT 1", array($express_company), 'ARRAY_A');
            if (!empty($row['code'])) {
                return (string) $row['code'];
            }
        } catch (Exception $e) {
        }

        return $express_company;
    }

    private static function get_logistics_cache($order_id, $tracking_no, $company_code, $cache_minutes) {
        if (self::$tododb === null) {
            return null;
        }
        if (!(method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_logistics'))) {
            return null;
        }

        $table = DB_PREFIX . 'order_logistics';
        $row = null;
        if ($order_id > 0) {
            $row = self::$tododb->get_row("SELECT * FROM {$table} WHERE order_id = ? LIMIT 1", array($order_id), 'ARRAY_A');
        }
        if (!$row) {
            $row = self::$tododb->get_row(
                "SELECT * FROM {$table} WHERE tracking_number = ? AND (express_company = ? OR express_company = ?) ORDER BY updated_at DESC LIMIT 1",
                array($tracking_no, $company_code, $company_code),
                'ARRAY_A'
            );
        }
        if (!$row) {
            $row = self::$tododb->get_row(
                "SELECT * FROM {$table} WHERE tracking_number = ? ORDER BY updated_at DESC LIMIT 1",
                array($tracking_no),
                'ARRAY_A'
            );
        }
        if (!$row || empty($row['tracking_info']) || empty($row['updated_at'])) {
            return null;
        }

        $updated_ts = strtotime($row['updated_at']);
        if ($updated_ts && (time() - $updated_ts) <= ($cache_minutes * 60)) {
            $decoded = json_decode((string) $row['tracking_info'], true);
            if (is_array($decoded)) {
                $decoded['success'] = isset($decoded['success']) ? (bool) $decoded['success'] : true;
                $decoded['tracking_no'] = $decoded['tracking_no'] ?? $tracking_no;
                $decoded['company_code'] = $decoded['company_code'] ?? $company_code;
                $decoded['express_company'] = $decoded['express_company'] ?? ($row['express_company'] ?? '');
                $decoded['last_query_at'] = $decoded['last_query_at'] ?? $row['updated_at'];
                return $decoded;
            }
            return array(
                'success' => true,
                'message' => '',
                'tracking_no' => $tracking_no,
                'express_company' => $row['express_company'] ?? '',
                'company_code' => $company_code,
                'provider' => '',
                'status' => $row['status'] ?? 'unknown',
                'status_text' => '',
                'traces' => array(),
                'last_query_at' => $row['updated_at'],
                'tracking_info' => $row['tracking_info'],
            );
        }

        return null;
    }

    private static function persist_logistics_cache($order_id, $payload) {
        if (self::$tododb === null) {
            return false;
        }
        if (!(method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_logistics'))) {
            return false;
        }

        $table = DB_PREFIX . 'order_logistics';
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $status = isset($payload['status']) ? (string) $payload['status'] : 'unknown';
        $express_company = isset($payload['company_code']) && $payload['company_code'] !== '' ? (string) $payload['company_code'] : (string) ($payload['express_company'] ?? '');
        $tracking_no = (string) ($payload['tracking_no'] ?? '');
        $tracking_info = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($tracking_info === false) {
            $tracking_info = '';
        }

        $existing = self::$tododb->get_row("SELECT id FROM {$table} WHERE order_id = ? LIMIT 1", array($order_id), 'ARRAY_A');
        if ($existing) {
            return self::$tododb->query(
                "UPDATE {$table} SET express_company = ?, tracking_number = ?, tracking_info = ?, status = ? WHERE order_id = ?",
                array($express_company, $tracking_no, $tracking_info, $status, $order_id)
            );
        }

        return self::$tododb->query(
            "INSERT INTO {$table} (order_id, express_company, tracking_number, tracking_info, status) VALUES (?, ?, ?, ?, ?)",
            array($order_id, $express_company, $tracking_no, $tracking_info, $status)
        );
    }

    private static function query_logistics_provider($provider, $settings, $company_code, $tracking_no, $receiver_phone = '') {
        $provider = strtolower((string) $provider);
        $company_code_upper = strtoupper(trim((string) $company_code));
        $tracking_no_upper = strtoupper(trim((string) $tracking_no));
        $is_sandbox = !empty($settings['sandbox']);

        // 如果是 UPS，或者单号为 UPS 测试号/1Z 开头，或公司编码为 UPS
        if ($provider === 'ups' || $company_code_upper === 'UPS' || strpos($tracking_no_upper, '1Z') === 0 || strpos($tracking_no_upper, 'TEST_UPS') === 0) {
            return self::query_ups($settings['ups'] ?? array(), $company_code, $tracking_no, $is_sandbox);
        }

        // 如果是 DHL，或者单号为 DHL 测试号，或公司编码为 DHL
        if ($provider === 'dhl' || $company_code_upper === 'DHL' || $company_code_upper === 'DHL-EXPRESS' || strpos($tracking_no_upper, 'TEST_DHL') === 0 || $tracking_no_upper === '7777777777' || $tracking_no_upper === '1234567890') {
            return self::query_dhl($settings['dhl'] ?? array(), $company_code, $tracking_no, $is_sandbox);
        }

        if ($provider === 'kdniao') {
            $res = self::query_kdniao($settings['kdniao'] ?? array(), $company_code, $tracking_no);
            if (!empty($res['success']) || !$is_sandbox) {
                return $res;
            }
        } elseif ($provider === 'cainiao') {
            $res = self::query_cainiao_top($settings['cainiao'] ?? array(), $company_code, $tracking_no);
            if (!empty($res['success']) || !$is_sandbox) {
                return $res;
            }
        } else {
            $res = self::query_kuaidi100($settings['kuaidi100'] ?? array(), $company_code, $tracking_no, $receiver_phone);
            if (!empty($res['success']) || !$is_sandbox) {
                return $res;
            }
        }

        // 沙盒模式回退：若主接口未配置或报错，且开启了沙盒模式，则根据单号自动返回通用沙盒模拟轨迹
        if ($is_sandbox) {
            return self::get_generic_sandbox_logistics($company_code, $tracking_no);
        }

        return isset($res) && is_array($res) ? $res : array('success' => false, 'message' => '未找到对应的物流接口服务');
    }

    private static function query_ups($cfg, $company_code, $tracking_no, $is_sandbox = false) {
        $client_id = isset($cfg['client_id']) ? trim((string)$cfg['client_id']) : '';
        $client_secret = isset($cfg['client_secret']) ? trim((string)$cfg['client_secret']) : '';
        $access_token = isset($cfg['access_token']) ? trim((string)$cfg['access_token']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string)$cfg['endpoint']) : 'https://onlinetools.ups.com/api/shipments/v1/transports/details/';
        
        $tracking_upper = strtoupper(trim((string)$tracking_no));
        $is_test_num = in_array($tracking_upper, array('1Z9999999999999999', '1Z9999999999999998', '1Z9999999999999997', '1Z9999999999999996')) || strpos($tracking_upper, 'TEST') === 0;

        // 若为沙盒模式且无凭证，或为测试单号，返回包含全节点流程的模拟数据
        if (($is_sandbox && ($client_id === '' && $access_token === '')) || $is_test_num) {
            $status = ($tracking_upper === '1Z9999999999999998') ? 'shipping' : 'delivered';
            $status_text = ($status === 'delivered') ? 'Delivered' : 'In Transit';
            $now = current_time('timestamp');
            
            if ($status === 'delivered') {
                $traces = array(
                    array('time' => date('Y-m-d H:i:s', $now), 'status' => 'Delivered - Left at Front Door', 'location' => 'Louisville, KY, US'),
                    array('time' => date('Y-m-d H:i:s', $now - 7200), 'status' => 'Out for Delivery, UPS Facility', 'location' => 'Louisville, KY, US'),
                    array('time' => date('Y-m-d H:i:s', $now - 43200), 'status' => 'Arrived at UPS Air Hub', 'location' => 'Louisville, KY, US'),
                    array('time' => date('Y-m-d H:i:s', $now - 86400), 'status' => 'Departed UPS International Gateway', 'location' => 'Shanghai, CN'),
                    array('time' => date('Y-m-d H:i:s', $now - 129600), 'status' => 'Shipment Picked Up & Order Processed', 'location' => 'Shenzhen, CN'),
                );
            } else {
                $traces = array(
                    array('time' => date('Y-m-d H:i:s', $now), 'status' => 'In Transit: Customs Clearance Completed', 'location' => 'Anchorage, AK, US'),
                    array('time' => date('Y-m-d H:i:s', $now - 28800), 'status' => 'Departed International Hub', 'location' => 'Shanghai, CN'),
                    array('time' => date('Y-m-d H:i:s', $now - 86400), 'status' => 'Shipment Received at UPS Facility', 'location' => 'Shenzhen, CN'),
                    array('time' => date('Y-m-d H:i:s', $now - 100000), 'status' => 'Shipper Created Label', 'location' => 'Shenzhen, CN'),
                );
            }

            return array(
                'success' => true,
                'message' => 'UPS 沙盒测试模式',
                'status' => $status,
                'status_text' => $status_text,
                'traces' => $traces,
                'raw' => array('provider' => 'ups', 'sandbox' => true),
            );
        }

        if ($access_token === '' && ($client_id === '' || $client_secret === '')) {
            return array('success' => false, 'message' => 'UPS API 密钥未配置');
        }

        $url = rtrim($endpoint, '/') . '/' . rawurlencode($tracking_no);
        $headers = array(
            'Accept: application/json',
            'transId: todo_' . time(),
            'transactionSrc: TodoCMS',
        );
        if ($access_token !== '') {
            $headers[] = 'Authorization: Bearer ' . $access_token;
        }

        $resp = self::http_request('GET', $url, '', $headers, 15);
        $json = is_array($resp) ? ($resp['json'] ?? null) : null;

        if (is_array($json) && !empty($json['trackResponse']['shipment'])) {
            $shipment = reset($json['trackResponse']['shipment']);
            $package = $shipment['package'][0] ?? array();
            $activity = $package['activity'] ?? array();
            $traces = array();
            foreach ((array)$activity as $act) {
                $date = $act['date'] ?? '';
                $time = $act['time'] ?? '';
                $formatted_time = ($date !== '') ? date('Y-m-d H:i:s', strtotime($date . ' ' . $time)) : '';
                $status_desc = $act['status']['description'] ?? ($act['activityScan'] ?? '');
                $loc = trim(($act['location']['address']['city'] ?? '') . ' ' . ($act['location']['address']['countryCode'] ?? ''));
                $traces[] = array('time' => $formatted_time, 'status' => $status_desc, 'location' => $loc);
            }
            $status_code = $package['activity'][0]['status']['type'] ?? 'D';
            $status = ($status_code === 'D') ? 'delivered' : 'shipping';
            return array(
                'success' => true,
                'message' => '',
                'status' => $status,
                'status_text' => ($status === 'delivered') ? 'Delivered' : 'In Transit',
                'traces' => $traces,
                'raw' => array('provider' => 'ups'),
            );
        }

        return array('success' => false, 'message' => $json['response']['errors'][0]['message'] ?? 'UPS 查询失败');
    }

    private static function query_dhl($cfg, $company_code, $tracking_no, $is_sandbox = false) {
        $api_key = isset($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string)$cfg['endpoint']) : 'https://express.api.dhl.com/mydhlapi/shipments/';

        $tracking_upper = strtoupper(trim((string)$tracking_no));
        $is_test_num = in_array($tracking_upper, array('7777777777', '1234567890')) || strpos($tracking_upper, 'TEST') === 0;

        // 若为沙盒模式且无凭证，或为测试单号，返回包含全节点流程的模拟数据
        if (($is_sandbox && $api_key === '') || $is_test_num) {
            $status = ($tracking_upper === '7777777777') ? 'delivered' : 'shipping';
            $status_text = ($status === 'delivered') ? 'Delivered' : 'In Transit';
            $now = current_time('timestamp');

            if ($status === 'delivered') {
                $traces = array(
                    array('time' => date('Y-m-d H:i:s', $now), 'status' => 'Delivered - Signed by Recipient', 'location' => 'Frankfurt, DE'),
                    array('time' => date('Y-m-d H:i:s', $now - 5400), 'status' => 'With delivery courier', 'location' => 'Frankfurt, DE'),
                    array('time' => date('Y-m-d H:i:s', $now - 36000), 'status' => 'Processed at DHL Express Hub Leipzig', 'location' => 'Leipzig, DE'),
                    array('time' => date('Y-m-d H:i:s', $now - 72000), 'status' => 'Departed Facility in Hong Kong Hub', 'location' => 'Hong Kong, HK'),
                    array('time' => date('Y-m-d H:i:s', $now - 108000), 'status' => 'Shipment Picked Up', 'location' => 'Hong Kong, HK'),
                );
            } else {
                $traces = array(
                    array('time' => date('Y-m-d H:i:s', $now), 'status' => 'Processed at DHL Hub Leipzig', 'location' => 'Leipzig, DE'),
                    array('time' => date('Y-m-d H:i:s', $now - 21600), 'status' => 'Customs clearance status updated', 'location' => 'Leipzig, DE'),
                    array('time' => date('Y-m-d H:i:s', $now - 64800), 'status' => 'Departed Facility in Shenzhen', 'location' => 'Shenzhen, CN'),
                    array('time' => date('Y-m-d H:i:s', $now - 90000), 'status' => 'Shipment information received', 'location' => 'Shenzhen, CN'),
                );
            }

            return array(
                'success' => true,
                'message' => 'DHL 沙盒测试模式',
                'status' => $status,
                'status_text' => $status_text,
                'traces' => $traces,
                'raw' => array('provider' => 'dhl', 'sandbox' => true),
            );
        }

        if ($api_key === '') {
            return array('success' => false, 'message' => 'DHL API Key 未配置');
        }

        $url = rtrim($endpoint, '/') . '/' . rawurlencode($tracking_no) . '/tracking';
        if ($is_sandbox && strpos($url, '/test/') === false) {
            $url = 'https://express.api.dhl.com/mydhlapi/test/shipments/' . rawurlencode($tracking_no) . '/tracking';
        }

        $headers = array(
            'Accept: application/json',
            'DHL-API-Key: ' . $api_key,
        );

        $resp = self::http_request('GET', $url, '', $headers, 15);
        $json = is_array($resp) ? ($resp['json'] ?? null) : null;

        if (is_array($json) && !empty($json['shipments'])) {
            $shipment = reset($json['shipments']);
            $events = $shipment['events'] ?? array();
            $traces = array();
            foreach ((array)$events as $ev) {
                $time = $ev['timestamp'] ?? '';
                $formatted_time = ($time !== '') ? date('Y-m-d H:i:s', strtotime($time)) : '';
                $status_desc = $ev['description'] ?? '';
                $loc = trim(($ev['location']['address']['addressLocality'] ?? '') . ' ' . ($ev['location']['address']['countryCode'] ?? ''));
                $traces[] = array('time' => $formatted_time, 'status' => $status_desc, 'location' => $loc);
            }
            $status_str = strtolower((string)($shipment['status']['status'] ?? ''));
            $status = (strpos($status_str, 'delivered') !== false) ? 'delivered' : 'shipping';
            return array(
                'success' => true,
                'message' => '',
                'status' => $status,
                'status_text' => $shipment['status']['status'] ?? ($status === 'delivered' ? 'Delivered' : 'In Transit'),
                'traces' => $traces,
                'raw' => array('provider' => 'dhl'),
            );
        }

        return array('success' => false, 'message' => $json['detail'] ?? ($json['message'] ?? 'DHL 查询失败'));
    }

    private static function get_generic_sandbox_logistics($company_code, $tracking_no) {
        $now = current_time('timestamp');
        return array(
            'success' => true,
            'message' => '通用沙盒模拟结果',
            'status' => 'shipping',
            'status_text' => 'In Transit (Sandbox)',
            'traces' => array(
                array('time' => date('Y-m-d H:i:s', $now), 'status' => '【沙盒测试】包裹已到达转运中心 (Company: ' . $company_code . ')', 'location' => 'Transit Hub'),
                array('time' => date('Y-m-d H:i:s', $now - 28800), 'status' => '【沙盒测试】国际清关完成，正在转运中', 'location' => 'Customs Area'),
                array('time' => date('Y-m-d H:i:s', $now - 86400), 'status' => '【沙盒测试】包裹已成功揽收 (No: ' . $tracking_no . ')', 'location' => 'Origin Depot'),
            ),
            'raw' => array('provider' => 'sandbox_mock'),
        );
    }

    private static function query_kuaidi100($cfg, $company_code, $tracking_no, $receiver_phone = '') {
        $customer = isset($cfg['customer']) ? trim((string) $cfg['customer']) : '';
        $key = isset($cfg['key']) ? trim((string) $cfg['key']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string) $cfg['endpoint']) : 'https://poll.kuaidi100.com/poll/query.do';
        if ($customer === '' || $key === '') {
            return array('success' => false, 'message' => '快递100未配置');
        }

        $param = array(
            'com' => $company_code,
            'num' => $tracking_no,
        );
        if ($receiver_phone !== '') {
            $param['phone'] = $receiver_phone;
        }
        $param_json = json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($param_json === false) {
            $param_json = '{}';
        }
        $sign = strtoupper(md5($param_json . $key . $customer));

        $resp = self::http_request('POST', $endpoint, http_build_query(array(
            'customer' => $customer,
            'sign' => $sign,
            'param' => $param_json,
        )), array('Content-Type: application/x-www-form-urlencoded'), 12);

        $json = is_array($resp) ? ($resp['json'] ?? null) : null;
        if (!is_array($json)) {
            return array('success' => false, 'message' => '快递100返回异常');
        }

        $ok = isset($json['result']) ? (bool) $json['result'] : (isset($json['status']) ? ((string) $json['status'] === '200') : false);
        if (!$ok) {
            $msg = isset($json['message']) ? (string) $json['message'] : (isset($json['msg']) ? (string) $json['msg'] : '快递100查询失败');
            return array('success' => false, 'message' => $msg);
        }

        $traces = array();
        if (!empty($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $time = isset($it['time']) ? (string) $it['time'] : '';
                $context = isset($it['context']) ? (string) $it['context'] : '';
                $location = isset($it['location']) ? (string) $it['location'] : '';
                if ($time === '' && isset($it['ftime'])) {
                    $time = (string) $it['ftime'];
                }
                $traces[] = array('time' => $time, 'status' => $context, 'location' => $location);
            }
        }

        $status_map = array('0' => 'created', '1' => 'shipping', '2' => 'exception', '3' => 'delivered', '4' => 'delivered');
        $state = isset($json['state']) ? (string) $json['state'] : '';
        $status = isset($status_map[$state]) ? $status_map[$state] : 'shipping';

        return array(
            'success' => true,
            'message' => '',
            'status' => $status,
            'status_text' => isset($json['status']) ? (string) $json['status'] : '',
            'traces' => $traces,
            'raw' => array('provider' => 'kuaidi100'),
        );
    }

    private static function query_kdniao($cfg, $company_code, $tracking_no) {
        $ebusiness_id = isset($cfg['ebusiness_id']) ? trim((string) $cfg['ebusiness_id']) : '';
        $app_key = isset($cfg['app_key']) ? trim((string) $cfg['app_key']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string) $cfg['endpoint']) : 'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx';
        $request_type = isset($cfg['request_type']) ? preg_replace('/[^0-9]/', '', (string) $cfg['request_type']) : '1002';
        if ($request_type === '') {
            $request_type = '1002';
        }
        if ($ebusiness_id === '' || $app_key === '') {
            return array('success' => false, 'message' => '快递鸟未配置');
        }

        $request_data = json_encode(array(
            'ShipperCode' => $company_code,
            'LogisticCode' => $tracking_no,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($request_data === false) {
            $request_data = '{}';
        }

        $data_sign = base64_encode(md5($request_data . $app_key, true));

        $resp = self::http_request('POST', $endpoint, http_build_query(array(
            'RequestData' => $request_data,
            'EBusinessID' => $ebusiness_id,
            'RequestType' => $request_type,
            'DataSign' => $data_sign,
            'DataType' => '2',
        )), array('Content-Type: application/x-www-form-urlencoded'), 12);

        $json = is_array($resp) ? ($resp['json'] ?? null) : null;
        if (!is_array($json)) {
            return array('success' => false, 'message' => '快递鸟返回异常');
        }

        if (empty($json['Success'])) {
            $msg = isset($json['Reason']) ? (string) $json['Reason'] : (isset($json['Message']) ? (string) $json['Message'] : '快递鸟查询失败');
            return array('success' => false, 'message' => $msg);
        }

        $traces = array();
        if (!empty($json['Traces']) && is_array($json['Traces'])) {
            foreach ($json['Traces'] as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $time = isset($it['AcceptTime']) ? (string) $it['AcceptTime'] : (string) ($it['time'] ?? '');
                $station = isset($it['AcceptStation']) ? (string) $it['AcceptStation'] : (string) ($it['status'] ?? '');
                $traces[] = array('time' => $time, 'status' => $station, 'location' => '');
            }
        }

        $state = isset($json['State']) ? (string) $json['State'] : '';
        $status_map = array('0' => 'created', '1' => 'created', '2' => 'shipping', '3' => 'delivered', '4' => 'exception');
        $status = isset($status_map[$state]) ? $status_map[$state] : 'shipping';

        return array(
            'success' => true,
            'message' => '',
            'status' => $status,
            'status_text' => $state,
            'traces' => $traces,
            'raw' => array('provider' => 'kdniao'),
        );
    }

    private static function query_cainiao_top($cfg, $company_code, $tracking_no) {
        $app_key = isset($cfg['app_key']) ? trim((string) $cfg['app_key']) : '';
        $app_secret = isset($cfg['app_secret']) ? trim((string) $cfg['app_secret']) : '';
        $session = isset($cfg['session']) ? trim((string) $cfg['session']) : '';
        $endpoint = isset($cfg['endpoint']) ? trim((string) $cfg['endpoint']) : 'https://gw.api.taobao.com/router/rest';
        $method = isset($cfg['method']) ? trim((string) $cfg['method']) : 'taobao.logistics.trace.search';
        $sign_method = isset($cfg['sign_method']) ? strtolower(trim((string) $cfg['sign_method'])) : 'md5';
        $param_company_key = isset($cfg['param_company_key']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $cfg['param_company_key']) : 'company_code';
        $param_tracking_key = isset($cfg['param_tracking_key']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $cfg['param_tracking_key']) : 'out_sid';
        if ($param_company_key === '') {
            $param_company_key = 'company_code';
        }
        if ($param_tracking_key === '') {
            $param_tracking_key = 'out_sid';
        }
        if ($app_key === '' || $app_secret === '' || $method === '') {
            return array('success' => false, 'message' => '菜鸟（TOP）未配置');
        }
        if ($sign_method !== 'md5' && $sign_method !== 'hmac') {
            $sign_method = 'md5';
        }

        $params = array(
            'app_key' => $app_key,
            'method' => $method,
            'format' => 'json',
            'v' => '2.0',
            'sign_method' => $sign_method,
            'timestamp' => date('Y-m-d H:i:s'),
        );
        if ($session !== '') {
            $params['session'] = $session;
        }
        $params[$param_company_key] = $company_code;
        $params[$param_tracking_key] = $tracking_no;
        $params['sign'] = self::top_sign($params, $app_secret, $sign_method);

        $resp = self::http_request('POST', $endpoint, http_build_query($params), array('Content-Type: application/x-www-form-urlencoded;charset=utf-8'), 12);
        $json = is_array($resp) ? ($resp['json'] ?? null) : null;
        if (!is_array($json)) {
            return array('success' => false, 'message' => '菜鸟（TOP）返回异常');
        }
        if (isset($json['error_response'])) {
            $msg = isset($json['error_response']['sub_msg']) ? (string) $json['error_response']['sub_msg'] : (isset($json['error_response']['msg']) ? (string) $json['error_response']['msg'] : '菜鸟（TOP）查询失败');
            return array('success' => false, 'message' => $msg);
        }

        $traces = self::extract_traces_from_unknown_payload($json);
        $status = 'shipping';
        if (!empty($traces)) {
            $last = end($traces);
            if (is_array($last)) {
                $txt = (string) ($last['status'] ?? '');
                $has_signed = function_exists('mb_strpos') ? (mb_strpos($txt, '签收') !== false) : (strpos($txt, '签收') !== false);
                if ($has_signed) {
                    $status = 'delivered';
                }
            }
        }

        return array(
            'success' => true,
            'message' => '',
            'status' => $status,
            'status_text' => '',
            'traces' => $traces,
            'raw' => array('provider' => 'cainiao'),
        );
    }

    private static function top_sign($params, $secret, $sign_method) {
        $params = is_array($params) ? $params : array();
        if (isset($params['sign'])) {
            unset($params['sign']);
        }
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            $str .= (string) $k . (string) $v;
        }
        if ($sign_method === 'hmac') {
            return strtoupper(hash_hmac('md5', $str, (string) $secret));
        }
        return strtoupper(md5((string) $secret . $str . (string) $secret));
    }

    private static function extract_traces_from_unknown_payload($json) {
        $best = array();
        $stack = array(array($json));
        while (!empty($stack)) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
            if (self::looks_like_traces_list($node)) {
                $parsed = self::normalize_traces_list($node);
                if (count($parsed) > count($best)) {
                    $best = $parsed;
                }
            }
        }
        return $best;
    }

    private static function looks_like_traces_list($arr) {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }
        $first = reset($arr);
        if (!is_array($first)) {
            return false;
        }
        $keys = array_keys($first);
        $key_str = implode(',', $keys);
        return (stripos($key_str, 'time') !== false) || (stripos($key_str, 'accepttime') !== false) || (stripos($key_str, 'status') !== false) || (stripos($key_str, 'context') !== false);
    }

    private static function normalize_traces_list($arr) {
        $out = array();
        foreach ($arr as $it) {
            if (!is_array($it)) {
                continue;
            }
            $time = '';
            if (isset($it['time'])) $time = (string) $it['time'];
            elseif (isset($it['AcceptTime'])) $time = (string) $it['AcceptTime'];
            elseif (isset($it['action_time'])) $time = (string) $it['action_time'];
            elseif (isset($it['ftime'])) $time = (string) $it['ftime'];
            $status = '';
            if (isset($it['status'])) $status = (string) $it['status'];
            elseif (isset($it['context'])) $status = (string) $it['context'];
            elseif (isset($it['AcceptStation'])) $status = (string) $it['AcceptStation'];
            elseif (isset($it['desc'])) $status = (string) $it['desc'];
            $location = isset($it['location']) ? (string) $it['location'] : '';
            $out[] = array('time' => $time, 'status' => $status, 'location' => $location);
        }
        return $out;
    }

    private static function http_request($method, $url, $body = null, $headers = array(), $timeout = 12) {
        $method = strtoupper((string) $method);
        $url = (string) $url;
        $headers = is_array($headers) ? $headers : array();

        $header_lines = array();
        foreach ($headers as $h) {
            $header_lines[] = (string) $h;
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $header_lines),
                'content' => $body === null ? '' : (string) $body,
                'timeout' => (int) $timeout,
                'ignore_errors' => true,
            )
        ));

        $resp_body = @file_get_contents($url, false, $context);
        $status = 0;
        $hdr_var = 'http_response_header';
        $resp_headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (isset($$hdr_var) && is_array($$hdr_var) ? $$hdr_var : null);
        if (is_array($resp_headers)) {
            foreach ($resp_headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        if ($resp_body === false && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($header_lines)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
            }
            if ($method !== 'GET' && $body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            $resp_body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        $json = null;
        if (is_string($resp_body) && $resp_body !== '') {
            $json = json_decode($resp_body, true);
        }

        return array(
            'status' => $status,
            'body' => $resp_body,
            'json' => $json,
        );
    }

    /**
     * 检查订单是否已评价
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    public static function has_reviewed($order_id) {
        // 检查订单是否存在评价记录
        $table = DB_PREFIX . 'order_reviews';
        $query = "SELECT COUNT(*) FROM {$table} WHERE order_id = ?";
        
        $count = self::$tododb->get_var($query, [$order_id]);
        
        return $count > 0;
    }

    /**
     * 更新商品评分
     * 
     * @param int $product_id 商品ID
     * @param float $rating 评分
     * @return bool
     */
    public static function update_product_rating($product_id, $rating) {
        try {
            // 获取当前评分统计
            $current_rating = floatval(get_post_meta($product_id, '_average_rating', true));
            $review_count = intval(get_post_meta($product_id, '_review_count', true));
            
            // 计算新的平均评分
            $total_rating = ($current_rating * $review_count) + $rating;
            $new_count = $review_count + 1;
            $new_average = $total_rating / $new_count;
            
            // 更新元数据
            update_post_meta($product_id, '_average_rating', round($new_average, 1));
            update_post_meta($product_id, '_review_count', $new_count);
            
            return true;
            
        } catch (Exception $e) {
            error_log('更新商品评分失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 数据库连接
     * @var CMS_DB
     */
    private static $tododb;
    
    /**
     * 订单状态常量
     */
    const STATUS_PENDING = 'pending';           // 待付款
    const STATUS_PAID = 'paid';                 // 已付款
    const STATUS_PROCESSING = 'processing';     // 处理中
    const STATUS_SHIPPED = 'shipped';           // 已发货
    const STATUS_DELIVERED = 'delivered';       // 已送达
    const STATUS_COMPLETED = 'completed';       // 已完成
    const STATUS_CANCELLED = 'cancelled';       // 已取消
    const STATUS_REFUND = 'refund';             // 售后中
    const STATUS_REFUNDED = 'refunded';         // 已退款
    
    /**
     * 支付状态常量
     */
    const PAYMENT_PENDING = 'pending';          // 待付款
    const PAYMENT_PAID = 'paid';                // 已付款
    const PAYMENT_FAILED = 'failed';            // 付款失败
    const PAYMENT_CANCELLED = 'cancelled';      // 支付取消
    const PAYMENT_REFUNDED = 'refunded';        // 已退款
    const PAYMENT_PARTIAL_REFUNDED = 'partial_refunded'; // 部分退款
    
    /**
     * 发货方式常量
     */
    const DELIVERY_EXPRESS = 'express';         // 快递发货
    const DELIVERY_DOWNLOAD = 'download';       // 网盘下载
    const DELIVERY_TEXT = 'text';               // 文本内容
    const DELIVERY_MANUAL = 'manual';           // 手动发货
    
    /**
     * 订单缓存
     * @var array
     */
    private static $orders_cache = array();
    private static $logistics_runtime_inited = false;
    
    /**
     * 初始化订单管理器
     */
    public static function init() {
        global $tododb;
        self::$tododb = $tododb;
        if (!self::$logistics_runtime_inited) {
            self::$logistics_runtime_inited = true;
            if (function_exists('add_action')) {
                add_action('todo_logistics_poll', array(__CLASS__, 'poll_logistics'));
                add_action('todo_order_completed', array(__CLASS__, 'process_order_points_deduction'));
                add_action('todo_auto_confirm_orders', array(__CLASS__, 'process_auto_confirm_orders'));
            }
        }
        self::ensure_logistics_schedule();
        self::ensure_auto_confirm_schedule();
        self::ensure_admin_notes_column();
    }

    /**
     * 确保 orders 表包含 admin_notes 字段
     */
    public static function ensure_admin_notes_column() {
        if (self::$tododb !== null) {
            static $checked = false;
            if (!$checked) {
                $checked = true;
                $cols = self::$tododb->get_results("SHOW COLUMNS FROM " . DB_PREFIX . "orders LIKE 'admin_notes'");
                if (empty($cols)) {
                    self::$tododb->query("ALTER TABLE " . DB_PREFIX . "orders ADD COLUMN admin_notes text COMMENT '管理员备注信息'");
                }
            }
        }
    }

    /**
     * 判断订单是否为虚拟订单
     * 
     * @param int|array $order_id_or_data 订单ID或订单数据
     * @return bool
     */
    public static function is_virtual_order($order_id_or_data) {
        if (is_array($order_id_or_data)) {
            $order = $order_id_or_data;
        } else {
            $order = self::get_order($order_id_or_data, true);
        }
        if (!$order) {
            return false;
        }
        if (isset($order['shipping_method']) && $order['shipping_method'] === 'virtual') {
            return true;
        }
        if (!empty($order['items']) && is_array($order['items'])) {
            $is_all_virtual = true;
            foreach ($order['items'] as $item) {
                $pid = intval($item['product_id'] ?? 0);
                $pt = $item['product_type'] ?? ($pid > 0 ? get_post_meta($pid, 'product_type', true) : 'physical');
                if ($pt !== 'virtual') {
                    $is_all_virtual = false;
                    break;
                }
            }
            if ($is_all_virtual && !empty($order['items'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 创建订单
     * 
     * @param array $order_data 订单数据
     * @return bool|int 成功返回订单ID，失败返回false
     */
    public static function create_order($order_data) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
        }
        
        if (self::$tododb === null) {
            todo_log_error('Database connection not available in create_order');
            return false;
        }
        
        if (!isset($order_data['user_id']) || empty($order_data['items'])) {
            return false;
        }
        
        // 判定订单语言环境
        $lang_code = $order_data['lang'] ?? ($order_data['order_lang'] ?? ($order_data['language'] ?? ''));
        if (empty($lang_code)) {
            if (function_exists('todo_get_current_language')) {
                $lang_code = todo_get_current_language();
            } elseif (function_exists('current_locale')) {
                $lang_code = current_locale();
            }
        }
        
        // 生成订单号（若未显式传入）
        if (empty($order_data['order_no'])) {
            $order_no = self::generate_order_no($lang_code);
        } else {
            $order_no = $order_data['order_no'];
        }
        
        // 计算订单总金额
        $total_amount = self::calculate_order_total($order_data['items']);
        
        // 默认值
        $defaults = array(
            'order_no' => $order_no,
            'status' => self::STATUS_PENDING,
            'payment_status' => self::PAYMENT_PENDING,
            'total_amount' => $total_amount,
            'paid_amount' => 0,
            'shipping_fee' => 0,
            'discount_amount' => 0,
            'currency' => function_exists('todo_get_default_currency_config') ? (todo_get_default_currency_config()['code'] ?? '') : '',
            'payment_method' => '',
            'delivery_method' => self::DELIVERY_EXPRESS,
            'notes' => '',
            'admin_notes' => ''
        );
        
        $order_data = array_merge($defaults, $order_data);
        
        // 矫正订单总金额，包含运费与优惠，避免外部传入0导致显示为0
        $items_total = self::calculate_order_total($order_data['items']);
        $order_data['total_amount'] = round($items_total + floatval($order_data['shipping_fee']) - floatval($order_data['discount_amount']), 2);
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 插入订单主表（调整字段以匹配实际orders表结构）
            $query = "INSERT INTO " . DB_PREFIX . "orders 
                      (order_no, user_id, total_amount, payment_amount,
                       shipping_fee, discount_amount, status, payment_status, shipping_status,
                       payment_method, shipping_method, shipping_address, shipping_info, remark, admin_notes, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $params = [
                $order_data['order_no'],
                $order_data['user_id'],
                $order_data['total_amount'],
                $order_data['paid_amount'], // 内部使用paid_amount，数据库字段为payment_amount
                $order_data['shipping_fee'],
                $order_data['discount_amount'],
                $order_data['status'],
                $order_data['payment_status'],
                'unshipped',
                $order_data['payment_method'],
                $order_data['shipping_method'] ?? ($order_data['delivery_method'] ?? ''), // 映射为shipping_method
                $order_data['shipping_address'] ?? '',
                '', // 初始无物流信息
                $order_data['notes'] ?? $order_data['order_note'] ?? $order_data['remark'] ?? '',
                $order_data['admin_notes'] ?? $order_data['admin_note'] ?? ''
            ];
            $result = self::$tododb->query($query, $params);
            if ($result === false) {
                throw new Exception('Failed to create order');
            }
            
            $order_id = self::$tododb->insert_id();
            
            // 插入订单项目
            foreach ($order_data['items'] as $item) {
                if (!self::create_order_item($order_id, $item)) {
                    throw new Exception('Failed to create order item');
                }
            }
            
            // 保存自定义字段
            if (!empty($order_data['guest_email'])) {
                self::save_order_custom_field($order_id, 'guest_email', $order_data['guest_email']);
            }
            if (!empty($order_data['custom_fields'])) {
                foreach ($order_data['custom_fields'] as $field_name => $field_value) {
                    self::save_order_custom_field($order_id, $field_name, $field_value);
                }
            }
            
            // 自动保存订单多语言环境标识
            if (!empty($lang_code)) {
                self::save_order_custom_field($order_id, '_order_lang', $lang_code);
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 记录订单日志
            self::add_order_log($order_id, 'order_created', __('订单创建'), $order_data['user_id']);
            
            if (function_exists('todo_save_guest_order_id')) {
                todo_save_guest_order_id($order_id, $order_data['order_no'] ?? '');
            }
            
            return $order_id;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Order creation failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新订单
     * 
     * @param int $order_id 订单ID
     * @param array $order_data 订单数据
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function update_order($order_id, $order_data, $operator_id = 0) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
        }
        
        if (self::$tododb === null) {
            todo_log_error('Database connection not available in update_order');
            return false;
        }
        
        $set_clauses = array();
        $params = array();
        $types = '';
        
        // 字段到数据库列的映射
        $column_mapping = array(
            'status' => 'status',
            'payment_status' => 'payment_status',
            'total_amount' => 'total_amount',
            'paid_amount' => 'payment_amount',
            'payment_amount' => 'payment_amount',
            'shipping_fee' => 'shipping_fee',
            'discount_amount' => 'discount_amount',
            'payment_method' => 'payment_method',
            'delivery_method' => 'shipping_method',
            'shipping_method' => 'shipping_method',
            'shipping_address' => 'shipping_address',
            'billing_address' => 'billing_address',
            'notes' => 'remark',
            'remark' => 'remark',
            'admin_notes' => 'admin_notes',
            'admin_note' => 'admin_notes',
            'transaction_id' => 'transaction_id',
            'paid_at' => 'paid_at',
            'payment_time' => 'paid_at',
            'ab_ref_order_no' => 'ab_ref_order_no',
            'ab_order_type' => 'ab_order_type',
            'ab_b_site_url' => 'ab_b_site_url'
        );

        $updatable_fields = array_keys($column_mapping);
        
        $old_order = self::get_order($order_id);
        if (!$old_order) {
            return false;
        }

        // 处理从数据库加载的旧数据中的列名差异（用于后续日志比较）
        $mapped_old_order = $old_order;
        if (isset($old_order['payment_amount'])) $mapped_old_order['paid_amount'] = $old_order['payment_amount'];
        if (isset($old_order['shipping_method'])) $mapped_old_order['delivery_method'] = $old_order['shipping_method'];
        if (isset($old_order['remark'])) $mapped_old_order['notes'] = $old_order['remark'];
        
        foreach ($column_mapping as $field => $column) {
            if (isset($order_data[$field])) {
                $set_clauses[] = "{$column} = ?";
                $params[] = $order_data[$field];
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $set_clauses[] = 'updated_at = NOW()';
        $params[] = $order_id;
        
        $query = "UPDATE " . DB_PREFIX . "orders SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        
        if (self::$tododb->query($query, $params)) {
             // 记录变更日志时使用映射后的旧数据进行比较
             // 记录变更日志时使用映射后的旧数据进行比较
             $old_order = $mapped_old_order;

            // 更新物流信息
            if (isset($order_data['express_company']) || isset($order_data['tracking_no']) || isset($order_data['tracking_info'])) {
                $logistics_table = DB_PREFIX . 'order_logistics';
                $express_comp = $order_data['express_company'] ?? $old_order['express_company'] ?? '';
                $track_no = $order_data['tracking_no'] ?? $old_order['tracking_no'] ?? '';
                $track_info = $order_data['tracking_info'] ?? $old_order['tracking_info'] ?? '';

                if (method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_logistics')) {
                    $existing_logistics = self::$tododb->get_row("SELECT id FROM {$logistics_table} WHERE order_id = ?", [$order_id]);
                    
                    if ($existing_logistics) {
                        self::$tododb->query(
                            "UPDATE {$logistics_table} SET express_company = ?, tracking_number = ?, tracking_info = ? WHERE order_id = ?",
                            [$express_comp, $track_no, $track_info, $order_id]
                        );
                    } else {
                        self::$tododb->query(
                            "INSERT INTO {$logistics_table} (order_id, express_company, tracking_number, tracking_info, status) VALUES (?, ?, ?, ?, 'created')",
                            [$order_id, $express_comp, $track_no, $track_info]
                        );
                    }
                }
            }

            // 定义字段中文名称映射
            $field_labels = array(
                'status' => '订单状态',
                'payment_status' => '支付状态',
                'total_amount' => '订单总额',
                'paid_amount' => '已付金额',
                'shipping_fee' => '运费',
                'discount_amount' => '优惠金额',
                'payment_method' => '支付方式',
                'delivery_method' => '交付方式',
                'shipping_address' => '收货地址',
                'tracking_no' => '快递单号',
                'express_company' => '快递公司',
                'notes' => '客户备注',
                'admin_notes' => '管理员备注',
                'transaction_id' => 'transaction_id',
                'paid_at' => 'paid_at'
            );

            // 状态名称映射
            $status_labels = array(
                'pending' => __('待付款'),
                'paid' => __('已付款'),
                'processing' => __('待发货'),
                'shipped' => __('已发货'),
                'delivered' => __('已送达'),
                'completed' => __('已完成'),
                'cancelled' => __('已取消'),
                'refunded' => __('已退款'),
                'partial_refunded' => __('部分退款'),
                'failed' => __('失败')
            );

            // 交付方式名称映射
            $delivery_labels = array(
                'express' => __('快递发货'),
                'delivery' => __('快递配送'),
                'self_pickup' => __('线下自提'),
                'pickup' => __('到店自提'),
                'download' => __('网盘下载'),
                'content' => __('文本内容'),
                'expresstrue' => __('快递发货')
            );

            // 记录变更日志
            // 注意：$updatable_fields 是 array_keys() 返回的普通索引数组，
            // 不能用 as $field => $type，否则 $field 是整数索引而非字段名。
            foreach ($updatable_fields as $field) {
                if (isset($order_data[$field]) && isset($old_order[$field]) && $old_order[$field] != $order_data[$field]) {
                    $old_val = $old_order[$field];
                    $new_val = $order_data[$field];

                    // 对 JSON 结构（如收货地址）进行规范化对比，消除键名顺序或无意义的空白字段差异
                    if (in_array($field, ['shipping_address', 'billing_address'], true)) {
                        $old_json = json_decode((string)$old_val, true);
                        $new_json = json_decode((string)$new_val, true);
                        if (is_array($old_json) && is_array($new_json)) {
                            $norm_old = array_filter(array_map('trim', array_map('strval', $old_json)), function($v) { return $v !== ''; });
                            $norm_new = array_filter(array_map('trim', array_map('strval', $new_json)), function($v) { return $v !== ''; });
                            ksort($norm_old);
                            ksort($norm_new);
                            if ($norm_old === $norm_new) {
                                continue;
                            }
                        }
                    }

                    $label = isset($field_labels[$field]) ? $field_labels[$field] : $field;

                    // 格式化数值和状态
                    if (in_array($field, ['total_amount', 'shipping_fee', 'discount_amount', 'paid_amount'])) {
                        $old_val = function_exists('todo_format_admin_price') ? todo_format_admin_price((float)$old_val) : number_format((float)$old_val, 2);
                        $new_val = function_exists('todo_format_admin_price') ? todo_format_admin_price((float)$new_val) : number_format((float)$new_val, 2);
                        $action = 'price_changed';
                    } elseif (in_array($field, ['status', 'payment_status'])) {
                        $label_str = ($field === 'status') ? '订单状态' : '支付状态';
                        $action = $field . '_updated';
                        $message = "{$label_str}: 从 '{$old_val}' 变更为 '{$new_val}'";
                        self::add_order_log($order_id, $action, $message, $operator_id);
                        continue;
                    } elseif ($field === 'delivery_method' || $field === 'shipping_method') {
                        $old_val = isset($delivery_labels[$old_val]) ? $delivery_labels[$old_val] : $old_val;
                        $new_val = isset($delivery_labels[$new_val]) ? $delivery_labels[$new_val] : $new_val;
                        $action = 'order_updated';
                    } else {
                        $action = 'order_updated';
                    }

                    $message = "{$label}: 从 '{$old_val}' 变更为 '{$new_val}'";
                    self::add_order_log($order_id, $action, $message, $operator_id);
                }
            }
            
            // 更新自定义字段
            if (!empty($order_data['custom_fields'])) {
                foreach ($order_data['custom_fields'] as $field_name => $field_value) {
                    self::save_order_custom_field($order_id, $field_name, $field_value);
                }
            }
            
            // 如果订单状态更新为已发货，触发发货通知邮件
            if (isset($order_data['status']) && ($order_data['status'] === self::STATUS_SHIPPED || $order_data['status'] === 'shipped')) {
                if (function_exists('todo_notify_admin_order_shipping')) {
                    $shipping_data = array(
                        'express_company' => $order_data['express_company'] ?? ($old_order['express_company'] ?? ''),
                        'tracking_no' => $order_data['tracking_no'] ?? ($old_order['tracking_no'] ?? '')
                    );
                    todo_notify_admin_order_shipping($order_id, $shipping_data);
                }
            }

            // 如果订单状态更新为已完成或支付状态更新为已付款，触发积分扣减
            if ((isset($order_data['status']) && ($order_data['status'] === self::STATUS_COMPLETED || $order_data['status'] === 'completed'))
                || (isset($order_data['payment_status']) && ($order_data['payment_status'] === self::PAYMENT_PAID || $order_data['payment_status'] === 'paid'))) {
                self::process_order_points_deduction($order_id);
            }
            // 如果订单状态更新为已取消/已退款，或支付状态更新为已退款，触发积分返还
            if ((isset($order_data['status']) && in_array($order_data['status'], [self::STATUS_CANCELLED, 'cancelled', self::STATUS_REFUNDED, 'refunded', 'partial_refunded'], true))
                || (isset($order_data['payment_status']) && in_array($order_data['payment_status'], [self::PAYMENT_REFUNDED, 'refunded', 'partial_refunded'], true))) {
                self::process_order_points_refund($order_id, $operator_id, $order_data['notes'] ?? ($order_data['remark'] ?? ''));
            }

            // 余额支付原路退款：当订单状态或支付状态变更为已退款时，触发余额原路退还至用户账户
            if ((isset($order_data['status']) && in_array($order_data['status'], [self::STATUS_REFUNDED, 'refunded'], true))
                || (isset($order_data['payment_status']) && in_array($order_data['payment_status'], [self::PAYMENT_REFUNDED, 'refunded'], true))) {
                self::process_order_balance_refund($order_id, $operator_id, $order_data['notes'] ?? ($order_data['remark'] ?? ''));
            }

            // 售后业务闭环：当订单被更新为已退款 (refunded)，自动将待处理的售后申请记录闭环为已退款 (completed)
            $is_now_refunded = (isset($order_data['status']) && in_array($order_data['status'], [self::STATUS_REFUNDED, 'refunded'], true))
                || (isset($order_data['payment_status']) && in_array($order_data['payment_status'], [self::PAYMENT_REFUNDED, 'refunded'], true));
            if ($is_now_refunded && isset(self::$tododb) && is_object(self::$tododb)) {
                $now_time = date('Y-m-d H:i:s');
                self::$tododb->query(self::$tododb->prepare(
                    "UPDATE " . DB_PREFIX . "refunds SET status = 'completed', processed_at = %s, updated_at = %s WHERE order_id = %d AND status IN ('pending', 'processing')",
                    $now_time,
                    $now_time,
                    $order_id
                ));
            } elseif (isset($order_data['status']) && in_array($order_data['status'], [self::STATUS_CANCELLED, 'cancelled'], true) && isset(self::$tododb) && is_object(self::$tododb)) {
                $now_time = date('Y-m-d H:i:s');
                self::$tododb->query(self::$tododb->prepare(
                    "UPDATE " . DB_PREFIX . "refunds SET status = 'cancelled', updated_at = %s WHERE order_id = %d AND status = 'pending'",
                    $now_time,
                    $order_id
                ));
            }

            // 清除缓存（包括带有后缀的缓存键）
            unset(self::$orders_cache[$order_id]);
            unset(self::$orders_cache[$order_id . '_with_items']);
            unset(self::$orders_cache[$order_id . '_no_items']);
            
            // 自动同步 AB 订单状态与变更到对端（A站 <-> B站）
            if (class_exists('Todo_AB_Payment_API')) {
                $order_raw = self::$tododb->get_row("SELECT ab_order_type FROM " . DB_PREFIX . "orders WHERE id = " . intval($order_id));
                $ab_type = is_object($order_raw) ? ($order_raw->ab_order_type ?? '') : (is_array($order_raw) ? ($order_raw['ab_order_type'] ?? '') : '');
                if (!empty($ab_type)) {
                    $ab_api = new Todo_AB_Payment_API();
                    $ab_api->sync_ab_order($order_id);
                }
            }

            return true;
        }
        
        return false;
    }
    
    /**
     * 获取订单信息
     * 
     * @param int $order_id 订单ID
     * @param bool $include_items 是否包含订单项目
     * @return array|null
     */
    public static function get_order($order_id, $include_items = true) {
        if (self::$tododb === null) {
            self::init();
        }
        $cache_key = $order_id . '_' . ($include_items ? 'with_items' : 'no_items');
        
        if (isset(self::$orders_cache[$cache_key])) {
            return self::$orders_cache[$cache_key];
        }
        
        $query = "SELECT o.*, u.user_login AS username, u.user_email as user_email, 
                  u.display_name as customer_name,
                  (SELECT meta_value FROM " . DB_PREFIX . "user_meta WHERE user_id = o.user_id AND meta_key = 'phone' LIMIT 1) as customer_phone
                  FROM " . DB_PREFIX . "orders o 
                  LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.ID 
                  WHERE o.id = ?";
        
        $row = self::$tododb->get_row($query, [$order_id], 'ARRAY_A');
        
        if ($row) {
            // 获取订单项目
            if ($include_items) {
                $row['items'] = self::get_order_items($order_id);
            }
            
            // 获取物流与发货信息
            $logistics_table = DB_PREFIX . 'order_logistics';
            if (method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_logistics')) {
                $logistics = self::$tododb->get_row("SELECT * FROM {$logistics_table} WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$order_id], 'ARRAY_A');
                if ($logistics) {
                    $row['express_company'] = $logistics['express_company'];
                    $row['tracking_no'] = $logistics['tracking_number'];
                    $row['tracking_info'] = $logistics['tracking_info'];
                    $row['logistics_status'] = $logistics['status'];
                    if (!empty($logistics['created_at'])) {
                        $row['shipped_at'] = $logistics['created_at'];
                        $row['shipping_time'] = $logistics['created_at'];
                    }
                }
            }
            if (empty($row['express_company']) || empty($row['tracking_no'])) {
                $shipments_table = DB_PREFIX . 'order_shipments';
                if (method_exists(self::$tododb, 'table_exists') && self::$tododb->table_exists('order_shipments')) {
                    $shipment = self::$tododb->get_row("SELECT * FROM {$shipments_table} WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$order_id], 'ARRAY_A');
                    if ($shipment) {
                        if (empty($row['express_company'])) $row['express_company'] = $shipment['express_company'];
                        if (empty($row['tracking_no'])) $row['tracking_no'] = $shipment['tracking_no'];
                        if (empty($row['shipped_at']) && !empty($shipment['created_at'])) {
                            $row['shipped_at'] = $shipment['created_at'];
                            $row['shipping_time'] = $shipment['created_at'];
                        }
                    }
                }
            }
            
            // 获取自定义字段
            $row['custom_fields'] = self::get_order_custom_fields($order_id);
            
            // 解析地址JSON
            if (!empty($row['shipping_address'])) {
                $row['shipping_address_data'] = json_decode($row['shipping_address'], true);
            }
            if (!empty($row['billing_address'])) {
                $row['billing_address_data'] = json_decode($row['billing_address'], true);
            }

            // 补充访客邮箱信息
            $row['guest_email'] = $row['custom_fields']['guest_email'] 
                ?? ($row['custom_fields']['_guest_email'] 
                ?? ($row['shipping_address_data']['guest_email'] 
                ?? ($row['shipping_address_data']['email'] ?? '')));
            
            // 规范化备注别名（兼容 notes/order_note/order_notes/remark 及 admin_notes/admin_note 字段命名）
            $row['notes'] = $row['remark'] ?? ($row['notes'] ?? ($row['order_note'] ?? ''));
            $row['order_note'] = $row['notes'];
            $row['order_notes'] = $row['notes'];
            $row['admin_notes'] = $row['admin_notes'] ?? ($row['admin_note'] ?? '');
            $row['admin_note'] = $row['admin_notes'];
            
            // 规范化支付时间字段（支持 paid_at 与 payment_time 互读，已支付订单自动兜底填充）
            $raw_paid_at = !empty($row['paid_at']) ? $row['paid_at'] : (!empty($row['payment_time']) ? $row['payment_time'] : '');
            if (empty($raw_paid_at) && (string)($row['payment_status'] ?? '') === 'paid') {
                $pay_rec = self::$tododb->get_var("SELECT payment_time FROM " . DB_PREFIX . "order_payments WHERE order_id = ? AND payment_status = 'paid' ORDER BY id DESC LIMIT 1", [$order_id]);
                if (!empty($pay_rec)) {
                    $raw_paid_at = $pay_rec;
                } else {
                    $raw_paid_at = !empty($row['updated_at']) ? $row['updated_at'] : ($row['created_at'] ?? '');
                }
            }
            if (!empty($raw_paid_at)) {
                $row['paid_at'] = $raw_paid_at;
                $row['payment_time'] = $raw_paid_at;
            }
            
            self::$orders_cache[$cache_key] = $row;
            return $row;
        }
        
        return null;
    }
    
    /**
     * 根据订单号获取订单
     * 
     * @param string $order_no 订单号
     * @param bool $include_items 是否包含订单项目
     * @return array|null
     */
    public static function get_order_by_no($order_no, $include_items = true) {
        if (self::$tododb === null) {
            self::init();
        }
        $query = "SELECT id FROM " . DB_PREFIX . "orders WHERE order_no = ?";
        $row = self::$tododb->get_row($query, [$order_no], 'ARRAY_A');
        
        if ($row) {
            return self::get_order($row['id'], $include_items);
        }
        
        return null;
    }
    
    /**
     * 获取订单列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_orders($args = array()) {
        // 默认参数
        $defaults = array(
            'user_id' => null,
            'status' => null,
            'payment_status' => null,
            'delivery_method' => null,
            'date_from' => null,
            'date_to' => null,
            'keyword' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'include_items' => false
        );

        $args = array_merge($defaults, $args);

        $where_clauses = array();
        $params = array();

        // 构建WHERE条件
        if ($args['user_id'] !== null) {
            $where_clauses[] = 'o.user_id = ?';
            $params[] = intval($args['user_id']);
        }

        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = str_repeat('?,', count($args['status']) - 1) . '?';
                $where_clauses[] = "o.status IN ({$placeholders})";
                $params = array_merge($params, $args['status']);
            } else {
                $where_clauses[] = 'o.status = ?';
                $params[] = $args['status'];
            }
        }

        if (!empty($args['payment_status'])) {
            $where_clauses[] = 'o.payment_status = ?';
            $params[] = $args['payment_status'];
        }

        if (!empty($args['delivery_method'])) {
            $where_clauses[] = 'o.delivery_method = ?';
            $params[] = $args['delivery_method'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'o.created_at >= ?';
            $params[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'o.created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['keyword'])) {
            $where_clauses[] = '(o.order_no LIKE ? OR u.user_login LIKE ? OR u.user_email LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
        }

        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

        // 构建ORDER BY
        $allowed_order_fields = array('id', 'order_no', 'total_amount', 'created_at', 'updated_at');
        $order_by = in_array($args['order_by'], $allowed_order_fields) ? $args['order_by'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $order_sql = "ORDER BY o.{$order_by} {$order}";

        // 构建LIMIT
        $limit_sql = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);

        $query = "SELECT o.*, u.user_login AS username, u.user_email AS user_email 
                  FROM " . DB_PREFIX . "orders o 
                  LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.ID 
                  {$where_sql} {$order_sql} {$limit_sql}";

        // 使用 Extended_PDO 接口执行查询
        $rows = self::$tododb->get_results($query, $params, 'ARRAY_A');

        $orders = array();
        foreach ($rows as $row) {
            // 获取订单项目
            if ($args['include_items']) {
                $row['items'] = self::get_order_items($row['id']);
            }

            // 解析地址JSON
            if (!empty($row['shipping_address'])) {
                $row['shipping_address_data'] = json_decode($row['shipping_address'], true);
            }

            $orders[] = $row;
        }

        return $orders;
    }
    
    /**
     * 获取订单统计
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_order_stats($args = array()) {
        $stats = array(
            'total_orders' => 0,
            'total_amount' => 0,
            'status_counts' => array(),
            'payment_status_counts' => array(),
            'monthly_stats' => array()
        );

        // 基础统计
        $where_clauses = array();
        $params = array();

        if (!empty($args['user_id'])) {
            $where_clauses[] = 'user_id = ?';
            $params[] = intval($args['user_id']);
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= ?';
            $params[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

        // 总订单数和总金额
        $query = "SELECT COUNT(*) as total_orders, COALESCE(SUM(total_amount), 0) as total_amount 
                  FROM " . DB_PREFIX . "orders {$where_sql}";
        $row = self::$tododb->get_row($query, $params, 'ARRAY_A');
        if ($row) {
            $stats['total_orders'] = intval($row['total_orders']);
            $stats['total_amount'] = floatval($row['total_amount']);
        }

        // 按状态统计
        $query = "SELECT status, COUNT(*) as count 
                  FROM " . DB_PREFIX . "orders {$where_sql} 
                  GROUP BY status";
        $rows = self::$tododb->get_results($query, $params, 'ARRAY_A');
        foreach ($rows as $r) {
            $stats['status_counts'][$r['status']] = intval($r['count']);
        }

        // 按支付状态统计
        $query = "SELECT payment_status, COUNT(*) as count 
                  FROM " . DB_PREFIX . "orders {$where_sql} 
                  GROUP BY payment_status";
        $rows = self::$tododb->get_results($query, $params, 'ARRAY_A');
        foreach ($rows as $r) {
            $stats['payment_status_counts'][$r['payment_status']] = intval($r['count']);
        }

        return $stats;
    }
    
    /**
     * 创建订单项目
     * 
     * @param int $order_id 订单ID
     * @param array $item_data 项目数据
     * @return bool
     */
    private static function create_order_item($order_id, $item_data) {
        // 对齐 order_items 表结构并增强健壮性，兼容不同键名
        $quantity = isset($item_data['quantity']) ? intval($item_data['quantity']) : 1;
        $unit_price = isset($item_data['unit_price']) ? floatval($item_data['unit_price']) : (isset($item_data['price']) ? floatval($item_data['price']) : 0.0);
        $total_price = isset($item_data['total_price']) ? floatval($item_data['total_price']) : ($quantity * $unit_price);
        $product_name = $item_data['product_name'] ?? ($item_data['name'] ?? ($item_data['title'] ?? ''));
        $spec_id = isset($item_data['spec_id']) ? intval($item_data['spec_id']) : 0;
        $spec_name = $item_data['spec_name'] ?? '';
        $product_image = $item_data['product_image'] ?? ($item_data['image'] ?? '');
        if ($product_image !== '' && function_exists('todo_media_url_to_relative')) {
            $product_image = todo_media_url_to_relative($product_image);
        }

        $query = "INSERT INTO " . DB_PREFIX . "order_items 
                  (order_id, product_id, spec_id, product_name, spec_name, price, quantity, total_price, product_image, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            $order_id,
            $item_data['product_id'],
            $spec_id,
            $product_name,
            $spec_name,
            $unit_price,
            $quantity,
            $total_price,
            $product_image
        ];
        $result = self::$tododb->query($query, $params);
        return $result !== false;
    }
    
    /**
     * 获取订单项目
     * 
     * @param int $order_id 订单ID
     * @return array
     */
    public static function get_order_items($order_id) {
        if (self::$tododb === null) {
            self::init();
        }
        $query = "SELECT * FROM " . DB_PREFIX . "order_items WHERE order_id = ? ORDER BY id";
        $rows = self::$tododb->get_results($query, [$order_id], 'ARRAY_A');

        $items = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                // 确保$row是数组格式
                if (is_object($row)) {
                    $row = (array) $row;
                }
                
                // 与新表结构对齐，提供兼容键名
                if (isset($row['price']) && !isset($row['unit_price'])) {
                    $row['unit_price'] = $row['price'];
                }
                // 旧版本可能使用的字段占位，避免模板报错
                if (!isset($row['product_specs_data'])) $row['product_specs_data'] = array();
                if (!isset($row['delivery_info_data'])) $row['delivery_info_data'] = array();
                // 确保 product_image 为规范相对路径（/content/...）
                if (!empty($row['product_image']) && function_exists('todo_media_url_to_relative')) {
                    $row['product_image'] = todo_media_url_to_relative($row['product_image']);
                }

                if (!isset($row['product_title']) && isset($row['product_name'])) {
                    $row['product_title'] = $row['product_name'];
                }
                $items[] = $row;
            }
        }

        if (!empty($items)) {
            foreach ($items as &$item) {
                $pid = intval($item['product_id'] ?? 0);
                if ($pid > 0) {
                    if (function_exists('todo_get_permalink')) {
                        $item['product_url'] = todo_get_permalink($pid);
                    } elseif (function_exists('get_permalink')) {
                        $item['product_url'] = get_permalink($pid);
                    }
                    $item['url'] = $item['product_url'] ?? '';
                    if (function_exists('todo_get_the_title')) {
                        $translated_title = todo_get_the_title($pid);
                        if (!empty($translated_title)) {
                            $item['product_title'] = $translated_title;
                        }
                    }
                }
                if (empty($item['product_title']) && !empty($item['product_name'])) {
                    $item['product_title'] = $item['product_name'];
                }
                if ($pid > 0) {
                    $pt = get_post_meta($pid, 'product_type', true);
                    $dt = get_post_meta($pid, 'delivery_type', true);
                    $item['product_type'] = $pt ?: 'physical';
                    $item['delivery_type'] = $dt ?: 'express';
                    $item['delivery_express_note'] = get_post_meta($pid, 'delivery_express_note', true) ?: '';
                    $item['delivery_download_url'] = get_post_meta($pid, 'delivery_download_url', true) ?: '';
                    $item['delivery_download_code'] = get_post_meta($pid, 'delivery_download_code', true) ?: '';
                    $item['delivery_download_password'] = get_post_meta($pid, 'delivery_download_password', true) ?: '';
                    $item['delivery_download_note'] = get_post_meta($pid, 'delivery_download_note', true) ?: '';
                    $item['delivery_content_note'] = get_post_meta($pid, 'delivery_content_note', true) ?: '';
                    $item['auto_gen_license_key'] = get_post_meta($pid, 'auto_gen_license_key', true) ?: 0;

                    $mfp = DB_PREFIX . 'model_fields_product';
                    try {
                        $mfp_row = self::$tododb->get_row(self::$tododb->prepare("SELECT product_type, delivery_type, delivery_express_note, delivery_download_url, delivery_download_code, delivery_download_password, delivery_download_note, delivery_content_note, auto_gen_license_key FROM {$mfp} WHERE post_id = %d LIMIT 1", $pid), ARRAY_A);
                        if ($mfp_row && (is_array($mfp_row) || is_object($mfp_row))) {
                            $mfp_arr = (array) $mfp_row;
                            foreach ($mfp_arr as $mk => $mv) {
                                $item[$mk] = $mv ?? '';
                            }
                        }
                    } catch (\Exception $e) {}

                    // 动态替换 delivery_content_note：始终实时使用商品最新模板，替换为该订单明细专属的 license_key 和 license_pass
                    $note_template = $item['delivery_content_note'] ?? '';
                    $lic_key = $item['license_key'] ?? '';
                    $lic_pass = $item['license_pass'] ?? '';
                    if (!empty($lic_key) || !empty($lic_pass)) {
                        $item['delivery_content_note'] = str_replace(
                            ['{license_key}', '{license_pass}'],
                            [$lic_key, $lic_pass],
                            $note_template
                        );
                    }

                    // 根据 product_type 与 delivery_type 严格清理无关的交付物数据（后端渲染端过滤，绝不越权暴露）
                    $pt = $item['product_type'] ?? 'physical';
                    $dt = $item['delivery_type'] ?? 'express';

                    if ($pt === 'physical' || $pt !== 'virtual') {
                        // 实物商品完全不使用交付物卡片，清空 delivery_type 及所有交付数据
                        $item['delivery_type'] = '';
                        $item['delivery_express_note'] = '';
                        $item['delivery_download_url'] = '';
                        $item['delivery_download_code'] = '';
                        $item['delivery_download_password'] = '';
                        $item['delivery_download_note'] = '';
                        $item['delivery_content_note'] = '';
                    } else {
                        // 虚拟商品：依据 delivery_type 精准保留对应字段，其余清空
                        if (in_array($dt, ['express', 'expresstrue'], true)) {
                            $item['delivery_download_url'] = '';
                            $item['delivery_download_code'] = '';
                            $item['delivery_download_password'] = '';
                            $item['delivery_download_note'] = '';
                            $item['delivery_content_note'] = '';
                        } elseif ($dt === 'download') {
                            $item['delivery_express_note'] = '';
                            $item['delivery_content_note'] = '';
                        } elseif ($dt === 'content') {
                            $item['delivery_express_note'] = '';
                            $item['delivery_download_url'] = '';
                            $item['delivery_download_code'] = '';
                            $item['delivery_download_password'] = '';
                            $item['delivery_download_note'] = '';
                        }
                    }
                } else {
                    $item['product_type'] = 'physical';
                    $item['delivery_type'] = 'express';
                }
            }
            unset($item);
        }

        return $items;
    }
    
    /**
     * 计算订单总金额
     * 
     * @param array $items 订单项目
     * @return float
     */
    private static function calculate_order_total($items) {
        $total = 0.0;
        foreach ($items as $item) {
            // 兼容不同键名：total_price、total、以及从数量与单价计算
            if (isset($item['total_price']) && $item['total_price'] !== '') {
                $line_total = floatval($item['total_price']);
            } elseif (isset($item['total']) && $item['total'] !== '') {
                $line_total = floatval($item['total']);
            } else {
                $qty = isset($item['quantity']) ? floatval($item['quantity']) : 1.0;
                $unit = null;
                if (isset($item['unit_price'])) {
                    $unit = floatval($item['unit_price']);
                } elseif (isset($item['price'])) {
                    $unit = floatval($item['price']);
                } else {
                    $unit = 0.0;
                }
                $line_total = $qty * $unit;
            }
            $total += $line_total;
        }
        return $total;
    }
    
    /**
     * 根据语言代码获取订单号前缀（2位大写英文字母）
     * 
     * @param string $lang 语言代码，如 zh-CN, en-US
     * @return string
     */
    public static function get_language_prefix($lang = '') {
        $lang = strtolower(str_replace('_', '-', trim((string) $lang)));
        if (empty($lang)) {
            return 'ZH';
        }
        
        if (strpos($lang, 'zh') === 0) {
            return 'ZH';
        } elseif (strpos($lang, 'en') === 0) {
            return 'EN';
        } elseif (strpos($lang, 'ja') === 0 || strpos($lang, 'jp') === 0) {
            return 'JA';
        } elseif (strpos($lang, 'ko') === 0 || strpos($lang, 'kr') === 0) {
            return 'KO';
        } elseif (strpos($lang, 'de') === 0) {
            return 'DE';
        } elseif (strpos($lang, 'fr') === 0) {
            return 'FR';
        } elseif (strpos($lang, 'es') === 0) {
            return 'ES';
        } elseif (strpos($lang, 'ru') === 0) {
            return 'RU';
        } elseif (strpos($lang, 'it') === 0) {
            return 'IT';
        } elseif (strpos($lang, 'pt') === 0) {
            return 'PT';
        } elseif (strpos($lang, 'ar') === 0) {
            return 'AR';
        } elseif (strpos($lang, 'th') === 0) {
            return 'TH';
        } elseif (strpos($lang, 'vi') === 0) {
            return 'VI';
        }
        
        $clean = preg_replace('/[^a-z]/i', '', $lang);
        if (strlen($clean) >= 2) {
            return strtoupper(substr($clean, 0, 2));
        }
        return 'ZH';
    }

    /**
     * 生成订单号
     * 
     * @return string
     */
    public static function generate_order_no($lang = '') {
        if (empty($lang)) {
            if (function_exists('todo_get_current_language')) {
                $lang = todo_get_current_language();
            } elseif (function_exists('current_locale')) {
                $lang = current_locale();
            }
        }
        $prefix = self::get_language_prefix($lang);
        $order_no = $prefix . date('ymdHis') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        while (self::order_no_exists($order_no)) {
            $order_no = $prefix . date('ymdHis') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        }
        return $order_no;
    }

    /**
     * 获取订单对应的语言环境代码
     * 
     * @param int|array $order 订单ID或订单数据数组
     * @return string 语言代码（如 zh-CN, en-US）
     */
    public static function get_order_language($order) {
        $order_data = null;
        $order_id = 0;
        if (is_numeric($order)) {
            $order_id = (int)$order;
            $order_data = self::get_order($order_id, false);
        } elseif (is_array($order)) {
            $order_data = $order;
            $order_id = (int)($order['id'] ?? 0);
        }

        // 1. 从 Meta 元数据中获取
        if ($order_id > 0) {
            $saved_lang = self::get_order_custom_field($order_id, '_order_lang');
            if (!empty($saved_lang)) {
                return $saved_lang;
            }
        }

        if ($order_data) {
            if (!empty($order_data['custom_fields']['_order_lang'])) {
                return $order_data['custom_fields']['_order_lang'];
            }
            if (!empty($order_data['_order_lang'])) {
                return $order_data['_order_lang'];
            }
            if (!empty($order_data['lang'])) {
                return $order_data['lang'];
            }
        }

        // 2. 从订单号前缀（如 EN, ZH, JA, DE）解析推断
        $order_no = $order_data['order_no'] ?? '';
        if (!empty($order_no) && preg_match('/^([A-Z]{2})\d{12,}/', $order_no, $matches)) {
            $prefix = $matches[1];
            $prefix_map = [
                'ZH' => 'zh-CN',
                'EN' => 'en-US',
                'JA' => 'ja-JP',
                'KO' => 'ko-KR',
                'DE' => 'de-DE',
                'FR' => 'fr-FR',
                'ES' => 'es-ES',
                'RU' => 'ru-RU',
                'IT' => 'it-IT',
                'PT' => 'pt-PT',
                'AR' => 'ar-SA',
                'TH' => 'th-TH',
                'VI' => 'vi-VN',
            ];
            if (isset($prefix_map[$prefix])) {
                return $prefix_map[$prefix];
            }
        }

        // 3. 从前端设备指纹 language 尝试提取
        if ($order_id > 0) {
            $device_info = self::get_order_custom_field($order_id, '_device_info');
            if (!empty($device_info['language'])) {
                return $device_info['language'];
            }
        }

        return 'zh-CN';
    }

    /**
     * 获取订单状态的多语言翻译名称
     * 
     * @param string $status 状态键值
     * @param string $lang 语言代码（如 zh-CN, en-US）
     * @return string 翻译后的状态名称
     */
    public static function get_order_status_i18n($status, $lang = 'zh-CN') {
        $status = strtolower(trim((string) $status));
        
        $key_map = [
            'pending'          => '待付款',
            'unpaid'           => '未支付',
            'processing'       => '待发货',
            'paid'             => '已付款',
            'shipped'          => '已发货',
            'delivered'        => '已送达',
            'completed'        => '已完成',
            'cancelled'        => '已取消',
            'refund'           => '售后中',
            'refunding'        => '退款中',
            'refunded'         => '已退款',
            'partial_refunded' => '部分退款',
            'failed'           => '付款失败',
        ];

        $zh_text = $key_map[$status] ?? ($status ?: '未知');

        if (function_exists('__')) {
            return __($zh_text);
        }

        return $zh_text;
    }
    
    /**
     * 检查订单号是否存在
     * 
     * @param string $order_no 订单号
     * @return bool
     */
    private static function order_no_exists($order_no) {
        $query = "SELECT id FROM " . DB_PREFIX . "orders WHERE order_no = ?";
        $id = self::$tododb->get_var($query, [$order_no]);
        return !empty($id);
    }
    
    /**
     * 保存订单自定义字段
     * 
     * @param int $order_id 订单ID
     * @param string $field_name 字段名
     * @param mixed $field_value 字段值
     * @return bool
     */
    public static function save_order_custom_field($order_id, $field_name, $field_value) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
        }
        
        if (self::$tododb === null) {
            todo_log_error('Database connection not available in save_order_custom_field');
            return false;
        }
        
        $query = "INSERT INTO " . DB_PREFIX . "order_custom_fields 
                  (order_id, field_name, field_value, created_at) 
                  VALUES (?, ?, ?, NOW()) 
                  ON DUPLICATE KEY UPDATE 
                  field_value = VALUES(field_value), updated_at = NOW()";
        
        // 将数组类型的字段值序列化为JSON字符串
        $field_value_json = is_array($field_value) ? json_encode($field_value) : $field_value;
        
        // 使用CMS_DB的参数化查询执行
        $result = self::$tododb->query($query, [$order_id, $field_name, $field_value_json]);
        
        return $result !== false;
    }

    /**
     * 获取订单自定义字段
     * 
     * @param int $order_id 订单ID
     * @param string $field_name 字段名（可选）
     * @return array|string|null
     */
    public static function get_order_custom_fields($order_id, $field_name = null) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
        }
        
        if (self::$tododb === null) {
            todo_log_error('Database connection not available in get_order_custom_fields');
            return $field_name ? null : array();
        }
        
        if ($field_name) {
            $query = "SELECT field_value FROM " . DB_PREFIX . "order_custom_fields 
                      WHERE order_id = ? AND field_name = ?";
            
            // 使用CMS_DB获取单个值
            $value = self::$tododb->get_var($query, [$order_id, $field_name]);
            if ($value === null) {
                return null;
            }
            
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        } else {
            $query = "SELECT field_name, field_value FROM " . DB_PREFIX . "order_custom_fields 
                      WHERE order_id = ?";
            
            // 使用CMS_DB获取结果集
            $rows = self::$tododb->get_results($query, [$order_id]);
            
            $fields = array();
            foreach ($rows as $row) {
                // 兼容对象或数组结果
                $field_name_key = is_array($row) ? ($row['field_name'] ?? null) : ($row->field_name ?? null);
                $value = is_array($row) ? ($row['field_value'] ?? null) : ($row->field_value ?? null);
                if ($field_name_key === null) {
                    continue;
                }
                $decoded = json_decode($value, true);
                $fields[$field_name_key] = $decoded !== null ? $decoded : $value;
            }
            
            return $fields;
        }
    }

    /**
     * 获取单个订单自定义字段（别名）
     * 
     * @param int $order_id 订单ID
     * @param string $field_name 字段名
     * @return mixed
     */
    public static function get_order_custom_field($order_id, $field_name) {
        return self::get_order_custom_fields($order_id, $field_name);
    }
    
    /**
     * 删除订单自定义字段
     * 
     * @param int $order_id 订单ID
     * @param string $field_name 字段名（可选）
     * @return bool
     */
    public static function delete_order_custom_field($order_id, $field_name = null) {
        if ($field_name) {
            $query = "DELETE FROM " . DB_PREFIX . "order_custom_fields WHERE order_id = ? AND field_name = ?";
            $result = self::$tododb->query($query, [$order_id, $field_name]);
        } else {
            $query = "DELETE FROM " . DB_PREFIX . "order_custom_fields WHERE order_id = ?";
            $result = self::$tododb->query($query, [$order_id]);
        }
        return $result !== false;
    }
    
    /**
     * 添加订单日志
     * 
     * @param int $order_id 订单ID
     * @param string $action 操作类型
     * @param string $message 日志消息
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function add_order_log($order_id, $action, $message, $operator_id = 0) {
        if (!self::$tododb) {
            self::init();
        }
        $db = self::$tododb ?: ($GLOBALS['tododb'] ?? null);
        if (!$db) {
            return false;
        }
        $prefix = defined('DB_PREFIX') ? DB_PREFIX : (property_exists($db, 'prefix') ? $db->prefix : '');
        $table = $prefix . 'order_logs';
        
        // 写入订单日志
        $query = "INSERT INTO {$table} (order_id, action, message, operator_id, created_at) VALUES (?, ?, ?, ?, NOW())";
        $result = $db->query($query, [$order_id, $action, $message, $operator_id]);
        return $result !== false;
    }
    
    /**
     * 格式化/翻译订单日志消息，支持多语言动态适配
     *
     * @param string $message
     * @param string $action
     * @return string
     */
    public static function format_order_log_message($message, $action = '') {
        if (empty($message)) {
            return '';
        }

        // 状态映射表（包含原始 key 与中文名称，均映射至 __() 动态翻译）
        $label_map = array(
            'pending'          => __('待付款'),
            'unpaid'           => __('待付款'),
            'paid'             => __('已付款'),
            'processing'       => __('待发货'),
            'shipped'          => __('已发货'),
            'delivered'        => __('已送达'),
            'completed'        => __('已完成'),
            'cancelled'        => __('已取消'),
            'refunded'         => __('已退款'),
            'partial_refunded' => __('部分退款'),
            'failed'           => __('失败'),
            '待付款'           => __('待付款'),
            '已付款'           => __('已付款'),
            '待发货'           => __('待发货'),
            '已发货'           => __('已发货'),
            '已送达'           => __('已送达'),
            '已完成'           => __('已完成'),
            '已取消'           => __('已取消'),
            '已退款'           => __('已退款'),
            '部分退款'         => __('部分退款'),
            '失败'             => __('失败'),
            '付款失败'         => __('付款失败'),
        );

        // 针对 "订单状态: 从 'old' 变更为 'new'" 或 "支付状态: 从 'old' 变更为 'new'" 格式正则解析
        if (preg_match('/^(订单状态|支付状态|Order status|Payment status)\s*[:：]\s*(?:从|from)?\s*[\'\"]?([^\'\"\s\(\（]+)[\'\"]?\s*(?:变更为|changed to)\s*[\'\"]?([^\'\"\s\(\（]+)[\'\"]?(?:\s*[\(（](?:备注|Note)[：:]\s*(.*)[\)）])?/iu', $message, $matches)) {
            $type_str = $matches[1];
            $old_raw = $matches[2];
            $new_raw = $matches[3];
            $note = isset($matches[4]) ? trim($matches[4]) : '';

            $old_label = isset($label_map[$old_raw]) ? $label_map[$old_raw] : __($old_raw);
            $new_label = isset($label_map[$new_raw]) ? $label_map[$new_raw] : __($new_raw);

            $is_payment = (mb_strpos($type_str, '支付') !== false || mb_strpos($type_str, 'Payment') !== false);
            $formatted = $is_payment
                ? sprintf(__('支付状态：从 "%s" 变更为 "%s"'), $old_label, $new_label)
                : sprintf(__('订单状态：从 "%s" 变更为 "%s"'), $old_label, $new_label);

            if ($note !== '') {
                $formatted .= sprintf(__('（备注：%s）'), __($note));
            }
            return $formatted;
        }

        // 针对固定标准短语走 __() 翻译
        $known_phrases = array(
            '订单创建', '用户确认收货', '订单已取消', '订单已完成', '订单已发货',
            '添加支付记录', '添加发货记录', '用户取消支付', '用户提交评价',
            '支付成功', '下载订单创建', '虚拟订单支付完成，自动进入已完成状态'
        );
        if (in_array($message, $known_phrases, true)) {
            return __($message);
        }

        // 针对 "物流发货信息：xxx" 开头的替换
        if (strpos($message, '物流发货信息：') === 0 || strpos($message, '物流发货信息:') === 0) {
            $info = preg_replace('/^物流发货信息[:：]/u', '', $message);
            return __('物流发货信息') . ': ' . $info;
        }

        return __($message);
    }

    /**
     * 获取订单日志
     * 
     * @param int $order_id 订单ID
     * @param int $limit 数量限制
     * @return array
     */
    public static function get_order_logs($order_id, $limit = 50, $user_visible = false) {
        // 表不存在时安全返回空数组
        if (method_exists(self::$tododb, 'table_exists') && !self::$tododb->table_exists('order_logs')) {
            return [];
        }
        $query = "SELECT ol.*, u.user_login AS operator_name 
                  FROM " . DB_PREFIX . "order_logs ol 
                  LEFT JOIN " . DB_PREFIX . "users u ON ol.operator_id = u.ID 
                  WHERE ol.order_id = ? 
                  ORDER BY ol.created_at DESC, ol.id DESC 
                  LIMIT ?";
        try {
            $result = self::$tododb->get_results($query, [$order_id, $limit], 'ARRAY_A');
            $logs = is_array($result) ? $result : [];

            if ($user_visible) {
                $filtered_logs = [];
                
                // 1. 遍历底层日志，提取结构化生命周期节点
                foreach ($logs as $log) {
                    $action = $log['action'] ?? '';
                    $msg = $log['message'] ?? '';
                    $created_at = $log['created_at'] ?? '';

                    // 过滤内部敏感变更（管理员备注、客户备注、管理端添加支付记录等）
                    if (mb_strpos($msg, '管理员备注') !== false 
                        || mb_strpos($msg, '客户备注') !== false 
                        || mb_strpos($msg, '收货地址') !== false
                        || mb_strpos($msg, '添加支付记录') !== false) {
                        continue;
                    }

                    // 显式流程节点映射（数值越大，说明生命周期发生越晚；倒序排列时 priority 越大越排上面）
                    if ($action === 'order_created' || $msg === '订单创建') {
                        $filtered_logs[] = ['message' => __('订单创建'), 'created_at' => $created_at, 'priority' => 1];
                    } elseif ($action === 'confirm_received' || strpos($msg, '用户确认收货') !== false) {
                        $filtered_logs[] = ['message' => __('用户确认收货'), 'created_at' => $created_at, 'priority' => 5];
                    } elseif ($action === 'order_shipped' || $action === 'shipped' || strpos($msg, '订单已发货') !== false || strpos($msg, "变更为 '已发货'") !== false || strpos($msg, "变更为 'shipped'") !== false) {
                        $filtered_logs[] = ['message' => sprintf(__('订单状态：从 "%s" 变更为 "%s"'), __('待发货'), __('已发货')), 'created_at' => $created_at, 'priority' => 4];
                    } elseif ($action === 'payment_status_updated' || strpos($msg, '支付状态') !== false) {
                        $clean_msg = self::format_order_log_message($msg, $action);
                        if ($clean_msg !== '') {
                            $filtered_logs[] = ['message' => $clean_msg, 'created_at' => $created_at, 'priority' => 2];
                        }
                    } elseif ($action === 'status_updated' || strpos($msg, '变更为') !== false) {
                        // 过滤掉内部自动带有的“支付完成，自动进入待发货”说明，保留纯粹的状态变更
                        $clean_msg = self::format_order_log_message($msg, $action);
                        if ($clean_msg !== '') {
                            $filtered_logs[] = ['message' => $clean_msg, 'created_at' => $created_at, 'priority' => 3];
                        }
                    }
                }

                // 补全兜底：如果前台日志列表中缺失“订单创建”节点，自动从订单主表 created_at 填充“订单创建”
                $has_created_node = false;
                foreach ($filtered_logs as $fl) {
                    if (($fl['priority'] ?? 0) === 1 || $fl['message'] === __('订单创建') || $fl['message'] === '订单创建') {
                        $has_created_node = true;
                        break;
                    }
                }
                if (!$has_created_node) {
                    $ord_row = self::get_order($order_id, false);
                    $ord_created_at = $ord_row['created_at'] ?? date('Y-m-d H:i:s');
                    $filtered_logs[] = ['message' => __('订单创建'), 'created_at' => $ord_created_at, 'priority' => 1];
                }

                // 2. 关联物流数据源节点 (order_logistics)
                $shipping_records = self::get_shipping_records($order_id);
                if (!empty($shipping_records)) {
                    foreach ($shipping_records as $shipping) {
                        $company = $shipping['express_company'] ?? '';
                        $no = $shipping['tracking_no'] ?? '';
                        if ($company !== '' || $no !== '') {
                            $filtered_logs[] = [
                                'message' => __("物流发货信息") . "：" . trim($company . ' ' . $no),
                                'created_at' => $shipping['created_at'] ?? date('Y-m-d H:i:s'),
                                'priority' => 4
                            ];
                        }
                    }
                }

                // 3. 按消息内容去重（里程碑节点全局按消息唯一去重，普通变更按消息+时间戳去重）
                $unique_logs = [];
                $seen_keys = [];
                foreach ($filtered_logs as $fl) {
                    $msg = $fl['message'] ?? '';
                    if ($msg === __('用户确认收货') || $msg === '用户确认收货' || $msg === __('订单创建') || $msg === '订单创建') {
                        $key = 'single_milestone_' . $msg;
                    } else {
                        $key = $msg . '_' . ($fl['created_at'] ?? '');
                    }
                    if (!isset($seen_keys[$key])) {
                        $seen_keys[$key] = true;
                        $unique_logs[] = $fl;
                    }
                }

                // 4. 严格排序：时间优先降序；时间相同时按生命周期节点优先级 (priority) 降序
                usort($unique_logs, function($a, $b) {
                    $t1 = strtotime($a['created_at']);
                    $t2 = strtotime($b['created_at']);
                    if ($t1 === $t2) {
                        return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
                    }
                    return $t2 <=> $t1;
                });

                // 移除仅内部使用的 priority 字段
                return array_map(function($item) {
                    unset($item['priority']);
                    return $item;
                }, array_slice($unique_logs, 0, $limit));
            }

            return array_map(function($log) {
                if (isset($log['message'])) {
                    $log['message'] = self::format_order_log_message($log['message'], $log['action'] ?? '');
                }
                return $log;
            }, $logs);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * 更新订单状态
     * 
     * @param int $order_id 订单ID
     * @param string $status 新状态
     * @param int $operator_id 操作者ID
     * @param string $notes 备注
     * @return bool
     */
    public static function update_order_status($order_id, $status, $operator_id = 0, $notes = '') {
        unset(self::$orders_cache[$order_id . '_no_items'], self::$orders_cache[$order_id . '_with_items']);
        $old_order = self::get_order($order_id, false);
        if (!$old_order) {
            return false;
        }
        
        // 虚拟商品订单，如更新目标为 processing/shipped 等中间发货状态，自动转为 completed 已完成
        if ($status === self::STATUS_PROCESSING || $status === 'processing' || $status === 'shipped' || $status === 'preparing' || $status === self::STATUS_COMPLETED || $status === 'completed') {
            if (self::is_virtual_order($order_id)) {
                self::process_auto_license_delivery($order_id);
                $status = self::STATUS_COMPLETED;
                $notes = $notes ?: __('虚拟商品订单，支付成功自动完成');
            }
        }

        // 幂等保护：若当前订单状态与目标状态一致，直接返回，避免重复更新与写入重复日志
        if (isset($old_order['status']) && (string) $old_order['status'] === (string) $status) {
            return true;
        }
        
        $query = "UPDATE " . DB_PREFIX . "orders SET status = ?, updated_at = NOW() WHERE id = ?";
        $result = self::$tododb->query($query, [$status, $order_id]);
        
        if ($result !== false) {
            // 立即清除缓存
            unset(self::$orders_cache[$order_id]);
            // 记录状态变更日志（存存原始状态 key，前端/查询时动态适配多语言）
            $old_status_key = $old_order['status'] ?? '';
            $new_status_key = $status;
            $message = "订单状态: 从 '" . $old_status_key . "' 变更为 '" . $new_status_key . "'";
            if (!empty($notes)) {
                $message .= " (" . __("备注") . "：" . $notes . ")";
            }
            
            self::add_order_log($order_id, 'status_updated', $message, $operator_id);
            
            // 订单进入已发货状态时，自动触发管理员邮件通知
            if ($status === self::STATUS_SHIPPED || $status === 'shipped') {
                if (function_exists('todo_notify_admin_order_shipping')) {
                    todo_notify_admin_order_shipping($order_id);
                }
            }

            // 订单进入已完成状态时，自动触发积分扣减与订单完成钩子
            if ($status === self::STATUS_COMPLETED || $status === 'completed') {
                self::process_order_points_deduction($order_id);
                if (function_exists('do_action')) {
                    do_action('todo_order_completed', $order_id);
                }
            }

            // 订单进入已取消或已退款状态时，自动触发已扣减积分退还
            if (in_array($status, [self::STATUS_CANCELLED, 'cancelled', self::STATUS_REFUNDED, 'refunded', 'partial_refunded'], true)) {
                self::process_order_points_refund($order_id, $operator_id, $notes);
            }

            // 订单进入已退款状态时，自动触发余额原路退款
            if (in_array($status, [self::STATUS_REFUNDED, 'refunded'], true)) {
                self::process_order_balance_refund($order_id, $operator_id, $notes);
            }

            // 售后业务闭环：当订单状态被更新为已退款或已取消时，联动更新售后申请表
            if (in_array($status, [self::STATUS_REFUNDED, 'refunded'], true) && isset(self::$tododb) && is_object(self::$tododb)) {
                $now_time = date('Y-m-d H:i:s');
                self::$tododb->query(self::$tododb->prepare(
                    "UPDATE " . DB_PREFIX . "refunds SET status = 'completed', processed_at = %s, updated_at = %s WHERE order_id = %d AND status IN ('pending', 'processing')",
                    $now_time,
                    $now_time,
                    $order_id
                ));
            } elseif (in_array($status, [self::STATUS_CANCELLED, 'cancelled'], true) && isset(self::$tododb) && is_object(self::$tododb)) {
                $now_time = date('Y-m-d H:i:s');
                self::$tododb->query(self::$tododb->prepare(
                    "UPDATE " . DB_PREFIX . "refunds SET status = 'cancelled', updated_at = %s WHERE order_id = %d AND status = 'pending'",
                    $now_time,
                    $order_id
                ));
            }

            // 清除缓存
            unset(self::$orders_cache[$order_id]);
            
            // 自动同步 AB 订单状态与变更到对端（A站 <-> B站）
            if (class_exists('Todo_AB_Payment_API')) {
                $order_raw = self::$tododb->get_row("SELECT ab_order_type FROM " . DB_PREFIX . "orders WHERE id = " . intval($order_id));
                $ab_type = is_object($order_raw) ? ($order_raw->ab_order_type ?? '') : (is_array($order_raw) ? ($order_raw['ab_order_type'] ?? '') : '');
                if (!empty($ab_type)) {
                    $ab_api = new Todo_AB_Payment_API();
                    $ab_api->sync_ab_order($order_id);
                }
            }

            return true;
        }
        
        return false;
    }
    
    /**
     * 更新支付状态
     * 
     * @param int $order_id 订单ID
     * @param string $payment_status 支付状态
     * @param float $paid_amount 已付金额
     * @param int $operator_id 操作者ID
     * @param string $notes 备注
     * @return bool
     */
    public static function update_payment_status($order_id, $payment_status, $paid_amount = null, $operator_id = 0, $notes = '') {
        unset(self::$orders_cache[$order_id . '_no_items'], self::$orders_cache[$order_id . '_with_items']);
        $old_order = self::get_order($order_id, false);
        if (!$old_order) {
            return false;
        }
        
        $extra_data = array();
        $numeric_paid_amount = null;
        
        if (is_array($paid_amount)) {
            $extra_data = $paid_amount;
            if (isset($extra_data['paid_amount'])) {
                $numeric_paid_amount = floatval($extra_data['paid_amount']);
            }
        } elseif ($paid_amount !== null && is_numeric($paid_amount)) {
            $numeric_paid_amount = floatval($paid_amount);
        }

        // 幂等保护：若当前支付状态与目标一致、无新增已付金额且无补全的 transaction_id/paid_at，直接返回
        $has_extra_updates = is_array($paid_amount) && (!empty($paid_amount['transaction_id']) || !empty($paid_amount['paid_at']) || !empty($paid_amount['payment_time']));
        if (isset($old_order['payment_status']) && (string) $old_order['payment_status'] === (string) $payment_status && $numeric_paid_amount === null && !$has_extra_updates) {
            return true;
        }
        
        $set_clauses = array('payment_status = ?', 'updated_at = NOW()');
        $params = array($payment_status);
        
        // 动态附加 payment_amount/paid_at/payment_method/transaction_id 等有效表字段
        if ($numeric_paid_amount !== null) {
            $set_clauses[] = 'payment_amount = ?';
            $params[] = $numeric_paid_amount;
        }
        if (!empty($extra_data['payment_method'])) {
            $set_clauses[] = 'payment_method = ?';
            $params[] = $extra_data['payment_method'];
        }
        if (!empty($extra_data['transaction_id'])) {
            $set_clauses[] = 'transaction_id = ?';
            $params[] = $extra_data['transaction_id'];
        }
        $now_time = current_time('mysql');
        if ($payment_status === self::PAYMENT_PAID || $payment_status === 'paid') {
            if (empty($extra_data['paid_at']) && empty($extra_data['payment_time'])) {
                $extra_data['paid_at'] = $now_time;
                $extra_data['payment_time'] = $now_time;
            }
        }

        if (!empty($extra_data['paid_at'])) {
            $set_clauses[] = 'paid_at = ?';
            $params[] = $extra_data['paid_at'];
        }
        
        $params[] = $order_id;
        
        $query = "UPDATE " . DB_PREFIX . "orders SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        
        $res = self::$tododb->query($query, $params);
        if ($res !== false) {
            // 立即清除缓存
            unset(self::$orders_cache[$order_id]);
            // 1. 如果支付完成，首先写入支付水表记录
            if ($payment_status === self::PAYMENT_PAID || $payment_status === 'paid') {
                $method = !empty($extra_data['payment_method']) ? $extra_data['payment_method'] : ($old_order['payment_method'] ?? 'online');
                $amount = $numeric_paid_amount !== null ? $numeric_paid_amount : (!empty($old_order['payment_amount']) && floatval($old_order['payment_amount']) > 0 ? floatval($old_order['payment_amount']) : floatval($old_order['total_amount'] ?? 0));
                $tx_id = !empty($extra_data['transaction_id']) ? $extra_data['transaction_id'] : ($old_order['transaction_id'] ?? '');
                $paid_time = !empty($extra_data['paid_at']) ? $extra_data['paid_at'] : date('Y-m-d H:i:s');
                
                self::add_payment_record($order_id, array(
                    'payment_method' => $method,
                    'amount' => $amount,
                    'transaction_id' => $tx_id,
                    'payment_status' => 'paid',
                    'notes' => $notes ?: __('订单支付成功'),
                    'payment_time' => $paid_time
                ));

                // 扣减订单抵扣的积分数（如果订单使用了积分抵扣且尚未扣减）
                self::process_order_points_deduction($order_id);
            }

            // 2. 记录支付状态变更日志（仅在支付状态字面值发生真实变化时记录）
            if ($old_order['payment_status'] !== $payment_status) {
                $old_pstatus_key = $old_order['payment_status'] ?? 'unpaid';
                if ($old_pstatus_key === 'pending' || $old_pstatus_key === '') {
                    $old_pstatus_key = 'unpaid';
                }
                $new_pstatus_key = $payment_status;
                $message = "支付状态: 从 '" . $old_pstatus_key . "' 变更为 '" . $new_pstatus_key . "'";
                if (!empty($notes)) {
                    $message .= " (" . __("备注") . "：" . $notes . ")";
                }
                self::add_order_log($order_id, 'payment_status_updated', $message, $operator_id);
            }
            
            // 3. 最终因支付成功，触发主订单状态走向与管理员邮件通知
            if ($payment_status === self::PAYMENT_PAID || $payment_status === 'paid') {
                if (function_exists('todo_notify_admin_order_payment')) {
                    todo_notify_admin_order_payment($old_order ?: $order_id);
                }
                if (self::is_virtual_order($old_order ?: $order_id)) {
                    // 处理虚拟商品自动发卡/授权码生成
                    self::process_auto_license_delivery($order_id);
                    // 虚拟订单不需要发货与物流，支付成功后直接走到【已完成】
                    self::update_order_status($order_id, self::STATUS_COMPLETED, $operator_id, __('虚拟订单支付完成，自动进入已完成状态'));
                    try {
                        self::$tododb->query("UPDATE " . DB_PREFIX . "orders SET shipping_status = 'delivered', shipped_at = NOW() WHERE id = ?", [$order_id]);
                    } catch (\Exception $e) {}
                } else {
                    $fresh_st = self::$tododb->get_var(self::$tododb->prepare("SELECT status FROM " . DB_PREFIX . "orders WHERE id = %d", $order_id));
                    if (!in_array($fresh_st, [self::STATUS_PROCESSING, 'processing', self::STATUS_SHIPPED, 'shipped', self::STATUS_COMPLETED, 'completed'], true)) {
                        self::update_order_status($order_id, self::STATUS_PROCESSING, $operator_id, '');
                    }
                }
            }
            
            // 支付状态变更为已退款/部分退款时，自动触发已扣减积分退还
            if (in_array($payment_status, [self::PAYMENT_REFUNDED, 'refunded', 'partial_refunded'], true)) {
                self::process_order_points_refund($order_id, $operator_id, $notes);
            }

            // 支付状态变更为已退款时，自动触发余额原路退款
            if (in_array($payment_status, [self::PAYMENT_REFUNDED, 'refunded'], true)) {
                self::process_order_balance_refund($order_id, $operator_id, $notes);
            }

            // 售后业务闭环：当支付状态被更新为已退款时，联动更新售后申请表
            if (in_array($payment_status, [self::PAYMENT_REFUNDED, 'refunded'], true) && isset(self::$tododb) && is_object(self::$tododb)) {
                $now_time = date('Y-m-d H:i:s');
                self::$tododb->query(self::$tododb->prepare(
                    "UPDATE " . DB_PREFIX . "refunds SET status = 'completed', processed_at = %s, updated_at = %s WHERE order_id = %d AND status IN ('pending', 'processing')",
                    $now_time,
                    $now_time,
                    $order_id
                ));
            }
            
            // 清除缓存
            unset(self::$orders_cache[$order_id]);

            // 自动同步 AB 订单状态与变更到对端（A站 <-> B站）
            if (class_exists('Todo_AB_Payment_API')) {
                $order_raw = self::$tododb->get_row("SELECT ab_order_type FROM " . DB_PREFIX . "orders WHERE id = " . intval($order_id));
                $ab_type = is_object($order_raw) ? ($order_raw->ab_order_type ?? '') : (is_array($order_raw) ? ($order_raw['ab_order_type'] ?? '') : '');
                if (!empty($ab_type)) {
                    $ab_api = new Todo_AB_Payment_API();
                    $ab_api->sync_ab_order($order_id);
                }
            }

            return true;
        }
        
        return false;
    }

    /**
     * 扣减订单消费/抵扣的积分，并记录积分动态日志
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    public static function process_order_points_deduction($order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $order = self::get_order($order_id, false);
        if (!$order) {
            return false;
        }

        $user_id = intval($order['user_id'] ?? 0);
        if ($user_id <= 0) {
            return false;
        }

        $points_used = intval(self::get_order_custom_field($order_id, '_points_used') ?: 0);
        $points_deducted = self::get_order_custom_field($order_id, '_points_deducted');

        if ($points_used <= 0 || !empty($points_deducted)) {
            return false;
        }

        $order_no = !empty($order['order_no']) ? $order['order_no'] : $order_id;
        $desc = "订单 {$order_no} 抵扣";
        $action = 'order_deduction';

        // 确保 Points_Manager 已加载
        if (!class_exists('Points_Manager') && file_exists(ABSPATH . 'core/classes/class-points-manager.php')) {
            require_once ABSPATH . 'core/classes/class-points-manager.php';
        }

        $deducted = false;
        if (class_exists('Points_Manager')) {
            $pts_mgr = Points_Manager::get_instance();
            $deducted = $pts_mgr->deduct_points($user_id, $points_used, $action, $desc);
        }

        if (!$deducted) {
            // 兜底扣减处理并写入 user_points_log 表
            $cur_pts = (float) get_user_meta($user_id, '_user_points', true) ?: 0;
            $actual_deduct = min($cur_pts, $points_used);
            $new_pts = max(0, $cur_pts - $points_used);
            update_user_meta($user_id, '_user_points', $new_pts);

            if (self::$tododb) {
                $table_name = DB_PREFIX . 'user_points_log';
                self::$tododb->insert(
                    $table_name,
                    [
                        'user_id' => $user_id,
                        'amount' => -$actual_deduct,
                        'balance_after' => $new_pts,
                        'type' => 'spend',
                        'action' => $action,
                        'description' => $desc,
                        'created_at' => current_time('mysql')
                    ],
                    ['%d', '%f', '%f', '%s', '%s', '%s', '%s']
                );
            }
            $deducted = true;
        }

        if ($deducted) {
            self::save_order_custom_field($order_id, '_points_deducted', '1');
            return true;
        }

        return false;
    }

    /**
     * 退还订单消费/抵扣的积分，并记录积分动态日志
     * 
     * @param int $order_id 订单ID
     * @param int $operator_id 操作者ID
     * @param string $reason 退款/取消原因
     * @return bool
     */
    public static function process_order_points_refund($order_id, $operator_id = 0, $reason = '') {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $order = self::get_order($order_id, false);
        if (!$order) {
            return false;
        }

        $user_id = intval($order['user_id'] ?? 0);
        if ($user_id <= 0) {
            return false;
        }

        $points_used = intval(self::get_order_custom_field($order_id, '_points_used') ?: 0);
        $points_deducted = self::get_order_custom_field($order_id, '_points_deducted');
        $points_refunded = self::get_order_custom_field($order_id, '_points_refunded');

        // 仅当订单之前已经扣减过积分（_points_deducted === '1'）且尚未退还过（_points_refunded 为空）时才执行退还
        if ($points_used <= 0 || empty($points_deducted) || !empty($points_refunded)) {
            return false;
        }

        $order_no = !empty($order['order_no']) ? $order['order_no'] : $order_id;
        $desc = sprintf(__('订单 %s 退款/取消返还'), $order_no);
        if (!empty($reason)) {
            $desc .= " (" . sanitize_text_field($reason) . ")";
        }
        $action = 'order_cancel_refund';

        // 确保 Points_Manager 已加载
        if (!class_exists('Points_Manager') && file_exists(ABSPATH . 'core/classes/class-points-manager.php')) {
            require_once ABSPATH . 'core/classes/class-points-manager.php';
        }

        $refunded = false;
        if (class_exists('Points_Manager')) {
            $pts_mgr = Points_Manager::get_instance();
            $refunded = $pts_mgr->add_points($user_id, $points_used, $action, $desc);
        }

        if (!$refunded) {
            // 兜底退还处理并写入 user_points_log 表
            $cur_pts = (float) get_user_meta($user_id, '_user_points', true) ?: 0;
            $new_pts = $cur_pts + $points_used;
            update_user_meta($user_id, '_user_points', $new_pts);

            if (self::$tododb) {
                $table_name = DB_PREFIX . 'user_points_log';
                self::$tododb->insert(
                    $table_name,
                    [
                        'user_id' => $user_id,
                        'amount' => $points_used,
                        'balance_after' => $new_pts,
                        'type' => 'earn',
                        'action' => $action,
                        'description' => $desc,
                        'created_at' => current_time('mysql')
                    ],
                    ['%d', '%f', '%f', '%s', '%s', '%s', '%s']
                );
            }
            $refunded = true;
        }

        if ($refunded) {
            self::save_order_custom_field($order_id, '_points_refunded', '1');
            return true;
        }

        return false;
    }

    /**
     * 原路退款：对于使用余额支付的订单，在订单退款时自动将扣除的余额返还至用户账户
     * 遵循国际反洗钱及电商支付原路退回规范，记录流水并防止重复退款
     *
     * @param int $order_id 订单ID
     * @param int $operator_id 操作员ID
     * @param string $reason 退款原因/备注
     * @return bool
     */
    public static function process_order_balance_refund($order_id, $operator_id = 0, $reason = '') {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $order = self::get_order($order_id, false);
        if (!$order) {
            return false;
        }

        $user_id = intval($order['user_id'] ?? 0);
        if ($user_id <= 0) {
            return false;
        }

        // 仅对使用余额支付（payment_method === 'balance'）的订单执行余额原路退还
        $payment_method = $order['payment_method'] ?? '';
        if ($payment_method !== 'balance') {
            return false;
        }

        // 防重检查：已退还过则直接跳过
        $balance_refunded = self::get_order_custom_field($order_id, '_balance_refunded');
        if (!empty($balance_refunded)) {
            return false;
        }

        // 退还金额计算：优先取已支付金额（payment_amount），若为0则取总金额（total_amount）
        $refund_amount = floatval($order['payment_amount'] ?? 0);
        if ($refund_amount <= 0) {
            $refund_amount = floatval($order['total_amount'] ?? 0);
        }
        if ($refund_amount <= 0) {
            return false;
        }

        $order_no = !empty($order['order_no']) ? $order['order_no'] : (string) $order_id;
        $desc = sprintf(__('订单 %s 售后退款原路返还'), $order_no);
        if (!empty($reason)) {
            $desc .= " (" . sanitize_text_field($reason) . ")";
        }

        $success = false;
        if (function_exists('todo_core_add_user_balance')) {
            $success = todo_core_add_user_balance($user_id, $refund_amount, 'order_refund', $desc, $order_id);
        } else {
            $cur_bal = function_exists('todo_get_user_balance') ? todo_get_user_balance($user_id) : 0;
            $new_bal = $cur_bal + $refund_amount;
            if (isset(self::$tododb) && is_object(self::$tododb)) {
                $tb = DB_PREFIX . 'member_balance';
                $res = self::$tododb->insert($tb, [
                    'user_id' => $user_id,
                    'amount' => $refund_amount,
                    'balance_after' => $new_bal,
                    'type' => 'order_refund',
                    'description' => $desc,
                    'order_id' => $order_id,
                    'created_at' => current_time('mysql')
                ]);
                if ($res) {
                    if (function_exists('update_user_meta')) {
                        update_user_meta($user_id, 'todo_balance', $new_bal);
                    }
                    $success = true;
                }
            }
        }

        if ($success) {
            self::save_order_custom_field($order_id, '_balance_refunded', '1');
            $fmt_amt = function_exists('todo_format_price') ? todo_format_price($refund_amount) : number_format($refund_amount, 2);
            $log_msg = sprintf(__('余额原路退款成功：已退还 %s 至用户余额账户'), $fmt_amt);
            self::add_order_log($order_id, 'balance_refunded', $log_msg, $operator_id);
            return true;
        }

        return false;
    }
    
    /**
     * 发货
     * 
     * @param int $order_id 订单ID
     * @param array $shipping_data 发货数据
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function ship_order($order_id, $shipping_data, $operator_id = 0) {
        $update_data = array(
            'status' => self::STATUS_SHIPPED,
            'tracking_no' => $shipping_data['tracking_no'] ?? '',
            'express_company' => $shipping_data['express_company'] ?? ''
        );
        
        if (self::update_order($order_id, $update_data, $operator_id)) {
            $message = __('订单已发货');
            if (!empty($shipping_data['tracking_no'])) {
                $message .= "，" . __("快递单号") . "：" . $shipping_data['tracking_no'];
            }
            if (!empty($shipping_data['express_company'])) {
                $message .= "，" . __("快递公司") . "：" . $shipping_data['express_company'];
            }
            
            self::add_order_log($order_id, 'order_shipped', $message, $operator_id);

            if (function_exists('todo_notify_admin_order_shipping')) {
                todo_notify_admin_order_shipping($order_id, $shipping_data);
            }

            return true;
        }
        
        return false;
    }
    
    /**
     * 取消订单
     * 
     * @param int $order_id 订单ID
     * @param string $reason 取消原因
     * @param int $operator_id 操作者ID
     * @return bool
     */
    public static function cancel_order($order_id, $reason = '', $operator_id = 0) {
        $order = self::get_order($order_id, false);
        if (!$order) {
            return false;
        }
        
        // 只有待付款和已付款的订单可以取消
        if (!in_array($order['status'], array(self::STATUS_PENDING, self::STATUS_PAID))) {
            return false;
        }
        
        if (self::update_order_status($order_id, self::STATUS_CANCELLED, $operator_id)) {
            // 国际标准：订单取消，解冻还原用户优惠券状态
            if (empty($order['payment_status']) || $order['payment_status'] === self::PAYMENT_PENDING || $order['payment_status'] === self::PAYMENT_CANCELLED) {
                global $tododb;
                $user_coupons_table = $tododb->prefix . 'user_coupons';
                $coupons_table = $tododb->prefix . 'coupons';

                $order_user_id = (int) ($order['user_id'] ?? 0);
                $order_coupon_id = (int) ($order['coupon_id'] ?? 0);

                // 优先查绑定了该 order_id 的记录
                $sql_uc = $tododb->prepare(
                    "SELECT id, coupon_id FROM {$user_coupons_table} WHERE order_id = %d LIMIT 1",
                    (int) $order_id
                );
                $uc = $tododb->get_row($sql_uc, [], 'ARRAY_A');

                // 兜底：若 order_id 未回写，根据 order['coupon_id'] 和 user_id 查该用户最热的 used 状态记录
                if (!$uc && $order_coupon_id > 0 && $order_user_id > 0) {
                    $sql_uc_fallback = $tododb->prepare(
                        "SELECT id, coupon_id FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d AND status = 'used' ORDER BY used_at DESC LIMIT 1",
                        $order_coupon_id, $order_user_id
                    );
                    $uc = $tododb->get_row($sql_uc_fallback, [], 'ARRAY_A');
                }

                if ($uc) {
                    $uc_id = (int) ($uc['id'] ?? 0);
                    $c_id = (int) ($uc['coupon_id'] ?? 0);
                    if ($uc_id > 0) {
                        // 还原用户卡券状态为 unused
                        $tododb->update($user_coupons_table, [
                            'status' => 'unused',
                            'used_at' => null,
                            'order_id' => null
                        ], ['id' => $uc_id]);

                        // 扣回使用的计数
                        if ($c_id > 0) {
                            $tododb->query($tododb->prepare(
                                "UPDATE {$coupons_table} SET used_count = GREATEST(0, used_count - 1) WHERE id = %d",
                                $c_id
                            ));
                        }
                    }
                }
            }

            $message = __('订单已取消');
            if (!empty($reason)) {
                $message .= "，".__('原因') . ': ' . $reason;
            }
            
            self::add_order_log($order_id, 'order_cancelled', $message, $operator_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * 完成订单
     * 
     * @param int $order_id 订单ID
     * @param int $operator_id 操作者ID
     * @param string $notes 备注
     * @return bool
     */
    public static function complete_order($order_id, $operator_id = 0, $notes = '') {
        if (self::update_order_status($order_id, self::STATUS_COMPLETED, $operator_id, $notes)) {
            self::add_order_log($order_id, 'order_completed', __('订单已完成'), $operator_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取状态选项
     * 
     * @return array
     */
    public static function get_status_options() {
        return array(
            self::STATUS_PENDING => __('待付款'),
            self::STATUS_PAID => __('已付款'),
            self::STATUS_PROCESSING => __('待发货'),
            self::STATUS_SHIPPED => __('已发货'),
            self::STATUS_DELIVERED => __('已送达'),
            self::STATUS_COMPLETED => __('已完成'),
            self::STATUS_CANCELLED => __('已取消'),
            self::STATUS_REFUND => __('售后中'),
            self::STATUS_REFUNDED => __('已退款')
        );
    }
    
    /**
     * 获取支付状态选项
     * 
     * @return array
     */
    public static function get_payment_status_options() {
        return array(
            self::PAYMENT_PENDING => __('待付款'),
            self::PAYMENT_PAID => __('已付款'),
            self::PAYMENT_FAILED => __('付款失败'),
            self::PAYMENT_REFUNDED => __('已退款'),
            self::PAYMENT_PARTIAL_REFUNDED => __('部分退款')
        );
    }
    
    /**
     * 获取发货方式选项
     * 
     * @return array
     */
    public static function get_delivery_method_options() {
        return array(
            self::DELIVERY_EXPRESS => '快递发货',
            self::DELIVERY_DOWNLOAD => '网盘下载',
            self::DELIVERY_TEXT => '文本内容',
            self::DELIVERY_MANUAL => '手动发货'
        );
    }
    
    /**
     * 删除订单
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    public static function delete_order($order_id) {
        $order = self::get_order($order_id, false);
        if (!$order) {
            return false;
        }
        
        // 开始事务
        self::$tododb->begin_transaction();
        
        try {
            // 删除订单项目
            $result = self::$tododb->query("DELETE FROM " . DB_PREFIX . "order_items WHERE order_id = ?", [$order_id]);
            if ($result === false) {
                throw new Exception('Failed to delete order_items: ' . self::$tododb->get_last_error());
            }
            
            // 删除自定义字段
            $result = self::$tododb->query("DELETE FROM " . DB_PREFIX . "order_custom_fields WHERE order_id = ?", [$order_id]);
            if ($result === false) {
                throw new Exception('Failed to delete order_custom_fields: ' . self::$tododb->get_last_error());
            }
            
            // 删除订单日志
            $result = self::$tododb->query("DELETE FROM " . DB_PREFIX . "order_logs WHERE order_id = ?", [$order_id]);
            if ($result === false) {
                throw new Exception('Failed to delete order_logs: ' . self::$tododb->get_last_error());
            }
            
            // 删除订单主记录
            $result = self::$tododb->query("DELETE FROM " . DB_PREFIX . "orders WHERE id = ?", [$order_id]);
            if ($result === false) {
                throw new Exception('Failed to delete order: ' . self::$tododb->get_last_error());
            }
            
            // 提交事务
            self::$tododb->commit();
            
            // 清除缓存
            unset(self::$orders_cache[$order_id]);
            
            return true;
            
        } catch (Exception $e) {
            // 回滚事务
            self::$tododb->rollback();
            todo_log_error('Order deletion failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建下载订单
     * 
     * @param array $order_data 订单数据
     * @return int|false 订单ID或false
     */
    public static function create_download_order($order_data) {
        // 设置为下载订单
        $order_data['delivery_method'] = self::DELIVERY_DOWNLOAD;
        $order_data['is_download_order'] = 1;
        
        // 创建订单
        $order_id = self::create_order($order_data);
        
        if ($order_id) {
            // 记录下载订单日志
            self::add_order_log($order_id, 'download_order_created', __('下载订单创建'), $order_data['user_id']);
        }
        
        return $order_id;
    }
    
    /**
     * 检查下载权限
     * 
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID
     * @return array
     */
    public static function check_download_permission($order_id, $user_id) {
        $order = self::get_order($order_id, false);
        
        if (!$order) {
            return array('allowed' => false, 'message' => '订单不存在');
        }
        
        if ($order['user_id'] != $user_id) {
            return array('allowed' => false, 'message' => '无权限访问此订单');
        }
        
        if ($order['payment_status'] !== self::PAYMENT_PAID) {
            return array('allowed' => false, 'message' => '订单待付款');
        }
        
        if ($order['status'] === self::STATUS_CANCELLED) {
            return array('allowed' => false, 'message' => '订单已取消');
        }
        
        return array('allowed' => true, 'message' => '允许下载');
    }
    
    /**
     * 记录下载日志
     * 
     * @param int $order_id 订单ID
     * @param int $product_id 商品ID
     * @param string $file_path 文件路径
     * @param string $file_name 文件名称
     * @return bool
     */
    public static function log_download($order_id, $product_id, $file_path, $file_name) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
            if (self::$tododb === null) {
                todo_log_error('Database connection not available in log_download');
                return false;
            }
        }
        
        $query = "INSERT INTO " . DB_PREFIX . "download_logs 
                  (order_id, product_id, file_path, file_name, download_time, ip_address) 
                  VALUES (?, ?, ?, ?, NOW(), ?)";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        
        return self::$tododb->query($query, [$order_id, $product_id, $file_path, $file_name, $ip_address]);
    }
    
    /**
     * 添加支付记录
     * 
     * @param int $order_id 订单ID
     * @param array $payment_data 支付数据
     * @return bool
     */
    public static function add_payment_record($order_id, $payment_data) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
            if (self::$tododb === null) {
                todo_log_error('Database connection not available in add_payment_record');
                return false;
            }
        }
        
        $defaults = array(
            'payment_method' => '',
            'amount' => 0,
            'transaction_id' => '',
            'payment_status' => 'paid',
            'notes' => '',
            'payment_time' => null
        );
        
        $payment_data = array_merge($defaults, $payment_data);
        if (empty($payment_data['payment_method']) && !empty($payment_data['method'])) {
            $payment_data['payment_method'] = $payment_data['method'];
        }
        
        $query = "INSERT INTO " . DB_PREFIX . "order_payments 
                  (order_id, payment_method, amount, transaction_id, payment_status, notes, payment_time, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $payment_time = $payment_data['payment_time'] ?: date('Y-m-d H:i:s');
        
        $params = [
            $order_id,
            $payment_data['payment_method'],
            $payment_data['amount'],
            $payment_data['transaction_id'],
            $payment_data['payment_status'],
            $payment_data['notes'],
            $payment_time
        ];
        
        if (self::$tododb->query($query, $params)) {
            // 记录日志
            $message = __('添加支付记录') . ': ' . $payment_data['payment_method'] . ', ' . __('金额') . ': ' . $payment_data['amount'];
            self::add_order_log($order_id, 'payment_record_added', $message);
            return true;
        }
        
        return false;
    }
    
    /**
     * 添加发货记录
     * 
     * @param int $order_id 订单ID
     * @param array $shipping_data 发货数据
     * @return bool
     */
    public static function add_shipping_record($order_id, $shipping_data) {
        // 确保数据库连接已初始化
        if (self::$tododb === null) {
            self::init();
            if (self::$tododb === null) {
                todo_log_error('Database connection not available in add_shipping_record');
                return false;
            }
        }
        
        $defaults = array(
            'express_company' => '',
            'tracking_no' => '',
            'shipping_address' => '',
            'notes' => '',
            'shipping_time' => null
        );
        
        $shipping_data = array_merge($defaults, $shipping_data);
        if (empty($shipping_data['express_company']) && !empty($shipping_data['company'])) {
            $shipping_data['express_company'] = $shipping_data['company'];
        }
        $express_company = $shipping_data['express_company'];
        $tracking_no = trim((string) $shipping_data['tracking_no']);

        // 防重检查：如果同一个订单下该单号与快递公司记录已存在，则跳过重复插入
        $shipments_table = DB_PREFIX . 'order_shipments';
        if (!empty($tracking_no) && (method_exists(self::$tododb, 'table_exists') ? self::$tododb->table_exists('order_shipments') : true)) {
            $check_sql = "SELECT id FROM {$shipments_table} WHERE order_id = ? AND tracking_no = ? AND express_company = ? LIMIT 1";
            $exist_id = self::$tododb->get_var($check_sql, [$order_id, $tracking_no, $express_company]);
            if ($exist_id) {
                return true; // 视为添加成功
            }
        }
        
        $query = "INSERT INTO {$shipments_table} 
                  (order_id, express_company, tracking_no, shipping_address, notes, shipping_time, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $shipping_time = $shipping_data['shipping_time'] ?: date('Y-m-d H:i:s');
        
        $params = [
            $order_id,
            $shipping_data['express_company'],
            $shipping_data['tracking_no'],
            $shipping_data['shipping_address'],
            $shipping_data['notes'],
            $shipping_time
        ];
        
        if (self::$tododb->query($query, $params)) {
            // 记录日志
            $message = __("添加发货记录") . ': ' . $shipping_data['express_company'] . ', ' . __('单号') . ': ' . $shipping_data['tracking_no'];
            self::add_order_log($order_id, 'shipping_record_added', $message);
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取支付记录
     * 
     * @param int $order_id 订单ID
     * @return array
     */
    public static function get_payment_records($order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return [];
        }

        if (self::$tododb === null) {
            self::init();
        }

        $records = [];
        $has_table = (!method_exists(self::$tododb, 'table_exists') || self::$tododb->table_exists('order_payments'));

        if ($has_table) {
            $query = "SELECT * FROM " . DB_PREFIX . "order_payments 
                      WHERE order_id = ? 
                      ORDER BY created_at DESC";
            
            try {
                $records = self::$tododb->get_results($query, [$order_id], 'ARRAY_A');
                $records = is_array($records) ? $records : [];
            } catch (Exception $e) {
                $records = [];
            }
        }

        // 自动打通：如果无支付记录，但订单为已付款状态，自动补齐/持久化支付记录
        if (empty($records)) {
            $order = self::get_order($order_id, false);
            if ($order && (
                ($order['payment_status'] ?? '') === self::PAYMENT_PAID || 
                ($order['payment_status'] ?? '') === 'paid' || 
                ($order['status'] ?? '') === self::STATUS_PAID ||
                ($order['status'] ?? '') === 'paid' ||
                !empty($order['paid_at'])
            )) {
                $method = !empty($order['payment_method']) ? $order['payment_method'] : 'online';
                $amount = !empty($order['payment_amount']) && floatval($order['payment_amount']) > 0 
                    ? floatval($order['payment_amount']) 
                    : (isset($order['total_amount']) ? floatval($order['total_amount']) : 0.00);
                
                $transaction_id = $order['transaction_id'] ?? ($order['trade_no'] ?? '');
                if (empty($transaction_id) && function_exists('todo_get_post_meta')) {
                    $pay_info = todo_get_post_meta($order_id, '_payment_info', true);
                    if (is_array($pay_info)) {
                        $transaction_id = $pay_info['transaction_id'] ?? ($pay_info['payjs_order_id'] ?? ($pay_info['paypal_order_id'] ?? ''));
                    }
                }
                
                $payment_time = !empty($order['paid_at']) ? $order['paid_at'] : (!empty($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s'));
                
                $payment_data = array(
                    'payment_method' => $method,
                    'amount' => $amount,
                    'transaction_id' => $transaction_id,
                    'payment_status' => 'paid',
                    'notes' => '系统自动关联订单支付记录',
                    'payment_time' => $payment_time
                );
                
                if ($has_table) {
                    self::add_payment_record($order_id, $payment_data);
                    try {
                        $query = "SELECT * FROM " . DB_PREFIX . "order_payments WHERE order_id = ? ORDER BY created_at DESC";
                        $records = self::$tododb->get_results($query, [$order_id], 'ARRAY_A') ?: [];
                    } catch (Exception $e) {
                        $records = [];
                    }
                }

                if (empty($records)) {
                    $records = array(
                        array(
                            'id' => 0,
                            'order_id' => $order_id,
                            'payment_method' => $method,
                            'amount' => $amount,
                            'transaction_id' => $transaction_id,
                            'payment_status' => 'paid',
                            'notes' => '系统自动关联订单支付记录',
                            'payment_time' => $payment_time,
                            'created_at' => $payment_time
                        )
                    );
                }
            }
        }

        if (!function_exists('todo_get_payment_method_label')) {
            if (class_exists('Todo_Function_Loader')) {
                Todo_Function_Loader::load_function('shop-service-settings');
            } else {
                $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
                if ($p && file_exists($p)) {
                    require_once $p;
                }
            }
        }

        foreach ($records as &$record) {
            $m_code = $record['payment_method'] ?? ($record['method'] ?? '');
            $record['payment_method'] = $m_code;
            $record['method'] = function_exists('todo_get_payment_method_label')
                ? todo_get_payment_method_label($m_code)
                : ($m_code ?: '在线支付');
        }
        unset($record);

        return $records;
    }
    
    /**
     * 获取发货记录
     * 
     * @param int $order_id 订单ID
     * @return array
     */
    public static function get_shipping_records($order_id) {
        if (self::$tododb === null) {
            self::init();
        }
        if (self::$tododb === null) {
            global $tododb;
            if (isset($tododb) && $tododb !== null) {
                self::$tododb = $tododb;
            } else {
                return [];
            }
        }
        
        $records = [];
        $shipments_table = DB_PREFIX . 'order_shipments';
        if (!method_exists(self::$tododb, 'table_exists') || self::$tododb->table_exists('order_shipments')) {
            try {
                $query1 = "SELECT id, order_id, express_company, tracking_no, notes, notes AS shipping_notes, created_at 
                           FROM {$shipments_table} 
                           WHERE order_id = ? 
                           ORDER BY created_at DESC, id DESC";
                $rows1 = self::$tododb->get_results($query1, [$order_id], 'ARRAY_A');
                if (!empty($rows1) && is_array($rows1)) {
                    $records = array_merge($records, $rows1);
                }
            } catch (Exception $e) {}
        }
        
        $logistics_table = DB_PREFIX . 'order_logistics';
        if (!method_exists(self::$tododb, 'table_exists') || self::$tododb->table_exists('order_logistics')) {
            try {
                $query2 = "SELECT id, order_id, express_company, tracking_number AS tracking_no, created_at, updated_at 
                           FROM {$logistics_table} 
                           WHERE order_id = ? AND (express_company <> '' OR tracking_number <> '') 
                           ORDER BY updated_at DESC, id DESC";
                $rows2 = self::$tododb->get_results($query2, [$order_id], 'ARRAY_A');
                if (!empty($rows2) && is_array($rows2)) {
                    $existing_nos = array_column($records, 'tracking_no');
                    foreach ($rows2 as $r2) {
                        if (!empty($r2['tracking_no']) && in_array($r2['tracking_no'], $existing_nos, true)) {
                            continue;
                        }
                        $r2['notes'] = $r2['notes'] ?? '';
                        $r2['shipping_notes'] = $r2['shipping_notes'] ?? $r2['notes'];
                        $records[] = $r2;
                    }
                }
            } catch (Exception $e) {}
        }
        
        foreach ($records as &$rec) {
            $rec['notes'] = $rec['notes'] ?? $rec['shipping_notes'] ?? '';
            $rec['shipping_notes'] = $rec['shipping_notes'] ?? $rec['notes'] ?? '';
        }
        unset($rec);

        return $records;
    }

    /**
     * 获取状态名称
     * 
     * @param string $status 状态代码
     * @return string
     */
    public static function get_status_name($status) {
        $status_options = self::get_status_options();
        return isset($status_options[$status]) ? $status_options[$status] : $status;
    }
    
    /**
     * 获取支付状态名称
     * 
     * @param string $payment_status 支付状态代码
     * @return string
     */
    public static function get_payment_status_name($payment_status) {
        $payment_status_options = self::get_payment_status_options();
        return isset($payment_status_options[$payment_status]) ? $payment_status_options[$payment_status] : $payment_status;
    }
    
    /**
     * 获取订单统计数据
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_order_statistics($args = array()) {
        $defaults = array(
            'start_date' => date('Y-m-01'), // 本月开始
            'end_date' => date('Y-m-d'),    // 今天
            'status' => '',                 // 订单状态
            'payment_status' => ''          // 支付状态
        );
        
        $args = array_merge($defaults, $args);
        
        // 构建查询条件
        $where_conditions = array("DATE(created_at) BETWEEN ? AND ?");
        $params = array($args['start_date'], $args['end_date']);
        $types = 'ss';
        
        if (!empty($args['status'])) {
            $where_conditions[] = "status = ?";
            $params[] = $args['status'];
            $types .= 's';
        }
        
        if (!empty($args['payment_status'])) {
            $where_conditions[] = "payment_status = ?";
            $params[] = $args['payment_status'];
            $types .= 's';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // 获取基础统计
        $query = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
                    AVG(CASE WHEN payment_status = 'paid' THEN total_amount ELSE NULL END) as avg_order_value
                  FROM " . DB_PREFIX . "orders 
                  WHERE {$where_clause}";
        
        $stats = self::$tododb->get_row($query, $params, 'ARRAY_A');
        
        // 获取状态分布
        $status_query = "SELECT status, COUNT(*) as count 
                        FROM " . DB_PREFIX . "orders 
                        WHERE {$where_clause}
                        GROUP BY status";
        
        $status_results = self::$tododb->get_results($status_query, $params, 'ARRAY_A');
        
        $status_distribution = array();
        if ($status_results) {
            foreach ($status_results as $row) {
                $status_distribution[$row['status']] = intval($row['count']);
            }
        }
        
        return array(
            'total_orders' => intval($stats['total_orders']),
            'total_revenue' => floatval($stats['total_revenue']),
            'paid_orders' => intval($stats['paid_orders']),
            'avg_order_value' => floatval($stats['avg_order_value']),
            'status_distribution' => $status_distribution
        );
    }
    
    /**
     * 获取图表数据
     * 
     * @param array $args 查询参数
     * @return array
     */
    public static function get_chart_data($args = array()) {
        $defaults = array(
            'type' => 'daily',              // daily, weekly, monthly
            'start_date' => date('Y-m-01'), // 开始日期
            'end_date' => date('Y-m-d'),    // 结束日期
            'metric' => 'revenue'           // revenue, orders, users
        );
        
        $args = array_merge($defaults, $args);
        
        // 根据类型确定日期格式
        switch ($args['type']) {
            case 'weekly':
                $date_format = '%Y-%u';
                $date_label = 'YEARWEEK(created_at)';
                break;
            case 'monthly':
                $date_format = '%Y-%m';
                $date_label = 'DATE_FORMAT(created_at, "%Y-%m")';
                break;
            default: // daily
                $date_format = '%Y-%m-%d';
                $date_label = 'DATE(created_at)';
                break;
        }
        
        // 根据指标确定查询字段
        switch ($args['metric']) {
            case 'orders':
                $metric_field = 'COUNT(*)';
                break;
            case 'users':
                $metric_field = 'COUNT(DISTINCT user_id)';
                break;
            default: // revenue
                $metric_field = 'SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END)';
                break;
        }
        
        $query = "SELECT 
                    {$date_label} as date_label,
                    {$metric_field} as value
                  FROM " . DB_PREFIX . "orders 
                  WHERE DATE(created_at) BETWEEN ? AND ?
                  GROUP BY {$date_label}
                  ORDER BY date_label";
        
        $results = self::$tododb->get_results($query, [$args['start_date'], $args['end_date']], 'ARRAY_A');
        
        $chart_data = array();
        if ($results) {
            foreach ($results as $row) {
                $chart_data[] = array(
                    'date' => $row['date_label'],
                    'value' => floatval($row['value'])
                );
            }
        }
        
        return $chart_data;
    }
    
    /**
     * 获取状态标签
     * 
     * @param string $status 状态代码
     * @return string
     */
    public static function get_status_label($status) {
        $status_labels = array(
            self::STATUS_PENDING => '<span class="badge badge-warning">' . __('待付款') . '</span>',
            self::STATUS_PAID => '<span class="badge badge-info">' . __('已付款') . '</span>',
            self::STATUS_PROCESSING => '<span class="badge badge-primary">' . __('待发货') . '</span>',
            self::STATUS_SHIPPED => '<span class="badge badge-secondary">' . __('已发货') . '</span>',
            self::STATUS_DELIVERED => '<span class="badge badge-light">' . __('已送达') . '</span>',
            self::STATUS_COMPLETED => '<span class="badge badge-success">' . __('已完成') . '</span>',
            self::STATUS_CANCELLED => '<span class="badge badge-dark">' . __('已取消') . '</span>',
            self::STATUS_REFUND => '<span class="badge badge-warning">' . __('售后中') . '</span>',
            self::STATUS_REFUNDED => '<span class="badge badge-danger">' . __('已退款') . '</span>'
        );
        
        return isset($status_labels[$status]) ? $status_labels[$status] : '<span class="badge badge-secondary">' . esc_html($status) . '</span>';
    }
    
    /**
     * 获取支付状态标签
     * 
     * @param string $payment_status 支付状态代码
     * @return string
     */
    public static function get_payment_status_label($payment_status) {
        $payment_status_labels = array(
            self::PAYMENT_PENDING => '<span class="badge badge-warning">' . __('待付款') . '</span>',
            self::PAYMENT_PAID => '<span class="badge badge-success">' . __('已付款') . '</span>',
            self::PAYMENT_FAILED => '<span class="badge badge-danger">' . __('付款失败') . '</span>',
            self::PAYMENT_REFUNDED => '<span class="badge badge-info">' . __('已退款') . '</span>',
            self::PAYMENT_PARTIAL_REFUNDED => '<span class="badge badge-secondary">' . __('部分退款') . '</span>'
        );
        
        return isset($payment_status_labels[$payment_status]) ? $payment_status_labels[$payment_status] : '<span class="badge badge-secondary">' . esc_html($payment_status) . '</span>';
    }
    
    /**
     * 统计订单数量
     * 
     * @param array $args 查询参数（与get_orders方法相同）
     * @return int
     */
    public static function count_orders($args = array()) {
        // 默认参数
        $defaults = array(
            'user_id' => null,
            'status' => null,
            'payment_status' => null,
            'delivery_method' => null,
            'date_from' => null,
            'date_to' => null,
            'keyword' => null
        );
        
        $args = array_merge($defaults, $args);
        
        $where_clauses = array();
        $params = array();
        $types = '';
        
        // 构建WHERE条件（与get_orders方法相同的逻辑）
        if ($args['user_id'] !== null) {
            $where_clauses[] = 'o.user_id = ?';
            $params[] = intval($args['user_id']);
            $types .= 'i';
        }
        
        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = str_repeat('?,', count($args['status']) - 1) . '?';
                $where_clauses[] = "o.status IN ({$placeholders})";
                $params = array_merge($params, $args['status']);
                $types .= str_repeat('s', count($args['status']));
            } else {
                $where_clauses[] = 'o.status = ?';
                $params[] = $args['status'];
                $types .= 's';
            }
        }
        
        if (!empty($args['payment_status'])) {
            $where_clauses[] = 'o.payment_status = ?';
            $params[] = $args['payment_status'];
            $types .= 's';
        }
        
        if (!empty($args['delivery_method'])) {
            $where_clauses[] = 'o.delivery_method = ?';
            $params[] = $args['delivery_method'];
            $types .= 's';
        }
        
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'o.created_at >= ?';
            $params[] = $args['date_from'];
            $types .= 's';
        }
        
        if (!empty($args['date_to'])) {
            $where_clauses[] = 'o.created_at <= ?';
            $params[] = $args['date_to'] . ' 23:59:59';
            $types .= 's';
        }
        
        if (!empty($args['keyword'])) {
            $where_clauses[] = '(o.order_no LIKE ? OR u.user_login LIKE ? OR u.user_email LIKE ?)';
            $keyword = '%' . $args['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
            $types .= 'sss';
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 构建COUNT查询
        $query = "SELECT COUNT(*) as total 
                  FROM " . DB_PREFIX . "orders o 
                  LEFT JOIN " . DB_PREFIX . "users u ON o.user_id = u.ID 
                  {$where_sql}";
        
        try {
            $count = self::$tododb->get_var($query, $params);
            return intval($count);
            
            return 0;
            
        } catch (Exception $e) {
            error_log('count_orders: 查询失败 - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 获取订单状态文本
     * 
     * @param string $status 状态值
     * @return string 状态文本
     */
    /**
     * 获取订单状态文本（支持多语言）
     * 
     * @param string $status 状态值
     * @param string|null $lang 语言代码，传 null 时使用默认语言
     * @return string 状态文本
     */
    public static function get_order_status_text($status, $lang = null) {
        if ($lang !== null) {
            return self::get_order_status_i18n($status, $lang);
        }
        $status_texts = array(
            self::STATUS_PENDING => '待付款',
            self::STATUS_PAID => '已付款',
            self::STATUS_SHIPPED => '已发货',
            self::STATUS_DELIVERED => '已送达',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_REFUND => '售后中',
            self::STATUS_REFUNDED => '已退款'
        );
        
        return isset($status_texts[$status]) ? $status_texts[$status] : '未知状态';
    }
    
    /**
     * 获取支付状态文本（支持多语言）
     * 
     * @param string $status 支付状态值
     * @param string|null $lang 语言代码
     * @return string 支付状态文本
     */
    public static function get_payment_status_text($status, $lang = null) {
        if ($lang !== null) {
            return self::get_order_status_i18n($status, $lang);
        }
        $payment_status_texts = array(
            self::PAYMENT_PENDING => '待付款',
            self::PAYMENT_PAID => '已付款',
            self::PAYMENT_FAILED => '付款失败',
            self::PAYMENT_REFUNDED => '已退款',
            self::PAYMENT_PARTIAL_REFUNDED => '部分退款'
        );
        
        return isset($payment_status_texts[$status]) ? $payment_status_texts[$status] : '未知状态';
    }

    /**
     * 处理虚拟商品自动生成授权码/卡密并更新交付内容说明
     *
     * @param int $order_id 订单ID
     * @return bool
     */
    public static function process_auto_license_delivery($order_id) {
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $items = self::get_order_items($order_id);
        if (empty($items)) {
            return false;
        }

        $license_file = ABSPATH . '/geinmo/license-manager.php';
        if (!file_exists($license_file)) {
            return false;
        }
        require_once $license_file;

        if (!class_exists('GeinmoLicenseManager') && !function_exists('geinmo_get_license_manager')) {
            return false;
        }

        global $tododb;
        // 自动建列兼容：确保 order_items 表存在 delivery_content_note 字段
        try {
            $order_items_table = DB_PREFIX . 'order_items';
            $cols = $tododb->get_results("SHOW COLUMNS FROM {$order_items_table} LIKE 'delivery_content_note'");
            if (empty($cols)) {
                $tododb->query("ALTER TABLE {$order_items_table} ADD COLUMN delivery_content_note text COMMENT '发卡/卡密文本交付内容说明'");
            }
        } catch (\Throwable $e) {}

        try {
            $license_manager = function_exists('geinmo_get_license_manager') ? geinmo_get_license_manager() : new GeinmoLicenseManager();
        } catch (\Throwable $e) {
            return false;
        }

        $order = self::get_order($order_id, false);
        $user_id = intval($order['user_id'] ?? 0);
        $updated_any = false;

        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }

            $pt = get_post_meta($pid, 'product_type', true);
            $dt = get_post_meta($pid, 'delivery_type', true);

            // 从 model_fields_product 表同步最新配置
            $mfp = DB_PREFIX . 'model_fields_product';
            try {
                $mfp_row = self::$tododb->get_row(self::$tododb->prepare("SELECT product_type, delivery_type, delivery_content_note, auto_gen_license_key FROM {$mfp} WHERE post_id = %d LIMIT 1", $pid), ARRAY_A);
                if ($mfp_row && (is_array($mfp_row) || is_object($mfp_row))) {
                    $mfp_arr = (array)$mfp_row;
                    $pt = $mfp_arr['product_type'] ?: $pt;
                    $dt = $mfp_arr['delivery_type'] ?: $dt;
                    $auto_gen = isset($mfp_arr['auto_gen_license_key']) ? intval($mfp_arr['auto_gen_license_key']) : 0;
                    $note_template = $mfp_arr['delivery_content_note'] ?? '';
                } else {
                    $auto_gen = intval(get_post_meta($pid, 'auto_gen_license_key', true) ?: 0);
                    $note_template = get_post_meta($pid, 'delivery_content_note', true) ?: '';
                }
            } catch (\Exception $e) {
                $auto_gen = intval(get_post_meta($pid, 'auto_gen_license_key', true) ?: 0);
                $note_template = get_post_meta($pid, 'delivery_content_note', true) ?: '';
            }

            // 仅对虚拟商品且交付类型为文本内容且开启自动发卡生效
            if ($pt === 'virtual' && $dt === 'content' && !empty($auto_gen)) {
                $item_id = intval($item['id'] ?? 0);
                if ($item_id <= 0) {
                    continue;
                }

                // 自动生成授权码 (16字节 random_bytes 的 32位十六进制)
                $license_key = bin2hex(random_bytes(16));
                
                // 自动生成符合强密码规则的 user_password
                // 规则：至少16位大小写字母 + 数字 + 特殊字符
                $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                $lower = 'abcdefghijkmnpqrstuvwxyz';
                $digits = '23456789';
                $special = '!@#$%^&*()_+-=';
                
                $pwd_chars = [];
                $pwd_chars[] = $upper[random_int(0, strlen($upper) - 1)];
                $pwd_chars[] = $lower[random_int(0, strlen($lower) - 1)];
                $pwd_chars[] = $digits[random_int(0, strlen($digits) - 1)];
                $pwd_chars[] = $special[random_int(0, strlen($special) - 1)];
                
                $all_chars = $upper . $lower . $digits . $special;
                for ($i = 4; $i < 16; $i++) {
                    $pwd_chars[] = $all_chars[random_int(0, strlen($all_chars) - 1)];
                }
                shuffle($pwd_chars);
                $user_password = implode('', $pwd_chars);

                // 创建授权码及绑定的 Geinmo 用户
                $lic = $license_manager->create_license_key($license_key, array(
                    'status' => 'active',
                    'owner_user_id' => $user_id,
                    'max_rebinds_per_year' => 3,
                    'notes' => '订单 #' . $order_id . ' 自动卡密生成',
                    'user_password' => $user_password
                ));

                if ($lic) {
                    $order_items_table = DB_PREFIX . 'order_items';
                    try {
                        self::$tododb->query(
                            "UPDATE {$order_items_table} SET license_key = ?, license_pass = ? WHERE id = ?",
                            [$license_key, $user_password, $item_id]
                        );
                        $updated_any = true;
                    } catch (\Exception $e) {
                        error_log('更新订单项 license_key/license_pass 异常: ' . $e->getMessage());
                    }
                }
            }
        }

        return $updated_any;
    }
}

// 便捷函数
if (!function_exists('todo_create_order')) {
    /**
     * 创建订单
     * 
     * @param array $order_data 订单数据
     * @return bool|int
     */
    function todo_create_order($order_data) {
        return Todo_Order_Manager::create_order($order_data);
    }
}

if (!function_exists('todo_get_order')) {
    /**
     * 获取订单信息
     * 
     * @param int $order_id 订单ID
     * @param bool $include_items 是否包含订单项目
     * @return array|null
     */
    function todo_get_order($order_id, $include_items = true) {
        return Todo_Order_Manager::get_order($order_id, $include_items);
    }
}

if (!function_exists('todo_get_orders')) {
    /**
     * 获取订单列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    function todo_get_orders($args = array()) {
        return Todo_Order_Manager::get_orders($args);
    }
}

if (!function_exists('todo_update_order_status')) {
    /**
     * 更新订单状态
     * 
     * @param int $order_id 订单ID
     * @param string $status 新状态
     * @param int $operator_id 操作者ID
     * @param string $notes 备注
     * @return bool
     */
    function todo_update_order_status($order_id, $status, $operator_id = 0, $notes = '') {
        return Todo_Order_Manager::update_order_status($order_id, $status, $operator_id, $notes);
    }
}

if (!function_exists('todo_ship_order')) {
    /**
     * 发货
     * 
     * @param int $order_id 订单ID
     * @param array $shipping_data 发货数据
     * @param int $operator_id 操作者ID
     * @return bool
     */
    function todo_ship_order($order_id, $shipping_data, $operator_id = 0) {
        return Todo_Order_Manager::ship_order($order_id, $shipping_data, $operator_id);
    }
}

if (!function_exists('todo_get_order_items')) {
    /**
     * 获取订单项目
     * 
     * @param int $order_id 订单ID
     * @return array
     */
    function todo_get_order_items($order_id) {
        return Todo_Order_Manager::get_order_items($order_id);
    }
}

if (!function_exists('todo_delete_order')) {
    /**
     * 删除订单
     * 
     * @param int $order_id 订单ID
     * @return bool
     */
    function todo_delete_order($order_id) {
        return Todo_Order_Manager::delete_order($order_id);
    }
}

if (!function_exists('todo_create_download_order')) {
    /**
     * 创建下载订单
     * 
     * @param array $order_data 订单数据
     * @return int|false 订单ID或false
     */
    function todo_create_download_order($order_data) {
        return Todo_Order_Manager::create_download_order($order_data);
    }
}

if (!function_exists('todo_check_download_permission')) {
    /**
     * 检查下载权限
     * 
     * @param int $order_id 订单ID
     * @param int $user_id 用户ID
     * @return array
     */
    function todo_check_download_permission($order_id, $user_id) {
        return Todo_Order_Manager::check_download_permission($order_id, $user_id);
    }
}

if (!function_exists('todo_log_download')) {
    /**
     * 记录下载日志
     * 
     * @param int $order_id 订单ID
     * @param int $product_id 商品ID
     * @param string $file_path 文件路径
     * @param string $file_name 文件名称
     * @return bool
     */
    function todo_log_download($order_id, $product_id, $file_path, $file_name) {
        return Todo_Order_Manager::log_download($order_id, $product_id, $file_path, $file_name);
    }
}

<?php
/**
 * 系统函数 - shop-service-settings.php
 * 系统全局辅助函数库
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!function_exists('todo_shop_service_settings_defaults')) {
    function todo_shop_service_settings_defaults()
    {
        return array(
            'unified_notice' => '',
            'guest_checkout' => 0,
            'review_approval' => 0,
            'allow_refund' => 0,
            'return_window_days' => 14,
            'refund_max_attempts' => 3,
            'auto_confirm_days' => 7,
            'product_service_items' => array(
                array('code' => 'shipping_insurance', 'label' => '运费险'),
                array('code' => 'cash_on_delivery', 'label' => '货到付款'),
                array('code' => 'instant_refund', 'label' => '闪电退款'),
                array('code' => 'seven_day_return', 'label' => '7天无理由退货'),
            ),
            'shipping_methods' => array(
                array('code' => 'express', 'name' => '快递配送', 'fee' => '10.00', 'desc' => '预计 2-3 天送达', 'enabled' => 1),
                array('code' => 'ems', 'name' => '特快专递 (EMS)', 'fee' => '20.00', 'desc' => '预计 1-2 天送达', 'enabled' => 1),
                array('code' => 'self_pickup', 'name' => '到店自提', 'fee' => '0.00', 'desc' => '免费', 'enabled' => 1),
            ),
            'express_companies' => array(),
            'payment_methods' => array(),
            'payment_providers' => array(
                'wechat' => 'payjs',
                'alipay' => 'payjs',
                'unionpay' => 'unionpay_direct',
            ),
            'logistics_tracking' => array(
                'enabled' => 0,
                'sandbox' => 0,
                'provider' => 'kuaidi100',
                'cache_minutes' => 10,
                'auto_poll' => 0,
                'poll_interval_minutes' => 60,
                'kuaidi100' => array(
                    'customer' => '',
                    'key' => '',
                    'endpoint' => 'https://poll.kuaidi100.com/poll/query.do',
                ),
                'kdniao' => array(
                    'ebusiness_id' => '',
                    'app_key' => '',
                    'endpoint' => 'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx',
                    'request_type' => '1002',
                ),
                'cainiao' => array(
                    'app_key' => '',
                    'app_secret' => '',
                    'session' => '',
                    'endpoint' => 'https://gw.api.taobao.com/router/rest',
                    'method' => 'taobao.logistics.trace.search',
                    'sign_method' => 'md5',
                    'param_company_key' => 'company_code',
                    'param_tracking_key' => 'out_sid',
                ),
                'ups' => array(
                    'access_token' => '',
                    'client_id' => '',
                    'client_secret' => '',
                    'endpoint' => 'https://onlinetools.ups.com/api/shipments/v1/transports/details/',
                ),
                'dhl' => array(
                    'api_key' => '',
                    'api_secret' => '',
                    'endpoint' => 'https://express.api.dhl.com/mydhlapi/shipments/',
                ),
            ),
        );
    }
}

if (!function_exists('todo_shop_get_service_settings')) {
    function todo_shop_get_service_settings($raw = false)
    {
        $defaults = todo_shop_service_settings_defaults();
        $settings = $defaults;
        if (class_exists('CMS_Option') && method_exists('CMS_Option', 'exists') && !CMS_Option::exists('todo_service_settings')) {
            CMS_Option::update('todo_service_settings', $defaults, 'general');
            $settings = $defaults;
        } elseif (function_exists('get_option')) {
            $settings = get_option('todo_service_settings', $defaults);
        } elseif (class_exists('CMS_Option')) {
            $settings = CMS_Option::get('todo_service_settings', $defaults);
        } elseif (function_exists('todo_get_option')) {
            $settings = todo_get_option('todo_service_settings', $defaults);
        }
        if (function_exists('todo_parse_args')) {
            $settings = todo_parse_args($settings, $defaults);
        } else {
            $settings = array_merge($defaults, is_array($settings) ? $settings : array());
        }

        if (!isset($settings['payment_providers']) || !is_array($settings['payment_providers'])) {
            $settings['payment_providers'] = $defaults['payment_providers'];
        }
        if (!isset($settings['logistics_tracking']) || !is_array($settings['logistics_tracking'])) {
            $settings['logistics_tracking'] = array();
        }
        if (function_exists('todo_parse_args')) {
            $settings['logistics_tracking'] = todo_parse_args($settings['logistics_tracking'], $defaults['logistics_tracking']);
            $settings['logistics_tracking']['kuaidi100'] = todo_parse_args(is_array($settings['logistics_tracking']['kuaidi100'] ?? null) ? $settings['logistics_tracking']['kuaidi100'] : array(), $defaults['logistics_tracking']['kuaidi100']);
            $settings['logistics_tracking']['kdniao'] = todo_parse_args(is_array($settings['logistics_tracking']['kdniao'] ?? null) ? $settings['logistics_tracking']['kdniao'] : array(), $defaults['logistics_tracking']['kdniao']);
            $settings['logistics_tracking']['cainiao'] = todo_parse_args(is_array($settings['logistics_tracking']['cainiao'] ?? null) ? $settings['logistics_tracking']['cainiao'] : array(), $defaults['logistics_tracking']['cainiao']);
            $settings['logistics_tracking']['ups'] = todo_parse_args(is_array($settings['logistics_tracking']['ups'] ?? null) ? $settings['logistics_tracking']['ups'] : array(), $defaults['logistics_tracking']['ups']);
            $settings['logistics_tracking']['dhl'] = todo_parse_args(is_array($settings['logistics_tracking']['dhl'] ?? null) ? $settings['logistics_tracking']['dhl'] : array(), $defaults['logistics_tracking']['dhl']);
        }

        if (!empty($settings['payment_methods']) && is_array($settings['payment_methods'])) {
            $converted = array();
            foreach ($settings['payment_methods'] as $c) {
                $c = function_exists('sanitize_text_field') ? sanitize_text_field($c) : (string) $c;
                if ($c === 'payjs_wechat') {
                    $converted[] = 'wechat';
                    $settings['payment_providers']['wechat'] = 'payjs';
                    continue;
                }
                if ($c === 'payjs_alipay') {
                    $converted[] = 'alipay';
                    $settings['payment_providers']['alipay'] = 'payjs';
                    continue;
                }
                if ($c === 'bank_transfer') {
                    $converted[] = 'offline';
                    continue;
                }
                $converted[] = $c;
            }
            $settings['payment_methods'] = array_values(array_unique($converted));
        }

        // 非编辑环境时，自动解析多语言（内联 [en|...] 及 模板标签 {{todo:t}}）
        if (!$raw) {
            if (function_exists('todo_map_inline_translations_deep')) {
                $settings = todo_map_inline_translations_deep($settings);
            }
            if (class_exists('CMS_Template_Compiler_V2')) {
                array_walk_recursive($settings, function (&$item) {
                    if (is_string($item) && strpos($item, '{{todo:') !== false) {
                        try {
                            $item = CMS_Template_Compiler_V2::render_string($item, []);
                        } catch (\Throwable $e) {}
                    }
                });
            }
        }

        return $settings;
    }
}

if (!function_exists('todo_get_shipping_methods')) {
    function todo_get_shipping_methods($only_enabled = true)
    {
        $settings = todo_shop_get_service_settings();
        $methods = isset($settings['shipping_methods']) && is_array($settings['shipping_methods']) ? $settings['shipping_methods'] : array();
        if ($only_enabled) {
            $methods = array_values(array_filter($methods, function($m) {
                return !empty($m['enabled']);
            }));
        }
        return $methods;
    }
}

if (!function_exists('todo_is_review_approval_enabled')) {
    function todo_is_review_approval_enabled()
    {
        $settings = todo_shop_get_service_settings();
        if (is_array($settings) && array_key_exists('review_approval', $settings)) {
            return (int) $settings['review_approval'] === 1;
        }
        $review_mod = function_exists('todo_get_option') ? todo_get_option('review_moderation', 'auto_approve') : (function_exists('get_option') ? get_option('review_moderation', 'auto_approve') : 'auto_approve');
        return $review_mod === 'manual_approve';
    }
}

if (!function_exists('todo_get_service_express_companies')) {
    /**
     * 获取服务设置中统一管理的可用快递公司列表
     * 仅返回在服务设置（Service Settings）中被具体勾选选定且 status=1 的快递公司。
     * 若在服务设置中未勾选任何快递公司（为空数组），则返回空数组。
     *
     * @param string $output ARRAY_A (默认) | OBJECT
     * @return array
     */
    function todo_get_service_express_companies($output = ARRAY_A)
    {
        global $tododb;
        if (!$tododb || !isset($tododb->prefix)) {
            return array();
        }

        $settings = todo_shop_get_service_settings();
        $express_ids = isset($settings['express_companies']) && is_array($settings['express_companies'])
            ? array_values(array_filter(array_map('intval', $settings['express_companies'])))
            : array();

        if (empty($express_ids)) {
            return array();
        }

        $table = $tododb->prefix . 'shipping_companies';
        $in_clause = implode(',', $express_ids);
        $sql = "SELECT id, name, code, website, phone, status, sort_order FROM {$table} WHERE status = 1 AND id IN ({$in_clause}) ORDER BY FIELD(id, {$in_clause})";

        $results = $tododb->get_results($sql, $output);
        return is_array($results) ? $results : array();
    }
}

if (!function_exists('todo_get_payment_method_label')) {
    /**
     * 动态获取支付方式标识对应的显示名称（优先从数据库及系统支付配置中读取）
     *
     * @param string $code
     * @return string
     */
    function todo_get_payment_method_label($code)
    {
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            return '';
        }

        static $label_cache = null;
        if ($label_cache === null) {
            $label_cache = array();
            global $tododb;
            if ($tododb && isset($tododb->prefix)) {
                // 1. 查询 payment_methods 表
                $table_pm = $tododb->prefix . 'payment_methods';
                try {
                    $rows = $tododb->get_results("SELECT code, name FROM {$table_pm}", ARRAY_A);
                    if (is_array($rows)) {
                        foreach ($rows as $r) {
                            $c = (string) ($r['code'] ?? '');
                            $n = (string) ($r['name'] ?? '');
                            if ($c !== '' && $n !== '') {
                                $label_cache[$c] = $n;
                            }
                        }
                    }
                } catch (\Throwable $e) {}

                // 2. 查询 payment_method_configs 表
                $table_pmc = $tododb->prefix . 'payment_method_configs';
                try {
                    $rows2 = $tododb->get_results("SELECT method_id, name FROM {$table_pmc}", ARRAY_A);
                    if (is_array($rows2)) {
                        foreach ($rows2 as $r) {
                            $c = (string) ($r['method_id'] ?? '');
                            $n = (string) ($r['name'] ?? '');
                            if ($c !== '' && $n !== '' && !isset($label_cache[$c])) {
                                $label_cache[$c] = $n;
                            }
                        }
                    }
                } catch (\Throwable $e) {}
            }

            // 3. 回退辅助逻辑：调用支付列表辅助函数
            if (!function_exists('todo_service_settings_get_payment_labels')) {
                $pm_func = defined('ADMIN_DIR') ? ADMIN_DIR . '/pages/shop/payments-functions.php' : '';
                if (!$pm_func && defined('ABSPATH')) {
                    $pm_func = ABSPATH . 'admin/pages/shop/payments-functions.php';
                }
                if ($pm_func && file_exists($pm_func)) {
                    require_once $pm_func;
                }
            }
            if (function_exists('todo_service_settings_get_payment_labels')) {
                $helper_labels = todo_service_settings_get_payment_labels();
                if (is_array($helper_labels)) {
                    foreach ($helper_labels as $k => $v) {
                        if (!isset($label_cache[$k]) && !empty($v)) {
                            $label_cache[$k] = $v;
                        }
                    }
                }
            }
        }

        return $label_cache[$code] ?? $code;
    }
}

if (!function_exists('todo_get_service_payment_methods')) {
    /**
     * 获取服务设置中统一归一化管理的可用支付方式列表
     * 严格来自于服务设置（Service Settings）中勾选配置的 payment_methods 数组。
     *
     * @return array 格式为 [ ['code' => '...', 'name' => '...', 'label' => '...'] ]
     */
    function todo_get_service_payment_methods()
    {
        $settings = todo_shop_get_service_settings();
        $codes = isset($settings['payment_methods']) && is_array($settings['payment_methods'])
            ? array_values(array_unique(array_filter($settings['payment_methods'])))
            : array();

        $methods = array();
        foreach ($codes as $code) {
            $code = is_string($code) ? trim($code) : '';
            if ($code === '') {
                continue;
            }
            $name = todo_get_payment_method_label($code);
            $methods[] = array(
                'code'  => $code,
                'name'  => $name,
                'label' => $name,
            );
        }
        return $methods;
    }
}



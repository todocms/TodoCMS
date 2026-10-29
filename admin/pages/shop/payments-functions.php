<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 获取服务设置页可选支付方式的标签集合
 *
 * @param array|null $method_ids 自定义方法标识集合，留空使用系统推荐集合
 * @return array [method_id => label]
 */
function todo_service_settings_get_payment_labels(?array $method_ids = null) {
    // 推荐集合：与现有服务设置页保持一致
    if ($method_ids === null) {
        $method_ids = array('wechat', 'alipay', 'paypal', 'unionpay', 'offline', 'cod', 'balance');
    }

    $labels = array();
    foreach ($method_ids as $mid) {
        $map = array(
            'wechat'       => '微信支付',
            'alipay'       => '支付宝',
            'paypal'       => 'PayPal',
            'unionpay'     => '云闪付',
            'payjs_wechat' => '微信支付',
            'payjs_alipay' => '支付宝',
            'offline'      => '银行转账',
            'cod'          => '货到付款',
            'balance'      => '余额支付',
        );
        $labels[$mid] = isset($map[$mid]) ? $map[$mid] : strtoupper($mid);
    }
    return $labels;
}

/**
 * 获取支付方式是否启用的映射
 *
 * 约定映射关系：
 * - payjs_wechat => wechat
 * - payjs_alipay => alipay
 * - offline      => bank_transfer
 * - balance      => balance
 *
 * @return array [method_id => bool]
 */
function todo_service_settings_get_payment_enabled_lookup() {
    $opts = todo_get_option('todo_payment_settings', array());
    $third_party = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $payjs = isset($third_party['payjs']) && is_array($third_party['payjs']) ? $third_party['payjs'] : array();
    $hupijiao = isset($third_party['hupijiao']) && is_array($third_party['hupijiao']) ? $third_party['hupijiao'] : array();
    $paypal = isset($third_party['paypal']) && is_array($third_party['paypal']) ? $third_party['paypal'] : array();

    $payjs_mchid = isset($payjs['mchid']) ? (string) $payjs['mchid'] : '';
    $payjs_key = isset($payjs['key']) ? (string) $payjs['key'] : '';
    if ($payjs_mchid === '') {
        $payjs_mchid = (string) todo_get_option('payjs_mchid', '');
    }
    if ($payjs_key === '') {
        $payjs_key = (string) todo_get_option('payjs_key', '');
    }
    $payjs_enabled = !empty($third_party['enabled']) && !empty($payjs['enabled']) && ($payjs_mchid !== '') && ($payjs_key !== '');

    $hupijiao_appid = isset($hupijiao['app_id']) ? (string) $hupijiao['app_id'] : '';
    $hupijiao_secret = isset($hupijiao['app_secret']) ? (string) $hupijiao['app_secret'] : '';
    $hupijiao_enabled = !empty($third_party['enabled']) && !empty($hupijiao['enabled']) && ($hupijiao_appid !== '') && ($hupijiao_secret !== '');

    $wechat_direct_ok = !empty($opts['wechat']['enabled'])
        && !empty($opts['wechat']['mini_appid'])
        && !empty($opts['wechat']['mch_id'])
        && !empty($opts['wechat']['api_key']);

    $alipay_direct_ok = !empty($opts['alipay']['enabled'])
        && !empty($opts['alipay']['app_id'])
        && !empty($opts['alipay']['private_key'])
        && !empty($opts['alipay']['public_key']);

    $wechat_enabled = ($payjs_enabled || $hupijiao_enabled || $wechat_direct_ok);
    $alipay_enabled = ($payjs_enabled || $hupijiao_enabled || $alipay_direct_ok);
    $paypal_has_account = false;
    if (!empty($paypal['accounts']) && is_array($paypal['accounts'])) {
        foreach ($paypal['accounts'] as $acc) {
            if (!empty($acc['client_id']) && !empty($acc['client_secret'])) {
                $paypal_has_account = true;
                break;
            }
        }
    } else {
        $paypal_has_account = !empty($paypal['client_id']) && !empty($paypal['client_secret']);
    }

    $paypal_enabled = !empty($third_party['enabled'])
        && !empty($paypal['enabled'])
        && $paypal_has_account;

    $unionpay_direct_ok = !empty($opts['unionpay']['enabled'])
        && !empty($opts['unionpay']['mer_id'])
        && !empty($opts['unionpay']['private_key']);

    return array(
        'wechat'       => $wechat_enabled,
        'alipay'       => $alipay_enabled,
        'paypal'       => $paypal_enabled,
        'unionpay'     => $unionpay_direct_ok,
        'payjs_wechat' => $payjs_enabled,
        'payjs_alipay' => $payjs_enabled,
        'offline'      => !empty($opts['bank_transfer']['enabled']),
        'cod'          => !empty($opts['cod']['enabled']),
        'balance'      => !empty($opts['balance']['enabled']),
    );
}

function todo_service_settings_get_payment_provider_options() {
    $opts = todo_get_option('todo_payment_settings', array());
    $third_party = isset($opts['third_party']) && is_array($opts['third_party']) ? $opts['third_party'] : array();
    $payjs = isset($third_party['payjs']) && is_array($third_party['payjs']) ? $third_party['payjs'] : array();
    $hupijiao = isset($third_party['hupijiao']) && is_array($third_party['hupijiao']) ? $third_party['hupijiao'] : array();

    $payjs_mchid = isset($payjs['mchid']) ? (string) $payjs['mchid'] : '';
    $payjs_key = isset($payjs['key']) ? (string) $payjs['key'] : '';
    if ($payjs_mchid === '') {
        $payjs_mchid = (string) todo_get_option('payjs_mchid', '');
    }
    if ($payjs_key === '') {
        $payjs_key = (string) todo_get_option('payjs_key', '');
    }
    $payjs_enabled = !empty($third_party['enabled']) && !empty($payjs['enabled']) && ($payjs_mchid !== '') && ($payjs_key !== '');

    $hupijiao_appid = isset($hupijiao['app_id']) ? (string) $hupijiao['app_id'] : '';
    $hupijiao_secret = isset($hupijiao['app_secret']) ? (string) $hupijiao['app_secret'] : '';
    $hupijiao_enabled = !empty($third_party['enabled']) && !empty($hupijiao['enabled']) && ($hupijiao_appid !== '') && ($hupijiao_secret !== '');

    $wechat_direct_ok = !empty($opts['wechat']['enabled'])
        && !empty($opts['wechat']['mini_appid'])
        && !empty($opts['wechat']['mch_id'])
        && !empty($opts['wechat']['api_key']);

    $alipay_direct_ok = !empty($opts['alipay']['enabled'])
        && !empty($opts['alipay']['app_id'])
        && !empty($opts['alipay']['private_key'])
        && !empty($opts['alipay']['public_key']);

    $unionpay_direct_ok = !empty($opts['unionpay']['enabled'])
        && !empty($opts['unionpay']['mer_id'])
        && !empty($opts['unionpay']['private_key']);

    return array(
        'wechat' => array(
            array('code' => 'wechat_direct', 'label' => '直连微信支付', 'enabled' => $wechat_direct_ok),
            array('code' => 'payjs', 'label' => 'PayJS', 'enabled' => $payjs_enabled),
            array('code' => 'hupijiao', 'label' => '虎皮椒', 'enabled' => $hupijiao_enabled),
        ),
        'alipay' => array(
            array('code' => 'alipay_direct', 'label' => '直连支付宝', 'enabled' => $alipay_direct_ok),
            array('code' => 'payjs', 'label' => 'PayJS', 'enabled' => $payjs_enabled),
            array('code' => 'hupijiao', 'label' => '虎皮椒', 'enabled' => $hupijiao_enabled),
        ),
        'unionpay' => array(
            array('code' => 'unionpay_direct', 'label' => '直连云闪付/银联国际', 'enabled' => $unionpay_direct_ok),
        ),
    );
}

/**
 * 组合：返回支付方式标签与启用映射
 *
 * @param array|null $method_ids 可传入自定义集合
 * @return array [$labels, $enabled_lookup]
 */
function todo_service_settings_get_payment_list_data(?array $method_ids = null) {
    $labels = todo_service_settings_get_payment_labels($method_ids);
    $enabled = todo_service_settings_get_payment_enabled_lookup();
    return array($labels, $enabled);
}

/**
 * 获取全部（已配置表中的）支付方式（轻量封装）
 * @return array
 */
function todo_admin_get_all_payment_methods() {
    return todo_get_payment_methods();
}

/**
 * 获取单个支付方式（轻量封装）
 * @param int $id
 * @return array|null
 */
function todo_admin_get_payment_method($id) {
    return todo_get_payment_method($id);
}

/**
 * 新增支付方式（轻量封装）
 */
function todo_admin_add_payment_method($name, $code, $description = '', $status = 1, $icon = '', $settings = array()) {
    return todo_add_payment_method($name, $code, $description, $status, $icon, $settings);
}

/**
 * 更新支付方式（轻量封装）
 */
function todo_admin_update_payment_method($id, $name, $code, $description = '', $status = 1, $icon = '', $settings = array()) {
    return todo_update_payment_method($id, $name, $code, $description, $status, $icon, $settings);
}

/**
 * 删除支付方式（轻量封装）
 */
function todo_admin_delete_payment_method($id) {
    return todo_delete_payment_method($id);
}

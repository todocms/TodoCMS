<?php
/**
 * TodoCMS 支付方式管理页面
 * 
 * 管理系统支持的支付方式
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('TODO_ADMIN')) {
    exit('Direct access denied');
}

// 检查权限
if (!todo_current_user_can('manage_options')) {
    todo_redirect(todo_admin_url('index.php?page=dashboard'));
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证CSRF令牌
    if (!todo_verify_csrf_token($_POST['_csrf_token'] ?? '', 'payment_methods')) {
        todo_admin_notice('CSRF验证失败', 'error');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'save_settings':
                // 保存支付方式设置
                $payment_settings = [];
                
                // 支付宝设置
                $payment_settings['alipay'] = [
                    'enabled' => isset($_POST['alipay_enabled']) ? 1 : 0,
                    'app_id' => sanitize_text_field($_POST['alipay_app_id'] ?? ''),
                    'private_key' => sanitize_textarea_field($_POST['alipay_private_key'] ?? ''),
                    'public_key' => sanitize_textarea_field($_POST['alipay_public_key'] ?? '')
                ];
                
                // 微信支付设置
                $payment_settings['wechat'] = [
                    'enabled' => isset($_POST['wechat_enabled']) ? 1 : 0,
                    'app_id' => sanitize_text_field($_POST['wechat_app_id'] ?? ''),
                    'mini_appid' => sanitize_text_field($_POST['wechat_mini_appid'] ?? ''),
                    'mini_secret' => sanitize_text_field($_POST['wechat_mini_secret'] ?? ''),
                    'mch_id' => sanitize_text_field($_POST['wechat_mch_id'] ?? ''),
                    'api_key' => sanitize_text_field($_POST['wechat_api_key'] ?? ''),
                    'cert_path' => sanitize_text_field($_POST['wechat_cert_path'] ?? ''),
                    'key_path' => sanitize_text_field($_POST['wechat_key_path'] ?? '')
                ];
                
                // 同时更新全局选项以便 API 调用
                update_option('wechat_mini_appid', $payment_settings['wechat']['mini_appid']);
                update_option('wechat_mini_secret', $payment_settings['wechat']['mini_secret']);
                
                // 银行转账设置
                $payment_settings['bank_transfer'] = [
                    'enabled' => isset($_POST['bank_enabled']) ? 1 : 0,
                    'bank_name' => sanitize_text_field($_POST['bank_name'] ?? ''),
                    'account_name' => sanitize_text_field($_POST['account_name'] ?? ''),
                    'account_number' => sanitize_text_field($_POST['account_number'] ?? ''),
                    'instructions' => sanitize_textarea_field($_POST['bank_instructions'] ?? '')
                ];
                
                // 货到付款设置
                $payment_settings['cod'] = [
                    'enabled' => isset($_POST['cod_enabled']) ? 1 : 0,
                    'fee' => floatval($_POST['cod_fee'] ?? 0),
                    'min_amount' => floatval($_POST['cod_min_amount'] ?? 0),
                    'max_amount' => floatval($_POST['cod_max_amount'] ?? 0),
                    'instructions' => sanitize_textarea_field($_POST['cod_instructions'] ?? '')
                ];
                
                // 余额支付设置
                $payment_settings['balance'] = [
                    'enabled' => isset($_POST['balance_enabled']) ? 1 : 0
                ];

                // 云闪付设置
                $payment_settings['unionpay'] = [
                    'enabled' => isset($_POST['unionpay_enabled']) ? 1 : 0,
                    'mer_id' => sanitize_text_field($_POST['unionpay_mer_id'] ?? ''),
                    'private_key' => sanitize_textarea_field($_POST['unionpay_private_key'] ?? ''),
                    'public_key' => sanitize_textarea_field($_POST['unionpay_public_key'] ?? ''),
                    'sandbox' => isset($_POST['unionpay_sandbox']) ? 1 : 0,
                ];

                $payment_settings['third_party'] = [
                    'enabled' => isset($_POST['third_party_enabled']) ? 1 : 0,
                    'provider' => sanitize_text_field($_POST['third_party_provider'] ?? 'payjs'),
                    'payjs' => [
                        'enabled' => isset($_POST['payjs_enabled']) ? 1 : 0,
                        'mchid' => sanitize_text_field($_POST['payjs_mchid'] ?? ''),
                        'key' => sanitize_text_field($_POST['payjs_key'] ?? ''),
                    ],
                    'hupijiao' => [
                        'enabled' => isset($_POST['hupijiao_enabled']) ? 1 : 0,
                        'app_id' => sanitize_text_field($_POST['hupijiao_app_id'] ?? ''),
                        'app_secret' => sanitize_text_field($_POST['hupijiao_app_secret'] ?? ''),
                        'gateway' => sanitize_text_field($_POST['hupijiao_gateway'] ?? ''),
                    ],
                    'paypal' => [
                        'enabled' => isset($_POST['paypal_enabled']) ? 1 : 0,
                        'sandbox' => isset($_POST['paypal_sandbox']) ? 1 : 0,
                        'accounts' => []
                    ],
                ];

                // Process paypal multiple accounts
                $paypal_accounts = [];
                if (isset($_POST['paypal_accounts']) && is_array($_POST['paypal_accounts'])) {
                    $cids = $_POST['paypal_accounts']['client_id'] ?? [];
                    $csecs = $_POST['paypal_accounts']['client_secret'] ?? [];
                    $whids = $_POST['paypal_accounts']['webhook_id'] ?? [];
                    
                    for ($i = 0; $i < count($cids); $i++) {
                        if (!empty($cids[$i]) && !empty($csecs[$i])) {
                            $paypal_accounts[] = [
                                'client_id' => sanitize_text_field($cids[$i]),
                                'client_secret' => sanitize_text_field($csecs[$i]),
                                'webhook_id' => sanitize_text_field($whids[$i] ?? '')
                            ];
                        }
                    }
                }
                
                // Fallback for single account format if submitted
                if (empty($paypal_accounts) && !empty($_POST['paypal_client_id'])) {
                    $paypal_accounts[] = [
                        'client_id' => sanitize_text_field($_POST['paypal_client_id']),
                        'client_secret' => sanitize_text_field($_POST['paypal_client_secret']),
                        'webhook_id' => sanitize_text_field($_POST['paypal_webhook_id'] ?? '')
                    ];
                }
                $payment_settings['third_party']['paypal']['accounts'] = $paypal_accounts;

                update_option('payjs_mchid', $payment_settings['third_party']['payjs']['mchid']);
                update_option('payjs_key', $payment_settings['third_party']['payjs']['key']);
                
                // AB 支付配置
                update_option('ab_payment_shared_secret', sanitize_text_field($_POST['ab_payment_shared_secret'] ?? ''));
                update_option('ab_payment_is_b_site', isset($_POST['ab_payment_is_b_site']) ? 1 : 0);
                update_option('ab_payment_b_site_urls', sanitize_textarea_field($_POST['ab_payment_b_site_urls'] ?? ''));
                update_option('ab_payment_product_whitelist', sanitize_textarea_field($_POST['ab_payment_product_whitelist'] ?? ''));
                
                // 保存设置
                update_option('todo_payment_settings', $payment_settings);
                todo_admin_notice('支付设置保存成功', 'success');
                break;
        }
    }
}

// 获取当前设置
$payment_settings = get_option('todo_payment_settings', []);

// 默认设置
$default_settings = [
    'alipay' => ['enabled' => 0, 'app_id' => '', 'private_key' => '', 'public_key' => ''],
    'wechat' => ['enabled' => 0, 'app_id' => '', 'mch_id' => '', 'api_key' => '', 'cert_path' => '', 'key_path' => ''],
    'bank_transfer' => ['enabled' => 0, 'bank_name' => '', 'account_name' => '', 'account_number' => '', 'instructions' => ''],
    'cod' => ['enabled' => 0, 'fee' => 0, 'min_amount' => 0, 'max_amount' => 0, 'instructions' => ''],
    'balance' => ['enabled' => 0],
    'unionpay' => ['enabled' => 0, 'mer_id' => '', 'private_key' => '', 'public_key' => '', 'sandbox' => 0],
    'third_party' => [
        'enabled' => 0,
        'provider' => 'payjs',
        'payjs' => ['enabled' => 0, 'mchid' => '', 'key' => ''],
        'hupijiao' => ['enabled' => 0, 'app_id' => '', 'app_secret' => '', 'gateway' => ''],
        'paypal' => ['enabled' => 0, 'client_id' => '', 'client_secret' => '', 'webhook_id' => ''],
    ],
];

$payment_settings = array_merge($default_settings, $payment_settings);

// 页面标题
$page_title = '支付平台配置';
?>

<div class="admin-page">
    <div class="admin-page-header">
        <h2 class="admin-page-title"><?php echo $page_title; ?></h2>
    </div>
    
    <ul class="admin-tabs">
        <li>
            <a href="#alipay" class="active" onclick="switchTab(event, 'alipay')">支付宝</a>
        </li>
        <li>
            <a href="#wechat" onclick="switchTab(event, 'wechat')">微信支付</a>
        </li>
        <li>
            <a href="#unionpay" onclick="switchTab(event, 'unionpay')">云闪付</a>
        </li>
        <li>
            <a href="#bank" onclick="switchTab(event, 'bank')">银行转账</a>
        </li>
        <li>
            <a href="#cod" onclick="switchTab(event, 'cod')">货到付款</a>
        </li>
        <li>
            <a href="#balance" onclick="switchTab(event, 'balance')">余额支付</a>
        </li>
        <li>
            <a href="#third-party" onclick="switchTab(event, 'third-party')">第三方支付</a>
        </li>
        <li class="tab-last">
            <a href="#ab-payment" onclick="switchTab(event, 'ab-payment')">AB支付</a>
        </li>
    </ul>
    
    <div class="admin-card">
        <div class="admin-card-header">
            <p class="description">启用付款方式后，结算最终支付方式取决于 [服务设置 > 支付列表] 配置</p>
        </div>
        <div class="admin-card-content">
            <form method="POST" id="paymentForm" action="<?php echo todo_admin_url('index.php?page=' . (isset($_GET['page']) ? $_GET['page'] : 'todo-payments')); ?>">
                <?php echo todo_csrf_token_field('payment_methods'); ?>
                <input type="hidden" name="action" value="save_settings">
        
                <!-- 支付宝设置 -->
                <div id="alipay" class="tab-content active">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="alipay_enabled" value="1" <?php checked($payment_settings['alipay']['enabled']); ?>>
                                    启用支付宝支付
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>应用ID (APP_ID)</th>
                            <td>
                                <input type="text" name="alipay_app_id" value="<?php echo esc_attr($payment_settings['alipay']['app_id']); ?>" class="regular-text">
                                <p class="description">支付宝开放平台应用的APP_ID</p>
                            </td>
                        </tr>
                        <tr>
                            <th>应用私钥</th>
                            <td>
                                <textarea name="alipay_private_key" rows="8" class="large-text"><?php echo esc_textarea($payment_settings['alipay']['private_key']); ?></textarea>
                                <p class="description">应用私钥，用于签名</p>
                            </td>
                        </tr>
                        <tr>
                            <th>支付宝公钥</th>
                            <td>
                                <textarea name="alipay_public_key" rows="8" class="large-text"><?php echo esc_textarea($payment_settings['alipay']['public_key']); ?></textarea>
                                <p class="description">支付宝公钥，用于验签</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 微信支付设置 -->
                <div id="wechat" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wechat_enabled" value="1" <?php checked($payment_settings['wechat']['enabled']); ?>>
                                    启用微信支付
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>应用ID (AppID)</th>
                            <td>
                                <input type="text" name="wechat_app_id" value="<?php echo esc_attr($payment_settings['wechat']['app_id']); ?>" class="regular-text">
                                <p class="description">微信公众号或支付主体的AppID</p>
                            </td>
                        </tr>
                        <tr>
                            <th>小程序 AppID</th>
                            <td>
                                <input type="text" name="wechat_mini_appid" value="<?php echo esc_attr($payment_settings['wechat']['mini_appid'] ?? ''); ?>" class="regular-text">
                                <p class="description">微信小程序的AppID（用于小程序登录和支付）</p>
                            </td>
                        </tr>
                        <tr>
                            <th>小程序 AppSecret</th>
                            <td>
                                <input type="password" name="wechat_mini_secret" value="<?php echo esc_attr($payment_settings['wechat']['mini_secret'] ?? ''); ?>" class="regular-text">
                                <p class="description">微信小程序的AppSecret（用于获取openid）</p>
                            </td>
                        </tr>
                        <tr>
                            <th>商户号 MCH_ID</th>
                            <td>
                                <input type="text" name="wechat_mch_id" value="<?php echo esc_attr($payment_settings['wechat']['mch_id']); ?>" class="regular-text">
                                <p class="description">微信支付商户号</p>
                            </td>
                        </tr>
                        <tr>
                            <th>API密钥</th>
                            <td>
                                <input type="text" name="wechat_api_key" value="<?php echo esc_attr($payment_settings['wechat']['api_key']); ?>" class="regular-text">
                                <p class="description">微信支付API密钥</p>
                            </td>
                        </tr>
                        <tr>
                            <th>证书路径</th>
                            <td>
                                <input type="text" name="wechat_cert_path" value="<?php echo esc_attr($payment_settings['wechat']['cert_path']); ?>" class="regular-text">
                                <p class="description">apiclient_cert.pem 证书文件路径</p>
                            </td>
                        </tr>
                        <tr>
                            <th>私钥路径</th>
                            <td>
                                <input type="text" name="wechat_key_path" value="<?php echo esc_attr($payment_settings['wechat']['key_path']); ?>" class="regular-text">
                                <p class="description">apiclient_key.pem 私钥文件路径</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 云闪付设置 -->
                <div id="unionpay" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <label style="margin-right: 20px;">
                                        <input type="checkbox" name="unionpay_enabled" value="1" <?php checked(!empty($payment_settings['unionpay']['enabled'])); ?>>
                                        启用云闪付支付
                                    </label>
                                    <label>
                                        <input type="checkbox" name="unionpay_sandbox" value="1" <?php checked(!empty($payment_settings['unionpay']['sandbox'])); ?>>
                                        沙盒测试模式 (Sandbox)
                                    </label>
                                </div>
                                <p class="description">银联云闪付直连与跨境支付。支持国内结算及银联国际外币结算。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>商户代码 (MerId)</th>
                            <td>
                                <input type="text" name="unionpay_mer_id" value="<?php echo esc_attr($payment_settings['unionpay']['mer_id'] ?? ''); ?>" class="regular-text">
                                <p class="description">银联商户代码 (MerId，通常为 15 位数字)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>商户签名私钥</th>
                            <td>
                                <textarea name="unionpay_private_key" rows="6" class="large-text"><?php echo esc_textarea($payment_settings['unionpay']['private_key'] ?? ''); ?></textarea>
                                <p class="description">商户 RSA 签名私钥</p>
                            </td>
                        </tr>
                        <tr>
                            <th>银联验签公钥</th>
                            <td>
                                <textarea name="unionpay_public_key" rows="6" class="large-text"><?php echo esc_textarea($payment_settings['unionpay']['public_key'] ?? ''); ?></textarea>
                                <p class="description">中国银联签名验证公钥</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 银行转账设置 -->
                <div id="bank" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="bank_enabled" value="1" <?php checked($payment_settings['bank_transfer']['enabled']); ?>>
                                    启用银行转账支付
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>银行名称</th>
                            <td>
                                <input type="text" name="bank_name" value="<?php echo esc_attr($payment_settings['bank_transfer']['bank_name']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th>账户名称</th>
                            <td>
                                <input type="text" name="account_name" value="<?php echo esc_attr($payment_settings['bank_transfer']['account_name']); ?>" class="regular-text">
                                <p class="description">身份证姓名 或 营业执照全称，必须与银行预留信息一致</p>
                            </td>
                        </tr>
                        <tr>
                            <th>账户号码</th>
                            <td>
                                <input type="text" name="account_number" value="<?php echo esc_attr($payment_settings['bank_transfer']['account_number']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th>转账说明</th>
                            <td>
                                <textarea name="bank_instructions" rows="5" class="large-text"><?php echo esc_textarea($payment_settings['bank_transfer']['instructions']); ?></textarea>
                                <p class="description">显示给用户的转账说明</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 货到付款设置 -->
                <div id="cod" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cod_enabled" value="1" <?php checked($payment_settings['cod']['enabled']); ?>>
                                    启用货到付款
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>手续费</th>
                            <td>
                                <input type="number" name="cod_fee" value="<?php echo esc_attr($payment_settings['cod']['fee']); ?>" step="0.01" min="0" class="small-text">
                                <span><?php echo todo_get_default_currency_config()['code']; ?></span>
                                <p class="description">货到付款手续费，0表示免费</p>
                            </td>
                        </tr>
                        <tr>
                            <th>最小金额</th>
                            <td>
                                <input type="number" name="cod_min_amount" value="<?php echo esc_attr($payment_settings['cod']['min_amount']); ?>" step="0.01" min="0" class="small-text">
                                <span><?php echo todo_get_default_currency_config()['code']; ?></span>
                                <p class="description">支持货到付款的最小订单金额，0表示无限制</p>
                            </td>
                        </tr>
                        <tr>
                            <th>最大金额</th>
                            <td>
                                <input type="number" name="cod_max_amount" value="<?php echo esc_attr($payment_settings['cod']['max_amount']); ?>" step="0.01" min="0" class="small-text">
                                <span><?php echo todo_get_default_currency_config()['code']; ?></span>
                                <p class="description">支持货到付款的最大订单金额，0表示无限制</p>
                            </td>
                        </tr>
                        <tr>
                            <th>付款说明</th>
                            <td>
                                <textarea name="cod_instructions" rows="5" class="large-text"><?php echo esc_textarea($payment_settings['cod']['instructions']); ?></textarea>
                                <p class="description">显示给用户的货到付款说明</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 余额支付设置 -->
                <div id="balance" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="balance_enabled" value="1" <?php checked($payment_settings['balance']['enabled']); ?>>
                                    启用用户余额支付
                                </label>
                                <p class="description">允许用户使用账户余额支付订单</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="third-party" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="third_party_enabled" value="1" <?php checked($payment_settings['third_party']['enabled']); ?>>
                                    启用第三方支付通道
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>默认通道</th>
                            <td>
                                <select name="third_party_provider">
                                    <option value="payjs" <?php selected(($payment_settings['third_party']['provider'] ?? 'payjs'), 'payjs'); ?>>PayJS</option>
                                    <option value="hupijiao" <?php selected(($payment_settings['third_party']['provider'] ?? 'payjs'), 'hupijiao'); ?>>虎皮椒</option>
                                    <option value="paypal" <?php selected(($payment_settings['third_party']['provider'] ?? 'paypal'), 'paypal'); ?>>PayPal</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">PayJS</h3>
                        <span class="description m-0">https://payjs.cn</span>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="payjs_enabled" value="1" <?php checked($payment_settings['third_party']['payjs']['enabled']); ?>>
                                    启用 PayJS
                                </label>
                                <p class="description">可用于微信/支付宝</p>
                            </td>
                        </tr>
                        <tr>
                            <th>商户号 mchid</th>
                            <td>
                                <input type="text" name="payjs_mchid" value="<?php echo esc_attr($payment_settings['third_party']['payjs']['mchid']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th>通信密钥 key</th>
                            <td>
                                <input type="password" name="payjs_key" value="<?php echo esc_attr($payment_settings['third_party']['payjs']['key']); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">虎皮椒</h3>
                        <span class="description m-0">https://www.xunhupay.com</span>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="hupijiao_enabled" value="1" <?php checked($payment_settings['third_party']['hupijiao']['enabled']); ?>>
                                    启用 虎皮椒
                                </label>
                                <p class="description">可用于微信/支付宝</p>
                            </td>
                        </tr>
                        <tr>
                            <th>AppID</th>
                            <td>
                                <input type="text" name="hupijiao_app_id" value="<?php echo esc_attr($payment_settings['third_party']['hupijiao']['app_id']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th>AppSecret</th>
                            <td>
                                <input type="password" name="hupijiao_app_secret" value="<?php echo esc_attr($payment_settings['third_party']['hupijiao']['app_secret']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th>网关地址</th>
                            <td>
                                <input type="text" name="hupijiao_gateway" value="<?php echo esc_attr($payment_settings['third_party']['hupijiao']['gateway']); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">PayPal</h3>
                        <span class="description m-0">https://www.paypal.com</span>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th></th>
                            <td>
                                <div class="flex gap-2 flex-wrap">
                                    <label style="margin-right: 20px;">
                                        <input type="checkbox" name="paypal_enabled" value="1" <?php checked(!empty($payment_settings['third_party']['paypal']['enabled'])); ?>>
                                        启用 PayPal
                                    </label>
                                    <label>
                                        <input type="checkbox" name="paypal_sandbox" value="1" <?php checked(!empty($payment_settings['third_party']['paypal']['sandbox'])); ?>>
                                        沙盒测试模式 (Sandbox)
                                    </label>
                                </div>
                                <p class="description">用于 Web 跨境支付（跳转 PayPal 支付页）。建议使用 PayPal 中国企业账户收款；勾选沙盒模式后将连接 PayPal 沙盒测试网关。</p>
                            </td>
                        </tr>
                        <tr>
                            <th class="th-empty"></th>
                            <td colspan="2" style="padding:0;">
                                <table class="form-table paypal-accounts-table" style="background: #f9f9f9;padding: 10px 15px 0;border-radius: 4px;">
                                    <thead>
                                        <tr>
                                            <th style="text-align: center; padding: 0;">Client ID</th>
                                            <th style="text-align: center; padding: 0;">Client Secret</th>
                                            <th style="text-align: center; padding: 0;">Webhook ID</th>
                                        </tr>
                                    </thead>
                                    <tbody id="paypal-accounts-container">
                                        <?php
                                        $accounts = $payment_settings['third_party']['paypal']['accounts'] ?? [];
                                        // 兼容旧的单账号结构
                                        if (empty($accounts) && !empty($payment_settings['third_party']['paypal']['client_id'])) {
                                            $accounts[] = [
                                                'client_id' => $payment_settings['third_party']['paypal']['client_id'],
                                                'client_secret' => $payment_settings['third_party']['paypal']['client_secret'],
                                                'webhook_id' => $payment_settings['third_party']['paypal']['webhook_id'] ?? '',
                                            ];
                                        }
                                        if (empty($accounts)) {
                                            $accounts[] = ['client_id' => '', 'client_secret' => '', 'webhook_id' => ''];
                                        }
                                        foreach ($accounts as $acc):
                                        ?>
                                        <tr class="paypal-account-row" style="display: flex; flex-wrap: wrap; gap: 5px; align-items: center;">
                                            <td><input type="text" name="paypal_accounts[client_id][]" value="<?php echo esc_attr($acc['client_id']); ?>" class="regular-text" style="width:100%"></td>
                                            <td><input type="password" name="paypal_accounts[client_secret][]" value="<?php echo esc_attr($acc['client_secret']); ?>" class="regular-text" style="width:100%"></td>
                                            <td><input type="text" name="paypal_accounts[webhook_id][]" value="<?php echo esc_attr($acc['webhook_id']); ?>" class="regular-text" style="width:100%"></td>
                                            <td><button type="button" class="button button-secondary remove-paypal-account"><i class="toicon">✕</i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="4"><button type="button" class="button button-secondary" id="add-paypal-account">+ 添加 PayPal 账号</button></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <p class="description">配置多个 PayPal 账户实现收款轮询。系统会在创建订单时自动轮流分配收款账号。</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- AB 跳转支付 -->
                <div id="ab-payment" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th>安全通信密钥 Shared Secret</th>
                            <td>
                                <input type="text" name="ab_payment_shared_secret" value="<?php echo esc_attr(get_option('ab_payment_shared_secret', '')); ?>" class="regular-text">
                                <p class="description">A站与B站通信所需的签名密钥。留空则表示关闭AB支付跳转（回退到本地支付）。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>启用 B站 模式</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ab_payment_is_b_site" value="1" <?php checked(get_option('ab_payment_is_b_site', 0)); ?>>
                                    当前站点是否作为支付网关 (B站)
                                </label>
                                <p class="description">如果不勾选，则当前站点作为商城展示站 (A站)。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>B站支付域名列表</th>
                            <td>
                                <textarea name="ab_payment_b_site_urls" rows="4" class="large-text"><?php echo esc_textarea(get_option('ab_payment_b_site_urls', '')); ?></textarea>
                                <p class="description">仅A站生效。填写需要轮询的B站网址（包含http(s)://前缀，无尾斜杠），如果有多个请用英文逗号分隔。</p>
                            </td>
                        </tr>
                        <tr>
                            <th>商品白名单</th>
                            <td>
                                <textarea name="ab_payment_product_whitelist" rows="4" class="large-text"><?php echo esc_textarea(get_option('ab_payment_product_whitelist', '')); ?></textarea>
                                <p class="description">仅A站生效。格式：A站商品ID:B站商品ID，多个用英文逗号分隔（如：<code>101:201,102:202</code>）。如果填写了白名单，订单中包含白名单内的商品才走AB支付，未在白名单内的商品走A站本地支付。留空表示全站商品默认均走AB跳转。</p>
                            </td>
                        </tr>
                    </table>
                </div>
        
                <div class="form-submit">
                    <button type="submit" name="submit" class="button button-primary">保存设置</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 支付方式状态概览 -->
    <div class="admin-card">
        <div class="admin-card-header no-border">
            <h3 class="admin-card-title">状态</h3>
        </div>
        <div class="admin-card-content">
            <table class="admin-table">
            <thead>
                <tr>
                    <th style="min-width: 200px;">支付方式</th>
                    <th style="min-width: 80px;">状态</th>
                    <th style="min-width: 60px;">配置状态</th>
                    <th style="min-width: 200px;">说明</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>支付宝</td>
                    <td>
                        <?php if ($payment_settings['alipay']['enabled']): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['alipay']['app_id']) && !empty($payment_settings['alipay']['private_key'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>在线支付，即时到账</td>
                </tr>
                <tr>
                    <td>微信支付</td>
                    <td>
                        <?php if ($payment_settings['wechat']['enabled']): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['wechat']['app_id']) && !empty($payment_settings['wechat']['mch_id'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>在线支付，即时到账</td>
                </tr>
                <tr>
                    <td>银行转账</td>
                    <td>
                        <?php if ($payment_settings['bank_transfer']['enabled']): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['bank_transfer']['bank_name']) && !empty($payment_settings['bank_transfer']['account_number'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>线下转账，需人工确认</td>
                </tr>
                <tr>
                    <td>货到付款</td>
                    <td>
                        <?php if ($payment_settings['cod']['enabled']): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td></td>
                    <td>送货上门收款</td>
                </tr>
                <tr>
                    <td>余额支付</td>
                    <td>
                        <?php if ($payment_settings['balance']['enabled']): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td></td>
                    <td>使用账户余额支付</td>
                </tr>
                <tr>
                    <td>云闪付（UnionPay）</td>
                    <td>
                        <?php if (!empty($payment_settings['unionpay']['enabled'])): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['unionpay']['mer_id']) && !empty($payment_settings['unionpay']['private_key'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>银联云闪付 B2C 网关与外币卡跨境结算</td>
                </tr>
                <tr>
                    <td>第三方支付（PayJS）</td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['enabled']) && !empty($payment_settings['third_party']['payjs']['enabled'])): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['payjs']['mchid']) && !empty($payment_settings['third_party']['payjs']['key'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>第三方聚合通道（微信/支付宝）</td>
                </tr>
                <tr>
                    <td>第三方支付（虎皮椒）</td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['enabled']) && !empty($payment_settings['third_party']['hupijiao']['enabled'])): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['hupijiao']['app_id']) && !empty($payment_settings['third_party']['hupijiao']['app_secret'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>第三方聚合通道（微信/支付宝）</td>
                </tr>
                <tr>
                    <td>PayPal</td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['enabled']) && !empty($payment_settings['third_party']['paypal']['enabled'])): ?>
                            <span class="status-active">已启用</span>
                        <?php else: ?>
                            <span class="status-inactive">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($payment_settings['third_party']['paypal']['client_id']) && !empty($payment_settings['third_party']['paypal']['client_secret']) && !empty($payment_settings['third_party']['paypal']['webhook_id'])): ?>
                            <span class="status-active">已配置</span>
                        <?php else: ?>
                            <span class="status-pending">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td>Web 跨境支付（PayPal）</td>
                </tr>
            </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
/**
 * 切换标签页
 */
function switchTab(event, tabId) {
    event.preventDefault();
    
    // 隐藏所有标签内容
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(content => {
        content.classList.remove('active');
    });
    
    // 移除所有标签的活动状态
    const tabs = document.querySelectorAll('.admin-tabs a');
    tabs.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // 显示当前标签内容
    document.getElementById(tabId).classList.add('active');
    
    // 设置当前标签为活动状态
    event.target.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // 添加 PayPal 账号
    const addBtn = document.getElementById('add-paypal-account');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            const container = document.getElementById('paypal-accounts-container');
            const row = document.createElement('tr');
            row.className = 'paypal-account-row';
            row.style = "display: flex; flex-wrap: wrap; gap: 5px; align-items: center;";
            row.innerHTML = `
                <td><input type="text" name="paypal_accounts[client_id][]" value="" class="regular-text" style="width:100%"></td>
                <td><input type="password" name="paypal_accounts[client_secret][]" value="" class="regular-text" style="width:100%"></td>
                <td><input type="text" name="paypal_accounts[webhook_id][]" value="" class="regular-text" style="width:100%"></td>
                <td><button type="button" class="button button-secondary remove-paypal-account"><i class="toicon">✕</i></button></td>
            `;
            container.appendChild(row);
        });
    }

    // 删除 PayPal 账号
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('remove-paypal-account')) {
            const rows = document.querySelectorAll('.paypal-account-row');
            if (rows.length > 1) {
                e.target.closest('tr').remove();
            } else {
                TodoCMS.Utils.showMessage('至少需要保留一个账号配置行（若不使用可全部留空或直接禁用 PayPal）', 'warning');
            }
        }
    });
});
</script>

<style>
.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.tab-last {
    margin-inline-start: auto;
}

.mbody .tab-last {
    margin-inline-start: unset;
}

.mbody .th-empty {
    display: none;
}

.mbody .paypal-account-row td {
    width: 100%;
}
</style>

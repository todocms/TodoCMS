<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}
require_once ABSPATH . 'core/functions/utility-functions-extended.php';
require_once ABSPATH . 'core/functions/missing-core-functions.php';

// 确保安全函数可用
if (!function_exists('todo_sanitize_key')) {
    require_once ABSPATH . 'core/functions/security-functions.php';
}

// 确保特色图片组件可用
if (!function_exists('todo_render_featured_images_component')) {
    if (defined('ADMIN_DIR') && file_exists(ADMIN_DIR . '/components/featured-images-component.php')) {
        require_once ADMIN_DIR . '/components/featured-images-component.php';
    } elseif (file_exists(ABSPATH . 'admin/components/featured-images-component.php')) {
        require_once ABSPATH . 'admin/components/featured-images-component.php';
    }
}

if (!defined('ADMIN_DIR')) {
    if (defined('TODO_ADMIN_PATH')) {
        define('ADMIN_DIR', TODO_ADMIN_PATH);
    }
}
if (defined('ADMIN_DIR')) {
    require_once ADMIN_DIR . '/pages/shop/payments-functions.php';
}

// 检查用户代理（防止简单的机器人攻击）
if (empty($_SERVER['HTTP_USER_AGENT']) || strlen($_SERVER['HTTP_USER_AGENT']) < 10) {
    // 检查是否为AJAX请求
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '无效的用户代理']);
        exit;
    }
    todo_die('无效的用户代理');
}



/**
 * 服务设置页面
 */
function todo_service_settings_page()
{
    // 检查用户权限
    if (!todo_current_user_can('manage_options')) {
        todo_redirect(todo_admin_url('index.php?page=dashboard'));
        exit;
    }

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        todo_save_service_settings();
    }

    // 获取当前设置（后台编辑表单使用原始文本 raw=true）
    $settings = todo_get_service_settings(true);
    // 支付方式标签与启用映射（由辅助函数提供）
    list($payment_labels, $enabled_lookup) = todo_service_settings_get_payment_list_data(array('wechat', 'alipay', 'unionpay', 'paypal', 'offline', 'cod', 'balance'));
    $payment_provider_options = function_exists('todo_service_settings_get_payment_provider_options')
        ? todo_service_settings_get_payment_provider_options()
        : array();
    $selected_company_ids = is_array($settings['express_companies']) ? array_map('intval', $settings['express_companies']) : [];
    $all_companies = todo_service_settings_get_express_companies();
    ?>
    <div class="admin-page">
        <div class="admin-page-header">
            <h2 class="admin-page-title">服务设置</h2>
        </div>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'save_failed'): ?>
            <div class="notice notice-error is-dismissible">
                <p>服务设置保存失败，请稍后重试。</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
            <div class="notice notice-success is-dismissible">
                <p>服务设置已保存。</p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php
            if (function_exists('todo_csrf_field')) {
                echo todo_csrf_field('service_settings', '_todononce');
            } elseif (class_exists('Todo_Security_Manager')) {
                Todo_Security_Manager::init();
                echo Todo_Security_Manager::csrf_field('service_settings', '_todononce');
            }
            ?>
            <input type="hidden" name="action" value="save_service_settings" />
            <input type="hidden" name="timestamp" value="<?php echo time(); ?>" />

            <div class="flex gap-5 sev-form">
                <div class="flex flex-col flex-1 sev-left">
                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h3 class="admin-card-title">支付列表</h3>
                        </div>
                        <div class="admin-card-content">
                            <div id="selected-payment-methods" class="chip-list">
                                <?php
                                if (!empty($settings['payment_methods']) && is_array($settings['payment_methods'])) {
                                    foreach ($settings['payment_methods'] as $code) {
                                        $code = sanitize_text_field($code);
                                        $label = $payment_labels[$code] ?? strtoupper($code);
                                        echo '<div class="chip" data-code="' . esc_attr($code) . '">'
                                            . '<span class="chip-text">' . esc_html($label) . ' <em>' . esc_html($code) . '</em></span>'
                                            . '<input type="hidden" name="payment_methods[]" value="' . esc_attr($code) . '">'
                                            . '<button type="button" class="chip-remove" aria-label="移除">×</button>'
                                            . '</div>';
                                    }
                                }
                                ?>
                            </div>
                            <div id="selected-payment-providers">
                                <?php
                                 $payment_providers = isset($settings['payment_providers']) && is_array($settings['payment_providers']) ? $settings['payment_providers'] : array();
                                $wechat_provider = isset($payment_providers['wechat']) ? sanitize_text_field($payment_providers['wechat']) : 'payjs';
                                $alipay_provider = isset($payment_providers['alipay']) ? sanitize_text_field($payment_providers['alipay']) : 'payjs';
                                $unionpay_provider = isset($payment_providers['unionpay']) ? sanitize_text_field($payment_providers['unionpay']) : 'unionpay_direct';
                                echo '<input type="hidden" name="payment_providers[wechat]" value="' . esc_attr($wechat_provider) . '">';
                                echo '<input type="hidden" name="payment_providers[alipay]" value="' . esc_attr($alipay_provider) . '">';
                                echo '<input type="hidden" name="payment_providers[unionpay]" value="' . esc_attr($unionpay_provider) . '">';
                                ?>
                            </div>
                            <button type="button" class="button" id="btn-open-payment">配置支付列表</button>
                            <p class="description">选择并排序结算页展示的支付方式。</p>
                        </div>
                    </div>

                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h3 class="admin-card-title">快递公司</h3>
                        </div>
                        <div class="admin-card-content">
                            <input type="hidden" name="express_companies_present" value="1" />
                            <div id="selected-express-companies" class="chip-list">
                                <?php
                                if (!empty($selected_company_ids)) {
                                    // 建立索引
                                    $company_map = [];
                                    foreach ($all_companies as $c) {
                                        $company_map[(int) $c->id] = $c;
                                    }
                                    foreach ($selected_company_ids as $cid) {
                                        if (isset($company_map[$cid])) {
                                            $c = $company_map[$cid];
                                            echo '<div class="chip" data-id="' . (int) $c->id . '">'
                                                . '<span class="chip-text">' . esc_html($c->name) . ' <em>' . esc_html($c->code) . '</em></span>'
                                                . '<input type="hidden" name="express_companies[]" value="' . (int) $c->id . '">'
                                                . '<button type="button" class="chip-remove" aria-label="移除">×</button>'
                                                . '</div>';
                                        }
                                    }
                                }
                                ?>
                            </div>
                            <button type="button" class="button" id="btn-open-express">选择快递公司</button>
                            <p class="description">从系统快递公司库中选择可用项。</p>
                        </div>
                    </div>

                    <div class="admin-card">
                        <div class="admin-card-header">
                            <h3 class="admin-card-title">配送方式</h3>
                        </div>
                        <div class="admin-card-content">
                            <input type="hidden" name="shipping_methods_present" value="1" />
                            <table class="admin-table" id="shipping-methods-table" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                    	<th style="width: 50px; text-align: center;">启用</th>
                                        <th style="min-width: 120px;">标识代码</th>
                                        <th style="min-width: 140px;">配送名称</th>
                                        <th style="min-width: 100px;">运费<?php echo todo_get_default_currency_config()['symbol']; ?></th>
                                        <th style="min-width: 140px;">描述说明</th>
                                        <th style="width: 60px; text-align: center;">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="shipping-methods-list">
                                    <?php
                                    $shipping_methods = isset($settings['shipping_methods']) && is_array($settings['shipping_methods']) ? $settings['shipping_methods'] : array();
                                    if (!empty($shipping_methods)):
                                        foreach ($shipping_methods as $idx => $sm):
                                            $code = esc_attr($sm['code'] ?? '');
                                            $name = esc_attr($sm['name'] ?? '');
                                            $fee = esc_attr($sm['fee'] ?? '0.00');
                                            $desc = esc_attr($sm['desc'] ?? '');
                                            $enabled = !empty($sm['enabled']);
                                    ?>
                                    <tr class="shipping-method-row">
                                    	<td style="text-align: center;">
                                            <input type="checkbox" name="shipping_methods[<?php echo $idx; ?>][enabled]" value="1" <?php checked($enabled, true); ?>>
                                        </td>
                                        <td><input type="text" name="shipping_methods[<?php echo $idx; ?>][code]" value="<?php echo $code; ?>" class="regular-text" style="min-width: 100%; width: 100%;" placeholder="如 express" required></td>
                                        <td><input type="text" name="shipping_methods[<?php echo $idx; ?>][name]" value="<?php echo $name; ?>" class="regular-text" style="min-width: 100%; width: 100%;" placeholder="如 快递配送" required></td>
                                        <td><input type="number" step="0.01" min="0" name="shipping_methods[<?php echo $idx; ?>][fee]" value="<?php echo $fee; ?>" class="regular-text" style="min-width: 100%; width: 100%;" placeholder="10.00"></td>
                                        <td><input type="text" name="shipping_methods[<?php echo $idx; ?>][desc]" value="<?php echo $desc; ?>" class="regular-text" style="min-width: 100%; width: 100%;" placeholder="如 预计 2-3 天送达"></td>
                                        <td style="text-align: center;">
                                            <button type="button" class="button button-secondary btn-remove-shipping-row"><i class="toicon">✕</i></button>
                                        </td>
                                    </tr>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </tbody>
                            </table>
                            <button type="button" class="button" id="btn-add-shipping-method">+ 添加配送方式</button>
                            <p class="description">配置结算页面展示的配送方式及固定运费价格。</p>
                        </div>
                    </div>

                    <!-- 左侧：基础表单 -->
                    <div class="admin-card flex-1">
                        <div class="admin-card-header">
                            <h3 class="admin-card-title">其他基础服务</h3>
                        </div>
                        <div class="admin-card-content">
                            <div class="form-group">
                                <label>统一提示</label>
                                <textarea id="unified_notice" name="unified_notice" rows="3" style="width: 100%;"
                                    class="large-text"><?php echo todo_esc_textarea($settings['unified_notice']); ?></textarea>
                                <p class="description">在下单确认页面显示的统一提示信息，支持html。</p>
                            </div>
                            <div class="form-group">
                                <label>游客结账</label>
                                <label>
                                    <input type="checkbox" id="guest_checkout" name="guest_checkout" value="1" <?php todo_checked($settings['guest_checkout'], 1); ?> />
                                    允许游客结账
                                </label>
                                <p class="description">开启后，未登录用户也可以进行结账</p>
                            </div>
                            <div class="form-group">
                                <label>评价审核</label>
                                <label>
                                    <input type="checkbox" id="review_approval" name="review_approval" value="1" <?php todo_checked($settings['review_approval'], 1); ?> />
                                    开启评价审核
                                </label>
                                <p class="description">仅控制评价审核，是否显示评价取决于 设置 > 网站信息 > 讨论 > <a href="<?php echo todo_admin_url('index.php?page=settings&tab=discussion'); ?>">评论设置</a></p>
                            </div>
                            <div class="form-group">
                                <label>售后申请</label>
                                <label>
                                    <input type="checkbox" id="allow_refund" name="allow_refund" value="1" <?php todo_checked(!empty($settings['allow_refund']), 1); ?> />
                                    开启售后通道
                                </label>
                                <p class="description">默认关闭，用户中心将不展示“申请售后”入口，禁止前台提交售后；开启则可在订单完成或发货后提交退款申请。</p>
                            </div>
                            <div class="form-group">
                                <label>售后保障期</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="number" id="return_window_days" name="return_window_days" min="0" max="365" step="1" value="<?php echo esc_attr(isset($settings['return_window_days']) ? $settings['return_window_days'] : 14); ?>" style="width: 100px;" class="regular-text" />
                                    <span>天</span>
                                </div>
                                <p class="description">发货或订单完成后允许申请售后的有效天数（国际电商标准通常为 14 或 30 天）。0 表示不限期。</p>
                            </div>
                            <div class="form-group">
                                <label>售后申请机会</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="number" id="refund_max_attempts" name="refund_max_attempts" min="1" max="10" step="1" value="<?php echo esc_attr(isset($settings['refund_max_attempts']) ? $settings['refund_max_attempts'] : 3); ?>" style="width: 100px;" class="regular-text" />
                                    <span>次</span>
                                </div>
                                <p class="description">同一订单允许发起售后的最大次数（包含被驳回或撤销后重新提交的次数，防恶意刷单，默认 3 次）。</p>
                            </div>
                            <div class="form-group">
                                <label>自动确认收货</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="number" id="auto_confirm_days" name="auto_confirm_days" min="0" max="90" step="1" value="<?php echo esc_attr(isset($settings['auto_confirm_days']) ? $settings['auto_confirm_days'] : 7); ?>" style="width: 100px;" class="regular-text" />
                                    <span>天</span>
                                </div>
                                <p class="description">发货后超过指定天数将自动确认并完成订单。0 则禁用自动确认收货功能（默认 7 天）。</p>
                            </div>
                            <h4 class="admin-card-header">产品服务</h4>
                            <div class="form-group">
                                <div id="product-service-items" class="product-service-items">
                                    <input type="hidden" name="product_service_items_present" value="1" />
                                    <?php
                                    // 读取已配置的服务项（code/label）
                                    $service_items = isset($settings['product_service_items']) && is_array($settings['product_service_items'])
                                        ? $settings['product_service_items']
                                        : array();
                                    $seen_codes = array();
                                    $normalized_items = array();
                                    foreach ($service_items as $it) {
                                        $code = isset($it['code']) ? todo_sanitize_key($it['code']) : '';
                                        $label = isset($it['label']) ? sanitize_text_field($it['label']) : '';
                                        $images = array();
                                        if (isset($it['images'])) {
                                            $imgs = is_string($it['images']) ? json_decode($it['images'], true) : (is_array($it['images']) ? $it['images'] : array());
                                            if (is_array($imgs)) {
                                                foreach ($imgs as $img) {
                                                    $images[] = array(
                                                        'id' => isset($img['id']) ? intval($img['id']) : 0,
                                                        'url' => isset($img['url']) ? sanitize_text_field($img['url']) : '',
                                                        'title' => isset($img['title']) ? sanitize_text_field($img['title']) : ''
                                                    );
                                                }
                                            }
                                        }
                                        if (!$code || !$label) {
                                            continue;
                                        }
                                        if (isset($seen_codes[$code])) {
                                            continue;
                                        }
                                        $seen_codes[$code] = true;
                                        $post_id = isset($it['post_id']) ? intval($it['post_id']) : 0;
                                        $normalized_items[] = array('code' => $code, 'label' => $label, 'images' => $images, 'post_id' => $post_id);
                                    }
                                    $service_items = $normalized_items;
                                    ?>
                                    <div class="service-items-box">
                                        <div class="service-items-header"
                                            style="display:flex;gap:10px;margin-bottom:6px;color:#6b7280;">
                                            <span style="width:170px;flex-shrink: 0;">标识（code）</span>
                                            <span style="flex-shrink: 0;">显示名称（label）</span>
                                        </div>
                                        <div class="service-items-body">
                                            <?php if (!empty($service_items)):
                                                foreach ($service_items as $idx => $it): ?>
                                                    <div class="service-item-row" data-index="<?php echo (int) $idx; ?>"
                                                        style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
                                                        <input type="text"
                                                            name="product_service_items[<?php echo (int) $idx; ?>][code]"
                                                            value="<?php echo esc_attr($it['code']); ?>"
                                                            placeholder="如：shipping_insurance" pattern="[a-z0-9_]{2,40}"
                                                            title="仅限小写字母、数字、下划线" style="min-width: 160px;" />
                                                        <input type="text"
                                                            name="product_service_items[<?php echo (int) $idx; ?>][label]"
                                                            value="<?php echo esc_attr($it['label']); ?>" placeholder="如：运费险"
                                                            style="flex:1;" />
                                                        <div style="flex:1;min-width:280px;position: relative;">
                                                            <input type="number"
	                                                            name="product_service_items[<?php echo (int) $idx; ?>][post_id]"
	                                                            value="<?php echo esc_attr(isset($it['post_id']) && $it['post_id'] > 0 ? $it['post_id'] : ''); ?>" placeholder="PostID"
	                                                            style="flex:1;position: absolute;right: 0;bottom: 0;z-index: 1;min-width: 65px;width: 65px;min-height: auto;line-height: 25px;border: none;border-radius: 0;background: rgba(0, 0, 0, 0.07);opacity: 0.5;" min="0" />
                                                            <?php
                                                            $curr_images = isset($it['images']) && is_array($it['images']) ? $it['images'] : array();
                                                            if (function_exists('todo_render_featured_images_component')) {
                                                                echo todo_render_featured_images_component(array(
                                                                    'field_id' => 'service_images_' . (int) $idx,
                                                                    'field_name' => 'product_service_items[' . (int) $idx . '][images]',
                                                                    'current_images' => $curr_images,
                                                                    'multiple' => true,
                                                                    'max_images' => 6,
                                                                    'file_type' => 'image',
                                                                    'enable_debug' => false
                                                                ));
                                                            } else {
                                                                // Fallback: 手动输出 HTML 和 JS
                                                                $js_url = todo_admin_url('assets/js/featured-images-component.js');
                                                                $ver = defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
                                                                echo '<input type="hidden" name="product_service_items[' . (int) $idx . '][images]" id="service_images_' . (int) $idx . '_data" value="' . esc_attr(json_encode($curr_images)) . '">';
                                                                echo '<div class="featured-images-component" id="service_images_' . (int) $idx . '" data-field-name="product_service_items[' . (int) $idx . '][images]" data-multiple="true" data-max-images="6" data-current-images="' . esc_attr(json_encode($curr_images)) . '" data-file-type="image" data-variant="featured" data-enable-debug="false">';
                                                                echo '<div class="featured-images-preview" id="service_images_' . (int) $idx . '-preview"></div>';
                                                                echo '<div class="featured-images-actions"><button type="button" class="button button-secondary add-featured-image" data-modal-id="service_images_' . (int) $idx . '-modal" data-field-id="service_images_' . (int) $idx . '" data-multiple="true" data-file-type="image" data-max-images="6"><i class="toicon">✚</i></button></div>';
                                                                echo '</div>';
                                                                // 标记需要加载 JS（在页面底部统一加载）
                                                                if (!isset($GLOBALS['todo_featured_images_js_loaded'])) {
                                                                    $GLOBALS['todo_featured_images_js_loaded'] = true;
                                                                }
                                                            }
                                                            ?>
                                                        </div>
                                                        <button type="button" class="button button-secondary service-item-remove"
                                                            aria-label="删除"><i class="toicon">✕</i></button>
                                                    </div>
                                                <?php endforeach; else: ?>
                                                <div class="service-item-row" data-index="0"
                                                    style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
                                                    <input type="text" name="product_service_items[0][code]" value=""
                                                        placeholder="如：shipping_insurance" pattern="[a-z0-9_]{2,40}"
                                                        title="仅限小写字母、数字、下划线" style="min-width: 160px;" />
                                                    <input type="text" name="product_service_items[0][label]" value=""
                                                        placeholder="如：运费险" style="flex:1;" />
                                                    <div style="flex:1;min-width:280px;position: relative;">
                                                        <input type="number" name="product_service_items[0][post_id]" value=""
                                                            placeholder="PostID" style="flex:1;position: absolute;right: 0;bottom: 0;z-index: 1;min-width: 65px;width: 65px;min-height: auto;line-height: 25px;border: none;border-radius: 0;background: rgba(0, 0, 0, 0.07);opacity: 0.5;" min="0" />
                                                        <?php
                                                        if (function_exists('todo_render_featured_images_component')) {
                                                            echo todo_render_featured_images_component(array(
                                                                'field_id' => 'service_images_0',
                                                                'field_name' => 'product_service_items[0][images]',
                                                                'current_images' => array(),
                                                                'multiple' => true,
                                                                'max_images' => 6,
                                                                'file_type' => 'image',
                                                                'enable_debug' => false
                                                            ));
                                                        } else {
                                                            echo '<input type="hidden" name="product_service_items[0][images]" id="service_images_0_data" value="[]">';
                                                            echo '<div class="featured-images-component" id="service_images_0" data-field-name="product_service_items[0][images]" data-multiple="true" data-max-images="6" data-current-images="[]" data-file-type="image" data-variant="featured" data-enable-debug="false">';
                                                            echo '<div class="featured-images-preview" id="service_images_0-preview"></div>';
                                                            echo '<div class="featured-images-actions"><button type="button" class="button button-secondary add-featured-image" data-modal-id="service_images_0-modal" data-field-id="service_images_0" data-multiple="true" data-file-type="image" data-max-images="6"><i class="toicon">✚</i></button></div>';
                                                            echo '</div>';
                                                        }
                                                        ?>
                                                    </div>
                                                    <button type="button" class="button button-secondary service-item-remove"
                                                        aria-label="删除"><i class="toicon">✕</i></button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <button type="button" class="button button-secondary" id="add-service-item">+
                                        新增服务项</button>
                                </div>
                                <p class="description">产品服务项将在商品发布页以多选框显示；可根据需要新增、删除或重命名；图片则作为服务介绍。</p>
                                <script type="text/javascript">
                                    (function () {
                                        var wrap = document.getElementById('product-service-items');
                                        if (!wrap) return;
                                        var body = wrap.querySelector('.service-items-body');
                                        function nextIndex() {
                                            var rows = body.querySelectorAll('.service-item-row');
                                            var max = -1; rows.forEach(function (r) { var i = parseInt(r.getAttribute('data-index') || '0', 10); if (i > max) max = i; });
                                            return max + 1;
                                        }
                                        function bindRowEvents(row) {
                                            var rm = row.querySelector('.service-item-remove');
                                            if (rm) { rm.addEventListener('click', function () { row.parentNode.removeChild(row); }); }
                                        }
                                        // 绑定已有行
                                        body.querySelectorAll('.service-item-row').forEach(bindRowEvents);
                                        // 新增
                                        wrap.querySelector('#add-service-item').addEventListener('click', function () {
                                            var i = nextIndex();
                                            var div = document.createElement('div');
                                            div.className = 'service-item-row';
                                            div.setAttribute('data-index', i);
                                            div.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;';
                                            div.innerHTML = '' +
                                                '<input type="text" name="product_service_items[' + i + '][code]" value="" placeholder="如：shipping_insurance" pattern="[a-z0-9_]{2,40}" title="仅限小写字母、数字、下划线" style="min-width: 160px;" />' +
                                                '<input type="text" name="product_service_items[' + i + '][label]" value="" placeholder="如：运费险" style="flex:1;" />' +
                                                '<div style="flex:1;min-width:280px;position: relative;">' +
                                                '<input type="number" name="product_service_items[' + i + '][post_id]" value="" placeholder="PostID" style="flex:1;position: absolute;right: 0;bottom: 0;z-index: 1;min-width: 65px;width: 65px;min-height: auto;line-height: 25px;border: none;border-radius: 0;background: rgba(0, 0, 0, 0.07);opacity: 0.5;" min="0" />' +
                                                '<input type="hidden" name="product_service_items[' + i + '][images]" id="service_images_' + i + '_data" value="[]">' +
                                                '<div class="featured-images-component" id="service_images_' + i + '" data-field-name="product_service_items[' + i + '][images]" data-multiple="true" data-max-images="6" data-current-images="[]" data-file-type="image" data-variant="featured" data-enable-debug="false">' +
                                                '<div class="featured-images-preview" id="service_images_' + i + '-preview"></div>' +
                                                '<div class="featured-images-actions">' +
                                                '<button type="button" class="button button-secondary add-featured-image" data-modal-id="service_images_' + i + '-modal" data-field-id="service_images_' + i + '" data-multiple="true" data-file-type="image" data-max-images="6"><i class="toicon">✚</i></button>' +
                                                '</div>' +
                                                '</div>' +
                                                '</div>' +
                                                '<button type="button" class="button button-secondary service-item-remove" aria-label="删除"><i class="toicon">✕</i></button>';
                                            body.appendChild(div);
                                            bindRowEvents(div);
                                        });
                                    })();
                                </script>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">物流查询</h3>
                    </div>
                    <div class="admin-card-content">
                        <?php
                        $lt = isset($settings['logistics_tracking']) && is_array($settings['logistics_tracking']) ? $settings['logistics_tracking'] : array();
                        $lt_enabled = !empty($lt['enabled']);
                        $lt_sandbox = !empty($lt['sandbox']);
                        $lt_provider = isset($lt['provider']) ? sanitize_text_field($lt['provider']) : 'kuaidi100';
                        $lt_cache_minutes = isset($lt['cache_minutes']) ? intval($lt['cache_minutes']) : 10;
                        $lt_auto_poll = !empty($lt['auto_poll']);
                        $lt_poll_interval = isset($lt['poll_interval_minutes']) ? intval($lt['poll_interval_minutes']) : 60;
                        $lt_k100 = isset($lt['kuaidi100']) && is_array($lt['kuaidi100']) ? $lt['kuaidi100'] : array();
                        $lt_kdn = isset($lt['kdniao']) && is_array($lt['kdniao']) ? $lt['kdniao'] : array();
                        $lt_cai = isset($lt['cainiao']) && is_array($lt['cainiao']) ? $lt['cainiao'] : array();
                        $lt_ups = isset($lt['ups']) && is_array($lt['ups']) ? $lt['ups'] : array();
                        $lt_dhl = isset($lt['dhl']) && is_array($lt['dhl']) ? $lt['dhl'] : array();
                        $lt_nonce = function_exists('todo_create_nonce') ? todo_create_nonce('test_logistics_query') : '';
                        $lt_ajax_url = function_exists('todo_admin_url') ? todo_admin_url('admin-ajax.php') : '';
                        ?>
                        <label class="flex items-center">
                            <input type="checkbox" name="logistics_tracking[enabled]" value="1" <?php echo $lt_enabled ? 'checked' : ''; ?>>
                            <span>启用物流轨迹查询</span>
                        </label>

                        <div class="m-t-10">
                            <label>供应商</label>
                            <select id="logistics-provider" name="logistics_tracking[provider]" class="regular-text">
                                <option value="kuaidi100" <?php echo $lt_provider === 'kuaidi100' ? 'selected' : ''; ?>>快递100</option>
                                <option value="kdniao" <?php echo $lt_provider === 'kdniao' ? 'selected' : ''; ?>>快递鸟</option>
                                <option value="cainiao" <?php echo $lt_provider === 'cainiao' ? 'selected' : ''; ?>>菜鸟（TOP开放平台）</option>
                                <option value="ups" <?php echo $lt_provider === 'ups' ? 'selected' : ''; ?>>UPS 国际快递</option>
                                <option value="dhl" <?php echo $lt_provider === 'dhl' ? 'selected' : ''; ?>>DHL 国际快递</option>
                            </select>
                        </div>

                        <div class="m-t-10">
                            <label>缓存分钟数</label>
                            <input type="number" name="logistics_tracking[cache_minutes]" min="0" max="1440" value="<?php echo esc_attr($lt_cache_minutes); ?>" class="small-text">
                        </div>

                        <div class="m-t-10">
                            <label class="flex items-center">
                                <input type="checkbox" name="logistics_tracking[auto_poll]" value="1" <?php echo $lt_auto_poll ? 'checked' : ''; ?>>
                                <span>启用自动轮询更新（按访问触发）</span>
                            </label>
                            <div class="m-t-05">
                                <label>轮询间隔（分钟）</label>
                                <input type="number" name="logistics_tracking[poll_interval_minutes]" min="5" max="1440" value="<?php echo esc_attr($lt_poll_interval); ?>" class="small-text">
                            </div>
                        </div>

                        <div class="m-t-10" id="lt-sandbox-container" style="display:none;background-color: #f4f4f4;padding: 10px 10px 5px;border-radius: 5px;">
                            <label class="flex items-center">
                                <input type="checkbox" name="logistics_tracking[sandbox]" value="1" <?php echo $lt_sandbox ? 'checked' : ''; ?>>
                                <span>开启沙盒测试模式</span>
                            </label>
                            <p class="description">无 Key 或使用测试单号时返回模拟 UPS/DHL 完整轨迹与状态</p>
                        </div>

                        <div class="m-t-10" id="lt-provider-kuaidi100" style="display:none;">
                            <div class="m-t-05">
                                <label>customer</label>
                                <input type="text" name="logistics_tracking[kuaidi100][customer]" value="<?php echo esc_attr($lt_k100['customer'] ?? ''); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>key</label>
                                <input type="password" name="logistics_tracking[kuaidi100][key]" value="<?php echo esc_attr($lt_k100['key'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>endpoint</label>
                                <input type="text" name="logistics_tracking[kuaidi100][endpoint]" value="<?php echo esc_attr($lt_k100['endpoint'] ?? ''); ?>" class="large-text">
                            </div>
                        </div>

                        <div class="m-t-10" id="lt-provider-kdniao" style="display:none;">
                            <div class="m-t-05">
                                <label>EBusinessID</label>
                                <input type="text" name="logistics_tracking[kdniao][ebusiness_id]" value="<?php echo esc_attr($lt_kdn['ebusiness_id'] ?? ''); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>AppKey</label>
                                <input type="password" name="logistics_tracking[kdniao][app_key]" value="<?php echo esc_attr($lt_kdn['app_key'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>endpoint</label>
                                <input type="text" name="logistics_tracking[kdniao][endpoint]" value="<?php echo esc_attr($lt_kdn['endpoint'] ?? ''); ?>" class="large-text">
                            </div>
                            <div class="m-t-05">
                                <label>RequestType</label>
                                <input type="text" name="logistics_tracking[kdniao][request_type]" value="<?php echo esc_attr($lt_kdn['request_type'] ?? '1002'); ?>" class="small-text">
                            </div>
                        </div>

                        <div class="m-t-10" id="lt-provider-cainiao" style="display:none;">
                            <div class="m-t-05">
                                <label>app_key</label>
                                <input type="text" name="logistics_tracking[cainiao][app_key]" value="<?php echo esc_attr($lt_cai['app_key'] ?? ''); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>app_secret</label>
                                <input type="password" name="logistics_tracking[cainiao][app_secret]" value="<?php echo esc_attr($lt_cai['app_secret'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>session（如接口需要授权）</label>
                                <input type="password" name="logistics_tracking[cainiao][session]" value="<?php echo esc_attr($lt_cai['session'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>endpoint</label>
                                <input type="text" name="logistics_tracking[cainiao][endpoint]" value="<?php echo esc_attr($lt_cai['endpoint'] ?? ''); ?>" class="large-text">
                            </div>
                            <div class="m-t-05">
                                <label>method</label>
                                <input type="text" name="logistics_tracking[cainiao][method]" value="<?php echo esc_attr($lt_cai['method'] ?? 'taobao.logistics.trace.search'); ?>" class="large-text">
                            </div>
                            <div class="m-t-05">
                                <label>sign_method</label>
                                <select name="logistics_tracking[cainiao][sign_method]" class="regular-text">
                                    <option value="md5" <?php echo (isset($lt_cai['sign_method']) && strtolower((string) $lt_cai['sign_method']) === 'md5') ? 'selected' : ''; ?>>md5</option>
                                    <option value="hmac" <?php echo (isset($lt_cai['sign_method']) && strtolower((string) $lt_cai['sign_method']) === 'hmac') ? 'selected' : ''; ?>>hmac</option>
                                </select>
                            </div>
                            <div class="m-t-05">
                                <label>参数键名：公司</label>
                                <input type="text" name="logistics_tracking[cainiao][param_company_key]" value="<?php echo esc_attr($lt_cai['param_company_key'] ?? 'company_code'); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>参数键名：单号</label>
                                <input type="text" name="logistics_tracking[cainiao][param_tracking_key]" value="<?php echo esc_attr($lt_cai['param_tracking_key'] ?? 'out_sid'); ?>" class="regular-text">
                            </div>
                        </div>

                        <div class="m-t-10" id="lt-provider-ups" style="display:none;">
                            <p class="description" style="color:#666;background-color: #f7f7fa;padding: 5px 10px;border-radius: 5px;">沙盒测试单号推荐：<code>1Z9999999999999999</code>（已签收）或 <code>1Z9999999999999998</code>（运输中）</p>
                            <div class="m-t-05">
                                <label>Client ID / Access Key</label>
                                <input type="text" name="logistics_tracking[ups][client_id]" value="<?php echo esc_attr($lt_ups['client_id'] ?? ''); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>Client Secret</label>
                                <input type="password" name="logistics_tracking[ups][client_secret]" value="<?php echo esc_attr($lt_ups['client_secret'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>Access Token (或 Bearer Token)</label>
                                <input type="password" name="logistics_tracking[ups][access_token]" value="<?php echo esc_attr($lt_ups['access_token'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>Endpoint</label>
                                <input type="text" name="logistics_tracking[ups][endpoint]" value="<?php echo esc_attr($lt_ups['endpoint'] ?? 'https://onlinetools.ups.com/api/shipments/v1/transports/details/'); ?>" class="large-text">
                            </div>
                        </div>

                        <div class="m-t-10" id="lt-provider-dhl" style="display:none;">
                            <p class="description" style="color:#666;background-color: #f7f7fa;padding: 5px 10px;border-radius: 5px;">沙盒测试单号推荐：<code>7777777777</code>（已签收）或 <code>1234567890</code>（运输中）</p>
                            <div class="m-t-05">
                                <label>DHL API Key</label>
                                <input type="text" name="logistics_tracking[dhl][api_key]" value="<?php echo esc_attr($lt_dhl['api_key'] ?? ''); ?>" class="regular-text">
                            </div>
                            <div class="m-t-05">
                                <label>DHL API Secret</label>
                                <input type="password" name="logistics_tracking[dhl][api_secret]" value="<?php echo esc_attr($lt_dhl['api_secret'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                            </div>
                            <div class="m-t-05">
                                <label>Endpoint</label>
                                <input type="text" name="logistics_tracking[dhl][endpoint]" value="<?php echo esc_attr($lt_dhl['endpoint'] ?? 'https://express.api.dhl.com/mydhlapi/shipments/'); ?>" class="large-text">
                            </div>
                        </div>

                        <div class="m-t-40">
                            <label>测试查询</label>
                            <div class="m-t-05 flex items-center" style="gap:8px;flex-wrap:wrap;">
                                <input type="text" id="lt-test-company" placeholder="快递公司编码，如 UPS / DHL / SF" class="regular-text" style="width:220px;">
                                <input type="text" id="lt-test-no" placeholder="运单号 (如 1Z9999999999999999 或 7777777777)" class="regular-text" style="width:260px;">
                                <button type="button" class="button" id="lt-test-btn"
                                    data-ajax="<?php echo esc_attr($lt_ajax_url); ?>"
                                    data-nonce="<?php echo esc_attr($lt_nonce); ?>">测试查询</button>
                            </div>
                            <pre id="lt-test-result" style="white-space:pre-wrap;word-break:break-word;max-height:240px;overflow:auto;margin-top:8px;"></pre>
                        </div>

                        <script type="text/javascript">
                            (function () {
                                var sel = document.getElementById('logistics-provider');
                                function showProvider() {
                                    var v = sel ? sel.value : 'kuaidi100';
                                    var ids = ['kuaidi100', 'kdniao', 'cainiao', 'ups', 'dhl'];
                                    ids.forEach(function (k) {
                                        var el = document.getElementById('lt-provider-' + k);
                                        if (el) el.style.display = (k === v) ? 'block' : 'none';
                                    });
                                    var sandboxEl = document.getElementById('lt-sandbox-container');
                                    if (sandboxEl) {
                                        sandboxEl.style.display = (v === 'ups' || v === 'dhl') ? 'block' : 'none';
                                    }
                                }
                                if (sel) {
                                    sel.addEventListener('change', showProvider);
                                    showProvider();
                                }

                                var btn = document.getElementById('lt-test-btn');
                                var out = document.getElementById('lt-test-result');
                                if (!btn || !out) return;
                                btn.addEventListener('click', function () {
                                    out.textContent = '查询中...';
                                    var ajaxUrl = btn.getAttribute('data-ajax') || '';
                                    var nonce = btn.getAttribute('data-nonce') || '';
                                    var company = (document.getElementById('lt-test-company') || {}).value || '';
                                    var num = (document.getElementById('lt-test-no') || {}).value || '';
                                    var fd = new FormData();
                                    fd.append('action', 'todo_test_logistics_query');
                                    fd.append('_todononce', nonce);
                                    fd.append('company_code', company);
                                    fd.append('tracking_no', num);
                                    fetch(ajaxUrl, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                        body: fd
                                    }).then(function (r) { return r.json(); })
                                        .then(function (j) { out.textContent = JSON.stringify(j, null, 2); })
                                        .catch(function (e) { out.textContent = String(e && e.message ? e.message : e); });
                                });
                            })();
                        </script>
                    </div>
                </div>
            </div>
            <div class="admin-form-submit m-t-0">
                <button type="submit" class="button button-primary">保存设置</button>
            </div>
        </form>
    </div>

    <?php
    // 加载特色图片组件 JS（如果使用了 fallback 或需要统一加载）
    if (isset($GLOBALS['todo_featured_images_js_loaded']) && $GLOBALS['todo_featured_images_js_loaded']) {
        $js_url = todo_admin_url('assets/js/featured-images-component.js');
        $ver = defined('TODO_VERSION') ? TODO_VERSION : '1.0.0';
        echo '<script src="' . esc_url($js_url) . '?v=' . esc_attr($ver) . '" type="text/javascript"></script>';
        echo '<script type="text/javascript">
        jQuery(document).ready(function($) {
            // 初始化所有特色图片组件
            $(".featured-images-component").each(function() {
                var $container = $(this);
                var config = {
                    fieldId: $container.attr("id"),
                    fieldName: $container.data("field-name") || "featured_images",
                    multiple: $container.data("multiple") !== false,
                    maxImages: parseInt($container.data("max-images")) || 6,
                    enableDebug: $container.data("enable-debug") === true,
                    fileType: $container.data("file-type") || "any",
                    variant: $container.data("variant") || "featured"
                };
                if (typeof window.FeaturedImagesComponent !== "undefined") {
                    new window.FeaturedImagesComponent(config);
                } else {
                    console.error("FeaturedImagesComponent未定义，请检查JS文件是否加载");
                }
            });
        });
        </script>';
    }
    ?>

    <template id="express-modal-template">
        <div data-slot="title">选择快递公司</div>
        <div data-slot="body">
            <input type="text" id="expressSearch" class="regular-text" placeholder="搜索快递公司..." />
            <div id="expressList">
                <?php if (!empty($all_companies)): ?>
                    <?php foreach ($all_companies as $c): ?>
                        <div class="flex items-center justify-between m-b-10 m-t-10 modal-item">
                            <label class="flex items-center m-0">
                                <input type="checkbox" class="express-option" value="<?php echo (int) $c->id; ?>"
                                    data-name="<?php echo esc_attr($c->name); ?>" <?php echo in_array((int) $c->id, $selected_company_ids, true) ? 'checked' : ''; ?>>
                                <span class="m-r-05"><?php echo esc_html($c->name); ?></span>
                                <?php if (!empty($c->code)): ?>
                                    <small class="description m-0"><?php echo esc_html($c->code); ?></small>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-items">
                        <p>暂无快递公司，请先在“快递公司管理”中添加。</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div data-slot="footer">
            <a href="<?php echo todo_admin_url('index.php?page=todo-express'); ?>"
                class="button button-secondary" style="margin-right: auto;" title="在快递公司管理中维护">管理快递公司</a>
            <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
            <button type="button" class="button button-primary" id="btn-apply-express">保存选择</button>
        </div>
    </template>

    <template id="payment-modal-template">
        <div data-slot="title">配置支付列表</div>
        <div data-slot="body">
            <?php foreach ($payment_labels as $code => $label): ?>
                <?php $enabled = !empty($enabled_lookup[$code]); ?>
                <label class="flex items-center<?php echo $enabled ? '' : ' is-disabled'; ?>" style="display: block;margin: 5px 0;">
                    <input type="checkbox" class="payment-option" value="<?php echo esc_attr($code); ?>"
                        data-label="<?php echo esc_attr($label); ?>" <?php echo in_array($code, $settings['payment_methods'] ?? [], true) ? 'checked' : ''; ?>>
                    <span><?php echo esc_html($label); ?><em style="font-size: 12px;color: #999;"><?php echo $enabled ? '' : '（未启用）'; ?></em></span>
                    <?php if ($code === 'wechat' || $code === 'alipay' || $code === 'unionpay'): ?>
                        <?php
                        $providers = isset($payment_provider_options[$code]) && is_array($payment_provider_options[$code]) ? $payment_provider_options[$code] : array();
                        $current_providers = isset($settings['payment_providers']) && is_array($settings['payment_providers']) ? $settings['payment_providers'] : array();
                        $default_p = ($code === 'unionpay') ? 'unionpay_direct' : 'payjs';
                        $current = isset($current_providers[$code]) ? sanitize_text_field($current_providers[$code]) : $default_p;
                        ?>
                        <select class="payment-provider-select" data-provider-for="<?php echo esc_attr($code); ?>" style="margin-left: 5px;">
                            <?php foreach ($providers as $p): ?>
                                <?php
                                $p_code = isset($p['code']) ? sanitize_text_field($p['code']) : '';
                                $p_label = isset($p['label']) ? sanitize_text_field($p['label']) : $p_code;
                                $p_enabled = !empty($p['enabled']);
                                ?>
                                <option value="<?php echo esc_attr($p_code); ?>" <?php echo $p_code === $current ? 'selected' : ''; ?> <?php echo $p_enabled ? '' : 'disabled'; ?>>
                                    <?php echo esc_html($p_label); ?><?php echo $p_enabled ? '' : '（未配置）'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <p class="description">提示：“未启用”的方式仍可加入列表，但若未在支付方式管理中配置，将无法实际使用。</p>
        </div>
        <div data-slot="footer">
            <a href="<?php echo todo_admin_url('index.php?page=todo-payments'); ?>"
                class="button button-secondary" style="margin-right: auto;">管理支付方式</a>
            <button type="button" class="button button-secondary todo-modal-cancel">取消</button>
            <button type="button" class="button button-primary" id="btn-apply-payment">保存选择</button>
        </div>
    </template>

    <style>
        .mbody .sev-form {
            flex-wrap: wrap;
        }
        .mbody .sev-left {
            width: 100%;
        }
        .mbody .sev-left .admin-card:last-child {
            margin-bottom: 0;
        }

        .modal-tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .tab-button {
            padding: 10px 20px;
            border: none;
            background: #f1f1f1;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .tab-button.active {
            background: #fff;
            border-bottom-color: #0073aa;
            color: #0073aa;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .manage-header {
            margin-bottom: 20px;
            text-align: right;
        }

        .payment-manage-list table {
            width: 100%;
        }

        .featured-images-preview {
            background: #f1f1f1;
            border-radius: 4px;
        }
    </style>

    <script type="text/javascript">
        // 轻量工具函数
        function createChip(text, hiddenName, hiddenValue, extraAttrs) {
            var chip = document.createElement('div');
            chip.className = 'chip';
            if (extraAttrs) {
                Object.keys(extraAttrs).forEach(function (k) { chip.setAttribute(k, extraAttrs[k]); });
            }
            var span = document.createElement('span');
            span.className = 'chip-text';
            span.textContent = text;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = hiddenName;
            input.value = hiddenValue;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chip-remove';
            btn.setAttribute('aria-label', '移除');
            btn.textContent = '×';
            btn.addEventListener('click', function () { chip.remove(); });
            chip.appendChild(span); chip.appendChild(input); chip.appendChild(btn);
            return chip;
        }

        function createHiddenInput(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value == null ? '' : String(value);
            return input;
        }

        let expressModalInstance = null;
        let paymentModalInstance = null;

        function getExpressModalRoot() {
            return expressModalInstance && expressModalInstance.element ? expressModalInstance.element : null;
        }
        function getPaymentModalRoot() {
            return paymentModalInstance && paymentModalInstance.element ? paymentModalInstance.element : null;
        }

        // 快递公司 Modal 逻辑
        function openExpressModal() {
            if (expressModalInstance && expressModalInstance.isOpen) return;
            expressModalInstance = new TodoModal({
                templateId: 'express-modal-template',
                destroyOnClose: true
            });
            expressModalInstance.onCloseCallback(function () {
                expressModalInstance = null;
            });
            var root = getExpressModalRoot();
            if (!root) return;
            var btnApply = root.querySelector('#btn-apply-express');
            if (btnApply) btnApply.addEventListener('click', applyExpressSelection);
            var expressSearch = root.querySelector('#expressSearch');
            if (expressSearch) expressSearch.addEventListener('input', filterExpressList);
            expressModalInstance.open();
        }
        function closeExpressModal() {
            if (expressModalInstance) expressModalInstance.close();
        }
        function filterExpressList() {
            var root = getExpressModalRoot();
            if (!root) return;
            var search = root.querySelector('#expressSearch');
            var q = ((search && search.value) || '').toLowerCase();
            root.querySelectorAll('#expressList .modal-item').forEach(function (item) {
                var t = item.textContent.toLowerCase();
                item.style.display = t.indexOf(q) !== -1 ? '' : 'none';
            });
        }
        function applyExpressSelection() {
            var container = document.getElementById('selected-express-companies');
            container.innerHTML = '';
            var root = getExpressModalRoot();
            if (!root) return;
            root.querySelectorAll('.express-option:checked').forEach(function (cb) {
                var id = cb.value; var name = cb.getAttribute('data-name');
                var chip = createChip(name, 'express_companies[]', id, { 'data-id': id });
                container.appendChild(chip);
            });
            closeExpressModal();
        }

        // 支付方式 Modal 逻辑
        function openPaymentModal() {
            if (paymentModalInstance && paymentModalInstance.isOpen) return;
            paymentModalInstance = new TodoModal({
                templateId: 'payment-modal-template',
                destroyOnClose: true
            });
            paymentModalInstance.onCloseCallback(function () {
                paymentModalInstance = null;
            });
            var root = getPaymentModalRoot();
            if (!root) return;
            var btnApply = root.querySelector('#btn-apply-payment');
            if (btnApply) btnApply.addEventListener('click', applyPaymentSelection);
            paymentModalInstance.open();
        }
        function closePaymentModal() {
            if (paymentModalInstance) paymentModalInstance.close();
        }

        function applyPaymentSelection() {
            var container = document.getElementById('selected-payment-methods');
            var providerContainer = document.getElementById('selected-payment-providers');
            var root = getPaymentModalRoot();
            if (!root) return;
            var checked = Array.prototype.slice.call(root.querySelectorAll('.payment-option:checked')).map(function (cb) { return { code: cb.value, label: cb.getAttribute('data-label') }; });
            // 去重并按当前勾选顺序输出
            container.innerHTML = '';
            var seen = {};
            checked.forEach(function (m) {
                if (seen[m.code]) return; seen[m.code] = true;
                var chip = createChip(m.label, 'payment_methods[]', m.code, { 'data-code': m.code });
                container.appendChild(chip);
            });
            if (providerContainer) {
                providerContainer.innerHTML = '';
                var wechatSel = root.querySelector('.payment-provider-select[data-provider-for="wechat"]');
                var alipaySel = root.querySelector('.payment-provider-select[data-provider-for="alipay"]');
                var unionpaySel = root.querySelector('.payment-provider-select[data-provider-for="unionpay"]');
                var wechatVal = wechatSel ? wechatSel.value : 'payjs';
                var alipayVal = alipaySel ? alipaySel.value : 'payjs';
                var unionpayVal = unionpaySel ? unionpaySel.value : 'unionpay_direct';
                providerContainer.appendChild(createHiddenInput('payment_providers[wechat]', wechatVal));
                providerContainer.appendChild(createHiddenInput('payment_providers[alipay]', alipayVal));
                providerContainer.appendChild(createHiddenInput('payment_providers[unionpay]', unionpayVal));
            }
            closePaymentModal();
        }

        // 支持 chip 内点击移除（服务端无需特殊处理）
        document.addEventListener('click', function (e) {
            if (e.target && e.target.classList.contains('chip-remove')) {
                e.preventDefault();
                var chip = e.target.closest('.chip');
                if (chip) chip.remove();
            }
        });
        // 初始化事件绑定 (立即执行以确保在 moveGlobalComponents 移动元素前绑定完成)
        (function() {
            // 绑定打开模态框按钮
            var btnOpenExpress = document.getElementById('btn-open-express');
            if (btnOpenExpress) btnOpenExpress.addEventListener('click', openExpressModal);

            var btnOpenPayment = document.getElementById('btn-open-payment');
            if (btnOpenPayment) btnOpenPayment.addEventListener('click', openPaymentModal);

            var btnAddShipping = document.getElementById('btn-add-shipping-method');
            var shippingList = document.getElementById('shipping-methods-list');
            if (btnAddShipping && shippingList) {
                btnAddShipping.addEventListener('click', function() {
                    var idx = Date.now();
                    var tr = document.createElement('tr');
                    tr.className = 'shipping-method-row';
                    tr.innerHTML = '<td><input type="text" name="shipping_methods[' + idx + '][code]" value="" class="regular-text" style="width: 100%;" placeholder="如 express" required></td>' +
                        '<td><input type="text" name="shipping_methods[' + idx + '][name]" value="" class="regular-text" style="width: 100%;" placeholder="如 快递配送" required></td>' +
                        '<td><input type="number" step="0.01" min="0" name="shipping_methods[' + idx + '][fee]" value="0.00" class="regular-text" style="width: 100%;" placeholder="10.00"></td>' +
                        '<td><input type="text" name="shipping_methods[' + idx + '][desc]" value="" class="regular-text" style="width: 100%;" placeholder="如 预计 2-3 天送达"></td>' +
                        '<td style="text-align: center;"><input type="checkbox" name="shipping_methods[' + idx + '][enabled]" value="1" checked></td>' +
                        '<td style="text-align: center;"><button type="button" class="button button-small btn-remove-shipping-row" style="color: #a00;">删除</button></td>';
                    shippingList.appendChild(tr);
                });

                shippingList.addEventListener('click', function(e) {
                    if (e.target && e.target.classList.contains('btn-remove-shipping-row')) {
                        e.preventDefault();
                        var row = e.target.closest('tr');
                        if (row) row.remove();
                    }
                });
            }
        })();

        // 确保父级页面也能访问这些函数（应对 moveGlobalComponents 移动元素的情况）
        if (window.parent && window.parent !== window) {
            var functionsToExport = [
                'openExpressModal', 'closeExpressModal', 'applyExpressSelection', 'filterExpressList',
                'openPaymentModal', 'closePaymentModal', 'applyPaymentSelection'
            ];
            functionsToExport.forEach(function (fnName) {
                // 如果父级尚未定义该函数，或者我们想覆盖它（此处建议仅在未定义时挂载）
                window.parent[fnName] = window.parent[fnName] || window[fnName];
            });
        }

    </script>

    <style>
        .chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 4px 4px 8px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }

        .chip-text {
            font-size: 12px;
            color: #111827;
        }

        .chip-text em {
            font-style: normal;
            color: #76767e;
        }

        .chip-remove {
            background: transparent;
            border: 0;
            color: #6b7280;
            cursor: pointer;
            line-height: 1;
            padding: 0 2px;
        }

        .chip-remove:hover {
            color: #ef4444;
        }

        .mbody .service-items-box {
            overflow-x: auto;
        }
    </style>
    <?php
}

/**
 * 获取服务设置
 */
function todo_get_service_settings($raw = false)
{
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
    if (function_exists('todo_shop_get_service_settings')) {
        return todo_shop_get_service_settings($raw);
    }
    return array();
}

/**
 * 保存服务设置
 */
function todo_save_service_settings()
{
    // 权限检查
    if (!todo_current_user_can('manage_options')) {
        todo_die('权限不足，无法保存设置');
    }

    // 验证请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        todo_die('无效的请求方法');
    }

    // 验证action参数
    if (!isset($_POST['action']) || $_POST['action'] !== 'save_service_settings') {
        todo_die('无效的操作参数');
    }

    // CSRF验证
    $csrf_token = $_POST['_todononce'] ?? '';
    if (!todo_verify_csrf_token($csrf_token, 'service_settings')) {
        todo_die('安全验证失败，请重新提交');
    }

    // 验证时间戳（防止重放攻击）
    if (!isset($_POST['timestamp']) || (time() - intval($_POST['timestamp'])) > 300) {
        todo_die('请求已过期，请重新提交');
    }

    // 记录操作日志（兼容对象/数组两种返回类型）
    $current_user = todo_get_current_user();
    $user_login = 'Unknown';
    if ($current_user) {
        if (is_object($current_user) && isset($current_user->user_login)) {
            $user_login = $current_user->user_login;
        } elseif (is_array($current_user) && isset($current_user['user_login'])) {
            $user_login = $current_user['user_login'];
        } elseif (is_object($current_user) && isset($current_user->display_name)) {
            $user_login = $current_user->display_name;
        } elseif (is_array($current_user) && isset($current_user['display_name'])) {
            $user_login = $current_user['display_name'];
        }
    }
    error_log(sprintf(
        '[TodoCMS] 用户 %s (ID: %d) 修改了服务设置',
        $user_login,
        todo_get_current_user_id()
    ));

    $previous = todo_get_service_settings();
    $sanitize_unified_notice = function ($html) {
        $html = is_string($html) ? $html : (is_scalar($html) ? (string) $html : '');
        if ($html === '') {
            return '';
        }
        if (function_exists('todo_kses_post')) {
            $html = todo_kses_post($html);
        } else {
            $html = strip_tags($html, '<p><br><strong><em><b><i><u><ul><ol><li><a><img><div><span>');
        }

        $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        $html = preg_replace_callback('/\s(href|src)\s*=\s*(["\'])(.*?)\2/i', function ($m) {
            $v = trim((string) $m[3]);
            if ($v !== '' && preg_match('/^\s*javascript:/i', $v)) {
                $v = '#';
            }
            return ' ' . $m[1] . '=' . $m[2] . $v . $m[2];
        }, $html);

        $html = preg_replace_callback('/\sstyle\s*=\s*(["\'])(.*?)\1/i', function ($m) {
            $v = (string) $m[2];
            $v = preg_replace('/expression\s*\(/i', '', $v);
            $v = preg_replace('/javascript\s*:/i', '', $v);
            return ' style=' . $m[1] . $v . $m[1];
        }, $html);

        return $html;
    };
    if (!function_exists('todo_shop_service_settings_defaults')) {
        if (class_exists('Todo_Function_Loader')) {
            Todo_Function_Loader::load_function('shop-service-settings');
        } else {
            $p = defined('ABSPATH') ? ABSPATH . 'core/functions/shop-service-settings.php' : '';
            if ($p && file_exists($p)) {
                require_once $p;
            }
        }
    }
    $defaults = function_exists('todo_shop_service_settings_defaults') ? todo_shop_service_settings_defaults() : array();
    $settings = is_array($defaults) ? $defaults : array();
    $settings['unified_notice'] = $sanitize_unified_notice($_POST['unified_notice'] ?? '');
    $settings['guest_checkout'] = isset($_POST['guest_checkout']) ? 1 : 0;
    $settings['review_approval'] = isset($_POST['review_approval']) ? 1 : 0;
    $settings['allow_refund'] = isset($_POST['allow_refund']) ? 1 : 0;
    $settings['return_window_days'] = isset($_POST['return_window_days']) ? max(0, min(365, intval($_POST['return_window_days']))) : 14;
    $settings['refund_max_attempts'] = isset($_POST['refund_max_attempts']) ? max(1, min(10, intval($_POST['refund_max_attempts']))) : 3;
    $settings['auto_confirm_days'] = isset($_POST['auto_confirm_days']) ? max(0, min(90, intval($_POST['auto_confirm_days']))) : 7;
    $settings['express_companies'] = array();
    $settings['payment_methods'] = array();

    // 处理产品服务项（保存为 code/label/images 数组，去重并过滤空值）
    // 支持空集合保存：若存在哨兵字段但未提交任何行，则保存为空数组
    if (isset($_POST['product_service_items']) && is_array($_POST['product_service_items'])) {
        $items = array();
        $seen = array();
        foreach ($_POST['product_service_items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $code = isset($it['code']) ? todo_sanitize_key($it['code']) : '';
            $label = isset($it['label']) ? sanitize_text_field($it['label']) : '';
            $images = array();
            if (isset($it['images'])) {
                $imgs = is_string($it['images']) ? json_decode($it['images'], true) : (is_array($it['images']) ? $it['images'] : array());
                if (is_array($imgs)) {
                    foreach ($imgs as $img) {
                        $raw_url = isset($img['url']) ? sanitize_text_field($img['url']) : '';
                        if ($raw_url !== '' && function_exists('todo_media_url_to_relative')) {
                            $raw_url = todo_media_url_to_relative($raw_url);
                        }
                        $images[] = array(
                            'id' => isset($img['id']) ? intval($img['id']) : 0,
                            'url' => $raw_url,
                            'title' => isset($img['title']) ? sanitize_text_field($img['title']) : ''
                        );
                    }
                }
            }
            if (!$code || !$label) {
                continue;
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $post_id = isset($it['post_id']) ? intval($it['post_id']) : 0;
            $items[] = array('code' => $code, 'label' => $label, 'images' => $images, 'post_id' => $post_id);
        }
        $settings['product_service_items'] = $items;
    } elseif (isset($_POST['product_service_items_present'])) {
        // 用户明确交互过服务项区域但未提交任何行 → 保存为空数组，避免回退默认项
        $settings['product_service_items'] = array();
    }

    // 处理快递公司（保存为 ID）
    if (!empty($_POST['express_companies']) && is_array($_POST['express_companies'])) {
        $ids = array_map('intval', $_POST['express_companies']);
        $ids = array_values(array_unique(array_filter($ids))); // 去重并去除无效值
        $settings['express_companies'] = $ids;
    } elseif (isset($_POST['express_companies_present'])) {
        $settings['express_companies'] = array();
    }

    // 处理配送方式
    if (isset($_POST['shipping_methods']) && is_array($_POST['shipping_methods'])) {
        $sm_list = array();
        $seen_codes = array();
        foreach ($_POST['shipping_methods'] as $sm) {
            if (!is_array($sm)) continue;
            $code = isset($sm['code']) ? todo_sanitize_key($sm['code']) : '';
            $name = isset($sm['name']) ? sanitize_text_field($sm['name']) : '';
            $fee  = isset($sm['fee']) ? sprintf('%.2f', max(0, floatval($sm['fee']))) : '0.00';
            $desc = isset($sm['desc']) ? sanitize_text_field($sm['desc']) : '';
            $enabled = !empty($sm['enabled']) ? 1 : 0;
            if (!$code || !$name) continue;
            if (isset($seen_codes[$code])) continue;
            $seen_codes[$code] = true;
            $sm_list[] = array(
                'code'    => $code,
                'name'    => $name,
                'fee'     => $fee,
                'desc'    => $desc,
                'enabled' => $enabled
            );
        }
        $settings['shipping_methods'] = $sm_list;
    } elseif (isset($_POST['shipping_methods_present'])) {
        $settings['shipping_methods'] = array();
    }


    // 处理支付方式（保存为 code）
    if (!empty($_POST['payment_methods']) && is_array($_POST['payment_methods'])) {
        $codes = array_map('sanitize_text_field', $_POST['payment_methods']);
        $allowed = array('wechat', 'alipay', 'unionpay', 'paypal', 'offline', 'cod', 'balance');
        $codes = array_values(array_unique(array_filter($codes, function ($c) use ($allowed) {
            return in_array($c, $allowed, true); })));
        $settings['payment_methods'] = $codes;
    }

    if (!empty($_POST['payment_providers']) && is_array($_POST['payment_providers'])) {
        $pp = $_POST['payment_providers'];
        $wechat_provider = isset($pp['wechat']) ? sanitize_text_field($pp['wechat']) : 'payjs';
        $alipay_provider = isset($pp['alipay']) ? sanitize_text_field($pp['alipay']) : 'payjs';
        $unionpay_provider = isset($pp['unionpay']) ? sanitize_text_field($pp['unionpay']) : 'unionpay_direct';

        $allowed_wechat = array('payjs', 'hupijiao', 'wechat_direct');
        $allowed_alipay = array('payjs', 'hupijiao', 'alipay_direct');
        $allowed_unionpay = array('unionpay_direct');
        if (!in_array($wechat_provider, $allowed_wechat, true)) {
            $wechat_provider = 'payjs';
        }
        if (!in_array($alipay_provider, $allowed_alipay, true)) {
            $alipay_provider = 'payjs';
        }
        if (!in_array($unionpay_provider, $allowed_unionpay, true)) {
            $unionpay_provider = 'unionpay_direct';
        }

        $settings['payment_providers']['wechat'] = $wechat_provider;
        $settings['payment_providers']['alipay'] = $alipay_provider;
        $settings['payment_providers']['unionpay'] = $unionpay_provider;
    }

    if (isset($_POST['logistics_tracking']) && is_array($_POST['logistics_tracking'])) {
        $lt = $_POST['logistics_tracking'];
        $provider = isset($lt['provider']) ? sanitize_text_field($lt['provider']) : 'kuaidi100';
        $allowed_providers = array('kuaidi100', 'kdniao', 'cainiao', 'ups', 'dhl');
        if (!in_array($provider, $allowed_providers, true)) {
            $provider = 'kuaidi100';
        }

        $cache_minutes = isset($lt['cache_minutes']) ? intval($lt['cache_minutes']) : 10;
        if ($cache_minutes < 0) {
            $cache_minutes = 0;
        }
        if ($cache_minutes > 1440) {
            $cache_minutes = 1440;
        }

        $poll_interval_minutes = isset($lt['poll_interval_minutes']) ? intval($lt['poll_interval_minutes']) : 60;
        if ($poll_interval_minutes < 5) {
            $poll_interval_minutes = 5;
        }
        if ($poll_interval_minutes > 1440) {
            $poll_interval_minutes = 1440;
        }

        $settings['logistics_tracking']['enabled'] = isset($lt['enabled']) ? 1 : 0;
        $settings['logistics_tracking']['sandbox'] = isset($lt['sandbox']) ? 1 : 0;
        $settings['logistics_tracking']['provider'] = $provider;
        $settings['logistics_tracking']['cache_minutes'] = $cache_minutes;
        $settings['logistics_tracking']['auto_poll'] = isset($lt['auto_poll']) ? 1 : 0;
        $settings['logistics_tracking']['poll_interval_minutes'] = $poll_interval_minutes;

        $k100 = isset($lt['kuaidi100']) && is_array($lt['kuaidi100']) ? $lt['kuaidi100'] : array();
        $settings['logistics_tracking']['kuaidi100']['customer'] = sanitize_text_field($k100['customer'] ?? '');
        $settings['logistics_tracking']['kuaidi100']['key'] = sanitize_text_field($k100['key'] ?? '');
        $settings['logistics_tracking']['kuaidi100']['endpoint'] = esc_url_raw($k100['endpoint'] ?? $settings['logistics_tracking']['kuaidi100']['endpoint']);

        $kdn = isset($lt['kdniao']) && is_array($lt['kdniao']) ? $lt['kdniao'] : array();
        $settings['logistics_tracking']['kdniao']['ebusiness_id'] = sanitize_text_field($kdn['ebusiness_id'] ?? '');
        $settings['logistics_tracking']['kdniao']['app_key'] = sanitize_text_field($kdn['app_key'] ?? '');
        $settings['logistics_tracking']['kdniao']['endpoint'] = esc_url_raw($kdn['endpoint'] ?? $settings['logistics_tracking']['kdniao']['endpoint']);
        $req_type = isset($kdn['request_type']) ? preg_replace('/[^0-9]/', '', (string) $kdn['request_type']) : '1002';
        if ($req_type === '') {
            $req_type = '1002';
        }
        $settings['logistics_tracking']['kdniao']['request_type'] = $req_type;

        $cai = isset($lt['cainiao']) && is_array($lt['cainiao']) ? $lt['cainiao'] : array();
        $settings['logistics_tracking']['cainiao']['app_key'] = sanitize_text_field($cai['app_key'] ?? '');
        $settings['logistics_tracking']['cainiao']['app_secret'] = sanitize_text_field($cai['app_secret'] ?? '');
        $settings['logistics_tracking']['cainiao']['session'] = sanitize_text_field($cai['session'] ?? '');
        $settings['logistics_tracking']['cainiao']['endpoint'] = esc_url_raw($cai['endpoint'] ?? $settings['logistics_tracking']['cainiao']['endpoint']);
        $settings['logistics_tracking']['cainiao']['method'] = sanitize_text_field($cai['method'] ?? $settings['logistics_tracking']['cainiao']['method']);
        $sign_method = isset($cai['sign_method']) ? strtolower(sanitize_text_field($cai['sign_method'])) : 'md5';
        if ($sign_method !== 'md5' && $sign_method !== 'hmac') {
            $sign_method = 'md5';
        }
        $settings['logistics_tracking']['cainiao']['sign_method'] = $sign_method;

        $param_company_key = isset($cai['param_company_key']) ? sanitize_text_field($cai['param_company_key']) : 'company_code';
        $param_tracking_key = isset($cai['param_tracking_key']) ? sanitize_text_field($cai['param_tracking_key']) : 'out_sid';
        $param_company_key = preg_replace('/[^a-z0-9_]/i', '', $param_company_key);
        $param_tracking_key = preg_replace('/[^a-z0-9_]/i', '', $param_tracking_key);
        if ($param_company_key === '') {
            $param_company_key = 'company_code';
        }
        if ($param_tracking_key === '') {
            $param_tracking_key = 'out_sid';
        }
        $settings['logistics_tracking']['cainiao']['param_company_key'] = $param_company_key;
        $settings['logistics_tracking']['cainiao']['param_tracking_key'] = $param_tracking_key;

        $ups_conf = isset($lt['ups']) && is_array($lt['ups']) ? $lt['ups'] : array();
        $settings['logistics_tracking']['ups']['client_id'] = sanitize_text_field($ups_conf['client_id'] ?? '');
        $settings['logistics_tracking']['ups']['client_secret'] = sanitize_text_field($ups_conf['client_secret'] ?? '');
        $settings['logistics_tracking']['ups']['access_token'] = sanitize_text_field($ups_conf['access_token'] ?? '');
        $settings['logistics_tracking']['ups']['endpoint'] = esc_url_raw($ups_conf['endpoint'] ?? 'https://onlinetools.ups.com/api/shipments/v1/transports/details/');

        $dhl_conf = isset($lt['dhl']) && is_array($lt['dhl']) ? $lt['dhl'] : array();
        $settings['logistics_tracking']['dhl']['api_key'] = sanitize_text_field($dhl_conf['api_key'] ?? '');
        $settings['logistics_tracking']['dhl']['api_secret'] = sanitize_text_field($dhl_conf['api_secret'] ?? '');
        $settings['logistics_tracking']['dhl']['endpoint'] = esc_url_raw($dhl_conf['endpoint'] ?? 'https://express.api.dhl.com/mydhlapi/shipments/');
    } else {
        $settings['logistics_tracking']['enabled'] = 0;
    }

    if (!class_exists('Todo_Cache_Manager')) {
        $cache_manager = defined('ABSPATH') ? ABSPATH . 'core/classes/class-cache-manager.php' : '';
        if ($cache_manager && file_exists($cache_manager)) {
            require_once $cache_manager;
        }
    }

    // 使用统一的选项存储（CMS_Option）确保读取与写入一致
    $saved = (bool) todo_update_option('todo_service_settings', $settings);
    todo_update_option('guest_checkout', (int) ($settings['guest_checkout'] ?? 0));

    if (class_exists('Todo_Cache_Manager')) {
        Todo_Cache_Manager::delete('autoload_options', 'options', 'global');
        Todo_Cache_Manager::flush_group('options');
    }

    // PRG: 保存后重定向防止重复提交
    $redirect_url = $saved
        ? todo_admin_url('index.php?page=todo-service-settings&updated=1')
        : todo_admin_url('index.php?page=todo-service-settings&error=save_failed');
    if (!headers_sent()) {
        header('Location: ' . $redirect_url);
        exit;
    } else {
        echo '<script type="text/javascript">location.href=' . json_encode($redirect_url) . ';</script>';
        exit;
    }
}

/**
 * 本页使用：获取快递公司（仅启用）
 */
function todo_service_settings_get_express_companies($only_enabled = true)
{
    global $tododb;
    $table = $tododb->prefix . 'shipping_companies';
    $sql = "SELECT id, name, code, status FROM {$table}";
    if ($only_enabled) {
        $sql .= " WHERE status = 1";
    }
    $sql .= " ORDER BY name ASC";
    return $tododb->get_results($sql);
}

// 渲染服务设置页面（保证包含本页时输出内容）
todo_service_settings_page();

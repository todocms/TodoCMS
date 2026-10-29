<?php
/**
 * 用户中心模板函数
 *
 * 提供用户中心模板渲染的便捷函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 渲染用户中心页面
 *
 * @param string $page_name 页面名称
 * @param array $data 页面数据
 * @return string 渲染后的HTML
 */
function todo_render_user_center_page($page_name, $data = []) {
    // 准备数据
    $data = todo_prepare_user_center_data($data);
    
    // 注册标签
    todo_register_user_center_tags();
    
    $theme_dir = function_exists('get_template_directory') ? get_template_directory() : ABSPATH . 'content/themes/' . (function_exists('get_theme_name') ? get_theme_name() : 'default');
    $template_path = $theme_dir . '/users/' . $page_name . '.htm';
    
    if (!file_exists($template_path)) {
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            return '<div class="error">模板文件不存在: ' . $template_path . '</div>';
        }
        return '<div class="error">模板文件不存在</div>';
    }
    
    return CMS_Template_Compiler_V2::render_template($template_path, $data);
}

/**
 * 渲染用户中心组件
 *
 * @param string $component_name 组件名称
 * @param array $data 组件数据
 * @return string 渲染后的HTML
 */
function todo_render_user_center_component($component_name, $data = []) {
    // 准备数据
    $data = todo_prepare_user_center_data($data);
    
    // 注册标签
    todo_register_user_center_tags();

    $theme_dir = function_exists('get_template_directory') ? get_template_directory() : ABSPATH . 'content/themes/' . (function_exists('get_theme_name') ? get_theme_name() : 'default');
    $template_path = $theme_dir . '/users/' . $component_name . '.htm';
    
    if (!file_exists($template_path)) {
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            return '<div class="error">组件模板不存在: ' . $component_name . '.htm</div>';
        }
        return '<div class="error">组件模板不存在</div>';
    }
    
    return CMS_Template_Compiler_V2::render_template($template_path, $data);
}

/**
 * 解析用户中心模板内容
 *
 * @param string $template_content 模板内容
 * @param array $data 模板数据
 * @return string 解析后的内容
 */
function todo_parse_user_center_content($template_content, $data = []) {
    // 准备数据
    $data = todo_prepare_user_center_data($data);
    
    // 注册标签
    todo_register_user_center_tags();
    
    return CMS_Template_Compiler_V2::render_string($template_content, $data);
}

/**
 * 准备用户中心数据
 *
 * @param array $data 原始数据
 * @return array 增强后的数据
 */
function todo_prepare_user_center_data($data = []) {
    // 获取当前用户信息
    if (function_exists('todo_get_current_user')) {
        $current_user = todo_get_current_user();
        if ($current_user) {
            if (is_object($current_user)) {
                $current_user = get_object_vars($current_user);
            }
            if (is_array($current_user)) {
                $uid = 0;
                if (isset($current_user['ID'])) {
                    $uid = (int) $current_user['ID'];
                } elseif (isset($current_user['id'])) {
                    $uid = (int) $current_user['id'];
                }
                if ($uid > 0 && function_exists('get_user_avatar_url')) {
                    $resolved_avatar = get_user_avatar_url($uid, 80);
                    if (function_exists('todo_media_url_to_runtime_absolute')) {
                        $resolved_avatar = todo_media_url_to_runtime_absolute($resolved_avatar);
                    }
                    $current_user['avatar'] = $resolved_avatar;
                }
                if ($uid > 0 && (!isset($current_user['level_name']) || $current_user['level_name'] === '' || $current_user['level_name'] === null)) {
                    $current_user['level_name'] = '注册会员';
                    global $tododb;
                    if ($tododb && isset($tododb->prefix)) {
                        try {
                            $level_row = null;
                            $user_level_id = isset($current_user['user_level']) ? (int) $current_user['user_level'] : 0;
                            if ($user_level_id > 0) {
                                $level_row = $tododb->get_row(
                                    "SELECT level_name, level_slug FROM {$tododb->prefix}user_levels WHERE id = ? LIMIT 1",
                                    [$user_level_id]
                                );
                            }
                            if (!$level_row) {
                                $level_row = $tododb->get_row(
                                    "SELECT ul.level_name, ul.level_slug FROM {$tododb->prefix}user_level_records ulr LEFT JOIN {$tododb->prefix}user_levels ul ON ulr.level_id = ul.id WHERE ulr.user_id = ? LIMIT 1",
                                    [$uid]
                                );
                            }
                            if ($level_row) {
                                $level_arr = is_object($level_row) ? get_object_vars($level_row) : (is_array($level_row) ? $level_row : []);
                                if (isset($level_arr['level_name']) && $level_arr['level_name'] !== '') {
                                    $current_user['level_name'] = (string) $level_arr['level_name'];
                                }
                                if (isset($level_arr['level_slug']) && $level_arr['level_slug'] !== '') {
                                    $current_user['level_slug'] = (string) $level_arr['level_slug'];
                                }
                            }
                        } catch (Throwable $e) {
                        }
                    }
                }
                // 格式化注册时间与最后登录时间
                $registered_raw = $current_user['user_registered'] ?? ($current_user['created_at'] ?? '');
                if ($registered_raw) {
                    $current_user['user_registered_format'] = date('Y-m-d H:i:s', strtotime($registered_raw));
                } else {
                    $current_user['user_registered_format'] = '';
                }

                $meta_last_login = ($uid > 0 && function_exists('get_user_meta')) ? (get_user_meta($uid, 'last_login', true) ?: get_user_meta($uid, '_last_login', true)) : '';
                if (empty($meta_last_login) && $uid > 0 && class_exists('Todo_User_Manager')) {
                    $u_info = Todo_User_Manager::get_user($uid);
                    if ($u_info && !empty($u_info['last_login_at'])) {
                        $meta_last_login = $u_info['last_login_at'];
                    }
                }
                $last_login_raw = $meta_last_login ?: ($current_user['last_login_at'] ?? ($current_user['last_login_time'] ?? ($current_user['last_login'] ?? '')));
                if ($last_login_raw) {
                    $current_user['last_login'] = $last_login_raw;
                    $current_user['last_login_format'] = date('Y-m-d H:i:s', is_numeric($last_login_raw) ? (int)$last_login_raw : strtotime($last_login_raw));
                } else {
                    $current_user['last_login'] = '';
                    $current_user['last_login_format'] = '';
                }

                // 补充加载用户元数据 (phone, gender, birthday 等)，兼容后台 description
                if ($uid > 0 && function_exists('get_user_meta')) {
                    $user_metas = get_user_meta($uid);
                    if (is_array($user_metas)) {
                        foreach ($user_metas as $mk => $mv) {
                            if (!isset($current_user[$mk])) {
                                $current_user[$mk] = $mv;
                            }
                        }
                    }
                }
                if (!empty($current_user['birthday'])) {
                    $b_ts = strtotime($current_user['birthday']);
                    if ($b_ts !== false) {
                        $current_lang = function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh_CN';
                        if (strpos($current_lang, 'en') === 0 || $current_lang === 'en_US') {
                            $current_user['birthday_format'] = date('F j, Y', $b_ts);
                        } elseif (strpos($current_lang, 'zh') === 0 || $current_lang === 'zh_CN') {
                            $current_user['birthday_format'] = date('Y年m月d日', $b_ts);
                        } else {
                            $current_user['birthday_format'] = date('Y-m-d', $b_ts);
                        }
                    } else {
                        $current_user['birthday_format'] = $current_user['birthday'];
                    }
                } else {
                    $current_user['birthday_format'] = '';
                }
                // 动态计算用户最新的实际积分、账户余额及交易记录总条数
                if ($uid > 0) {
                    global $tododb;
                    // 绑定用户最新动态可用积分（扣除待支付订单占用的冻结积分）
                    $raw_pts = (float) get_user_meta($uid, '_user_points', true);
                    $orders_tbl = DB_PREFIX . 'orders';
                    $ocf_tbl = DB_PREFIX . 'order_custom_fields';
                    $sql_locked_pts = $tododb->prepare(
                        "SELECT SUM(CAST(ocf1.field_value AS UNSIGNED)) FROM {$ocf_tbl} ocf1
                         INNER JOIN {$orders_tbl} o ON ocf1.order_id = o.id
                         LEFT JOIN {$ocf_tbl} ocf2 ON ocf2.order_id = o.id AND ocf2.field_name = '_points_deducted'
                         WHERE o.user_id = %d
                           AND o.status IN ('pending', 'processing')
                           AND ocf1.field_name = '_points_used'
                           AND (ocf2.field_value IS NULL OR ocf2.field_value != '1')",
                        $uid
                    );
                    $locked_pts = (int) $tododb->get_var($sql_locked_pts);
                    $pts = max(0, $raw_pts - $locked_pts);

                    $current_user['points'] = $pts;
                    $current_user['user_points'] = $pts;
                    $current_user['_user_points'] = $pts;
                    $current_user['raw_points'] = $raw_pts;
                    $current_user['locked_points'] = $locked_pts;

                    if (function_exists('todo_get_user_balance')) {
                        $dynamic_balance = todo_get_user_balance($uid);
                        $current_user['balance'] = $dynamic_balance;
                        $current_user['wallet_balance'] = $dynamic_balance;
                        $current_user['user_balance'] = $dynamic_balance;
                        $current_user['balance_format'] = function_exists('todo_format_price') ? todo_format_price(floatval($dynamic_balance), 'number') : number_format(floatval($dynamic_balance), 2, '.', '');
                    }
                    if ($tododb && isset($tododb->prefix)) {
                        $table_mb = $tododb->prefix . 'member_balance';
                        $table_bl = $tododb->prefix . 'balance_logs';
                        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';

                        // 映射前端 Tab 分类到数据库中实际存储的类型集合
                        $type_category_map = [
                            'recharge' => ['recharge', 'admin_add', 'admin'],
                            'consume'  => ['consume', 'order_pay', 'admin_deduct'],
                            'refund'   => ['refund', 'order_refund'],
                        ];

                        $cnt = 0;
                        $where_mb = ["user_id = %d"];
                        $params_mb = [$uid];
                        if ($type_filter !== '' && $type_filter !== 'all') {
                            if (isset($type_category_map[$type_filter])) {
                                $matched_types = $type_category_map[$type_filter];
                                $placeholders = implode(',', array_fill(0, count($matched_types), '%s'));
                                $where_mb[] = "type IN ({$placeholders})";
                                foreach ($matched_types as $mt) {
                                    $params_mb[] = $mt;
                                }
                            } else {
                                $where_mb[] = "type = %s";
                                $params_mb[] = $type_filter;
                            }
                        }
                        try {
                            $cnt_mb = (int) $tododb->get_var($tododb->prepare("SELECT COUNT(*) FROM {$table_mb} WHERE " . implode(' AND ', $where_mb), ...$params_mb));
                            $cnt += $cnt_mb;
                        } catch (\Throwable $e) {}

                        try {
                            $cnt_bl = (int) $tododb->get_var($tododb->prepare("SELECT COUNT(*) FROM {$table_bl} WHERE " . implode(' AND ', $where_mb), ...$params_mb));
                            $cnt += $cnt_bl;
                        } catch (\Throwable $e) {}

                        $data['transactions_count'] = $cnt;
                    }
                }

                // 消费 Session 中的 Flash 成功与错误提示
                if (isset($_SESSION['todo_user_center_flash_message']) && !empty($_SESSION['todo_user_center_flash_message'])) {
                    $data['message'] = $_SESSION['todo_user_center_flash_message'];
                    unset($_SESSION['todo_user_center_flash_message']);
                }
                if (isset($_SESSION['todo_user_center_flash_error']) && !empty($_SESSION['todo_user_center_flash_error'])) {
                    $data['error'] = $_SESSION['todo_user_center_flash_error'];
                    unset($_SESSION['todo_user_center_flash_error']);
                }

                // 处理个人资料提交更新逻辑
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
                    $token = $_POST['_todononce'] ?? ($_POST['_csrf_token'] ?? '');
                    if (function_exists('todo_verify_csrf_token') && !todo_verify_csrf_token($token, 'update_profile')) {
                        $data['error'] = __('安全验证失败，请刷新页面重试');
                    } else {
                        $update_data = [
                            'ID' => $uid,
                        ];
                        if (isset($_POST['display_name']) && trim($_POST['display_name']) !== '') {
                            $update_data['display_name'] = sanitize_text_field(trim($_POST['display_name']));
                        }
                        if (isset($_POST['user_email']) && trim($_POST['user_email']) !== '') {
                            $update_data['user_email'] = sanitize_email(trim($_POST['user_email']));
                        }

                        $res = todo_update_user($update_data);
                        if (is_todo_error($res)) {
                            $data['error'] = $res->get_error_message();
                        } else {
                            // 保存手机号、性别、生日、个人简介等 Meta 字段，统一将简介保存到 description
                            $meta_fields = ['phone', 'gender', 'birthday', 'description'];
                            foreach ($meta_fields as $mf) {
                                if (isset($_POST[$mf])) {
                                    $val = sanitize_text_field($_POST[$mf]);
                                    if ($mf === 'description') {
                                        update_user_meta($uid, 'description', $val);
                                        $current_user['description'] = $val;
                                    } elseif ($mf === 'phone') {
                                        update_user_meta($uid, 'phone', $val);
                                        $current_user['phone'] = $val;
                                    } else {
                                        update_user_meta($uid, $mf, $val);
                                        $current_user[$mf] = $val;
                                    }
                                }
                            }

                            // 头像上传处理 (统一由 todo_process_frontend_image_upload 进行安全检测与存储)
                            if (
                                isset($_FILES['avatar']) && 
                                !empty($_FILES['avatar']['name']) && 
                                $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE
                            ) {
                                if (!function_exists('todo_process_frontend_image_upload')) {
                                    require_once ABSPATH . 'core/functions/media.php';
                                }
                                $upload_res = todo_process_frontend_image_upload($_FILES['avatar'], 'avatar', $uid, __('用户个人头像'));
                                if (!empty($upload_res['success'])) {
                                    $avatar_rel = $upload_res['url'];
                                    update_user_meta($uid, 'avatar', $avatar_rel);
                                    $current_user['avatar'] = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($avatar_rel) : $avatar_rel;
                                } else {
                                    $_SESSION['todo_user_center_flash_error'] = $upload_res['error'] ?? __('头像上传失败');
                                    $profile_redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('profile') : todo_get_site_url('/?user=profile');
                                    header('Location: ' . $profile_redirect_url);
                                    exit;
                                }
                            }

                            // 更新当前数组中已修改的字段
                            if (isset($update_data['display_name'])) {
                                $current_user['display_name'] = $update_data['display_name'];
                            }
                            if (isset($update_data['user_email'])) {
                                $current_user['user_email'] = $update_data['user_email'];
                            }

                            $_SESSION['todo_user_center_flash_message'] = __('个人资料修改成功');
                            $profile_redirect_url = function_exists('todo_get_user_page_url') ? todo_get_user_page_url('profile') : todo_get_site_url('/?user=profile');
                            header('Location: ' . $profile_redirect_url);
                            exit;
                        }
                    }
                }

                // 处理优惠券兑换逻辑
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'exchange_coupon') {
                    $token = $_POST['_todononce'] ?? ($_POST['_csrf_token'] ?? '');
                    if (function_exists('todo_verify_csrf_token') && !todo_verify_csrf_token($token, 'exchange_coupon') && !todo_verify_csrf_token($token, 'default')) {
                        $data['error'] = __('安全验证失败，请刷新页面重试');
                    } else {
                        $code = sanitize_text_field(trim($_POST['coupon_code'] ?? ''));
                        if ($code === '') {
                            $data['error'] = __('请输入优惠券兑换码');
                        } else {
                            $coupons_table = $tododb->prefix . 'coupons';
                            $user_coupons_table = $tododb->prefix . 'user_coupons';

                            $sql_coupon = $tododb->prepare("SELECT * FROM {$coupons_table} WHERE code = %s LIMIT 1", $code);
                            $coupon = $tododb->get_row($sql_coupon, [], 'ARRAY_A');

                            $c_status = is_array($coupon) ? ($coupon['status'] ?? 0) : (is_object($coupon) ? ($coupon->status ?? 0) : 0);
                            $c_issue_mode = is_array($coupon) ? ($coupon['issue_mode'] ?? 'redeem') : (is_object($coupon) ? ($coupon->issue_mode ?? 'redeem') : 'redeem');
                            $c_start_date = is_array($coupon) ? ($coupon['start_date'] ?? null) : (is_object($coupon) ? ($coupon->start_date ?? null) : null);
                            $c_end_date = is_array($coupon) ? ($coupon['end_date'] ?? null) : (is_object($coupon) ? ($coupon->end_date ?? null) : null);
                            $c_usage_limit = is_array($coupon) ? intval($coupon['usage_limit'] ?? 0) : (is_object($coupon) ? intval($coupon->usage_limit ?? 0) : 0);
                            $c_used_count = is_array($coupon) ? intval($coupon['used_count'] ?? 0) : (is_object($coupon) ? intval($coupon->used_count ?? 0) : 0);
                            $c_claim_limit = is_array($coupon) ? intval($coupon['claim_limit'] ?? 1) : (is_object($coupon) ? intval($coupon->claim_limit ?? 1) : 1);
                            $c_id = is_array($coupon) ? intval($coupon['id'] ?? 0) : (is_object($coupon) ? intval($coupon->id ?? 0) : 0);

                            if (!$coupon) {
                                $data['error'] = __('优惠券兑换码无效或不存在');
                            } elseif (empty($c_status)) {
                                $data['error'] = __('该优惠券已被禁用');
                            } elseif ((string) $c_issue_mode !== 'redeem') {
                                $data['error'] = __('该优惠券不支持手动兑换');
                            } else {
                                $now_ts = current_time('timestamp');
                                $start_ts = !empty($c_start_date) ? strtotime($c_start_date) : 0;
                                $end_ts = !empty($c_end_date) ? strtotime($c_end_date) : 0;
                                
                                if ($start_ts > 0 && $now_ts < $start_ts) {
                                    $data['error'] = __('优惠券兑换活动尚未开始');
                                } elseif ($end_ts > 0 && $now_ts > $end_ts) {
                                    $data['error'] = __('优惠券已被领完或已过期');
                                } else {
                                    if ($c_usage_limit > 0 && $c_used_count >= $c_usage_limit) {
                                        $data['error'] = __('该优惠券已达到发放上限');
                                    } else {
                                        if ($c_claim_limit <= 0) $c_claim_limit = 1;
                                        $user_total = (int) $tododb->get_var($tododb->prepare(
                                            "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d",
                                            $c_id, $uid
                                        ));
                                        if ($user_total >= $c_claim_limit) {
                                            $data['error'] = __('您已达到该优惠券的兑换上限');
                                        } else {
                                            $tododb->query('START TRANSACTION');
                                            $affected = $tododb->query($tododb->prepare(
                                                "UPDATE {$coupons_table} SET used_count = used_count + 1 WHERE id = %d AND status = 1 AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit)",
                                                $c_id
                                            ));
                                            if (!$affected) {
                                                $tododb->query('ROLLBACK');
                                                $data['error'] = __('优惠券已兑完');
                                            } else {
                                                $ok = $tododb->insert($user_coupons_table, [
                                                    'coupon_id' => $c_id,
                                                    'user_id' => $uid,
                                                    'status' => 'unused',
                                                    'source' => 'redeem',
                                                    'from_user_id' => null,
                                                    'assigned_at' => current_time('mysql'),
                                                ]);
                                                if (!$ok) {
                                                    $tododb->query('ROLLBACK');
                                                    $data['error'] = __('兑换失败');
                                                } else {
                                                    $tododb->query('COMMIT');
                                                    $data['message'] = __('优惠券兑换成功');
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // 处理优惠券转赠逻辑
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_coupon') {
                    $token = $_POST['_todononce'] ?? ($_POST['_csrf_token'] ?? '');
                    if (function_exists('todo_verify_csrf_token') && !todo_verify_csrf_token($token, 'transfer_coupon') && !todo_verify_csrf_token($token, 'default')) {
                        $data['error'] = __('安全验证失败，请刷新页面重试');
                    } else {
                        $user_coupon_id = intval($_POST['user_coupon_id'] ?? 0);
                        $to_user_login = sanitize_text_field(trim($_POST['to_user_login'] ?? ''));

                        if ($user_coupon_id <= 0 || $to_user_login === '') {
                            $data['error'] = __('请输入要转赠的目标接收账号');
                        } else {
                            $users_table = $tododb->prefix . 'users';
                            $sql_target = $tododb->prepare(
                                "SELECT ID FROM {$users_table} WHERE user_login = %s OR user_email = %s LIMIT 1",
                                $to_user_login, $to_user_login
                            );
                            $target_user = $tododb->get_row($sql_target, [], 'ARRAY_A');

                            $to_user_id = 0;
                            if (is_array($target_user) && isset($target_user['ID'])) {
                                $to_user_id = intval($target_user['ID']);
                            } elseif (is_object($target_user) && isset($target_user->ID)) {
                                $to_user_id = intval($target_user->ID);
                            }

                            if ($to_user_id <= 0) {
                                $data['error'] = __('目标接收账号不存在');
                            } elseif ($to_user_id === $uid) {
                                $data['error'] = __('不能将优惠券转赠给自己');
                            } else {
                                $user_coupons_table = $tododb->prefix . 'user_coupons';
                                $coupons_table = $tododb->prefix . 'coupons';

                                $sql_uc = $tododb->prepare(
                                    "SELECT * FROM {$user_coupons_table} WHERE id = %d AND user_id = %d LIMIT 1",
                                    $user_coupon_id, $uid
                                );
                                $uc = $tododb->get_row($sql_uc, [], 'ARRAY_A');
                                $uc_status = is_array($uc) ? ($uc['status'] ?? '') : (is_object($uc) ? ($uc->status ?? '') : '');
                                $coupon_id_from_uc = is_array($uc) ? ($uc['coupon_id'] ?? 0) : (is_object($uc) ? ($uc->coupon_id ?? 0) : 0);

                                if (!$uc) {
                                    $data['error'] = __('优惠券不存在或不属于当前账户');
                                } elseif ((string)$uc_status !== 'unused') {
                                    $data['error'] = __('该优惠券已被使用或已失效，无法转赠');
                                } else {
                                    $sql_c = $tododb->prepare(
                                        "SELECT * FROM {$coupons_table} WHERE id = %d LIMIT 1",
                                        intval($coupon_id_from_uc)
                                    );
                                    $coupon = $tododb->get_row($sql_c, [], 'ARRAY_A');
                                    $is_transferable = is_array($coupon) ? (!empty($coupon['is_transferable'])) : (is_object($coupon) ? (!empty($coupon->is_transferable)) : false);
                                    $claim_limit = is_array($coupon) ? (isset($coupon['claim_limit']) ? intval($coupon['claim_limit']) : 1) : (is_object($coupon) ? (isset($coupon->claim_limit) ? intval($coupon->claim_limit) : 1) : 1);
                                    $c_id = is_array($coupon) ? intval($coupon['id'] ?? 0) : (is_object($coupon) ? intval($coupon->id ?? 0) : 0);

                                    if (!$coupon) {
                                        $data['error'] = __('优惠券定义不存在');
                                    } elseif (!$is_transferable) {
                                        $data['error'] = __('该优惠券不可转赠');
                                    } else {
                                        if ($claim_limit <= 0) $claim_limit = 1;
                                        $recipient_total = intval($tododb->get_var($tododb->prepare(
                                            "SELECT COUNT(*) FROM {$user_coupons_table} WHERE coupon_id = %d AND user_id = %d",
                                            $c_id, $to_user_id
                                        )));

                                        if ($recipient_total >= $claim_limit) {
                                            $data['error'] = __('目标账户该优惠券拥有数量已达上限');
                                        } else {
                                            $tododb->query('START TRANSACTION');
                                            $ok = $tododb->update($user_coupons_table, [
                                                'user_id' => $to_user_id,
                                                'from_user_id' => $uid,
                                                'assigned_at' => current_time('mysql')
                                            ], ['id' => $user_coupon_id, 'user_id' => $uid]);

                                            if ($ok !== false) {
                                                $tododb->query('COMMIT');
                                                $data['message'] = sprintf(__('优惠券转赠成功，已转给账号 %s'), esc_html($to_user_login));
                                            } else {
                                                $tododb->query('ROLLBACK');
                                                $data['error'] = __('转赠失败');
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $data['current_user'] = $current_user;
                $data['user'] = $current_user;
                $data['user_id'] = $uid;
            }
        }
    }

    // 绑定积分系统相关配置（汇率与可用商品分类限制）
    if (function_exists('get_option')) {
        $data['points_ratio'] = (int) get_option('points_to_currency_ratio', 100);
        $data['points_usable_categories'] = (string) get_option('points_usable_categories', '');
    } else {
        $data['points_ratio'] = 100;
        $data['points_usable_categories'] = '';
    }
    
    // 添加用户中心基础URL
    if (function_exists('todo_get_user_center_url')) {
        $uc_url = todo_get_user_center_url();
    } else {
        $uc_url = '/?user=profile';
    }
    $data['user_center_url'] = $uc_url;
    $data['user_url'] = $uc_url;
    
    // 添加注册开启状态
    $data['users_can_register'] = function_exists('get_option') ? (bool) get_option('users_can_register', false) : false;
    
    // 添加主题URL
    if (function_exists('get_template_directory_uri')) {
        $data['theme_url'] = get_template_directory_uri();
    } else {
        $active_theme = function_exists('get_theme_name') ? get_theme_name() : 'default';
        $site = function_exists('todo_url') ? todo_url() : '';
        $data['theme_url'] = $site . '/content/themes/' . $active_theme;
    }
    
    // 添加当前页面信息及筛选类型
    $user_page_val = isset($_GET['user']) ? sanitize_text_field($_GET['user']) : (isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '');
    if ($user_page_val === '' && isset($_SERVER['REQUEST_URI'])) {
        $parsed_q = [];
        $query_str = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($query_str) {
            parse_str($query_str, $parsed_q);
            $user_page_val = isset($parsed_q['user']) ? sanitize_text_field($parsed_q['user']) : (isset($parsed_q['page']) ? sanitize_text_field($parsed_q['page']) : '');
        }
        if ($user_page_val === '') {
            $path_basename = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '.php');
            $user_page_val = basename($path_basename, '.htm');
        }
    }
    $data['current_page'] = $user_page_val;
    $data['current_url'] = $_SERVER['REQUEST_URI'];
    $data['current_type'] = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';
    
    // 添加CSRF令牌
    if (function_exists('todo_generate_csrf_token')) {
        $data['csrf_token'] = todo_generate_csrf_token();
    }
    
    // 添加购物车数据
    if (class_exists('Todo_Cart_Manager')) {
        $raw_cart = Todo_Cart_Manager::get_cart();
        $data['cart_count'] = intval($raw_cart['count'] ?? 0);
        $cart_details = Todo_Cart_Manager::get_cart_details(array('persist' => false));

        // 支持从购物车传参 selected 选中的项目列表（如 ?user=checkout&selected=key1,key2）
        $selected_param = trim($_GET['selected'] ?? $_POST['selected'] ?? '');
        if (!empty($selected_param) && !empty($cart_details['items'])) {
            $selected_keys = array_map('trim', explode(',', $selected_param));
            $filtered_items = [];
            foreach ($cart_details['items'] as $ck => $ci) {
                if (in_array((string)$ck, $selected_keys, true) || in_array((string)($ci['product_id'] ?? ''), $selected_keys, true)) {
                    $filtered_items[$ck] = $ci;
                }
            }
            if (!empty($filtered_items)) {
                $cart_details['items'] = $filtered_items;
                $tot_qty = 0;
                $tot_price = 0;
                foreach ($filtered_items as $fi) {
                    $q = intval($fi['quantity'] ?? 1);
                    $p = floatval($fi['price'] ?? 0);
                    $tot_qty += $q;
                    $tot_price += $q * $p;
                }
                $cart_details['count'] = $tot_qty;
                $cart_details['total'] = $tot_price;
            }
        }

        $cart_is_virtual = false;
        if (!empty($cart_details['items'])) {
            $cart_is_virtual = true;
            foreach ($cart_details['items'] as $item) {
                if (($item['product_type'] ?? 'physical') !== 'virtual') {
                    $cart_is_virtual = false;
                    break;
                }
            }
        }

        $data['cart_items'] = $cart_details['items'] ?? [];
        $data['cart_count'] = $cart_details['count'] ?? $data['cart_count'];
        $data['cart_total'] = number_format($cart_details['total'] ?? 0, 2);

        // 计算购物车各项费用（含原价与批发/会员优惠）
        $subtotal = 0;
        $original_subtotal = 0;
        if (isset($cart_details['items']) && is_array($cart_details['items'])) {
            foreach ($cart_details['items'] as $item) {
                $item_p = floatval($item['price'] ?? 0);
                $item_rp = floatval($item['regular_price'] ?? $item_p);
                if ($item_rp < $item_p) {
                    $item_rp = $item_p;
                }
                $qty = intval($item['quantity'] ?? 0);
                $subtotal += $item_p * $qty;
                $original_subtotal += $item_rp * $qty;
            }
        }
        $wholesale_discount = max(0, $original_subtotal - $subtotal);
        $shipping_fee = 0;
        $total = $subtotal + $shipping_fee;

        $exchanged_subtotal = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($subtotal) : $subtotal;
        $exchanged_orig_subtotal = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($original_subtotal) : $original_subtotal;
        $exchanged_wholesale_discount = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($wholesale_discount) : $wholesale_discount;
        $exchanged_shipping = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($shipping_fee) : $shipping_fee;
        $exchanged_total = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($total) : $total;

        $data['cart'] = [
            'items' => $cart_details['items'] ?? [],
            'subtotal' => $exchanged_subtotal,
            'subtotal_format' => function_exists('todo_format_price') ? todo_format_price($subtotal, 'number') : number_format($exchanged_subtotal, 2),
            'original_subtotal' => $exchanged_orig_subtotal,
            'original_subtotal_format' => function_exists('todo_format_price') ? todo_format_price($original_subtotal, 'number') : number_format($exchanged_orig_subtotal, 2),
            'wholesale_discount' => $exchanged_wholesale_discount,
            'wholesale_discount_format' => function_exists('todo_format_price') ? todo_format_price($wholesale_discount, 'number') : number_format($exchanged_wholesale_discount, 2),
            'has_wholesale_discount' => ($wholesale_discount > 0.001),
            'shipping_fee' => $exchanged_shipping,
            'shipping_fee_format' => function_exists('todo_format_price') ? todo_format_price($shipping_fee, 'number') : number_format($exchanged_shipping, 2),
            'total' => $exchanged_total,
            'total_format' => function_exists('todo_format_price') ? todo_format_price($total, 'number') : number_format($exchanged_total, 2),
            'count' => $cart_details['count'] ?? 0,
            'is_virtual' => $cart_is_virtual
        ];
        $data['cart_is_virtual'] = $cart_is_virtual;
    } else if (function_exists('todo_get_user_cart_items')) {
        $data['cart_items'] = todo_get_user_cart_items();
    }
    
    $is_logged_in = function_exists('is_user_logged_in') ? (bool) is_user_logged_in() : false;
    $raw_uid = isset($data['user_id']) ? (int) $data['user_id'] : 0;
    $effective_uid = $is_logged_in ? $raw_uid : 0;

    // 注入当前状态下的订单数（满足 orders.htm 的 user_order_count 变量检查，同时支持登录会员与游客模式）
    if (function_exists('todo_get_user_order_count')) {
        $status = $_GET['status'] ?? '';
        $data['user_order_count'] = intval(todo_get_user_order_count($effective_uid, $status));
    }

    // 注入收藏统计数量（满足 users_header.htm 和 favorites.htm 的收藏计数检查）
    $fav_count = 0;
    $fav_prod_count = 0;
    $fav_art_count = 0;
    if ($effective_uid > 0 && function_exists('todo_get_favorites_count')) {
        $fav_count = todo_get_favorites_count($effective_uid);
        $fav_prod_count = todo_get_favorites_count($effective_uid, 'product');
        $fav_art_count = todo_get_favorites_count($effective_uid, 'post');
    }
    $data['favorites_count'] = $fav_count;
    $data['user_favorites_count'] = $fav_count;
    $data['favorite_products_count'] = $fav_prod_count;
    $data['favorite_articles_count'] = $fav_art_count;
    $search_val = $_GET['search_order'] ?? $_GET['order_no'] ?? $_GET['guest_query'] ?? '';
    $data['search_order_val'] = sanitize_text_field($search_val);
    $data['status_param_val'] = sanitize_text_field($_GET['status'] ?? '');
    $data['is_guest_mode'] = !$is_logged_in;
    $data['status_is_all'] = $data['status_param_val'] === '';
    $data['status_is_pending'] = $data['status_param_val'] === 'pending';
    $data['status_is_processing'] = $data['status_param_val'] === 'processing';
    $data['status_is_shipped'] = $data['status_param_val'] === 'shipped';
    $data['status_is_completed'] = $data['status_param_val'] === 'completed';
    $data['status_is_cancelled'] = $data['status_param_val'] === 'cancelled';
    $data['status_is_refund'] = $data['status_param_val'] === 'refund';

    if ($data['search_order_val'] !== '') {
        $guest_ids = [];
        if (!$is_logged_in && function_exists('todo_get_guest_order_ids')) {
            $guest_ids = todo_get_guest_order_ids();
        }
        $debug = $GLOBALS['todo_order_search_debug'] ?? null;
        $orders_dump = [];
        $orders_dump_meta = [
            'final_ids' => [],
            'loaded_ids' => [],
            'rejected' => [],
            'skipped_missing' => [],
        ];
        if (is_array($debug) && !empty($debug['final_ids'])) {
            $orders_dump_meta['final_ids'] = (array) $debug['final_ids'];
            if (class_exists('Todo_Order_Manager')) {
                foreach ((array) $debug['final_ids'] as $oid) {
                    $oid = (int) $oid;
                    if ($oid <= 0) {
                        continue;
                    }
                    $o = Todo_Order_Manager::get_order($oid, true);
                    if (!$o) {
                        $orders_dump_meta['skipped_missing'][] = $oid;
                        continue;
                    }
                    if ($effective_uid > 0) {
                        if (!isset($o['user_id']) || (int) $o['user_id'] !== $effective_uid) {
                            $orders_dump_meta['rejected'][] = ['id' => $oid, 'reason' => 'user_mismatch', 'order_user_id' => isset($o['user_id']) ? (int) $o['user_id'] : null];
                            continue;
                        }
                    } else {
                        if (!in_array($oid, $guest_ids, true) && !in_array($oid, (array) ($debug['session_ids'] ?? []), true) && !in_array($oid, (array) ($debug['cookie_ids'] ?? []), true)) {
                            $orders_dump_meta['rejected'][] = ['id' => $oid, 'reason' => 'not_in_guest_ids'];
                            continue;
                        }
                    }
                    $orders_dump[] = $o;
                    $orders_dump_meta['loaded_ids'][] = $oid;
                    if (count($orders_dump) >= 3) {
                        break;
                    }
                }
            }
        }
        $data['order_search_debug_text'] = json_encode([
            'search' => $data['search_order_val'],
            'status' => $data['status_param_val'],
            'is_user_logged_in' => $is_logged_in,
            'raw_user_id' => $raw_uid,
            'effective_user_id_for_orders' => $effective_uid,
            'guest_ids' => $guest_ids,
            'guest_debug' => $debug
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $data['order_search_orders_dump_text'] = json_encode([
            'meta' => $orders_dump_meta,
            'orders' => $orders_dump
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    // 注入单笔订单详情数据（满足 order-detail.htm 页面需求）
    $target_order_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['order_id']) ? intval($_GET['order_id']) : 0);
    $target_recharge_id = isset($_GET['recharge_id']) ? intval($_GET['recharge_id']) : 0;
    $target_order_no = isset($_GET['order_no']) ? sanitize_text_field($_GET['order_no']) : '';
    $guest_target_order_ids = (!$is_logged_in && function_exists('todo_get_guest_order_ids'))
        ? array_map('intval', (array) todo_get_guest_order_ids())
        : array();
    $can_access_target_order = function($candidate_order) use ($effective_uid, $guest_target_order_ids) {
        if (!$candidate_order || !is_array($candidate_order)) {
            return false;
        }

        $candidate_order_id = intval($candidate_order['id'] ?? 0);
        $candidate_user_id = intval($candidate_order['user_id'] ?? 0);
        if ($effective_uid > 0) {
            return $candidate_user_id > 0 && $candidate_user_id === $effective_uid;
        }

        return $candidate_order_id > 0 && in_array($candidate_order_id, $guest_target_order_ids, true);
    };
    
    if ($target_order_id > 0 || $target_recharge_id > 0 || $target_order_no !== '') {
        $order = null;
        if ($target_recharge_id > 0 && $target_order_id <= 0) {
            if (isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                $db = $GLOBALS['tododb'];
                $rc_row = $db->get_row($db->prepare("SELECT * FROM {$db->prefix}recharge_records WHERE id = %d LIMIT 1", $target_recharge_id), [], 'ARRAY_A');
                if (is_object($rc_row)) $rc_row = (array) $rc_row;
                if ($rc_row && $effective_uid > 0 && intval($rc_row['user_id'] ?? 0) === $effective_uid) {
                    $amt = floatval($rc_row['amount'] ?? 0);
                    $created_ts = !empty($rc_row['created_at']) ? strtotime($rc_row['created_at']) : time();
                    $formatted_rc_no = 'RC' . date('YmdHis', $created_ts) . str_pad($rc_row['id'], 6, '0', STR_PAD_LEFT);
                    $order = [
                        'id' => intval($rc_row['id']),
                        'order_no' => $formatted_rc_no,
                        'created_at' => $rc_row['created_at'] ?? '',
                        'status' => $rc_row['status'] ?? 'pending',
                        'payment_status' => $rc_row['status'] ?? 'pending',
                        'payment_method' => $rc_row['payment_method'] ?? '',
                        'total_amount' => $amt,
                        'payment_amount' => $amt,
                        'subtotal_amount' => $amt,
                        'shipping_fee' => 0,
                        'discount_amount' => 0,
                        'is_recharge' => true,
                        'items' => [
                            [
                                'id' => 1,
                                'product_id' => 0,
                                'product_title' => __('账户充值') . ' (' . (function_exists('todo_format_price') ? todo_format_price($amt, 'number') : number_format($amt, 2)) . ')',
                                'product_name' => __('账户充值'),
                                'spec_name' => __('在线充值'),
                                'quantity' => 1,
                                'price' => $amt,
                                'unit_price' => $amt,
                                'total_price' => $amt,
                                'product_image' => ''
                            ]
                        ]
                    ];
                }
            }
        } elseif (class_exists('Todo_Order_Manager')) {
            Todo_Order_Manager::init();
            if ($target_order_id > 0) {
                $order = Todo_Order_Manager::get_order($target_order_id, true);
            } elseif ($target_order_no !== '') {
                $order = Todo_Order_Manager::get_order_by_no($target_order_no, true);
            }
        }
        
        if ($order && is_array($order)) {
            if (empty($order['is_recharge']) && !$can_access_target_order($order)) {
                $order = null;
            }
        }

        if ($order && is_array($order)) {
            $order['order_status'] = $order['status'] ?? 'pending';
            $order['order_status_text'] = function_exists('todo_get_order_status_text') ? todo_get_order_status_text($order['status'] ?? '') : ($order['status'] ?? '');
            $order['status_text'] = $order['order_status_text'];
            
            // 判定是否为虚拟订单（全虚拟商品或虚拟配送方式）
            $is_virt_order = false;
            if (class_exists('Todo_Order_Manager')) {
                $is_virt_order = Todo_Order_Manager::is_virtual_order($order);
            } else {
                $is_virt_order = ($order['shipping_method'] ?? '') === 'virtual';
            }
            $order['is_virtual'] = $is_virt_order;
            
            $payment_status = $order['payment_status'] ?? 'pending';
            $pay_status_map = ['pending' => '待付款', 'paid' => '已付款', 'refunded' => '已退款', 'failed' => '支付失败', 'cancelled' => '已取消'];
            $order['payment_status_text'] = $pay_status_map[$payment_status] ?? $payment_status;
            
            $pm = $order['payment_method'] ?? '';
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
            $order['payment_method_text'] = function_exists('todo_get_payment_method_label')
                ? (todo_get_payment_method_label($pm) ?: ($pm ?: '未选择'))
                : ($pm ?: '未选择');
            
            $order['created_at_format'] = !empty($order['created_at']) ? date('Y-m-d H:i:s', strtotime($order['created_at'])) : '';
            $order['paid_at_format'] = !empty($order['paid_at']) ? date('Y-m-d H:i:s', strtotime($order['paid_at'])) : '';
            $order['shipped_at_format'] = !empty($order['shipped_at']) ? date('Y-m-d H:i:s', strtotime($order['shipped_at'])) : (!empty($order['shipping_time']) ? date('Y-m-d H:i:s', strtotime($order['shipping_time'])) : '');

            $total_amt = floatval($order['total_amount'] ?? 0);
            $ship_fee = floatval($order['shipping_fee'] ?? 0);
            $disc_amt = floatval($order['discount_amount'] ?? 0);
            $payment_amt = floatval($order['payment_amount'] ?? 0);

            $pay_amt = ($payment_amt > 0) ? $payment_amt : $total_amt;

            $order['pay_amount'] = $pay_amt;
            $order['pay_amount_format'] = function_exists('todo_format_price') ? todo_format_price($pay_amt, 'number') : number_format($pay_amt, 2);
            $order['payment_amount'] = $payment_amt;
            $order['payment_amount_format'] = function_exists('todo_format_price') ? todo_format_price($payment_amt, 'number') : number_format($payment_amt, 2);
            $order['total_amount_format'] = function_exists('todo_format_price') ? todo_format_price($total_amt, 'number') : number_format($total_amt, 2);
            $order['shipping_fee_format'] = function_exists('todo_format_price') ? todo_format_price($ship_fee, 'number') : number_format($ship_fee, 2);
            $order['discount_amount_format'] = function_exists('todo_format_price') ? todo_format_price($disc_amt, 'number') : number_format($disc_amt, 2);

            // 拆分优惠券抵扣与积分抵扣（订单金额及 custom field 均以基准货币存储，展示时再按当前语言换算）
            $points_disc_base = 0.0;
            $points_used_cnt = 0;
            if (empty($order['is_recharge']) && class_exists('Todo_Order_Manager')) {
                $order_id_val = intval($order['id'] ?? 0);
                if ($order_id_val > 0) {
                    $points_disc_base = floatval(Todo_Order_Manager::get_order_custom_field($order_id_val, '_points_discount_amount') ?: 0);
                    $points_used_cnt = intval(Todo_Order_Manager::get_order_custom_field($order_id_val, '_points_used') ?: 0);
                }
            }
            $coupon_disc_base = max(0.0, $disc_amt - $points_disc_base);

            // 近零抵扣（换算成显示币后为 0.00）一律按 0 处理
            $coupon_show_val = function_exists('todo_apply_currency_exchange') ? floatval(todo_apply_currency_exchange($coupon_disc_base)) : $coupon_disc_base;
            if (round($coupon_show_val, 2) <= 0.0) {
                $coupon_disc_base = 0.0;
            }
            $points_show_val = function_exists('todo_apply_currency_exchange') ? floatval(todo_apply_currency_exchange($points_disc_base)) : $points_disc_base;
            if (round($points_show_val, 2) <= 0.0) {
                $points_disc_base = 0.0;
                $points_used_cnt = 0;
            }

            $order['coupon_discount_amount'] = $coupon_disc_base;
            $order['points_discount_amount'] = $points_disc_base;
            $order['points_used'] = $points_used_cnt;
            $order['coupon_discount_amount_format'] = function_exists('todo_format_price') ? todo_format_price($coupon_disc_base, 'number') : number_format($coupon_disc_base, 2);
            $order['points_discount_amount_format'] = function_exists('todo_format_price') ? todo_format_price($points_disc_base, 'number') : number_format($points_disc_base, 2);
            
            $subtotal = $total_amt - $ship_fee + $disc_amt;
            $order['subtotal'] = $subtotal;
            $order['subtotal_amount'] = $subtotal;
            $order['subtotal_format'] = function_exists('todo_format_price') ? todo_format_price($subtotal, 'number') : number_format($subtotal, 2);
            $order['subtotal_amount_format'] = function_exists('todo_format_price') ? todo_format_price($subtotal, 'number') : number_format($subtotal, 2);

            // 解析收货地址结构
            $addr = $order['shipping_address'] ?? '';
            $addr_arr = [];
            if (is_string($addr) && $addr !== '') {
                $decoded = json_decode($addr, true);
                if (is_array($decoded)) {
                    $addr_arr = $decoded;
                } else {
                    $addr_arr = ['full_address' => $addr, 'name' => '', 'phone' => ''];
                }
            } elseif (is_array($addr)) {
                $addr_arr = $addr;
            } else {
                $addr_arr = ['full_address' => '', 'name' => '', 'phone' => ''];
            }
            $region_type = $addr_arr['region_type'] ?? ($addr_arr['address_region_type'] ?? '');
            $prov = $addr_arr['province'] ?? ($addr_arr['province_name'] ?? '');
            $country = $addr_arr['country'] ?? ($addr_arr['intl_country'] ?? '');
            $state = $addr_arr['state'] ?? ($addr_arr['intl_state'] ?? '');

            $has_delimiter = (strpos($prov, ' | ') !== false);
            if ($has_delimiter) {
                list($c_tmp, $s_tmp) = explode(' | ', $prov, 2);
                $country = trim($c_tmp);
                $state = trim($s_tmp);
                $is_intl = true;
            } elseif ($region_type === 'intl') {
                $is_intl = true;
            } elseif (!empty($country) && !in_array($country, ['中国', 'China', 'CN', '中华人民共和国'], true)) {
                $is_intl = true;
            } else {
                $is_intl = false;
            }

            $city = $addr_arr['city'] ?? ($addr_arr['city_name'] ?? '');
            $dist = $addr_arr['district'] ?? ($addr_arr['district_name'] ?? '');
            $det = $addr_arr['detail'] ?? ($addr_arr['address'] ?? ($addr_arr['detailed_address'] ?? ''));
            $zip = $addr_arr['postal_code'] ?? ($addr_arr['zipcode'] ?? '');

            if ($is_intl) {
                $parts = [];
                if ($det !== '') $parts[] = $det;
                if ($city !== '') $parts[] = $city;
                $state_zip = $state;
                if ($zip !== '') {
                    $state_zip = $state_zip !== '' ? ($state_zip . ' ' . $zip) : $zip;
                }
                if ($state_zip !== '') $parts[] = $state_zip;
                if ($country !== '') $parts[] = $country;
                $full_addr = implode(', ', $parts);
            } else {
                $p_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($prov) : '';
                $c_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($city) : '';
                $d_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($dist) : '';
                $prov_disp = !empty($p_name) ? $p_name : $prov;
                $city_disp = !empty($c_name) ? $c_name : $city;
                $dist_disp = !empty($d_name) ? $d_name : $dist;
                $parts = array_filter([$prov_disp, $city_disp, $dist_disp, $det]);
                $full_addr = implode(' ', $parts);
            }
            $addr_arr['full_address'] = $full_addr;

            // 为 order-detail.htm / order-success.htm 准备收货地址结构与别名
            $recipient_name = $addr_arr['name'] ?? ($addr_arr['recipient'] ?? ($addr_arr['recipient_name'] ?? ''));
            $recipient_phone = $addr_arr['phone'] ?? ($addr_arr['mobile'] ?? ($addr_arr['recipient_phone'] ?? ''));
            $recipient_email = $addr_arr['email'] ?? ($addr_arr['guest_email'] ?? ($addr_arr['recipient_email'] ?? ($order['shipping_email'] ?? ($order['contact_email'] ?? ($order['guest_email'] ?? ($order['user_email'] ?? ''))))));
            if (empty($recipient_email) && !empty($order['user_id']) && intval($order['user_id']) > 0) {
                global $tododb;
                $u_email = $tododb->get_var($tododb->prepare("SELECT user_email FROM {$tododb->prefix}users WHERE ID = %d LIMIT 1", intval($order['user_id'])));
                if ($u_email && strpos($u_email, '@guest.local') === false) {
                    $recipient_email = trim($u_email);
                }
            }
            if (strpos($recipient_email, '@guest.local') !== false) {
                $recipient_email = '';
            }

            $order['shipping_name'] = $recipient_name;
            $order['shipping_phone'] = $recipient_phone;
            $order['shipping_email'] = $recipient_email;
            $order['shipping_address_text'] = $full_addr;
            $order['shipping_address_data'] = $addr_arr;
            $order['shipping_address'] = array_merge($addr_arr, [
                'name' => $recipient_name,
                'phone' => $recipient_phone,
                'email' => $recipient_email,
                'province' => $prov,
                'city' => $city,
                'district' => $dist,
                'detail' => $det,
                'full_address' => $full_addr
            ]);
            $order['order_notes'] = $order['remark'] ?? ($order['notes'] ?? ($order['order_note'] ?? ''));
            $order['notes'] = $order['order_notes'];
            $order['remark'] = $order['order_notes'];
            $raw_payment_time = !empty($order['paid_at']) ? $order['paid_at'] : (!empty($order['payment_time']) ? $order['payment_time'] : '');
            $order['payment_time'] = !empty($raw_payment_time) ? date('Y-m-d H:i:s', strtotime($raw_payment_time)) : '';
            $raw_shipping_time = !empty($order['shipped_at']) ? $order['shipped_at'] : (!empty($order['shipping_time']) ? $order['shipping_time'] : '');
            $order['shipping_time'] = !empty($raw_shipping_time) ? date('Y-m-d H:i:s', strtotime($raw_shipping_time)) : '';
            $order['tracking_number'] = $order['tracking_no'] ?? ($order['tracking_number'] ?? '');
            $order['shipping_company'] = $order['express_company'] ?? ($order['shipping_company'] ?? '');

            // 格式化订单关联商品列表
            if (isset($order['items']) && is_array($order['items'])) {
                $order_pids = [];
                foreach ($order['items'] as $it_chk) {
                    $pid_chk = intval($it_chk['product_id'] ?? 0);
                    if ($pid_chk > 0) $order_pids[] = $pid_chk;
                }
                $order_units_cache = function_exists('todo_preload_product_units') ? todo_preload_product_units($order_pids) : [];

                foreach ($order['items'] as &$it) {
                    $unit_price = floatval($it['unit_price'] ?? ($it['price'] ?? 0));
                    $qty = intval($it['quantity'] ?? ($it['qty'] ?? 1));
                    $total_price = floatval($it['total_price'] ?? ($unit_price * $qty));

                    $it['price'] = $unit_price;
                    $it['unit_price'] = $unit_price;
                    $it['price_format'] = function_exists('todo_format_price') ? todo_format_price($unit_price, 'number') : number_format($unit_price, 2);
                    $it['unit_price_format'] = function_exists('todo_format_price') ? todo_format_price($unit_price, 'number') : number_format($unit_price, 2);
                    $it['quantity'] = $qty;
                    $it['amount'] = $total_price;
                    $it['amount_format'] = function_exists('todo_format_price') ? todo_format_price($total_price, 'number') : number_format($total_price, 2);
                    $it['subtotal'] = $total_price;
                    $it['subtotal_format'] = function_exists('todo_format_price') ? todo_format_price($total_price, 'number') : number_format($total_price, 2);
                    $it['total_price'] = $total_price;
                    $it['total_price_format'] = function_exists('todo_format_price') ? todo_format_price($total_price, 'number') : number_format($total_price, 2);

                    $title = !empty($it['product_title']) ? $it['product_title'] : (!empty($it['product_name']) ? $it['product_name'] : (!empty($it['title']) ? $it['title'] : ''));
                    $it['title'] = $title;
                    $it['product_name'] = $title;
                    $it['product_title'] = $title;

                    if (!isset($it['item_id']) && isset($it['id'])) {
                        $it['item_id'] = $it['id'];
                    }

                    if (empty($it['product_image']) && !empty($it['thumbnail'])) {
                        $it['product_image'] = $it['thumbnail'];
                    }
                    if (empty($it['product_image']) && !empty($it['image'])) {
                        $it['product_image'] = $it['image'];
                    }

                    if (!empty($it['product_id']) && function_exists('todo_get_permalink')) {
                        $it['product_url'] = todo_get_permalink($it['product_id']);
                    }

                    // 规格信息：统一输出
                    $specs_raw = $it['product_specs'] ?? ($it['product_spec'] ?? '');
                    $specs_arr = [];
                    if (is_string($specs_raw) && $specs_raw !== '') {
                        $decoded = json_decode($specs_raw, true);
                        if (is_array($decoded)) {
                            $specs_arr = $decoded;
                        }
                    } elseif (is_array($specs_raw)) {
                        $specs_arr = $specs_raw;
                    }
                    $specs_text = '';
                    if (!empty($specs_arr) && function_exists('todo_format_specs_text')) {
                        $specs_text = todo_format_specs_text($specs_arr, intval($it['product_id'] ?? 0));
                    } else {
                        $sn = isset($it['spec_name']) ? (string) $it['spec_name'] : '';
                        if ($sn !== '' && function_exists('todo_normalize_specs_text_from_spec_name')) {
                            $specs_text = todo_normalize_specs_text_from_spec_name($sn, intval($it['product_id'] ?? 0));
                        } else {
                            $specs_text = trim($sn);
                        }
                    }
                    if (function_exists('todo_parse_inline_translations')) {
                        $specs_text = todo_parse_inline_translations($specs_text);
                    }
                    if (function_exists('todo_translate')) {
                        $specs_text = todo_translate($specs_text);
                    }
                    $it['spec_name'] = $specs_text;
                    $it['specs_text'] = $specs_text;
                    $it['product_specs'] = $specs_arr;
                    if (!empty($specs_arr) && is_array($specs_arr)) {
                        $pairs = [];
                        foreach ($specs_arr as $k => $v) {
                            if (!is_scalar($v)) {
                                continue;
                            }
                            $pairs[] = ['spec_name' => (string) $k, 'spec_value' => (string) $v];
                        }
                        $it['product_spec'] = $pairs;
                    } elseif ($specs_text !== '') {
                        $it['product_spec'] = [['spec_name' => '', 'spec_value' => $specs_text]];
                    } else {
                        $it['product_spec'] = [];
                    }
                    $pid_for_unit = intval($it['product_id'] ?? 0);
                    $u_raw = isset($it['unit']) ? (string)$it['unit'] : '';
                    $uq_raw = isset($it['unit_qty']) ? $it['unit_qty'] : null;

                    if (($u_raw === '' || $uq_raw === null) && $pid_for_unit > 0) {
                        $cached_u = $order_units_cache[$pid_for_unit] ?? null;
                        if ($cached_u) {
                            if ($u_raw === '' && isset($cached_u['unit'])) $u_raw = (string)$cached_u['unit'];
                            if ($uq_raw === null && isset($cached_u['unit_qty'])) $uq_raw = $cached_u['unit_qty'];
                        }
                    }

                    $it['product_unit_text'] = function_exists('todo_format_unit_text') ? todo_format_unit_text($uq_raw, $u_raw) : '';

                    // 提取产品扩展字段（支付前说明、支付后说明、确认收货说明）
                    $before_note = '';
                    $after_note = '';
                    $confirm_note = '';
                    $pid = intval($it['product_id'] ?? 0);
                    if ($pid > 0) {
                        $p_data = null;
                        if (class_exists('Todo_Html_Template_Tags')) {
                            $p_data = Todo_Html_Template_Tags::get_product_model_row($pid);
                        }
                        if (!$p_data && isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                            $db = $GLOBALS['tododb'];
                            $mfp_table = (isset($db->prefix) ? $db->prefix : 'todo_') . 'model_fields_product';
                            try {
                                $p_data = $db->get_row("SELECT memo_before_pay, memo_after_pay, memo_after_confirm, before_payment_note, after_payment_note, confirm_receipt_note FROM {$mfp_table} WHERE post_id = ? LIMIT 1", [$pid]);
                            } catch (\Exception $e) {}
                        }
                        if ($p_data) {
                            if (is_object($p_data)) {
                                $before_note = !empty($p_data->memo_before_pay) ? $p_data->memo_before_pay : (!empty($p_data->before_payment_note) ? $p_data->before_payment_note : '');
                                $after_note = !empty($p_data->memo_after_pay) ? $p_data->memo_after_pay : (!empty($p_data->after_payment_note) ? $p_data->after_payment_note : '');
                                $confirm_note = !empty($p_data->memo_after_confirm) ? $p_data->memo_after_confirm : (!empty($p_data->confirm_receipt_note) ? $p_data->confirm_receipt_note : '');
                            } elseif (is_array($p_data)) {
                                $before_note = !empty($p_data['memo_before_pay']) ? $p_data['memo_before_pay'] : (!empty($p_data['before_payment_note']) ? $p_data['before_payment_note'] : '');
                                $after_note = !empty($p_data['memo_after_pay']) ? $p_data['memo_after_pay'] : (!empty($p_data['after_payment_note']) ? $p_data['after_payment_note'] : '');
                                $confirm_note = !empty($p_data['memo_after_confirm']) ? $p_data['memo_after_confirm'] : (!empty($p_data['confirm_receipt_note']) ? $p_data['confirm_receipt_note'] : '');
                            }
                        }
                    }
                    $it['before_payment_note'] = $before_note;
                    $it['after_payment_note'] = $after_note;
                    $it['confirm_receipt_note'] = $confirm_note;
                    $it['memo_before_pay'] = $before_note;
                    $it['memo_after_pay'] = $after_note;
                    $it['memo_after_confirm'] = $confirm_note;

                    // 从 todo_model_fields_product 表查询交付物字段
                    if ($pid > 0 && isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                        $db = $GLOBALS['tododb'];
                        $mfp_table = (isset($db->prefix) ? $db->prefix : 'todo_') . 'model_fields_product';
                        try {
                            $mfp_row = $db->get_row($db->prepare(
                                "SELECT delivery_type, delivery_express_note, delivery_download_url, delivery_download_code, delivery_download_password, delivery_download_note, delivery_content_note, auto_gen_license_key FROM {$mfp_table} WHERE post_id = %d LIMIT 1",
                                $pid
                            ), ARRAY_A);
                            if ($mfp_row && (is_array($mfp_row) || is_object($mfp_row))) {
                                $mfp_arr = (array) $mfp_row;
                                foreach ($mfp_arr as $mk => $mv) {
                                    $it[$mk] = $mv ?: '';
                                }
                            }
                        } catch (\Exception $e) {}
                    }
                    // 保留 post_meta 作为备选回退
                    if (empty($it['delivery_type']) && $pid > 0) {
                        $it['delivery_type'] = get_post_meta($pid, 'delivery_type', true) ?: '';
                        $it['delivery_express_note'] = $it['delivery_express_note'] ?? (get_post_meta($pid, 'delivery_express_note', true) ?: '');
                        $it['delivery_download_url'] = $it['delivery_download_url'] ?? (get_post_meta($pid, 'delivery_download_url', true) ?: '');
                        $it['delivery_download_code'] = $it['delivery_download_code'] ?? (get_post_meta($pid, 'delivery_download_code', true) ?: '');
                        $it['delivery_download_password'] = $it['delivery_download_password'] ?? (get_post_meta($pid, 'delivery_download_password', true) ?: '');
                        $it['delivery_download_note'] = $it['delivery_download_note'] ?? (get_post_meta($pid, 'delivery_download_note', true) ?: '');
                        $it['delivery_content_note'] = $it['delivery_content_note'] ?? (get_post_meta($pid, 'delivery_content_note', true) ?: '');
                    }

                    // 动态替换 delivery_content_note 中的占位符
                    $note_template = $it['delivery_content_note'] ?? '';
                    $lic_key = $it['license_key'] ?? '';
                    $lic_pass = $it['license_pass'] ?? '';
                    if (!empty($lic_key) || !empty($lic_pass)) {
                        $it['delivery_content_note'] = str_replace(
                            ['{license_key}', '{license_pass}'],
                            [$lic_key, $lic_pass],
                            $note_template
                        );
                    }

                    // 根据 product_type 与 delivery_type 严格清理无关的交付物数据（后端渲染端过滤，绝不暴露）
                    $pt = $it['product_type'] ?? 'physical';
                    $dt = $it['delivery_type'] ?? 'express';

                    if ($pt === 'physical' || $pt !== 'virtual') {
                        // 实物商品完全不使用交付物卡片，清空 delivery_type 及所有交付数据
                        $it['delivery_type'] = '';
                        $it['delivery_express_note'] = '';
                        $it['delivery_download_url'] = '';
                        $it['delivery_download_code'] = '';
                        $it['delivery_download_password'] = '';
                        $it['delivery_download_note'] = '';
                        $it['delivery_content_note'] = '';
                    } else {
                        // 虚拟商品：依据 delivery_type 精准保留对应字段，其余清空
                        if (in_array($dt, ['express', 'expresstrue'], true)) {
                            $it['delivery_download_url'] = '';
                            $it['delivery_download_code'] = '';
                            $it['delivery_download_password'] = '';
                            $it['delivery_download_note'] = '';
                            $it['delivery_content_note'] = '';
                        } elseif ($dt === 'download') {
                            $it['delivery_express_note'] = '';
                            $it['delivery_content_note'] = '';
                        } elseif ($dt === 'content') {
                            $it['delivery_express_note'] = '';
                            $it['delivery_download_url'] = '';
                            $it['delivery_download_code'] = '';
                            $it['delivery_download_password'] = '';
                            $it['delivery_download_note'] = '';
                        }
                    }

                    // 查询该商品项是否已评价，并补充已评价数据（供 order-review.htm 渲染只读已评价内容与晒图）
                    $it['is_reviewed'] = false;
                    $it['review'] = [
                        'rating' => 5,
                        'content' => '',
                        'created_at_format' => '',
                        'images' => []
                    ];
                    if ($pid > 0 && !empty($order['id']) && isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                        $db = $GLOBALS['tododb'];
                        $pr_tbl = $db->prefix . 'product_reviews';
                        $c_sql = "SELECT id, rating, content, images, created_at 
                                  FROM {$pr_tbl} 
                                  WHERE product_id = ? AND order_id = ? 
                                  ORDER BY id DESC LIMIT 1";
                        $c_row = $db->get_row($c_sql, [$pid, $order['id']], 'ARRAY_A');
                        if (!$c_row) {
                            // 尝试从 todo_comments 及 commentmeta 中检索
                            try {
                                $com_tbl = $db->prefix . 'comments';
                                $cm_tbl = $db->prefix . 'commentmeta';
                                $c_sql2 = "SELECT c.comment_ID as id, c.comment_content as content, c.comment_date as created_at,
                                                  m_rate.meta_value as rating, m_imgs.meta_value as images
                                           FROM {$com_tbl} c
                                           LEFT JOIN {$cm_tbl} m_rate ON (c.comment_ID = m_rate.comment_id AND m_rate.meta_key = 'rating')
                                           LEFT JOIN {$cm_tbl} m_imgs ON (c.comment_ID = m_imgs.comment_id AND m_imgs.meta_key = 'images')
                                           LEFT JOIN {$cm_tbl} m_oid ON (c.comment_ID = m_oid.comment_id AND m_oid.meta_key = 'order_id')
                                           WHERE c.comment_post_ID = ? AND m_oid.meta_value = ?
                                           ORDER BY c.comment_ID DESC LIMIT 1";
                                $c_row = $db->get_row($c_sql2, [$pid, $order['id']], 'ARRAY_A');
                            } catch (Throwable $e) {
                            }
                        }

                        if ($c_row) {
                            $it['is_reviewed'] = true;
                            $imgs_raw = $c_row['images'] ?? '';
                            $img_list = [];
                            if (!empty($imgs_raw)) {
                                $decoded = is_string($imgs_raw) ? json_decode($imgs_raw, true) : (is_array($imgs_raw) ? $imgs_raw : []);
                                if (is_string($decoded)) {
                                    $decoded = json_decode($decoded, true);
                                }
                                if (!is_array($decoded) && is_string($imgs_raw)) {
                                    $decoded = array_filter(array_map('trim', explode(',', $imgs_raw)));
                                }
                                if (is_array($decoded)) {
                                    foreach ($decoded as $u) {
                                        $raw_url = '';
                                        if (is_string($u)) {
                                            $raw_url = trim($u);
                                        } elseif (is_array($u)) {
                                            $raw_url = trim((string)($u['image_url'] ?? $u['url'] ?? $u['src'] ?? ''));
                                        }
                                        if ($raw_url !== '') {
                                            if (strpos($raw_url, 'data:image/') === 0) {
                                                $safe_url = $raw_url;
                                            } else {
                                                $safe_url = esc_url($raw_url);
                                                if ($safe_url === '' && (filter_var($raw_url, FILTER_VALIDATE_URL) || (isset($raw_url[0]) && $raw_url[0] === '/'))) {
                                                    $safe_url = $raw_url;
                                                }
                                            }
                                            if ($safe_url !== '') {
                                                $img_list[] = [
                                                    'image_url' => $safe_url,
                                                    'url'       => $safe_url,
                                                    'src'       => $safe_url,
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                            $review_rating = intval($c_row['rating'] ?? 5);
                            if ($review_rating < 1) $review_rating = 1;
                            if ($review_rating > 5) $review_rating = 5;

                            $stars_arr = [];
                            for ($s = 1; $s <= 5; $s++) {
                                $stars_arr[] = [
                                    'star' => $s,
                                    'active' => ($s <= $review_rating)
                                ];
                            }

                            $it['review'] = [
                                'rating' => $review_rating,
                                'rating_percent' => min(100, max(0, intval(($review_rating / 5) * 100))),
                                'content' => nl2br(esc_html($c_row['content'] ?? '')),
                                'created_at_format' => !empty($c_row['created_at']) ? date('Y-m-d H:i', strtotime($c_row['created_at'])) : '',
                                'images' => $img_list
                            ];
                            $it['review_images'] = $img_list;
                            $it['review_rating'] = $it['review']['rating'];
                            $it['review_rating_percent'] = $it['review']['rating_percent'];
                            $it['review_stars'] = $stars_arr;
                            $it['review_content'] = $it['review']['content'];
                            $it['review_created_at_format'] = $it['review']['created_at_format'];
                        }
                    }
                }
                unset($it);

                // 汇总订单层级的说明（多商品取去重并排后的非空说明）
                $order_before_notes = array_unique(array_filter(array_column($order['items'], 'before_payment_note')));
                $order_after_notes = array_unique(array_filter(array_column($order['items'], 'after_payment_note')));
                $order_confirm_notes = array_unique(array_filter(array_column($order['items'], 'confirm_receipt_note')));

                $order['before_payment_note'] = implode("\n", $order_before_notes);
                $order['after_payment_note'] = implode("\n", $order_after_notes);
                $order['confirm_receipt_note'] = implode("\n", $order_confirm_notes);
                $order['memo_before_pay'] = $order['before_payment_note'];
                $order['memo_after_pay'] = $order['after_payment_note'];
                $order['memo_after_confirm'] = $order['confirm_receipt_note'];
            }

            // 获取支付记录与发货记录/日志
            if (class_exists('Todo_Order_Manager')) {
                $pay_recs = Todo_Order_Manager::get_payment_records($order['id']);
                if (is_array($pay_recs)) {
                    foreach ($pay_recs as &$pr) {
                        $amt_val = floatval($pr['amount'] ?? 0);
                        $pr['amount_format'] = function_exists('todo_format_price') ? todo_format_price($amt_val, 'number') : number_format($amt_val, 2);
                        $pr['status'] = $pr['payment_status'] ?? 'paid';
                        $pr['status_text'] = $pay_status_map[$pr['status']] ?? $pr['status'];
                        
                        $pr_pm = !empty($pr['payment_method']) ? $pr['payment_method'] : ($order['payment_method'] ?? '');
                        $pr['payment_method'] = $pm_map[$pr_pm] ?? ($pr_pm ?: '在线支付');
                    }
                    unset($pr);
                }
                $order['payment_records'] = $pay_recs;
                $order['logs'] = Todo_Order_Manager::get_order_logs($order['id'], 50, true);
            }

            // 获取最新售后/退款记录
            $order['refund_info'] = null;
            $order['has_refund'] = false;
            $order['is_refunding'] = false;
            $order['is_refunded'] = false;
            if (isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb']) && isset($GLOBALS['tododb']->prefix)) {
                $db = $GLOBALS['tododb'];
                $ref_row = $db->get_row($db->prepare("SELECT * FROM {$db->prefix}refunds WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order['id']), [], 'ARRAY_A');
                if (is_object($ref_row)) $ref_row = (array) $ref_row;
                if ($ref_row) {
                    $ref_amt = floatval($ref_row['refund_amount'] ?? 0);
                    $ref_status = $ref_row['status'] ?? 'pending';
                    $is_terminal_refunded = in_array($ref_status, ['completed', 'success'], true)
                        || ($order['status'] ?? '') === 'refunded'
                        || ($order['payment_status'] ?? '') === 'refunded';
                    if ($is_terminal_refunded) {
                        $ref_status = 'completed';
                        $ref_row['status'] = 'completed';
                    }
                    $status_text_map = [
                        'pending' => __('待审核'),
                        'processing' => __('处理中'),
                        'completed' => __('已退款'),
                        'success' => __('退款成功'),
                        'rejected' => __('已拒绝'),
                        'failed' => __('退款失败'),
                        'cancelled' => __('已撤销')
                    ];
                    $ref_row['refund_amount_format'] = function_exists('todo_format_price') ? todo_format_price($ref_amt, 'number') : number_format($ref_amt, 2);
                    $ref_row['status_text'] = $status_text_map[$ref_status] ?? $ref_status;
                    $ref_row['created_at_format'] = !empty($ref_row['created_at']) ? date('Y-m-d H:i:s', strtotime($ref_row['created_at'])) : '';
                    $ref_row['processed_at_format'] = !empty($ref_row['processed_at']) ? date('Y-m-d H:i:s', strtotime($ref_row['processed_at'])) : '';
                    $ref_row['return_shipped_at_format'] = !empty($ref_row['return_shipped_at']) ? date('Y-m-d H:i:s', strtotime($ref_row['return_shipped_at'])) : '';

                    // 格式化凭证图片
                    $ev_raw = $ref_row['evidence_images'] ?? '';
                    $ev_imgs = [];
                    if (!empty($ev_raw)) {
                        $decoded_ev = is_string($ev_raw) ? json_decode($ev_raw, true) : (is_array($ev_raw) ? $ev_raw : []);
                        if (is_array($decoded_ev)) {
                            foreach ($decoded_ev as $e_url) {
                                if (is_string($e_url) && trim($e_url) !== '') {
                                    $raw_url = trim($e_url);
                                    $final_url = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($raw_url) : $raw_url;
                                    $ev_imgs[] = [
                                        'value'     => $final_url,
                                        'url'       => $final_url,
                                        'src'       => $final_url,
                                        'image_url' => $final_url,
                                    ];
                                }
                            }
                        }
                    }
                    $ref_row['evidence_image_list'] = $ev_imgs;
                    $ref_row['has_evidence_images'] = !empty($ev_imgs);

                    $rf_type = $ref_row['refund_type'] ?? 'refund_only';
                    $ref_row['is_return_and_refund'] = ($rf_type === 'return_and_refund');
                    $ref_row['refund_type_text'] = ($rf_type === 'return_and_refund') ? __('退货退款') : __('仅退款');
                    $ref_row['has_return_logistics'] = !empty($ref_row['return_tracking_no']);
                    $is_terminal_refunded = in_array($ref_status, ['completed', 'success'], true)
                        || ($order['status'] ?? '') === 'refunded'
                        || ($order['payment_status'] ?? '') === 'refunded';
                    if ($is_terminal_refunded && in_array($ref_status, ['pending', 'processing'], true)) {
                        $ref_status = 'completed';
                        $ref_row['status'] = 'completed';
                        $ref_row['status_text'] = $status_text_map['completed'] ?? __('已退款');
                        if (empty($ref_row['processed_at_format'])) {
                            $ref_row['processed_at_format'] = date('Y-m-d H:i:s');
                        }
                        $db->query($db->prepare(
                            "UPDATE {$db->prefix}refunds SET status = 'completed', processed_at = IFNULL(processed_at, NOW()), updated_at = NOW() WHERE id = %d",
                            $ref_row['id']
                        ));
                    }
                    $order['refund_info'] = $ref_row;
                    $order['has_refund'] = true;
                    $order['is_refunded'] = $is_terminal_refunded;
                    $order['is_refunding'] = !$is_terminal_refunded && in_array($ref_status, ['pending', 'processing'], true);
                    $order['can_cancel_refund'] = !$is_terminal_refunded && ($ref_status === 'pending');
                    $ref_row['can_submit_return_logistics'] = !$is_terminal_refunded && in_array($ref_status, ['pending', 'processing'], true) && ($rf_type === 'return_and_refund');
                }
            }
            if (!$order['has_refund']) {
                $curr_pstatus = $order['payment_status'] ?? '';
                $curr_ostatus = $order['status'] ?? '';
                if ($curr_pstatus === 'refunded' || $curr_ostatus === 'refunded') {
                    $order['has_refund'] = true;
                    $order['is_refunded'] = true;
                    $order['is_refunding'] = false;
                } elseif ($curr_pstatus === 'refunding' || $curr_ostatus === 'refund') {
                    $order['has_refund'] = true;
                    $order['is_refunding'] = true;
                }
            }
            // 判断是否允许申请售后（后台需开启售后通道，且订单为已付款、待发货、待收货、已完成订单，当前不在退款中或已退款状态）
            $can_apply_refund = false;
            $srv_settings = function_exists('todo_shop_get_service_settings') ? todo_shop_get_service_settings() : [];
            $allow_refund_channel = !empty($srv_settings['allow_refund']);

            $pm_status = $order['payment_status'] ?? '';
            $st_status = $order['status'] ?? '';
            if ($allow_refund_channel && empty($order['is_recharge'])) {
                if ($pm_status === 'paid' || in_array($st_status, ['processing', 'shipped', 'delivered', 'completed'], true)) {
                    if ($pm_status !== 'refunding' && $pm_status !== 'refunded' && $st_status !== 'refund' && $st_status !== 'refunded' && $st_status !== 'cancelled') {
                        $can_apply_refund = true;

                        // 检查售后保障期（按发货或完成时间）
                        $ret_days = isset($srv_settings['return_window_days']) ? intval($srv_settings['return_window_days']) : 14;
                        if ($ret_days > 0) {
                            $base_ts = !empty($order['completed_at']) ? strtotime($order['completed_at']) : (!empty($order['shipped_at']) ? strtotime($order['shipped_at']) : 0);
                            if ($base_ts > 0 && time() > ($base_ts + ($ret_days * 86400))) {
                                $can_apply_refund = false;
                            }
                        }

                        // 检查历史售后申请次数上限
                        if ($can_apply_refund) {
                            $max_attempts = isset($srv_settings['refund_max_attempts']) ? max(1, intval($srv_settings['refund_max_attempts'])) : 3;
                            if (isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb'])) {
                                $h_cnt = intval($GLOBALS['tododb']->get_var($GLOBALS['tododb']->prepare(
                                    "SELECT COUNT(*) FROM {$GLOBALS['tododb']->prefix}refunds WHERE order_id = %d",
                                    $order['id']
                                )));
                                if ($h_cnt >= $max_attempts) {
                                    $can_apply_refund = false;
                                }
                            }
                        }
                    }
                }
            }
            $order['can_apply_refund'] = $can_apply_refund;

            $data['order'] = $order;
            $data['order_id'] = $order['id'];

            $debug_enabled = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
            if ($debug_enabled) {
                $data['order_debug_json'] = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if (isset($GLOBALS['tododb']) && is_object($GLOBALS['tododb']) && isset($GLOBALS['tododb']->prefix)) {
                    $db = $GLOBALS['tododb'];
                    $items_table = $db->prefix . 'order_items';
                    $rows = $db->get_results(
                        "SELECT id, order_id, product_id, product_name, spec_id, spec_name, price, quantity, total_price, product_image, created_at FROM {$items_table} WHERE order_id = ? ORDER BY id",
                        [$order['id']],
                        'ARRAY_A'
                    );
                    $data['order_items_db_dump_json'] = json_encode(is_array($rows) ? $rows : [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } else {
                    $data['order_items_db_dump_json'] = json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
            }
        }
    }
    
    return $data;
}

/**
 * 注册用户中心标签
 */
function todo_register_user_center_tags() {
    if (!class_exists('CMS_User_Center_Template_Tags') || !class_exists('CMS_Template_Compiler_V2')) {
        return;
    }
    
    $tag_handler = CMS_User_Center_Template_Tags::get_instance();
    
    // 用户信息标签
    CMS_Template_Compiler_V2::register_tag('user_name', [$tag_handler, 'parse_user_name']);
    CMS_Template_Compiler_V2::register_tag('user_email', [$tag_handler, 'parse_user_email']);
    CMS_Template_Compiler_V2::register_tag('user_avatar', [$tag_handler, 'parse_user_avatar']);
    CMS_Template_Compiler_V2::register_tag('user_balance', [$tag_handler, 'parse_user_balance']);
    CMS_Template_Compiler_V2::register_tag('user_points', [$tag_handler, 'parse_user_points']);
    CMS_Template_Compiler_V2::register_tag('user_level', [$tag_handler, 'parse_user_level']);
    
    // 订单相关标签
    CMS_Template_Compiler_V2::register_tag('order_number', [$tag_handler, 'parse_order_number']);
    CMS_Template_Compiler_V2::register_tag('order_date', [$tag_handler, 'parse_order_date']);
    CMS_Template_Compiler_V2::register_tag('order_amount', [$tag_handler, 'parse_order_amount']);
    CMS_Template_Compiler_V2::register_tag('order_status', [$tag_handler, 'parse_order_status']);
    CMS_Template_Compiler_V2::register_tag('order_status_text', [$tag_handler, 'parse_order_status_text']);
    
    // 产品相关标签
    CMS_Template_Compiler_V2::register_tag('product_name', [$tag_handler, 'parse_uc_product_name']);
    CMS_Template_Compiler_V2::register_tag('product_price', [$tag_handler, 'parse_uc_product_price']);
    CMS_Template_Compiler_V2::register_tag('product_image', [$tag_handler, 'parse_uc_product_image']);
    
    // 购物车相关标签
    CMS_Template_Compiler_V2::register_tag('cart_count', [$tag_handler, 'parse_cart_count']);
    CMS_Template_Compiler_V2::register_tag('cart_total', [$tag_handler, 'parse_cart_total']);
    CMS_Template_Compiler_V2::register_tag('cart_subtotal_format', [$tag_handler, 'parse_cart_subtotal_format']);
    CMS_Template_Compiler_V2::register_tag('cart_shipping_fee_format', [$tag_handler, 'parse_cart_shipping_fee_format']);
    CMS_Template_Compiler_V2::register_tag('cart_total_format', [$tag_handler, 'parse_cart_total_format']);
    CMS_Template_Compiler_V2::register_tag('cart_url', [$tag_handler, 'parse_cart_url']);
    CMS_Template_Compiler_V2::register_tag('cart_items', [$tag_handler, 'parse_cart_items']);
    CMS_Template_Compiler_V2::register_tag('checkout_url', [$tag_handler, 'parse_checkout_url']);
    CMS_Template_Compiler_V2::register_tag('shop_url', [$tag_handler, 'parse_shop_url']);
    
    // 收藏和消息标签
    CMS_Template_Compiler_V2::register_tag('favorites_count', [$tag_handler, 'parse_favorites_count']);
    CMS_Template_Compiler_V2::register_tag('favorite_products_count', [$tag_handler, 'parse_favorite_products_count']);
    CMS_Template_Compiler_V2::register_tag('favorite_articles_count', [$tag_handler, 'parse_favorite_articles_count']);
    CMS_Template_Compiler_V2::register_tag('favorite_products', [$tag_handler, 'parse_user_favorite_products']);
    CMS_Template_Compiler_V2::register_tag('favorite_articles', [$tag_handler, 'parse_user_favorite_articles']);
    
    // 地址相关标签
    CMS_Template_Compiler_V2::register_tag('user_default_address_text', [$tag_handler, 'parse_user_default_address_text']);
    
    // 文章与分类相关标签
    CMS_Template_Compiler_V2::register_tag('article_title', [$tag_handler, 'parse_article_title']);
    CMS_Template_Compiler_V2::register_tag('article_excerpt', [$tag_handler, 'parse_article_excerpt']);
    CMS_Template_Compiler_V2::register_tag('article_date', [$tag_handler, 'parse_article_date']);
    CMS_Template_Compiler_V2::register_tag('product_category', [$tag_handler, 'parse_product_category']);
    CMS_Template_Compiler_V2::register_tag('article_category', [$tag_handler, 'parse_article_category']);
    
    // 钱包相关标签
    CMS_Template_Compiler_V2::register_tag('wallet_url', [$tag_handler, 'parse_wallet_url']);
    CMS_Template_Compiler_V2::register_tag('wallet_balance', [$tag_handler, 'parse_wallet_balance']);
    CMS_Template_Compiler_V2::register_tag('wallet_transactions', [$tag_handler, 'parse_wallet_transactions']);
    
    // 优惠券标签
    CMS_Template_Compiler_V2::register_tag('coupons_count', [$tag_handler, 'parse_coupons_count']);
    CMS_Template_Compiler_V2::register_tag('user_coupons_count', [$tag_handler, 'parse_coupons_count']);
    CMS_Template_Compiler_V2::register_tag('user_coupons', [$tag_handler, 'parse_user_coupons']);
    
    // 页面导航标签
    CMS_Template_Compiler_V2::register_tag('current_page', [$tag_handler, 'parse_current_page']);
    CMS_Template_Compiler_V2::register_tag('user_url', [$tag_handler, 'parse_user_url']);
    CMS_Template_Compiler_V2::register_tag('user_center_url', [$tag_handler, 'parse_user_url']);
    CMS_Template_Compiler_V2::register_tag('user_page_url', [$tag_handler, 'parse_user_url']);
    CMS_Template_Compiler_V2::register_tag('is_user_page', [$tag_handler, 'parse_is_user_page']);
    
    // 更多用户特定标签
    CMS_Template_Compiler_V2::register_tag('user_orders', [$tag_handler, 'parse_user_orders']);
    CMS_Template_Compiler_V2::register_tag('user_order_count', [$tag_handler, 'parse_user_order_count']);
    CMS_Template_Compiler_V2::register_tag('user_orders_count', [$tag_handler, 'parse_user_orders_count']);
    CMS_Template_Compiler_V2::register_tag('user_addresses', [$tag_handler, 'parse_user_addresses']);
    CMS_Template_Compiler_V2::register_tag('user_cart_count', [$tag_handler, 'parse_user_cart_count']);
    CMS_Template_Compiler_V2::register_tag('user_cart_total', [$tag_handler, 'parse_user_cart_total']);
    CMS_Template_Compiler_V2::register_tag('user_favorites_count', [$tag_handler, 'parse_user_favorites_count']);
    CMS_Template_Compiler_V2::register_tag('user_address_count', [$tag_handler, 'parse_user_address_count']);
    CMS_Template_Compiler_V2::register_tag('user_wallet_balance', [$tag_handler, 'parse_user_wallet_balance']);
    CMS_Template_Compiler_V2::register_tag('user_coupon_count', [$tag_handler, 'parse_user_coupon_count']);
}


/**
 * 获取用户统计数据
 *
 * @param int $user_id 用户ID
 * @return array 统计数据
 */
function todo_get_user_center_stats($user_id = null) {
    if (!$user_id) {
        if (function_exists('todo_get_current_user_id')) {
            $user_id = todo_get_current_user_id();
        } else {
            return [];
        }
    }
    
    $stats = [];
    
    // 订单统计
    if (function_exists('todo_get_user_orders_count')) {
        $stats['orders_count'] = todo_get_user_orders_count($user_id);
        $stats['pending_orders'] = todo_get_user_orders_count($user_id, 'pending');
        $stats['completed_orders'] = todo_get_user_orders_count($user_id, 'completed');
    }
    
    // 购物车统计
    if (function_exists('todo_get_cart_count')) {
        $stats['cart_count'] = todo_get_cart_count($user_id);
    }
    
    // 收藏统计
    if (function_exists('todo_get_favorites_count')) {
        $stats['favorites_count'] = todo_get_favorites_count($user_id);
    }
    
    // 文章统计
    if (function_exists('todo_get_user_articles_count')) {
        $stats['articles_count'] = todo_get_user_articles_count($user_id);
    }
    
    return $stats;
}

/**
 * 获取用户订单列表
 *
 * @param int $user_id 用户ID
 * @param string $status 订单状态
 * @param int $limit 限制数量
 * @param int $offset 偏移量
 * @return array 订单列表
 */
if (!function_exists('todo_get_user_orders')) {
    function todo_get_user_orders($user_id = null, $status = '', $limit = 10, $offset = 0) {
        global $tododb;
        
        if (!$user_id) {
            $user_id = todo_get_current_user_id();
        }
        
        if (!$user_id) {
            return [];
        }
        
        $table_name = $tododb->prefix . 'orders';
        $where_clause = "WHERE user_id = ?";
        $params = [$user_id];
        
        if (!empty($status)) {
            $where_clause .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $orders = $tododb->get_results($sql, $params, 'ARRAY_A');
        
        // 处理订单数据格式化
        foreach ($orders as &$order) {
            // 映射数据库字段到模板字段
            $order['order_notes'] = $order['remark'] ?? '';
            $order['status_text'] = todo_get_order_status_text($order['status'] ?? '');
            $order['created_at_format'] = date('Y-m-d H:i:s', strtotime($order['created_at'] ?? ''));
            $order['total_amount_format'] = function_exists('todo_format_price') ? todo_format_price(floatval($order['total_amount'] ?? 0), 'number') : number_format(floatval($order['total_amount'] ?? 0), 2);
            $pay_amt = floatval($order['payment_amount'] ?? 0) > 0 ? floatval($order['payment_amount']) : floatval($order['total_amount'] ?? 0);
            $order['pay_amount'] = $pay_amt;
            $order['pay_amount_format'] = function_exists('todo_format_price') ? todo_format_price($pay_amt, 'number') : number_format($pay_amt, 2);
            
            // 获取订单商品
            $order_items = todo_get_order_items($order['id'] ?? 0);
            $order['items'] = $order_items;
            $order['items_count'] = count($order_items);
            $order['first_item'] = !empty($order_items) ? $order_items[0] : null;
        }
        
        return $orders;
    }
}

/**
 * 获取订单状态文本
 *
 * @param string $status 状态代码
 * @return string 状态文本
 */
if (!function_exists('todo_get_order_status_text')) {
    function todo_get_order_status_text($status) {
        $status_map = [
            'pending' => __('待付款'),
            'paid' => __('已付款'),
            'processing' => __('待发货'), 
            'shipped' => __('待收货'),
            'delivered' => __('已送达'),
            'completed' => __('已完成'),
            'cancelled' => __('已取消'),
            'refund' => __('售后中'),
            'refunding' => __('退款中'),
            'refunded' => __('已退款'),
            'failed' => __('支付失败')
        ];
        
        return $status_map[$status] ?? __('未知状态');
    }
}

/**
 * 获取订单商品列表
 *
 * @param int $order_id 订单ID
 * @return array 订单商品列表
 */
if (!function_exists('todo_get_order_items')) {
    function todo_get_order_items($order_id) {
        global $tododb;
        
        if (!$order_id) {
            return [];
        }
        
        $table_name = $tododb->prefix . 'order_items';
        $sql = "SELECT * FROM {$table_name} WHERE order_id = ? ORDER BY id";
        
        $items = $tododb->get_results($sql, [$order_id], 'ARRAY_A');
        
        // 格式化商品数据
        foreach ($items as &$item) {
            // 字段对齐与兼容处理
            if (isset($item['price']) && !isset($item['unit_price'])) {
                $item['unit_price'] = $item['price'];
            }
            if (isset($item['total_price']) && !isset($item['subtotal'])) {
                $item['subtotal'] = $item['total_price'];
            }
            $title = !empty($item['product_title']) ? $item['product_title'] : (!empty($item['product_name']) ? $item['product_name'] : (!empty($item['title']) ? $item['title'] : ''));
            $item['title'] = $title;
            $item['product_name'] = $title;
            $item['product_title'] = $title;
            
            $item_pid = intval($item['product_id'] ?? 0);
            if (!empty($item_pid)) {
                if (function_exists('todo_get_permalink')) {
                    $item['product_url'] = todo_get_permalink($item_pid);
                } elseif (function_exists('get_permalink')) {
                    $item['product_url'] = get_permalink($item_pid);
                }
                $item['url'] = $item['product_url'] ?? '';
            }
            
            // 图片处理：优先使用订单项图片
            if (!empty($item['product_image'])) {
                // 保留已有图片
            } elseif (!empty($item['product_id']) && function_exists('get_the_post_thumbnail_url')) {
                $item['product_image'] = get_the_post_thumbnail_url($item['product_id'], 'medium');
            } else {
                $item['product_image'] = '';
            }

            if (!empty($item['product_image']) && function_exists('todo_media_url_to_relative')) {
                $item['product_image'] = todo_media_url_to_relative($item['product_image']);
            }
            
            // 处理规格信息
            $specs_text = '';
            $specs_arr = [];
            $sn = !empty($item['spec_name']) ? trim((string) $item['spec_name']) : '';
            if (!empty($item['product_spec']) && is_string($item['product_spec'])) {
                $decoded = json_decode($item['product_spec'], true);
                if (is_array($decoded)) {
                    $specs_arr = $decoded;
                }
            } elseif (!empty($item['product_specs']) && is_string($item['product_specs'])) {
                $decoded = json_decode($item['product_specs'], true);
                if (is_array($decoded)) {
                    $specs_arr = $decoded;
                }
            }

            if (!empty($specs_arr) && function_exists('todo_format_specs_text')) {
                $specs_text = todo_format_specs_text($specs_arr, $item['product_id']);
            } elseif ($sn !== '' && function_exists('todo_normalize_specs_text_from_spec_name')) {
                $specs_text = todo_normalize_specs_text_from_spec_name($sn, $item['product_id']);
            } elseif ($sn !== '') {
                $specs_text = $sn;
            }
            if (function_exists('todo_parse_inline_translations')) {
                $specs_text = todo_parse_inline_translations($specs_text);
            }
            if (function_exists('todo_translate')) {
                $specs_text = todo_translate($specs_text);
            }
            $item['spec_name'] = $specs_text;
            $item['specs_text'] = $specs_text;
            if (!empty($specs_arr) && is_array($specs_arr)) {
                $pairs = [];
                foreach ($specs_arr as $k => $v) {
                    if (!is_scalar($v)) {
                        continue;
                    }
                    $pairs[] = ['spec_name' => (string) $k, 'spec_value' => (string) $v];
                }
                $item['product_spec'] = $pairs;
                $item['product_specs'] = $specs_arr;
            } elseif ($specs_text !== '') {
                $item['product_spec'] = [['spec_name' => '', 'spec_value' => $specs_text]];
                $item['product_specs'] = [['spec_name' => '', 'spec_value' => $specs_text]];
            } else {
                $item['product_spec'] = [];
                $item['product_specs'] = [];
            }
            
            $up_val = floatval($item['unit_price'] ?? 0);
            $qty_val = intval($item['quantity'] ?? 1);
            $sub_val = floatval($item['subtotal'] ?? (isset($item['total_price']) ? $item['total_price'] : ($up_val * $qty_val)));
            $item['total_price'] = $sub_val;
            $item['unit_price_format'] = function_exists('todo_format_price') ? todo_format_price($up_val, 'number') : number_format($up_val, 2);
            $item['subtotal_format'] = function_exists('todo_format_price') ? todo_format_price($sub_val, 'number') : number_format($sub_val, 2);
            $item['total_price_format'] = function_exists('todo_format_price') ? todo_format_price($sub_val, 'number') : number_format($sub_val, 2);

            if (class_exists('Todo_Error_Handler')) {
                Todo_Error_Handler::log("ORDER_ITEM_PRICE_DEBUG: order_id={$order_id}, item_id=" . ($item['id'] ?? 0) . ", spec_name=" . ($item['spec_name'] ?? '') . ", unit_price={$up_val}, qty={$qty_val}, total_price={$sub_val}, total_price_format=" . $item['total_price_format'], Todo_Error_Handler::LEVEL_INFO);
            }
        }
        
        return $items;
    }
}

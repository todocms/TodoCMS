<?php
/**
 * 管理后台设置页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

if (!defined('TODO_UNIFIED_CACHE_INIT_DONE') && defined('ABSPATH') && class_exists('Todo_Unified_Cache_Manager')) {
    define('TODO_UNIFIED_CACHE_INIT_DONE', true);
    Todo_Unified_Cache_Manager::init();
}

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// 引入媒体模态框组件
if (file_exists(ADMIN_DIR . '/components/media-modal.php')) {
    include_once ADMIN_DIR . '/components/media-modal.php';
}
// 引入特色图片组件（用于多Logo选择/排序）
if (file_exists(ADMIN_DIR . '/components/featured-images-component.php')) {
    include_once ADMIN_DIR . '/components/featured-images-component.php';
}

// 调试：检查系统状态
if (defined('TODO_DEBUG') && TODO_DEBUG) {
    error_log('TodoCMS Debug [Settings]: 检查系统状态');
    error_log('TodoCMS Debug [Settings]: todo_add_admin_action函数存在: ' . (function_exists('todo_add_admin_action') ? 'YES' : 'NO'));
    error_log('TodoCMS Debug [Settings]: todo_do_admin_action函数存在: ' . (function_exists('todo_do_admin_action') ? 'YES' : 'NO'));
    
    // 检查已注册的admin动作
    global $todo_admin_actions;
    if (!empty($todo_admin_actions)) {
        error_log('TodoCMS Debug [Settings]: 已注册的admin动作: ' . implode(', ', array_keys($todo_admin_actions)));
        if (isset($todo_admin_actions['footer'])) {
            error_log('TodoCMS Debug [Settings]: footer动作有 ' . count($todo_admin_actions['footer']) . ' 个回调');
        }
    } else {
        error_log('TodoCMS Debug [Settings]: 没有注册的admin动作');
    }
}

// 检查用户权限
if (!current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

$is_ajax_request = false;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $is_ajax_request = true;
} elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $is_ajax_request = true;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'update_settings' || $_POST['action'] === 'update_site_info')) {
    // 验证nonce
    if (!Todo_Security_Manager::verify_request_csrf('settings_nonce', '_todononce')) {
        if ($is_ajax_request) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                'success' => false,
                'message' => '安全验证失败，请重新提交。'
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        todo_die('安全验证失败，请重新提交。');
    }
    // 获取表单数据
    $site_title = isset($_POST['site_title']) ? trim($_POST['site_title']) : '';
    $site_description = isset($_POST['site_description']) ? trim($_POST['site_description']) : '';
    $site_url = isset($_POST['site_url']) ? trim($_POST['site_url']) : '';
    $admin_email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';
    $posts_per_page = isset($_POST['posts_per_page']) ? intval($_POST['posts_per_page']) : 10;
    $date_format = isset($_POST['date_format']) ? trim($_POST['date_format']) : 'Y-m-d';
    $date_format_custom = isset($_POST['date_format_custom']) ? trim($_POST['date_format_custom']) : '';
    $time_format = isset($_POST['time_format']) ? trim($_POST['time_format']) : 'H:i';
    $time_format_custom = isset($_POST['time_format_custom']) ? trim($_POST['time_format_custom']) : '';
    $timezone = isset($_POST['timezone']) ? trim($_POST['timezone']) : get_option('timezone', 'Asia/Shanghai');
    // 页面访问模式：dynamic=禁用模板缓存；static=启用模板缓存
    $page_mode = isset($_POST['page_mode']) ? (($_POST['page_mode'] === 'static') ? 'static' : 'dynamic') : get_option('page_mode', 'dynamic');
    // 动态模板微缓存TTL（秒），仅在动态模式下生效，用于缓解热点页面解析压力
    $dynamic_template_cache_ttl = isset($_POST['dynamic_template_cache_ttl']) ? intval($_POST['dynamic_template_cache_ttl']) : (int) get_option('dynamic_template_cache_ttl', 120);
    $permalink_structure = isset($_POST['permalink_structure']) ? trim($_POST['permalink_structure']) : '';
    $permalink_structure_custom = isset($_POST['permalink_structure_custom']) ? trim($_POST['permalink_structure_custom']) : '';
    $comments_per_page = isset($_POST['comments_per_page']) ? intval($_POST['comments_per_page']) : 20;
    $default_comment_status = isset($_POST['default_comment_status']) ? $_POST['default_comment_status'] : 'closed';
    $comment_moderation = isset($_POST['comment_moderation']) ? 1 : 0;
    $users_can_register = isset($_POST['users_can_register']) ? (bool) $_POST['users_can_register'] : false;
    $default_role = isset($_POST['default_role']) ? $_POST['default_role'] : 'subscriber';
    $mobile_login_enabled = isset($_POST['mobile_login_enabled']) ? (bool) $_POST['mobile_login_enabled'] : false;
    $sms_provider = isset($_POST['sms_provider']) ? trim($_POST['sms_provider']) : 'disabled';
    $sms_aliyun_access_key_id = isset($_POST['sms_aliyun_access_key_id']) ? trim($_POST['sms_aliyun_access_key_id']) : '';
    $sms_aliyun_access_key_secret = isset($_POST['sms_aliyun_access_key_secret']) ? trim($_POST['sms_aliyun_access_key_secret']) : '';
    $sms_aliyun_sign_name = isset($_POST['sms_aliyun_sign_name']) ? trim($_POST['sms_aliyun_sign_name']) : '';
    $sms_aliyun_template_code = isset($_POST['sms_aliyun_template_code']) ? trim($_POST['sms_aliyun_template_code']) : '';
    $sms_aliyun_endpoint = isset($_POST['sms_aliyun_endpoint']) ? trim($_POST['sms_aliyun_endpoint']) : '';
    $sms_aliyun_region = isset($_POST['sms_aliyun_region']) ? trim($_POST['sms_aliyun_region']) : '';
    $sms_tencent_secret_id = isset($_POST['sms_tencent_secret_id']) ? trim($_POST['sms_tencent_secret_id']) : '';
    $sms_tencent_secret_key = isset($_POST['sms_tencent_secret_key']) ? trim($_POST['sms_tencent_secret_key']) : '';
    $sms_tencent_app_id = isset($_POST['sms_tencent_app_id']) ? trim($_POST['sms_tencent_app_id']) : '';
    $sms_tencent_sign_name = isset($_POST['sms_tencent_sign_name']) ? trim($_POST['sms_tencent_sign_name']) : '';
    $sms_tencent_template_id = isset($_POST['sms_tencent_template_id']) ? trim($_POST['sms_tencent_template_id']) : '';
    $sms_tencent_endpoint = isset($_POST['sms_tencent_endpoint']) ? trim($_POST['sms_tencent_endpoint']) : '';
    $sms_tencent_region = isset($_POST['sms_tencent_region']) ? trim($_POST['sms_tencent_region']) : '';
    $qq_login_enabled = isset($_POST['qq_login_enabled']) ? (bool) $_POST['qq_login_enabled'] : false;
    $qq_app_id = isset($_POST['qq_app_id']) ? trim($_POST['qq_app_id']) : '';
    $qq_app_key = isset($_POST['qq_app_key']) ? trim($_POST['qq_app_key']) : '';
    $qq_redirect_uri = isset($_POST['qq_redirect_uri']) ? trim($_POST['qq_redirect_uri']) : '';
    
    // 后台登录路径设置
    $site_adminbasefile = isset($_POST['site-adminbasefile']) ? trim($_POST['site-adminbasefile']) : '';
    $site_adminbasefile_old = isset($_POST['site-adminbasefile-old']) ? trim($_POST['site-adminbasefile-old']) : '';
    $todo_auto_rotate_admin_entry = isset($_POST['todo_auto_rotate_admin_entry']) ? '1' : '0';
    $is_demo_admin = function_exists('todo_is_demo_admin') ? todo_is_demo_admin() : false;
    if ($is_demo_admin) {
        $site_adminbasefile = '';
        $site_adminbasefile_old = '';
        $todo_auto_rotate_admin_entry = '0';
    }
    
    // 上传设置相关字段
    $enable_upload = isset($_POST['enable_upload']) ? (bool) $_POST['enable_upload'] : false;
    $allowed_file_types = isset($_POST['allowed_file_types']) ? trim($_POST['allowed_file_types']) : '';
    $max_upload_size = isset($_POST['max_upload_size']) ? intval($_POST['max_upload_size']) : 10;
    $default_category = isset($_POST['default_category']) ? intval($_POST['default_category']) : 0;
    
    // 网站信息相关字段
    $site_logo = isset($_POST['site_logo']) ? $_POST['site_logo'] : '';
    $site_favicon = isset($_POST['site_favicon']) ? $_POST['site_favicon'] : '';
    $site_keywords = isset($_POST['site_keywords']) ? trim($_POST['site_keywords']) : '';
    $site_copyright = isset($_POST['site_copyright']) ? trim($_POST['site_copyright']) : '';
    $site_icp = isset($_POST['site_icp']) ? trim($_POST['site_icp']) : '';
    $site_analytics = isset($_POST['site_analytics']) ? trim($_POST['site_analytics']) : '';
    
    // 验证数据
    $errors = array();
    
    // 获取当前tab（优先使用POST以保证提交时不丢失当前标签）
    $current_tab = isset($_POST['tab']) ? $_POST['tab'] : (isset($_GET['tab']) ? $_GET['tab'] : 'general');
    
    // 根据不同tab进行不同的验证
    if ($current_tab === 'general') {
        if (empty($site_title)) {
            $errors[] = '网站标题不能为空。';
        }
    }
    
    if (!empty($admin_email) && !is_email($admin_email)) {
        $errors[] = '管理员邮箱格式不正确。';
    }
    
    if ($posts_per_page < 1) {
        $errors[] = '每页文章数必须大于0。';
    }
    
    if ($comments_per_page < 1) {
        $errors[] = '每页评论数必须大于0。';
    }

    if ($current_tab === 'writing') {
        if ($default_category < 0) {
            $default_category = 0;
        }
        if ($default_category > 0 && function_exists('get_term')) {
            $term = get_term($default_category, 'category');
            if (!$term || (function_exists('is_todo_error') && is_todo_error($term))) {
                $default_category = 0;
            }
        }
    }
    
    // 验证后台登录路径
    if ($current_tab === 'users') {
        if ($todo_auto_rotate_admin_entry === '0' && !empty($site_adminbasefile)) {
            // 验证文件名安全性（只允许字母、数字、下划线、连字符）
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $site_adminbasefile)) {
                $errors[] = '后台登录路径只能包含字母、数字、下划线和连字符。';
            }
            
            // 检查长度（手动输入上限放宽到32位，以兼容自动生成的长度）
            if (strlen($site_adminbasefile) < 3 || strlen($site_adminbasefile) > 32) {
                $errors[] = '后台登录路径长度必须在3-32个字符之间。';
            }
            
            // 强密码验证：如果用户选择手动模式，必须提供符合强度的名称
            if (function_exists('todo_is_strong_admin_entry')) {
                if (!todo_is_strong_admin_entry($site_adminbasefile)) {
                    // 如果不符合强度，则强制开启自动更换，且不执行重命名逻辑
                    $todo_auto_rotate_admin_entry = '1';
                    
                    // 如果文件名没变，不需要提示报错，只是强制开启自动；如果变了但不符合强度，则报错并丢弃输入
                    if ($site_adminbasefile !== $site_adminbasefile_old) {
                        $errors[] = '手动设置的后台登录路径强度不足（需包含大小写字母和数字，长度不少于10位）。系统已自动恢复“自动路径”模式以确保安全。';
                    }
                    
                    // 关键：如果不符合强度，不应该记录入库或执行文件更名
                    $site_adminbasefile = ''; 
                }
            }

            // 检查是否与当前文件名相同
            if ($site_adminbasefile === $site_adminbasefile_old) {
                // 文件名没有改变，跳过重命名
                $site_adminbasefile = '';
            }
        }
    }
    
    // 如果没有错误，更新设置
    if (empty($errors)) {
        // 根据当前tab更新相应设置
        if ($current_tab === 'general') {
            if (isset($_POST['date_format']) && $date_format === 'custom') {
                $date_format = $date_format_custom;
                if (function_exists('sanitize_text_field')) {
                    $date_format = sanitize_text_field($date_format);
                }
                $date_format = trim((string) $date_format);
                if ($date_format === '') {
                    $date_format = get_option('date_format', 'Y-m-d');
                }
            }
            if (isset($_POST['time_format']) && $time_format === 'custom') {
                $time_format = $time_format_custom;
                if (function_exists('sanitize_text_field')) {
                    $time_format = sanitize_text_field($time_format);
                }
                $time_format = trim((string) $time_format);
                if ($time_format === '') {
                    $time_format = get_option('time_format', 'H:i');
                }
            }

            // 更新常规设置
            update_option('site_title', $site_title);
            update_option('site_description', $site_description);
            update_option('site_keywords', $site_keywords);
            update_option('site_url', $site_url);
            update_option('admin_email', $admin_email);
            update_option('date_format', $date_format);
            update_option('time_format', $time_format);
            update_option('timezone', $timezone);
            update_option('timezone_string', $timezone);
            if ($timezone) {
                try {
                    $tz_obj = new DateTimeZone($timezone);
                    $now_obj = new DateTimeImmutable('now', $tz_obj);
                    update_option('gmt_offset', $now_obj->getOffset() / 3600);
                } catch (Throwable $e) {
                }
            }
        } elseif ($current_tab === 'reading') {
            // 更新阅读设置
            update_option('posts_per_page', $posts_per_page);
            // 页面访问模式：dynamic(禁用缓存)/static(启用缓存)
            update_option('page_mode', $page_mode);
            // 动态模板微缓存TTL（秒）：支持 0（禁用微缓存），否则限制在 30-3600 区间
            if ((int)$dynamic_template_cache_ttl === 0) {
                $__ttl = 0;
            } else {
                $__ttl = max(30, min(3600, (int)$dynamic_template_cache_ttl));
            }
            update_option('dynamic_template_cache_ttl', $__ttl);

            // 更新 欧盟 Cookie 合规设置
            $cookie_consent_enabled = isset($_POST['cookie_consent_enabled']) ? '1' : '0';
            $cookie_consent_google_mode_v2 = isset($_POST['cookie_consent_google_mode_v2']) ? '1' : '0';
            $cookie_consent_audit_log = isset($_POST['cookie_consent_audit_log']) ? '1' : '0';
            $cookie_consent_post_id = isset($_POST['cookie_consent_post_id']) ? intval($_POST['cookie_consent_post_id']) : 0;
            $cookie_consent_btn_accept = isset($_POST['cookie_consent_btn_accept']) ? trim($_POST['cookie_consent_btn_accept']) : '';
            $cookie_consent_btn_reject = isset($_POST['cookie_consent_btn_reject']) ? trim($_POST['cookie_consent_btn_reject']) : '';
            $post_allowed_template_tags = isset($_POST['post_allowed_template_tags']) ? trim($_POST['post_allowed_template_tags']) : '';

            update_option('cookie_consent_enabled', $cookie_consent_enabled);
            update_option('cookie_consent_google_mode_v2', $cookie_consent_google_mode_v2);
            update_option('cookie_consent_audit_log', $cookie_consent_audit_log);
            update_option('cookie_consent_post_id', $cookie_consent_post_id);
            update_option('cookie_consent_btn_accept', $cookie_consent_btn_accept);
            update_option('cookie_consent_btn_reject', $cookie_consent_btn_reject);
            update_option('post_allowed_template_tags', $post_allowed_template_tags);
        } elseif ($current_tab === 'writing') {
            // 更新撰写/上传设置
            update_option('default_category', $default_category);
            update_option('enable_upload', $enable_upload);
            update_option('allowed_file_types', $allowed_file_types);
            update_option('max_upload_size', $max_upload_size);
            update_option('media_modal_notice_rules', isset($_POST['media_modal_notice_rules']) ? trim($_POST['media_modal_notice_rules']) : '');

            // 更新 CDN 与云存储配置
            $storage_provider = isset($_POST['storage_provider']) ? trim($_POST['storage_provider']) : 'local';
            update_option('storage_provider', $storage_provider);
            update_option('cdn_enabled', isset($_POST['cdn_enabled']) ? '1' : '0');
            $cdn_domain = isset($_POST['cdn_domain']) ? rtrim(trim($_POST['cdn_domain']), '/') : '';
            if ($cdn_domain !== '' && !preg_match('#^https?://#i', $cdn_domain)) {
                $cdn_domain = 'http://' . $cdn_domain;
            }
            update_option('cdn_domain', $cdn_domain);
            update_option('cdn_scope_uploads', isset($_POST['cdn_scope_uploads']) ? '1' : '0');
            update_option('cdn_scope_themes', isset($_POST['cdn_scope_themes']) ? '1' : '0');
            update_option('storage_qiniu_ak', isset($_POST['storage_qiniu_ak']) ? trim($_POST['storage_qiniu_ak']) : '');
            update_option('storage_qiniu_sk', isset($_POST['storage_qiniu_sk']) ? trim($_POST['storage_qiniu_sk']) : '');
            update_option('storage_qiniu_bucket', isset($_POST['storage_qiniu_bucket']) ? trim($_POST['storage_qiniu_bucket']) : '');
            update_option('storage_qiniu_domain', isset($_POST['storage_qiniu_domain']) ? rtrim(trim($_POST['storage_qiniu_domain']), '/') : '');
            update_option('storage_aliyun_ak', isset($_POST['storage_aliyun_ak']) ? trim($_POST['storage_aliyun_ak']) : '');
            update_option('storage_aliyun_sk', isset($_POST['storage_aliyun_sk']) ? trim($_POST['storage_aliyun_sk']) : '');
            update_option('storage_aliyun_bucket', isset($_POST['storage_aliyun_bucket']) ? trim($_POST['storage_aliyun_bucket']) : '');
            update_option('storage_aliyun_endpoint', isset($_POST['storage_aliyun_endpoint']) ? trim($_POST['storage_aliyun_endpoint']) : '');
            update_option('storage_aliyun_domain', isset($_POST['storage_aliyun_domain']) ? rtrim(trim($_POST['storage_aliyun_domain']), '/') : '');
            update_option('storage_tencent_secret_id', isset($_POST['storage_tencent_secret_id']) ? trim($_POST['storage_tencent_secret_id']) : '');
            update_option('storage_tencent_secret_key', isset($_POST['storage_tencent_secret_key']) ? trim($_POST['storage_tencent_secret_key']) : '');
            update_option('storage_tencent_bucket', isset($_POST['storage_tencent_bucket']) ? trim($_POST['storage_tencent_bucket']) : '');
            update_option('storage_tencent_region', isset($_POST['storage_tencent_region']) ? trim($_POST['storage_tencent_region']) : '');
            update_option('storage_tencent_domain', isset($_POST['storage_tencent_domain']) ? rtrim(trim($_POST['storage_tencent_domain']), '/') : '');
            update_option('storage_keep_local', isset($_POST['storage_keep_local']) ? '1' : '0');

            // 处理高危/敏感文件超时设置 (sql, html, htm, css, js, php 等)
            $sensitive_exts = class_exists('TodoUploadConfig') ? TodoUploadConfig::get_sensitive_extensions() : ['sql', 'html', 'htm', 'css', 'js', 'php'];
            $ext_list_save = array_filter(array_map('trim', explode(',', (string)$allowed_file_types)));
            $has_sensitive_save = !empty(array_intersect($ext_list_save, $sensitive_exts));
            if ($has_sensitive_save) {
                // 如果管理员主观勾选了高危/敏感格式，设置 1 小时 (3600秒) 有效期，任务完成后超时自动关闭
                update_option('sensitive_upload_expires', time() + 3600);
            } else {
                update_option('sensitive_upload_expires', 0);
            }

            // 同步上传配置到 TodoUploadConfig（单位字节）+ 同步MIME白名单
            if (class_exists('TodoUploadConfig')) {
                $max_bytes = max(0, (int)$max_upload_size) * 1024 * 1024; // MB → bytes
                $mime_whitelist = [];
                // 将扩展名列表转换为MIME类型白名单
                $ext_list = array_filter(array_map('trim', explode(',', (string)$allowed_file_types)));
                if (!empty($ext_list)) {
                    foreach ($ext_list as $ext) {
                        $ext = strtolower(ltrim($ext, '.'));
                        $mime = function_exists('todo_get_mime_type_from_extension') ? todo_get_mime_type_from_extension($ext) : null;
                        if (!$mime && function_exists('get_mime_type')) {
                            // 注意：get_mime_type基于文件路径，这里不适用，只保底
                            $mime = null;
                        }
                        if (empty($mime)) {
                            // 基础映射补充
                            $map = [
                                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                                'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                'txt' => 'text/plain', 'csv' => 'text/csv',
                                'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
                                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
                                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime', 'wmv' => 'video/x-ms-wmv'
                            ];
                            $mime = $map[$ext] ?? null;
                        }
                        if ($mime) {
                            $mime_whitelist[] = $mime;
                        }
                    }
                }
                if (empty($mime_whitelist)) {
                    // 如果为空不覆盖已有白名单，保持系统安全
                    TodoUploadConfig::update_config('max_file_size', $max_bytes);
                } else {
                    // 精准同步：用扩展名列表映射出的MIME白名单覆盖配置（避免“扩展名允许但MIME不在白名单”的困惑）
                    TodoUploadConfig::update_config([
                        'max_file_size' => $max_bytes,
                        'allowed_types' => array_values(array_unique($mime_whitelist))
                    ]);
                }
            }
        } elseif ($current_tab === 'permalink') {
            // 更新固定链接设置（支持自定义结构 + 记忆自定义值）
            $is_custom = ($permalink_structure === 'custom');
            $structure = $permalink_structure;
            if ($is_custom) {
                // 使用自定义字段并清理
                $structure = sanitize_text_field($permalink_structure_custom);
                if ($structure !== '' && strpos($structure, '/') !== 0) {
                    $structure = '/' . $structure;
                }
                // 记忆自定义结构，便于切换到其他结构后仍可回显
                update_option('permalink_structure_custom', $structure);
                // 当前请求内也保持回显
                $permalink_structure_custom = $structure;
            } else {
                // 非自定义时不覆盖记忆，如需回显，读取已保存的自定义值
                if (empty($permalink_structure_custom)) {
                    $permalink_structure_custom = get_option('permalink_structure_custom', '');
                }
            }
            set_permalink_structure($structure);
            // 使页面回显使用实际保存的结构（避免显示 custom）
            $permalink_structure = $structure;
        } elseif ($current_tab === 'discussion') {
            // 更新讨论设置
            update_option('comments_per_page', $comments_per_page);
            update_option('default_comment_status', $default_comment_status);
            update_option('comment_moderation', $comment_moderation);
        } elseif ($current_tab === 'users') {
            // 更新用户设置
            update_option('users_can_register', $users_can_register);
            update_option('default_role', $default_role);
            update_option('mobile_login_enabled', $mobile_login_enabled ? '1' : '0');
            update_option('sms_provider', $sms_provider);
            update_option('sms_aliyun_access_key_id', $sms_aliyun_access_key_id);
            update_option('sms_aliyun_access_key_secret', $sms_aliyun_access_key_secret);
            update_option('sms_aliyun_sign_name', $sms_aliyun_sign_name);
            update_option('sms_aliyun_template_code', $sms_aliyun_template_code);
            update_option('sms_aliyun_endpoint', $sms_aliyun_endpoint);
            update_option('sms_aliyun_region', $sms_aliyun_region);
            update_option('sms_tencent_secret_id', $sms_tencent_secret_id);
            update_option('sms_tencent_secret_key', $sms_tencent_secret_key);
            update_option('sms_tencent_app_id', $sms_tencent_app_id);
            update_option('sms_tencent_sign_name', $sms_tencent_sign_name);
            update_option('sms_tencent_template_id', $sms_tencent_template_id);
            update_option('sms_tencent_endpoint', $sms_tencent_endpoint);
            update_option('sms_tencent_region', $sms_tencent_region);
            update_option('qq_login_enabled', $qq_login_enabled ? '1' : '0');
            update_option('qq_app_id', $qq_app_id);
            update_option('qq_app_key', $qq_app_key);
            update_option('qq_redirect_uri', $qq_redirect_uri);
            update_option('todo_auto_rotate_admin_entry', $todo_auto_rotate_admin_entry);
            
            // 处理后台登录路径更改
            if (!empty($site_adminbasefile) && $site_adminbasefile !== $site_adminbasefile_old) {
                $rename_result = todo_rename_admin_entry_file($site_adminbasefile_old, $site_adminbasefile);
                if ($rename_result['success']) {
                    update_option('site_adminbasefile', $site_adminbasefile);
                    $message = '设置已保存。后台登录路径已更新为：' . $site_adminbasefile . '.php';
                } else {
                    $errors[] = '后台登录路径更新失败：' . $rename_result['message'];
                }
            }
        }
        
        // 如果是网站信息表单提交，更新网站信息相关字段
        if (isset($_POST['action']) && $_POST['action'] === 'update_site_info') {
            // 将特色图片组件提交的JSON字符串解析为数组后保存
            if (is_string($site_logo)) {
                $decoded = json_decode($site_logo, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $site_logo = $decoded;
                }
            }
            if (function_exists('todo_media_url_to_relative')) {
                if (is_array($site_logo)) {
                    foreach ($site_logo as $k => $item) {
                        if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                            $site_logo[$k]['url'] = todo_media_url_to_relative($item['url']);
                        }
                    }
                } elseif (is_string($site_logo)) {
                    $site_logo = todo_media_url_to_relative($site_logo);
                }
                if (is_string($site_favicon)) {
                    $decoded_fav = json_decode($site_favicon, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $site_favicon = $decoded_fav;
                    }
                }
                if (is_array($site_favicon)) {
                    foreach ($site_favicon as $k => $item) {
                        if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                            $site_favicon[$k]['url'] = todo_media_url_to_relative($item['url']);
                        }
                    }
                } elseif (is_string($site_favicon) && $site_favicon !== '') {
                    $site_favicon = todo_media_url_to_relative($site_favicon);
                }
            }
            update_option('site_logo', $site_logo);
            update_option('site_favicon', $site_favicon);
            update_option('site_copyright', $site_copyright);
            update_option('site_icp', $site_icp);
            update_option('site_analytics', $site_analytics);
        }
        
        // 如果更改了时区，更新PHP时区设置
        if ($timezone) {
            date_default_timezone_set($timezone);
        }
        
        // PRG 重定向
        $notice_message = isset($message) && $message !== '' ? $message : '设置已保存。';
        if (function_exists('todo_add_admin_notice')) {
            todo_add_admin_notice($notice_message, 'success');
        }
        $redirect_url = todo_admin_url('index.php?page=settings&tab=' . $current_tab);

        if ($is_ajax_request) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                'success' => true,
                'redirect' => $redirect_url
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 使用JS重定向以确保iframe兼容性
        if (function_exists('todo_is_iframe_mode') && todo_is_iframe_mode()) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            echo "<script type=\"text/javascript\">window.location.href = '$redirect_url';</script>";
        } else {
            todo_redirect($redirect_url);
        }
        exit;
    }

    if ($is_ajax_request) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            'success' => false,
            'message' => implode("\n", $errors),
            'errors' => $errors
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // 获取当前设置
    $site_title = get_option('site_title', 'TodoCMS');
    $site_description = get_option('site_description', '基于PHP的内容管理系统');
    $site_url = get_option('site_url', todo_get_site_url());
    $admin_email = get_option('admin_email', 'admin@example.com');
    $posts_per_page = get_option('posts_per_page', 10);
    $date_format = get_option('date_format', 'Y-m-d');
    $time_format = get_option('time_format', 'H:i');
    $timezone = get_option('timezone', 'UTC');
    $permalink_structure = get_option('permalink_structure', '');
    // 记忆自定义固定链接结构：当当前结构为系统预设之一时，单独读取已保存的自定义结构以便回显
    $default_structures = array('', '/%year%/%monthnum%/%day%/%postname%/', '/%year%/%monthnum%/%postname%/', '/%postname%/', '/archives/%post_id%');
    $permalink_structure_custom = get_option('permalink_structure_custom', '');
    if ($permalink_structure_custom === '' && !in_array($permalink_structure, $default_structures)) {
        // 若当前就是自定义结构但尚未单独存储，则用当前结构作为初始自定义结构
        $permalink_structure_custom = $permalink_structure;
    }
    $comments_per_page = get_option('comments_per_page', 20);
    $default_comment_status = get_option('default_comment_status', 'open');
    $comment_moderation = (int) get_option('comment_moderation', 0);
    $users_can_register = get_option('users_can_register', false);
    $default_role = get_option('default_role', 'subscriber');
    $mobile_login_enabled = (bool) get_option('mobile_login_enabled', '0');
    $sms_provider = get_option('sms_provider', 'aliyun');
    $sms_aliyun_access_key_id = get_option('sms_aliyun_access_key_id', '');
    $sms_aliyun_access_key_secret = get_option('sms_aliyun_access_key_secret', '');
    $sms_aliyun_sign_name = get_option('sms_aliyun_sign_name', '');
    $sms_aliyun_template_code = get_option('sms_aliyun_template_code', '');
    $sms_aliyun_endpoint = get_option('sms_aliyun_endpoint', '');
    $sms_aliyun_region = get_option('sms_aliyun_region', '');
    $sms_tencent_secret_id = get_option('sms_tencent_secret_id', '');
    $sms_tencent_secret_key = get_option('sms_tencent_secret_key', '');
    $sms_tencent_app_id = get_option('sms_tencent_app_id', '');
    $sms_tencent_sign_name = get_option('sms_tencent_sign_name', '');
    $sms_tencent_template_id = get_option('sms_tencent_template_id', '');
    $sms_tencent_endpoint = get_option('sms_tencent_endpoint', '');
    $sms_tencent_region = get_option('sms_tencent_region', '');
    $qq_login_enabled = (bool) get_option('qq_login_enabled', '0');
    $qq_app_id = get_option('qq_app_id', '');
    $qq_app_key = get_option('qq_app_key', '');
    $qq_redirect_uri = get_option('qq_redirect_uri', '');
    $default_category = (int) get_option('default_category', 0);

    // 获取上传设置
    $enable_upload = get_option('enable_upload', true);
    $allowed_file_types = get_option('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,txt,zip');
    $max_upload_size = get_option('max_upload_size', 10);

    // 获取 CDN 与云存储设置
    $storage_provider = get_option('storage_provider', 'local');
    $cdn_enabled = get_option('cdn_enabled', '0');
    $cdn_domain = get_option('cdn_domain', '');
    $cdn_scope_uploads = get_option('cdn_scope_uploads', '1');
    $cdn_scope_themes = get_option('cdn_scope_themes', '1');
    $storage_qiniu_ak = get_option('storage_qiniu_ak', '');
    $storage_qiniu_sk = get_option('storage_qiniu_sk', '');
    $storage_qiniu_bucket = get_option('storage_qiniu_bucket', '');
    $storage_qiniu_domain = get_option('storage_qiniu_domain', '');
    $storage_aliyun_ak = get_option('storage_aliyun_ak', '');
    $storage_aliyun_sk = get_option('storage_aliyun_sk', '');
    $storage_aliyun_bucket = get_option('storage_aliyun_bucket', '');
    $storage_aliyun_endpoint = get_option('storage_aliyun_endpoint', '');
    $storage_aliyun_domain = get_option('storage_aliyun_domain', '');
    $storage_tencent_secret_id = get_option('storage_tencent_secret_id', '');
    $storage_tencent_secret_key = get_option('storage_tencent_secret_key', '');
    $storage_tencent_bucket = get_option('storage_tencent_bucket', '');
    $storage_tencent_region = get_option('storage_tencent_region', '');
    $storage_tencent_domain = get_option('storage_tencent_domain', '');
    $storage_keep_local = get_option('storage_keep_local', '0');

    $site_logo_raw = get_option('site_logo', '');
    if (is_string($site_logo_raw) && $site_logo_raw !== '') {
        if (strpos($site_logo_raw, '[{') === 0 || strpos($site_logo_raw, '{') === 0) {
            $decoded_logo = json_decode($site_logo_raw, true);
            $site_logo_images = is_array($decoded_logo) ? $decoded_logo : array(array('url' => $site_logo_raw));
        } else {
            $site_logo_images = array(array('url' => $site_logo_raw));
        }
    } elseif (is_array($site_logo_raw)) {
        $site_logo_images = $site_logo_raw;
    } else {
        $site_logo_images = array();
    }

    $site_favicon_raw = get_option('site_favicon', '');
    if (is_string($site_favicon_raw) && $site_favicon_raw !== '') {
        if (strpos($site_favicon_raw, '[{') === 0 || strpos($site_favicon_raw, '{') === 0) {
            $decoded_fav = json_decode($site_favicon_raw, true);
            $site_favicon_images = is_array($decoded_fav) ? $decoded_fav : array(array('url' => $site_favicon_raw));
        } else {
            $site_favicon_images = array(array('url' => $site_favicon_raw));
        }
    } elseif (is_array($site_favicon_raw)) {
        $site_favicon_images = $site_favicon_raw;
    } else {
        $site_favicon_images = array();
    }
    $site_keywords = get_option('site_keywords', '');
    $site_copyright = get_option('site_copyright', '');
    $site_icp = get_option('site_icp', '');
    $site_analytics = get_option('site_analytics', '');
}

if (!isset($site_logo_images)) {
    $site_logo_images = array();
    if (isset($site_logo) && $site_logo !== '') {
        if (is_string($site_logo)) {
            $decoded_for_view = json_decode($site_logo, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_for_view)) {
                $site_logo_images = $decoded_for_view;
            } else {
                $site_logo_images = array(array('url' => $site_logo));
            }
        } elseif (is_array($site_logo)) {
            $site_logo_images = $site_logo;
        }
    }
    if (empty($site_logo_images)) {
        $site_logo_raw_for_view = get_option('site_logo', '');
        if (is_string($site_logo_raw_for_view) && $site_logo_raw_for_view !== '') {
            if (strpos($site_logo_raw_for_view, '[{') === 0 || strpos($site_logo_raw_for_view, '{') === 0) {
                $decoded_v = json_decode($site_logo_raw_for_view, true);
                $site_logo_images = is_array($decoded_v) ? $decoded_v : array(array('url' => $site_logo_raw_for_view));
            } else {
                $site_logo_images = array(array('url' => $site_logo_raw_for_view));
            }
        } elseif (is_array($site_logo_raw_for_view)) {
            $site_logo_images = $site_logo_raw_for_view;
        }
    }
}

if (!isset($site_favicon_images)) {
    $site_favicon_images = array();
    if (isset($site_favicon) && $site_favicon !== '') {
        if (is_string($site_favicon)) {
            $decoded_for_view = json_decode($site_favicon, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_for_view)) {
                $site_favicon_images = $decoded_for_view;
            } else {
                $site_favicon_images = array(array('url' => $site_favicon));
            }
        } elseif (is_array($site_favicon)) {
            $site_favicon_images = $site_favicon;
        }
    }
    if (empty($site_favicon_images)) {
        $site_favicon_raw_for_view = get_option('site_favicon', '');
        if (is_string($site_favicon_raw_for_view) && $site_favicon_raw_for_view !== '') {
            if (strpos($site_favicon_raw_for_view, '[{') === 0 || strpos($site_favicon_raw_for_view, '{') === 0) {
                $decoded_v = json_decode($site_favicon_raw_for_view, true);
                $site_favicon_images = is_array($decoded_v) ? $decoded_v : array(array('url' => $site_favicon_raw_for_view));
            } else {
                $site_favicon_images = array(array('url' => $site_favicon_raw_for_view));
            }
        } elseif (is_array($site_favicon_raw_for_view)) {
            $site_favicon_images = $site_favicon_raw_for_view;
        }
    }
}

// 获取可用的时区列表
$timezone_identifiers = DateTimeZone::listIdentifiers();

// 获取可用的用户角色
$roles = array(
    'administrator' => '管理员',
    'editor' => '编辑',
    'author' => '作者',
    'subscriber' => '订阅者'
);

// 获取当前活动的标签页（提交后优先使用POST保持当前标签不跳回）
$active_tab = isset($_POST['tab']) ? $_POST['tab'] : (isset($_GET['tab']) ? $_GET['tab'] : 'general');

// 计算当前有效的最大上传上限（受PHP限制影响）
$todo_effective_upload_limit = '';
if (class_exists('TodoUploadConfig')) {
    $__limit_bytes = TodoUploadConfig::get_max_upload_size();
    $todo_effective_upload_limit = TodoUploadConfig::format_file_size($__limit_bytes);
}
?>

<div class="admin-settings-page">    
    <?php if (!empty($errors)): ?>
        <div class="notice notice-error is-dismissible">
            <?php foreach ($errors as $error): ?>
                <p><?php echo $error; ?></p>
            <?php endforeach; ?>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>
    
    <div class="admin-settings-tabs">
        <ul class="admin-tabs">
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=general'); ?>" class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>">
                    常规
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=site-info'); ?>" class="<?php echo $active_tab === 'site-info' ? 'active' : ''; ?>">
                    版权
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=reading'); ?>" class="<?php echo $active_tab === 'reading' ? 'active' : ''; ?>">
                    阅读
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=writing'); ?>" class="<?php echo $active_tab === 'writing' ? 'active' : ''; ?>">
                    撰写
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=discussion'); ?>" class="<?php echo $active_tab === 'discussion' ? 'active' : ''; ?>">
                    讨论
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=permalink'); ?>" class="<?php echo $active_tab === 'permalink' ? 'active' : ''; ?>">
                    固定链接
                </a>
            </li>
            <li>
                <a href="<?php echo todo_admin_url('index.php?page=settings&tab=users'); ?>" class="<?php echo $active_tab === 'users' ? 'active' : ''; ?>">
                    用户
                </a>
            </li>
        </ul>
    </div>
    
    <form method="post" action="<?php echo todo_admin_url('index.php?page=settings&tab=' . $active_tab); ?>" class="admin-settings-form todo-request">
        <input type="hidden" name="action" value="<?php echo ($active_tab === 'site-info') ? 'update_site_info' : 'update_settings'; ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">
        <?php echo Todo_Security_Manager::csrf_field('settings_nonce', '_todononce'); ?>
        
        <?php if ($active_tab === 'general'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">常规设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label for="site-title">网站标题</label>
                        <input type="text" name="site_title" id="site-title" value="<?php echo esc_attr($site_title); ?>" class="regular-text" style="min-width: 100%;">
                        <p class="description">网站标题将显示在浏览器标题栏和主题中。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="site-keywords">网站关键词</label>
                        <input type="text" name="site_keywords" id="site-keywords" value="<?php echo esc_attr($site_keywords); ?>" class="regular-text" style="min-width: 100%;">
                        <p class="description">网站的关键词，用逗号分隔，有助于搜索引擎优化。</p>
                    </div>

                    <div class="form-group">
                        <label for="site-description">网站描述</label>
                        <textarea name="site_description" id="site-description" class="regular-text" style="min-width: 100%;"><?php echo esc_textarea($site_description); ?></textarea>
                        <p class="description">简短的描述，说明您的网站是关于什么的。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="site-url">网站地址 (URL)</label>
                        <input type="url" name="site_url" id="site-url" value="<?php echo esc_attr($site_url); ?>" class="regular-text mw-100">
                        <p class="description">输入您的网站完整URL，包括 http:// 或 https://</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-email">管理员邮箱</label>
                        <input type="email" name="admin_email" id="admin-email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                        <p class="description">此邮箱用于管理通知。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="timezone">时区</label>
                        <select name="timezone" id="timezone">
                            <?php foreach ($timezone_identifiers as $tz): ?>
                                <option value="<?php echo $tz; ?>" <?php selected($timezone, $tz); ?>>
                                    <?php echo $tz; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">选择与您所在位置匹配的国家时区，时区关系到定时文章与定时AD的准确性。</p>
                    </div>
                    
                    <div class="form-group">
                        <h4>日期格式</h4>
                        <fieldset>
                            <label>
                                <input type="radio" name="date_format" value="Y-m-d" <?php checked($date_format, 'Y-m-d'); ?>> 
                                <span><?php echo date('Y-m-d'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="date_format" value="Y/m/d" <?php checked($date_format, 'Y/m/d'); ?>> 
                                <span><?php echo date('Y/m/d'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="date_format" value="d-m-Y" <?php checked($date_format, 'd-m-Y'); ?>> 
                                <span><?php echo date('d-m-Y'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="date_format" value="d/m/Y" <?php checked($date_format, 'd/m/Y'); ?>> 
                                <span><?php echo date('d/m/Y'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="date_format" value="F j, Y" <?php checked($date_format, 'F j, Y'); ?>> 
                                <span><?php echo date('F j, Y'); ?></span>
                            </label>
                            <label style="display: flex; flex-wrap: wrap; gap: 10px 5px; align-items: center;">
                                <input type="radio" name="date_format" value="custom" <?php checked(!in_array($date_format, array('Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'F j, Y'))); ?>> 
                                自定义: <input type="text" name="date_format_custom" value="<?php echo esc_attr($date_format); ?>" class="small-text">
                            </label>
                            <p class="description">预览: <span id="date-format-preview"><?php echo date($date_format); ?></span></p>
                        </fieldset>
                    </div>
                    
                    <div class="form-group">
                        <h4>时间格式</h4>
                        <fieldset>
                            <label>
                                <input type="radio" name="time_format" value="H:i" <?php checked($time_format, 'H:i'); ?>> 
                                <span><?php echo date('H:i'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="time_format" value="H:i:s" <?php checked($time_format, 'H:i:s'); ?>> 
                                <span><?php echo date('H:i:s'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="time_format" value="g:i a" <?php checked($time_format, 'g:i a'); ?>> 
                                <span><?php echo date('g:i a'); ?></span>
                            </label>
                            <label>
                                <input type="radio" name="time_format" value="g:i A" <?php checked($time_format, 'g:i A'); ?>> 
                                <span><?php echo date('g:i A'); ?></span>
                            </label>
                            <label style="display: flex; flex-wrap: wrap; gap: 10px 5px; align-items: center;">
                                <input type="radio" name="time_format" value="custom" <?php checked(!in_array($time_format, array('H:i', 'H:i:s', 'g:i a', 'g:i A'))); ?>> 
                                自定义: <input type="text" name="time_format_custom" value="<?php echo esc_attr($time_format); ?>" class="small-text">
                            </label>
                            <p class="description">预览: <span id="time-format-preview"><?php echo date($time_format); ?></span></p>
                        </fieldset>
                    </div>
                </div>
            </div>
        <?php elseif ($active_tab === 'reading'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">阅读设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label>页面访问</label>
                        <fieldset>
                            <?php $___pm = get_option('page_mode', 'dynamic'); ?>
                            <label>
                                <input type="radio" name="page_mode" value="dynamic" <?php checked($___pm, 'dynamic'); ?>> 动态开发模式
                            </label>
                            <p class="description m-b-10">
                                动态开发模式：适合开发调试与频繁改版场景。<br>
                            </p>
                            <div class="form-group" id="dynamic-ttl-group" style="margin: 5px 0 20px 20px;<?php echo ($___pm === 'dynamic') ? '' : ' display:none;'; ?>">
                                <label for="dynamic-template-cache-ttl">动态模板微缓存TTL（0、30-3600秒）</label>
                                <?php $___ttl = (int) get_option('dynamic_template_cache_ttl', 120); ?>
                                <input type="number" name="dynamic_template_cache_ttl" id="dynamic-template-cache-ttl" value="<?php echo esc_attr(($___ttl === 0) ? 0 : max(30, min(3600, $___ttl))); ?>" class="small-text" min="0" max="3600">
                                <p class="description">仅动态开发模式下生效：为模板解析、广告与Tag解析等提供微缓存，0表示禁用缓存；设置为较大值可显著降低热点页面耗时。</p>
                            </div>
                            <script type="text/javascript">
                            (function(){
                                function toggleTTL(){
                                    var g=document.getElementById('dynamic-ttl-group');
                                    if(!g)return;
                                    var pm=document.querySelector('input[name="page_mode"]:checked');
                                    g.style.display=(pm&&pm.value==='dynamic')?'':'none';
                                }
                                document.addEventListener('change',function(e){
                                    if(e.target&&e.target.name==='page_mode'){toggleTTL();}
                                },true);
                                document.addEventListener('DOMContentLoaded',toggleTTL);
                            })();
                            </script>
                            <label>
                                <input type="radio" name="page_mode" value="static" <?php checked($___pm, 'static'); ?>> 静态缓存模式
                            </label>
                            <p class="description">
                                静态缓存模式：启用模板缓存，加速前台渲染利于SEO收录，更新数据后执行“清除缓存”前台才会展示，管理员可以在URL中添加参数?todocache=refresh来刷新缓存。
                            </p>
                        </fieldset>
                    </div>
                    
                    <div class="form-group">
                        <label for="posts-per-page">每页显示的文章数</label>
                        <input type="number" name="posts_per_page" id="posts-per-page" value="<?php echo esc_attr($posts_per_page); ?>" class="small-text" min="1">
                    </div>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">Cookie 合规设置 (GDPR / ePrivacy)</h3>
                    </div>
                    <div class="form-group">
                        <?php
                        $cookie_consent_enabled = get_option('cookie_consent_enabled', '0');
                        $cookie_consent_google_mode_v2 = get_option('cookie_consent_google_mode_v2', '1');
                        $cookie_consent_audit_log = get_option('cookie_consent_audit_log', '1');
                        $cookie_consent_post_id = get_option('cookie_consent_post_id', '');
                        $cookie_consent_btn_accept = get_option('cookie_consent_btn_accept');
                        $cookie_consent_btn_reject = get_option('cookie_consent_btn_reject');
                        ?>
                        <label style="font-weight: 600;">
                            <input type="checkbox" name="cookie_consent_enabled" value="1" <?php checked($cookie_consent_enabled, '1'); ?> onchange="document.getElementById('cookie-consent-options-wrapper').style.display = this.checked ? 'block' : 'none';"> 启用 Cookie 合规同意弹窗
                        </label>
                    </div>

                    <div id="cookie-consent-options-wrapper" style="display: <?php echo $cookie_consent_enabled === '1' ? 'block' : 'none'; ?>; margin-bottom: 15px; border-left: 5px solid #eceef1; padding: 10px 20px 5px; background-color: #f9fafd;">
                        <p class="description m-t-0 m-b-10" style="color: #2563eb;">出海欧盟必备红线要求：用户未明确同意前默认关闭非必要 Cookie，防止 GDPR 巨额罚款与谷歌/广告下架风险。</p>
                        <div class="form-group">
                            <label style="font-weight: 600;">
                                <input type="checkbox" name="cookie_consent_google_mode_v2" value="1" <?php checked($cookie_consent_google_mode_v2, '1'); ?>> 接入 Google Consent Mode v2 (谷歌强制规范)
                            </label>
                            <p class="description">在 <code>&lt;head&gt;</code> 默认注入 <code>gtag('consent', 'default', {ad_storage:'denied', analytics_storage:'denied'...})</code>，并在用户同意后动态更新。为 2024 年起 Google 广告与 GA4 出海强制要求。</p>
                        </div>

                        <div class="form-group">
                            <label style="font-weight: 600;">
                                <input type="checkbox" name="cookie_consent_audit_log" value="1" <?php checked($cookie_consent_audit_log, '1'); ?>> 开启 GDPR 同意日志存证 (Consent Audit Log)
                            </label>
                            <p class="description">开启后将遵循 GDPR Article 4 规范对用户 IP 进行脱敏/匿名化处理，并自动记录授权同意日志，以备欧盟 DPA 审计调取举证。</p>
                        </div>

                        <div class="form-group">
                            <label for="cookie_consent_post_id">条款与说明（Post ID）</label>
                            <input type="number" name="cookie_consent_post_id" id="cookie_consent_post_id" value="<?php echo esc_attr($cookie_consent_post_id); ?>" class="small-text" min="0" placeholder="例如 12">
                            <p class="description">请输入包含 Cookie 条款与隐私说明的 Post ID。弹窗标题与内容将直接读取该文章。</p>
                        </div>

                        <div class="form-group">
                            <label for="cookie_consent_btn_accept">“接受全部”按钮文本</label>
                            <input type="text" name="cookie_consent_btn_accept" id="cookie_consent_btn_accept" value="<?php echo esc_attr($cookie_consent_btn_accept); ?>" class="regular-text">
                            <p class="description">支持多语言语法标识输入，如：<code>[zh|接受全部][en|Accept All]</code>。</p>
                        </div>

                        <div class="form-group">
                            <label for="cookie_consent_btn_reject">“拒绝非必要”按钮文本</label>
                            <input type="text" name="cookie_consent_btn_reject" id="cookie_consent_btn_reject" value="<?php echo esc_attr($cookie_consent_btn_reject); ?>" class="w-100 regular-text">
                            <p class="description">支持多语言语法标识输入，如：<code>[zh|拒绝非必要][en|Reject Non-Essential]</code>。根据 EDPB 指引，拒绝按钮必须与接受按钮处于同等显眼位置。</p>
                        </div>

                        <?php
                        global $tododb;
                        $log_table = TABLE_PREFIX . 'cookie_consent_logs';
                        $recent_logs = [];
                        if ($tododb) {
                            $check_table = $tododb->get_var("SHOW TABLES LIKE '{$log_table}'");
                            if ($check_table) {
                                $recent_logs = $tododb->get_results("SELECT * FROM {$log_table} ORDER BY id DESC LIMIT 10", ARRAY_A);
                            }
                        }
                        ?>
                        <div class="form-group">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                GDPR 同意存证日志 (前 10 条)
                                <div style="display: flex; gap: 8px;">
                                    <a href="<?php echo todo_admin_url('ajax/cookie-log-operations.php?action=export_csv'); ?>" class="btn" style="display: inline-flex; align-items: center; gap: 6px;" target="_blank">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        导出存证日志 (CSV)</a>
                                    <button type="button" class="btn" style="background: none; display: inline-flex; align-items: center; gap: 6px;" onclick="todoPruneGdprLogs()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        清理 1 年前日志</button>
                                </div>
                            </div>
                            <?php if (!empty($recent_logs)): ?>
                                <div style="max-height: 220px; overflow-y: auto; margin-top: 4px;">
                                    <table class="admin-table widefat fixed striped" style="font-size: 12px; margin: 0; border: none;">
                                        <thead>
                                            <tr>
                                                <th style="width: 60px;">ID</th>
                                                <th style="width: 140px;">匿名 IP</th>
                                                <th>授权选项</th>
                                                <th style="width: 150px;">时间</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_logs as $log): ?>
                                                <tr>
                                                    <td>#<?php echo intval($log['id']); ?></td>
                                                    <td><code><?php echo esc_html($log['ip_address']); ?></code></td>
                                                    <td><span style="word-break: break-all; color: #475569;"><?php echo esc_html($log['consent_choice']); ?></span></td>
                                                    <td><?php echo esc_html($log['created_at']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="description" style="margin-top: 5px;">暂无存证记录。当开启存证且前台用户点击 Cookie 同意弹窗时，将自动在此记录举证数据。</p>
                            <?php endif; ?>
                            <p class="description" style="margin-top: 10px;">
                                <b style="color: #2563eb;">合规提示：</b>您可以在隐私政策或 Cookie 政策文章内容中直接插入标签 <code>{{todo:cookie_table}}</code>，系统将为用户自动渲染最新的 Cookie 分类明细表格。
                            </p>
                            <p class="description" style="margin-top: 20px;">
                                <b style="color: #2563eb;">第三方脚本的规范管理：</b><br>如果未来站长在后台“自定义头部代码/统计代码”中手工粘贴第三方统计（如 Facebook Pixel、Hotjar 等），建议提醒站长将其包裹在 <code>&lt;script type="text/plain" data-cookie-category="marketing"&gt;</code> 中，或者通过 Google Tag Manager (GTM) 统一托管，以确保其受到前端 Cookie Consent 的前置阻断管控。
                            </p>
                        </div>
                        <script>
                        function todoPruneGdprLogs() {
                            var doPrune = function() {
                                var formData = new FormData();
                                formData.append('action', 'prune_logs');
                                formData.append('days', '365');
                                fetch('<?php echo todo_admin_url('ajax/cookie-log-operations.php'); ?>', {
                                    method: 'POST',
                                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                    body: formData
                                }).then(function(r){ return r.json(); }).then(function(res) {
                                    var msg = res.message || '操作完成';
                                    if (typeof TodoModal !== 'undefined') {
                                        if (res.success) {
                                            TodoModal.success(msg, {
                                                onConfirm: function() { window.location.reload(); }
                                            });
                                        } else {
                                            TodoModal.error(msg);
                                        }
                                    }
                                }).catch(function() {
                                    if (typeof TodoModal !== 'undefined') {
                                        TodoModal.error('请求失败');
                                    }
                                });
                            };

                            var confirmMessage = '确定要清理 365 天以前的历史 Cookie 同意日志吗？根据 GDPR 数据最小化原则，过期的同意日志应定期清理。';
                            if (typeof TodoModal !== 'undefined') {
                                TodoModal.deleteConfirm({
                                    title: '清理确认',
                                    message: confirmMessage,
                                    confirmText: '确认清理',
                                    onConfirm: doPrune
                                });
                            } else {
                                if (confirm(confirmMessage)) {
                                    doPrune();
                                }
                            }
                        }
                        </script>
                    </div>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">其他配置</h3>
                    </div>
                    <div class="form-group">
                        <?php
                        $post_allowed_template_tags = get_option('post_allowed_template_tags', '{{todo:cookie_table}}');
                        ?>
                        <label for="post_allowed_template_tags">正文特殊解析标签</label>
                        <textarea name="post_allowed_template_tags" id="post_allowed_template_tags" rows="3" class="large-text code w-100" placeholder="{{todo:cookie_table}},{{todo:page_title}},{{todo:the_custom_logo}}"><?php echo esc_textarea($post_allowed_template_tags); ?></textarea>
                        <p class="description">
                            允许在文章正文中解析的模板标签，多个标签请使用<strong>英文逗号</strong>分隔。<br>
                        </p>
                    </div>
                </div>
            </div>
        <?php elseif ($active_tab === 'writing'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">撰写设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label>默认文章分类</label>
                        <select name="default_category">
                            <option value="0">未分类</option>
                            <?php
                            $categories = get_categories(array('hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC'));
                            $cat_select_items = [];
                            if (!empty($categories) && is_array($categories)) {
                                $by_parent = [];
                                foreach ($categories as $c) {
                                    if (!is_object($c)) continue;
                                    $tid = isset($c->term_id) ? (int) $c->term_id : 0;
                                    if ($tid <= 0) continue;
                                    $pid = isset($c->parent) ? (int) $c->parent : 0;
                                    if (!isset($by_parent[$pid])) $by_parent[$pid] = [];
                                    $by_parent[$pid][] = $c;
                                }
                                $walker = null;
                                $walker = function ($parent_id, $level) use (&$walker, &$by_parent, &$cat_select_items) {
                                    if (!isset($by_parent[$parent_id])) return;
                                    foreach ($by_parent[$parent_id] as $c) {
                                        $cat_name_item = (string) ($c->name ?? '');
                                        if (function_exists('todo_parse_inline_translations')) {
                                            $cat_name_item = todo_parse_inline_translations($cat_name_item);
                                        }
                                        $cat_select_items[] = [
                                            'term_id' => (int) $c->term_id,
                                            'name' => $cat_name_item,
                                            'level' => (int) $level
                                        ];
                                        $walker((int) $c->term_id, $level + 1);
                                    }
                                };
                                $walker(0, 0);
                            }
                            foreach ($cat_select_items as $item):
                                $selected = ((int) $item['term_id'] === (int) ($default_category ?? 0)) ? ' selected' : '';
                            ?>
                                <option value="<?php echo $item['term_id']; ?>"<?php echo $selected; ?>>
                                    <?php echo esc_html(($item['level'] > 0 ? str_repeat('-', $item['level']) . ' ' : '') . $item['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
            
                    <div class="form-group">
                        <label>文件上传功能</label>
                        <fieldset>
                            <label>
                                <input type="checkbox" id="enable_upload" name="enable_upload" value="1" <?php echo get_option('enable_upload', '1') ? 'checked' : ''; ?>> 启用文件上传功能
                            </label>
                        </fieldset>
                        <p class="description">关闭此选项可以完全禁用文件上传功能，有效防止恶意文件上传</p>
                    </div>
                    
                    <div class="form-group">
                        <label>允许上传的文件类型</label>
                        <?php
                            // 处理高危/敏感脚本超时 (html, htm, css, js, sql, php 等)
                            if (class_exists('TodoUploadConfig')) {
                                TodoUploadConfig::check_sensitive_expiration();
                            }
                            $sensitive_expires = (int)get_option('sensitive_upload_expires', 0);
                            $sensitive_exts = class_exists('TodoUploadConfig') ? TodoUploadConfig::get_sensitive_extensions() : ['sql', 'html', 'htm', 'css', 'js', 'php'];
                            $selected = function_exists('todo_get_allowed_extensions')
                                ? todo_get_allowed_extensions()
                                : (class_exists('TodoUploadConfig') ? TodoUploadConfig::get_allowed_extensions() : []);

                            // 从统一清单生成复选框
                            $mime_map = function_exists('todo_supported_mime_types_flat') ? todo_supported_mime_types_flat() : [];
                            // 分类
                            $groups = [
                                '图片' => [], '视频' => [], '音频' => [], '文档' => [], '压缩包' => [], '其他' => [], '敏感性' => []
                            ];
                            $sensitive_exts_map = array_fill_keys($sensitive_exts, 1);
                            foreach ($mime_map as $ext => $mime) {
                                if (isset($sensitive_exts_map[$ext])) {
                                    $groups['敏感性'][$ext] = $mime;
                                } elseif (strpos($mime, 'image/') === 0) {
                                    $groups['图片'][$ext] = $mime;
                                } elseif (strpos($mime, 'video/') === 0) {
                                    $groups['视频'][$ext] = $mime;
                                } elseif (strpos($mime, 'audio/') === 0) {
                                    $groups['音频'][$ext] = $mime;
                                } elseif (strpos($mime, 'text/') === 0 || strpos($mime, 'application/pdf') === 0 || strpos($mime, 'application/msword') === 0 || strpos($mime, 'application/vnd.openxmlformats-officedocument') === 0 || strpos($mime, 'application/vnd.ms-') === 0) {
                                    $groups['文档'][$ext] = $mime;
                                } elseif (strpos($mime, 'application/zip') === 0 || strpos($mime, 'application/x-rar') === 0 || strpos($mime, 'application/x-7z') === 0 || strpos($mime, 'application/x-tar') === 0 || strpos($mime, 'application/gzip') === 0) {
                                    $groups['压缩包'][$ext] = $mime;
                                } else {
                                    $groups['其他'][$ext] = $mime;
                                }
                            }
                        ?>
                        <div class="mime-checkboxes" style="padding-left: 18px;">
                            <?php foreach ($groups as $group_label => $items): ?>
                                <div class="mime-group">
                                    <span style="min-width: 48px; margin-right: 10px; color: #bbbbc4; float: left;"><?php echo esc_html($group_label); ?></span>
                                    <div class="mime-group-grid flex items-center gap-5 gap-y-0 flex-wrap">
                                        <?php foreach ($items as $ext => $mime): 
                                            $checked = in_array($ext, $selected) ? 'checked' : ''; 
                                            $is_counting = ($sensitive_expires > 0 && isset($sensitive_exts_map[$ext]) && in_array($ext, $selected));
                                        ?>
                                            <label class="flex items-center" style="min-width: 70px;">
                                                <input type="checkbox" name="allowed_file_types_checkbox[]" value="<?php echo esc_attr($ext); ?>" <?php echo $checked; ?>>
                                                <span<?php echo $is_counting ? ' style="color: #e74c3c;"' : ''; ?>><?php echo esc_html(strtolower($ext)); ?></span>
                                                <small class="description hidden"><?php echo esc_html($mime); ?></small>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- 同步到隐藏字段以兼容原有保存逻辑 -->
                        <input type="hidden" name="allowed_file_types" id="allowed_file_types_hidden" value="<?php echo esc_attr(join(',', $selected)); ?>">
                        <?php if ($sensitive_expires > 0): ?>
                            <p class="description" style="color: #e74c3c;">
                                高危/敏感格式上传权限已开启，将在 <?php echo date('H:i:s', $sensitive_expires); ?> (约 <?php echo ceil(($sensitive_expires - time()) / 60); ?> 分钟后) 自动关闭。
                            </p>
                        <?php else: ?>
                            <p class="description" style="color: #666;">
                                高危/敏感类型（如 html, htm, css, js, sql, php 等）勾选后仅保留 1 小时上传时限，超时将自动关闭，无需手动干预。
                            </p>
                        <?php endif; ?>
                        <p class="description">从系统支持的类型清单中勾选允许上传的扩展名。保存后会同步到上传安全配置的MIME白名单，确保“扩展名”和“真实MIME”一致。</p>
                        
                        <script type="text/javascript">
                        (function(){
                            function syncAllowedTypes(){
                                var boxes = document.querySelectorAll('input[name="allowed_file_types_checkbox[]"]');
                                var selected = Array.prototype.slice.call(boxes).filter(function(b){return b.checked;}).map(function(b){return b.value.toLowerCase();});
                                document.getElementById('allowed_file_types_hidden').value = selected.join(',');
                            }
                            document.addEventListener('change', function(e){
                                if (e.target && e.target.name === 'allowed_file_types_checkbox[]') { syncAllowedTypes(); }
                            }, true);
                            document.addEventListener('DOMContentLoaded', syncAllowedTypes);
                        })();
                        </script>

                    </div>
                    
                    <div class="form-group">
                        <label>最大上传文件大小 (MB)</label>
                        <input type="number" name="max_upload_size" value="<?php echo esc_attr(get_option('max_upload_size', '10')); ?>" min="0" max="100">
                        <p class="description">
                            单个文件的最大上传大小限制，单位为MB。
                            <?php if (!empty($todo_effective_upload_limit)): ?>
                                当前有效上限（受PHP限制影响）：<strong><?php echo esc_html($todo_effective_upload_limit); ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="media_modal_notice_rules">媒体模态框提示集</label>
                        <textarea name="media_modal_notice_rules" id="media_modal_notice_rules" class="w-100" rows="4"><?php echo esc_textarea(get_option('media_modal_notice_rules', '')); ?></textarea>
                        <p class="description">
                            格式：<code>[title弹窗标题|id弹窗ID|提示信息,支持html]</code>，一个 <code>[...]</code> 对应一个模态框。<br>
                            匹配规则：优先匹配 <code>title + id</code>，其次匹配 <code>id</code> 或 <code>title</code>。命中的提示信息将嵌入渲染在媒体模态框标题栏中。<br>
                            例如：<code>[选择图片|mail-media-modal|&lt;em style="color:#f60;"&gt;提示：建议上传800x800尺寸&lt;/em&gt;]</code>
                        </p>
                    </div>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">静态资源 CDN 加速设置</h3>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="cdn_enabled" value="1" <?php checked($cdn_enabled, '1'); ?> onchange="document.getElementById('cdn-options-wrapper').style.display = this.checked ? 'block' : 'none';">
                            开启静态资源 CDN 加速
                        </label>
                        <p class="description">开启后，系统在渲染静态资源时将使用指定的 CDN 域名进行分发与加速。</p>
                    </div>

                    <div id="cdn-options-wrapper" style="display: <?php echo $cdn_enabled === '1' ? 'block' : 'none'; ?>; margin-bottom: 15px;border-left: 5px solid #eceef1;padding: 10px 20px 5px;background-color: #f9fafd;">
                        <div class="form-group">
                            <label>CDN 镜像/加速域名</label>
                            <input type="url" name="cdn_domain" value="<?php echo esc_attr($cdn_domain); ?>" placeholder="https://cdn.example.com" class="w-100 regular-text">
                            <p class="description">填写您的 CDN 加速域名，包含 <code>http://</code> 或 <code>https://</code>。留空则默认回退到主站域名。</p>
                        </div>
                        <div class="form-group">
                            <label>CDN 加速范围</label>
                            <label style="display: block; margin-right: 15px;">
                                <input type="checkbox" name="cdn_scope_uploads" value="1" <?php checked($cdn_scope_uploads, '1'); ?>>
                                媒体与上传文件 (<code>/content/uploads/</code>)
                            </label>
                            <label>
                                <input type="checkbox" name="cdn_scope_themes" value="1" <?php checked($cdn_scope_themes, '1'); ?>>
                                主题与公共静态资源 (<code>/content/themes/</code>, <code>/public/</code>)
                            </label>
                            <p class="description">勾选对应目录，系统会将该目录下的资源替换为 CDN 域名解析。</p>
                        </div>
                    </div>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">云存储方案 (第三方对象存储)</h3>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="radio" name="storage_provider" value="local" <?php checked($storage_provider, 'local'); ?> onchange="toggleStorageOptions()"> 
                            本地服务器
                        </label>
                        <label>
                            <input type="radio" name="storage_provider" value="qiniu" <?php checked($storage_provider, 'qiniu'); ?> onchange="toggleStorageOptions()"> 
                            七牛云对象存储 (Kodo)
                        </label>
                        <label>
                            <input type="radio" name="storage_provider" value="aliyun" <?php checked($storage_provider, 'aliyun'); ?> onchange="toggleStorageOptions()"> 
                            阿里云对象存储 (OSS)
                        </label>
                        <label>
                            <input type="radio" name="storage_provider" value="tencent" <?php checked($storage_provider, 'tencent'); ?> onchange="toggleStorageOptions()"> 
                            腾讯云对象存储 (COS)
                        </label>
                        <p class="description">配置全局媒体文件的存储位置，可以分发站点资源降低服务器压力和提高访问速度。</p>
                    </div>

                    <!-- 七牛云配置 -->
                    <div id="storage-qiniu-options" style="margin-bottom: 15px;border-left: 5px solid #eceef1;padding: 10px 20px 5px;background-color: #f9fafd;display: <?php echo $storage_provider === 'qiniu' ? 'block' : 'none'; ?>">
                        <h4>七牛云配置</h4>
                        <div class="form-group">
                            <label>Access Key (AK)</label>
                            <input type="text" name="storage_qiniu_ak" value="<?php echo esc_attr($storage_qiniu_ak); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Secret Key (SK)</label>
                            <input type="password" name="storage_qiniu_sk" value="<?php echo esc_attr($storage_qiniu_sk); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>存储空间 (Bucket)</label>
                            <input type="text" name="storage_qiniu_bucket" value="<?php echo esc_attr($storage_qiniu_bucket); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>绑定域名</label>
                            <input type="text" name="storage_qiniu_domain" value="<?php echo esc_attr($storage_qiniu_domain); ?>" class="w-100 regular-text" placeholder="http://或https://开头" autocomplete="off">
                            <p class="description">外网访问的域名，需包含协议，不能以/结尾。</p>
                        </div>
                    </div>

                    <!-- 阿里云配置 -->
                    <div id="storage-aliyun-options" style="margin-bottom: 15px;border-left: 5px solid #eceef1;padding: 10px 20px 5px;background-color: #f9fafd;display: <?php echo $storage_provider === 'aliyun' ? 'block' : 'none'; ?>">
                        <h4>阿里云 OSS 配置</h4>
                        <div class="form-group">
                            <label>Access Key ID</label>
                            <input type="text" name="storage_aliyun_ak" value="<?php echo esc_attr($storage_aliyun_ak); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Access Key Secret</label>
                            <input type="password" name="storage_aliyun_sk" value="<?php echo esc_attr($storage_aliyun_sk); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>存储空间 (Bucket)</label>
                            <input type="text" name="storage_aliyun_bucket" value="<?php echo esc_attr($storage_aliyun_bucket); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Endpoint</label>
                            <input type="text" name="storage_aliyun_endpoint" value="<?php echo esc_attr($storage_aliyun_endpoint); ?>" class="regular-text" placeholder="如: oss-cn-hangzhou.aliyuncs.com" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>自定义域名</label>
                            <input type="text" name="storage_aliyun_domain" value="<?php echo esc_attr($storage_aliyun_domain); ?>" class="w-100 regular-text" placeholder="留空则使用默认 Endpoint" autocomplete="off">
                            <p class="description">如果有绑定的自定义域名，请带上协议填入此处，不能以/结尾。</p>
                        </div>
                    </div>

                    <!-- 腾讯云 COS 配置 -->
                    <div id="storage-tencent-options" style="margin-bottom: 15px;border-left: 5px solid #eceef1;padding: 10px 20px 5px;background-color: #f9fafd;display: <?php echo $storage_provider === 'tencent' ? 'block' : 'none'; ?>">
                        <h4>腾讯云 COS 配置</h4>
                        <div class="form-group">
                            <label>SecretId</label>
                            <input type="text" name="storage_tencent_secret_id" value="<?php echo esc_attr($storage_tencent_secret_id); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>SecretKey</label>
                            <input type="password" name="storage_tencent_secret_key" value="<?php echo esc_attr($storage_tencent_secret_key); ?>" class="regular-text" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>存储桶 (Bucket)</label>
                            <input type="text" name="storage_tencent_bucket" value="<?php echo esc_attr($storage_tencent_bucket); ?>" class="regular-text" placeholder="格式: BucketName-APPID" autocomplete="off">
                            <p class="description">存储桶名称，需包含 APPID，如 mybucket-1250000000。</p>
                        </div>
                        <div class="form-group">
                            <label>地域 (Region)</label>
                            <input type="text" name="storage_tencent_region" value="<?php echo esc_attr($storage_tencent_region); ?>" class="regular-text" placeholder="如: ap-guangzhou" autocomplete="off">
                            <p class="description">存储桶所在地域代码，如 ap-guangzhou、ap-beijing、ap-shanghai 等。</p>
                        </div>
                        <div class="form-group">
                            <label>自定义域名</label>
                            <input type="text" name="storage_tencent_domain" value="<?php echo esc_attr($storage_tencent_domain); ?>" class="w-100 regular-text" placeholder="留空则使用默认域名" autocomplete="off">
                            <p class="description">如果有绑定的自定义域名（含CDN加速域名），请带上协议填入此处，不能以/结尾。</p>
                        </div>
                    </div>

                    <div class="form-group" id="storage-keep-local-wrapper" style="display: <?php echo $storage_provider !== 'local' ? 'block' : 'none'; ?>;">
                        <label>
                            <input type="checkbox" name="storage_keep_local" value="1" <?php checked($storage_keep_local, '1'); ?>>
                            保存本地副本
                        </label>
                        <p class="description">取消勾选则文件成功上传至第三方云存储后，自动删除本地物理文件，释放磁盘空间。</p>
                    </div>

                    <script type="text/javascript">
                        function toggleStorageOptions() {
                            var providerInputs = document.querySelectorAll('input[name="storage_provider"]');
                            var provider = 'local';
                            for (var i = 0; i < providerInputs.length; i++) {
                                if (providerInputs[i].checked) {
                                    provider = providerInputs[i].value;
                                    break;
                                }
                            }
                            document.getElementById('storage-qiniu-options').style.display = provider === 'qiniu' ? 'block' : 'none';
                            document.getElementById('storage-aliyun-options').style.display = provider === 'aliyun' ? 'block' : 'none';
                            document.getElementById('storage-tencent-options').style.display = provider === 'tencent' ? 'block' : 'none';
                            document.getElementById('storage-keep-local-wrapper').style.display = provider !== 'local' ? 'block' : 'none';
                        }
                    </script>

                </div>
            </div>
        <?php elseif ($active_tab === 'discussion'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">讨论设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label>默认文章评论设置</label>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="default_comment_status" value="open" <?php checked($default_comment_status, 'open'); ?>> 
                                允许他人在新文章上发表评论
                            </label>
                        </fieldset>
                    </div>
                    
                    <div class="form-group">
                        <label for="comments-per-page">每页显示的评论数</label>
                        <input type="number" name="comments_per_page" id="comments-per-page" value="<?php echo esc_attr($comments_per_page); ?>" class="small-text" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label>评论审核</label>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="comment_moderation" value="1" <?php checked($comment_moderation, 1); ?>> 
                                评论必须经人工批准
                            </label>
                        </fieldset>
                    </div>
                </div>
            </div>
        <?php elseif ($active_tab === 'permalink'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">固定链接设置</h3>
                </div>
                <div class="admin-card-content">
                    <div class="form-group">
                        <label>固定链接结构</label>
                        <fieldset>
                            <label>
                                <input type="radio" name="permalink_structure" value="" <?php checked($permalink_structure, ''); ?>> 
                                默认 <code>?p=123</code>
                            </label>
                            <label>
                                <input type="radio" name="permalink_structure" value="/%year%/%monthnum%/%day%/%postname%/" <?php checked($permalink_structure, '/%year%/%monthnum%/%day%/%postname%/'); ?>> 
                                日期和名称型 <code>/2022/05/15/postname.html</code>
                            </label>
                            <label>
                                <input type="radio" name="permalink_structure" value="/%year%/%monthnum%/%postname%/" <?php checked($permalink_structure, '/%year%/%monthnum%/%postname%/'); ?>> 
                                月份和名称型 <code>/2022/05/postname.html</code>
                            </label>
                            <label>
                                <input type="radio" name="permalink_structure" value="/%postname%/" <?php checked($permalink_structure, '/%postname%/'); ?>> 
                                文章名 <code>/postname.html</code>
                            </label>
                            <label>
                                <input type="radio" name="permalink_structure" value="/archives/%post_id%" <?php checked($permalink_structure, '/archives/%post_id%'); ?>> 
                                文章ID <code>/archives/123.html</code>
                            </label>
                            <label style="display: flex; flex-wrap: wrap; gap: 10px 5px; align-items: center;">
                                <input type="radio" name="permalink_structure" value="custom" <?php checked(!in_array($permalink_structure, array('', '/%year%/%monthnum%/%day%/%postname%/', '/%year%/%monthnum%/%postname%/', '/%postname%/', '/archives/%post_id%'))); ?>> 
                                自定义结构 <input type="text" name="permalink_structure_custom" value="<?php echo esc_attr($permalink_structure_custom); ?>" class="regular-text code" style="min-width: 80%;"> .html
                            </label>
                            <p class="description">可用标签: %year%, %monthnum%, %day%, %hour%, %minute%, %second%, %postname%, %post_id%, %category%, %author%, %breadcrumb%</p>
                        </fieldset>
                    </div>
                </div>
            </div>
        <?php elseif ($active_tab === 'users'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">登录/注册</h3>
                </div>
                <div class="admin-card-content">
                    <?php if (!(function_exists('todo_is_demo_admin') && todo_is_demo_admin())): ?>
                        <div class="form-group">
                            <label>轮换登录路径</label>
                            <fieldset>
                                <label>
                                    <?php $todo_auto_rotate = get_option('todo_auto_rotate_admin_entry', '1'); ?>
                                    <input type="checkbox" name="todo_auto_rotate_admin_entry" id="todo_auto_rotate_admin_entry" value="1" <?php checked($todo_auto_rotate, '1'); ?>> 
                                    开启每周自动更换登录路径（推荐）
                                </label>
                                <p class="description">开启后，系统将每周随机更换一次后台登录文件名，以极大提高系统安全性。如需手动指定，请关闭此开关。</p>
                            </fieldset>
                        </div>

                        <div class="form-group" id="site-adminbasefile-container">
                            <label for="site-adminbasefile">后台登录路径</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px 5px; align-items: center;">
                                <span><?php echo esc_html(todo_home_url()); ?>/admin/</span>
                                <?php $todo_current_admin_entry = function_exists('todo_get_admin_entry_filename') ? todo_get_admin_entry_filename() : get_option('site_adminbasefile', 'index'); ?>
                                <input type="hidden" name="site-adminbasefile-old" value="<?php echo esc_attr($todo_current_admin_entry); ?>">
                                <input type="text" name="site-adminbasefile" value="<?php echo esc_attr($todo_current_admin_entry); ?>" id="site-adminbasefile" data-site_url="<?php echo esc_attr(function_exists('todo_home_url') ? todo_home_url() : todo_get_site_url()); ?>" pattern="[A-Za-z0-9_\-]{3,32}" minlength="3" maxlength="32" onkeyup="this.value=this.value.replace(/[^\w\-]/g,'');" onbeforepaste="clipboardData.setData('text',clipboardData.getData('text').replace(/[^\w\-]/g,''));" style="width: 200px; <?php echo ($todo_auto_rotate === '1') ? 'opacity: 0.6; background-color: #f9f9f9;' : ''; ?>" autocomplete="off" <?php echo ($todo_auto_rotate === '1') ? 'readonly disabled' : ''; ?>> 
                                <span>.php</span>
                            </div>
                            <p class="description">手动输入路径需要包含大小写字母和数字，长度不少于10位。如果不符合规范，保存后系统将自动恢复为“自动路径”模式。</p>
                        </div>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const rotateSwitch = document.getElementById('todo_auto_rotate_admin_entry');
                            const adminInput = document.getElementById('site-adminbasefile');
                            
                            if (rotateSwitch && adminInput) {
                                rotateSwitch.addEventListener('change', function() {
                                    adminInput.disabled = this.checked;
                                    adminInput.readOnly = this.checked;
                                    if (this.checked) {
                                        adminInput.style.opacity = '0.6';
                                        adminInput.style.backgroundColor = '#f9f9f9';
                                    } else {
                                        adminInput.style.opacity = '1';
                                        adminInput.style.backgroundColor = '';
                                    }
                                });
                                
                                // 初始化状态
                                if (rotateSwitch.checked) {
                                    adminInput.style.opacity = '0.6';
                                    adminInput.style.backgroundColor = '#f9f9f9';
                                }
                            }
                        });
                        </script>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>成员资格</label>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="users_can_register" value="1" <?php checked($users_can_register, true); ?>> 
                                任何人都可以注册
                            </label>
                        </fieldset>
                    </div>
                    
                    <div class="form-group">
                        <label for="default-role">新用户默认角色</label>
                        <select name="default_role" id="default-role">
                            <?php foreach ($roles as $role_id => $role_name): ?>
                                <option value="<?php echo $role_id; ?>" <?php selected($default_role, $role_id); ?>>
                                    <?php echo $role_name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">短信服务设置</h3>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="mobile_login_enabled" value="1" <?php checked($mobile_login_enabled, true); ?> onchange="document.getElementById('sms-options-wrapper').style.display = this.checked ? 'block' : 'none';"> 
                            手机号注册和登录
                        </label>
                        <p class="description">开启后，登录/注册/忘记密码页面将支持短信验证。</p>
                    </div>

                    <div id="sms-options-wrapper" style="display: <?php echo $mobile_login_enabled ? 'block' : 'none'; ?>; margin-bottom: 15px; border-left: 5px solid #eceef1; padding: 10px 20px 5px; background-color: #f9fafd;">
                        <div class="form-group">
                            <label>短信服务商选择</label>
                            <label style="margin-right: 15px;">
                                <input type="radio" name="sms_provider" value="aliyun" <?php checked($sms_provider, 'aliyun'); ?> onchange="toggleSmsProviderOptions()"> 
                                阿里云短信 (Aliyun SMS)
                            </label>
                            <label>
                                <input type="radio" name="sms_provider" value="tencent" <?php checked($sms_provider, 'tencent'); ?> onchange="toggleSmsProviderOptions()"> 
                                腾讯云短信 (Tencent SMS)
                            </label>
                        </div>

                        <!-- 阿里云短信配置 -->
                        <div id="sms-provider-aliyun" style="display: <?php echo $sms_provider === 'aliyun' ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="sms_aliyun_access_key_id">AccessKey ID</label>
                                <input type="text" name="sms_aliyun_access_key_id" id="sms_aliyun_access_key_id" class="w-100 regular-text" value="<?php echo esc_attr($sms_aliyun_access_key_id); ?>" placeholder="LTAI5txxxxxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label for="sms_aliyun_access_key_secret">AccessKey Secret</label>
                                <input type="password" name="sms_aliyun_access_key_secret" id="sms_aliyun_access_key_secret" class="w-100 regular-text" value="<?php echo esc_attr($sms_aliyun_access_key_secret); ?>" placeholder="请输入阿里云 AccessKey Secret">
                            </div>
                            <div class="form-group">
                                <label for="sms_aliyun_sign_name">短信签名<span class="description">SignName</span></label>
                                <input type="text" name="sms_aliyun_sign_name" id="sms_aliyun_sign_name" class="regular-text" value="<?php echo esc_attr($sms_aliyun_sign_name); ?>" placeholder="如：我的网站">
                            </div>
                            <div class="form-group">
                                <label for="sms_aliyun_template_code">验证码模板 CODE<span class="description">TemplateCode</span></label>
                                <input type="text" name="sms_aliyun_template_code" id="sms_aliyun_template_code" class="regular-text" value="<?php echo esc_attr($sms_aliyun_template_code); ?>" placeholder="如：SMS_123456789">
                                <p class="description">模板变量需包含 <code>${code}</code>（例如：您的验证码为${code}，5分钟内有效）。</p>
                            </div>
                            <div class="form-group">
                                <label for="sms_aliyun_endpoint">接口地址 / Endpoint<span class="description">(选填)</span></label>
                                <input type="text" name="sms_aliyun_endpoint" id="sms_aliyun_endpoint" class="w-100 regular-text" value="<?php echo esc_attr($sms_aliyun_endpoint); ?>" placeholder="留空默认：dysmsapi.aliyuncs.com（或测试认证接口：dypnsapi.aliyuncs.com）">
                                <p class="description">默认商用接口为 <code>dysmsapi.aliyuncs.com</code>（Action为SendSms）；如使用阿里云号码认证/测试控制台接口，请填写 <code>dypnsapi.aliyuncs.com</code>（系统将自动适配SendSmsVerifyCode参数规范）。</p>
                            </div>
                            <div class="form-group">
                                <label for="sms_aliyun_region">服务地域 / RegionId<span class="description">(选填)</span></label>
                                <input type="text" name="sms_aliyun_region" id="sms_aliyun_region" class="regular-text" value="<?php echo esc_attr($sms_aliyun_region); ?>" placeholder="默认：cn-hangzhou">
                            </div>
                        </div>

                        <!-- 腾讯云短信配置 -->
                        <div id="sms-provider-tencent" style="display: <?php echo $sms_provider === 'tencent' ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="sms_tencent_secret_id">SecretId</label>
                                <input type="text" name="sms_tencent_secret_id" id="sms_tencent_secret_id" class="w-100 regular-text" value="<?php echo esc_attr($sms_tencent_secret_id); ?>" placeholder="AKIDxxxxxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_secret_key">SecretKey</label>
                                <input type="password" name="sms_tencent_secret_key" id="sms_tencent_secret_key" class="w-100 regular-text" value="<?php echo esc_attr($sms_tencent_secret_key); ?>" placeholder="请输入腾讯云 SecretKey">
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_app_id">SDK AppID</label>
                                <input type="text" name="sms_tencent_app_id" id="sms_tencent_app_id" class="regular-text" value="<?php echo esc_attr($sms_tencent_app_id); ?>" placeholder="如：1400000000">
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_sign_name">短信签名<span class="description">SignName</span></label>
                                <input type="text" name="sms_tencent_sign_name" id="sms_tencent_sign_name" class="regular-text" value="<?php echo esc_attr($sms_tencent_sign_name); ?>" placeholder="如：我的公众号">
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_template_id">模板 ID<span class="description">TemplateId</span></label>
                                <input type="text" name="sms_tencent_template_id" id="sms_tencent_template_id" class="regular-text" value="<?php echo esc_attr($sms_tencent_template_id); ?>" placeholder="如：123456">
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_endpoint">接口地址 / Endpoint<span class="description">(选填)</span></label>
                                <input type="text" name="sms_tencent_endpoint" id="sms_tencent_endpoint" class="w-100 regular-text" value="<?php echo esc_attr($sms_tencent_endpoint); ?>" placeholder="默认：sms.tencentcloudapi.com">
                                <p class="description">腾讯云短信 API 服务端点 Host，留空默认使用 <code>sms.tencentcloudapi.com</code>。</p>
                            </div>
                            <div class="form-group">
                                <label for="sms_tencent_region">地域 / Region<span class="description">(选填)</span></label>
                                <input type="text" name="sms_tencent_region" id="sms_tencent_region" class="regular-text" value="<?php echo esc_attr($sms_tencent_region); ?>" placeholder="默认：ap-guangzhou">
                                <p class="description">例如 <code>ap-guangzhou</code>、<code>ap-nanjing</code>、<code>ap-beijing</code> 等。</p>
                            </div>
                        </div>
                    </div>

                    <script>
                    function toggleSmsProviderOptions() {
                        var radios = document.getElementsByName('sms_provider');
                        var val = 'disabled';
                        for (var i = 0; i < radios.length; i++) {
                            if (radios[i].checked) { val = radios[i].value; break; }
                        }
                        document.getElementById('sms-provider-aliyun').style.display = (val === 'aliyun') ? 'block' : 'none';
                        document.getElementById('sms-provider-tencent').style.display = (val === 'tencent') ? 'block' : 'none';
                    }
                    </script>

                    <div class="admin-card-header">
                        <h3 class="admin-card-title">QQ登录设置</h3>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="qq_login_enabled" value="1" <?php checked($qq_login_enabled, true); ?> onchange="document.getElementById('qq-options-wrapper').style.display = this.checked ? 'block' : 'none';">
                            开启QQ登录
                        </label>
                        <p class="description">开启后，前端登录页面将显示QQ快捷登录入口。</p>
                    </div>

                    <div id="qq-options-wrapper" style="display: <?php echo $qq_login_enabled ? 'block' : 'none'; ?>; margin-bottom: 15px; border-left: 5px solid #eceef1; padding: 10px 20px 5px; background-color: #f9fafd;">
                        <div class="form-group">
                            <label for="qq-app-id">APP ID</label>
                            <input type="text" name="qq_app_id" id="qq-app-id" class="regular-text" value="<?php echo esc_attr($qq_app_id); ?>" placeholder="请输入QQ互联 APP ID">
                        </div>
                        <div class="form-group">
                            <label for="qq-app-key">APP Key</label>
                            <input type="password" name="qq_app_key" id="qq-app-key" class="regular-text" value="<?php echo esc_attr($qq_app_key); ?>" placeholder="请输入QQ互联 APP Key">
                        </div>
                        <div class="form-group">
                            <label for="qq-redirect-uri">回调地址</label>
                            <input type="text" name="qq_redirect_uri" id="qq-redirect-uri" class="w-100 regular-text" value="<?php echo esc_attr($qq_redirect_uri); ?>" placeholder="<?php echo esc_attr(todo_get_site_url('/api/user/qq-callback.php')); ?>">
                            <p class="description">默认回调地址为：<code><?php echo esc_url(todo_get_site_url('/api/user/qq-callback.php')); ?></code>（须与QQ互联开发者中心填写的回调地址一致）</p>
                        </div>
                    </div>

                </div>
            </div>
        <?php elseif ($active_tab === 'site-info'): ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">版权设置</h3>
                </div>
                <div class="admin-card-content">
                    <input type="hidden" name="action" value="update_site_info">
                    
                    <div class="form-group">
                        <span>网站Logo</span>
                        <div class="media-upload-group">
                            <?php
                            if (function_exists('todo_render_featured_images_component')) {
                                echo todo_render_featured_images_component(array(
                                    'field_id' => 'site_logo_group',
                                    'field_name' => 'site_logo',
                                    'current_images' => $site_logo_images,
                                    'multiple' => true,
                                    'max_images' => 6,
                                    'enable_debug' => false
                                ));
                            } else {
                                echo '<p class="description">特色图片组件未加载，无法渲染Logo选择器。</p>';
                            }
                            ?>
                        </div>
                        <p class="description">可上传多个Logo版本，拖拽排序。</p>
                    </div>
                    
                    <div class="form-group">
                        <span>网站图标(Favicon)</span>
                        <div class="media-upload-group">
                            <?php
                            if (function_exists('todo_render_featured_images_component')) {
                                echo todo_render_featured_images_component(array(
                                    'field_id' => 'site_favicon_group',
                                    'field_name' => 'site_favicon',
                                    'current_images' => $site_favicon_images,
                                    'multiple' => true,
                                    'max_images' => 6,
                                    'enable_debug' => false
                                ));
                            } else {
                                echo '<p class="description">特色图片组件未加载，无法渲染Favicon选择器。</p>';
                            }
                            ?>
                        </div>
                        <p class="description">可上传多个Favicon版本，拖拽排序。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="site-copyright">版权信息</label>
                        <input type="text" name="site_copyright" id="site-copyright" value="<?php echo esc_attr($site_copyright); ?>" class="regular-text mw-100" style="width: 50%;">
                        <p class="description">网站的版权声明，显示在页脚。如：© {{todo:current_year}} {{todo:site_title}} 版权所有.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="site-icp">备案信息</label>
                        <input type="text" name="site_icp" id="site-icp" value="<?php echo esc_attr($site_icp); ?>" class="regular-text mw-100" style="width: 50%;">
                        <p class="description">网站的ICP备案号。</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="site-analytics">统计/分析代码</label>
                        <textarea name="site_analytics" id="site-analytics" rows="5" class="large-text mw-100 code" style="width: 50%;"><?php echo esc_textarea($site_analytics); ?></textarea>
                        <p class="description">添加Google Analytics、百度统计等第三方统计代码。</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="admin-form-submit">
            <input type="submit" name="submit" value="保存更改" class="button button-primary">
        </div>
    </form>
</div>

<style>
/* 媒体上传组件样式 */
.media-upload-group {
    display: flex;
    align-items: center;
    flex-direction: column;
    align-items: flex-start;
    margin-top: 10px;
}

.code {
    font-family: 'Courier New', Courier, monospace;
    background: #f9fafd;
}
</style>

<script type="text/javascript">
// 设置页面脚本
document.addEventListener('DOMContentLoaded', function() {
    // 日期格式预览
    var dateFormatInputs = document.querySelectorAll('input[name="date_format"]');
    var dateFormatCustom = document.querySelector('input[name="date_format_custom"]');
    var dateFormatPreview = document.getElementById('date-format-preview');
    
    function updateDateFormatPreview() {
        var format = '';
        
        for (var i = 0; i < dateFormatInputs.length; i++) {
            if (dateFormatInputs[i].checked) {
                if (dateFormatInputs[i].value === 'custom') {
                    format = dateFormatCustom.value;
                } else {
                    format = dateFormatInputs[i].value;
                }
                break;
            }
        }
        
        // 这里应该有一个AJAX请求来获取格式化后的日期
        // 但为了简单起见，我们只显示格式字符串
        dateFormatPreview.textContent = format;
    }
    
    if (dateFormatInputs.length && dateFormatCustom && dateFormatPreview) {
        dateFormatInputs.forEach(function(input) {
            input.addEventListener('change', updateDateFormatPreview);
        });
        
        dateFormatCustom.addEventListener('input', function() {
            document.querySelector('input[name="date_format"][value="custom"]').checked = true;
            updateDateFormatPreview();
        });
    }
    
    // 时间格式预览
    var timeFormatInputs = document.querySelectorAll('input[name="time_format"]');
    var timeFormatCustom = document.querySelector('input[name="time_format_custom"]');
    var timeFormatPreview = document.getElementById('time-format-preview');
    
    function updateTimeFormatPreview() {
        var format = '';
        
        for (var i = 0; i < timeFormatInputs.length; i++) {
            if (timeFormatInputs[i].checked) {
                if (timeFormatInputs[i].value === 'custom') {
                    format = timeFormatCustom.value;
                } else {
                    format = timeFormatInputs[i].value;
                }
                break;
            }
        }
        
        // 这里应该有一个AJAX请求来获取格式化后的时间
        // 但为了简单起见，我们只显示格式字符串
        timeFormatPreview.textContent = format;
    }
    
    if (timeFormatInputs.length && timeFormatCustom && timeFormatPreview) {
        timeFormatInputs.forEach(function(input) {
            input.addEventListener('change', updateTimeFormatPreview);
        });
        
        timeFormatCustom.addEventListener('input', function() {
            document.querySelector('input[name="time_format"][value="custom"]').checked = true;
            updateTimeFormatPreview();
        });
    }
});
</script>

<script type="text/javascript">
// 全局媒体模态框功能检查和初始化
(function() {
    'use strict';

    function getBaseUrl() {
        if (window.TODO && typeof window.TODO.SITE_URL === 'string' && window.TODO.SITE_URL) {
            return window.TODO.SITE_URL.replace(/\/+$/, '');
        }
        return (window.location && window.location.origin) ? window.location.origin : '';
    }

    function getBasePathPrefix() {
        try {
            var base = getBaseUrl();
            if (!base) return '';
            var u = new URL(base + '/', window.location.origin);
            var p = (u && u.pathname) ? u.pathname : '';
            p = (p || '').replace(/\/+$/, '');
            return p === '/' ? '' : p;
        } catch (e) {
            return '';
        }
    }

    function toRelativeUrl(url) {
        if (!url) return '';
        var s = ('' + url).trim();
        if (!s) return '';
        if (s.charAt(0) === '/' && !/^\/\//.test(s)) {
            var prefix = getBasePathPrefix();
            if (prefix && s.indexOf(prefix + '/') === 0) {
                s = s.substring(prefix.length);
                if (!s) s = '/';
            }
            return s.charAt(0) === '/' ? s : '/' + s;
        }
        if (!/^https?:\/\//i.test(s) && !/^\/\//.test(s)) {
            if (s.charAt(0) !== '/') s = '/' + s.replace(/^\/+/, '');
            var prefix2 = getBasePathPrefix();
            if (prefix2 && s.indexOf(prefix2 + '/') === 0) {
                s = s.substring(prefix2.length);
                if (!s) s = '/';
            }
            return s;
        }
        try {
            var base = getBaseUrl() || (window.location ? window.location.origin : '');
            var bu = new URL(base + '/', window.location.origin);
            var u2 = new URL(s, window.location.origin);
            var bh = (bu.hostname || '').toLowerCase().replace(/^www\./, '');
            var uh = (u2.hostname || '').toLowerCase().replace(/^www\./, '');
            if (!bh || !uh || bh !== uh) return s;
            var rel = u2.pathname + (u2.search || '') + (u2.hash || '');
            var prefix3 = getBasePathPrefix();
            if (prefix3 && rel.indexOf(prefix3 + '/') === 0) {
                rel = rel.substring(prefix3.length);
                if (!rel) rel = '/';
            }
            if (rel.charAt(0) !== '/') rel = '/' + rel;
            return rel;
        } catch (e) {
            return s;
        }
    }

    function toAbsoluteUrl(urlOrPath) {
        if (!urlOrPath) return '';
        var s = ('' + urlOrPath).trim();
        if (!s) return '';
        if (/^https?:\/\//i.test(s)) return s;
        if (/^\/\//.test(s)) {
            try {
                var b = getBaseUrl() || (window.location ? window.location.origin : '');
                var bu = b ? new URL(b + '/', window.location.origin) : null;
                var scheme = (bu && bu.protocol) ? bu.protocol : (window.location ? window.location.protocol : 'http:');
                return scheme + s;
            } catch (e) {
                return s;
            }
        }
        var base = getBaseUrl();
        if (!base) return s;
        if (s.charAt(0) !== '/') s = '/' + s.replace(/^\/+/, '');
        return base + s;
    }

    function ensureFaviconPreviewImg() {
        var container = document.querySelector('#site-favicon-preview .featured-image-preview');
        if (!container) return;
        if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
            window.TodoCMS.Utils.ImagePreview.ensureCloneInContainer(container, { previewClass: 'previewimg' });
        }
    }

    function bindFaviconPreviewEvents() {
        var root = document.getElementById('site-favicon-preview');
        if (!root || root.dataset.previewimgBound) return;
        root.dataset.previewimgBound = '1';

        root.addEventListener('mouseover', function(e) {
            var container = e.target && e.target.closest ? e.target.closest('.featured-image-preview') : null;
            if (!container || !root.contains(container)) return;
            if (e.relatedTarget && container.contains(e.relatedTarget)) return;
            ensureFaviconPreviewImg();
            if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                window.TodoCMS.Utils.ImagePreview.adjustPosition(container, { previewSelector: 'img.previewimg', edgeCompensation: -30 });
            }
        });

        root.addEventListener('mouseout', function(e) {
            var container = e.target && e.target.closest ? e.target.closest('.featured-image-preview') : null;
            if (!container || !root.contains(container)) return;
            if (e.relatedTarget && container.contains(e.relatedTarget)) return;
            if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                window.TodoCMS.Utils.ImagePreview.resetPosition(container, { previewSelector: 'img.previewimg' });
            }
        });
    }

    window.selectSiteFavicon = function() {
        if (typeof window.selectMediaForField === 'function') {
            window.selectMediaForField({
                title: '选择网站图标',
                button_text: '选择图像',
                type: 'image',
                modal_id: 'site_favicon',
                input_id: 'site-favicon',
                preview_id: 'site-favicon-preview',
                callback: window.setSiteFavicon
            });
        } else {
            TodoCMS.Utils.showMessage('媒体选择功能正在加载中，请稍后重试。', 'warning');
        }
    };

    // 设置Favicon回调函数
    window.setSiteFavicon = function(media) {
        if (!media || !media.url) {
            console.error('无效的媒体数据:', media);
            return;
        }
        
        document.getElementById('site-favicon-id').value = media.id || '';
        var rel = toRelativeUrl(media.url);
        document.getElementById('site-favicon').value = rel;
        
        const preview = document.getElementById('site-favicon-preview');
        if (preview) {
            preview.innerHTML = `
                <div class="featured-image-item">
                    <div class="featured-image-preview">
                        <img src="${toAbsoluteUrl(rel)}" alt="Favicon预览" style="width: 32px; height: 32px;">
                    </div>
                    <div class="featured-image-actions">
                        <button type="button" class="button remove-featured-image" title="移除图标" onclick="removeSiteFavicon(); return false;">
                            x
                        </button>
                    </div>
                </div>
            `;
        }

        ensureFaviconPreviewImg();
    };

    // 移除Favicon函数
    window.removeSiteFavicon = function() {
        document.getElementById('site-favicon-id').value = '';
        document.getElementById('site-favicon').value = '';
        
        const preview = document.getElementById('site-favicon-preview');
        if (preview) {
            preview.innerHTML = '';
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ensureFaviconPreviewImg();
            bindFaviconPreviewEvents();
        });
    } else {
        ensureFaviconPreviewImg();
        bindFaviconPreviewEvents();
    }
})();
</script>

<?php
// 渲染媒体模态框
if (class_exists('TodoMediaModal')) {
    $media_modal = new TodoMediaModal();
    echo $media_modal->render();
}
?>

<?php
/**
 * TodoCMS 主题工作室 (Theme Studio) 后台 AJAX 处理器
 * 
 * 核心功能：
 * 1. 扫描与检索现有主题结构及文件树
 * 2. 素材包管理与解析
 * 3. 严格遵循 TodoCMS 规范构建基础主题包（双端、头尾分离、模板齐全）
 * 4. 深度对接官方原 users 用户中心（自动克隆）
 * 5. 自动导入模板标签手册 template-tags.md
 * 6. 生成内涵激活数据初始化机制与默认图片映射的 functions.php
 * 7. 生成全量 AI 规格说明 JSON (严禁 .tsx/SPA，纯原生标签规范) 及 Google AI Studio 投喂 Prompt
 * 8. 一键打包 ZIP 与快速激活
 * 
 * @package TodoCMS
 * @subpackage ThemeStudio
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
if (!defined('IS_ADMIN')) {
    define('IS_ADMIN', true);
}

// 确保主配置与环境载入
if (!defined('ABSPATH')) {
    $current_dir = str_replace('\\', '/', __DIR__);
    $root_path = dirname(dirname($current_dir));
    define('ABSPATH', rtrim($root_path, '/') . '/');
}

if (!file_exists(ABSPATH . 'config.php')) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => '系统主配置文件 config.php 缺失']);
    exit;
}

require_once ABSPATH . 'config.php';

if (!class_exists('Todo_Security_Manager')) {
    $autoload_path = ABSPATH . 'vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
    }
}

if (!function_exists('todo_get_site_url')) {
    $bootstrap_path = ABSPATH . 'core/bootstrap.php';
    if (file_exists($bootstrap_path)) {
        require_once $bootstrap_path;
    }
}

// 会话管理初始化
if (class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'init')) {
    Todo_Session_Manager::init();
} elseif (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// 鉴权拦截
$is_admin = false;
if (class_exists('Todo_Session_Manager')) {
    $is_admin = Todo_Session_Manager::is_admin_logged_in();
}
if (!$is_admin && function_exists('is_user_logged_in')) {
    $is_admin = is_user_logged_in();
}
if (!$is_admin && (!empty($_SESSION['admin_user_id']) || !empty($_SESSION['todo_admin_uid']))) {
    $is_admin = true;
}
if (!$is_admin && defined('TODO_DEMO_ADMIN_ENABLED') && TODO_DEMO_ADMIN_ENABLED && !empty($_SESSION['todo_demo_admin'])) {
    $is_admin = true;
}
if (!$is_admin && php_sapi_name() === 'cli') {
    $is_admin = true;
}

if (!$is_admin) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => '未授权的管理员操作，访问被拒绝']);
    exit;
}

// CSRF 校验
if (php_sapi_name() !== 'cli' && class_exists('Todo_Security_Manager')) {
    $nonce = $_REQUEST['_todononce'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $valid_nonce = Todo_Security_Manager::verify_csrf_token($nonce, 'common_ajax_nonce') 
        || Todo_Security_Manager::verify_csrf_token($nonce, 'theme_studio_nonce')
        || !empty($_SESSION['admin_user_id']) || !empty($_SESSION['todo_admin_uid']);
    if (!$valid_nonce) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'CSRF 安全令牌校验失败，请刷新页面重试']);
        exit;
    }
}

// 请求动作获取
$action = $_REQUEST['studio_action'] ?? $_REQUEST['action'] ?? '';

// 演示模式 (Demo Admin) 安全防护：拦截所有写操作与打包操作，杜绝演示用户恶意篡改或耗尽系统资源
if (function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session()) {
    $write_actions = ['build_scaffold', 'theme_studio_build_scaffold', 'onekey_package', 'theme_studio_onekey_package'];
    if (in_array($action, $write_actions, true)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => '演示模式，功能已禁用',
            'error'   => '演示模式'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 辅助函数：扫描目录并生成树状结构
if (!function_exists('todo_studio_scan_dir_tree')) {
    function todo_studio_scan_dir_tree($base_dir, $sub_path = '') {
        $current_dir = rtrim($base_dir . '/' . $sub_path, '/');
        if (!is_dir($current_dir)) {
            return [];
        }

        $items = scandir($current_dir);
        $tree = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store' || $item === '.git') {
                continue;
            }

            $full_path = $current_dir . '/' . $item;
            $rel_path = ltrim($sub_path . '/' . $item, '/');
            $is_dir = is_dir($full_path);

            $node = [
                'name' => $item,
                'path' => $rel_path,
                'is_dir' => $is_dir,
                'ext' => $is_dir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
                'size' => $is_dir ? 0 : filesize($full_path),
                'mtime' => date('Y-m-d H:i:s', filemtime($full_path)),
            ];

            if ($is_dir) {
                $node['children'] = todo_studio_scan_dir_tree($base_dir, $rel_path);
            }

            $tree[] = $node;
        }

        usort($tree, function ($a, $b) {
            if ($a['is_dir'] === $b['is_dir']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['is_dir'] ? -1 : 1;
        });

        return $tree;
    }
}

// 辅助函数：安全递归创建目录
if (!function_exists('todo_studio_ensure_dir')) {
    function todo_studio_ensure_dir($dir, $mode = 0755) {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, $mode, true);
    }
}

// 辅助函数：安全深拷贝目录
if (!function_exists('todo_studio_copy_dir')) {
    function todo_studio_copy_dir($src, $dst) {
        if (!file_exists($src)) {
            return false;
        }
        todo_studio_ensure_dir($dst);
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file != '.' && $file != '..' && $file != '.DS_Store') {
                if (is_dir($src . '/' . $file)) {
                    todo_studio_copy_dir($src . '/' . $file, $dst . '/' . $file);
                } else {
                    @copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
        return true;
    }
}

// 辅助函数：智能精简拷贝素材包目录（自动过滤 >150KB 的外部编译 CSS/JS 及大视频，杜绝 LLM 上下文爆炸与噪音）
if (!function_exists('todo_studio_copy_material_dir')) {
    function todo_studio_copy_material_dir($src, $dst) {
        if (!file_exists($src)) {
            return false;
        }
        todo_studio_ensure_dir($dst);
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file != '.' && $file != '..' && $file != '.DS_Store') {
                $src_path = $src . '/' . $file;
                $dst_path = $dst . '/' . $file;
                if (is_dir($src_path)) {
                    todo_studio_copy_material_dir($src_path, $dst_path);
                } else {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $fsize = @filesize($src_path);
                    // 过滤大于 150KB 的编译后样式/脚本（例如 2.9MB 的 tailwind-atomic.min.css）
                    if (in_array($ext, ['css', 'js', 'map']) && $fsize > 150 * 1024) {
                        continue;
                    }
                    // 过滤大于 2MB 的视频或压缩包
                    if (in_array($ext, ['mp4', 'webm', 'mov', 'zip', 'tar', 'gz']) && $fsize > 2 * 1024 * 1024) {
                        continue;
                    }
                    @copy($src_path, $dst_path);
                }
            }
        }
        closedir($dir);
        return true;
    }
}

// 辅助函数：安全递归删除目录
if (!function_exists('todo_studio_delete_dir')) {
    function todo_studio_delete_dir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? todo_studio_delete_dir($path) : @unlink($path);
        }
        return @rmdir($dir);
    }
}

// 辅助函数：安全过滤文件名
if (!function_exists('todo_studio_sanitize_slug')) {
    function todo_studio_sanitize_slug($slug) {
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$slug);
        return trim($slug);
    }
}

// 辅助函数：从素材包设计大图中裁剪并输出真实高质感封面图/缩略图
if (!function_exists('todo_studio_extract_real_cover')) {
    function todo_studio_extract_real_cover($source_img_path, $target_path, $target_width = 800, $target_height = 500, $y_offset_ratio = 0.1) {
        if (!file_exists($source_img_path) || !function_exists('imagecreatefromstring')) {
            return false;
        }
        $raw = @file_get_contents($source_img_path);
        if (!$raw) return false;
        $src_img = @imagecreatefromstring($raw);
        if (!$src_img) return false;

        $src_w = imagesx($src_img);
        $src_h = imagesy($src_img);
        if ($src_w <= 0 || $src_h <= 0) {
            @imagedestroy($src_img);
            return false;
        }

        $dst_img = imagecreatetruecolor($target_width, $target_height);
        imagealphablending($dst_img, false);
        imagesavealpha($dst_img, true);

        // 计算等比裁切窗口
        $target_ratio = $target_width / max(1, $target_height);
        $src_ratio = $src_w / max(1, $src_h);

        if ($src_ratio > $target_ratio) {
            $crop_h = $src_h;
            $crop_w = (int)($src_h * $target_ratio);
        } else {
            $crop_w = $src_w;
            $crop_h = (int)($src_w / $target_ratio);
        }

        $crop_x = max(0, (int)(($src_w - $crop_w) / 2));
        $crop_y = max(0, (int)($src_h * $y_offset_ratio));
        if ($crop_y + $crop_h > $src_h) {
            $crop_y = max(0, $src_h - $crop_h);
        }

        imagecopyresampled($dst_img, $src_img, 0, 0, $crop_x, $crop_y, $target_width, $target_height, $crop_w, $crop_h);

        todo_studio_ensure_dir(dirname($target_path));
        $ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            imagepng($dst_img, $target_path, 8);
        } else {
            imagejpeg($dst_img, $target_path, 92);
        }
        @imagedestroy($src_img);
        @imagedestroy($dst_img);
        return true;
    }
}

// 辅助函数：生成默认占位图片（保底渐变艺术卡片）
if (!function_exists('todo_studio_generate_placeholder_image')) {
    function todo_studio_generate_placeholder_image($target_path, $text = 'Theme Image', $width = 800, $height = 500, $bg_color = [37, 99, 235]) {
        todo_studio_ensure_dir(dirname($target_path));

        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($width, $height);
            for ($y = 0; $y < $height; $y++) {
                $factor = $y / max(1, $height);
                $r = (int)min(255, max(0, $bg_color[0] * (1 - $factor * 0.4) + 15 * $factor));
                $g = (int)min(255, max(0, $bg_color[1] * (1 - $factor * 0.4) + 23 * $factor));
                $b = (int)min(255, max(0, $bg_color[2] * (1 - $factor * 0.3) + 42 * $factor));
                $line_color = imagecolorallocate($im, $r, $g, $b);
                imageline($im, 0, $y, $width, $y, $line_color);
            }

            $card_margin_x = (int)($width * 0.12);
            $card_margin_y = (int)($height * 0.15);
            $card_bg = imagecolorallocatealpha($im, 255, 255, 255, 115);
            imagefilledrectangle($im, $card_margin_x, $card_margin_y, $width - $card_margin_x, $height - $card_margin_y, $card_bg);

            $border_color = imagecolorallocatealpha($im, 255, 255, 255, 95);
            imagerectangle($im, $card_margin_x, $card_margin_y, $width - $card_margin_x, $height - $card_margin_y, $border_color);

            $white = imagecolorallocate($im, 255, 255, 255);
            $font_size = 5;
            $text_w = imagefontwidth($font_size) * strlen($text);
            $text_h = imagefontheight($font_size);
            $x = (int)(($width - $text_w) / 2);
            $y = (int)(($height - $text_h) / 2) - 10;
            imagestring($im, $font_size, $x, $y, $text, $white);

            $sub_text = 'TodoCMS High-End Visual';
            $sub_w = imagefontwidth(3) * strlen($sub_text);
            imagestring($im, 3, (int)(($width - $sub_w) / 2), $y + 26, $sub_text, imagecolorallocatealpha($im, 255, 255, 255, 40));

            $ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
            if ($ext === 'png') {
                imagepng($im, $target_path, 8);
            } else {
                imagejpeg($im, $target_path, 90);
            }
            imagedestroy($im);
            return true;
        }

        $svg_content = <<<SVG
<svg width="{$width}" height="{$height}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="rgb({$bg_color[0]},{$bg_color[1]},{$bg_color[2]})" />
      <stop offset="100%" stop-color="#111827" />
    </linearGradient>
  </defs>
  <rect width="100%" height="100%" fill="url(#g)"/>
  <rect x="10%" y="15%" width="80%" height="70%" rx="12" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.15)"/>
  <text x="50%" y="48%" font-family="sans-serif" font-size="24" font-weight="bold" fill="#ffffff" text-anchor="middle">{$text}</text>
  <text x="50%" y="58%" font-family="sans-serif" font-size="14" fill="rgba(255,255,255,0.6)" text-anchor="middle">TodoCMS High-End Visual</text>
</svg>
SVG;
        file_put_contents($target_path, $svg_content);
        return true;
    }
}

// ---------------------------------------------------------------------
// 辅助函数：构建全量 AI 规格说明 JSON
// ---------------------------------------------------------------------
if (!function_exists('todo_studio_build_full_ai_spec')) {
    function todo_studio_build_full_ai_spec($slug, $name, $version, $mode, $material_slug = '', $custom_material_url = '') {
        $spec_doc_path = ABSPATH . 'docs/todo-template-tags-spec.md';
        $spec_markdown = file_exists($spec_doc_path) ? file_get_contents($spec_doc_path) : '';

        return [
            'target_cms' => 'TodoCMS',
            'engine_standard' => 'TodoCMS v4.0 Double-Brackets Namespace Template Engine',
            'theme_metadata' => [
                'theme_slug' => $slug,
                'theme_name' => $name,
                'version' => $version,
                'architecture_mode' => $mode,
                'material_slug' => $material_slug,
                'custom_material_url' => $custom_material_url,
                'description' => "遵从 TodoCMS 官方标准的双端主题，包含完整的数据自填充机制与用户中心对接规范。"
            ],

            // 极其严肃的导出与生成格式限制（严禁 .tsx/Next.js/React 与 WordPress 语法污染）
            'strict_generation_constraints' => [
                'CRITICAL_WARNING' => 'DO NOT GENERATE REACT, NEXT.JS, VUE, SVELTE, OR ANY TSX/JSX SPA PROJECTS! TodoCMS is a PHP & Native HTML template CMS. SPA build artifacts cannot be activated as themes in TodoCMS.',
                'STRONG_REFERENCE_DESIGN_HIERARCHY' => 'STRONG BENCHMARK REFERENCE HIERARCHY: Once a reference target (local material package or custom reference URL) is selected, AI must strictly follow two tiers: Tier 1 (DOM & Interaction Baseline - Highest Priority): Prioritize directly referencing the target\'s DOM-level elements and structure: UI layout, grid typography rhythm, and JS interactive animation/effects (nav drawer, sticky header, carousel sliders, modal dialogs, tabs); Tier 2 (Visual Aesthetics & Palette): Supplement and refine visual style, tone, colors, and atmosphere based on the user prompt.',
                'WORDPRESS_SYNTAX_STRICTLY_FORBIDDEN' => 'STRICTLY FORBIDDEN: DO NOT USE WORDPRESS HOOKS OR FUNCTIONS IN functions.php! WE ARE TODOCMS, NOT WORDPRESS! Forbidden: wp_enqueue_style, wp_enqueue_script, register_nav_menus, add_theme_support, after_setup_theme, wp_head, wp_footer, is_wp_error. Required TodoCMS equivalents: todo_enqueue_style, todo_enqueue_script, todo_enqueue_scripts hook, todo_head hook, todo_footer hook, todo_theme_activated hook.',
                'USER_ROUTING_STANDARD' => 'NEVER hardcode /users/login or /users/cart! Strictly use TodoCMS dynamic routing tags: {{todo:login_url}}, {{todo:logout_url}}, {{todo:user_url}}, and {{todo:user_page_url page=\'cart\'}}.',
                'I18N_AND_LANGUAGE_STANDARD' => 'Theme MUST be designed with English as default language. ALL static UI texts in templates MUST be wrapped in {{todo:t text="..."}} for multilingual translation via assets/lang/*.php.',
                'REAL_DESIGN_ASSETS_STANDARD' => 'Theme must output and link genuine, high-quality screenshot.png (800x600+) and realistic cover images (e.g. cover_6.jpg, default-banner.jpg) in pc/assets/images/data/. Strictly forbid invalid plain monochrome placeholder blocks!',
                'ATOMIC_CSS_AND_STYLESHEET_SYNC' => 'STRICTLY FORBIDDEN to use undeclared phantom atomic utility classes (like text-center, mt-12, text-white, text-xl, block, h-full, text-stone-200, px-10) in templates without defining them in style.css! Every class in HTML must have real CSS rules in assets/css/style.css.',
                'NO_DOUBLE_CURRENCY_SYMBOL' => 'DO NOT write currency symbols ($ or ¥) before price tags! {{todo:post_current_price}} and {{todo:current_price}} automatically include and format currency symbol according to site locale. Writing "$ {{todo:post_current_price}}" causes fatal double symbol "$$0.15".',
                'FONTAWESOME_ICON_STANDARD' => 'Use FontAwesome 6 icons (<i class="fa-solid fa-magnifying-glass"></i>, <i class="fa-solid fa-bag-shopping"></i>, <i class="fa-solid fa-user"></i>, etc.) instead of todofont to prevent missing blank icons.',
                'HERO_SLIDER_ASSETS_STANDARD' => 'If using slider classes (hero-slider, slider-track, slide), ensure hero-slider.css and hero-slider.js from official pc/assets/ are properly included and enqueued.',
                'BREADCRUMB_DOM_CSS_STANDARD' => 'Must provide full CSS styling for breadcrumb-list and its generated DOM structure (.breadcrumb-list, li, a, .breadcrumb-separator, .breadcrumb-current).',
                'CSRF_TOKEN_HIDDEN_STANDARD' => 'NEVER render {{todo:csrf_token}} directly naked in HTML body! Must place inside hidden input: <input type="hidden" name="_todononce" value="{{todo:csrf_token name=\'...\'}}"> or use {{todo:csrf_field}}.',
                'SYSTEM_COMPONENTS_THEMING_OVERRIDE' => 'Must override default styles for system components (.todo-language-switcher.dropdown-style .current-language:hover, .todo-site-switcher, .breadcrumb-list, etc.) in style.css whenever necessary to seamlessly match each new theme\'s visual branding and color palette.',
                'FULL_STATIC_ASSETS_INTEGRITY_MANDATE' => 'CRITICAL MANDATE: NEVER deliver a stripped theme containing only style.css and theme.js! Theme MUST retain the entire infrastructure assets tree: pc/users/ (all 19 templates + skin), pc/assets/css/tailwind-atomic.min.css (Tailwind engine), pc/assets/js/motion.min.js & motion-helper.js (Motion engine), pc/assets/js/csrf-manager.js & submit-guard.js, pc/assets/css/hero-slider.css & pc/assets/js/hero-slider.js, pc/assets/lang/ (all language packs), and pc/assets/images/data/ (real covers & default banner/product/article images). Dropping these assets renders the delivery a COMPLETE FAILURE.',
                'USER_CENTER_PRESERVATION_MANDATE' => 'STRICTLY FORBIDDEN to omit, delete, or skip pc/users/ in the delivery package! All 19 user center templates (login, register, forgot-password, cart, checkout, orders, profile, etc.) are mandatory infrastructure components and must be fully preserved in the delivery.',
                'MANDATORY_5_ROUND_SELF_AUDIT' => 'AI MUST execute and report at least 5 rounds of rigorous self-audit and static verification before delivering code (Round 1: Syntax, zero-WP functions & hidden CSRF token; Round 2: 100% i18n text wrapping & no double currency symbols; Round 3: Dynamic auth routes {{todo:login_url}} & {{todo:user_page_url page=\'cart\'}}; Round 4: Material benchmark DOM & JS fidelity, real cover fidelity, 100% atomic CSS stylesheet sync & system components theme overrides for .todo-language-switcher hover and .breadcrumb-list; Round 5: Full static assets topology preservation [users/, tailwind-atomic, motion, csrf, lang/, covers] & zero 404/console errors). Deliveries without 5-round verification will be rejected.',
                'ALLOWED_FILE_TYPES' => ['.html', '.php', '.css', '.js', '.json', '.md', '.png', '.jpg', '.svg', '.webp'],
                'FORBIDDEN_FILE_TYPES' => ['.tsx', '.jsx', '.ts', 'package.json', 'vite.config.*', 'tsconfig.json'],
                'HTML_STANDARD' => 'Pure HTML with embedded TodoCMS tags (e.g. {{todo:loop}}, {{title}}, {{todo:todo_head}}).',
                'PHP_STANDARD' => 'functions.php must be pure executable PHP using TodoCMS functions (todo_enqueue_style, todo_enqueue_script, todo_enqueue_scripts hook, todo_theme_activated hook). Strictly forbid WordPress functions.'
            ],

            // 数据初始化规范（functions.php 与默认图片）
            'data_seeding_and_image_requirements' => [
                'hook_implementation' => [
                    'file' => 'functions.php',
                    'hook_name' => 'todo_theme_activated',
                    'requirement' => '主题在激活时，必须在 functions.php 中调用初始化数据逻辑，自动向数据库插入演示分类、文章、轮播图与站点配置。'
                ],
                'default_images_directory' => 'assets/images/data/',
                'default_images_rules' => [
                    'rule_1' => '所有主题用到的默认占位图、演示产品图、轮播图等必须存放在 pc/assets/images/data/ 及 mobile/assets/images/data/ 目录中。',
                    'rule_2' => '在 functions.php 初始化文章/产品数据或模板中引用静态图时，系统 {{todo:theme_url}} 和 get_template_directory_uri() 已自动包含当前设备目录，因此资源路径必须写为 $theme_url . \'/assets/images/data/xxx.jpg\'，严禁重复拼接 /pc/。',
                    'rule_3' => '模板中对静态演示图的引用统一使用 {{todo:theme_url}}/assets/images/data/xxx.jpg，确保开箱即用、图文并茂。'
                ],
                'asset_enqueue_rules' => [
                    'stylesheets' => '统一在 functions.php 中通过 todo_enqueue_style 注册并排队在 {{todo:todo_head}} 输出。',
                    'scripts' => '统一在 functions.php 中通过 todo_enqueue_script 注册并排队在 {{todo:todo_footer}} 输出。',
                    'languages' => '多语言文本包必须存放在 lang/ 或 languages/ 目录下，通过 __() 或 {{todo:t}} 标签调用。'
                ]
            ],

            // 官方原 users 用户中心对接规范
            'user_center_integration' => [
                'status' => 'PRE_CLONED_FROM_OFFICIAL_DEFAULT',
                'directory' => 'pc/users/',
                'critical_rule' => '用户中心（users 目录下的购物车、结算、订单列表、登录注册、个人资料等）在基础包中已从官方原完整克隆，开发者或 AI 仅需做 CSS 样式外观与主题色调统一对接，严禁篡改其内置的模板标签逻辑、表单提交路由与业务交互接口！'
            ],

            // 全站头部 (header.html) 规范与自由定制原则
            'header_customization_rules' => [
                'official_benchmark_menu' => '{{todo:nav_menu theme_location="primary" menu_id="1" container_class="nav-container" menu_class="nav-menu"}}',
                'essential_components' => [
                    'site_switcher' => '{{todo:site_switcher type="dropdown"}} 多站点切换器',
                    'language_switcher' => '{{todo:language_switcher type="dropdown"}} 多语言切换器',
                    'search_interface' => '搜索框必须绑定 window.TODO.doSafeSearch(...) 接口',
                    'user_center_hydration' => '全静态缓存模式下的登录态容器 <div id="todo-user-nav-item"></div>，由客户端异步水合填充，防闪烁',
                    'cart_badge' => '购物车入口与数量徽章 <a href="{{todo:user_page_url page=\'cart\'}}"><span id="todo-cart-count"></span></a>'
                ],
                'ai_customization_freedom' => '头部基本延续官方原功能架构与标签体系，但开发者与 AI 可完全自由重构其 CSS 视觉、排版布局（如左右通栏、居中对称、吸顶悬浮、玻璃拟态等）、色彩与交互微动效，打造独特的品牌外观。'
            ],

            // 模板文件架构与头尾分离标准（严格对标官方原全量 38 个基础模板体系）
            'template_hierarchy_rules' => [
                'header_separation' => '全站公共头部抽取为 header.html，通过 {{todo:include file="header.html"}} 引入。',
                'footer_separation' => '全站公共尾部抽取为 footer.html，通过 {{todo:include file="footer.html"}} 引入。',
                'total_templates_count' => 38,
                'required_templates' => [
                    // 核心骨架 (4)
                    'header.html' => '全站公共头部，包含 {{todo:todo_head}}、导航菜单、用户登录状态',
                    'footer.html' => '全站公共尾部，包含 {{todo:todo_footer}}、底部导航、版权信息',
                    'index.html' => '首页骨架，组织轮播大图、核心业务推荐、最新资讯动态',
                    '404.html' => '404 错误缺省页',
                    // 模型专属列表 (9)
                    'lists_article.html' => '文章/资讯模型专属列表页',
                    'lists_product.html' => '商品/产品模型专属列表页',
                    'lists_images.html' => '图集/画册模型专属列表页',
                    'lists_download.html' => '软件/资源下载模型专属列表页',
                    'lists_media.html' => '音频/视频多媒体模型专属列表页',
                    'lists_recruit.html' => '人才招聘模型专属列表页',
                    'lists_guestbook.html' => '在线留言/访客留言列表与提交页',
                    'lists_special.html' => '专题聚合展示列表页',
                    'lists_custom-cat.html' => '自定义模型分类专属列表页',
                    // 通用列表与归档 (7)
                    'archive.html' => '通用归档列表页',
                    'category.html' => '分类列表页',
                    'author.html' => '作者专栏与个人发文列表页',
                    'tag.html' => '单个标签关联内容列表页',
                    'lists_tags.html' => '全站标签云/标签聚合页',
                    'lists_archives.html' => '按月份/年份归档聚合页',
                    'lists_search.html' => '全站关键词搜索结果列表页',
                    // 详情与内容展示 (9)
                    'view_single.html' => '通用单页面展示（关于我们、企业介绍等）',
                    'view_article.html' => '文章/资讯正文详情页',
                    'view_product.html' => '商品/产品规格详情与购买页',
                    'view_images.html' => '图集画册大图浏览详情页',
                    'view_download.html' => '下载资源详情与附件下载页',
                    'view_media.html' => '音视频播放器与多媒体详情页',
                    'view_recruit.html' => '职位详情与在线投递页',
                    'view_special.html' => '专题详情展示页',
                    'view_custom-cat.html' => '自定义模型详情页',
                    // 功能组件与系统服务 (9)
                    'breadcrumb.html' => '面包屑导航组件（{{todo:include file="breadcrumb.html"}}）',
                    'banner.html' => '栏目头图/轮播横幅组件',
                    'sidebar.html' => '侧边栏热点推荐/分类导航小工具组件',
                    'comment_section.html' => '文章/产品评论发表与回复列表组件',
                    'inquiry_section.html' => '商品询盘/快速留言交互表单组件',
                    'captcha_modal.html' => '安全验证码弹窗组件',
                    'ajax-pager-article.html' => 'AJAX 无刷新滚动加载卡片模板',
                    'unsubscribe.html' => 'EDM 邮件订阅取消退订通知与处理页',
                    'rss.html' => '全站 RSS 2.0 订阅 XML 输出模板'
                ]
            ],

            // 标签语法全量手册映射（直接来自 template-tags.md）
            'tag_syntax_spec' => [
                'core_syntax' => '{{todo:标签名 属性="值"}}',
                'include_syntax' => '{{todo:include file="header.html"}} (必须使用 file="文件名" 属性，严禁使用 src=)',
                'loop_syntax' => '{{todo:loop posts model="article" limit="10"}} ... {{todo:title}} ... {{/todo:loop}} (第一个参数必须是数据集名称如 posts，后接模型 model 过滤与数量 limit)',
                'thumbnail_standard' => '{{todo:post_thumbnail class="card-thumb"}} or {{todo:if post_thumbnail}} ... {{/todo:if}}',
                'conditional_syntax' => '{{todo:if condition}} ... {{else}} ... {{/todo:if}}',
                'data_output' => '{{todo:title}}, {{todo:url}}, {{todo:excerpt}}, {{todo:content}}, {{todo:views}}',
                'head_output' => '{{todo:todo_head}} (必须置于 <head> 内)',
                'footer_output' => '{{todo:todo_footer}} (必须置于 </body> 闭合前)',
                'full_spec_reference_file' => 'template-tags.md'
            ]
        ];
    }
}

// ---------------------------------------------------------------------
// 辅助函数：扫描素材包并生成与 TodoCMS 模板的精准对标映射表 (Blueprint Mapping)
// ---------------------------------------------------------------------
if (!function_exists('todo_studio_build_material_mapping_table')) {
    function todo_studio_build_material_mapping_table($material_slug = '', $custom_material_url = '') {
        $custom_material_url = trim((string)$custom_material_url);
        $has_custom_url = ($material_slug === '__custom_url__' || !empty($custom_material_url));
        $has_material_pkg = (!empty($material_slug) && $material_slug !== '__custom_url__');

        $rows = [];

        if ($has_material_pkg) {
            $mat_dir = ABSPATH . 'content/materials/' . $material_slug;
            if (is_dir($mat_dir)) {
                $html_files = [];
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($mat_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if (!$f->isDir() && strtolower($f->getExtension()) === 'html') {
                        $rel = substr($f->getRealPath(), strlen($mat_dir) + 1);
                        if (strpos($rel, '.DS_Store') === false) {
                            $html_files[] = str_replace('\\', '/', $rel);
                        }
                    }
                }
                sort($html_files);

                foreach ($html_files as $hf) {
                    $base = strtolower(pathinfo($hf, PATHINFO_FILENAME));
                    $target_tpl = '';
                    $duty = '';

                    if ($base === 'index' || $base === 'home') {
                        $target_tpl = '`01_TARGET_THEME/pc/index.html`';
                        $duty = '【核心门户】100% 对标其首屏 Hero 轮播高度、网格列数比例、Section 布局流向、卡片间距与悬浮动效';
                    } elseif (preg_match('/^(product|products|goods|shop|item|catalog)/', $base)) {
                        $target_tpl = '`01_TARGET_THEME/pc/lists_product.html` & `view_product.html`';
                        $duty = '【商品展示】100% 对标素材包的商品网格卡片长宽比、筛选交互与详情页图文排版';
                    } elseif (preg_match('/^(news|article|articles|blog|blogs|press)/', $base)) {
                        $target_tpl = '`01_TARGET_THEME/pc/lists_article.html` & `view_article.html`';
                        $duty = '【内容动态】100% 对标素材包的时间线/卡片图文流排版与文章正文排版节奏';
                    } elseif (preg_match('/^(about|company|brand|history|story|team)/', $base)) {
                        $target_tpl = '`01_TARGET_THEME/pc/view_single.html`';
                        $duty = '【单页介绍】100% 对标素材包的双栏叙事、时间线、企业愿景图文网格';
                    } elseif (preg_match('/^(service|services|solution|solutions)/', $base)) {
                        $target_tpl = '`01_TARGET_THEME/pc/lists_article.html` (或自定义专题单页)';
                        $duty = '【业务服务】对标素材包的服务特色图标网格、图文交错 Section 视觉结构';
                    } elseif (preg_match('/^(contact|feedback|message|guestbook)/', $base)) {
                        $target_tpl = '`01_TARGET_THEME/pc/lists_guestbook.html`';
                        $duty = '【留言联系】对标素材包的联系方式布局、表单排版、地图嵌入区域';
                    } else {
                        $target_tpl = "`01_TARGET_THEME/pc/lists_custom-cat.html` 或对应模板";
                        $duty = "【定制页面】提取其独特的页面组件排版并融入系统标签";
                    }

                    $rows[] = "| `02_MATERIAL_DESIGN/{$hf}` | {$target_tpl} | {$duty} |";
                }
            }
        } elseif ($has_custom_url) {
            $rows[] = "| 参考站点首页及全局主视觉 | `01_TARGET_THEME/pc/index.html` | 深度还原其 Header 吸顶、Hero 轮播高度、品牌叙事 Section 及网格间距 |";
            $rows[] = "| 参考站点产品列表/详情 | `01_TARGET_THEME/pc/lists_product.html` & `view_product.html` | 对标其产品卡片悬浮动效、多图排版与参数规格区域 |";
            $rows[] = "| 参考站点关于/新闻页 | `01_TARGET_THEME/pc/view_single.html` & `lists_article.html` | 对标其图文节奏、历史进程轴与文章列表 |";
        }

        if (empty($rows)) {
            return "";
        }

        $table = "## 🗺️ 素材包原型与交付模板精准映射蓝图 (Material Blueprint Mapping)\n";
        $table .= "⚠️ **【最高执行优先级】请根据下表的对应关系，逐个将素材包原型精准重构并迁移至目标模板中**：\n\n";
        $table .= "| 素材包原型文件 (02_MATERIAL_DESIGN/) | 对应重构交付目标 (01_TARGET_THEME/pc/) | 重点提取与对标职责要求 |\n";
        $table .= "| :--- | :--- | :--- |\n";
        $table .= implode("\n", $rows) . "\n\n";
        $table .= "> 💡 **【次级系统模板继承法则】**：\n";
        $table .= "> 上表未列出的次级系统模板（如 `404.html`, `rss.html`, `lists_recruit.html`, `view_download.html`, `lists_tags.html`, `users/` 会员中心等）在素材包中无直接原型。\n";
        $table .= "> **此类模板请直接 100% 继承 `03_OFFICIAL_REFERENCE/` 中的官方标准模板结构与系统标签**，仅需使其共享全局 `style.css` 的配色与字体变量，**严禁 AI 凭空捏造或重写破坏其底层逻辑！把 100% 的精力聚焦在上述有原型对标的核心页面上！**\n";

        return $table;
    }
}

// ---------------------------------------------------------------------
// 辅助函数：构建 04_DEVELOPMENT_TASK.md 终极任务契约文档
// ---------------------------------------------------------------------
if (!function_exists('todo_studio_build_development_task_md')) {
    function todo_studio_build_development_task_md($slug, $name, $material_slug = '', $custom_material_url = '') {
        $custom_material_url = trim((string)$custom_material_url);
        $has_custom_url = ($material_slug === '__custom_url__' || !empty($custom_material_url));
        $has_material_pkg = (!empty($material_slug) && $material_slug !== '__custom_url__');

        if ($has_custom_url) {
            $url_display = !empty($custom_material_url) ? $custom_material_url : '（用户自定义参考 URL 站点）';
            $mat_info = <<<MAT
🌐 **选定强参考对标目标（自定义外部站点/原型 URL）**：`{$url_display}`
> **🎯 强参考层级法则（最高优先级对标基线）**：
> 1. **第一层级（DOM 层面要素与 JS 交互动效 - 强对标、最高优先级）**：优先直接参考该对标站点的 DOM 结构层级、页面 UI 布局划分、内容区块流向、排版网格密度与 JS 交互动效（如移动端侧边抽屉、头部吸顶粘滞、多级菜单展开、轮播切换、弹窗交互等），深度还原其交互体验与页面骨架。
> 2. **第二层级（视觉调性与风格补充 - 遵循 Prompt）**：在稳固的 DOM 与交互骨架之上，根据目标主题【{$name}】的业务定位与受众偏好，完成视觉层面的品牌色调、光影质感与图文素材的赋能补充。
MAT;
            $hierarchy_section = <<<HIERARCHY
## 🏆 强参考层级法则（STRONG REFERENCE HIERARCHY - 核心必须严格执行）
**参考目标 URL 具备极高的强参考对标价值。AI 在设计和编码时必须坚决优先对标目标的 DOM 层面要素与交互结构，再依照 Prompt 进行视觉赋能：**

1. **第一层级（DOM 结构与 JS 交互基线 - 强对标、最高优先级）**：
   - **DOM 层面要素与 UI 布局**：直接对标参考目标 URL 的页面 DOM 节点划分、栅格布局、内容流向、Section 区域逻辑划分与层级架构；
   - **排版节奏与信息密度**：严谨对标参考目标的字体层级、行高间距、留白节奏与图文图块排布比例；
   - **JS 交互动效与组件行为**：深度对标参考目标的动态交互体验（如：导航下拉/抽屉滑出动效、轮播图切换过渡、Tab 选项卡切换、悬停动效、筛选器伸缩、模态弹窗等交互行为），确保体验顺滑丝滑。

2. **第二层级（视觉调性与风格补充 - 遵循目标主题【{$name}】业务需求）**：
   - 在上述稳固且高品质的 DOM 结构与 JS 交互框架之上，根据目标主题【{$name}】的真实业务定位，精准完成视觉层面的风格调性、主辅色彩搭配、高级光影阴影、材质质感及图文意境的补充，杜绝千篇一律或脱离实际业务场景。
HIERARCHY;
        } elseif ($has_material_pkg) {
            $mat_info = <<<MAT
📦 **选定强参考对标目标（本地素材包+高保真设计原型）**：`{$material_slug}`（请重点审查 `02_MATERIAL_DESIGN/` 中的高保真设计效果图与原型代码）
> **🎯 强参考层级法则（最高优先级对标基线）**：
> 1. **第一层级（DOM 层面要素与 JS 交互动效 - 强对标、最高优先级）**：优先直接参考素材包中的 DOM 层面要素及结构（UI 布局划分、栅格排版、JS 交互动效与组件行为），以此为最高基准构建模板。
> 2. **第二层级（视觉调性与风格补充 - 遵循 Prompt）**：在遵循素材包 DOM 与交互结构的基础上，根据目标主题【{$name}】的业务定位完成视觉层面的风格调性与色彩补充（注意：设计美学与交互动效强对标，代码严格遵循 TodoCMS 标签规范）。
MAT;
            $hierarchy_section = <<<HIERARCHY
## 🏆 强参考层级法则（STRONG REFERENCE HIERARCHY - 核心必须严格执行）
**选定的本地素材包具备极高的强参考对标价值。AI 在设计和编码时必须坚决优先对标目标的 DOM 层面要素与交互结构，再依照 Prompt 进行视觉赋能：**

1. **第一层级（DOM 结构与 JS 交互基线 - 强对标、最高优先级）**：
   - **DOM 层面要素与 UI 布局**：直接对标素材包原型（位于 `02_MATERIAL_DESIGN/`）的页面 DOM 节点划分、栅格布局、内容流向、Section 区域逻辑划分与层级架构；
   - **排版节奏与信息密度**：严谨对标素材包的字体层级、行高间距、留白节奏与图文图块排布比例；
   - **JS 交互动效与组件行为**：深度对标素材包的动态交互体验（如：导航下拉/抽屉滑出动效、轮播图切换过渡、Tab 选项卡切换、悬停动效、筛选器伸缩、模态弹窗等交互行为），确保体验顺滑丝滑。

2. **第二层级（视觉调性与风格补充 - 遵循目标主题【{$name}】业务需求）**：
   - 在上述稳固且高品质的 DOM 结构与 JS 交互框架之上，根据目标主题【{$name}】的业务定位，精准完成视觉层面的风格调性、主辅色彩搭配、高级光影阴影、材质质感及图文意境的补充，杜绝千篇一律或脱离实际业务场景。
HIERARCHY;
        } else {
            $mat_info = <<<MAT
📦 **选定构建模式**：`【纯净官方基准开发（无外部参考素材）】`
> **🎯 纯净设计构建准则（无外部原型束缚，全由官方规范+Prompt创意驱动）**：
> 1. **结构与标签标准**：以 `03_OFFICIAL_REFERENCE/` 官方 38 套基础模板与 TodoCMS 系统双括号标签为底层技术底座；
> 2. **UI 视觉与美学创意**：当前**未绑定任何外部参考素材或原型**，绝无任何旧原型/外包代码的 DOM 约束！请完全遵循目标主题【{$name}】的业务定位，以最高水准的现代化国际化独立站审美（高级弱渐变色彩调性、个性化精致卡片排版、丰富栅格、微动效）全新设计并交付 01_TARGET_THEME/ 的全套模板！
MAT;
            $hierarchy_section = <<<HIERARCHY
## 🏆 纯净设计构建准则（FREESTYLE HIGH-END DESIGN - 官方底座 + 创意审美）
**当前主题未绑定特定外部素材包或外部参考原型。你拥有充分的视觉美学创意自由度：**

1. **全站 DOM 结构与 UI 布局自由设计**：
   - 完全遵循用户对主题【{$name}】的品牌定位与国际化高水准独立站审美，自由设计层次丰富、栅格考究的 Section 结构与组件卡片；
   - 杜绝生搬硬套任何旧素材结构，依照需求构建最具艺术美感与视觉冲击力的前台界面。

2. **精致 JS 交互动效**：
   - 根据主题调性自主设计并实现丝滑流畅的交互行为（包括导航粘滞吸顶、移动端侧滑抽屉、画廊滤镜切换、模态弹窗、无刷新异步交互等）。

3. **严格遵守 TodoCMS 官方标签底座**：
   - 严格以 `03_OFFICIAL_REFERENCE/` 中的官方 38 套基础模板、头尾分离、组件化与数据模型为交付规范。
HIERARCHY;
        }

        $blueprint_mapping_section = todo_studio_build_material_mapping_table($material_slug, $custom_material_url);

        $cross_industry_section = <<<CROSS
## 🧬 跨行业形态与业务解耦转译协议 (Cross-Industry Structure Extraction Protocol)
⚠️ **【极其重要：严禁因行业不符而弃用素材原型！】**
1. **素材包是“建筑骨架”，目标主题是“装修风格与业务内涵”**：
   - 参考素材包（`02_MATERIAL_DESIGN/`）可能属于其他行业原型（例如素材包是卫浴瓷砖、工业数码、建筑地产，而目标主题是鲜花花艺、艺术画廊、时尚服饰等）；
   - **绝对禁止因为行业文字不相符就擅自判定素材“无关联”而放弃参考（杜绝开盲盒与自嗨创作）！**
2. **AI 必须执行的“骨架抽取与业务置换”工作流**：
   - **A. 提取骨架（100% 忠实保留素材包原型）**：
     - **页面结构节奏**：100% 继承原型的 Section 划分节奏（首屏 Hero 巨幅轮播高度、品牌叙事双栏图文、3列/4列网格展示、图文交错模块、Sticky 吸顶导航）；
     - **DOM 树与排版网格**：100% 继承原型的 HTML 层次划分与 Tailwind 原子类名（如 `max-w-7xl mx-auto px-6`、`grid grid-cols-1 md:grid-cols-3 gap-8`、`aspect-[4/3]` 等）；
     - **微动效与触觉反馈**：100% 继承原型的悬停缩放（`hover:scale-[1.02]`）、按钮箭头位移（`group-hover:translate-x-1.5`）、移动端抽屉导航等交互动效。
   - **B. 置换业务（注入目标主题 {{THEME_NAME}} 的专属业务灵魂）**：
     - **文案置换**：将素材包中的原行业文字（如卫浴建材词汇）替换为符合目标主题 **{{THEME_NAME}}** 的高端国际化英文文案；
     - **图片置换**：将素材包中的原图片链接替换为目标主题的演示图片路径（如 `{{todo:theme_url}}/assets/images/data/cover_1.jpg` 或 `{{todo:post_thumbnail}}`）；
     - **动态标签注入**：将静态链接（如 `href="product.html"`）替换为 TodoCMS 官方动态标签（如 `href="{{todo:category_url id='1'}}"` 或 `href="{{todo:url}}"`）。
   - **🎯 终极验收标准**：最终交付的 `01_TARGET_THEME/pc/index.html`，其页面骨架和组件分布必须能让用户一眼看出是素材包 `02_MATERIAL_DESIGN/index.html` 的结构升级版！
CROSS;

        $ai_studio_guide = <<<GUIDE
## 🤖 Google AI Studio 专属工作模式与分步投喂建议
⚠️ **【建议避开 "Build an App" 模式，使用 Chat Prompt 模式】**：
1. **模式避坑说明**：
   - Google AI Studio 首页的 "Build an app" 是为前端 Vite + React 沙箱设计的，强行在该模式生成会混入不必要的 `package.json` 和 `vite.config.ts`。
   - **推荐操作**：请在 Google AI Studio 中点击 **"Create new prompt" ➡️ "Chat prompt"**（或 "System Instructions"），直接上传本工程包 ZIP 并附带本 Prompt！
2. **三步递进式生成法（击破单次生成 Token 截断与注意力衰减）**：
   - **第一轮对话（核心门面与主基调）**：让 AI 首先交付最重要的核心门面：`index.html`、`header.html`、`footer.html` 与 `style.css`，确保 100% 还原素材包的布局与动效；
   - **第二轮对话（核心业务详情页）**：让 AI 根据映射表交付 `lists_product.html`、`view_product.html`、`lists_article.html` 等内容页；
   - **第三轮对话（数据注入与自检）**：让 AI 交付 `functions.php` 的演示数据与其余次级模板。
GUIDE;

        $template = <<<'MARKDOWN'
# 🎯 TodoCMS 主题智能开发交付契约（Google AI Studio / LLM 专属）

你好！你正在为 **TodoCMS**（一个基于 PHP + 原生 HTML 双括号模板引擎的高性能开源内容管理系统）进行专业主题 **{{THEME_NAME}} (`{{THEME_SLUG}}`)** 的开发交付。

{{MAT_INFO}}

---

{{HIERARCHY_SECTION}}

---

{{CROSS_INDUSTRY_SECTION}}

---

{{BLUEPRINT_MAPPING_SECTION}}

---

{{AI_STUDIO_GUIDE_SECTION}}

---

## 🚨 严正红线（STRICT RED LINES - 违者直接判为交付失败）
1. ❌ **严禁生成任何单页应用展示（Showcase SPA）或 Web 模拟页**！
   - 严禁输出任何带有“仿真模式切换”、“全站首页模拟演示”、“假离线包下载”的展示类网页！
   - 严禁虚构任何下载链接（例如 `<a href="...zip">下载 {{THEME_SLUG}}.zip 离线包</a>`）！这是极其严重的跑偏行为，会导致用户在浏览器中下载到一个 10KB 的 404 错误页！用户是在开发真实系统的主题，不需要任何假下载按钮！
2. ❌ **绝对禁止输出 React / Vue / Next.js / Angular / Svelte / TSX / JSX / Vite 等任何前端框架工程！**
   - TodoCMS 是通过后台直接激活主题运行的，无法解析或执行任何 SPA 前端框架和 node_modules 构建产物。
3. ❌ **必须输出的文件与交付格式**：
   - 请以具体的 Markdown 代码块输出需要修改或完善的模板文件（例如 `01_TARGET_THEME/pc/index.html`、`01_TARGET_THEME/pc/lists_article.html`、`01_TARGET_THEME/pc/view_article.html`、`01_TARGET_THEME/pc/assets/css/style.css`、`01_TARGET_THEME/functions.php` 等）。
   - 每个代码块首行明确标明目标文件路径，例如：
     ```html
     <!-- FILE: 01_TARGET_THEME/pc/index.html -->
     ...
     ```
4. ❌ **严格标签语法要求（避免编译致命错误）**：
   - 严禁在模板标签中写嵌套引号（如 `{{item.thumbnail|default='...'}}`），这会导致模板引擎报 `syntax error, unexpected 'thumbnail'` 编译失败！
   - 渲染文章/产品缩略图**必须严格对标官方原标准语法**：
     ```html
     {{todo:if post_thumbnail}}
         {{todo:post_thumbnail class="card-thumb"}}
     {{/todo:if}}
     ```
   - ⚠️ **资源 URL 路径规则**：TodoCMS 的 `{{todo:theme_url}}` 和 `get_template_directory_uri()` 在运行时已经根据访问设备自适应携带了 `/pc` 或 `/mobile`。因此在模板和 `functions.php` 中引用 assets 时必须写为 `{{todo:theme_url}}/assets/...` 或 `$theme_url . '/assets/...'`，严禁再手动拼接 `/pc/`，否则会导致 404！
5. ❌ **严禁使用任何 WordPress 专属风格与函数（我们是 TodoCMS，绝对不是 WordPress！）**：
   - **严正告诫**：TodoCMS 具有完全自主独立的架构和钩子系统。虽然部分模板标签理念有借鉴，但**底层函数与执行钩子完全不同，绝对不要使用任何 WordPress 专属函数与动作钩子**！
   - 违规生成的 WordPress 代码在 TodoCMS 中将直接报错或被当成无效调用！
   - **禁止与正确写法严格对照表**：
     | 严禁使用的 WordPress 伪代码 (违者不合格) | TodoCMS 官方原生必须写法 | 原因与机制说明 |
     | :--- | :--- | :--- |
     | `add_action('wp_enqueue_scripts', ...)` | `add_action('todo_enqueue_scripts', ...)` | TodoCMS 专属资源入队钩子 |
     | `wp_enqueue_style(...)` | `todo_enqueue_style(...)` | 注册并在 `{{todo:todo_head}}` 输出样式 |
     | `wp_enqueue_script(...)` | `todo_enqueue_script(...)` | 注册并在 `{{todo:todo_footer}}` 输出脚本 |
     | `add_action('wp_head', ...)` | `add_action('todo_head', ...)` 或模板中使用 `{{todo:todo_head}}` | TodoCMS 专属头部钩子 |
     | `add_action('wp_footer', ...)` | `add_action('todo_footer', ...)` 或模板中使用 `{{todo:todo_footer}}` | TodoCMS 专属尾部钩子 |
     | `add_action('after_setup_theme', ...)` | `add_action('todo_theme_activated', ...)` | 主题激活自动数据初始化钩子 |
     | `register_nav_menus(...)` | **完全不需要且不存在！** | TodoCMS 菜单由系统后台统一配置，模板直接使用 `{{todo:nav_menu theme_location="primary" ...}}` 即可调用 |
     | `add_theme_support('post-thumbnails')`<br>`add_theme_support('title-tag')`<br>`add_theme_support('custom-logo')` | **完全不需要且不存在！** | TodoCMS 系统核心已默认全面支持，模板直接写 `<title>{{todo:page_title}}</title>`、`{{todo:post_thumbnail}}`、`{{todo:site_logo}}` |
     | `is_wp_error(...)` | **严禁使用！** | TodoCMS 不存在 `WP_Error` 类 |
6. ❌ **严禁编撰或自制会员中心！严禁硬编码会员中心路径！**：
   - **严正告诫**：TodoCMS 的会员中心体系（登录、注册、找回密码、个人中心、购物车、订单、结算等全套页面与业务逻辑）**已经在 `01_TARGET_THEME/pc/users/` 完整预装并完全对标官方原标准**！
   - **绝对禁止 AI 自己重新编撰、生造一套会员中心页面或自定义路由！** 开发者或 AI 严禁篡改其内置表单接口，只需做全局 CSS 风格统一。
   - 头部仅需引用官方预设标签指向登录入口与购物车入口，**严禁手拼路径**（严禁写 `/users/login` 或 `/users/cart`）：
     - 登录页面：`{{todo:login_url}}`（自适应生成正确登录地址）
     - 退出登录：`{{todo:logout_url}}`
     - 个人中心主页：`{{todo:user_url}}`
     - 购物车页面：`{{todo:user_page_url page='cart'}}`
     - 我的订单页面：`{{todo:user_page_url page='orders'}}`
     - 结算页面：`{{todo:user_page_url page='checkout'}}`
     - 个人资料页面：`{{todo:user_page_url page='profile'}}`
7. ❌ **默认以英文为主语言，所有模板静态字符必须 100% 使用 `{{todo:t}}` 包装（严禁语言包单引号未转义引起崩溃）**：
   - 本主题默认设计采用**高质感现代英文（English）**为主语言（例如：`{{todo:t text="Explore Our Collection"}}`、`{{todo:t text="Add to Cart"}}`、`{{todo:t text="Sign In"}}` 等），面向国际化独立站受众。
   - **严禁**在模板中出现任何未包装的裸露中文字符串或裸露英文！所有前台展示文本（按钮名、栏目标题、搜索占位符、操作文案）都必须写为 `{{todo:t text="..."}}` 或 `{{todo:t|text=...}}`。
   - 多语言包已同步至 `pc/assets/lang/`（含 `en_US.php`, `zh_CN.php`, `ar_SA.php` 等），系统会在运行时通过 `{{todo:t}}` 标签自动完成语种翻译与替换。
   - ⚠️ **多语言语言包 PHP 语法严正防崩禁令（极其重要）**：在 `pc/assets/lang/*.php` 中编写多语言字典数组时，**若键名或文本包含英文撇号/单引号（如 `Nature's`, `Don't`, `It's` 等），必须严格转义 `\'` 或使用双引号包裹 `\"...\"`**！严禁出现未转义的 `'Nature's'`，否则会导致 PHP 报 `syntax error, unexpected 's' (T_STRING), expecting ']'` 致命解析错误，导致全站和后台登录彻底崩溃！
8. ❌ **严禁使用无效纯色占位符交付！必须生成真实的 `screenshot.png` 与相应设计图片**：
   - 主题根目录必须存在真实、高清的主题缩略图 `screenshot.png`（800x600 或 1200x900），完美展现首页的最终视觉效果；
   - `/pc/assets/images/data/` 中的演示封面图（例如 `cover_1.jpg` ~ `cover_10.jpg`、尤其是 `cover_6.jpg`、`default-banner.jpg`、`default-product.jpg`、`default-article.jpg` 等）**严禁交付带有“Cover #6”等文字的单色简陋方块**！必须对标 `02_MATERIAL_DESIGN/` 中的真实设计稿与效果图，提供真实、优雅、符合目标主题【{{THEME_NAME}}】品牌调性的高保真摄影/设计图片，确保系统开箱即用、图文并茂！
9. ❌ **严禁粗制滥造一次性交付！必须完成并附带【至少 5 轮深度自检自测校验报告】**：
   - **严禁“写完第一遍就直接交付”！** 样式错误、代码报错、链接 404 就提交属于严重违规。AI 在输出最终代码前，必须在内部至少执行 5 轮严格的深度自审与推演排错。
   - **交付产物首部必须显式附带《交付前 5 轮深度自测校验报告》**：
     - **第 1 轮（语法、底层规范与安全）**：自测 functions.php 绝无任何 WordPress 钩子（`wp_enqueue_*` / `register_nav_menus` / `add_theme_support` / `after_setup_theme`），模板标签无嵌套引号；自测 `{{todo:csrf_token}}` 绝无裸露输出在 UI 上（必须置于隐藏域 `<input type="hidden" name="_todononce" value="...">`）。
     - **第 2 轮（国际化、多语言与价格规范）**：自测模板内 100% 静态 UI 字符串均已使用 `{{todo:t text="..."}}` 包裹；自测价格标签（如 `{{todo:post_current_price}}`）前**绝无手动硬编码货币符号**（杜绝 `$$0.15` 双符号 Bug）；**自测 `pc/assets/lang/*.php` 语言包中包含撇号的键名与文本（如 Nature\'s, Don\'t）均已转义单引号或采用双引号，确保语言包 0 语法错误**。
     - **第 3 轮（会员中心与动态路由）**：自测绝无手写 `/users/login` 或 `/users/cart`，登录必须为 `{{todo:login_url}}`，购物车必须为 `{{todo:user_page_url page='cart'}}`，严禁自造会员中心页面；**自测会员中心登录/注册/找回密码唤起图形验证码时，`#captcha-modal` / `.todo-captcha-modal` 具备完整滑块拼图样式与 `window.Captcha` 交互驱动，绝无无样式或空白弹窗**。
     - **第 4 轮（视觉设计、Tailwind 样式与 Motion 微动效自测）**：自测真实 `screenshot.png` 与 `cover_6.jpg` 等设计图的视觉还原度，杜绝假占位图；自测模板中所有原子类名（如 `p-8`, `px-6`, `grid`, `flex` 等）均由 Tailwind 开箱支持，绝无样式坍塌；**自测首页首屏是否应用了优雅错落的入场微动效，自测按钮与箭头是否具备 `group-hover:translate-x-1.5` 与 `hover:scale-[1.02]` 微交互反馈，自测滚动入场是否遵循“弱+细”原则，绝无生硬死板的纯静态卡顿感**；自测 `breadcrumb-list` 面包屑内层 DOM 具备完整样式；自测如有必要已覆盖组件原有样式。
     - **第 5 轮（全量静态资产拓扑保全与基础设施零缺失）**：
       - [x] `pc/users/` 用户中心 19 个模板（login, cart, checkout, orders, profile 等）及 `skin/` 目录 100% 完好在位（绝无裁剪或丢失）；
       - [x] `pc/assets/css/tailwind-atomic.min.css`（Tailwind 原子样式底座）100% 完好在位；
       - [x] `pc/assets/js/motion.min.js` 与 `pc/assets/js/motion-helper.js`（Motion 微动效双引擎）100% 完好在位；
       - [x] `pc/assets/js/csrf-manager.js` 与 `pc/assets/js/submit-guard.js`（全站安全防重底座）100% 完好在位；
       - [x] `pc/assets/css/hero-slider.css` 与 `pc/assets/js/hero-slider.js`（轮播系统静态资产）100% 完好在位；
       - [x] `pc/assets/lang/`（官方多语言包目录及 `en_US.php`, `zh_CN.php`, `ar_SA.php` 等）100% 完好在位；
       - [x] `pc/assets/images/data/`（真实封面图 `cover_1.jpg`~`cover_10.jpg`、`default-banner.jpg`、`default-product.jpg` 等）100% 完好在位；
       - [x] `screenshot.png` 真实高保真缩略图 100% 完好在位；
       - [x] 自测全站图标统一使用 FontAwesome 6（杜绝空白方块）；自测控制台零 404、零未定义报错。
   - **凡未附带或未通过 5 轮深度自测的代码，直接判定为交付失败！**
10. ❌ **前端基础底座：全面采用 Tailwind CSS + Motion 双引擎架构，严格遵循“弱+细”微动效原则（开箱即用，严禁在 style.css 中手写重复动画与原子类）**：
    - **双引擎底层标配（已全量预装并全局入队）**：
      1. **Tailwind CSS**（`assets/css/tailwind-atomic.min.css`）：100% 完整覆盖 `p-8`、`px-6`、`py-12`、`mb-6`、`grid`、`flex`、`items-center`、`justify-between`、`rounded-xl`、`shadow-sm`、`gap-8`、`text-stone-200` 等全量原子类；
      2. **Motion 微动效引擎**（`assets/js/motion.min.js` + `assets/js/motion-helper.js`）：基于官方开源 Web Animations API 硬件加速驱动，全局注入 `window.Motion` 与 `window.TodoMotion`。
    - **🌟 核心设计准则：严格贯彻“弱+细”微动效原则（杜绝大面积生硬或过度晃眼）**：
      - **A. 首屏 Hero 错落入场 (First-Screen Stagger)**：首屏 Hero 区域的标语、主副标题、CTA 按钮绝不能僵硬瞬间弹出！必须采用轻柔阶梯错落渐显：
        - 极简开箱写法：只需给对应 HTML 标签声明 `data-motion="fade-up"`，首屏直接加 `data-motion-trigger="load"`，阶梯延迟加 `data-motion-delay="0.08"`、`data-motion-delay="0.16"`；
        - 位移幅度必须轻微克制（`translateY(16px)` -> `0`，严禁大幅度跳动），缓动曲线采用极柔和减速 `[0.16, 1, 0.3, 1]`；
      - **B. 按钮与箭头微交互 (Button & Arrow Micro-Interactions)**：交互按钮绝不能呆板冷冰，必须具备细腻灵动的触觉反馈：
        - 按钮本体结合 Tailwind：`hover:scale-[1.02] active:scale-[0.98] transition-all duration-300`；
        - 箭头/图标联动：在父级添加 `group`，子级图标声明 `transition-transform duration-300 group-hover:translate-x-1.5`，实现悬停时箭头平滑向右微推 6px 的高级交互；
      - **C. 滚动视口联动触发 (Scroll InView Reveal)**：内容区域卡片、产品列表、服务网格在用户向下滚动进入视口 15% 时自动平滑浮现：
        - 极简开箱写法：直接在卡片容器写 `data-motion="fade-up"` 或 `data-motion="scale-up"`，底层 `motion-helper.js` 会通过 `Motion.inView()` 自动监听并顺滑触发；
      - **D. 滚动进度与视差指示**：需要页面阅读进度条时，仅需放置 `<div class="h-1 bg-primary origin-left" data-motion-scroll-progress></div>` 即可开箱自动联动滚动条！
    - **严正禁令**：**严禁在 `style.css` 中手动手写冗长复杂的 `@keyframes` 和原生动画逻辑**！一切原子类与动效均由 Tailwind + Motion 开箱即用驱动，AI 应将 100% 的精力聚焦在高级审美布局、图文构图与精致细节设计上！
11. ❌ **严禁在价格标签前硬编码货币符号！杜绝 `$$0.15` 双货币符号展示**：
    - TodoCMS 系统底座与模板标签（`{{todo:post_current_price}}`、`{{todo:current_price}}`、`{{todo:post_original_price}}`）在默认格式化时**已经自动携带并格式化了当前站点的货币符号**（如 `$15.00` 或 `¥88.00`），或者由前端脚本 `window.TODO.CURRENCY_SYMBOL` 进行动态注入。
    - **严禁**在 HTML 模板中写成 `$ {{todo:post_current_price}}` 或 `￥{{todo:current_price}}`，否则会导致前台页面输出 `$$0.15` 这样的双货币符号！展示价格直接书写 `<span class="current-price">{{todo:post_current_price}}</span>` 即可。
12. ❌ **图标统一使用稳定开源第三方图标库 FontAwesome 6，严禁使用易显示空白的 todofont**：
    - 官方原 `todofont` 仅包含少数内建图标，极易因字符映射缺失导致页面图标显示为空白方块。全站所有矢量图标统一使用 FontAwesome 6 图标库（如 `<i class="fa-solid fa-magnifying-glass"></i>`、`<i class="fa-solid fa-bag-shopping"></i>`、`<i class="fa-solid fa-user"></i>`、`<i class="fa-solid fa-bars"></i>`、`<i class="fa-solid fa-arrow-right"></i>`、`<i class="fa-solid fa-chevron-right"></i>` 等），`functions.php` 与 `header.html` 已自动引入 FontAwesome 6，保证图标稳定、高清渲染！
13. ❌ **轮播系统静态资源必须真实引入与对齐！**：
    - 模板中若使用了 `hero-slider`、`slider-track`、`slide`、`slider-prev`、`slider-next` 等轮播系统类名，必须如实引入官方原 `assets/css/hero-slider.css` 与 `assets/js/hero-slider.js`，`functions.php` 中已预置自动排队加载逻辑。严禁只在 HTML 中堆砌轮播类名却不准备实质的 CSS/JS 静态支持！
14. ❌ **必须具备 `breadcrumb-list` 内部完整生成 DOM 结构的 CSS 样式**：
    - `{{todo:breadcrumbs}}` 或 `{{todo:include file="breadcrumb.html"}}` 在页面中输出的标准 DOM 结构如下：
      ```html
      <ul class="breadcrumb-list">
          <li><a href="...">首页</a><span class="breadcrumb-separator"> &gt; </span></li>
          <li><a href="...">栏目名</a><span class="breadcrumb-separator"> &gt; </span></li>
          <li><span class="breadcrumb-current">当前标题</span></li>
      </ul>
      ```
    - 样式表 `style.css` 中必须完整定义 `.breadcrumb-list`、`.breadcrumb-list li`、`.breadcrumb-list li a`、`.breadcrumb-list li .breadcrumb-separator` 以及 `.breadcrumb-list li .breadcrumb-current`，严禁遗漏面包屑内部 DOM 的 CSS 规则！
15. ❌ **CSRF 令牌必须作为隐藏表单字段生成，严禁在页面 UI 上直接裸露输出！**：
    - `{{todo:csrf_token}}` 标签解析出的是长串哈希防伪字符串，**严禁直接裸露写在 HTML 内容区**（如 `<div>{{todo:csrf_token}}</div>` 会导致一长串无意义的哈希字符直接渲染在网页屏幕上！）。
    - 必须严格参照官方标准用法，将其置于隐藏域中：
      ```html
      <input type="hidden" name="_todononce" value="{{todo:csrf_token name='forgot_password'}}">
      ```
      或直接使用官方原生快捷标签生成隐藏域：
      ```html
      {{todo:csrf_field name='forgot_password'}}
      ```
16. ❌ **如有必要，必须主动覆盖系统内置组件原有样式（贴合每一个新设计的主题）**：
    - 系统公共组件（如 `.todo-language-switcher.dropdown-style .current-language:hover`、`.todo-site-switcher.site-dropdown-style .current-site:hover`、`.breadcrumb-list` 等）在底座公共 CSS 中仅预设了通用朴素样式（例如 hover 为深灰 `#333`）。
    - **新设计的主题若使用了特定调性配色（如暗黑顶栏、奢华香槟金、玫瑰暗红、极简灰白等），必须在 `assets/css/style.css` 中主动覆盖这些组件的原有样式**！
    - 特别是 `.todo-language-switcher.dropdown-style .current-language:hover` 与 `.breadcrumb-list`，必须贴合当前主题的 `--primary-color`、微底色胶囊悬浮态、卡片阴影与圆角，确保系统内置组件与每一个新设计的主题完美融合、浑然一体！
17. ❌ **严禁破坏 functions.php 中的激活数据自动填充机制**：
    - `functions.php` 中包含系统标准的 `todo_theme_activated` 动作钩子（即主题激活时自动向数据库填充演示分类、文章、商品与默认配置）。
    - **严禁**在交付 `functions.php` 时将此动作钩子删除或清空！必须完整保留或按新主题风格增强该数据初始化方法，确保主题在后台一键激活时系统数据与前台展示立即完整呈现！
18. ❌ **严禁篡改 theme.json 标准规范结构**：
    - TodoCMS 系统识别主题严格依赖 `theme.json` 中的标准定义（`slug`, `name`, `version`, `author`, `directories` 等）。
    - **严禁**删改 `"slug"` 字段或将 `"directories"` 键值清空！必须保证包含 `"pc": "pc"`, `"users": "pc/users"`, `"images_data": "pc/assets/images/data"` 的规范映射，杜绝系统识别失败或路由无法解析！
19. ❌ **严禁删除或跳过 pc/users/ 会员中心模板**：
    - TodoCMS 会员系统包含 19 个内建用户中心 `.htm` 模板（登录、注册、找回密码、购物车、结算、订单列表与详情等），已全部放置在 `pc/users/` 目录。
    - **严禁**删除此目录或将其移位！在交付主题时必须保证 `pc/users/` 完整存在于工程包中。
20. ❌ **全站主导航多级子菜单必须具备高质感 `.sub-menu` 悬浮下拉样式**：
    - 当系统后台菜单配置了二级/三级子菜单时，TodoCMS 会自动在 `has-children` 的 `<li>` 内输出 `<ul class="sub-menu">`。
    - **必须在 `assets/css/style.css` 中为 `.nav-menu .sub-menu` 提供完整的定位、背景阴影、微动画与悬停展开样式**，确保子菜单高质感呈现，严禁子菜单无样式裸露或破坏导航栏布局！
21. ❌ **严禁破坏或伪造安全滑块验证码弹窗 `captcha_modal.html`！用户中心必须具备 100% 完整样式与底层真实驱动**：
    - **系统级基础设施地位**：`captcha_modal.html` **绝不是普通可随意改写的静态 HTML 片段，而是 TodoCMS 全站最高等级的风控安全基础设施**！它被会员中心登录（`users/login.htm`）、会员注册（`users/register.htm`）、找回密码（`users/forgot-password.htm`）、留言板（`lists_guestbook.html`）等直接引用（`{{todo:include file="captcha_modal.html"}}`）并通过 `Captcha.show()` 动态唤起！
    - **三大核心禁令（违者必究）**：
      - ❌ **严禁简化为不可用的“假弹窗”**（例如自己手写一个只有静态文字、没有拼图图片槽位、没有滑轨按钮的伪 modal）；
      - ❌ **严禁篡改 DOM 契约与 ID**！外层弹窗容器必须为 `<div id="captcha-modal" class="todo-captcha-modal">`（必须同时支持 `#captcha-modal`、`#todo-captcha-modal` 与 `.todo-captcha-modal` 类选择器），子元素必须完整包含 `.captcha-box`、`.captcha-img-box`、`#captcha-bg`、`#captcha-piece`、`.captcha-slider-box`、`#captcha-slider-bar`、`#captcha-slider-btn`、`.captcha-text`、`.captcha-footer`、`#captcha-close`、`#captcha-refresh`；
      - ❌ **严禁删减底层驱动脚本 `window.Captcha`**！脚本内置向 `/api/captcha/slider.php` 请求碎片图、PoW 运算、拖拽位移轨迹记录与服务端验算交互，严禁删除此脚本！
    - **样式完备要求（严禁无样式白屏或错位坍塌）**：
      - 必须保证 `#captcha-modal, #todo-captcha-modal, .todo-captcha-modal` 以及内部 `.captcha-box`、`.captcha-slider-box`、`.captcha-slider-bar`、`.captcha-slider-btn` 拥有完整样式（包含全局居中浮层、毛玻璃模糊半透明背景遮罩、拼图加载动画、圆角滑轨与悬停反馈）；
      - AI 可根据当前主题色调（`--primary-color`）对滑块高亮颜色进行主题化适配，但**基础排版、交互与 DOM 结构必须完整可靠**！
22. ❌ **严禁在交付时丢失或裁剪任何系统基础设施静态资源与用户中心（零丢失严苛红线）**：
    - **灾难性错误通报**：近期 AI 生成主题中频繁出现严重失误——**AI 在交付时仅输出了 HTML 模板与 `pc/assets/css/style.css` + `pc/assets/js/theme.js` 两个文件，把脚手架工程中不可或缺的 `pc/users/` 用户中心、`tailwind-atomic.min.css`、`motion.min.js`、`csrf-manager.js`、`pc/assets/lang/` 语言包及封面图全量丢弃**！导致用户部署后用户中心 404、Tailwind 原子类失效坍塌、微动效引擎丢失、CSRF 校验报错。这是**彻底失败的无效交付**！
    - **交付规范双轨制要求（AI 必须严格执行）**：
      1. **若 AI 组织为 ZIP 压缩包交付**：压缩包内必须完整包含下表所列的**全量 52 项资产与目录**，严禁只打包模板和两个样式脚本！
      2. **若 AI 使用 Markdown 代码块交付**：首部必须以粗体高亮警告用户：
         > ⚠️ **【重要部署与解压警告】**：本次交付为主题模板与业务样式定制代码。请在已生成的脚手架工程基础上覆盖对应文件，**严禁清空脚手架原有的 `pc/users/` 用户中心、`pc/assets/css/tailwind-atomic.min.css`、`pc/assets/js/motion.min.js`、`pc/assets/js/motion-helper.js`、`pc/assets/lang/` 语言包及 `pc/assets/images/data/` 资源**！全量静态基础设施必须 100% 完整保留！

---

## 📋 全量主题交付拓扑清单与资产保护总表（52 项全景清单）
⚠️ **严苛交付契约（全量 38 套模板 + 14 项核心驱动与基础设施静态资产，必须 100% 完备交付）**：
TodoCMS 是生产级企业 CMS，每个模板对应后台不同的内容模型与业务路由，每个静态资源对应系统底座能力。
**绝不能只交付几个主要模板或丢弃静态基础设施！** AI 必须在交付成果中完整保全以下 52 项资产：

### 板块一：38 套全量核心页面与组件模板（位于 `01_TARGET_THEME/pc/`）
| 序号 | 模板文件路径 | 模板职责与对应系统模型 | 交付状态要求 |
| :--- | :--- | :--- | :--- |
| 01 | `01_TARGET_THEME/pc/index.html` | 全站首页（Hero 横幅、特色精选、动态、推荐） | 必须交付完整代码 |
| 02 | `01_TARGET_THEME/pc/header.html` | 全局顶部公用页头（Logo、导航、语言切换、登录态） | 必须交付完整代码 |
| 03 | `01_TARGET_THEME/pc/footer.html` | 全局底部公用页脚（版权、页脚菜单、社交媒体） | 必须交付完整代码 |
| 04 | `01_TARGET_THEME/pc/sidebar.html` | 全局公用侧边栏（分类列表、热门文章、标签云） | 必须交付完整代码 |
| 05 | `01_TARGET_THEME/pc/breadcrumb.html` | 公用面包屑导航组件 | 必须交付完整代码 |
| 06 | `01_TARGET_THEME/pc/banner.html` | 栏目/内页公用横幅组件 | 必须交付完整代码 |
| 07 | `01_TARGET_THEME/pc/category.html` | 分类通用列表主页 | 必须交付完整代码 |
| 08 | `01_TARGET_THEME/pc/archive.html` | 归档通用列表主页 | 必须交付完整代码 |
| 09 | `01_TARGET_THEME/pc/author.html` | 作者专栏与文章列表页 | 必须交付完整代码 |
| 10 | `01_TARGET_THEME/pc/tag.html` | 标签聚合列表页 | 必须交付完整代码 |
| 11 | `01_TARGET_THEME/pc/lists_article.html` | 文章模型列表页 (Article List) | 必须交付完整代码 |
| 12 | `01_TARGET_THEME/pc/lists_product.html` | 商品模型列表页 (Product Catalog) | 必须交付完整代码 |
| 13 | `01_TARGET_THEME/pc/lists_images.html` | 图集/相册模型列表页 (Gallery List) | 必须交付完整代码 |
| 14 | `01_TARGET_THEME/pc/lists_download.html` | 下载模型列表页 (Download List) | 必须交付完整代码 |
| 15 | `01_TARGET_THEME/pc/lists_media.html` | 视频/多媒体模型列表页 (Media List) | 必须交付完整代码 |
| 16 | `01_TARGET_THEME/pc/lists_recruit.html` | 招聘模型列表页 (Recruit List) | 必须交付完整代码 |
| 17 | `01_TARGET_THEME/pc/lists_guestbook.html` | 留言板模型列表页 (Guestbook) | 必须交付完整代码 |
| 18 | `01_TARGET_THEME/pc/lists_special.html` | 专题专题聚合列表页 (Special Topic) | 必须交付完整代码 |
| 19 | `01_TARGET_THEME/pc/lists_tags.html` | 标签总览与标签云列表页 | 必须交付完整代码 |
| 20 | `01_TARGET_THEME/pc/lists_archives.html` | 历史归档列表页 (Year/Month Archives) | 必须交付完整代码 |
| 21 | `01_TARGET_THEME/pc/lists_search.html` | 全站搜索结果列表页 (Search Results) | 必须交付完整代码 |
| 22 | `01_TARGET_THEME/pc/lists_custom-cat.html`| 自定义模型栏目列表页 | 必须交付完整代码 |
| 23 | `01_TARGET_THEME/pc/view_article.html` | 文章详情页 (Article Detail) | 必须交付完整代码 |
| 24 | `01_TARGET_THEME/pc/view_product.html` | 商品详情页 (Product Detail，含价格、相册、询盘/加入购物车) | 必须交付完整代码 |
| 25 | `01_TARGET_THEME/pc/view_images.html` | 图集详情页 (Gallery Detail，含多图浏览) | 必须交付完整代码 |
| 26 | `01_TARGET_THEME/pc/view_download.html` | 下载详情页 (Download Detail，含附件下载) | 必须交付完整代码 |
| 27 | `01_TARGET_THEME/pc/view_media.html` | 视频/音视频详情页 (Video/Audio Player) | 必须交付完整代码 |
| 28 | `01_TARGET_THEME/pc/view_recruit.html` | 职位招聘详情页 (Job Detail，含岗位职责、投递入口) | 必须交付完整代码 |
| 29 | `01_TARGET_THEME/pc/view_special.html` | 专题详情页 (Special Topic Detail) | 必须交付完整代码 |
| 30 | `01_TARGET_THEME/pc/view_single.html` | 单页/关于我们/公司介绍详情页 (Single Page) | 必须交付完整代码 |
| 31 | `01_TARGET_THEME/pc/view_custom-cat.html`| 自定义模型单页详情页 | 必须交付完整代码 |
| 32 | `01_TARGET_THEME/pc/comment_section.html`| 详情页评论互动组件 (Comment Form & List) | 必须交付完整代码 |
| 33 | `01_TARGET_THEME/pc/inquiry_section.html`| 产品/商业询盘交互组件 (Inquiry Modal/Form) | 必须交付完整代码 |
| 34 | `01_TARGET_THEME/pc/captcha_modal.html` | 安全滑块验证码弹窗组件 (风控核心，严禁篡改 DOM ID 与驱动脚本) | 必须交付且样式完备 |
| 35 | `01_TARGET_THEME/pc/ajax-pager-article.html`| 文章模型 Ajax 无刷新分页容器片段 | 必须交付完整代码 |
| 36 | `01_TARGET_THEME/pc/404.html` | 404 错误页 (Not Found Page) | 必须交付完整代码 |
| 37 | `01_TARGET_THEME/pc/rss.html` | RSS Feed 输出模板 | 必须交付完整代码 |
| 38 | `01_TARGET_THEME/pc/unsubscribe.html` | 邮件订阅/退订服务页 (Unsubscribe Form) | 必须交付完整代码 |

### 板块二：主题核心驱动、元数据与缩略图
| 序号 | 资产文件路径 | 资产职责说明 | 交付状态要求 |
| :--- | :--- | :--- | :--- |
| 39 | `01_TARGET_THEME/functions.php` | 主题核心函数库（资源入队、视图格式化、todo_theme_activated 激活初始化钩子） | 必须交付且保留标准钩子 |
| 40 | `01_TARGET_THEME/theme.json` | 主题元数据规范（必须保留 slug 与 directories 配置） | 必须符合标准规范 |
| 41 | `01_TARGET_THEME/screenshot.png` | 主题高保真视觉缩略图（完美展现首页最终设计效果） | 必须真实交付，严禁假图 |

### 板块三：主题核心视觉与交互定制层（必须由 AI 深度定制）
| 序号 | 资产文件路径 | 资产职责说明 | 交付状态要求 |
| :--- | :--- | :--- | :--- |
| 42 | `01_TARGET_THEME/pc/assets/css/style.css` | 主题核心样式表（涵盖全站品牌色彩、组件覆盖与响应式） | 必须交付完整定制代码 |
| 43 | `01_TARGET_THEME/pc/assets/js/theme.js` | 主题核心交互行为脚本（导航吸顶、弹层、交互） | 必须交付完整交互脚本 |

### 板块四：系统级核心基础设施资产库（【绝对底座资产，严禁裁剪/丢弃】）
| 序号 | 资产文件路径 | 资产职责说明 | 交付保护要求 |
| :--- | :--- | :--- | :--- |
| 44 | `01_TARGET_THEME/pc/users/` | 全量 19 个用户中心模板（登录/注册/购物车/结算/订单/个人资料等全套页面及 skin/ 目录） | **必须 100% 完整保留，严禁丢失！** |
| 45 | `01_TARGET_THEME/pc/assets/css/tailwind-atomic.min.css` | 开源 Tailwind 全量原子样式库（提供 p-8, mt-12, flex, grid 等开箱支持） | **必须 100% 完整保留，严禁丢失！** |
| 46 | `01_TARGET_THEME/pc/assets/js/motion.min.js` | Motion WAAPI 硬件加速微动效引擎底层库（24KB，官方标准） | **必须 100% 完整保留，严禁丢失！** |
| 47 | `01_TARGET_THEME/pc/assets/js/motion-helper.js` | Motion 声明式微动效驱动脚本（支持 data-motion 弱+细入场与滚动联动） | **必须 100% 完整保留，严禁丢失！** |
| 48 | `01_TARGET_THEME/pc/assets/css/hero-slider.css`<br>`01_TARGET_THEME/pc/assets/js/hero-slider.js` | 官方原生 Hero Slider 轮播系统样式与交互脚本 | **必须 100% 完整保留，严禁丢失！** |
| 49 | `01_TARGET_THEME/pc/assets/js/csrf-manager.js`<br>`01_TARGET_THEME/pc/assets/js/submit-guard.js` | 全站安全防伪 CSRF 管理器与表单防重复提交守卫脚本 | **必须 100% 完整保留，严禁丢失！** |
| 50 | `01_TARGET_THEME/pc/assets/lang/` | 官方多语言包目录（包含 `en_US.php`, `zh_CN.php`, `ar_SA.php` 等） | **必须 100% 完整保留，严禁丢失！** |
| 51 | `01_TARGET_THEME/pc/assets/images/data/` | 真实高保真封面演示图集（含 `cover_1.jpg`~`cover_10.jpg`, `default-banner.jpg` 等） | **必须 100% 完整保留，严禁丢失！** |

---

## 🌳 主题物理工程拓扑全景树 (Full Asset Directory Topology)
```text
01_TARGET_THEME/
├── functions.php                        [板块二：核心函数库与激活数据初始化钩子]
├── theme.json                           [板块二：主题标准元数据定义]
├── screenshot.png                       [板块二：主题高清缩略图 1200x800]
└── pc/                                  [PC 端主根目录]
    ├── index.html, header.html...       [板块一：全量 38 套 HTML 页面与组件模板]
    ├── captcha_modal.html               [板块一：全站安全滑块验证码弹窗核心组件]
    ├── users/                           [板块四：会员中心 19 套完整模板 + skin 目录，严禁丢失！]
    │   ├── login.htm, register.htm, forgot-password.htm
    │   ├── cart.htm, checkout.htm, orders.htm, order-detail.htm
    │   ├── profile.htm, addresses.htm, coupons.htm, wallet.htm...
    │   └── skin/
    └── assets/                          [静态资产总目录，严禁裁剪成仅剩两个文件！]
        ├── css/
        │   ├── style.css                [板块三：主题全站核心定制样式表]
        │   ├── tailwind-atomic.min.css  [板块四：Tailwind 全量原子样式库，严禁丢失！]
        │   ├── hero-slider.css          [板块四：官方原生轮播系统样式组件]
        │   └── user-center.css          [板块四：会员中心专用交互与排版样式]
        ├── js/
        │   ├── theme.js                 [板块三：主题全站核心定制交互脚本]
        │   ├── motion.min.js            [板块四：Motion WAAPI 微动效引擎，严禁丢失！]
        │   ├── motion-helper.js         [板块四：Motion 声明式弱+细微动效运行时，严禁丢失！]
        │   ├── csrf-manager.js          [板块四：全站安全防伪 CSRF 管理器，严禁丢失！]
        │   ├── submit-guard.js          [板块四：表单防重复提交守卫脚本，严禁丢失！]
        │   ├── hero-slider.js           [板块四：官方原生轮播系统交互驱动脚本]
        │   └── ajax-pager.js            [板块四：文章/内容列表 Ajax 无刷新分页脚本]
        ├── lang/                        [板块四：多语言语言包目录（en_US.php 等），严禁丢失！]
        │   ├── en_US.php, zh_CN.php, ar_SA.php...
        └── images/
            └── data/                    [板块四：真实高质感封面图与演示图集，严禁丢失！]
                ├── cover_1.jpg ~ cover_10.jpg (含真实 cover_6.jpg)
                ├── default-banner.jpg, default-product.jpg, default-article.jpg...
```

## 📂 工程包四维体系与各目录职责
解压本工程包后，你将看到以下四个目录，各司其职：
1. **`01_TARGET_THEME/`**：【你的修改与交付目标】
   - 包含已铺垫好素材包 HTML 架构与 root.css 的全套 38 个基础模板、functions.php 与 assets 资源。你的所有产出代码都将写入这里的对应文件。
   - **特别提醒**：`pc/users/` 会员中心模板已全量预装，严禁自行重新编撰一套会员中心，仅需对接全局样式！
2. **`02_MATERIAL_DESIGN/`**：【视觉与原型输入标杆】
   - 包含素材包中的真实设计效果图（`*.png`）与纯静态原型源码（HTML/CSS）。你的任务是将这些设计质感（卡片网格、留白、字体、配色）完全还原到 `01_TARGET_THEME/` 中！在开头说明的风格及理念范围内。
3. **`03_OFFICIAL_REFERENCE/`**：【系统标签与数据结构标准】
   - 包含 TodoCMS 官方原主题的 38 个全量模板样本。当你不确定某个功能该用什么标签时（如评论、搜索、多语言、退订、分页），直接查阅这里的对应模板！
4. **`04_DEVELOPMENT_TASK.md`**：【本开发任务契约】

---

## 🧭 全站头部 (header.html) 规范与自由度
- **基准主导航菜单**：系统核心主菜单**必须直接采用官方原基准标签**：
  `{{todo:nav_menu theme_location="primary" menu_id="1" container_class="nav-container" menu_class="nav-menu"}}`
- **头部延续官方原功能体系**：
  - 多站点与多语言支持：`{{todo:site_switcher type="dropdown"}}` 与 `{{todo:language_switcher type="dropdown"}}`；
  - 搜索功能与接口：保留搜索输入框与 `window.TODO.doSafeSearch(...)` 接口；
  - 用户中心与购物车：保留全静态缓存异步登录态占位容器 `#todo-user-nav-item`（未登录时指向 `{{todo:login_url}}`）以及购物车数量徽章 `#todo-cart-count`（指向 `{{todo:user_page_url page='cart'}}`）与配套 Hydration 脚本。
- **自由定制原则**：
  - 在保留上述基准标签与功能容器的前提下，开发者与 AI **拥有绝对自由重构头部所有 HTML 标签结构与 CSS 布局的权利**！
  - 自由发挥独特的品牌调性（居中对称、左右通栏、吸顶悬浮、透明毛玻璃拟态等），只要最终提供对应类名的 CSS 规则即可！

---

## 🎨 全局样式与交互 (style.css & theme.js) 规范
1. **基础样式覆盖**：必须定义 `--primary-color`、`--text-main` 等核心 CSS 变量，并为 `.container`、`.site-header`、`.nav-menu` 等提供完整美观的样式。
2. **主导航多级子菜单**：必须包含 `.nav-menu .sub-menu` 的样式（定位、背景、阴影、微动画、hover 展示等），确保有二级菜单时正常展开。
3. **安全滑块验证码弹窗样式**：必须包含 `#captcha-modal, #todo-captcha-modal, .todo-captcha-modal` 及内部 `.captcha-box`、`.captcha-slider-box`、`.captcha-slider-bar`、`.captcha-slider-btn` 的完整居中浮层与拖拽轨道样式，支持 `window.Captcha.show()` 正常显示。
4. **系统内置组件主题化覆盖**：必须在 `style.css` 中覆盖 `.todo-language-switcher.dropdown-style .current-language:hover`、`.todo-site-switcher.site-dropdown-style .current-site:hover` 及 `.breadcrumb-list`，贴合主题品牌色彩。
5. **Tailwind + Motion 双引擎**：全量原子类（`p-8`, `px-6`, `flex`, `grid` 等）由 `tailwind-atomic.min.css` 开箱提供；微动效通过 `data-motion="fade-up"` 等属性由 `motion-helper.js` 驱动，**严禁在 style.css 中手写冗长动画**。

---

## 🛠️ functions.php 与数据自动填充规范
1. **资源入队**：必须在 `todo_enqueue_scripts` 动作钩子中通过 `todo_enqueue_style` 和 `todo_enqueue_script` 规范加载 CSS 与 JS，包含 FontAwesome 6、Tailwind、Motion、Hero Slider 与核心 theme.js。
2. **主题激活自动填充**：必须包含 `add_action('todo_theme_activated', '...')`，在主题激活时自动向数据库填充演示分类、文章、商品与配置项，确保主题激活后立即呈现丰富界面。
3. **数据引用路径**：演示数据引用的静态图统一存放于 `pc/assets/images/data/`，路径写为 `$theme_url . '/assets/images/data/xxx.jpg'`。

---

祝你开发顺利！期待你交付一套视觉惊艳、交互顺滑丝滑、规范 100% 达标的 TodoCMS 专业级主题！
MARKDOWN;

        return str_replace(
            [
                '{{THEME_NAME}}',
                '{{THEME_SLUG}}',
                '{{MAT_INFO}}',
                '{{HIERARCHY_SECTION}}',
                '{{CROSS_INDUSTRY_SECTION}}',
                '{{BLUEPRINT_MAPPING_SECTION}}',
                '{{AI_STUDIO_GUIDE_SECTION}}'
            ],
            [
                $name,
                $slug,
                $mat_info,
                $hierarchy_section,
                $cross_industry_section,
                $blueprint_mapping_section,
                $ai_studio_guide
            ],
            $template
        );
    }
}

// ---------------------------------------------------------------------
// 辅助函数：构建 Google AI Studio 专属 Prompt 指南
// ---------------------------------------------------------------------
if (!function_exists('todo_studio_build_prompt_guide')) {
    function todo_studio_build_prompt_guide($slug, $name, $spec, $material_slug = '', $custom_material_url = '') {
        $task_md = todo_studio_build_development_task_md($slug, $name, $material_slug, $custom_material_url);
        return $task_md;
    }
}

// 路由处理
header('Content-Type: application/json; charset=UTF-8');

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. 获取工作区元数据（可用主题、素材包、当前激活主题）
        // -------------------------------------------------------------
        case 'get_workspace_meta':
        case 'theme_studio_get_workspace_meta':
            $themes_dir = ABSPATH . 'content/themes';
            $themes_list = [];

            if (is_dir($themes_dir)) {
                $dir_handle = opendir($themes_dir);
                while (($folder = readdir($dir_handle)) !== false) {
                    if ($folder === '.' || $folder === '..' || $folder === '.DS_Store') {
                        continue;
                    }
                    $theme_path = $themes_dir . '/' . $folder;
                    if (is_dir($theme_path)) {
                        $json_file = $theme_path . '/theme.json';
                        $theme_meta = [
                            'slug' => $folder,
                            'name' => $folder,
                            'version' => '1.0.0',
                            'author' => 'TodoCMS Team',
                            'description' => '',
                            'has_pc' => is_dir($theme_path . '/pc'),
                            'has_mobile' => is_dir($theme_path . '/mobile'),
                            'has_users' => is_dir($theme_path . '/pc/users') || is_dir($theme_path . '/users'),
                            'is_official' => in_array($folder, ['default', 'defaulten']),
                            'is_active' => false
                        ];

                        if (file_exists($json_file)) {
                            $parsed = json_decode(file_get_contents($json_file), true);
                            if (is_array($parsed)) {
                                $theme_meta['name'] = $parsed['name'] ?? $theme_meta['name'];
                                $theme_meta['version'] = $parsed['version'] ?? $theme_meta['version'];
                                $theme_meta['author'] = $parsed['author'] ?? $theme_meta['author'];
                                $theme_meta['description'] = $parsed['description'] ?? '';
                            }
                        }

                        $themes_list[] = $theme_meta;
                    }
                }
                closedir($dir_handle);
            }

            // 获取当前激活主题
            $active_theme_slug = 'default';
            if (function_exists('get_option')) {
                $active_theme_slug = get_option('current_theme', 'default');
            } elseif (class_exists('CMS_Option')) {
                $active_theme_slug = CMS_Option::get('current_theme', 'default');
            }
            if (empty($active_theme_slug)) {
                $active_theme_slug = 'default';
            }

            foreach ($themes_list as &$t) {
                if ($t['slug'] === $active_theme_slug) {
                    $t['is_active'] = true;
                }
            }
            unset($t);

            // 扫描素材包目录 content/materials
            $materials_dir = ABSPATH . 'content/materials';
            todo_studio_ensure_dir($materials_dir);

            $materials_list = [];

            if (is_dir($materials_dir)) {
                $m_handle = opendir($materials_dir);
                while (($m_item = readdir($m_handle)) !== false) {
                    if ($m_item === '.' || $m_item === '..' || $m_item === '.DS_Store' || $m_item === 'demo-corporate') {
                        continue;
                    }
                    $full_m_path = $materials_dir . '/' . $m_item;
                    if (is_dir($full_m_path)) {
                        $materials_list[] = [
                            'slug' => $m_item,
                            'name' => $m_item . ' (文件夹)',
                            'type' => 'directory',
                            'description' => '存放于 content/materials/' . $m_item
                        ];
                    } elseif (strtolower(pathinfo($m_item, PATHINFO_EXTENSION)) === 'zip') {
                        $materials_list[] = [
                            'slug' => $m_item,
                            'name' => $m_item . ' (ZIP 压缩包)',
                            'type' => 'zip',
                            'description' => '自动解压并提取静态图像与HTML'
                        ];
                    }
                }
                closedir($m_handle);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'themes' => $themes_list,
                    'active_theme' => $active_theme_slug,
                    'materials' => $materials_list,
                    'materials_dir' => 'content/materials',
                    'template_tags_doc_url' => todo_admin_url('index.php?page=template-tags')
                ]
            ]);
            break;

        // -------------------------------------------------------------
        // 2. 获取指定主题的文件资源树
        // -------------------------------------------------------------
        case 'get_theme_tree':
        case 'theme_studio_get_theme_tree':
            $theme_slug = todo_studio_sanitize_slug($_POST['theme'] ?? $_GET['theme'] ?? '');
            if (empty($theme_slug)) {
                throw new Exception('未指定主题目录名');
            }

            $theme_base_dir = ABSPATH . 'content/themes/' . $theme_slug;
            if (!is_dir($theme_base_dir)) {
                throw new Exception('主题目录不存在: ' . $theme_slug);
            }

            $tree = todo_studio_scan_dir_tree($theme_base_dir);

            echo json_encode([
                'success' => true,
                'theme' => $theme_slug,
                'data' => $tree
            ]);
            break;

        // -------------------------------------------------------------
        // 3. 读取主题内的指定文件内容（预览）
        // -------------------------------------------------------------
        case 'read_file_content':
        case 'theme_studio_read_file':
            $theme_slug = todo_studio_sanitize_slug($_POST['theme'] ?? '');
            $rel_path = trim($_POST['file_path'] ?? '', '/\\');

            if (empty($theme_slug) || empty($rel_path)) {
                throw new Exception('缺少必要参数');
            }

            if (strpos($rel_path, '..') !== false) {
                throw new Exception('非法文件路径');
            }

            $full_path = ABSPATH . 'content/themes/' . $theme_slug . '/' . $rel_path;
            if (!file_exists($full_path) || is_dir($full_path)) {
                throw new Exception('目标文件不存在');
            }

            $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
            $is_image = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico']);

            if ($is_image) {
                $img_data = base64_encode(file_get_contents($full_path));
                $mime = mime_content_type($full_path) ?: ('image/' . $ext);
                echo json_encode([
                    'success' => true,
                    'is_image' => true,
                    'file_name' => basename($full_path),
                    'mime' => $mime,
                    'data_url' => 'data:' . $mime . ';base64,' . $img_data
                ]);
            } else {
                $content = file_get_contents($full_path, false, null, 0, 1048576);
                echo json_encode([
                    'success' => true,
                    'is_image' => false,
                    'file_name' => basename($full_path),
                    'extension' => $ext,
                    'size' => filesize($full_path),
                    'content' => $content
                ]);
            }
            break;

        // -------------------------------------------------------------
        // 4. 任务 1：构建规范基础主题地基包 (Scaffold Build)
        // -------------------------------------------------------------
        case 'build_scaffold':
        case 'theme_studio_build_scaffold':
            $source_slug = todo_studio_sanitize_slug($_POST['source_theme'] ?? 'default');
            $target_slug = todo_studio_sanitize_slug($_POST['target_slug'] ?? '');
            $material_slug = trim($_POST['material_slug'] ?? '');
            $custom_material_url = trim($_POST['custom_material_url'] ?? '');
            $theme_mode = $_POST['theme_mode'] ?? 'pc_mobile';
            $theme_name = trim($_POST['theme_name'] ?? '');
            $theme_version = trim($_POST['theme_version'] ?? '1.0.0');
            $theme_author = trim($_POST['theme_author'] ?? 'TodoCMS AI Architect');
            $theme_desc = trim($_POST['theme_desc'] ?? '基于 TodoCMS 规范构建的标准双端响应式主题包');

            if (empty($target_slug)) {
                throw new Exception('请指定要创建的主题文件夹名称（仅限英文、数字、下划线）');
            }

            if (in_array($target_slug, ['default', 'defaulten'])) {
                throw new Exception('禁止使用系统保留主题名称: ' . $target_slug);
            }

            $source_dir = ABSPATH . 'content/themes/' . $source_slug;
            if (!is_dir($source_dir)) {
                $source_dir = ABSPATH . 'content/themes/default';
            }

            $target_dir = ABSPATH . 'content/themes/' . $target_slug;
            todo_studio_ensure_dir($target_dir);

            // 清理主题根目录下可能存在的历史残留 assets/ 目录
            if (is_dir($target_dir . '/assets')) {
                todo_studio_delete_dir($target_dir . '/assets');
            }

            $logs = [];
            $logs[] = "🚀 开始构建基础主题地基包: [{$target_slug}]";
            $logs[] = "📌 对标官方原基准主题: [{$source_slug}]，主题模式: [{$theme_mode}]";

            $has_pc = ($theme_mode === 'pc_mobile' || $theme_mode === 'pc_only');
            $has_mobile = ($theme_mode === 'pc_mobile' || $theme_mode === 'mobile_only');

            if ($has_pc) {
                $pc_dir = $target_dir . '/pc';
                todo_studio_ensure_dir($pc_dir . '/assets/css');
                todo_studio_ensure_dir($pc_dir . '/assets/js');
                todo_studio_ensure_dir($pc_dir . '/assets/images/data');
                todo_studio_ensure_dir($pc_dir . '/assets/fonts');
                todo_studio_ensure_dir($pc_dir . '/assets/lang');

                $logs[] = "📁 成功创建 PC 端规范目录及静态资源结构 (pc/assets/...)";

                // 核心素材包与资源路径检测
                $mat_dir = (!empty($material_slug) && $material_slug !== '__custom_url__') ? ABSPATH . 'content/materials/' . $material_slug : '';
                $has_material = (!empty($mat_dir) && is_dir($mat_dir));
                $has_mat_root_css = false;

                // 深度同步素材包 assets/ 到 pc/assets
                if ($has_material && is_dir($mat_dir . '/assets')) {
                    todo_studio_copy_dir($mat_dir . '/assets', $pc_dir . '/assets');
                    if (file_exists($pc_dir . '/assets/css/root.css')) {
                        $has_mat_root_css = true;
                    }
                    $logs[] = "🎨 素材包深度注入：已同步 [{$material_slug}] 的 assets/ 样式与资源到主题中";
                }

                // 复制官方原所有核心通用样式到主题 pc/assets/css (包含 tailwind-atomic.min.css, user-center.css, hero-slider.css 等)
                $official_css_dir = $source_dir . '/pc/assets/css';
                if (is_dir($official_css_dir)) {
                    todo_studio_copy_dir($official_css_dir, $pc_dir . '/assets/css');
                    $logs[] = "🎨 官方样式对标：已自动同步 tailwind-atomic.min.css, user-center.css, hero-slider.css 等核心静态样式库";
                }

                // 复制官方原所有核心通用脚本到主题 pc/assets/js，彻底杜绝 404 (包含 motion, csrf-manager, submit-guard 等)
                $official_js_dir = $source_dir . '/pc/assets/js';
                if (is_dir($official_js_dir)) {
                    todo_studio_copy_dir($official_js_dir, $pc_dir . '/assets/js');
                    $logs[] = "🛡️ 官方脚本对标：已自动同步 motion.min.js, motion-helper.js, csrf-manager.js, submit-guard.js, hero-slider.js 等核心交互组件";
                }

                // 复制官方原多语言语言包到主题 pc/assets/lang，确保多语言支持开箱即用
                $official_lang_dir = $source_dir . '/pc/assets/lang';
                if (!is_dir($official_lang_dir) && is_dir($source_dir . '/assets/lang')) {
                    $official_lang_dir = $source_dir . '/assets/lang';
                }
                if (is_dir($official_lang_dir)) {
                    todo_studio_copy_dir($official_lang_dir, $pc_dir . '/assets/lang');
                    $logs[] = "🌐 多语言支持就绪：已自动同步 en_US, zh_CN, ar_SA 等官方多语言包到 pc/assets/lang/";
                }

                // 生成或提炼真实高质感设计演示图、封面系列图与 screenshot.png（严禁无效单色简陋方块）
                $data_img_dir = $pc_dir . '/assets/images/data';
                todo_studio_ensure_dir($data_img_dir);

                $mat_imgs = [];
                if ($has_material) {
                    $mat_imgs = glob($mat_dir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
                }

                // 1. 生成真实 screenshot.png（优先使用素材包首页设计大图，杜绝空白或无效占位）
                $hero_candidate = '';
                if (!empty($mat_imgs)) {
                    foreach ($mat_imgs as $m_candidate) {
                        if (stripos(basename($m_candidate), 'index') !== false) {
                            $hero_candidate = $m_candidate;
                            break;
                        }
                    }
                    if (!$hero_candidate) {
                        $hero_candidate = $mat_imgs[0];
                    }
                }

                if ($hero_candidate) {
                    todo_studio_extract_real_cover($hero_candidate, $target_dir . '/screenshot.png', 1200, 800, 0.0);
                    @copy($target_dir . '/screenshot.png', $pc_dir . '/screenshot.png');
                } elseif (!file_exists($target_dir . '/screenshot.png')) {
                    todo_studio_generate_placeholder_image($target_dir . '/screenshot.png', 'Theme Preview', 1200, 800, [30, 58, 138]);
                    @copy($target_dir . '/screenshot.png', $pc_dir . '/screenshot.png');
                }

                // 2. 基于素材包真实设计提取高保真封面与演示图（包含 cover_6.jpg 等）
                if (!empty($mat_imgs)) {
                    $mat_count = count($mat_imgs);
                    todo_studio_extract_real_cover($hero_candidate ?: $mat_imgs[0], $data_img_dir . '/default-banner.jpg', 1200, 600, 0.05);
                    todo_studio_extract_real_cover($mat_imgs[1 % $mat_count], $data_img_dir . '/default-product.jpg', 800, 600, 0.15);
                    todo_studio_extract_real_cover($mat_imgs[2 % $mat_count], $data_img_dir . '/default-article.jpg', 800, 500, 0.25);

                    for ($ci = 1; $ci <= 10; $ci++) {
                        $source_img = $mat_imgs[($ci - 1) % $mat_count];
                        $y_ratio = fmod($ci * 0.08, 0.6);
                        todo_studio_extract_real_cover($source_img, $data_img_dir . "/cover_{$ci}.jpg", 800, 500, $y_ratio);
                    }

                    foreach ($mat_imgs as $m_img) {
                        @copy($m_img, $data_img_dir . '/' . basename($m_img));
                    }
                    $logs[] = "🖼️ 真实设计图集就绪：已基于素材设计大图生成真实 screenshot.png 与 cover_1~10 高清封面（含真实高质感 cover_6.jpg）";
                } else {
                    todo_studio_generate_placeholder_image($data_img_dir . '/default-banner.jpg', 'Hero Banner', 1200, 600, [30, 58, 138]);
                    todo_studio_generate_placeholder_image($data_img_dir . '/default-product.jpg', 'Product Image', 800, 600, [14, 165, 233]);
                    todo_studio_generate_placeholder_image($data_img_dir . '/default-article.jpg', 'Article Image', 800, 500, [99, 102, 241]);
                    for ($ci = 1; $ci <= 10; $ci++) {
                        todo_studio_generate_placeholder_image($data_img_dir . "/cover_{$ci}.jpg", "Cover #{$ci}", 800, 500, [40 + $ci * 15, 70 + $ci * 10, 160 + $ci * 8]);
                    }
                    $logs[] = "🖼️ 默认图集就绪：已自动生成 assets/images/data/ 下的高质感艺术演示图与封面图集";
                }

                if (file_exists($source_dir . '/pc/assets/images/logo.png')) {
                    @copy($source_dir . '/pc/assets/images/logo.png', $pc_dir . '/assets/images/logo.png');
                }

                // 克隆官方原 users 用户中心
                $source_users = $source_dir . '/pc/users';
                if (!is_dir($source_users)) {
                    $source_users = $source_dir . '/users';
                }
                if (is_dir($source_users)) {
                    todo_studio_copy_dir($source_users, $pc_dir . '/users');
                    $logs[] = "👥 深度对标：已从官方原完整克隆 users 用户中心到 pc/users/ (保持全量交互标准)";
                }

                // 植入核心通用样式与素材包辅助样式
                $import_root_css = $has_mat_root_css ? "@import \"root.css\";\n" : "";
                $base_css = <<<CSS
{$import_root_css}/**
 * TodoCMS Theme Base Stylesheet
 * 遵从 TodoCMS 标准与素材包响应式规范
 */
:root {
    --primary-color: #2563eb;
    --primary-hover: #1d4ed8;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --bg-main: #ffffff;
    --bg-card: #ffffff;
    --border-color: #e5e7eb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: var(--text-main); background: var(--bg-main); line-height: 1.6; }
a { color: inherit; text-decoration: none; transition: color 0.2s; }
a:hover { color: var(--primary-color); }
.container { max-width: 1200px; margin: 0 auto; padding: 0 16px; }

/* 头部样式体系 */
.site-header { background: var(--bg-card); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
.header-topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid rgba(229,231,235,0.6); }
.site-branding { display: flex; align-items: center; gap: 12px; }
.site-branding img, .custom-logo { max-height: 40px; width: auto; object-fit: contain; }
.site-title { font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0; letter-spacing: -0.02em; }
.site-description { font-size: 13px; color: var(--text-muted); margin: 2px 0 0 0; }

.header-tools { display: flex; align-items: center; gap: 16px; }
.header-search-wrap { display: flex; align-items: center; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 20px; padding: 2px 4px 2px 14px; transition: all 0.2s; }
.header-search-wrap:focus-within { border-color: var(--primary-color); background: #ffffff; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.search-input { border: none; background: transparent; outline: none; font-size: 13px; width: 180px; color: var(--text-main); }
.search-btn { border: none; background: transparent; color: var(--text-muted); cursor: pointer; padding: 6px 10px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.search-btn:hover { color: var(--primary-color); }

.main-navigation-bar { background: var(--bg-card); border-bottom: 1px solid #f1f5f9; }
.nav-inner-wrap { display: flex; align-items: center; justify-content: space-between; min-height: 52px; padding: 0 24px; }
.nav-container { display: flex; align-items: center; }
.nav-menu { display: flex; gap: 8px; list-style: none; margin: 0; padding: 0; }
.nav-menu > li { position: relative; }
.nav-menu > li.has-children > a { display: inline-flex; align-items: center; gap: 6px; }
.nav-menu > li > a { display: inline-flex; align-items: center; font-weight: 500; font-size: 14px; padding: 8px 14px; border-radius: 6px; color: #374151; }
.nav-menu > li > a:hover, .nav-menu > li.current-menu-item > a { color: var(--primary-color); background: rgba(37,99,235,0.06); }
.nav-menu .sub-menu { display: none; position: absolute; top: 100%; left: 0; min-width: 180px; background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 6px 0; margin-top: 6px; list-style: none; z-index: 1000; text-align: left; }
.nav-menu .sub-menu::before { content: ""; position: absolute; top: -6px; left: 0; width: 100%; height: 6px; }
.nav-menu > li.has-children:hover > .sub-menu, .nav-menu > li.has-children:focus-within > .sub-menu { display: block; animation: todoSubMenuFadeIn 0.2s ease; }
.nav-menu .sub-menu li { width: 100%; position: relative; }
.nav-menu .sub-menu li a { display: flex; align-items: center; padding: 8px 16px; font-size: 13px; color: var(--text-main, #374151); text-decoration: none; transition: all 0.15s ease; white-space: nowrap; }
.nav-menu .sub-menu li a:hover { background: rgba(37,99,235,0.08); color: var(--primary-color); padding-left: 20px; }
.nav-menu .sub-menu li.has-children > .sub-menu { top: 0; left: 100%; margin-top: -6px; }
@keyframes todoSubMenuFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* 用户中心与购物车导航 */
.user-center-nav { display: flex; align-items: center; gap: 16px; position: relative; }
.user-center-btn, .login-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; padding: 6px 14px; border-radius: 6px; }
.login-btn { background: #111827; color: #fff !important; }
.login-btn:hover { background: #374151; }
.user-profile-menu { position: relative; }
.user-profile-menu:hover .user-dropdown { display: block; }
.user-dropdown { display: none; position: absolute; right: 0; top: 100%; min-width: 140px; background: #fff; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 6px 0; z-index: 1000; }
.dropdown-item { display: block; padding: 8px 16px; font-size: 13px; color: #374151; }
.dropdown-item:hover { background: #f8fafc; color: var(--primary-color); }
.dropdown-divider { height: 1px; background: #e5e7eb; margin: 4px 0; }

.cart-link { display: inline-flex; align-items: center; gap: 4px; position: relative; color: #4b5563; font-size: 18px; padding: 6px 10px; }
.cart-count-badge { position: absolute; top: 0; right: 0; background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; height: 18px; min-width: 18px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; line-height: 1; }

/* 系统内置多语言/多站点组件主题化覆盖 */
.todo-language-switcher.dropdown-style .current-language,
.todo-site-switcher.site-dropdown-style .current-site { color: inherit; font-size: 13px; padding: 4px 10px; border-radius: 6px; transition: all 0.2s ease; }
.todo-language-switcher.dropdown-style .current-language:hover,
.todo-site-switcher.site-dropdown-style .current-site:hover { color: var(--primary-color) !important; background: rgba(37, 99, 235, 0.08); }
.todo-language-switcher.dropdown-style .language-dropdown,
.todo-site-switcher.site-dropdown-style .site-dropdown-list { background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08); padding: 4px 0; z-index: 1000; }
.todo-language-switcher.dropdown-style .language-option,
.todo-site-switcher.site-dropdown-style .site-option { color: var(--text-main, #374151); font-size: 13px; padding: 8px 14px; transition: all 0.15s ease; }
.todo-language-switcher.dropdown-style .language-option:hover,
.todo-site-switcher.site-dropdown-style .site-option:hover { background: rgba(37, 99, 235, 0.06); color: var(--primary-color) !important; }

/* Breadcrumb 面包屑导航完整 DOM 规范样式 */
.breadcrumb-list { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; list-style: none; margin: 16px 0; padding: 0; font-size: 14px; color: var(--text-muted); }
.breadcrumb-list li { display: inline-flex; align-items: center; gap: 8px; margin: 0; padding: 0; list-style: none; }
.breadcrumb-list li a { color: var(--text-muted); text-decoration: none; transition: color 0.2s ease; }
.breadcrumb-list li a:hover { color: var(--primary-color); text-decoration: underline; }
.breadcrumb-list li .breadcrumb-separator { color: #94a3b8; user-select: none; display: inline-block; padding: 0 2px; }
.breadcrumb-list li .breadcrumb-current { color: var(--text-main); font-weight: 500; }

/* 全站安全滑块验证码弹窗公共样式 (Captcha Modal) */
#captcha-modal, #todo-captcha-modal, .todo-captcha-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 99999;
    justify-content: center;
    align-items: center;
}
.captcha-box {
    background: #ffffff;
    padding: 24px;
    border-radius: 12px;
    width: 350px;
    max-width: 90vw;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
    position: relative;
    box-sizing: border-box;
}
.captcha-box h3 { margin: 0 0 16px; font-size: 16px; font-weight: 600; color: #111827; }
.captcha-img-box { position: relative; width: 300px; max-width: 100%; height: 150px; background: #f3f4f6; margin: 10px auto; overflow: hidden; border-radius: 8px; }
.captcha-img-box:before { content: '☯'; left: 50%; top: 50%; position: absolute; transform: translate(-50%, -50%); z-index: 0; font-size: 40px; opacity: 0.2; animation: captcha-spin 1.2s linear infinite; }
@keyframes captcha-spin { 0% { transform: translate(-50%, -50%) rotate(0deg); } 100% { transform: translate(-50%, -50%) rotate(360deg); } }
#captcha-piece { position: absolute; top: 0; left: 0; height: 50px; z-index: 2; }
.captcha-slider-box { position: relative; width: 300px; max-width: 100%; height: 42px; padding: 0; background: #f9fafb; margin: 16px auto 12px; border-radius: 21px; border: 1px solid #e5e7eb; box-sizing: border-box; }
#captcha-slider-bar { position: absolute; top: 0; left: 0; height: 100%; width: 0; background: rgba(37, 99, 235, 0.15); border-radius: 21px 0 0 21px; transition: background 0.2s; }
#captcha-slider-btn { position: absolute; top: 0; left: 0; width: 42px; height: 40px; background: #ffffff; border: 1px solid #d1d5db; border-radius: 50%; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15); cursor: grab; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #4b5563; z-index: 3; transition: background 0.2s, border-color 0.2s, color 0.2s; user-select: none; }
#captcha-slider-btn:hover { background: #0284c7; color: #ffffff; border-color: #0284c7; }
.captcha-text { text-align: center; line-height: 40px; color: #6b7280; font-size: 13px; user-select: none; text-indent: 20px; overflow: hidden; height: 100%; }
.captcha-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
.captcha-btn { background: none; border: none; cursor: pointer; font-size: 13px; padding: 4px 8px; color: #6b7280; border-radius: 4px; transition: color 0.2s; }
.captcha-btn:hover { color: #111827 !important; text-decoration: underline; }
#captcha-refresh { color: #0284c7; }
#captcha-close { color: #9ca3af; }
CSS;
                file_put_contents($pc_dir . '/assets/css/style.css', $base_css);

                // 生成 header.html
                $root_css_link = $has_mat_root_css ? '<link rel="stylesheet" href="{{todo:theme_url}}/assets/css/root.css">' . "\n    " : '';
                $header_html = <<<HTML
<!DOCTYPE html>
<html {{todo:html_attributes}}>
<head>
    <meta charset="{{todo:charset}}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="generator" content="TodoCMS">
    <title>{{todo:page_title}}</title>
    <meta name="keywords" content="{{todo:meta_keywords}}">
    <meta name="description" content="{{todo:meta_description}}">
    {{todo:if is_search || is_tag || is_author || is_archive || is_paged}}
    <meta name="robots" content="noindex, follow">
    {{/todo:if}}

    <meta name="site-url" content="{{todo:site_url}}">
    <meta name="site-root" content="{{todo:site_url|default}}">
    {{todo:if user_logged_in}}
    <meta name="todo-user-logged-in" content="1">
    {{else}}
    <meta name="todo-user-logged-in" content="0">
    <meta name="todo-login-url" content="{{todo:login_url}}">
    {{/todo:if}}

    <link rel="shortcut icon" href="{{todo:site_favicon}}" type="image/x-icon">
    {$root_css_link}<link rel="stylesheet" href="{{todo:theme_url}}/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    {{todo:todo_head}}
    <script>
        (function () {
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var storedTheme = localStorage.getItem('theme');
            if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
</head>
<body {{todo:body_class}}>
    <header class="site-header">
        <div class="header-topbar">
            <div class="site-branding">
                {{todo:if site_logo}}
                <a href="{{todo:site_url}}" class="site-logo">
                    <img src="{{todo:site_logo}}" alt="{{todo:site_name}}" class="custom-logo">
                </a>
                {{else}}
                <div class="site-title-wrap">
                    <h1 class="site-title"><a href="{{todo:site_url}}">{{todo:site_name}}</a></h1>
                    <p class="site-description">{{todo:site_description}}</p>
                </div>
                {{/todo:if}}
            </div>

            <div class="header-tools">
                <div class="header-search-wrap">
                    <input type="text" id="site-search-input" class="search-input" placeholder="{{todo:t text='Search...'}}" onkeydown="if(event.keyCode==13){window.TODO&&window.TODO.doSafeSearch?window.TODO.doSafeSearch(this.value):location.href='{{todo:site_url}}/search/'+encodeURIComponent(this.value);}">
                    <button type="button" class="search-btn" onclick="var inp=document.getElementById('site-search-input');if(inp){window.TODO&&window.TODO.doSafeSearch?window.TODO.doSafeSearch(inp.value):location.href='{{todo:site_url}}/search/'+encodeURIComponent(inp.value);}"><i class="fa-solid fa-magnifying-glass"></i></button>
                </div>

                {{todo:language_switcher type="dropdown"}}
                {{todo:site_switcher type="dropdown"}}

                <div class="user-center-nav">
                    <div id="todo-user-nav-item">
                        {{todo:if user_logged_in}}
                        <div class="user-profile-menu">
                            <a href="{{todo:user_url}}" class="user-center-btn"><i class="fa-solid fa-user"></i> {{todo:current_user_name}}</a>
                            <div class="user-dropdown">
                                <a href="{{todo:user_page_url page='profile'}}" class="dropdown-item">{{todo:t text='Profile'}}</a>
                                <a href="{{todo:user_page_url page='orders'}}" class="dropdown-item">{{todo:t text='My Orders'}}</a>
                                <div class="dropdown-divider"></div>
                                <a href="{{todo:logout_url}}" class="dropdown-item logout-link">{{todo:t text='Sign Out'}}</a>
                            </div>
                        </div>
                        {{else}}
                        <a href="{{todo:login_url}}" class="login-btn"><i class="fa-solid fa-user"></i> {{todo:t text='Sign In'}}</a>
                        {{/todo:if}}
                    </div>

                    <a href="{{todo:user_page_url page='cart'}}" class="cart-link" title="{{todo:t text='Cart'}}">
                        <i class="fa-solid fa-bag-shopping"></i>
                        <span id="todo-cart-count" class="cart-count-badge">0</span>
                    </a>
                </div>
            </div>
        </div>

        <nav class="main-navigation-bar">
            <div class="nav-inner-wrap container">
                {{todo:nav_menu theme_location="primary" menu_id="1" container_class="nav-container" menu_class="nav-menu"}}
            </div>
        </nav>
    </header>
HTML;
                file_put_contents($pc_dir . '/header.html', $header_html);

                // 生成 footer.html
                $footer_html = <<<HTML
    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-widgets">
                <div class="footer-col">
                    <h4 class="footer-title">{{todo:site_name}}</h4>
                    <p class="footer-desc">{{todo:site_description}}</p>
                </div>
                <div class="footer-col">
                    <h4 class="footer-title">{{todo:t text='Quick Links'}}</h4>
                    {{todo:nav_menu theme_location="footer" menu_id="2" container_class="footer-nav" menu_class="footer-menu"}}
                </div>
                <div class="footer-col">
                    <h4 class="footer-title">{{todo:t text='Contact'}}</h4>
                    <p>{{todo:site_contact_address}}</p>
                    <p>{{todo:site_contact_email}}</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p class="copyright">{{todo:site_copyright}} Powered by <a href="https://todocms.com" target="_blank">TodoCMS</a></p>
            </div>
        </div>
    </footer>
    {{todo:todo_footer}}
    <script src="{{todo:theme_url}}/assets/js/theme.js"></script>
</body>
</html>
HTML;
                file_put_contents($pc_dir . '/footer.html', $footer_html);

                // 复制官方全套 38 个模板
                $official_pc_dir = $source_dir . '/pc';
                if (is_dir($official_pc_dir)) {
                    $tpl_files = glob($official_pc_dir . '/*.html');
                    foreach ($tpl_files as $tpl_f) {
                        $f_base = basename($tpl_f);
                        if (!file_exists($pc_dir . '/' . $f_base)) {
                            @copy($tpl_f, $pc_dir . '/' . $f_base);
                        }
                    }
                    $logs[] = "📑 规范体系注入：已基于官方原标准完整注入 38 套核心模型与组件模板";
                }

                // 确保安全滑块验证码弹窗组件 captcha_modal.html 真实存在
                $default_captcha = $source_dir . '/pc/captcha_modal.html';
                if (file_exists($default_captcha) && !file_exists($pc_dir . '/captcha_modal.html')) {
                    @copy($default_captcha, $pc_dir . '/captcha_modal.html');
                }
            }

            // 生成移动端 mobile/ 目录（如需要）
            if ($has_mobile) {
                $mobile_dir = $target_dir . '/mobile';
                todo_studio_ensure_dir($mobile_dir . '/assets/css');
                todo_studio_ensure_dir($mobile_dir . '/assets/js');
                todo_studio_ensure_dir($mobile_dir . '/assets/images/data');
                todo_studio_ensure_dir($mobile_dir . '/assets/lang');

                $source_mob = $source_dir . '/mobile';
                if (is_dir($source_mob)) {
                    todo_studio_copy_dir($source_mob, $mobile_dir);
                    $logs[] = "📱 移动端架构注入：已从官方基准主题成功同步 mobile/ 独立端模板与交互";
                }
            }

            // 导入模板标签手册 template-tags.md
            $spec_doc_path = ABSPATH . 'docs/todo-template-tags-spec.md';
            if (file_exists($spec_doc_path)) {
                @copy($spec_doc_path, $target_dir . '/template-tags.md');
                $logs[] = "📖 导入标签体系：已将标准 template-tags.md 成功注入主题根目录";
            }

            // 生成 functions.php
            $functions_code = <<<PHP
<?php
/**
 * Theme Functions & Data Initialization
 *
 * 主题：{$theme_name} ({$target_slug})
 * 版本：{$theme_version}
 * 作者：{$theme_author}
 *
 * 说明：
 * 1. 严格对接 TodoCMS 规范，提供主题激活时自动初始化演示数据机制
 * 2. 默认演示数据中引用的默认图片统一保存在 assets/images/data/
 * 3. 样式、JS 与语言包(lang)的规范入队与加载
 *
 * @package TodoCMS
 */

if (!defined('ABSPATH') && !defined('DOING_AJAX')) {
    exit('Direct access denied');
}

// -------------------------------------------------------------
// 1. 资源统一定义与入队 (CSS/JS)
// -------------------------------------------------------------
if (!function_exists('{$target_slug}_enqueue_assets')) {
    function {$target_slug}_enqueue_assets() {
        if (!function_exists('todo_enqueue_style') || !function_exists('todo_enqueue_script')) {
            return;
        }

        \$theme_url = get_template_directory_uri();
        \$public_url = rtrim(todo_get_site_url('public'), '/');

        // 引入公共图标与基础 CSS
        todo_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', [], '6.5.1', 'all');
        todo_enqueue_style('todo-iconfont', \$public_url . '/static/common/css/iconfont.css', [], '4.7.0', 'all');

        // 开源原子 CSS 库 (包含 Tailwind 完整实用原子类)
        \$tailwind_src = file_exists(get_template_directory() . '/assets/css/tailwind-atomic.min.css')
            ? \$theme_url . '/assets/css/tailwind-atomic.min.css'
            : 'https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css';
        todo_enqueue_style('tailwind-atomic', \$tailwind_src, [], '2.2.19', 'all');

        if (file_exists(get_template_directory() . '/assets/css/root.css')) {
            todo_enqueue_style('{$target_slug}-root', \$theme_url . '/assets/css/root.css', [], '{$theme_version}', 'all');
        }
        todo_enqueue_style('{$target_slug}-style', \$theme_url . '/assets/css/style.css', [], '{$theme_version}', 'all');

        if (file_exists(get_template_directory() . '/assets/css/hero-slider.css')) {
            todo_enqueue_style('{$target_slug}-hero-slider', \$theme_url . '/assets/css/hero-slider.css', [], '{$theme_version}', 'all');
        }

        // jQuery 依赖排队
        \$jquery_url = file_exists(get_template_directory() . '/assets/js/jquery-3.6.0.min.js')
            ? \$theme_url . '/assets/js/jquery-3.6.0.min.js'
            : 'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js';
        todo_enqueue_script('jquery', \$jquery_url, [], '3.6.0', false);

        // Motion WAAPI 微动效引擎与运行时
        if (file_exists(get_template_directory() . '/assets/js/motion.min.js')) {
            todo_enqueue_script('motion', \$theme_url . '/assets/js/motion.min.js', [], '10.18.0', true);
            if (file_exists(get_template_directory() . '/assets/js/motion-helper.js')) {
                todo_enqueue_script('motion-helper', \$theme_url . '/assets/js/motion-helper.js', ['motion'], '1.0.0', true);
            }
        }

        if (file_exists(get_template_directory() . '/assets/js/csrf-manager.js')) {
            todo_enqueue_script('{$target_slug}-csrf', \$theme_url . '/assets/js/csrf-manager.js', ['jquery'], '{$theme_version}', true);
        }
        if (file_exists(get_template_directory() . '/assets/js/submit-guard.js')) {
            todo_enqueue_script('{$target_slug}-submit-guard', \$theme_url . '/assets/js/submit-guard.js', ['jquery'], '{$theme_version}', true);
        }
        if (file_exists(get_template_directory() . '/assets/js/hero-slider.js')) {
            todo_enqueue_script('{$target_slug}-hero-slider', \$theme_url . '/assets/js/hero-slider.js', ['jquery'], '{$theme_version}', true);
        }

        todo_enqueue_script('{$target_slug}-theme', \$theme_url . '/assets/js/theme.js', ['jquery'], '{$theme_version}', true);
    }
    add_action('todo_enqueue_scripts', '{$target_slug}_enqueue_assets');
}

// -------------------------------------------------------------
// 2. 主题激活自动数据填充 (Theme Activation Hook)
// -------------------------------------------------------------
if (!function_exists('{$target_slug}_theme_activated_handler')) {
    function {$target_slug}_theme_activated_handler() {
        \$theme_dir = get_template_directory();
        \$theme_url = get_template_directory_uri();
        // 自动数据初始化逻辑
    }
    add_action('todo_theme_activated', '{$target_slug}_theme_activated_handler');
}
PHP;
            file_put_contents($target_dir . '/functions.php', $functions_code);
            $logs[] = "⚙️ 基础驱动就绪：已写入标准 functions.php (已注入 todo_enqueue_scripts 与 todo_theme_activated)";

            // 生成 theme.json
            $directories = [
                'pc' => 'pc',
                'users' => 'pc/users',
                'images_data' => 'pc/assets/images/data'
            ];
            if ($has_mobile) {
                $directories['mobile'] = 'mobile';
            }

            $theme_json_data = [
                'slug' => $target_slug,
                'name' => $theme_name ?: $target_slug,
                'version' => $theme_version,
                'author' => $theme_author,
                'description' => $theme_desc,
                'mode' => $theme_mode,
                'material_slug' => $material_slug,
                'custom_material_url' => $custom_material_url,
                'directories' => $directories
            ];
            file_put_contents($target_dir . '/theme.json', json_encode($theme_json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $logs[] = "📝 主题元数据就绪：已生成符合 TodoCMS 规范的 theme.json";

            // 生成 AI 投喂就绪目录与规范
            $ai_ws_dir = $target_dir . '/.ai_workspace';
            todo_studio_ensure_dir($ai_ws_dir);
            todo_studio_ensure_dir($ai_ws_dir . '/02_MATERIAL_DESIGN');
            todo_studio_ensure_dir($ai_ws_dir . '/03_OFFICIAL_REFERENCE');

            if ($material_slug === '__custom_url__' || !empty($custom_material_url)) {
                $ref_url_content = <<<REFURL
# 🌐 自定义强参考对标目标 (Benchmark Reference Target)

- **对标站点/原型 URL**：`{$custom_material_url}`
- **🎯 强参考层级法则（最高优先级指令）**：
  1. **第一层级（结构与交互基线 - 强对标、最高优先级）**：深入分析对标目标站点的 DOM 结构、页面 UI 布局划分、内容区块流向、排版节奏与 JS 交互动效（如移动端抽屉导航、Header 粘滞动效、轮播动效、筛选过滤交互等），优先原汁原味对标并还原其交互与 DOM 布局体系。
  2. **第二层级（视觉调性与风格补充 - 遵循业务定位）**：在稳固的 DOM 与交互结构上，根据目标主题【{$target_slug}】的品牌调性与业务场景，完成视觉色彩、字体层级、光影质感与配图的补充融合。
REFURL;
                file_put_contents($ai_ws_dir . '/02_MATERIAL_DESIGN/REFERENCE_URL.md', $ref_url_content);
                $logs[] = "🌐 强对标目标设定：已绑定自定义参考 URL [{$custom_material_url}]，AI 将强对标其 DOM 布局与 JS 动效";
            } elseif ($has_material) {
                todo_studio_copy_material_dir($mat_dir, $ai_ws_dir . '/02_MATERIAL_DESIGN');
                file_put_contents($ai_ws_dir . '/02_MATERIAL_DESIGN/ASSETS_NOTE.md', "# ⚡ 素材包静态资源优化说明\n素材包中已自动过滤体积大于 150KB 的外部编译库（如 Tailwind 编译产物），因为 `01_TARGET_THEME/pc/assets/css/tailwind-atomic.min.css` 已经内置了系统全量原子类底座。请将精力 100% 聚焦于 HTML 骨架与设计视觉提取。");
                $logs[] = "🎨 素材原型注入：已将素材包 [{$material_slug}] 精简克隆至 .ai_workspace/02_MATERIAL_DESIGN/（已智能排除 >150KB 外部冗余库，防止 LLM 注意力淹没）";
            }

            if (is_dir($official_pc_dir)) {
                todo_studio_copy_dir($official_pc_dir, $ai_ws_dir . '/03_OFFICIAL_REFERENCE/pc');
                $logs[] = "📚 官方标准对标库：已将官方原 38 套基础模板注入 .ai_workspace/03_OFFICIAL_REFERENCE/pc/";
            }

            $task_md_content = todo_studio_build_development_task_md($target_slug, $theme_name ?: $target_slug, $material_slug, $custom_material_url);
            file_put_contents($ai_ws_dir . '/04_DEVELOPMENT_TASK.md', $task_md_content);

            echo json_encode([
                'success' => true,
                'target_slug' => $target_slug,
                'logs' => $logs,
                'message' => "主题 [{$target_slug}] 地基工程构建成功！全套静态基础设施已 100% 完备对齐。"
            ]);
            break;

        // -------------------------------------------------------------
        // 5. 任务 2：生成全量 AI 规格说明书 (JSON) 与 Prompt
        // -------------------------------------------------------------
        case 'generate_ai_spec':
        case 'theme_studio_generate_ai_spec':
            $theme_slug = todo_studio_sanitize_slug($_POST['theme_slug'] ?? '');
            $theme_name = trim($_POST['theme_name'] ?? '');
            $theme_version = trim($_POST['theme_version'] ?? '1.0.0');
            $theme_mode = $_POST['theme_mode'] ?? 'pc_mobile';

            if (empty($theme_slug)) {
                throw new Exception('未指定主题文件夹名称');
            }

            if (isset($_POST['material_slug'])) {
                $material_slug = trim($_POST['material_slug']);
            } else {
                $material_slug = '';
                if (file_exists(ABSPATH . 'content/themes/' . $theme_slug . '/theme.json')) {
                    $meta = json_decode(file_get_contents(ABSPATH . 'content/themes/' . $theme_slug . '/theme.json'), true);
                    if (!empty($meta['material_slug'])) {
                        $material_slug = $meta['material_slug'];
                    }
                }
            }

            if (isset($_POST['custom_material_url'])) {
                $custom_material_url = trim($_POST['custom_material_url']);
            } else {
                $custom_material_url = '';
                if (file_exists(ABSPATH . 'content/themes/' . $theme_slug . '/theme.json')) {
                    $meta = json_decode(file_get_contents(ABSPATH . 'content/themes/' . $theme_slug . '/theme.json'), true);
                    if (!empty($meta['custom_material_url'])) {
                        $custom_material_url = $meta['custom_material_url'];
                    }
                }
            }

            $spec = todo_studio_build_full_ai_spec($theme_slug, $theme_name, $theme_version, $theme_mode, $material_slug, $custom_material_url);
            $prompt_md = todo_studio_build_prompt_guide($theme_slug, $theme_name, $spec, $material_slug, $custom_material_url);

            echo json_encode([
                'success' => true,
                'spec_json' => $spec,
                'prompt_markdown' => $prompt_md,
                'filename' => 'theme-ai-spec-' . $theme_slug . '.json'
            ]);
            break;

        // -------------------------------------------------------------
        // 6. 任务 3：一键全流程打包 AI 全量工程采样与交付包 (Full AI Bundle ZIP)
        // -------------------------------------------------------------
        case 'onekey_package':
        case 'theme_studio_onekey_package':
            $target_slug = todo_studio_sanitize_slug($_POST['target_slug'] ?? '');
            if (empty($target_slug)) {
                throw new Exception('未指定主题文件夹名称');
            }

            $theme_dir = ABSPATH . 'content/themes/' . $target_slug;
            if (!is_dir($theme_dir)) {
                throw new Exception('主题目录不存在，请先执行“构建基础主题包”');
            }

            if (isset($_POST['material_slug'])) {
                $material_slug = trim($_POST['material_slug']);
            } else {
                $material_slug = '';
                if (file_exists($theme_dir . '/theme.json')) {
                    $meta = json_decode(file_get_contents($theme_dir . '/theme.json'), true);
                    if (!empty($meta['material_slug'])) {
                        $material_slug = $meta['material_slug'];
                    }
                }
            }

            if (isset($_POST['custom_material_url'])) {
                $custom_material_url = trim($_POST['custom_material_url']);
            } else {
                $custom_material_url = '';
                if (file_exists($theme_dir . '/theme.json')) {
                    $meta = json_decode(file_get_contents($theme_dir . '/theme.json'), true);
                    if (!empty($meta['custom_material_url'])) {
                        $custom_material_url = $meta['custom_material_url'];
                    }
                }
            }

            if (!class_exists('ZipArchive')) {
                throw new Exception('当前 PHP 环境未启用 ZipArchive 扩展，无法生成 ZIP 包');
            }

            $backup_dir = ABSPATH . 'content/backups';
            todo_studio_ensure_dir($backup_dir);

            $zip_filename = 'theme-' . $target_slug . '-ai-full-bundle.zip';
            $zip_filepath = $backup_dir . '/' . $zip_filename;
            if (file_exists($zip_filepath)) {
                @unlink($zip_filepath);
            }

            $zip = new ZipArchive();
            if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('无法创建压缩包: ' . $zip_filename);
            }

            // 清理主题根目录下可能存在的冗余 assets/ 目录
            if (is_dir($theme_dir . '/assets')) {
                todo_studio_delete_dir($theme_dir . '/assets');
            }

            // 打包前自愈同步：确保核心基础设施组件完备
            $default_theme_dir = ABSPATH . 'content/themes/default';

            // (a) 同步用户中心 pc/users
            $pc_users_dir = $theme_dir . '/pc/users';
            $default_users_dir = $default_theme_dir . '/pc/users';
            if (!is_dir($pc_users_dir) && is_dir($default_users_dir)) {
                todo_studio_copy_dir($default_users_dir, $pc_users_dir);
            }

            // (b) 同步语言包 pc/assets/lang 并进行健康检查与兜底自愈
            $default_lang_dir = $default_theme_dir . '/pc/assets/lang';
            $target_pc_lang = $theme_dir . '/pc/assets/lang';
            if (is_dir($default_lang_dir)) {
                if (!is_dir($target_pc_lang)) {
                    todo_studio_copy_dir($default_lang_dir, $target_pc_lang);
                } else {
                    // 检查每个语言文件是否存在语法错误（如未转义单引号导致 ParseError）
                    $lang_files = glob($target_pc_lang . '/*.php');
                    if ($lang_files) {
                        foreach ($lang_files as $lfile) {
                            $is_valid = false;
                            if (function_exists('exec')) {
                                $chk_out = [];
                                $chk_ret = 0;
                                @exec('php -l ' . escapeshellarg($lfile) . ' 2>&1', $chk_out, $chk_ret);
                                $is_valid = ($chk_ret === 0);
                            } else {
                                try {
                                    $check_map = @include $lfile;
                                    $is_valid = is_array($check_map);
                                } catch (\Throwable $te) {
                                    $is_valid = false;
                                }
                            }
                            if (!$is_valid) {
                                $fallback_file = $default_lang_dir . '/' . basename($lfile);
                                if (file_exists($fallback_file)) {
                                    @copy($fallback_file, $lfile);
                                }
                            }
                        }
                    }
                }
            }

            // (c) 同步开源 Tailwind 原子 CSS 库及 user-center.css
            $default_tailwind = $default_theme_dir . '/pc/assets/css/tailwind-atomic.min.css';
            if (file_exists($default_tailwind)) {
                $target_pc_tw = $theme_dir . '/pc/assets/css/tailwind-atomic.min.css';
                if (!file_exists($target_pc_tw)) {
                    todo_studio_ensure_dir(dirname($target_pc_tw));
                    @copy($default_tailwind, $target_pc_tw);
                }
            }

            // (d) 同步轮播、微动效与守卫脚本
            $scripts_to_sync = ['hero-slider.js', 'hero-slider.css', 'csrf-manager.js', 'submit-guard.js', 'jquery-3.6.0.min.js', 'motion.min.js', 'motion-helper.js', 'ajax-pager.js'];
            foreach ($scripts_to_sync as $sfile) {
                $ext = pathinfo($sfile, PATHINFO_EXTENSION);
                $sub = ($ext === 'css') ? 'css' : 'js';
                $src_file = $default_theme_dir . '/pc/assets/' . $sub . '/' . $sfile;
                if (file_exists($src_file)) {
                    $dst_pc = $theme_dir . '/pc/assets/' . $sub . '/' . $sfile;
                    if (!file_exists($dst_pc)) {
                        todo_studio_ensure_dir(dirname($dst_pc));
                        @copy($src_file, $dst_pc);
                    }
                }
            }

            // (e) 确保安全验证码组件 captcha_modal.html 真实存在且非伪造空壳
            $default_captcha = $default_theme_dir . '/pc/captcha_modal.html';
            $target_captcha = $theme_dir . '/pc/captcha_modal.html';
            if (file_exists($default_captcha)) {
                if (!file_exists($target_captcha)) {
                    todo_studio_ensure_dir(dirname($target_captcha));
                    @copy($default_captcha, $target_captcha);
                } else {
                    $c_content = @file_get_contents($target_captcha);
                    if ($c_content !== false && strpos($c_content, 'window.Captcha') === false) {
                        @copy($default_captcha, $target_captcha);
                    }
                }
            }

            // (f) 自动清洗所有模板与脚本中的末尾异常转义字符
            $pre_scan_files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($pre_scan_files as $f) {
                if (!$f->isDir()) {
                    $ext = strtolower($f->getExtension());
                    if (in_array($ext, ['js', 'css', 'php', 'html', 'json'])) {
                        $fpath = $f->getRealPath();
                        if (strpos($fpath, '.ai_workspace') !== false) {
                            continue;
                        }
                        $content = @file_get_contents($fpath);
                        if ($content !== false && preg_match('/(?:\\\\n|\x5c\x6e)+$/s', $content)) {
                            $cleaned = preg_replace('/(?:\\\\n|\x5c\x6e)+$/s', "\n", $content);
                            @file_put_contents($fpath, $cleaned);
                        }
                    }
                }
            }

            // 1. 注入 01_TARGET_THEME/ (目标主题目录，排除 .ai_workspace 避免重复)
            $theme_files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($theme_files as $file) {
                if (!$file->isDir()) {
                    $file_path = $file->getRealPath();
                    $rel_path = substr($file_path, strlen($theme_dir) + 1);
                    if (strpos($rel_path, '.ai_workspace') === 0 || strpos($rel_path, '.DS_Store') !== false) {
                        continue;
                    }
                    $zip->addFile($file_path, '01_TARGET_THEME/' . $rel_path);
                }
            }

            // 2. 注入 02_MATERIAL_DESIGN/ (智能精简，排除 >150KB 巨型编译样式/脚本，避免几十万 token 噪音)
            $mat_dir = (!empty($material_slug) && $material_slug !== '__custom_url__') ? ABSPATH . 'content/materials/' . $material_slug : '';
            if (!empty($mat_dir) && is_dir($mat_dir)) {
                $mat_files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($mat_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($mat_files as $file) {
                    if (!$file->isDir()) {
                        $file_path = $file->getRealPath();
                        $rel_path = substr($file_path, strlen($mat_dir) + 1);
                        if (strpos($rel_path, '.DS_Store') !== false) {
                            continue;
                        }
                        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                        $fsize = @filesize($file_path);
                        if (in_array($ext, ['css', 'js', 'map']) && $fsize > 150 * 1024) {
                            continue;
                        }
                        if (in_array($ext, ['mp4', 'webm', 'mov', 'zip', 'tar', 'gz']) && $fsize > 2 * 1024 * 1024) {
                            continue;
                        }
                        $zip->addFile($file_path, '02_MATERIAL_DESIGN/' . $rel_path);
                    }
                }
                $zip->addFromString('02_MATERIAL_DESIGN/ASSETS_NOTE.md', "# ⚡ 素材包静态资源优化说明\n素材包中已自动过滤体积大于 150KB 的外部编译库（如 Tailwind 编译产物），因为 `01_TARGET_THEME/pc/assets/css/tailwind-atomic.min.css` 已经内置了系统全量原子类底座。请将精力 100% 聚焦于 HTML 骨架与设计视觉提取。");
            }
            if ($material_slug === '__custom_url__' || !empty($custom_material_url)) {
                $ref_url_content = <<<REFURL
# 🌐 自定义强参考对标目标 (Benchmark Reference Target)

- **对标站点/原型 URL**：`{$custom_material_url}`
- **🎯 强参考层级法则（最高优先级指令）**：
  1. **第一层级（结构与交互基线 - 强对标、最高优先级）**：深入分析对标目标站点的 DOM 结构、页面 UI 布局划分、内容区块流向、排版节奏与 JS 交互动效（如移动端抽屉导航、Header 粘滞动效、轮播动效、筛选过滤交互等），优先原汁原味对标并还原其交互与 DOM 布局体系。
  2. **第二层级（视觉调性与风格补充 - 遵循业务定位）**：在稳固的 DOM 与交互结构上，根据目标主题【{$target_slug}】的品牌调性与业务场景，完成视觉色彩、字体层级、光影质感与配图的补充融合。
REFURL;
                $zip->addFromString('02_MATERIAL_DESIGN/REFERENCE_URL.md', $ref_url_content);
            } elseif (empty($mat_dir) || !is_dir($mat_dir)) {
                $zip->addFromString('02_MATERIAL_DESIGN/README.md', "# 设计素材包说明\n当前主题构建未指定特定外部素材包，请依照现代化 Web 设计美学对 01_TARGET_THEME/ 进行精细化重构。");
            }

            // 3. 注入 03_OFFICIAL_REFERENCE/pc
            $off_pc = $default_theme_dir . '/pc';
            if (is_dir($off_pc)) {
                $off_files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($off_pc, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($off_files as $file) {
                    if (!$file->isDir()) {
                        $file_path = $file->getRealPath();
                        $rel_path = substr($file_path, strlen($off_pc) + 1);
                        if (strpos($rel_path, '.DS_Store') !== false) {
                            continue;
                        }
                        $zip->addFile($file_path, '03_OFFICIAL_REFERENCE/pc/' . $rel_path);
                    }
                }
            }

            // 4. 注入 04_DEVELOPMENT_TASK.md 终极防跑偏任务契约
            $task_instructions = todo_studio_build_development_task_md($target_slug, $target_slug, $material_slug, $custom_material_url);
            $zip->addFromString('04_DEVELOPMENT_TASK.md', $task_instructions);

            // 5. 注入 theme-ai-spec.json
            $ai_spec = todo_studio_build_full_ai_spec($target_slug, $target_slug, '1.0.0', 'pc_mobile', $material_slug, $custom_material_url);
            $zip->addFromString('theme-ai-spec.json', json_encode($ai_spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $zip->close();

            $download_url = todo_get_site_url('content') . '/backups/' . $zip_filename;
            // 复制一份为 ready.zip 方便兼容下载
            @copy($zip_filepath, $backup_dir . '/theme-' . $target_slug . '-ai-ready.zip');

            echo json_encode([
                'success' => true,
                'zip_filename' => $zip_filename,
                'download_url' => $download_url,
                'file_size' => filesize($zip_filepath),
                'message' => '全流程 AI 投喂就绪包打包完成！已整合 01 目标地基、02 素材设计、03 官方标杆与 04 开发契约。'
            ]);
            break;

        default:
            throw new Exception("不支持的操作指令: " . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

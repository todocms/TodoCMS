<?php
/**
 * TodoCMS 企业级防采集与流量防护防火墙管理器 (Todo_Firewall_Manager)
 *
 * 核心功能：
 * 1. 搜索引擎蜘蛛（Google/Baidu/Bing/Yandex/Sogou等）反向 DNS 双向真实性校验 + 本地持久化缓存 (24h)
 * 2. 蜜罐陷阱 (Honeypot Trap) 自动识别与 7 天长期黑名单封禁
 * 3. 常见非浏览器爬虫 UA (python-requests, Scrapy, curl, wget, Puppeteer等) 智能拦截
 * 4. 高并发 IP 滑动窗口速率限制 (Rate Limiting) + 自动定期垃圾回收 (GC)
 * 5. 前端主题原生图形滑块拼图验证码 (Slider CAPTCHA) 挑战页 + 24小时白名单 Token 签发
 * 6. 隐蔽反侦察拦截：所有封禁/非法请求均静默伪装响应 404 Not Found (X-Robots-Tag: noindex, nofollow)
 * 7. 后台管理接口（获取封禁列表、解封 IP、读取安全日志）
 *
 * @package TodoCMS
 * @subpackage Core
 * @version 2.2.0
 */

class Todo_Firewall_Manager {
    /**
     * 路径与文件定义
     */
    private static $storage_dir     = null;
    private static $blacklist_file  = null;
    private static $bot_cache_file  = null;
    private static $rate_limit_file = null;
    private static $log_file        = null;

    /**
     * 已知搜索引擎蜘蛛及反向 DNS 匹配规则
     */
    private static $search_bots = [
        'Googlebot'   => ['googlebot.com', 'google.com'],
        'Bingbot'     => ['search.msn.com'],
        'Baiduspider' => ['baidu.com', 'baidu.jp'],
        'YandexBot'   => ['yandex.com', 'yandex.ru', 'yandex.net'],
        'Slurp'       => ['yahoo.com'],
        'DuckDuckBot' => ['duckduckgo.com'],
        'Sogou'       => ['sogou.com'],
        '360Spider'   => ['haosou.com', '360.cn'],
        'ByteDance'   => ['bytedance.com'],
    ];

    /**
     * 恶意爬虫 / 脚本 UA 黑名单关键词
     */
    private static $bad_ua_keywords = [
        'python-requests',
        'python-urllib',
        'Scrapy',
        'curl/',
        'wget/',
        'Go-http-client',
        'Apache-HttpClient',
        'Java/',
        'libwww-perl',
        'PyCurl',
        'aiohttp',
        'httpx/',
        'node-fetch',
        'axios/',
        'headlesschrome',
        'puppeteer',
        'selenium',
        'phantomjs',
    ];

    /**
     * 初始化防火墙配置与存储目录
     */
    private static function init_storage() {
        if (self::$storage_dir !== null) {
            return;
        }

        $base_log_dir = defined('TODO_LOG_DIR') ? TODO_LOG_DIR : (defined('ABSPATH') ? ABSPATH . 'logs' : __DIR__ . '/../../logs');
        self::$storage_dir = $base_log_dir . '/firewall';

        if (!is_dir(self::$storage_dir)) {
            @mkdir(self::$storage_dir, 0755, true);
        }

        self::$blacklist_file  = self::$storage_dir . '/blacklist.json';
        self::$bot_cache_file  = self::$storage_dir . '/bot_cache.json';
        self::$rate_limit_file = self::$storage_dir . '/rate_limit.json';
        self::$log_file        = self::$storage_dir . '/firewall.log';

        // 概率触发过期数据垃圾回收 (1% 概率)
        if (rand(1, 100) === 1) {
            self::garbage_collection();
        }
    }

    /**
     * 确保会话以 TodoCMS 前台会话规范安全启动
     */
    private static function ensure_session() {
        if (session_status() === PHP_SESSION_NONE) {
            if (class_exists('Todo_Session_Manager') && method_exists('Todo_Session_Manager', 'init')) {
                Todo_Session_Manager::init();
            } else {
                @session_start();
            }
        }
    }

    /**
     * 防火墙核心入口处理函数（在 index.php 极其前端调用）
     */
    public static function handle_request() {
        // 全局开关检查 (可通过定义 DISABLE_TODO_FIREWALL 常量关闭)
        if (defined('DISABLE_TODO_FIREWALL') && DISABLE_TODO_FIREWALL) {
            return;
        }
        if (php_sapi_name() === 'cli') {
            return;
        }

        self::init_storage();
        $ip  = self::get_client_ip();
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // 允许内部缓存预热请求 (携带 X-TODO-WARMUP 头) 全速放行，绕过限流
        if (!empty($_SERVER['HTTP_X_TODO_WARMUP'])) {
            return;
        }

        // 允许滑块验证码 API 路径透传放行，避免阻断验证码接口
        if (strpos($uri, '/api/captcha/slider.php') !== false) {
            return;
        }

        // 1. 蜜罐路径检查 (Trapdoor Honeypot: 默认封禁 7 天)
        if (self::is_honeypot_request($uri)) {
            self::ban_ip($ip, 7 * 86400, 'Honeypot trap triggered: ' . $uri);
            self::response_blocked();
        }

        // 2. 检查 IP 是否已在黑名单中
        if (self::is_ip_blacklisted($ip)) {
            self::response_blocked();
        }

        // 3. 搜索引擎蜘蛛识别与反向 DNS 验证
        $bot_name = self::detect_bot_ua($ua);
        if ($bot_name !== false) {
            $is_verified_bot = self::verify_search_bot_dns($ip, $bot_name, $ua);
            if ($is_verified_bot) {
                // 验证通过的搜索引擎蜘蛛：全速放行，绕过限流
                return;
            } else {
                // 伪造 UA 的假蜘蛛：直接封禁 7 天并静默响应 404
                self::ban_ip($ip, 7 * 86400, "Fake search engine bot detected claiming to be {$bot_name}");
                self::response_blocked();
            }
        }

        // 4. UA 黑名单与空 Header 匹配
        if (self::is_bad_ua($ua)) {
            self::response_blocked();
        }

        // 5. 检查客户端人机验证白名单 Token (已完成滑块验证的用户免打扰放行)
        if (self::has_valid_challenge_token($ip)) {
            return;
        }

        // 5.5 敏感入口专项严格限流防护 (写入攻击与搜索缓存爆破防护)
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && (
            strpos($uri, '/api/inquiry') !== false ||
            strpos($uri, '/api/subscribe') !== false ||
            strpos($uri, '/api/comment') !== false ||
            strpos($uri, '/inquiry') !== false ||
            strpos($uri, '/subscribe') !== false
        )) {
            // 写入提交类敏感接口：单 IP 严格限制每 30 秒最多 2 次提交
            if (!self::check_rate_limit($ip . '_write_sensitive', 2, 30)) {
                self::render_challenge_page($ip, __('提交过于频繁，请完成验证后再试'));
                exit;
            }
        }

        if (strpos($uri, '/search') !== false || isset($_GET['q'])) {
            $sc_raw = function_exists('todo_get_option') ? todo_get_option('search_config', '') : '';
            $sc     = !empty($sc_raw) ? json_decode($sc_raw, true) : [];
            $rate_max   = isset($sc['rate_max']) && (int)$sc['rate_max'] > 0 ? (int)$sc['rate_max'] : 5;
            $rate_limit = isset($sc['rate_limit']) && (int)$sc['rate_limit'] > 0 ? (int)$sc['rate_limit'] : 60;
            
            // 如果已被搜索频率锁定（由 search_config 控制），放行给搜索模板展示友好锁定提示（0 次数据库开销）
            $is_search_locked = function_exists('get_transient') && get_transient('search_lock_' . md5($ip));
            if (!$is_search_locked) {
                // 防火墙针对高频暴力刷词（如 10 秒内超 10 次或超配置阈值）进行人机验证防护
                $burst_limit = max(10, $rate_max * 2);
                if (!self::check_rate_limit($ip . '_search_sensitive', $burst_limit, 10)) {
                    self::render_challenge_page($ip, __('搜索请求过于频繁，请完成验证后再试'));
                    exit;
                }
            }
        }

        // 6. 滑动窗口全局 IP 速率限制 (Rate Limiting: 建议 30 次/分钟)
        // 合法搜索引擎蜘蛛已在前置步骤 3 中完全放行，此限流仅针对普通 IP
        $max_req = 30;
        if (!self::check_rate_limit($ip, $max_req, 60)) {
            self::render_challenge_page($ip, __('访问频率过高，请完成验证以恢复访问'));
            exit;
        }
    }

    /**
     * 智能解析客户端真实 IP 地址
     * 支持全球主流 CDN 厂商：
     * - 阿里云 CDN (ali-cdn-real-ip)
     * - Cloudflare (cf-connecting-ip)
     * - Amazon CloudFront (cloudfront-viewer-address)
     * - Fastly CDN (fastly-client-ip)
     * - Nginx 反向代理 (x-real-ip)
     * - 标准代理链 (x-forwarded-for)
     */
    public static function get_client_ip() {
        // 1. Amazon CloudFront 特殊格式处理 (格式如 "198.51.100.10:46532" 或 "[2001:db8::1]:46532")
        if (!empty($_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS'])) {
            $raw = trim($_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS']);
            // IPv6 带端口格式 [2001:db8::1]:46532
            if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $raw, $m)) {
                $ip = $m[1];
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
            // IPv4 带端口格式 198.51.100.10:46532
            $parts = explode(':', $raw);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        // 2. 主流 CDN 厂商与代理专用头清单
        $headers = [
            'HTTP_ALI_CDN_REAL_IP',   // 阿里云 CDN 专用 (ali-cdn-real-ip)
            'HTTP_CF_CONNECTING_IP',    // Cloudflare 专用 (cf-connecting-ip)
            'HTTP_FASTLY_CLIENT_IP',    // Fastly CDN 专用 (fastly-client-ip)
            'HTTP_X_REAL_IP',           // Nginx / 宝塔反向代理透传头
            'HTTP_X_FORWARDED_FOR',     // 标准代理链 (取第一跳客户端 IP)
            'REMOTE_ADDR'               // TCP 直连 socket IP (兜底)
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip_list = explode(',', $_SERVER[$header]);
                $ip = trim($ip_list[0]);
                // 清理 IPv4 误带的端口号 (如 1.2.3.4:8080)
                if (strpos($ip, ':') !== false && strpos($ip, '.') !== false) {
                    $ip = explode(':', $ip)[0];
                }
                // 校验格式是否为合法 IPv4 / IPv6
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 判断是否属于蜜罐防抓取路径
     */
    private static function is_honeypot_request($uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && (str_ends_with($path, '/trapdoor.html') || $path === 'trapdoor.html');
    }

    /**
     * 封禁 IP 并设定过期时间
     */
    public static function ban_ip($ip, $ttl = 604800, $reason = '') {
        self::init_storage();
        $blacklist = self::load_json(self::$blacklist_file);
        $blacklist[$ip] = [
            'expire' => time() + intval($ttl),
            'reason' => $reason,
            'time'   => date('Y-m-d H:i:s'),
        ];
        self::save_json(self::$blacklist_file, $blacklist);
        self::log_event("IP BANNED [{$ip}] TTL: {$ttl}s Reason: {$reason}");
    }

    /**
     * 解封指定 IP
     */
    public static function unban_ip($ip) {
        self::init_storage();
        $blacklist = self::load_json(self::$blacklist_file);
        if (isset($blacklist[$ip])) {
            unset($blacklist[$ip]);
            self::save_json(self::$blacklist_file, $blacklist);
            self::log_event("IP UNBANNED [{$ip}]");
            return true;
        }
        return false;
    }

    /**
     * 检查 IP 是否处于封禁状态
     */
    public static function is_ip_blacklisted($ip) {
        self::init_storage();
        $blacklist = self::load_json(self::$blacklist_file);
        if (isset($blacklist[$ip])) {
            if (time() < $blacklist[$ip]['expire']) {
                return true;
            } else {
                unset($blacklist[$ip]);
                self::save_json(self::$blacklist_file, $blacklist);
            }
        }
        return false;
    }

    /**
     * 检测 UA 是否包含知名搜索引擎蜘蛛标记
     */
    private static function detect_bot_ua($ua) {
        if (empty($ua)) {
            return false;
        }
        foreach (self::$search_bots as $bot => $domains) {
            if (stripos($ua, $bot) !== false) {
                return $bot;
            }
        }
        return false;
    }

    /**
     * 反向 DNS 双向匹配校验 (带 24h 高速本地缓存)
     */
    private static function verify_search_bot_dns($ip, $bot_name, $ua) {
        self::init_storage();
        $cache = self::load_json(self::$bot_cache_file);

        if (isset($cache[$ip]) && time() < $cache[$ip]['expire']) {
            return $cache[$ip]['verified'];
        }

        $allowed_domains = self::$search_bots[$bot_name] ?? [];
        $is_valid = false;

        $host = @gethostbyaddr($ip);
        if ($host && $host !== $ip) {
            foreach ($allowed_domains as $domain) {
                if (substr($host, -strlen('.' . $domain)) === '.' . $domain || $host === $domain) {
                    $resolved_ip = @gethostbyname($host);
                    if ($resolved_ip === $ip) {
                        $is_valid = true;
                        break;
                    }
                }
            }
        }

        $cache[$ip] = [
            'verified' => $is_valid,
            'bot'      => $bot_name,
            'expire'   => time() + 86400,
        ];
        self::save_json(self::$bot_cache_file, $cache);

        self::log_event("BOT DNS VERIFICATION [{$ip}] Bot: {$bot_name} Result: " . ($is_valid ? 'PASSED' : 'FAILED'));
        return $is_valid;
    }

    /**
     * 匹配非法/非浏览器 UA
     */
    private static function is_bad_ua($ua) {
        if (empty($ua)) {
            return true;
        }
        foreach (self::$bad_ua_keywords as $keyword) {
            if (stripos($ua, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 基于 APCu 或本地 JSON 的滑动窗口限流
     */
    private static function check_rate_limit($ip, $max_req = 60, $period = 60) {
        if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
            $key = 'todofw_rl_' . $ip;
            $count = apcu_fetch($key);
            if ($count === false) {
                apcu_store($key, 1, $period);
                return true;
            }
            if ($count >= $max_req) {
                return false;
            }
            if (function_exists('apcu_inc')) {
                apcu_inc($key);
            } else {
                apcu_store($key, $count + 1, $period);
            }
            return true;
        }

        self::init_storage();
        $rate_data = self::load_json(self::$rate_limit_file);
        $now = time();

        if (!isset($rate_data[$ip]) || ($now - $rate_data[$ip]['start_time']) > $period) {
            $rate_data[$ip] = [
                'start_time' => $now,
                'count'      => 1
            ];
            self::save_json(self::$rate_limit_file, $rate_data);
            return true;
        }

        $rate_data[$ip]['count']++;
        $is_allowed = ($rate_data[$ip]['count'] <= $max_req);
        self::save_json(self::$rate_limit_file, $rate_data);

        return $is_allowed;
    }

    /**
     * 校验人机白名单 Token（支持主题原生滑块拼图验证通过标记）
     */
    private static function has_valid_challenge_token($ip) {
        self::ensure_session();

        // 检查前端滑块验证码 API 验证成功标记
        if (!empty($_SESSION['todo_captcha_verified']) && $_SESSION['todo_captcha_verified'] === true) {
            // 自动签发 24 小时防火墙白名单 Token
            $_SESSION['todo_fw_whitelist_token'] = [
                'ip'     => $ip,
                'expire' => time() + 86400,
            ];

            $secret = defined('TODO_AUTH_KEY') ? TODO_AUTH_KEY : 'todocms_firewall_secret';
            $cookie_val = md5($ip . '_' . date('Ymd') . '_' . $secret);
            setcookie('todo_fw_whitelist', $cookie_val, time() + 86400, '/', '', false, true);

            return true;
        }

        if (!empty($_SESSION['todo_fw_whitelist_token'])) {
            $data = $_SESSION['todo_fw_whitelist_token'];
            if (isset($data['ip'], $data['expire']) && $data['ip'] === $ip && time() < $data['expire']) {
                return true;
            }
        }

        $cookie = $_COOKIE['todo_fw_whitelist'] ?? '';
        if (!empty($cookie)) {
            $secret = defined('TODO_AUTH_KEY') ? TODO_AUTH_KEY : 'todocms_firewall_secret';
            $expected = md5($ip . '_' . date('Ymd') . '_' . $secret);
            if (hash_equals($expected, $cookie)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 渲染嵌入前端主题图形滑块拼图验证码 (Slider CAPTCHA) 的挑战页面
     */
    private static function render_challenge_page($ip, $msg = '') {
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 60');

        $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : (defined('TODO_URL') ? rtrim(TODO_URL, '/') : '');
        $slider_api_url = $site_url ? ($site_url . '/api/captcha/slider.php') : '/api/captcha/slider.php';
        
        $title_text        = self::__('安全验证');
        $slider_text       = self::__('拖动滑块完成拼图');
        $refresh_text      = self::__('刷新');
        $err_load_text     = self::__('加载验证码失败，请刷新页面');
        $err_verify_text   = self::__('验证失败，请重试');
        $err_req_text      = self::__('验证请求失败');
        $display_msg       = !empty($msg) ? self::__($msg) : '';
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($title_text); ?> - TodoCMS</title>
            <style>
                body { background: #f4f6f9; color: #333; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                .captcha-card { background: #fff; padding: 20px; border-radius: 5px; width: 100%; max-width: 340px; box-shadow: 0 3px 5px rgba(0, 0, 0, 0.05); text-align: center; }
                .captcha-card h3 { margin: 0 0 10px; font-size: 18px; color: #1a202c; font-weight: 600; }
                .captcha-card p { font-size: 13px; color: #718096; margin-bottom: 20px; line-height: 1.5; }
                
                /* Captcha Public Styles (Matches content/themes/default/pc/captcha_modal.html) */
                .captcha-img-box { position: relative; width: 300px; height: 150px; background: #eee; margin: 0 auto; overflow: hidden; border-radius: 6px; }
                .captcha-img-box:before { content: '☯'; left: 50%; top: 50%; position: absolute; transform: translate(-50%, -50%); font-size: 40px; opacity: 0.15; animation: captcha-spin 1s linear infinite; }
                @keyframes captcha-spin { 0% { transform: translate(-50%, -50%) rotate(0deg); } 100% { transform: translate(-50%, -50%) rotate(360deg); } }
                #captcha-bg { width: 100%; height: 100%; display: block; position: relative; }
                #captcha-piece { position: absolute; top: 0; left: 0; height: 50px; z-index: 2; }
                .captcha-slider-box { position: relative; width: 290px; height: 42px; background: #f7f9fa; margin: 16px auto 12px; border-radius: 21px; border: 1px solid #e4e7eb; }
                #captcha-slider-bar { position: absolute; left: -1px; top: -1px; height: 44px; width: 0; background: #d1e9ff; border-radius: 21px 0 0 21px; border: 1px solid #1991fa; border-right: 0; box-sizing: border-box; }
                #captcha-slider-btn { position: absolute; left: -1px; top: -1px; height: 44px; width: 44px; background: #fff; border-radius: 50%; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15); cursor: pointer; display: flex; align-items: center; justify-content: center; color: #4a5568; border: 1px solid #cbd5e0; z-index: 3; font-size: 16px; user-select: none; transition: background 0.2s, color 0.2s; }
                #captcha-slider-btn:hover { background: #3182ce; color: #fff; border-color: #3182ce; }
                .captcha-text { text-align: center; line-height: 42px; color: #999; font-size: 13px; user-select: none; text-indent: 30px; overflow: hidden; height: 100%; }
                .captcha-footer { text-align: right; margin-top: 10px; }
                .captcha-btn { background: none; border: none; cursor: pointer; font-size: 13px; color: #666; padding: 5px 10px; }
                .captcha-btn:hover { background: none; border: none; color: #333 !important; text-decoration: underline; }
                @media (max-width: 330px) {
                    .captcha-img-box { transform: scaleX(0.94); transform-origin: left; }
                    .captcha-slider-box { transform: scaleX(0.96); transform-origin: left; }
                }
                @media (max-width: 250px) {
                    .captcha-box { padding: 15px 10px; width: auto; max-width: 100vw; }
                    .captcha-img-box { transform: scaleX(0.67); transform-origin: left; }
                    .captcha-slider-box { transform: scaleX(0.69); transform-origin: left; }
                }
            </style>
        </head>
        <body>
            <div class="captcha-card">
                <h3><?php echo htmlspecialchars($title_text); ?></h3>
                <p><?php echo htmlspecialchars($display_msg); ?></p>
                <div class="captcha-img-box">
                    <img id="captcha-bg">
                    <img id="captcha-piece">
                </div>
                <div class="captcha-slider-box">
                    <div id="captcha-slider-bar"></div>
                    <div id="captcha-slider-btn">➝</div>
                    <div class="captcha-text"><?php echo htmlspecialchars($slider_text); ?></div>
                </div>
                <div class="captcha-footer">
                     <button type="button" id="captcha-refresh" class="captcha-btn"><?php echo htmlspecialchars($refresh_text); ?></button>
                </div>
            </div>

            <script>
            window.Captcha = {
                bg: null,
                piece: null,
                sliderBtn: null,
                sliderBar: null,
                refreshBtn: null,
                isDragging: false,
                startX: 0,
                apiUrl: '<?php echo $slider_api_url; ?>',
                powSeed: null,
                powDifficulty: 3,
                trackData: [],

                sha256: function(str) {
                    function rightRotate(value, amount) {
                        return (value >>> amount) | (value << (32 - amount));
                    }
                    var mathPow = Math.pow;
                    var maxWord = mathPow(2, 32);
                    var i, j;
                    var result = "";
                    var bytes = [];
                    var h = [
                        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19
                    ];
                    var k = [
                        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
                        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
                        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
                        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
                        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
                        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
                        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
                        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
                    ];
                    var asciiBitLength = str.length * 8;
                    for (i = 0; i < str.length; i++) bytes.push(str.charCodeAt(i));
                    bytes.push(0x80);
                    while ((bytes.length % 64) !== 56) bytes.push(0);
                    var words = [];
                    for (i = 0; i < bytes.length; i += 4) {
                        words.push((bytes[i] << 24) | (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | (bytes[i + 3]));
                    }
                    words.push(Math.floor(asciiBitLength / maxWord));
                    words.push(asciiBitLength & 0xffffffff);
                    for (j = 0; j < words.length;) {
                        var w = words.slice(j, j += 16), oldH = h.slice(0);
                        for (i = 0; i < 64; i++) {
                            var w15 = w[i - 15], w2 = w[i - 2], a = h[0], e = h[4];
                            var s0 = (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3));
                            var s1 = (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10));
                            if (i >= 16) w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
                            var S1 = (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25));
                            var ch = ((e & h[5]) ^ ((~e) & h[6]));
                            var temp1 = (h[7] + S1 + ch + k[i] + w[i]) | 0;
                            var S0 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22));
                            var maj = ((a & h[1]) ^ (a & h[2]) ^ (h[1] & h[2]));
                            var temp2 = (S0 + maj) | 0;
                            h[7] = h[6]; h[6] = h[5]; h[5] = h[4]; h[4] = (h[3] + temp1) | 0;
                            h[3] = h[2]; h[2] = h[1]; h[1] = h[0]; h[0] = (temp1 + temp2) | 0;
                        }
                        for (i = 0; i < 8; i++) h[i] = (h[i] + oldH[i]) | 0;
                    }
                    for (i = 0; i < 8; i++) {
                        var hex = (h[i] >>> 0).toString(16);
                        result += ("00000000" + hex).slice(-8);
                    }
                    return result;
                },

                solvePoW: function() {
                    if (!this.powSeed) return '0';
                    var prefix = '0'.repeat(this.powDifficulty || 3);
                    var nonce = 0;
                    while (nonce < 100000) {
                        var hashVal = this.sha256(this.powSeed + nonce);
                        if (hashVal && hashVal.indexOf(prefix) === 0) {
                            return nonce;
                        }
                        nonce++;
                    }
                    return nonce;
                },
                
                init: function() {
                    var self = this;
                    this.bg = document.getElementById('captcha-bg');
                    this.piece = document.getElementById('captcha-piece');
                    this.sliderBtn = document.getElementById('captcha-slider-btn');
                    this.sliderBar = document.getElementById('captcha-slider-bar');
                    this.refreshBtn = document.getElementById('captcha-refresh');

                    if (!this.bg || !this.refreshBtn) return;

                    this.refreshBtn.addEventListener('click', function() { self.load(); });
                    
                    this.sliderBtn.addEventListener('mousedown', function(e) { self.startDrag(e); });
                    this.sliderBtn.addEventListener('touchstart', function(e) { self.startDrag(e); }, { passive: false });
                    document.addEventListener('mousemove', function(e) { self.onDrag(e); });
                    document.addEventListener('touchmove', function(e) { self.onDrag(e); }, { passive: false });
                    document.addEventListener('mouseup', function(e) { self.stopDrag(e); });
                    document.addEventListener('touchend', function(e) { self.stopDrag(e); });

                    this.load();
                },
                
                load: function() {
                    var self = this;
                    if (!this.sliderBtn || !this.sliderBar || !this.piece) return;
                    this.trackData = [];
                    this.sliderBtn.style.left = '-1px';
                    this.sliderBar.style.width = '0px';
                    this.sliderBar.style.borderWidth = '0px';
                    this.piece.style.left = '-1px';
                    
                    var formData = new FormData();
                    formData.append('action', 'get_captcha');
                    
                    fetch(this.apiUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.bg) {
                            self.bg.src = data.bg;
                            self.piece.src = data.piece;
                            self.piece.style.top = data.y + 'px'; 
                            self.powSeed = data.pow_seed || null;
                            self.powDifficulty = data.pow_difficulty || 3;
                        } else {
                            alert(data.message || <?php echo json_encode($err_load_text); ?>);
                        }
                    })
                    .catch(function(err) {
                        alert(<?php echo json_encode($err_load_text); ?>);
                    });
                },
                
                startDrag: function(e) {
                    this.isDragging = true;
                    this.startX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                    var startY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                    this.trackData = [{ x: 0, y: startY, t: Date.now() }];
                },
                
                onDrag: function(e) {
                    if (!this.isDragging) return;
                    var clientX = (e.clientX !== undefined) ? e.clientX : (e.touches ? e.touches[0].clientX : 0);
                    var clientY = (e.clientY !== undefined) ? e.clientY : (e.touches ? e.touches[0].clientY : 0);
                    var moveX = clientX - this.startX;
                    if (moveX < 0) moveX = 0;
                    if (moveX > 250) moveX = 250;
                    
                    this.trackData.push({
                        x: Math.round(moveX),
                        y: Math.round(clientY),
                        t: Date.now()
                    });

                    this.sliderBtn.style.left = moveX * 0.98 + 'px';
                    this.sliderBar.style.width = moveX + 20 + 'px';
                    this.sliderBar.style.borderWidth = '1px';
                    this.piece.style.left = moveX * 0.96 + 'px';
                    
                    if (e.cancelable) e.preventDefault();
                },
                
                stopDrag: function(e) {
                    if (!this.isDragging) return;
                    this.isDragging = false;
                    var x = parseInt(this.sliderBtn.style.left);
                    this.verify(x);
                },
                
                verify: function(x) {
                    var self = this;
                    var powNonce = this.solvePoW();

                    var formData = new FormData();
                    formData.append('action', 'verify_captcha');
                    formData.append('x', x);
                    formData.append('pow_nonce', powNonce);
                    formData.append('track', JSON.stringify(this.trackData));
                    
                    fetch(this.apiUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // 验证成功，刷新页面，触发防火墙白名单放行
                            window.location.reload();
                        } else {
                            alert(data.message || <?php echo json_encode($err_verify_text); ?>);
                            self.load();
                        }
                    })
                    .catch(function(err) {
                        alert(<?php echo json_encode($err_req_text); ?>);
                        self.load();
                    });
                }
            };

            document.addEventListener('DOMContentLoaded', function() {
                window.Captcha.init();
            });
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 静默伪装响应 404 Not Found 隐蔽拦截（不暴露防火墙的存在与拦截原因）
     */
    private static function response_blocked() {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
        exit('404 Not Found');
    }

    /**
     * 注入 HTML 底部隐藏蜜罐链接（仅在正常 200 OK 页面上注入，被拦截/非 200 页面决不输出）
     */
    public static function inject_honeypot_link($html) {
        // 如果 HTTP 响应状态码不是 200，绝不注入蜜罐链接
        $status = http_response_code();
        if ($status !== false && $status !== 200) {
            return $html;
        }

        // 如果该 IP 已在黑名单中，绝不注入蜜罐链接
        $ip = self::get_client_ip();
        if (self::is_ip_blacklisted($ip)) {
            return $html;
        }

        if (empty($html) || !is_string($html)) {
            return $html;
        }

        $trap_url = function_exists('todo_get_site_url') ? todo_get_site_url('trapdoor.html') : '/trapdoor.html';
        $trap_link = '<a href="' . htmlspecialchars($trap_url, ENT_QUOTES, 'UTF-8') . '" style="display:none !important;" aria-hidden="true" tabindex="-1" rel="nofollow">.</a>';

        // 已经注入过蜜罐链接，直接返回，避免多重 </body> 嵌套重复注入
        if (strpos($html, $trap_url) !== false) {
            return $html;
        }

        // 找到 HTML 最后一个 </body> 标签位置进行精准单次插入
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            return substr_replace($html, $trap_link . '</body>', $pos, strlen('</body>'));
        }

        return $html;
    }

    /**
     * 自动清理过期数据 (Garbage Collection)
     */
    private static function garbage_collection() {
        self::init_storage();
        $now = time();

        // 1. 清理过期黑名单
        $blacklist = self::load_json(self::$blacklist_file);
        $modified = false;
        foreach ($blacklist as $ip => $item) {
            if (isset($item['expire']) && $now >= $item['expire']) {
                unset($blacklist[$ip]);
                $modified = true;
            }
        }
        if ($modified) {
            self::save_json(self::$blacklist_file, $blacklist);
        }

        // 2. 清理过期 Bot DNS 缓存
        $bot_cache = self::load_json(self::$bot_cache_file);
        $b_modified = false;
        foreach ($bot_cache as $ip => $item) {
            if (isset($item['expire']) && $now >= $item['expire']) {
                unset($bot_cache[$ip]);
                $b_modified = true;
            }
        }
        if ($b_modified) {
            self::save_json(self::$bot_cache_file, $bot_cache);
        }

        // 3. 清理过期 Rate Limit 缓存
        $rate_limit = self::load_json(self::$rate_limit_file);
        $r_modified = false;
        foreach ($rate_limit as $ip => $item) {
            if (isset($item['start_time']) && ($now - $item['start_time']) > 120) {
                unset($rate_limit[$ip]);
                $r_modified = true;
            }
        }
        if ($r_modified) {
            self::save_json(self::$rate_limit_file, $rate_limit);
        }
    }

    /**
     * 多语言国际化翻译助手
     */
    private static function __($text) {
        if (function_exists('__')) {
            return __($text);
        }
        return $text;
    }

    /**
     * JSON 数据加载器
     */
    private static function load_json($filepath) {
        if (!file_exists($filepath)) {
            return [];
        }
        $content = @file_get_contents($filepath);
        if (!$content) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * JSON 数据持久化保存助手
     */
    private static function save_json($filepath, array $data) {
        @file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * 写入安全日志
     */
    private static function log_event($message) {
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents(self::$log_file, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * [后台 Api] 获取所有当前有效被封禁的 IP
     */
    public static function get_blacklisted_ips() {
        self::init_storage();
        return self::load_json(self::$blacklist_file);
    }

    /**
     * [后台 Api] 读取日志内容
     */
    public static function get_recent_logs($lines = 100) {
        self::init_storage();
        if (!file_exists(self::$log_file)) {
            return [];
        }
        $file_lines = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$file_lines) {
            return [];
        }
        return array_slice($file_lines, -$lines);
    }
}

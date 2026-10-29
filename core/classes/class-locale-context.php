<?php
/**
 * LocaleContext 请求级语言解析中间件
 * 优先级：路径段/路由参数 > 域名映射 > Cookie > Accept-Language > 默认
 *
 * @package TodoCMS
 * @version 1.0.0
 */
class LocaleContext
{
    private static $instance = null;
    private $locale = null;   // hyphen style, e.g. en-US
    private $source = 'default';

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    public function bootstrap()
    {
        $reg = LanguageRegistry::get_instance();
        $this->locale = null;

        // 0) URL参数/POST参数（最高优先级，用于强制切换或API传参）
        $req_lang = !empty($_GET['lang']) ? $_GET['lang'] : (!empty($_POST['lang']) ? $_POST['lang'] : '');
        if (!empty($req_lang)) {
            $loc = $reg->locale_for_mark($req_lang) ?: $reg->normalize_locale($req_lang);
            if ($reg->is_supported_locale($loc)) {
                $this->locale = $loc;
                $this->source = 'query';
                $this->persist_cookie();
                return;
            }
        }

        // 1) 路径段（优先：当前请求路径）
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $loc = $reg->match_by_path_segment($uri);
        if ($loc) {
            $this->locale = $loc;
            $this->source = 'path';
            $this->persist_cookie();
            return;
        }
        // 2) 域名映射
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');
        $loc = null;
        if (!$loc) {
            $loc = $reg->match_by_host($host);
        }
        if ($loc) {
            $this->locale = $loc;
            $this->source = 'domain';
            $this->persist_cookie();
            return;
        }

        // 1.5) Referer 与 Cookie / Accept-Language（专门针对 AJAX / API 请求，如 /api/captcha/slider.php）
        // API 接口路径自身不带有语言前缀，必须通过 Referer 页面路径、Cookie 或 Header 解析客户端语言
        $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || $is_xhr;
        $req_path = parse_url($uri, PHP_URL_PATH) ?: '';
        $is_api = ($req_path !== '' && (strpos($req_path, '/api/') !== false || strpos($req_path, '/ajax/') !== false));

        if ($is_ajax || $is_api) {
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $referer_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: '';
                if ($referer_path !== '') {
                    $loc = $reg->match_by_path_segment($referer_path);
                    if ($loc) {
                        $this->locale = $loc;
                        $this->source = 'referer';
                        return;
                    }
                }
            }

            // AJAX/API 请求在 Referer 未指明语言前缀时，尝试使用 Cookie 语言
            if (!empty($_COOKIE['todo_locale'])) {
                $loc = $_COOKIE['todo_locale'];
                if ($reg->is_supported_locale($loc)) {
                    $this->locale = $reg->normalize_locale($loc);
                    $this->source = 'cookie';
                    return;
                }
            }

            // AJAX/API 请求后备使用 Accept-Language
            if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $loc = $reg->choose_from_accept_language($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                if ($loc) {
                    $this->locale = $loc;
                    $this->source = 'accept';
                    return;
                }
            }
        }

        // 路由模式优先：在路径前缀模式下，普通页面未匹配到路径或域名映射时，强制使用默认语言
        // 这样可确保始终启用默认语言（例如默认语言为 en，则 / 与 /en/ 一致）
        $mode = method_exists($reg, 'get_routing_mode') ? $reg->get_routing_mode() : 'path_prefix';
        if ($mode === 'path_prefix') {
            $this->locale = $reg->get_default_locale() ?: $reg->get_fallback_locale();
            $this->source = 'default';
            $this->persist_cookie();
            return;
        }

        // 3) Cookie（仅非路径前缀模式下作为后备）
        if (!empty($_COOKIE['todo_locale'])) {
            $loc = $_COOKIE['todo_locale'];
            if ($reg->is_supported_locale($loc)) {
                $this->locale = $reg->normalize_locale($loc);
                $this->source = 'cookie';
                return;
            }
        }
        // 4) Accept-Language（仅非路径前缀模式下作为后备）
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $loc = $reg->choose_from_accept_language($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if ($loc) {
                $this->locale = $loc;
                $this->source = 'accept';
                $this->persist_cookie();
                return;
            }
        }
        // 5) 默认
        $this->locale = $reg->get_default_locale() ?: $reg->get_fallback_locale();
        $this->source = 'default';
    }

    public function get_locale()
    {
        if ($this->locale) {
            return $this->locale;
        }
        return LanguageRegistry::get_instance()->get_default_locale();
    }

    public function set_locale($locale)
    {
        $reg = LanguageRegistry::get_instance();
        if ($reg->is_supported_locale($locale)) {
            $this->locale = $reg->normalize_locale($locale);
            $this->source = 'manual';
            $this->persist_cookie();
            // 清除翻译缓存，确保后续翻译使用新语言
            if (class_exists('CMS_Translation')) {
                CMS_Translation::clear_cache();
            }
            // 额外清理数据库语言缓存，仅清理当前语言，避免跨语言污染
            if (function_exists('todo_language_manager')) {
                try {
                    $mgr = todo_language_manager();
                    if (is_object($mgr) && method_exists($mgr, 'clear_translation_cache')) {
                        $mgr->clear_translation_cache(null, $this->locale);
                    }
                } catch (\Throwable $e) {
                    // 安全吞掉异常，不影响语言切换流程
                }
            }
        }
    }

    public function get_source()
    {
        return $this->source;
    }

    private function persist_cookie()
    {
        if (!headers_sent() && $this->locale) {
            $path = '';
            if (isset($_SERVER['REQUEST_URI'])) {
                $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $path = is_string($path) ? $path : '';
            }
            $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || $is_xhr;
            $is_api = ($path !== '' && (strpos($path, '/api/') !== false || strpos($path, '/ajax/') !== false));
            if ($is_ajax || $is_api) {
                return;
            }

            // Exclude static assets (e.g. 404s for images shouldn't reset locale)
            if ($path !== '') {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'map', 'txt', 'xml', 'json'])) {
                    return;
                }
            }

            // Exclude fetch/API requests that explicitly accept JSON but not HTML
            if (!empty($_SERVER['HTTP_ACCEPT'])) {
                $accept = strtolower($_SERVER['HTTP_ACCEPT']);
                if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
                    return;
                }
            }

            if ((!defined('IS_ADMIN') || !IS_ADMIN) && function_exists('todo_get_language_default_site_id')) {
                $default_site_id = (int) todo_get_language_default_site_id($this->locale);
                if ($default_site_id > 0) {
                    $site_ok = false;
                    if (!empty($_COOKIE['todo_site_id']) && is_numeric($_COOKIE['todo_site_id'])) {
                        $candidate = (int) $_COOKIE['todo_site_id'];
                        if ($candidate > 0) {
                            $site_ok = true;
                            if (function_exists('todo_get_language_sites')) {
                                $lang_sites = todo_get_language_sites($this->locale);
                                if (!empty($lang_sites)) {
                                    $site_ok = false;
                                    foreach ($lang_sites as $s) {
                                        $sid = isset($s['id']) ? (int) $s['id'] : 0;
                                        if ($sid === $candidate) {
                                            $site_ok = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if (!$site_ok) {
                        $GLOBALS['todo_current_site_id'] = $default_site_id;
                        $_COOKIE['todo_site_id'] = (string) $default_site_id;
                        @setcookie('todo_site_id', (string) $default_site_id, time() + 15552000, '/');
                    }
                }
            }

            $expire = time() + 15552000; // 180 days
            @setcookie('todo_locale', $this->locale, $expire, '/');
        }
    }
}

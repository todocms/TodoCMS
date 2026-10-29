<?php
/**
 * API类
 *
 * 处理API请求的路由、认证和响应
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

class Todo_API {
    /**
     * API版本
     *
     * @var string
     */
    private $version = 'v1';
    
    /**
     * API命名空间
     *
     * @var string
     */
    private $namespace = 'api';
    
    /**
     * 路由
     *
     * @var array
     */
    private $routes = [];
    
    /**
     * 认证方法
     *
     * @var string
     */
    private $auth_method = 'jwt'; // 'jwt', 'api_key', 'none'
    
    /**
     * 当前用户ID
     *
     * @var int
     */
    private $current_user = 0;
    private $current_key_scopes = [];
    private $current_key_name = '';
    private $last_auth_error = '';
    private $last_secret_source = '';

    private static $instance = null;
    
    /**
     * 初始化API管理器
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        // 注册API路由处理钩子
        add_action('cms_init', [$this, 'register_hooks']);
    }
    
    /**
     * 注册钩子
     */
    public function register_hooks() {
        add_action('cms_query', [$this, 'handle_api_request']);
    }
    
    /**
     * 处理API请求
     *
     * @param array $query 查询参数
     */
    public function handle_api_request($query) {
        // 检查是否是API请求
        if (!$this->is_api_request()) {
            return;
        }
        
        // 设置内容类型为JSON
        header('Content-Type: application/json');
        
        // 设置安全响应头
        $this->set_security_headers();
        
        // 验证请求方法
        if (!$this->validate_request_method()) {
            $this->send_error(405, '方法不允许');
            exit;
        }
        
        // 验证内容类型
        if (!$this->validate_content_type()) {
            $this->send_error(415, '不支持的媒体类型');
            exit;
        }

        // 解析请求路径
        $path = $this->get_api_path();

        // 验证认证（部分端点允许匿名访问）
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (!$this->is_public_request($path, $method) && !$this->authenticate()) {
            $this->send_error(401, '未授权');
            exit;
        }
        
        // 路由请求
        $this->route_request($path);
    }
    
    /**
     * 检查是否是API请求
     *
     * @return bool 是否是API请求
     */
    private function is_api_request() {
        $request_uri = $_SERVER['REQUEST_URI'];
        $versioned_prefix = '/' . $this->namespace . '/' . $this->version . '/';
        $unversioned_prefix = '/' . $this->namespace . '/';
        return strpos($request_uri, $versioned_prefix) !== false
            || strpos($request_uri, $unversioned_prefix) !== false;
    }
    
    /**
     * 获取API路径
     *
     * @return string API路径
     */
    private function get_api_path() {
        $request_uri = $_SERVER['REQUEST_URI'];
        if (($pos = strpos($request_uri, '?')) !== false) {
            $request_uri = substr($request_uri, 0, $pos);
        }
        $versioned_prefix = '/' . $this->namespace . '/' . $this->version . '/';
        $unversioned_prefix = '/' . $this->namespace . '/';

        if (strpos($request_uri, $versioned_prefix) !== false) {
            $path = substr($request_uri, strpos($request_uri, $versioned_prefix) + strlen($versioned_prefix));
        } else {
            $path = substr($request_uri, strpos($request_uri, $unversioned_prefix) + strlen($unversioned_prefix));
        }
        return trim($path, '/');
    }
    
    /**
     * 设置安全响应头
     */
    private function set_security_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    
    /**
     * 验证请求方法
     *
     * @return bool 请求方法是否有效
     */
    private function validate_request_method() {
        $allowed_methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        return in_array($_SERVER['REQUEST_METHOD'], $allowed_methods);
    }
    
    /**
     * 验证内容类型
     *
     * @return bool 内容类型是否有效
     */
    private function validate_content_type() {
        // 对于GET请求，不需要验证内容类型
        if (todo_server_string('REQUEST_METHOD') === 'GET') {
            return true;
        }
        
        // 对于其他请求，验证内容类型为application/json
        $content_type = todo_server_string('CONTENT_TYPE');
        if ($content_type === '') {
            return false;
        }
        
        return strpos($content_type, 'application/json') !== false;
    }
    
    /**
     * 认证请求
     *
     * @return bool 认证是否成功
     */
    private function authenticate() {
        // 如果不需要认证
        if ($this->auth_method === 'none') {
            return true;
        }
        
        // 获取认证头
        $headers = $this->get_request_headers();
        $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : '');
        $api_key_header = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : (isset($headers['x-api-key']) ? $headers['x-api-key'] : (isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : ''));

        // 优先检查 X-API-Key 标头（Agent 自动化客户端常用）
        if (!empty($api_key_header)) {
            return $this->validate_raw_api_key($api_key_header);
        }

        if (empty($auth_header)) {
            $this->last_auth_error = 'missing_authorization';
            return false;
        }

        // 智能兼容：若携带 ApiKey 前缀
        if (strpos($auth_header, 'ApiKey ') === 0) {
            return $this->validate_api_key($auth_header);
        }
        
        // 根据认证方法或 Bearer 前缀验证
        if (strpos($auth_header, 'Bearer ') === 0 || $this->auth_method === 'jwt') {
            $ok = $this->validate_jwt($auth_header);
            if (!$ok && isset($_SERVER['HTTP_X_DEBUG_AUTH']) && $_SERVER['HTTP_X_DEBUG_AUTH'] === '1') {
                if (function_exists('todo_log_warning')) {
                    todo_log_warning('JWT auth failed', array(
                        'reason' => $this->last_auth_error,
                        'secret_source' => $this->last_secret_source,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    ));
                } else {
                    error_log('[TodoCMS API] JWT auth failed: ' . $this->last_auth_error . ' secret_source=' . $this->last_secret_source);
                }
            }
            return $ok;
        } elseif ($this->auth_method === 'api_key') {
            return $this->validate_api_key($auth_header);
        }
        
        return false;
    }

    private function get_request_headers() {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            $headers = is_array($h) ? $h : [];
            if (!isset($headers['Authorization'])) {
                if (isset($headers['authorization'])) {
                    $headers['Authorization'] = $headers['authorization'];
                } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
                } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                } elseif (isset($_SERVER['Authorization'])) {
                    $headers['Authorization'] = $_SERVER['Authorization'];
                }
            }
            return $headers;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        if (!isset($headers['Authorization'])) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['Authorization'])) {
                $headers['Authorization'] = $_SERVER['Authorization'];
            }
        }

        return $headers;
    }

    private function is_public_request($path, $method) {
        $path = trim((string) $path, '/');
        $method = strtoupper((string) $method);

        $rules = [
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?wechat/login$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?payment/payjs/notify$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?payment/hupijiao/notify$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?payment/.*$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?payment/.*$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?products(?:/[0-9]+)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?categories(?:/[0-9]+)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?tags(?:/[0-9]+)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?posts(?:/[0-9]+)?$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?posts/[0-9]+/like$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?posts/[0-9]+/view$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?cache/async-check$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?product-services(?:/[a-z0-9_-]+)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?captcha/generate$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?captcha/verify$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?sites$#'],
            ['method' => 'POST', 'pattern' => '#^user/login-handler\.php$#'],
            ['method' => 'POST', 'pattern' => '#^user/register-handler\.php$#'],
            ['method' => 'POST', 'pattern' => '#^user/mobile-login-handler\.php$#'],
            ['method' => 'POST', 'pattern' => '#^user/forgot-password-handler\.php$#'],
            ['method' => 'POST', 'pattern' => '#^user/send-email-code\.php$#'],
            ['method' => 'GET', 'pattern' => '#^user/login-qrcode\.php$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?ajax-pager$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?sms/send(?:\.php)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?captcha/slider(?:\.php)?$#'],
            ['method' => 'POST', 'pattern' => '#^(?:v1/)?captcha/slider(?:\.php)?$#'],
            ['method' => 'POST', 'pattern' => '#^guestbook/submit(?:\.php)?$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?agent/openapi\.json$#'],
            ['method' => 'GET', 'pattern' => '#^(?:v1/)?agent/context$#'],
        ];

        foreach ($rules as $r) {
            if ($method !== $r['method']) {
                continue;
            }
            if (preg_match($r['pattern'], $path)) {
                return true;
            }
        }

        return false;
    }

    public function get_current_user_id() {
        return (int) $this->current_user;
    }
    
    /**
     * 验证JWT令牌
     *
     * @param string $auth_header 认证头
     * @return bool 验证是否成功
     */
    private function validate_jwt($auth_header) {
        // 检查Bearer前缀
        if (strpos($auth_header, 'Bearer ') !== 0) {
            $this->last_auth_error = 'invalid_bearer_prefix';
            return false;
        }
        
        // 提取令牌
        $token = trim(substr($auth_header, 7));
        
        // 验证令牌
        // 获取签名密钥：仅从数据库读取，严禁 fallback 到任何静态配置常量
        // （防止攻击者利用已知的 config.php 常量伪造 JWT）
        $secret_key = trim((string) get_option('jwt_secret_key', ''));
        if (empty($secret_key) && function_exists('todo_get_option')) {
            $secret_key = trim((string) todo_get_option('jwt_secret_key', ''));
        }
        if (empty($secret_key)) {
            $this->last_auth_error = 'missing_secret';
            $this->last_secret_source = '';
            return false;
        }
        $this->last_secret_source = 'option:jwt_secret_key';
        
        $b64url_decode = function ($input) {
            $s = str_replace(['-', '_'], ['+', '/'], (string) $input);
            $pad = strlen($s) % 4;
            if ($pad > 0) {
                $s .= str_repeat('=', 4 - $pad);
            }
            $out = base64_decode($s, true);
            return $out === false ? '' : $out;
        };

        try {
            // 解析令牌
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $this->last_auth_error = 'invalid_token_format';
                return false;
            }
            
            // 解码头部和载荷
            $header_raw = $b64url_decode($parts[0]);
            $payload_raw = $b64url_decode($parts[1]);
            if ($header_raw === '' || $payload_raw === '') {
                $this->last_auth_error = 'base64_decode_failed';
                return false;
            }
            $header = json_decode($header_raw, true);
            $payload = json_decode($payload_raw, true);
            if (!is_array($header) || !is_array($payload)) {
                $this->last_auth_error = 'json_decode_failed';
                return false;
            }
            
            // 验证算法
            if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
                $this->last_auth_error = 'unsupported_alg';
                return false;
            }
            
            // 验证签名
            $signature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret_key, true);
            $signature_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            
            if ($signature_base64 !== $parts[2]) {
                $this->last_auth_error = 'signature_mismatch';
                return false;
            }
            
            // 验证过期时间
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->last_auth_error = 'token_expired';
                return false;
            }
            
            // 存储用户信息
            $this->current_user = isset($payload['sub']) ? $payload['sub'] : 0;
            
            return true;
        } catch (Throwable $e) {
            $this->last_auth_error = 'exception';
            return false;
        }
    }
    
    /**
     * 验证API密钥
     *
     * @param string $auth_header 认证头
     * @return bool 验证是否成功
     */
     private function validate_api_key($auth_header) {
         // 检查ApiKey前缀
         if (strpos($auth_header, 'ApiKey ') !== 0) {
             return false;
         }
         
         // 提取API密钥
         $api_key = substr($auth_header, 7);
         return $this->validate_raw_api_key($api_key);
     }

    /**
     * 直接校验裸 API Key 字符串并关联用户上下文
     *
     * @param string $api_key
     * @return bool
     */
    private function validate_raw_api_key($api_key) {
        $api_key = trim((string) $api_key);
        if ($api_key === '') {
            $this->last_auth_error = 'empty_api_key';
            return false;
        }

        $valid_keys = get_option('api_keys', []);
        if (empty($valid_keys) || !is_array($valid_keys)) {
            $this->last_auth_error = 'no_valid_api_keys_configured';
            return false;
        }

        foreach ($valid_keys as $key_identifier => $item) {
            // 支持两种存储形态：
            // 形态 1 (旧版兼容): [ user_id => "api_key_string" ]
            // 形态 2 (新版增强): [ key_id => [ 'key' => "...", 'name' => "...", 'user_id' => 1, 'scopes' => [...] ] ]
            if (is_string($item)) {
                if (hash_equals($item, $api_key)) {
                    $this->current_user = intval($key_identifier) > 0 ? intval($key_identifier) : 1;
                    $this->current_key_name = '默认遗留密钥';
                    // 默认安全基线：旧版密钥仅默认开放基础读写与评论审核，默认不开启 posts:delete 与 theme:manage
                    $this->current_key_scopes = ['read', 'publish', 'products:manage', 'comments:audit'];
                    return true;
                }
            } elseif (is_array($item) && !empty($item['key'])) {
                if (hash_equals((string)$item['key'], $api_key)) {
                    $this->current_user = !empty($item['user_id']) ? intval($item['user_id']) : 1;
                    $this->current_key_name = !empty($item['name']) ? (string)$item['name'] : 'API Key';
                    $this->current_key_scopes = isset($item['scopes']) && is_array($item['scopes']) ? $item['scopes'] : ['read', 'publish'];
                    return true;
                }
            }
        }

        $this->last_auth_error = 'invalid_api_key';
        return false;
    }

    /**
     * 校验当前认证上下文是否具备特定权限 (Scope)
     *
     * @param string $scope 待检查的权限 (如 posts:delete, theme:manage, system:manage, comments:audit)
     * @return bool
     */
    public function current_key_can($scope) {
        // 若为原生 Session / JWT 管理员登录且未受 Key 限制，放行
        if (!empty($this->current_user) && empty($this->current_key_scopes)) {
            return true;
        }

        // 超级通配符授权
        if (in_array('*', $this->current_key_scopes, true) || in_array('admin', $this->current_key_scopes, true)) {
            return true;
        }

        return in_array($scope, $this->current_key_scopes, true);
    }

    /**
     * 获取当前 API Key 所持有的全部 Scope 权限列表
     *
     * @return array
     */
    public function get_current_key_scopes() {
        return $this->current_key_scopes;
    }
    
    /**
     * 路由请求
     *
     * @param string $path 请求路径
     */
    private function route_request($path) {
        // 使用完整路径进行匹配，支持正则路由
        $path = trim($path, '/');
        
        // 获取请求方法
        $method = strtolower(todo_server_string('REQUEST_METHOD'));
        
        // 获取请求参数
        $params = [];
        if ($method === 'get') {
            $params = $_GET;
        } else {
            $input = file_get_contents('php://input');
            $params = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->send_error(400, '无效的JSON');
                exit;
            }
        }
        
        // 查找匹配的路由（按注册顺序匹配第一个命中项）
        $handler = null;
        $matchedPattern = null;
        $matches = [];
        
        foreach ($this->routes as $pattern => $methods) {
            if (!isset($methods[$method])) {
                continue;
            }
            $regex = '#^' . $pattern . '$#';
            if (preg_match($regex, $path, $m)) {
                $handler = $methods[$method];
                $matchedPattern = $pattern;
                $matches = $m;
                break;
            }
        }
        
        if (!$handler) {
            $this->send_error(404, '端点不存在');
            exit;
        }
        
        // 注入路径捕获组到参数（第一个捕获组映射为 id，后续为 param2/param3...）
        if (!empty($matches)) {
            if (!isset($params['id']) && isset($matches[1])) {
                $params['id'] = $matches[1];
            }
            if (count($matches) > 2) {
                for ($i = 2; $i < count($matches); $i++) {
                    $params['param' . ($i - 1)] = $matches[$i];
                }
            }
        }
        
        // 调用处理函数
        try {
            $response = call_user_func($handler, $params);
            $this->send_response($response);
        } catch (Throwable $e) {
            $this->send_error(500, $e->getMessage());
        }
    }
    
    /**
     * 注册路由
     *
     * @param string $endpoint 端点
     * @param string $method 请求方法
     * @param callable $handler 处理函数
     */
    public function register_route($endpoint, $method, $handler) {
        $method = strtolower($method);
        
        if (!isset($this->routes[$endpoint])) {
            $this->routes[$endpoint] = [];
        }
        
        $this->routes[$endpoint][$method] = $handler;
    }
    
    /**
     * 设置认证方法
     *
     * @param string $method 认证方法
     */
    public function set_auth_method($method) {
        $allowed_methods = ['jwt', 'api_key', 'none'];
        
        if (in_array($method, $allowed_methods)) {
            $this->auth_method = $method;
        }
    }
    
    /**
     * 发送响应
     *
     * @param mixed $data 响应数据
     */
    public function send_response($data) {
        // 确保所有输出缓冲被清空
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $json = json_encode([
            'status' => 'success',
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            // 回退方案：清理数据后重试
            $cleaned = $this->clean_for_json($data);
            $json = json_encode([
                'status' => 'success',
                'data' => $cleaned
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        echo $json;
        exit;
    }

    /**
     * 清理数据确保可 JSON 编码
     */
    private function clean_for_json($data) {
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->clean_for_json($value);
            }
            return $result;
        }
        return $data;
    }
    
    /**
     * 处理API请求
     *
     * @param string $path 请求路径
     * @param string $method 请求方法
     * @param array $params 请求参数
     */
    public function handle_request($path, $method, $params) {
        // 使用完整路径进行匹配，支持正则路由
        $path = ltrim($path, '/');

        // 剥离版本前缀（如 v1/），使注册路由时不需携带版本号
        if (preg_match('#^v[0-9]+/#', $path)) {
            $path = substr($path, strpos($path, '/') + 1);
        }

        if (empty($path)) {
            $this->send_response([
                'name' => 'TodoCMS API',
                'version' => $this->version,
                'namespace' => $this->namespace
            ]);
            return;
        }

        $method_upper = strtoupper((string) $method);
        if (!$this->is_public_request($path, $method_upper) && !$this->authenticate()) {
            $this->send_error(401, '未登录');
            return;
        }

        $method = strtolower((string) $method);
        
        // 查找匹配的路由
        $handler = null;
        $matchedPattern = null;
        $matches = [];
        foreach ($this->routes as $pattern => $methods) {
            if (!isset($methods[$method])) {
                continue;
            }
            $regex = '#^' . $pattern . '$#';
            if (preg_match($regex, $path, $m)) {
                $handler = $methods[$method];
                $matchedPattern = $pattern;
                $matches = $m;
                break;
            }
        }
        
        if (!$handler) {
            $standalone_path = str_replace(['../', '..\\'], '', $path);
            if ($standalone_path !== '') {
                $target_script = ABSPATH . 'api/' . $standalone_path;
                if (!is_file($target_script) && is_file($target_script . '.php')) {
                    $target_script .= '.php';
                }
                $api_root = realpath(ABSPATH . 'api');
                $real_target = realpath($target_script);
                if ($real_target && $api_root && strpos($real_target, $api_root) === 0 && is_file($real_target)) {
                    $target_basename = basename($real_target);
                    if ($target_basename !== 'index.php' && $target_basename !== 'router.php' && $target_basename !== 'helpers.php') {
                        require $real_target;
                        exit;
                    }
                }
            }

            $this->send_error(404, '端点不存在: ' . $path);
            return;
        }
        
        // 注入路径参数
        if (!empty($matches)) {
            if (!isset($params['id']) && isset($matches[1])) {
                $params['id'] = $matches[1];
            }
            if (count($matches) > 2) {
                for ($i = 2; $i < count($matches); $i++) {
                    $params['param' . ($i - 1)] = $matches[$i];
                }
            }
        }
        
        // 调用处理函数
        try {
            if (is_callable($handler)) {
                $response = call_user_func($handler, $params);
                $this->send_response($response);
            } else {
                $this->send_error(500, '处理函数不可调用');
            }
        } catch (Throwable $e) {
            $this->send_error(500, $e->getMessage());
        }
    }
    
    /**
     * 发送错误
     *
     * @param int $code HTTP状态码
     * @param string $message 错误消息
     */
    public function send_error($code, $message, $data = null) {
        // 确保所有输出缓冲被清空
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($code);
        $res = [
            'status' => 'error',
            'code' => $code,
            'message' => $message
        ];
        if (is_array($data)) {
            $res = array_merge($res, $data);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    
    /**
     * 获取所有注册的路由
     *
     * @return array 路由数组
     */
    public function get_routes() {
        return $this->routes;
    }

    /**
     * 获取实例（单例模式）
     *
     * @return Todo_API API实例
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::init();
        }
        return self::$instance;
    }
}

/**
 * 获取API实例
 *
 * @return Todo_API API实例
 */
function cms_api() {
    return Todo_API::get_instance();
}

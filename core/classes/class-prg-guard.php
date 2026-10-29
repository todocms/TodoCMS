<?php
/**
 * Todo_PRG_Guard — 防重复提交幂等守卫
 *
 * 实现服务端 PRG（Post/Redirect/Get）防重检测：
 *   - 客户端在每次写操作请求中携带唯一的 X-Todo-Submit-Key（或 _submit_key 字段）
 *   - 服务端在 Session 中维护已处理的提交键，TTL 内相同键的重复请求直接返回上一次结果
 *   - 写操作完成后自动将结果缓存到 Session，供重试请求复用
 *
 * 使用方式：
 *   // 在 AJAX 处理器顶部调用（读操作无需调用）
 *   $guard = Todo_PRG_Guard::check('my_action');
 *   if ($guard->is_duplicate()) {
 *       // 直接返回上次结果，不重复执行业务逻辑
 *       todo_send_json($guard->get_cached_result());
 *   }
 *   // ... 执行实际业务 ...
 *   $result = ['success' => true, 'data' => []];
 *   $guard->cache_result($result);
 *   todo_send_json($result);
 *
 * @package  TodoCMS
 * @version  1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

class Todo_PRG_Guard
{
    /** Session 命名空间 */
    const SESSION_KEY = '_todo_prg_keys';

    /** 默认 TTL（秒） — 同一提交键在此时间内有效 */
    const DEFAULT_TTL = 30;

    /** 单个 action 最多保留的历史键数，防止 Session 无限膨胀 */
    const MAX_KEYS_PER_ACTION = 100;

    /** 当前请求激活的守卫（供 todo_send_json_success 等响应函数回写结果） */
    private static $active = null;

    /** 本次请求提取到的提交键 */
    private $submit_key = '';

    /** 本次请求的 action 标识 */
    private $action = '';

    /** TTL（秒） */
    private $ttl = self::DEFAULT_TTL;

    /** 是否为重复提交 */
    private $is_duplicate = false;

    /** 缓存的上次响应结果 */
    private $cached_result = null;

    // ---------------------------------------------------------------
    // 工厂方法
    // ---------------------------------------------------------------

    /**
     * 检查当前请求是否为重复提交（推荐入口）
     *
     * @param  string $action  业务动作名（如 'save_post'）
     * @param  int    $ttl     幂等窗口（秒），0 = 使用默认值
     * @return static
     */
    public static function check($action = '', $ttl = 0)
    {
        $instance = new static($action, $ttl);
        $instance->_detect();
        return $instance;
    }

    /** 设置当前请求激活的守卫 */
    public static function set_active($guard)
    {
        static::$active = ($guard instanceof self) ? $guard : null;
    }

    /**
     * 响应函数在输出成功结果前调用，缓存本次响应（无激活守卫时无副作用）。
     * 用于 todo_send_json_success 这类会先清空输出缓冲再输出的函数。
     */
    public static function capture($payload)
    {
        if (static::$active instanceof self && is_array($payload) && !empty($payload['success'])) {
            static::$active->cache_result($payload);
        }
    }

    /**
     * 一行接入的端点守卫（仅 POST 且携带提交键时生效）
     * 重复提交 → 直接输出缓存 JSON 并 exit；
     * 首次提交 → 注册输出缓冲，在响应 success=true 时缓存结果。
     */
    public static function guard_endpoint($action, $ttl = 30)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || static::extract_submit_key() === '') {
            return;
        }
        $guard = static::check($action, $ttl);
        if ($guard->is_duplicate()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('X-Todo-PRG: hit');
            }
            $cached = $guard->get_cached_result();
            echo json_encode($cached !== null ? $cached : ['success' => true, 'prg' => 'cached'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        static::$active = $guard;
        ob_start(function ($buf, $phase) use ($guard) {
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) && is_string($buf)) {
                $d = json_decode(trim($buf), true);
                if (is_array($d) && !empty($d['success'])) {
                    $guard->cache_result($d);
                }
            }
            return $buf;
        });
    }

    // ---------------------------------------------------------------
    // 构造
    // ---------------------------------------------------------------

    public function __construct($action = '', $ttl = 0)
    {
        $this->action = $action ? $action : 'default';
        $this->ttl    = $ttl > 0 ? $ttl : static::DEFAULT_TTL;
    }

    // ---------------------------------------------------------------
    // 公开 API
    // ---------------------------------------------------------------

    /** 当前请求是否为重复提交 */
    public function is_duplicate()
    {
        return $this->is_duplicate;
    }

    /** 获取缓存的上次响应结果（仅 is_duplicate() === true 时有效） */
    public function get_cached_result()
    {
        return $this->cached_result;
    }

    /**
     * 缓存本次处理结果（在业务处理完成后调用）
     *
     * @param mixed $result 可序列化的响应数据
     */
    public function cache_result($result)
    {
        if (empty($this->submit_key)) {
            return;
        }
        $this->_ensure_session();
        $_SESSION[static::SESSION_KEY][$this->action][$this->submit_key] = [
            'result' => $result,
            'ts'     => time(),
        ];
        // 裁剪历史，防止 Session 无限增长
        $this->_prune($this->action);
    }

    /**
     * 主动使当前提交键失效（适用于操作失败或需要允许重试的场景）
     */
    public function invalidate()
    {
        if (empty($this->submit_key)) {
            return;
        }
        $this->_ensure_session();
        unset($_SESSION[static::SESSION_KEY][$this->action][$this->submit_key]);
    }

    /** 获取当前提交键（用于调试） */
    public function get_submit_key()
    {
        return $this->submit_key;
    }

    // ---------------------------------------------------------------
    // 静态工具
    // ---------------------------------------------------------------

    /**
     * 从当前请求中提取提交键
     * 优先级：HTTP Header > POST 字段 > GET 字段
     */
    public static function extract_submit_key()
    {
        // 1. HTTP Header（AJAX 场景首选）
        $header_key = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'x-todo-submit-key') {
                    $header_key = trim($value);
                    break;
                }
            }
        }
        if (empty($header_key)) {
            // 兼容 nginx + php-fpm 未开启 getallheaders 的环境
            if (!empty($_SERVER['HTTP_X_TODO_SUBMIT_KEY'])) {
                $header_key = trim($_SERVER['HTTP_X_TODO_SUBMIT_KEY']);
            }
        }
        if (!empty($header_key)) {
            return static::_sanitize_key($header_key);
        }

        // 2. POST 字段
        if (!empty($_POST['_submit_key'])) {
            return static::_sanitize_key($_POST['_submit_key']);
        }

        // 3. GET 字段
        if (!empty($_GET['_submit_key'])) {
            return static::_sanitize_key($_GET['_submit_key']);
        }

        return '';
    }

    /**
     * 清理过期的幂等键（可在 cron 或请求末尾调用）
     */
    public static function clean_expired()
    {
        if (!isset($_SESSION[static::SESSION_KEY]) || !is_array($_SESSION[static::SESSION_KEY])) {
            return;
        }
        $now = time();
        foreach ($_SESSION[static::SESSION_KEY] as $action => &$keys) {
            if (!is_array($keys)) {
                unset($_SESSION[static::SESSION_KEY][$action]);
                continue;
            }
            foreach ($keys as $key => $entry) {
                if (!isset($entry['ts']) || ($now - $entry['ts']) > static::DEFAULT_TTL * 4) {
                    unset($keys[$key]);
                }
            }
            if (empty($keys)) {
                unset($_SESSION[static::SESSION_KEY][$action]);
            }
        }
        unset($keys);
    }

    // ---------------------------------------------------------------
    // 内部方法
    // ---------------------------------------------------------------

    /** 执行重复检测核心逻辑 */
    private function _detect()
    {
        $this->submit_key = static::extract_submit_key();

        // 没有提交键 → 视为非幂等请求，不做检测（向后兼容）
        if (empty($this->submit_key)) {
            return;
        }

        $this->_ensure_session();

        $store = isset($_SESSION[static::SESSION_KEY][$this->action])
            ? $_SESSION[static::SESSION_KEY][$this->action]
            : [];

        if (isset($store[$this->submit_key])) {
            $entry = $store[$this->submit_key];
            $age   = time() - (isset($entry['ts']) ? $entry['ts'] : 0);

            if ($age <= $this->ttl) {
                // 在有效期内 → 重复提交
                $this->is_duplicate  = true;
                $this->cached_result = isset($entry['result']) ? $entry['result'] : null;
                return;
            }

            // 超出 TTL → 清除旧记录，允许重新提交
            unset($_SESSION[static::SESSION_KEY][$this->action][$this->submit_key]);
        }
    }

    /** 确保 Session 已启动且命名空间已初始化 */
    private function _ensure_session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!isset($_SESSION[static::SESSION_KEY]) || !is_array($_SESSION[static::SESSION_KEY])) {
            $_SESSION[static::SESSION_KEY] = [];
        }
        if (!isset($_SESSION[static::SESSION_KEY][$this->action])) {
            $_SESSION[static::SESSION_KEY][$this->action] = [];
        }
    }

    /** 裁剪单个 action 下超出上限的旧键 */
    private function _prune($action)
    {
        if (!isset($_SESSION[static::SESSION_KEY][$action])) {
            return;
        }
        $store = &$_SESSION[static::SESSION_KEY][$action];
        if (count($store) <= static::MAX_KEYS_PER_ACTION) {
            return;
        }
        // 按时间升序排列，删除最旧的
        uasort($store, function ($a, $b) {
            return (isset($a['ts']) ? $a['ts'] : 0) - (isset($b['ts']) ? $b['ts'] : 0);
        });
        $excess = count($store) - static::MAX_KEYS_PER_ACTION;
        $keys   = array_keys($store);
        for ($i = 0; $i < $excess; $i++) {
            unset($store[$keys[$i]]);
        }
    }

    /** 清理提交键，只保留 hex 字符和短横线（防止注入） */
    private static function _sanitize_key($key)
    {
        // 允许 hex 字符和短横线（UUID 格式），最长 128 字符
        $key = preg_replace('/[^a-f0-9\-]/i', '', $key);
        return substr($key, 0, 128);
    }
}

<?php
/**
 * 认证相关函数
 *
 * 提供用户认证和登录相关的函数
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!function_exists('authenticate_user')) {
    /**
     * 用户认证函数
     *
     * @param string $username 用户名或邮箱
     * @param string $password 密码
     * @return int|false 成功返回用户ID，失败返回false
     */
    function authenticate_user($username, $password)
    {
        // 使用todo_authenticate函数验证用户名和密码
        $user = todo_authenticate($username, $password);

        // 如果返回错误对象，则认证失败
        if (is_todo_error($user)) {
            return false;
        }

        if (is_object($user) && method_exists($user, 'get_id')) {
            return (int) $user->get_id();
        }
        if (is_object($user) && property_exists($user, 'ID')) {
            return (int) $user->ID;
        }
        if (is_object($user) && property_exists($user, 'id')) {
            return (int) $user->id;
        }
        if (is_array($user) && isset($user['ID'])) {
            return (int) $user['ID'];
        }
        if (is_array($user) && isset($user['id'])) {
            return (int) $user['id'];
        }

        return false;
    }
}

if (!function_exists('set_auth_cookie')) {
    /**
     * 设置认证Cookie
     *
     * @param int $user_id 用户ID
     * @param bool $remember 是否记住登录状态
     * @return bool 是否成功设置
     */
    function set_auth_cookie($user_id, $remember = false)
    {
        // 注意：此函数已废弃，会话管理现在由 Todo_Session_Manager 处理
        // 保留此函数仅用于兼容性，不再设置会话变量

        // —— 新增：将当前登录会话写入 user_sessions 表，避免会话校验认为无效 ——
        // 这与 Todo_User_Manager::set_user_session 的行为保持一致，确保后台伪装入口能正确识别登录状态
        try {
            global $tododb;
            $type = (defined('IS_ADMIN') && IS_ADMIN) ? 'admin' : 'frontend';
            $session_id = session_id();
            if ($session_id) {
                $session_id = $type . ':' . $session_id;
            }
            $ip_address = function_exists('todo_get_client_ip') ? todo_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $expires_at = date('Y-m-d H:i:s', time() + (int) ini_get('session.gc_maxlifetime'));

            if ($tododb && $session_id) {
                // 先删除相同 session_id 的旧记录，避免重复
                $tododb->delete(TABLE_PREFIX . 'user_sessions', array('session_id' => $session_id));

                // 写入新的会话记录
                $tododb->insert(TABLE_PREFIX . 'user_sessions', array(
                    'user_id' => $user_id,
                    'session_id' => $session_id,
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent,
                    'expires_at' => $expires_at,
                    'created_at' => date('Y-m-d H:i:s')
                ));
            }
        } catch (Exception $e) {
            // 保守处理：记录错误但不影响登录流程
            error_log('set_auth_cookie: failed to write user_sessions - ' . $e->getMessage());
        }

        // 如果记住登录，设置cookie
        if ($remember) {
            $expiration = time() + 14 * DAY_IN_SECONDS;
            $token = generate_auth_token($user_id);

            setcookie('cms_auth', $user_id . '|' . $token, $expiration, '/', '', is_ssl(), true);
        }

        return true;
    }
}

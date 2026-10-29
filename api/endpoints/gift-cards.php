<?php
/**
 * 礼品卡 API 端点
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

/**
 * 激活礼品卡
 */
function api_activate_gift_card($params) {
    global $tododb;
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        send_api_error(401, '未登录');
    }
    
    $code = isset($params['code']) ? strtoupper(trim((string)$params['code'])) : '';
    if (empty($code)) {
        send_api_error(400, '请输入礼品卡卡号');
    }
    
    $gift_cards_table = $tododb->prefix . 'gift_cards';
    $balance_table = $tododb->prefix . 'member_balance';
    
    // 开启事务
    $tododb->query('START TRANSACTION');
    
    try {
        $pin = isset($params['pin']) ? strtoupper(trim((string)$params['pin'])) : '';
        
        // 查找并锁定礼品卡记录，防止并发激活
        $card = $tododb->get_row("SELECT * FROM $gift_cards_table WHERE code = ? FOR UPDATE", [$code], 'ARRAY_A');
        
        if (!$card) {
            $tododb->query('ROLLBACK');
            send_api_error(404, '无效的礼品卡卡号');
        }

        // 校验 PIN (如果卡记录有 PIN)
        if (!empty($card['pin']) && $card['pin'] !== $pin) {
            $tododb->query('ROLLBACK');
            send_api_error(400, '激活码(PIN)错误');
        }
        
        if ($card['status'] != 0) {
            $tododb->query('ROLLBACK');
            $status_text = $card['status'] == 1 ? '已被使用' : '已作废';
            send_api_error(400, "该礼品卡{$status_text}");
        }

        // 校验有效期
        if (!empty($card['expiry_at']) && strtotime($card['expiry_at']) < time()) {
            $tododb->query('ROLLBACK');
            send_api_error(400, '该礼品卡已过期');
        }
        
        $amount = floatval($card['amount']);
        if ($amount <= 0) {
            $tododb->query('ROLLBACK');
            send_api_error(500, '礼品卡面额错误');
        }
        
        // 1. 标记礼品卡为已使用
        $update_card = $tododb->update($gift_cards_table, [
            'status' => 1,
            'activated_by' => $user_id,
            'activated_at' => current_time('mysql')
        ], ['id' => $card['id']]);
        
        if (!$update_card) {
            throw new Exception('更新礼品卡状态失败');
        }
        
        // 2. 增加用户余额
        // 注意：todo_get_user_balance 已经在 balance.php 中定义，如果 API 环境下不可用，可能需要包含它
        // 但通常 API 会加载核心核心文件。
        $current_balance = todo_get_user_balance($user_id);
        $balance_after = $current_balance + $amount;
        
        $insert_balance = $tododb->insert($balance_table, [
            'user_id' => $user_id,
            'amount' => $amount,
            'balance_after' => $balance_after,
            'type' => 'recharge',
            'description' => '礼品卡激活充值: ' . $code,
            'created_at' => current_time('mysql')
        ]);
        
        if (!$insert_balance) {
            throw new Exception('写入余额日志失败');
        }
        
        // 3. 更新用户总余额缓存/字段
        if (function_exists('todo_update_user_total_balance')) {
            todo_update_user_total_balance($user_id);
        }
        
        $tododb->query('COMMIT');
        
        return [
            'success' => true,
            'amount' => $amount,
            'balance' => todo_get_user_balance($user_id),
            'message' => '激活成功'
        ];
        
    } catch (Exception $e) {
        $tododb->query('ROLLBACK');
        send_api_error(500, '激活失败: ' . $e->getMessage());
    }
}

register_api_route('me/gift-cards/activate', 'POST', 'api_activate_gift_card');

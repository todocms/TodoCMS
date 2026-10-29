<?php
/**
 * Points Manager Class
 *
 * Handles user points, logs, and business logic.
 *
 * @package TodoCMS
 * @subpackage Core\Classes
 */

class Points_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get user current points balance
     */
    public function get_balance($user_id) {
        return (float) get_user_meta($user_id, '_user_points', true) ?: 0;
    }

    /**
     * Add points to a user
     */
    public function add_points($user_id, $amount, $action, $description = '') {
        global $tododb;
        
        $current_balance = $this->get_balance($user_id);
        $new_balance = $current_balance + $amount;
        
        // Update user meta
        update_user_meta($user_id, '_user_points', $new_balance);
        
        // Log the transaction
        return $this->log_transaction($user_id, $amount, $new_balance, 'earn', $action, $description);
    }

    /**
     * Deduct points from a user
     */
    public function deduct_points($user_id, $amount, $action, $description = '') {
        global $tododb;
        
        $amount = (float) $amount;
        if ($amount <= 0) {
            return false;
        }
        
        $current_balance = $this->get_balance($user_id);
        $deduct_amount = ($current_balance > 0) ? min($current_balance, $amount) : $amount;
        $new_balance = max(0, $current_balance - $amount);
        
        // Update user meta
        update_user_meta($user_id, '_user_points', $new_balance);
        
        // Log the transaction
        $log_amount = $deduct_amount > 0 ? -$deduct_amount : -$amount;
        return $this->log_transaction($user_id, $log_amount, $new_balance, 'spend', $action, $description);
    }

    /**
     * Log a points transaction
     */
    private function log_transaction($user_id, $amount, $balance_after, $type, $action, $description) {
        global $tododb;
        
        $table_name = TABLE_PREFIX . 'user_points_log';
        return $tododb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'amount' => $amount,
                'balance_after' => $balance_after,
                'type' => $type,
                'action' => $action,
                'description' => $description,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%f', '%f', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Get points history for a user
     */
    public function get_history($user_id, $limit = 20, $offset = 0) {
        global $tododb;
        
        $table_name = TABLE_PREFIX . 'user_points_log';
        $sql = "SELECT * FROM $table_name WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        return $tododb->get_results($sql, [$user_id, $limit, $offset], 'ARRAY_A');
    }

    /**
     * Check if a user can check in today
     */
    public function can_check_in($user_id) {
        $last_checkin = get_user_meta($user_id, '_last_checkin_date', true);
        $today = date('Y-m-d');
        return $last_checkin !== $today;
    }

    /**
     * Handle Daily Check-in
     */
    public function check_in($user_id) {
        if (!$this->can_check_in($user_id)) {
            return ['status' => 'error', 'message' => '今日已签到'];
        }
        
        $points_per_checkin = get_option('points_checkin_reward', 10);
        
        update_user_meta($user_id, '_last_checkin_date', date('Y-m-d'));
        $this->add_points($user_id, $points_per_checkin, 'checkin', '每日签到奖励');
        
        return [
            'status' => 'success', 
            'message' => '签到成功', 
            'reward' => $points_per_checkin,
            'balance' => $this->get_balance($user_id)
        ];
    }
}

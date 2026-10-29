<?php
/**
 * Todo_Update_Automation
 * 
 * Handles silent background updates during specified nighttime windows.
 * Triggered by frontend or background traffic (like a cron task).
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

class Todo_Update_Automation {

    /**
     * Initialize the automation hooks
     */
    public static function init() {
        // We hook into bootstrap loaded to ensure core is initialized
        if (function_exists('add_action')) {
            add_action('todo_bootstrap_loaded', [self::class, 'maybe_auto_update'], 100);
        }
    }

    /**
     * Check if an auto-update should be performed and execute if necessary
     */
    public static function maybe_auto_update() {
        // 1. Check if feature is enabled
        if (!function_exists('todo_get_option')) return;
        if (!todo_get_option('todo_auto_update_enabled')) return;

        // 2. Determine the update window from the last server check
        $windowStr = '02:00-05:00'; // Default fallback
        $lastCheckFile = ABSPATH . '/content/cache/updates/last_check.json';
        if (file_exists($lastCheckFile)) {
            $lastData = json_decode(@file_get_contents($lastCheckFile), true);
            if (!empty($lastData['auto_update_window'])) {
                $windowStr = $lastData['auto_update_window'];
            }
        }

        if (!self::is_in_window($windowStr)) {
            return;
        }

        // 3. Execution Lock/Throttle
        // Use a persistent option to ensure we only check once every 6 hours automatically
        $lastCheck = (int) todo_get_option('todo_last_auto_update_check', 0);
        if (time() - $lastCheck < 21600) { // 6 hours
            return;
        }

        // Update last check time immediately to prevent concurrent triggers
        todo_update_option('todo_last_auto_update_check', time());

        self::execute();
    }

    /**
     * Core execution logic
     */
    private static function execute() {
        if (!class_exists('Todo_Update_Manager')) {
            require_once ABSPATH . '/core/classes/class-update-manager.php';
        }

        try {
            self::log("Auto-Check: Checking for available updates...");
            $update = Todo_Update_Manager::check_updates();
            
            if (!$update || empty($update['has_update'])) {
                self::log("Auto-Check: No updates found.");
                return;
            }

            $version = $update['latest_version'];
            self::log("Auto-Check: New version found: " . $version . ". Starting silent update.");

            // Request download
            $req = Todo_Update_Manager::request_download($version);
            if (!$req || empty($req['ok'])) {
                self::log("Auto-Update Error: Failed to request download token.");
                return;
            }

            // Download package
            $dl = Todo_Update_Manager::download_package($req['download_url']);
            if (!$dl || empty($dl['ok'])) {
                self::log("Auto-Update Error: Failed to download update package.");
                return;
            }

            // Backup before upgrade (Quiet)
            self::log("Auto-Update: Creating backup before installation...");
            $backup = Todo_Update_Manager::backup_before_upgrade();
            if (!$backup || empty($backup['ok'])) {
                self::log("Auto-Update Error: Backup failed, aborting update.");
                @unlink($dl['path']);
                return;
            }

            // Install update
            self::log("Auto-Update: Installing update " . $version . "...");
            $res = Todo_Update_Manager::install_update($dl['path']);
            if ($res && !empty($res['ok'])) {
                self::log("Auto-Update Success: System updated to version " . $version);
                if (function_exists('todo_maybe_run_db_updates')) {
                    self::log("Auto-Update: Running database updates...");
                    $dbu = todo_maybe_run_db_updates();
                    if (is_array($dbu) && empty($dbu['ok'])) {
                        self::log("Auto-Update Error: Database update failed.");
                        return;
                    }
                }
                if (function_exists('todo_set_cms_version')) {
                    @todo_set_cms_version($version);
                }
                if (function_exists('todo_update_option')) {
                    todo_update_option('todo_force_relogin', time());
                }
            } else {
                self::log("Auto-Update Error: Installation failed.");
            }

            // Cleanup
            if (file_exists($dl['path'])) {
                @unlink($dl['path']);
            }

        } catch (Throwable $e) {
            self::log("Auto-Update Exception: " . $e->getMessage());
        }
    }

    /**
     * Check if current time is within window string (HH:mm-HH:mm)
     */
    private static function is_in_window($windowStr) {
        if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $windowStr, $matches)) {
            return false;
        }

        $now = date('H:i');
        $start = $matches[1];
        $end = $matches[2];

        if ($start <= $end) {
            // Normal range (e.g. 02:00 to 05:00)
            return ($now >= $start && $now <= $end);
        } else {
            // Overnight range (e.g. 23:00 to 02:00)
            return ($now >= $start || $now <= $end);
        }
    }

    /**
     * Log message specifically for auto-update
     */
    private static function log($msg) {
        $logPath = ABSPATH . '/logs/auto-update.log';
        $fullMsg = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        @file_put_contents($logPath, $fullMsg, FILE_APPEND | LOCK_EX);
        
        // Also log to main update log if possible
        if (class_exists('Todo_Core_Kernel')) {
            $reflected = new ReflectionMethod('Todo_Core_Kernel', 'log');
            $reflected->setAccessible(true);
            $reflected->invoke(null, "[AUTO] " . $msg);
        }
    }
}

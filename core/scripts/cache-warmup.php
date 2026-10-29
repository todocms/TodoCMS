<?php
/**
 * TodoCMS 缓存预热脚本
 * 独立运行，避免阻塞主请求
 */

// 设置基本路径
define("TODO_ROOT", dirname(dirname(__DIR__)));

// 加载核心文件
require_once TODO_ROOT . "/core/bootstrap.php";

// 获取URL参数
$urls_param = isset($argv[1]) ? $argv[1] : "";
$urls = null;

if (!empty($urls_param)) {
    $decoded = base64_decode($urls_param);
    if ($decoded) {
        $urls = json_decode($decoded, true);
    }
}

// 清除定时发布标志
if (isset($GLOBALS["todo_scheduled_published"])) {
    unset($GLOBALS["todo_scheduled_published"]);
}

// 执行缓存预热
if (class_exists("Todo_Unified_Cache_Manager")) {
    $result = Todo_Unified_Cache_Manager::warmup_cache($urls);
    
    // 记录结果
    if (function_exists("todo_log")) {
        todo_log("info", "Background cache warmup completed", $result);
    }
} else {
    error_log("TodoCMS: Cache manager class not found in warmup script");
}

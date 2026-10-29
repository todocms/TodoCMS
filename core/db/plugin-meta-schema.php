<?php
/**
 * TodoCMS 插件元数据表结构定义
 * 
 * @package TodoCMS
 * @subpackage Database
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

/**
 * 创建插件元数据表
 * 
 * @return bool
 */
function create_plugin_meta_table()
{
    global $tododb;
    $charset_collate = $tododb->get_charset_collate();

    try {
        // 插件元数据表
        $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "plugin_meta (
            meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin_id bigint(20) unsigned NOT NULL COMMENT '插件ID',
            meta_key varchar(191) NOT NULL COMMENT '元数据键（最长191字符以兼容utf8mb4唯一索引）',
            meta_value longtext COMMENT '元数据值',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
            PRIMARY KEY (meta_id),
            KEY plugin_id (plugin_id),
            KEY meta_key (meta_key(191)),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            UNIQUE KEY plugin_meta_key (plugin_id, meta_key)
        ) $charset_collate COMMENT='插件元数据表';";

        if ($tododb->query($sql) === false) {
            error_log('创建插件元数据表失败: ' . $tododb->last_error());
            return false;
        }

        error_log('插件元数据表创建成功');
        return true;

    } catch (Exception $e) {
        error_log('创建插件元数据表时发生异常: ' . $e->getMessage());
        return false;
    }
}

// 函数定义完成，由系统安装器调用执行

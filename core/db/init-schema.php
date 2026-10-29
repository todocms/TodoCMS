<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
}

/**
 * 数据库初始化脚本
 *
 * @package TodoCMS
 * @version 1.0.0
 * 
 * 用于创建和初始化数据库表结构
 */

// 防止直接访问（允许在安装阶段绕过保护）
if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// 引入必要的文件
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/taxonomy-schema.php';
require_once __DIR__ . '/term-attributes-schema.php';
require_once __DIR__ . '/upgrade-schema.php';
require_once __DIR__ . '/../functions/system-functions-extended.php';
// 引入语言表结构定义（集中管理），在安装阶段未定义 TODO_VERSION 时允许绕过直接访问保护
if (!defined('TODO_VERSION') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    define('DISABLE_DIRECT_ACCESS_PROTECTION', true);
}
require_once __DIR__ . '/language-schema.php';
require_once __DIR__ . '/multilang-extended-schema.php';
if (file_exists(ABSPATH . 'core/classes/class-db-migrator.php')) {
    require_once ABSPATH . 'core/classes/class-db-migrator.php';
}

// 定义 update_option 函数（如果不存在）
if (!function_exists('update_option')) {
    function update_option($option, $value, $group_name = 'general') {
        return CMS_Option::update($option, $value, $group_name);
    }
}

/**
 * 检查数据库版本
 *
 * @return bool 是否需要更新
 */
function check_db_version() {
    global $tododb;
    
    // 检查是否需要更新
    return needs_db_update();
}

/**
 * 初始化数据库
 *
 * @return bool 是否成功
 */
function init_database() {
    global $tododb;
    
    // 确保没有活动的事务
    $tododb->ensure_no_transaction();
    
    // 开始事务
    if (!$tododb->begin_transaction()) {
        error_log('无法开始事务');
        return false;
    }
    
    try {
        // 创建核心表
        if (!create_core_tables()) {
            throw new Exception('创建核心表失败');
        }
        
        // 创建数据模型表
        if (!create_model_tables()) {
            throw new Exception('创建数据模型表失败');
        }
        
        // 创建商城表
        if (!create_shop_tables()) {
            throw new Exception('创建商城表失败');
        }
        
        // 创建会员系统表
        if (!create_member_tables()) {
            throw new Exception('创建会员系统表失败');
        }
        
        // 创建用户等级表
        if (function_exists('create_user_level_tables')) {
            if (!create_user_level_tables()) {
                throw new Exception('创建用户等级表失败');
            }
        }
        
        // 创建升级套餐表
        if (function_exists('create_upgrade_package_tables')) {
            if (!create_upgrade_package_tables()) {
                throw new Exception('创建升级套餐表失败');
            }
        }
        
        // 创建扩展功能表
        if (!create_extend_tables()) {
            throw new Exception('创建扩展功能表失败');
        }
        
        // 创建分类表
        if (function_exists('create_taxonomy_tables')) {
            if (!create_taxonomy_tables()) {
                throw new Exception('创建分类表失败');
            }
        }
        
        // 创建分类属性元数据表 (term_attributes)
        if (function_exists('create_term_meta_tables')) {
            if (!create_term_meta_tables()) {
                throw new Exception('创建分类属性元数据表失败');
            }
        }
        
        // 创建语言系统相关数据表（集中管理，使用 language-schema.php + dbDelta）
        try {
            todo_create_language_tables();
            // 多语言扩展（自定义字段/术语/选项）
            if (function_exists('todo_create_multilang_extra_tables')) {
                todo_create_multilang_extra_tables();
            }
        } catch (Exception $e) {
            error_log('创建语言相关数据表失败: ' . $e->getMessage());
            throw new Exception('创建语言相关数据表失败');
        }
        
        // 更新数据库版本记录
        if (class_exists('Todo_DB_Migrator')) {
            Todo_DB_Migrator::update_db_version(Todo_DB_Migrator::get_target_version());
        }
        
        // 提交事务
        if ($tododb->in_transaction()) {
            if (!$tododb->commit()) {
                throw new Exception('提交事务失败');
            }
        } else {
            error_log('警告：尝试提交不存在的事务');
        }
        
        return true;
    } catch (Exception $e) {
        // 记录错误
        error_log('初始化数据库失败: ' . $e->getMessage());
        
        // 回滚事务
        $tododb->rollback();
        
        return false;
    }
}

/**
 * 更新数据库结构
 *
 * @return bool 是否成功
 */
function update_db_structure() {
    global $tododb;
    
    // 确保没有活动的事务
    $tododb->ensure_no_transaction();
    
    // 开始事务
    if (!$tododb->begin_transaction()) {
        error_log('无法开始事务');
        return false;
    }
    
    try {
        // 检查是否需要更新
        if (!check_db_version()) {
            // 不需要更新，直接提交事务
            $tododb->commit();
            return true;
        }
        
        // 执行更新操作
        // TODO: 实现具体的更新逻辑
        
        // 更新数据库版本
        if (!update_db_version(defined('TODO_DB_VERSION') ? constant('TODO_DB_VERSION') : '1.0.0')) {
            throw new Exception('更新数据库版本失败');
        }
        
        // 提交事务
        if (!$tododb->commit()) {
            throw new Exception('提交事务失败');
        }
        
        return true;
    } catch (Exception $e) {
        // 记录错误
        error_log('更新数据库结构失败: ' . $e->getMessage());
        
        // 回滚事务
        $tododb->rollback();
        
        return false;
    }
}

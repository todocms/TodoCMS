<?php
/**
 * Todo标签库基类
 * 
 * 提供标签库的基础功能（参考ZanCms的Base.php）
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * Todo标签库基类
 */
class Todo_Template_Taglib_Todo_Base {
    
    /**
     * 请求对象（延迟加载）
     * @var object
     */
    protected static $request = null;
    
    /**
     * 根目录
     * @var string
     */
    protected static $root_dir = '';
    
    /**
     * 语言切换开关
     * @var bool
     */
    protected static $lang_switch_on = false;
    
    /**
     * 主体语言
     * @var string
     */
    protected static $main_lang = '';
    
    /**
     * 前台当前语言
     * @var string
     */
    protected static $home_lang = '';
    
    /**
     * 语言信息
     * @var array
     */
    protected static $lang_info = [];
    
    /**
     * 语言分离模式
     * @var int
     */
    protected static $language_split = 0;
    
    /**
     * 初始化（延迟初始化）
     */
    protected function _initialize() {
        // 只初始化一次
        if (self::$root_dir !== '') {
            return;
        }
        
        // 设置根目录
        self::$root_dir = defined('ABSPATH') ? ABSPATH : dirname(dirname(dirname(__DIR__))) . '/';
        
        // 多语言初始化
        if (null === self::$main_lang) {
            // 主体语言
            self::$main_lang = function_exists('get_main_lang') ? get_main_lang() : 'zh-CN';
            
            // 前台当前语言
            self::$home_lang = function_exists('get_home_lang') ? get_home_lang() : self::$main_lang;
        }
        
        $this->lang = self::$home_lang;
        
        // 语言分离模式
        if (null === self::$language_split) {
            self::$language_split = function_exists('get_option') 
                ? intval(get_option('language_split', 0)) 
                : 0;
        }
    }
    
    /**
     * 获取文章数据（批量获取，避免N+1查询）
     * 
     * @param array $aid_arr 文章ID数组
     * @return array 文章数据数组（以aid为键）
     */
    protected function get_archivesData($aid_arr) {
        if (empty($aid_arr) || !is_array($aid_arr)) {
            return [];
        }
        
        // 批量查询文章数据（优化N+1查询）
        global $tododb;
        if (!$tododb) {
            return [];
        }
        
        $aid_str = implode(',', array_map('intval', $aid_arr));
        $sql = "SELECT * FROM {$tododb->prefix}posts WHERE ID IN ({$aid_str})";
        $results = $tododb->get_results($sql, [], 'ARRAY_A');
        
        // 转换为索引数组
        $indexed = [];
        foreach ($results as $row) {
            $indexed[$row['ID']] = $row;
        }
        
        return $indexed;
    }
}


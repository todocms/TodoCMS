<?php
/**
 * 数据模型数据库表结构
 *
 * 定义数据模型相关的数据库表结构
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

/**
 * 创建数据模型相关数据库表
 *
 * @return bool 是否成功创建表
 */
function create_model_tables() {
    global $tododb;
    
    try {
        $charset_collate = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        // 数据模型表 - 定义内容类型模型（文章、产品、图集等）
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "models (
            model_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '数据模型唯一标识ID',
            model_name varchar(100) NOT NULL DEFAULT '' COMMENT '数据模型名称',
            model_key varchar(50) NOT NULL DEFAULT '' COMMENT '数据模型标识键（唯一）',
            model_type varchar(20) NOT NULL DEFAULT 'article' COMMENT '模型类型：article/product/images等',
            model_description text NOT NULL COMMENT '数据模型描述说明',
            enable_shop tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用商城功能（1是/0否）',
            is_system tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统内置模型（1是/0否）',
            model_status varchar(20) NOT NULL DEFAULT 'active' COMMENT '模型状态：active/inactive',
            list_template varchar(100) NOT NULL DEFAULT '' COMMENT '列表页模板文件名',
            view_template varchar(100) NOT NULL DEFAULT '' COMMENT '详情页模板文件名',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '模型创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '模型更新时间',
            PRIMARY KEY (model_id),
            UNIQUE KEY model_key (model_key)
        ) $charset_collate COMMENT='数据模型表';");
        
        // 模型字段表 - 存储数据模型的自定义字段定义
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "model_fields (
            field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段唯一标识ID',
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的数据模型ID',
            field_name varchar(100) NOT NULL DEFAULT '' COMMENT '字段名称/标题',
            field_key varchar(50) NOT NULL DEFAULT '' COMMENT '字段标识键（模型内唯一）',
            field_type varchar(50) NOT NULL DEFAULT 'text' COMMENT '字段类型：text/textarea/select/image等',
            field_label varchar(100) NOT NULL DEFAULT '' COMMENT '字段显示标签',
            field_description text NOT NULL COMMENT '字段描述说明',
            field_default text NOT NULL COMMENT '字段默认值',
            field_options text NOT NULL COMMENT '字段选项配置（JSON格式）',
            is_required tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否必填字段（1是/0否）',
            category_only tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否仅在指定栏目显示（1是/0否）',
            field_order int(11) NOT NULL DEFAULT 0 COMMENT '字段排序权重',
            field_status varchar(20) NOT NULL DEFAULT 'active' COMMENT '字段状态：active/trash',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段更新时间',
            PRIMARY KEY (field_id),
            UNIQUE KEY model_field_key (model_id, field_key),
            KEY model_id (model_id)
        ) $charset_collate COMMENT='数据模型字段表';");
        
        // 栏目模型关联表 - 建立栏目与数据模型的绑定关系
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "term_models (
            term_model_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '栏目模型关联唯一标识ID',
            term_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的栏目ID',
            model_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的数据模型ID',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '关联关系创建时间',
            PRIMARY KEY (term_model_id),
            UNIQUE KEY term_model (term_id, model_id),
            KEY model_id (model_id)
        ) $charset_collate COMMENT='栏目模型关联表';");
        
        // 栏目字段可见性表 - 控制模型字段在特定栏目中的显示状态
        $tododb->query("CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "term_field_visibility (
            visibility_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '可见性设置唯一标识ID',
            term_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的栏目ID',
            field_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的字段ID',
            is_visible tinyint(1) NOT NULL DEFAULT 1 COMMENT '字段是否在该栏目中可见（1可见/0隐藏）',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '可见性设置创建时间',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '可见性设置更新时间',
            PRIMARY KEY (visibility_id),
            UNIQUE KEY term_field (term_id, field_id),
            KEY field_id (field_id)
        ) $charset_collate COMMENT='栏目字段可见性表';");

        // 插入默认数据模型
        $default_models = [
            [
                'name' => '文章模型',
                'key' => 'article',
                'type' => 'article',
                'description' => '默认文章内容模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_article.html',
                'view_template' => 'view_article.html'
            ],
            [
                'name' => '产品模型',
                'key' => 'product',
                'type' => 'product',
                'description' => '产品展示和销售模型',
                'enable_shop' => 1,
                'is_system' => 1,
                'list_template' => 'lists_product.html',
                'view_template' => 'view_product.html'
            ],
            [
                'name' => '图集模型',
                'key' => 'images',
                'type' => 'images',
                'description' => '图片展示模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_images.html',
                'view_template' => 'view_images.html'
            ],
            [
                'name' => '下载模型',
                'key' => 'download',
                'type' => 'download',
                'description' => '文件下载模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_download.html',
                'view_template' => 'view_download.html'
            ],
            [
                'name' => '视频模型',
                'key' => 'media',
                'type' => 'media',
                'description' => '视频展示模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_media.html',
                'view_template' => 'view_media.html'
            ],
            [
                'name' => '单页模型',
                'key' => 'single',
                'type' => 'single',
                'description' => '单页面内容模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => '',
                'view_template' => 'view_single.html'
            ],
            [
                'name' => '专题模型',
                'key' => 'special',
                'type' => 'special',
                'description' => '专题展示模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_special.html',
                'view_template' => 'view_special.html'
            ],
            [
                'name' => '留言模型',
                'key' => 'guestbook',
                'type' => 'guestbook',
                'description' => '留言反馈模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_guestbook.html',
                'view_template' => 'view_guestbook.html'
            ],
            [
                'name' => '招聘模型',
                'key' => 'recruit',
                'type' => 'recruit',
                'description' => '招聘信息模型',
                'enable_shop' => 0,
                'is_system' => 1,
                'list_template' => 'lists_recruit.html',
                'view_template' => 'view_recruit.html'
            ]
        ];
        
        foreach ($default_models as $model) {
            $sql = "INSERT IGNORE INTO " . TABLE_PREFIX . "models 
                (model_name, model_key, model_type, model_description, enable_shop, is_system, list_template, view_template) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $tododb->query($sql, [
                $model['name'], $model['key'], $model['type'], $model['description'],
                $model['enable_shop'], $model['is_system'], $model['list_template'], $model['view_template']
            ]);
        }
        
        // 第二层：创建数据模型特色字段表
        $model_keys = ['article', 'product', 'images', 'download', 'media', 'single', 'special', 'guestbook', 'recruit'];
        
        foreach ($model_keys as $model_key) {
            $table_name = TABLE_PREFIX . "model_fields_" . $model_key;
            
            // 根据不同模型创建不同的特色字段表
            if ($model_key === 'article') {
                // 文章模型特色字段
                $tododb->query("CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段数据唯一标识ID',
                    post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的文章ID',
                    article_source varchar(255) NOT NULL DEFAULT '' COMMENT '文章来源',
                    article_author varchar(100) NOT NULL DEFAULT '' COMMENT '文章作者',
                    reading_time int(11) NOT NULL DEFAULT 0 COMMENT '预计阅读时间（分钟）',
                    is_original tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否原创：1是/0否',
                    
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段数据创建时间',
                    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段数据更新时间',
                    PRIMARY KEY (field_id),
                    UNIQUE KEY post_id (post_id)
                ) $charset_collate COMMENT='文章模型特色字段表-第二层';");
            } elseif ($model_key === 'product') {
                // 产品模型特色字段
                $tododb->query("CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段数据唯一标识ID',
                    post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的产品ID',
                    
                    -- 基础设置字段
                    marketing_feature text COMMENT '营销特写（营销说明/关键词堆积标题等）',
                    product_video text COMMENT '产品视频（媒体ID，JSON格式）',
                    product_type varchar(20) NOT NULL DEFAULT 'physical' COMMENT '产品类型：physical实物/virtual虚拟',
                    product_services text COMMENT '产品服务（JSON格式）：运费险、货到付款、闪电退款、7天无理由退货',
                    
                    -- 规格设置字段
                    spec_type varchar(20) NOT NULL DEFAULT 'single' COMMENT '规格类型：single单规格/multiple多规格',
                    
                    -- 单规格字段
                    price decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '现售价',
                    unit_qty varchar(64) NOT NULL DEFAULT '1' COMMENT '单位数量',
                    unit varchar(32) NOT NULL DEFAULT 'piece' COMMENT '单位',
                    wholesale_price decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '批发价',
                    wholesale_vip_price decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '高级批发价',
                    original_price decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '原价',
                    discount_type varchar(20) NOT NULL DEFAULT 'none' COMMENT '折扣类型：none无折扣/auto自动折扣/level指定等级',
                    discount_config text COMMENT '折扣配置（JSON格式）',
                    product_code varchar(100) NOT NULL DEFAULT '' COMMENT '商品编码',
                    stock int(11) NOT NULL DEFAULT 0 COMMENT '库存数量',
                    show_stock tinyint(1) NOT NULL DEFAULT 0 COMMENT '前端是否显示库存：1显示/0不显示',
                    virtual_sales int(11) NOT NULL DEFAULT 0 COMMENT '虚拟销量',
                    actual_sales int(11) NOT NULL DEFAULT 0 COMMENT '实际销量',
                    
                    -- 多规格字段
                    spec_config text COMMENT '规格配置（JSON格式）',
                    spec_details text COMMENT '规格明细（JSON格式）',
                    
                    -- 发售设置
                    sale_status varchar(20) NOT NULL DEFAULT 'online' COMMENT '发售状态：online立即上架/warehouse放入仓库',
                    
                    -- 商品详情字段
                    delivery_type varchar(20) NOT NULL DEFAULT 'express' COMMENT '交付物类型：expresstrue快递发货/express手动发货/download网盘下载/content文本内容',
                    delivery_express_note text COMMENT '手动发货补充说明',
                    delivery_download_url varchar(500) NOT NULL DEFAULT '' COMMENT '网盘地址',
                    delivery_download_code varchar(100) NOT NULL DEFAULT '' COMMENT '提取码',
                    delivery_download_password varchar(100) NOT NULL DEFAULT '' COMMENT '解压密码',
                    delivery_download_note text COMMENT '网盘下载补充说明',
                    delivery_content_note text COMMENT '文本内容补充说明',
                    auto_gen_license_key tinyint(1) NOT NULL DEFAULT 0 COMMENT '自动发卡生成授权码：1开启/0关闭',
                    
                    -- 扩展参数字段
                    memo_before_pay text COMMENT '支付前补充说明',
                    memo_after_pay text COMMENT '支付后补充说明',
                    memo_after_confirm text COMMENT '确认收货补充说明',
                    
                    -- 选择参数字段
                    custom_params text COMMENT '产品自定义参数（JSON格式）',
                    
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段数据创建时间',
                    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段数据更新时间',
                    PRIMARY KEY (field_id),
                    UNIQUE KEY post_id (post_id),
                    KEY idx_product_type (product_type),
                    KEY idx_spec_type (spec_type),
                    KEY idx_sale_status (sale_status),
                    KEY idx_delivery_type (delivery_type)
                ) $charset_collate COMMENT='产品模型特色字段表-第二层';");
            } elseif ($model_key === 'download') {
                // 下载模型特色字段
                $tododb->query("CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段数据唯一标识ID',
                    post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的下载内容ID',
                    
                    -- 下载模型固定字段
                    download_config text COMMENT '下载配置（JSON格式）',
                    download_note text COMMENT '下载说明',
                    
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段数据创建时间',
                    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段数据更新时间',
                    PRIMARY KEY (field_id),
                    UNIQUE KEY post_id (post_id)
                ) $charset_collate COMMENT='下载模型特色字段表-第二层';");
            } else {
                // 其他模型的通用特色字段表
                $tododb->query("CREATE TABLE IF NOT EXISTS `{$table_name}` (
                    field_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '字段数据唯一标识ID',
                    post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联的内容ID',
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '字段数据创建时间',
                    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '字段数据更新时间',
                    PRIMARY KEY (field_id),
                    UNIQUE KEY post_id (post_id)
                ) $charset_collate COMMENT='{$model_key}模型特色字段表-第二层';");
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log('创建数据模型数据库表异常: ' . $e->getMessage());
        return false;
    }
}

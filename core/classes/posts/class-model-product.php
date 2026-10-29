<?php
/**
 * 产品模型类
 *
 * 处理产品类型的数据模型定义和字段管理，与商城功能绑定
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 引入datetime助手函数
require_once ABSPATH . 'core/includes/datetime-helper.php';
 
class CMS_Post_Type_Product {
    private static function normalize_decimal_money($value)
    {
        if ($value === null) {
            return '0.00';
        }
        if (is_bool($value)) {
            return $value ? '1.00' : '0.00';
        }
        if (is_int($value) || is_float($value)) {
            $n = (float) $value;
            if (!is_finite($n)) {
                return '0.00';
            }
            return number_format($n, 2, '.', '');
        }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') {
                return '0.00';
            }
            $s = preg_replace('/[^\d\.\-]/', '', $s);
            if ($s === '' || $s === '-' || $s === '.' || $s === '-.') {
                return '0.00';
            }
            $n = (float) $s;
            if (!is_finite($n)) {
                return '0.00';
            }
            return number_format($n, 2, '.', '');
        }
        return '0.00';
    }

    /**
     * 模型标识
     *
     * @var string
     */
    public static $model_key = 'product';
    
    /**
     * 模型名称
     *
     * @var string
     */
    public static $model_name = '产品模型';
    
    /**
     * 模型描述
     *
     * @var string
     */
    public static $model_description = '用于发布商品产品的数据模型，与商城管理模块捆绑';
    
    /**
     * 列表模板
     *
     * @var string
     */
    public static $list_template = 'lists_product.html';
    
    /**
     * 内容模板
     *
     * @var string
     */
    public static $view_template = 'view_product.html';
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 构造函数保留为空，所有功能通过静态方法实现
    }
    
    /**
     * 获取模型固定字段配置（存储在 todo_model_fields_* 表）
     *
     * @return array 模型固定字段配置数组
     */
    public static function get_model_fixed_fields() {
        $curr_label = function_exists('todo_get_currency_display_label')
            ? todo_get_currency_display_label()
            : (function_exists('todo_get_default_currency_config')
                ? (function() { $c = todo_get_default_currency_config(); return ($c['code'] ?? '') . ($c['symbol'] ? ' (' . $c['symbol'] . ')' : ''); })()
                : '');

        return [
            // 基础设置字段
            [
                'field_name' => '营销特写',
                'field_key' => 'marketing_feature',
                'field_type' => 'textarea',
                'field_label' => '营销特写',
                'field_description' => '产品营销说明，列表页会截取中英逗号前的字符。',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入营销特写...'
                ]),
                'is_required' => 0,
                'field_order' => 0,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '产品视频',
                'field_key' => 'product_video',
                'field_type' => 'media',
                'field_label' => '产品视频',
                'field_description' => '选择产品展示视频，支持多种视频格式',
                'field_default' => '',
                'field_options' => json_encode([
                    'media_type' => 'video',
                    'multiple' => false,
                    'accept' => 'video/*',
                    'placeholder' => '点击选择产品视频...'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '产品类型',
                'field_key' => 'product_type',
                'field_type' => 'radio',
                'field_label' => '产品类型',
                'field_description' => '实物产品：快递发货；虚拟产品：手动发货、网盘下载、文本内容',
                'field_default' => 'physical',
                'field_options' => json_encode([
                    'options' => [
                        'physical' => '实物产品',
                        'virtual' => '虚拟产品'
                    ],
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 1,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '发售状态',
                'field_key' => 'sale_status',
                'field_type' => 'radio',
                'field_label' => '发售状态',
                'field_description' => '选择商品发售状态',
                'field_default' => 'online',
                'field_options' => json_encode([
                    'options' => [
                        'online' => '立即上架',
                        'warehouse' => '放入仓库'
                    ],
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 1,
                'field_order' => 14,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],
            [
                'field_name' => '产品服务',
                'field_key' => 'product_services',
                'field_type' => 'checkbox',
                'field_label' => '产品服务',
                'field_description' => '选择提供的产品服务',
                'field_default' => '',
                'field_options' => json_encode([
                    'options' => [
                        'shipping_insurance' => '运费险',
                        'cash_on_delivery' => '货到付款',
                        'instant_refund' => '闪电退款',
                        'seven_day_return' => '7天无理由退货'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => '基础设置'
            ],

            // 商品详情字段
            [
                'field_name' => '交付物类型',
                'field_key' => 'delivery_type',
                'field_type' => 'radio',
                'field_label' => '交付物类型',
                'field_description' => '选择商品交付方式',
                'field_default' => 'express',
                'field_options' => json_encode([
                    'options' => [
                        'expresstrue' => '快递发货',
                        'express' => '手动发货',
                        'download' => '网盘下载',
                        'content' => '文本内容'
                    ],
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 1,
                'field_order' => 16,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '手动发货说明',
                'field_key' => 'delivery_express_note',
                'field_type' => 'textarea',
                'field_label' => '手动发货说明',
                'field_description' => '手动发货的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入快递发货补充说明...',
                    'layout' => ['full_width' => true, 'sub_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 17,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '网盘地址',
                'field_key' => 'delivery_download_url',
                'field_type' => 'text',
                'field_label' => '网盘地址',
                'field_description' => '网盘下载地址',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入网盘地址...'
                ]),
                'is_required' => 0,
                'field_order' => 18,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '提取码',
                'field_key' => 'delivery_download_code',
                'field_type' => 'text',
                'field_label' => '提取码',
                'field_description' => '网盘提取码',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入提取码...'
                ]),
                'is_required' => 0,
                'field_order' => 19,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '解压密码',
                'field_key' => 'delivery_download_password',
                'field_type' => 'text',
                'field_label' => '解压密码',
                'field_description' => '文件解压密码',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入解压密码...'
                ]),
                'is_required' => 0,
                'field_order' => 20,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '网盘下载说明',
                'field_key' => 'delivery_download_note',
                'field_type' => 'textarea',
                'field_label' => '网盘下载说明',
                'field_description' => '网盘下载的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入网盘下载补充说明...'
                ]),
                'is_required' => 0,
                'field_order' => 21,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '自动密钥',
                'field_key' => 'auto_gen_license_key',
                'field_type' => 'checkbox',
                'field_label' => '自动发卡/生成授权码',
                'field_description' => '勾选后，系统将自动生成哈希串+强密码，并插入以下“文本内容说明”中的 {license_key} 和 {license_pass} 占位符。',
                'field_default' => 0,
                'field_options' => json_encode([
                    'options' => [
                        '1' => '自动发卡/生成授权码'
                    ]
                ]),
                'is_required' => 0,
                'field_order' => 22,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            [
                'field_name' => '文本内容说明',
                'field_key' => 'delivery_content_note',
                'field_type' => 'textarea',
                'field_label' => '文本内容说明',
                'field_description' => '文本内容交付的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入文本内容补充说明...',
                    'layout' => ['full_width' => true, 'sub_width' => true]
                ]),
                'field_order' => 23,
                'is_fixed' => 1,
                'field_section' => '商品详情'
            ],
            
            // 规格设置字段
            [
                'field_name' => '规格类型',
                'field_key' => 'spec_type',
                'field_type' => 'radio',
                'field_label' => '规格类型',
                'field_description' => '选择商品规格类型',
                'field_default' => 'single',
                'field_options' => json_encode([
                    'options' => [
                        'single' => '单规格',
                        'multiple' => '多规格'
                    ],
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 1,
                'field_order' => 4,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '现售价' . todo_get_default_currency_config()['symbol'],
                'field_key' => 'price',
                'field_type' => 'number',
                'field_label' => '现售价',
                'field_description' => $curr_label . ' 现售价',
                'field_default' => '0.00',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 0.01,
                    'placeholder' => '请输入现售价...',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 1,
                'field_order' => 5,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '单位数量',
                'field_key' => 'unit_qty',
                'field_type' => 'text',
                'field_label' => '单位数量',
                'field_description' => '计量单位数量，1份可不填',
                'field_default' => '1',
                'field_options' => json_encode([
                    'placeholder' => '数量或其它(如均码)'
                ]),
                'is_required' => 0,
                'field_order' => 6,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '单位',
                'field_key' => 'unit',
                'field_type' => 'select',
                'field_label' => '单位',
                'field_description' => '计量单位选择，在前方输入数量',
                'field_default' => 'piece',
                'field_options' => json_encode([
                    'options' => [
                        'piece' => '件',
                        'item' => '个',
                        'set' => '套',
                        'pair' => '双',
                        'pack' => '包',
                        'bag' => '袋',
                        'box' => '盒',
                        'bottle' => '瓶',
                        'can' => '罐',
                        'roll' => '卷',
                        'strip' => '条',
                        'sheet' => '张',
                        'portion' => '份',
                        'bowl' => '碗',
                        'plate' => '盘',
                        'cup' => '杯',
                        'bunch' => '把',
                        'head' => '颗/头',
                        'g' => 'g克',
                        'kg' => 'kg千克',
                        'jin' => '斤',
                        'liang' => '两',
                        'ml' => 'mL毫升',
                        'l' => 'L升',
                        'other' => '其他'
                    ],
                    'quantity_units' => ['g', 'kg', 'jin', 'liang', 'ml', 'l']
                ]),
                'is_required' => 1,
                'field_order' => 7,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '批发价' . todo_get_default_currency_config()['symbol'],
                'field_key' => 'wholesale_price',
                'field_type' => 'number',
                'field_label' => '批发价',
                'field_description' => $curr_label . ' 基础批发价',
                'field_default' => '0.00',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 0.01,
                    'placeholder' => '请输入批发价...'
                ]),
                'is_required' => 0,
                'field_order' => 8,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '高级批发价' . todo_get_default_currency_config()['symbol'],
                'field_key' => 'wholesale_vip_price',
                'field_type' => 'number',
                'field_label' => '高级批发价',
                'field_description' => $curr_label . ' 高级批发价',
                'field_default' => '0.00',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 0.01,
                    'placeholder' => '请输入高级批发价...'
                ]),
                'is_required' => 0,
                'field_order' => 9,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '原价' . todo_get_default_currency_config()['symbol'],
                'field_key' => 'original_price',
                'field_type' => 'number',
                'field_label' => '原价',
                'field_description' => $curr_label . ' 商品原价',
                'field_default' => '0.00',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 0.01,
                    'placeholder' => '请输入原价...',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 10,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '折扣类型',
                'field_key' => 'discount_type',
                'field_type' => 'select',
                'field_label' => '折扣类型',
                'field_description' => '选择折扣计算方式',
                'field_default' => 'none',
                'field_options' => json_encode([
                    'options' => [
                        'none' => '无折扣',
                        'auto' => '自动折扣（根据用户等级设置自动计算价格）',
                        'level' => '指定等级（指定用户等级进行计算）'
                    ],
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 11,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '折扣配置',
                'field_key' => 'discount_config',
                'field_type' => 'textarea',
                'field_label' => '',
                'field_description' => '当折扣类型选择“指定等级”时，为每个会员等级设置固定价格',
                'field_default' => '{}',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '系统按所选折扣类型自动生成配置JSON',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 12,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '商品编码',
                'field_key' => 'product_code',
                'field_type' => 'text',
                'field_label' => '商品编码',
                'field_description' => '商品唯一编码',
                'field_default' => '',
                'field_options' => json_encode([
                    'placeholder' => '请输入商品编码...',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 13,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '商品库存',
                'field_key' => 'stock',
                'field_type' => 'number',
                'field_label' => '商品库存',
                'field_description' => '商品库存数量',
                'field_default' => '0',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 1,
                    'placeholder' => '请输入库存数量...'
                ]),
                'is_required' => 0,
                'field_order' => 14,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '前端显示库存',
                'field_key' => 'show_stock',
                'field_type' => 'switch',
                'field_label' => '前端显示库存',
                'field_description' => '是否在前端显示库存数量',
                'field_default' => '0',
                'field_options' => json_encode([
                    'on_text' => '显示',
                    'off_text' => '不显示'
                ]),
                'is_required' => 0,
                'field_order' => 15,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '虚拟销量',
                'field_key' => 'virtual_sales',
                'field_type' => 'number',
                'field_label' => '虚拟销量',
                'field_description' => '虚拟销量数量，前台展示时为实际销量+虚拟销量',
                'field_default' => '0',
                'field_options' => json_encode([
                    'min' => 0,
                    'step' => 1,
                    'placeholder' => '请输入虚拟销量...',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 16,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '规格配置',
                'field_key' => 'spec_config',
                'field_type' => 'textarea',
                'field_label' => '规格配置',
                'field_description' => '多规格配置（JSON格式）',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 5,
                    'placeholder' => '多规格配置，JSON格式...'
                ]),
                'is_required' => 0,
                'field_order' => 17,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            [
                'field_name' => '规格明细',
                'field_key' => 'spec_details',
                'field_type' => 'textarea',
                'field_label' => '规格明细',
                'field_description' => '规格明细配置（JSON格式）',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 8,
                    'placeholder' => '规格明细配置，JSON格式...'
                ]),
                'is_required' => 0,
                'field_order' => 18,
                'is_fixed' => 1,
                'field_section' => '规格设置'
            ],
            
            // 选择参数字段
            [
                'field_name' => '产品自定义参数',
                'field_key' => 'custom_params',
                'field_type' => 'textarea',
                'field_label' => '产品自定义参数',
                'field_description' => '产品自定义参数配置（JSON格式）',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 6,
                    'placeholder' => '产品自定义参数配置，JSON格式...',
                    'layout' => ['full_width' => true]
                ]),
                'is_required' => 0,
                'field_order' => 28,
                'is_fixed' => 1,
                'field_section' => '选择参数'
            ],
            
            // 扩展参数字段
            [
                'field_name' => '支付前说明',
                'field_key' => 'before_payment_note',
                'field_type' => 'textarea',
                'field_label' => '支付前说明',
                'field_description' => '支付前的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入支付前补充说明...'
                ]),
                'is_required' => 0,
                'field_order' => 25,
                'is_fixed' => 1,
                'field_section' => '扩展参数'
            ],
            [
                'field_name' => '支付后说明',
                'field_key' => 'after_payment_note',
                'field_type' => 'textarea',
                'field_label' => '支付后说明',
                'field_description' => '支付后的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入支付后补充说明...'
                ]),
                'is_required' => 0,
                'field_order' => 26,
                'is_fixed' => 1,
                'field_section' => '扩展参数'
            ],
            [
                'field_name' => '确认收货说明',
                'field_key' => 'confirm_receipt_note',
                'field_type' => 'textarea',
                'field_label' => '确认收货说明',
                'field_description' => '确认收货的补充说明',
                'field_default' => '',
                'field_options' => json_encode([
                    'rows' => 3,
                    'placeholder' => '请输入确认收货补充说明...'
                ]),
                'is_required' => 0,
                'field_order' => 27,
                'is_fixed' => 1,
                'field_section' => '扩展参数'
            ],
        ];
    }

    /**
     * 获取基础固定字段配置（存储在 posts 表）
     *
     * @return array 基础固定字段配置数组
     */
    public static function get_base_fixed_fields() {
        return [
            [
                'field_name' => 'SEO标题',
                'field_key' => 'seo_title',
                'field_type' => 'text',
                'field_label' => 'SEO标题',
                'field_description' => '留空则使用产品标题',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 60,
                    'placeholder' => '请输入SEO标题...'
                ]),
                'is_required' => 0,
                'field_order' => 1,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            [
                'field_name' => 'SEO关键字',
                'field_key' => 'seo_keywords',
                'field_type' => 'text',
                'field_label' => 'SEO关键字',
                'field_description' => '多个关键字用英文逗号,分隔',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 200,
                    'placeholder' => '英文逗号,分隔...'
                ]),
                'is_required' => 0,
                'field_order' => 2,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ],
            [
                'field_name' => 'SEO描述',
                'field_key' => 'seo_description',
                'field_type' => 'textarea',
                'field_label' => 'SEO描述',
                'field_description' => '',
                'field_default' => '',
                'field_options' => json_encode([
                    'max_length' => 160,
                    'rows' => 3,
                    'placeholder' => '请输入SEO描述...'
                ]),
                'is_required' => 0,
                'field_order' => 3,
                'is_fixed' => 1,
                'field_section' => 'SEO设置'
            ]
        ];
    }

    /**
     * 获取所有固定字段配置（以上两者的多重拼接）
     *
     * @return array 固定字段配置数组
     */
    public static function get_fixed_fields() {
        return array_merge(self::get_model_fixed_fields(), self::get_base_fixed_fields());
    }

    /**
     * 获取默认字段配置（从数据库动态获取）
     *
     * @return array 字段配置数组
     */
    public static function get_default_fields() {
        global $tododb;
        
        // 检查数据库连接和常量是否存在
        if (!$tododb || !defined('TABLE_PREFIX')) {
            return [];
        }
        
        $table_prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : '';
        
        // 首先尝试通过model_key='product'查询
        $sql = "SELECT mf.* FROM {$table_prefix}model_fields mf 
                LEFT JOIN {$table_prefix}models m ON mf.model_id = m.model_id 
                WHERE m.model_key = 'product' 
                ORDER BY mf.field_order ASC";
        
        $fields = $tododb->get_results($sql, ARRAY_A);
        
        // 如果没有找到，尝试直接查询model_id=2（产品模型通常为ID 2）
        if (empty($fields)) {
            $sql = "SELECT * FROM {$table_prefix}model_fields WHERE model_id = 2 ORDER BY field_order ASC";
            $fields = $tododb->get_results($sql, ARRAY_A);
        }
        
        $default_fields = [];
        
        if (!empty($fields)) {
            foreach ($fields as $field) {
                // 只返回非固定字段（自定义字段）
                if (isset($field['is_fixed']) && $field['is_fixed'] == 0) {
                    $default_fields[] = [
                        'field_name' => $field['field_label'],
                        'field_key' => $field['field_key'],
                        'field_type' => $field['field_type'],
                        'field_label' => $field['field_label'],
                        'field_description' => isset($field['field_description']) ? $field['field_description'] : '',
                        'field_default' => isset($field['field_default']) ? $field['field_default'] : '',
                        'field_options' => isset($field['field_options']) ? $field['field_options'] : '',
                        'is_required' => isset($field['is_required']) ? (int)$field['is_required'] : 0,
                        'field_order' => isset($field['field_order']) ? (int)$field['field_order'] : 0,
                        'is_fixed' => 0
                    ];
                }
            }
        }
        
        return $default_fields;
    }
    
    /**
     * 获取固定字段的结构化数据（用于模板渲染）
     * 使用字段渲染器的通用分组方法
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 产品ID
     * @param bool $include_wrapper 是否包含包装器
     * @return array 结构化的字段数据
     */
    public static function get_fixed_fields_data($field_values = [], $post_id = 0, $include_wrapper = true) {
        // 获取字段配置
        $fields = self::get_fixed_fields();
        $normalized_fields = [];
        $values = [];
        
        // 转换字段格式为渲染器期望的格式
        foreach ($fields as $field) {
            $field_key = $field['field_key'];
            
            // 获取字段值
            $value = '';
            if (isset($field_values[$field_key]) && $field_values[$field_key] !== '') {
                $value = $field_values[$field_key];
            } elseif ($post_id > 0) {
                $fixed_values = self::get_fixed_field_values($post_id);
                $value = isset($fixed_values[$field_key]) ? $fixed_values[$field_key] : $field['field_default'];
            } else {
                $value = $field['field_default'];
            }
            
            $values[$field_key] = $value;
            
            // 转换字段配置格式
            $options = json_decode($field['field_options'], true) ?: [];
            if ($field_key === 'unit' && isset($values['unit_qty'])) {
                $options['unit_qty_value'] = $values['unit_qty'];
            }
            $normalized_fields[$field_key] = [
                'type' => $field['field_type'],
                'label' => $field['field_label'],
                'required' => $field['is_required'],
                'description' => $field['field_description'],
                'options' => $options,
                'default' => $field['field_default'],
                'field_section' => $field['field_section'] ?? '基础设置'
            ];
            
            // 处理特定字段类型的属性
            if ($field['field_type'] === 'textarea') {
                $normalized_fields[$field_key]['rows'] = $options['rows'] ?? 3;
            }
            
            if ($field['field_type'] === 'media') {
                $normalized_fields[$field_key]['media_type'] = $options['media_type'] ?? 'all';
                $normalized_fields[$field_key]['multiple'] = $options['multiple'] ?? false;
                if (isset($options['accept'])) {
                    $normalized_fields[$field_key]['accept'] = $options['accept'];
                }
            }
            
            if (isset($options['placeholder'])) {
                $normalized_fields[$field_key]['placeholder'] = $options['placeholder'];
            }
            
            if (isset($options['max_length'])) {
                $normalized_fields[$field_key]['max_length'] = $options['max_length'];
            }
        }
        
        // 使用渲染器的通用分组方法
        return Todo_Field_Renderer::group_fields_by_section(
            $normalized_fields,
            $values,
            ['include_wrapper' => $include_wrapper]
        );
    }
    
    /**
     * 获取固定字段HTML
     *
     * @param array $field_values 字段值数组
     * @param int $post_id 产品ID
     * @param bool $include_wrapper 是否包含包装器
     * @return string HTML字符串
     */
    public static function get_fixed_fields_html($field_values = [], $post_id = 0, $include_wrapper = true) {
        $data = self::get_fixed_fields_data($field_values, $post_id, $include_wrapper);
        return self::render_fields_html($data);
    }
    
    /**
     * 渲染字段HTML
     *
     * @param array $data 字段数据
     * @return string HTML字符串
     */
    private static function render_fields_html($data) {
        return Todo_Field_Renderer::render_sections_html($data);
    }
    
    /**
     * 渲染单个字段HTML
     *
     * @param array $field 字段数据
     * @return string HTML字符串
     */
    private static function render_single_field($field) {
        $html = '';
        
        $html .= '<div class="product-field form-group fixed-field" data-field-type="' . esc_attr($field['type']) . '">';
        $html .= '<label for="' . esc_attr($field['key']) . '">' . esc_html($field['name']);
        if ($field['required']) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';
        
        // 根据字段类型渲染不同的输入控件
        switch ($field['type']) {
                case 'textarea':
                    $html .= '<textarea class="form-control" name="' . esc_attr($field['key']) . '" id="' . esc_attr($field['key']) . '"';
                    foreach ($field['attributes'] as $attr => $value) {
                        $html .= ' ' . $attr . '="' . esc_attr($value) . '"';
                    }
                    if ($field['required']) {
                        $html .= ' required';
                    }
                    $html .= '>' . esc_textarea($field['value']) . '</textarea>';
                    break;
                    
                case 'select':
                    $html .= '<select class="form-control" name="' . esc_attr($field['key']) . '" id="' . esc_attr($field['key']) . '"';
                    if ($field['required']) {
                        $html .= ' required';
                    }
                    $html .= '>';
                    if (isset($field['options']) && is_array($field['options'])) {
                        foreach ($field['options'] as $option_value => $option_label) {
                            $selected = ($field['value'] == $option_value) ? ' selected' : '';
                            $html .= '<option value="' . esc_attr($option_value) . '"' . $selected . '>' . esc_html($option_label) . '</option>';
                        }
                    }
                    $html .= '</select>';
                    break;
                    
                case 'number':
                    $html .= '<input type="number" class="form-control" name="' . esc_attr($field['key']) . '" id="' . esc_attr($field['key']) . '" value="' . esc_attr($field['value']) . '"';
                    foreach ($field['attributes'] as $attr => $value) {
                        $html .= ' ' . $attr . '="' . esc_attr($value) . '"';
                    }
                    if ($field['required']) {
                        $html .= ' required';
                    }
                    $html .= '>';
                    break;
                    
                case 'checkbox':
                    $checked = !empty($field['value']) ? ' checked' : '';
                    $html .= '<input type="checkbox" name="' . esc_attr($field['key']) . '" id="' . esc_attr($field['key']) . '" value="1"' . $checked . '>';
                    break;
                    
                default: // text input
                    $html .= '<input type="text" class="form-control" name="' . esc_attr($field['key']) . '" id="' . esc_attr($field['key']) . '" value="' . esc_attr($field['value']) . '"';
                    foreach ($field['attributes'] as $attr => $value) {
                        $html .= ' ' . $attr . '="' . esc_attr($value) . '"';
                    }
                    if ($field['required']) {
                        $html .= ' required';
                    }
                    $html .= '>';
                    break;
            }
            
        if (!empty($field['description'])) {
            $html .= '<p class="description">' . esc_html($field['description']) . '</p>';
        }
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * 验证字段数据
     *
     * @param array $data 提交的数据
     * @return array 验证结果
     */
    public static function validate_fields($data, $fields = null) {
        $all_fields = is_array($fields) ? $fields : array_merge(self::get_fixed_fields(), self::get_default_fields());
        $errors = [];
        
        foreach ($all_fields as $field) {
            if (!is_array($field) || empty($field['field_key'])) {
                continue;
            }
            $field_key = $field['field_key'];
            $value = isset($data[$field_key]) ? $data[$field_key] : '';
            $is_required = !empty($field['is_required']);
            
            // 检查必填字段
            if ($is_required && empty($value)) {
                $field_name = isset($field['field_name']) ? $field['field_name'] : $field_key;
                $errors[$field_key] = $field_name . ' 不能为空';
                continue;
            }
            
            // 检查字段长度
            $options = json_decode($field['field_options'], true) ?: [];
            if (isset($options['max_length']) && mb_strlen($value) > $options['max_length']) {
                $errors[$field_key] = $field['field_name'] . ' 长度不能超过 ' . $options['max_length'] . ' 个字符';
            }
        }
        
        // 多规格校验：基于 spec_config/spec_details
        $spec_type = $data['spec_type'] ?? 'single';
        if ($spec_type === 'multiple') {
            $sc = $data['spec_config'] ?? '';
            $sd = $data['spec_details'] ?? '';
            if (empty($sc)) {
                $errors['spec_config'] = '规格配置不能为空';
            }
            if (empty($sd)) {
                $errors['spec_details'] = '规格明细不能为空';
            }
            $specConfig = json_decode($sc, true);
            $specDetails = json_decode($sd, true);
            if ($sc !== '' && !is_array($specConfig)) {
                $errors['spec_config'] = '规格配置格式错误（需为JSON）';
            }
            if ($sd !== '' && !is_array($specDetails)) {
                $errors['spec_details'] = '规格明细格式错误（需为JSON）';
            }
            if (is_array($specConfig)) {
                // 至少1个规格组且每组至少1个值
                if (count($specConfig) < 1) {
                    $errors['spec_config'] = '至少需要一个规格组';
                } else {
                    foreach ($specConfig as $i => $grp) {
                        $vals = isset($grp['values']) && is_array($grp['values']) ? $grp['values'] : [];
                        if (count($vals) < 1) {
                            $errors['spec_config'] = '规格组' . ($i + 1) . '需要至少一个规格值';
                            break;
                        }
                    }
                }
            }
            // 明细与组合数量一致性
            if (is_array($specConfig) && is_array($specDetails)) {
                $totalComb = 1;
                foreach ($specConfig as $grp) {
                    $totalComb *= max(1, isset($grp['values']) && is_array($grp['values']) ? count($grp['values']) : 0);
                }
                if ($totalComb <= 0) {
                    $errors['spec_details'] = '规格明细数量不正确（组合数为0）';
                } elseif (count($specDetails) !== $totalComb) {
                    $errors['spec_details'] = '规格明细数量与规格组合数不一致（应为 ' . $totalComb . ' 条）';
                }
                // 数值范围校验
                $priceMin = 0; $priceMax = 99999999; $stockMin = 0; $stockMax = 99999999; $codeMaxLen = 120;
                if (is_array($specDetails)) {
                    foreach ($specDetails as $idx => $row) {
                        $p = $row['users_price'] ?? '';
                        $wmp = $row['wholesale_price'] ?? '';
                        $wvp = $row['wholesale_vip_price'] ?? '';
                        $cp = $row['crossed_price'] ?? '';
                        $gc = $row['goods_code'] ?? '';
                        $st = $row['stock_count'] ?? '';
                        $vs = $row['spec_sales_num'] ?? '';
                        if ($p === '' || !is_numeric($p) || $p < $priceMin || $p > $priceMax) {
                            $errors['spec_details_' . $idx . '_users_price'] = '第' . ($idx + 1) . '行价格必须在 ' . $priceMin . ' - ' . $priceMax . ' 之间';
                        }
                        if ($wmp !== '' && (!is_numeric($wmp) || $wmp < $priceMin || $wmp > $priceMax)) {
                            $errors['spec_details_' . $idx . '_wholesale_price'] = '第' . ($idx + 1) . '行批发价必须在 ' . $priceMin . ' - ' . $priceMax . ' 之间';
                        }
                        if ($wvp !== '' && (!is_numeric($wvp) || $wvp < $priceMin || $wvp > $priceMax)) {
                            $errors['spec_details_' . $idx . '_wholesale_vip_price'] = '第' . ($idx + 1) . '行高级批发价必须在 ' . $priceMin . ' - ' . $priceMax . ' 之间';
                        }
                        if ($cp !== '' && (!is_numeric($cp) || $cp < $p || $cp > $priceMax)) {
                            $errors['spec_details_' . $idx . '_crossed_price'] = '第' . ($idx + 1) . '行划线价需不小于现价，且在 0 - ' . $priceMax . ' 之间';
                        }
                        if ($gc !== '' && mb_strlen($gc) > $codeMaxLen) {
                            $errors['spec_details_' . $idx . '_goods_code'] = '第' . ($idx + 1) . '行商品编码长度不能超过 ' . $codeMaxLen . ' 个字符';
                        }
                        if ($st === '' || !is_numeric($st) || (int)$st < $stockMin || (int)$st > $stockMax) {
                            $errors['spec_details_' . $idx . '_stock_count'] = '第' . ($idx + 1) . '行库存必须在 ' . $stockMin . ' - ' . $stockMax . ' 之间';
                        }
                        if ($vs !== '' && (!is_numeric($vs) || (int)$vs < 0)) {
                            $errors['spec_details_' . $idx . '_spec_sales_num'] = '第' . ($idx + 1) . '行虚拟销量必须为不小于0的整数';
                        }
                    }
                }
            }
        }
        
        // 新增：产品类型与交付方式兼容性校验（后端强制）
        $product_type = $data['product_type'] ?? 'physical';
        $delivery_type = $data['delivery_type'] ?? 'express';
        
        if ($product_type === 'physical') {
            // 实物产品：支持快递发货、手动发货
            if (!in_array($delivery_type, ['expresstrue', 'express'], true)) {
                $errors['delivery_type'] = '实物产品仅支持快递/手动发货交付方式';
            }
        } elseif ($product_type === 'virtual') {
            // 虚拟产品：支持手动发货、网盘下载、文本内容
            if (!in_array($delivery_type, ['express', 'download', 'content'], true)) {
                $errors['delivery_type'] = '虚拟产品不支持快递发货，请选择手动发货、网盘下载或文本内容';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 保存固定字段数据到产品专用表
     *
     * @param int $post_id 产品ID
     * @param array $data 字段数据
     * @return bool 保存是否成功
     */
    private static function save_fixed_fields_to_product_table($post_id, $data) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return false;
        }
        
        try {
            $table_name = $tododb->prefix . 'model_fields_product';
            
            // 预处理：产品服务值标准化为JSON（存储代码数组）
            $ps = $data['product_services'] ?? [];
            if (is_string($ps)) {
                // 空串或JSON字符串
                $decoded = json_decode($ps, true);
                if ($ps === '' || $ps === null) {
                    $ps_list = [];
                } elseif (is_array($decoded)) {
                    $ps_list = $decoded;
                } else {
                    $ps_list = [$ps];
                }
            } elseif (is_array($ps)) {
                $ps_list = $ps;
            } else {
                $ps_list = [];
            }
            // 规范化为代码并去重
            $seen = [];
            $ps_codes = [];
            foreach ($ps_list as $v) {
                $code = is_string($v) ? todo_sanitize_key($v) : '';
                if (!$code || isset($seen[$code])) { continue; }
                $seen[$code] = true;
                $ps_codes[] = $code;
            }
            $product_services_json = json_encode($ps_codes, JSON_UNESCAPED_UNICODE);

            // 准备产品字段数据
            // 添加调试日志，检查 product_video 字段值
            $product_video_value = $data['product_video'] ?? '';
            error_log("保存产品字段 - product_video: " . (is_array($product_video_value) ? json_encode($product_video_value) : $product_video_value));

            $unit_qty_value = $data['unit_qty'] ?? 1;
            if ($unit_qty_value === '' || $unit_qty_value === null) {
                $unit_qty_value = 1;
            }
            $unit_value = $data['unit'] ?? 'piece';
            if (is_string($unit_value)) {
                $unit_value = trim($unit_value);
            }
            if ($unit_value === '' || $unit_value === null || is_array($unit_value) || is_object($unit_value)) {
                $unit_value = 'piece';
            }
            $unit_value = substr(todo_sanitize_key((string) $unit_value), 0, 32);
            if ($unit_value === '') {
                $unit_value = 'piece';
            }
            
            $fm = class_exists('Todo_Model_Fields_Manager') ? new Todo_Model_Fields_Manager() : null;
            
            $product_fields = [
                'marketing_feature' => $data['marketing_feature'] ?? '',
                'product_video' => $product_video_value,
                'product_type' => $data['product_type'] ?? 'physical',
                'product_services' => $product_services_json,
                'spec_type' => $data['spec_type'] ?? 'single',
                'price' => self::normalize_decimal_money($data['price'] ?? 0.00),
                'unit_qty' => (string)($unit_qty_value),
                'unit' => $unit_value,
                'wholesale_price' => self::normalize_decimal_money($data['wholesale_price'] ?? 0.00),
                'wholesale_vip_price' => self::normalize_decimal_money($data['wholesale_vip_price'] ?? 0.00),
                'original_price' => self::normalize_decimal_money($data['original_price'] ?? 0.00),
                'discount_type' => $data['discount_type'] ?? 'none',
                'discount_config' => $data['discount_config'] ?? '',
                'product_code' => $data['product_code'] ?? '',
                'stock' => $data['stock'] ?? 0,
                'show_stock' => $data['show_stock'] ?? 0,
                'virtual_sales' => $data['virtual_sales'] ?? 0,
                'actual_sales' => $data['actual_sales'] ?? 0,
                'spec_config' => (($data['spec_type'] ?? 'single') === 'multiple') ? ($fm ? $fm->process_field_value($data['spec_config'] ?? '', 'spec_config') : ($data['spec_config'] ?? '')) : '',
                'spec_details' => (($data['spec_type'] ?? 'single') === 'multiple') ? ($fm ? $fm->process_field_value($data['spec_details'] ?? '', 'spec_details') : ($data['spec_details'] ?? '')) : '',
                'sale_status' => $data['sale_status'] ?? 'online',
                'delivery_type' => $data['delivery_type'] ?? 'express',
                'delivery_express_note' => $data['delivery_express_note'] ?? '',
                'delivery_download_url' => $data['delivery_download_url'] ?? '',
                'delivery_download_code' => $data['delivery_download_code'] ?? '',
                'delivery_download_password' => $data['delivery_download_password'] ?? '',
                'delivery_download_note' => $data['delivery_download_note'] ?? '',
                'delivery_content_note' => $data['delivery_content_note'] ?? '',
                'auto_gen_license_key' => !empty($data['auto_gen_license_key']) ? (is_array($data['auto_gen_license_key']) ? (in_array('1', $data['auto_gen_license_key']) || in_array(1, $data['auto_gen_license_key']) ? 1 : 0) : (int)$data['auto_gen_license_key']) : 0,
                'memo_before_pay' => $data['memo_before_pay'] ?? $data['before_payment_note'] ?? '',
                'memo_after_pay' => $data['memo_after_pay'] ?? $data['after_payment_note'] ?? '',
                'memo_after_confirm' => $data['memo_after_confirm'] ?? $data['confirm_receipt_note'] ?? '',
                'custom_params' => $data['custom_params'] ?? ''
            ];
            
            // 单独保存SEO字段到posts表（这些字段不在model_fields_product表中）
            if (isset($data['seo_title']) || isset($data['seo_keywords']) || isset($data['seo_description'])) {
                $seo_updates = [];
                $seo_params = [];
                
                if (isset($data['seo_title'])) {
                    $seo_updates[] = 'seo_title = ?';
                    $seo_params[] = $data['seo_title'];
                }
                if (isset($data['seo_keywords'])) {
                    $seo_updates[] = 'seo_keywords = ?';
                    $seo_params[] = $data['seo_keywords'];
                }
                if (isset($data['seo_description'])) {
                    $seo_updates[] = 'seo_description = ?';
                    $seo_params[] = $data['seo_description'];
                }
                
                if (!empty($seo_updates)) {
                    $seo_params[] = $post_id;
                    $seo_sql = "UPDATE {$tododb->prefix}posts SET " . implode(', ', $seo_updates) . " WHERE ID = ?";
                    $tododb->query($seo_sql, $seo_params);
                }
            }
            
            // 检查记录是否存在
            $sql = "SELECT 1 FROM `{$table_name}` WHERE post_id = ? LIMIT 1";
            $exists = $tododb->get_var($sql, [$post_id]);
            
            if ($exists) {
                // 更新现有记录
                $set_clauses = [];
                $values = [];
                foreach ($product_fields as $field => $value) {
                    $set_clauses[] = "`{$field}` = ?";
                    $values[] = $value;
                }
                $sql = "UPDATE `{$table_name}` SET " . implode(', ', $set_clauses) . ", updated_at = NOW() WHERE post_id = ?";
                $values[] = $post_id;
                $ok = $tododb->query($sql, $values);
                if ($ok === false) {
                    throw new Exception($tododb->last_error());
                }
            } else {
                // 插入新记录
                $fields = array_keys($product_fields);
                $fields[] = 'post_id';
                $placeholders = array_fill(0, count($fields), '?');
                $values = array_values($product_fields);
                $values[] = $post_id;

                $fields_sql = '`' . implode('`, `', $fields) . '`';
                $sql = "INSERT INTO `{$table_name}` ({$fields_sql}, `created_at`, `updated_at`) VALUES (" . implode(', ', $placeholders) . ", NOW(), NOW())";
                $ok = $tododb->query($sql, $values);
                if ($ok === false) {
                    throw new Exception($tododb->last_error());
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log('保存产品固定字段数据异常: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 保存字段数据
     *
     * @param int $post_id 产品ID
     * @param array $data 字段数据
     * @return bool 保存是否成功
     */
    public static function save_fields($post_id, $data) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            error_log("产品保存失败: post_id或数据库连接无效 - post_id: $post_id");
            return false;
        }
        
        try {
            // 添加调试日志
            error_log("产品模型保存字段 - Post ID: $post_id, 字段数量: " . count($data) . ", 字段键: " . json_encode(array_keys($data), JSON_UNESCAPED_UNICODE));
            
            // 在验证前，为必填字段设置默认值（如果为空）
            $all_fields = array_merge(self::get_fixed_fields(), self::get_default_fields());
            foreach ($all_fields as $field) {
                $field_key = $field['field_key'];
                if (isset($field['is_required']) && $field['is_required'] && (!isset($data[$field_key]) || $data[$field_key] === '')) {
                    // 如果字段是必填的且值为空，使用默认值
                    if (isset($field['field_default'])) {
                        $data[$field_key] = $field['field_default'];
                        error_log("为必填字段设置默认值: $field_key = " . $field['field_default']);
                    }
                }
            }
            
            // 强制再次校验：包含产品类型与交付方式的兼容性
            $validation = self::validate_fields($data);
            if (empty($validation['valid'])) {
                if (!empty($validation['errors'])) {
                    error_log('产品保存校验失败: ' . json_encode($validation['errors'], JSON_UNESCAPED_UNICODE));
                }
                return false;
            }

            // 1. 保存固定字段到产品专用表
            $fixed_result = self::save_fixed_fields_to_product_table($post_id, $data);
            if (!$fixed_result) {
                // 固定字段保存失败，终止后续保存，避免部分数据落库
                error_log("产品固定字段保存失败 - Post ID: $post_id");
                return false;
            }
            
            error_log("产品固定字段保存成功 - Post ID: $post_id");
            
            // 2. 保存自定义字段到通用表
            $custom_fields = self::get_default_fields();
            $custom_result = true;
            
            foreach ($custom_fields as $field) {
                $field_key = $field['field_key'];
                $value = isset($data[$field_key]) ? $data[$field_key] : '';
                
                // 保存到todo_post_custom_fields表（仅自定义字段）
                $sql = "INSERT INTO {$tododb->prefix}post_custom_fields (post_id, model_id, field_key, field_value, field_type, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, 'text', NOW(), NOW()) 
                        ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = NOW()";
                $result = $tododb->query($sql, [$post_id, 2, $field_key, $value]); // model_id = 2 for product model
                if (!$result) {
                    $custom_result = false;
                }
            }
            
            return $fixed_result && $custom_result;
        } catch (Exception $e) {
            error_log('保存产品字段数据失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取固定字段值
     *
     * @param int $post_id 产品ID
     * @return array 固定字段值数组
     */
    public static function get_fixed_field_values($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }
        
        $field_values = [];
        
        try {
            // 获取固定字段（存储在posts表中）
            $post_sql = "SELECT seo_title, seo_keywords, seo_description FROM " . TABLE_PREFIX . "posts WHERE ID = ?";
            $post_data = $tododb->get_row($post_sql, [$post_id]);
            
            if ($post_data) {
                $post_array = is_array($post_data) ? $post_data : (array)$post_data;
                foreach ($post_array as $key => $value) {
                    // 即使值为空也要添加到数组中，这样字段就能正确显示
                    $field_values[$key] = $value;
                }
            }
            
            // 获取产品模型字段（存储在todo_post_custom_fields表中）
            $model_fields_sql = "SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ?";
            // 兼容CMS_DB的get_results签名：第二参数为查询参数；返回对象数组（默认）或关联数组
            $model_fields_data = $tododb->get_results($model_fields_sql, [$post_id]);
            
            if ($model_fields_data) {
                foreach ($model_fields_data as $field_data) {
                    // 兼容对象/数组两种返回结构，统一按数组处理
                    $field_array = is_array($field_data) ? $field_data : (array)$field_data;
                    $key = isset($field_array['field_key']) ? $field_array['field_key'] : null;
                    if ($key === null) { continue; }
                    $value = isset($field_array['field_value']) ? $field_array['field_value'] : '';
                    $field_values[$key] = $value !== '' ? $value : '';
                }
            }
            
        } catch (Exception $e) {
            error_log('获取产品固定字段值失败: ' . $e->getMessage());
        }
        
        // 合并产品专用表的固定字段值（含 memo_* 到 before/after/confirm_* 的兼容映射）
        $product_fixed = self::get_fixed_fields_from_product_table($post_id);
        if (!empty($product_fixed) && is_array($product_fixed)) {
            // 让产品表中的值优先生效
            $field_values = array_merge($field_values, $product_fixed);
        }
        
        return $field_values;
    }

    /**
     * 从产品专用表获取固定字段值
     *
     * @param int $post_id 产品ID
     * @return array 固定字段值数组
     */
    private static function get_fixed_fields_from_product_table($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }
        
        try {
            $table_name = $tododb->prefix . 'model_fields_product';
            $sql = "SELECT * FROM {$table_name} WHERE post_id = ?";
            $result = $tododb->get_row($sql, [$post_id], 'ARRAY_A');
            
            if ($result) {
                // 移除不需要的字段
                unset($result['field_id'], $result['post_id'], $result['created_at'], $result['updated_at']);
                
                // 兼容映射：数据库采用 memo_* 字段名，表单使用 before/after/confirm_* 字段名
                if (isset($result['memo_before_pay']) && !isset($result['before_payment_note'])) {
                    $result['before_payment_note'] = $result['memo_before_pay'];
                }
                if (isset($result['memo_after_pay']) && !isset($result['after_payment_note'])) {
                    $result['after_payment_note'] = $result['memo_after_pay'];
                }
                if (isset($result['memo_after_confirm']) && !isset($result['confirm_receipt_note'])) {
                    $result['confirm_receipt_note'] = $result['memo_after_confirm'];
                }
                
                return $result;
            }
            
            return [];
        } catch (Exception $e) {
            error_log('从产品专用表获取固定字段值失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取字段值
     *
     * @param int $post_id 产品ID
     * @return array 字段值数组
     */
    public static function get_field_values($post_id) {
        global $tododb;
        
        if (!$post_id || !$tododb) {
            return [];
        }
        
        $values = [];
        
        try {
            // 1. 从产品专用表获取固定字段值
            $fixed_values = self::get_fixed_fields_from_product_table($post_id);
            
            // 2. 从通用表获取自定义字段值
            $custom_fields = self::get_default_fields();
            $custom_values = [];
            
            if (!empty($custom_fields)) {
                $sql = "SELECT field_key, field_value FROM {$tododb->prefix}post_custom_fields WHERE post_id = ? AND model_id = 2";
                // 使用参数查询，结果按对象返回，后续统一转为数组以兼容
                $field_data = $tododb->get_results($sql, [$post_id]);
                
                if ($field_data) {
                    foreach ($field_data as $row) {
                        $row_array = is_array($row) ? $row : (array)$row;
                        if (!isset($row_array['field_key'])) { continue; }
                        $custom_values[$row_array['field_key']] = isset($row_array['field_value']) ? $row_array['field_value'] : '';
                    }
                }
            }
            
            // 3. 合并固定字段和自定义字段的值
            $all_fields = array_merge(self::get_fixed_fields(), self::get_default_fields());
            
            foreach ($all_fields as $field) {
                $field_key = $field['field_key'];
                
                // 优先从对应的数据源获取值
                if (isset($field['is_fixed']) && $field['is_fixed'] == 1) {
                    // 固定字段从专用表获取
                    $values[$field_key] = isset($fixed_values[$field_key]) && $fixed_values[$field_key] !== '' 
                        ? $fixed_values[$field_key] 
                        : $field['field_default'];
                } else {
                    // 自定义字段从通用表获取
                    $values[$field_key] = isset($custom_values[$field_key]) && $custom_values[$field_key] !== '' 
                        ? $custom_values[$field_key] 
                        : $field['field_default'];
                }
            }
            
        } catch (Exception $e) {
            error_log('获取产品字段值失败: ' . $e->getMessage());
        }
        
        return $values;
    }
    
    /**
     * 获取指定分类的模型字段
     *
     * @param array $category_ids 分类ID数组
     * @return array 模型字段数组
     */
    public function get_model_fields_for_categories($category_ids) {
        if (empty($category_ids)) {
            return array();
        }
        
        // 获取产品模型的默认字段
        $default_fields = self::get_default_fields();
        
        // 为字段添加分组信息
        foreach ($default_fields as &$field) {
            $field['field_group'] = 'model';
        }
        
        return $default_fields;
    }
    
    /**
     * 获取所有字段
     *
     * @param array $selected_categories 选中的分类ID数组
     * @return array 所有字段配置
     */
    public function get_all_fields($selected_categories = array()) {
        $all_fields = array();
        
        // 固定字段
        $all_fields = array_merge($all_fields, self::get_fixed_fields());
        
        // 模型字段
        $all_fields = array_merge($all_fields, self::get_default_fields());
        
        return $all_fields;
    }
    
    /**
     * 验证字段数据
     *
     * @param array $data 提交的数据
     * @param array $selected_categories 选中的分类ID数组
     * @return array 验证结果
     */
    public function validate_fields_unified($data, $selected_categories = array()) {
        $all_fields = $this->get_all_fields($selected_categories);
        return self::validate_fields($data, $all_fields);
    }
    
    /**
     * 保存字段数据
     *
     * @param int $post_id 产品ID
     * @param array $data 字段数据
     * @param array $selected_categories 选中的分类ID数组
     * @return bool 保存是否成功
     */
    public function save_fields_unified($post_id, $data, $selected_categories = array()) {
        $all_fields = $this->get_all_fields($selected_categories);
        // 统一保存入口：此处仅做聚合与兼容，不向下传递多余参数，避免参数数量不匹配
        return self::save_fields($post_id, $data);
    }
    
    /**
     * 获取字段值
     *
     * @param int $post_id 产品ID
     * @param array $selected_categories 选中的分类ID数组
     * @return array 字段值数组
     */
    public function get_field_values_unified($post_id, $selected_categories = array()) {
        $all_fields = $this->get_all_fields($selected_categories);
        return self::get_field_values($post_id, $all_fields);
    }
}

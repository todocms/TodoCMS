<?php
/**
 * 用户中心模板解析器
 *
 * 专门处理用户中心模板的解析和渲染
 *
 * @package TodoCMS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // 禁止直接访问
}

class CMS_User_Center_Template_Parser {
    
    /**
     * 单例实例
     *
     * @var CMS_User_Center_Template_Parser
     */
    private static $instance = null;
    
    /**
     * 模板解析器实例
     *
     * @var CMS_Template_Compiler_V2
     */
    private $template_parser;
    
    /**
     * 用户中心标签处理器实例
     *
     * @var CMS_User_Center_Template_Tags
     */
    private $tag_handler;
    
    /**
     * 获取单例实例
     *
     * @return CMS_User_Center_Template_Parser
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        $this->tag_handler = CMS_User_Center_Template_Tags::get_instance();
        
        // 注册用户中心标签
        $this->register_user_center_tags();
    }
    
    /**
     * 注册用户中心标签
     */
    private function register_user_center_tags() {
        // 用户信息标签
        $this->template_parser->register_tag('user_name', [$this->tag_handler, 'parse_user_name']);
        $this->template_parser->register_tag('user_email', [$this->tag_handler, 'parse_user_email']);
        $this->template_parser->register_tag('user_balance', [$this->tag_handler, 'parse_user_balance']);
        $this->template_parser->register_tag('user_points', [$this->tag_handler, 'parse_user_points']);
        $this->template_parser->register_tag('user_level', [$this->tag_handler, 'parse_user_level']);
        
        // 订单相关标签
        $this->template_parser->register_tag('order_number', [$this->tag_handler, 'parse_order_number']);
        $this->template_parser->register_tag('order_date', [$this->tag_handler, 'parse_order_date']);
        $this->template_parser->register_tag('order_amount', [$this->tag_handler, 'parse_order_amount']);
        $this->template_parser->register_tag('order_status', [$this->tag_handler, 'parse_order_status']);
        $this->template_parser->register_tag('order_status_text', [$this->tag_handler, 'parse_order_status_text']);
        
        // 产品相关标签
        $this->template_parser->register_tag('product_name', [$this->tag_handler, 'parse_uc_product_name']);
        $this->template_parser->register_tag('product_price', [$this->tag_handler, 'parse_uc_product_price']);
        $this->template_parser->register_tag('product_image', [$this->tag_handler, 'parse_uc_product_image']);
        
        // 购物车相关标签
        $this->template_parser->register_tag('cart_count', [$this->tag_handler, 'parse_cart_count']);
        $this->template_parser->register_tag('cart_total', [$this->tag_handler, 'parse_cart_total']);
        $this->template_parser->register_tag('cart_subtotal_format', [$this->tag_handler, 'parse_cart_subtotal_format']);
        $this->template_parser->register_tag('cart_shipping_fee_format', [$this->tag_handler, 'parse_cart_shipping_fee_format']);
        $this->template_parser->register_tag('cart_total_format', [$this->tag_handler, 'parse_cart_total_format']);
        $this->template_parser->register_tag('cart_url', [$this->tag_handler, 'parse_cart_url']);
        $this->template_parser->register_tag('cart_items', [$this->tag_handler, 'parse_cart_items']);
        $this->template_parser->register_tag('checkout_url', [$this->tag_handler, 'parse_checkout_url']);
        $this->template_parser->register_tag('shop_url', [$this->tag_handler, 'parse_shop_url']);
        
        // 收藏和消息标签
        $this->template_parser->register_tag('favorites_count', [$this->tag_handler, 'parse_favorites_count']);

        // 地址相关标签
        $this->template_parser->register_tag('user_default_address_text', [$this->tag_handler, 'parse_user_default_address_text']);
        
        // 文章相关标签
        $this->template_parser->register_tag('article_title', [$this->tag_handler, 'parse_article_title']);
        $this->template_parser->register_tag('article_excerpt', [$this->tag_handler, 'parse_article_excerpt']);
        $this->template_parser->register_tag('article_date', [$this->tag_handler, 'parse_article_date']);
        
        // 钱包相关标签
        $this->template_parser->register_tag('wallet_balance', [$this->tag_handler, 'parse_wallet_balance']);
        
        // 优惠券标签
        $this->template_parser->register_tag('coupons_count', [$this->tag_handler, 'parse_coupons_count']);
        
        // 页面导航标签
        $this->template_parser->register_tag('current_page', [$this->tag_handler, 'parse_current_page']);
    }
    
    /**
     * 解析用户中心模板
     *
     * @param string $template_path 模板文件路径
     * @param array $data 模板数据
     * @return string 解析后的HTML内容
     */
    public function parse_template($template_path, $data = []) {
        // 添加用户中心特定的数据
        $data = $this->prepare_user_center_data($data);
        
        // 使用主模板解析器解析
        return CMS_Template_Compiler_V2::parse_template_with_cache($template_path, $data);
    }
    
    /**
     * 解析模板内容字符串
     *
     * @param string $template_content 模板内容
     * @param array $data 模板数据
     * @return string 解析后的内容
     */
    public function parse_content($template_content, $data = []) {
        // 添加用户中心特定的数据
        $data = $this->prepare_user_center_data($data);
        
        // 使用主模板解析器解析
        return CMS_Template_Compiler_V2::render_string($template_content, $data);
    }
    
    /**
     * 准备用户中心数据
     *
     * @param array $data 原始数据
     * @return array 增强后的数据
     */
    private function prepare_user_center_data($data = []) {
        // 获取当前用户信息
        if (function_exists('todo_get_current_user')) {
            $current_user = todo_get_current_user();
            if ($current_user) {
                if (is_object($current_user)) {
                    $current_user = get_object_vars($current_user);
                }
                if (is_array($current_user)) {
                    $uid = 0;
                    if (isset($current_user['ID'])) {
                        $uid = (int) $current_user['ID'];
                    } elseif (isset($current_user['id'])) {
                        $uid = (int) $current_user['id'];
                    }
                    if ($uid > 0 && function_exists('get_user_avatar_url')) {
                        $current_user['avatar'] = get_user_avatar_url($uid, 80);
                        if (function_exists('todo_media_url_to_relative')) {
                            $current_user['avatar'] = todo_media_url_to_relative($current_user['avatar']);
                        }
                    }
                    $data['current_user'] = $current_user;
                    $data['user'] = $current_user;
                    $data['user_id'] = $uid;
                }
            }
        }
        
        // 添加用户中心基础URL
        if (function_exists('todo_get_user_center_url')) {
            $data['user_center_url'] = todo_get_user_center_url();
        } else {
            $data['user_center_url'] = '/users/';
        }
        
        // 添加主题URL
        if (function_exists('get_template_directory_uri')) {
            $data['theme_url'] = get_template_directory_uri();
        } else {
            $active_theme = function_exists('get_theme_name') ? get_theme_name() : 'default';
            $site = function_exists('todo_url') ? todo_url() : '';
            $data['theme_url'] = $site . '/content/themes/' . $active_theme;
        }
        
        // 添加当前页面信息
        $data['current_page'] = basename($_SERVER['SCRIPT_NAME'], '.php');
        $data['current_url'] = $_SERVER['REQUEST_URI'];
        
        // 添加CSRF令牌
        if (function_exists('todo_generate_csrf_token')) {
            $data['csrf_token'] = todo_generate_csrf_token();
        }
        
        // 添加购物车数据
        if (class_exists('Todo_Cart_Manager')) {
            $cart_details = Todo_Cart_Manager::get_cart_details();

            // 支持从 URL / POST 传参 selected 选中的项目列表（如 ?user=checkout&selected=key1,key2）
            $selected_param = trim($_GET['selected'] ?? $_POST['selected'] ?? '');
            if (!empty($selected_param) && !empty($cart_details['items'])) {
                $selected_keys = array_map('trim', explode(',', $selected_param));
                $filtered_items = array();
                foreach ($cart_details['items'] as $ck => $ci) {
                    if (in_array((string)$ck, $selected_keys, true) || in_array((string)($ci['product_id'] ?? ''), $selected_keys, true)) {
                        $filtered_items[$ck] = $ci;
                    }
                }
                if (!empty($filtered_items)) {
                    $cart_details['items'] = $filtered_items;
                    $tot_qty = 0;
                    $tot_price = 0;
                    foreach ($filtered_items as $fi) {
                        $q = intval($fi['quantity'] ?? 1);
                        $p = floatval($fi['price'] ?? 0);
                        $tot_qty += $q;
                        $tot_price += $q * $p;
                    }
                    $cart_details['count'] = $tot_qty;
                    $cart_details['total'] = $tot_price;
                }
            }

            $data['cart_items'] = $cart_details['items'] ?? [];
            $data['cart_count'] = $cart_details['count'] ?? 0;
            $data['cart_total'] = number_format($cart_details['total'] ?? 0, 2);
            
            // 计算购物车各项费用
            $subtotal = 0;
            $original_subtotal = 0;
            if (isset($cart_details['items']) && is_array($cart_details['items'])) {
                foreach ($cart_details['items'] as $item) {
                    $item_p = floatval($item['price']);
                    $item_rp = floatval($item['regular_price'] ?? $item_p);
                    if ($item_rp < $item_p) $item_rp = $item_p;
                    $qty = intval($item['quantity']);
                    $subtotal += $item_p * $qty;
                    $original_subtotal += $item_rp * $qty;
                }
            }
            $wholesale_discount = max(0, $original_subtotal - $subtotal);
            $shipping_fee = 0; // 默认运费为0
            $total = $subtotal + $shipping_fee;
            
            $data['cart'] = [
                'items' => $cart_details['items'] ?? [],
                'subtotal' => $subtotal,
                'subtotal_format' => number_format($subtotal, 2),
                'original_subtotal' => $original_subtotal,
                'original_subtotal_format' => number_format($original_subtotal, 2),
                'wholesale_discount' => $wholesale_discount,
                'wholesale_discount_format' => number_format($wholesale_discount, 2),
                'has_wholesale_discount' => ($wholesale_discount > 0.001),
                'shipping_fee' => $shipping_fee,
                'shipping_fee_format' => number_format($shipping_fee, 2),
                'total' => $total,
                'total_format' => number_format($total, 2),
                'count' => $cart_details['count'] ?? 0
            ];
        } else if (function_exists('todo_get_user_cart_items')) {
            $data['cart_items'] = todo_get_user_cart_items();
        }
        
        return $data;
    }
    
    /**
     * 渲染用户中心页面
     *
     * @param string $page_name 页面名称
     * @param array $data 页面数据
     * @return string 渲染后的HTML
     */
    public function render_page($page_name, $data = []) {
        $theme_dir = function_exists('get_template_directory') ? get_template_directory() : ABSPATH . 'content/themes/' . (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $template_path = $theme_dir . '/users/' . $page_name . '.htm';
        
        if (!file_exists($template_path)) {
            return '<div class="error">模板文件不存在: ' . $page_name . '.htm</div>';
        }
        
        return $this->parse_template($template_path, $data);
    }
    
    /**
     * 渲染用户中心组件
     *
     * @param string $component_name 组件名称
     * @param array $data 组件数据
     * @return string 渲染后的HTML
     */
    public function render_component($component_name, $data = []) {
        $theme_dir = function_exists('get_template_directory') ? get_template_directory() : ABSPATH . 'content/themes/' . (function_exists('get_theme_name') ? get_theme_name() : 'default');
        $template_path = $theme_dir . '/users/' . $component_name . '.htm';
        
        if (!file_exists($template_path)) {
            return '<div class="error">组件模板不存在: ' . $component_name . '.htm</div>';
        }
        
        return $this->parse_template($template_path, $data);
    }
    
    /**
     * 获取用户统计数据
     *
     * @param int $user_id 用户ID
     * @return array 统计数据
     */
    public function get_user_stats($user_id = null) {
        if (!$user_id) {
            if (function_exists('todo_get_current_user_id')) {
                $user_id = todo_get_current_user_id();
            } else {
                return [];
            }
        }
        
        $stats = [];
        
        // 订单统计
        if (function_exists('todo_get_user_orders_count')) {
            $stats['orders_count'] = todo_get_user_orders_count($user_id);
            $stats['pending_orders'] = todo_get_user_orders_count($user_id, 'pending');
            $stats['completed_orders'] = todo_get_user_orders_count($user_id, 'completed');
        }
        
        // 购物车统计
        if (function_exists('todo_get_cart_count')) {
            $stats['cart_count'] = todo_get_cart_count($user_id);
        }
        
        // 收藏统计
        if (function_exists('todo_get_favorites_count')) {
            $stats['favorites_count'] = todo_get_favorites_count($user_id);
        }
        
        // 文章统计
        if (function_exists('todo_get_user_articles_count')) {
            $stats['articles_count'] = todo_get_user_articles_count($user_id);
        }
        
        // 优惠券统计
        if (function_exists('todo_get_user_coupons_count')) {
            $stats['coupons_count'] = todo_get_user_coupons_count($user_id);
        }
        
        return $stats;
    }
    
    /**
     * 处理循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    public function process_loops($content, $data = []) {
        // 处理订单循环
        $content = $this->process_orders_loop($content, $data);
        
        // 处理购物车循环
        $content = $this->process_cart_loop($content, $data);
        
        // 处理todo:cart_items循环
        $content = $this->process_todo_cart_items_loop($content, $data);
        
        // 处理收藏循环
        $content = $this->process_favorites_loop($content, $data);
        
        // 处理地址循环
        $content = $this->process_addresses_loop($content, $data);
        
        // 处理文章循环
        $content = $this->process_articles_loop($content, $data);
        
        // 处理消息循环
        $content = $this->process_messages_loop($content, $data);
        
        return $content;
    }
    
    /**
     * 处理订单循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_orders_loop($content, $data) {
        $pattern = '/\{orders_loop\}(.*?)\{\/orders_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['orders']) && is_array($data['orders'])) {
                foreach ($data['orders'] as $order) {
                    $loop_data = array_merge($data, ['order' => $order]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 处理购物车循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_cart_loop($content, $data) {
        $pattern = '/\{cart_loop\}(.*?)\{\/cart_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['cart_items']) && is_array($data['cart_items'])) {
                foreach ($data['cart_items'] as $item) {
                    $loop_data = array_merge($data, ['cart_item' => $item, 'product' => $item]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 处理todo:cart_items循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_todo_cart_items_loop($content, $data) {
        $pattern = '/\{\{todo:cart_items\}\}(.*?)\{\{\/todo:cart_items\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            $items = isset($data['cart_items']) && is_array($data['cart_items']) ? $data['cart_items'] : [];
            
            if (!empty($items)) {
                foreach ($items as $cart_key => $item) {
                    $item = is_array($item) ? $item : (array) $item;
                    $pid = intval($item['product_id'] ?? 0);
                    $qty = intval($item['quantity'] ?? 1);
                    $price = floatval($item['price'] ?? ($item['current_price'] ?? ($item['regular_price'] ?? ($item['sale_price'] ?? 0))));
                    $regular_price = floatval($item['regular_price'] ?? ($item['original_price'] ?? $price));
                    if ($regular_price < $price) {
                        $regular_price = $price;
                    }
                    $is_wholesale = ($regular_price > $price + 0.001);
                    $saved_amount = max(0, $regular_price - $price) * $qty;
                    $orig_total = $regular_price * $qty;
                    $sale_price = floatval($item['sale_price'] ?? ($item['current_price'] ?? 0));
                    $stock = intval($item['stock'] ?? 999);
                    $image = $item['image'] ?? '';
                    if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                        $image = todo_media_url_to_runtime_absolute($image);
                    }
                    $title = $item['title'] ?? '';
                    $url = ($pid > 0 && function_exists('get_permalink')) ? get_permalink($pid) : '';
                    
                    $loop_data = array_merge($data, [
                        'item_id' => (string) $cart_key,
                        'product_id' => $pid,
                        'title' => $title,
                        'product_title' => $title,
                        'product_name' => $title,
                        'image' => $image,
                        'product_image' => $image,
                        'product_url' => $url,
                        'product_price' => number_format($price, 2),
                        'product_original_price' => $is_wholesale ? number_format($regular_price, 2) : '',
                        'original_unit_price_format' => number_format($regular_price, 2),
                        'original_total_price_format' => number_format($orig_total, 2),
                        'saved_amount_format' => number_format($saved_amount, 2),
                        'is_wholesale' => $is_wholesale,
                        'discount_label' => $is_wholesale ? (function_exists('todo_get_user_discount_label') ? todo_get_user_discount_label() : __('高级批发价')) : '',
                        'product_stock' => $stock,
                        'item_quantity' => $qty,
                        'quantity' => $qty,
                        'item_total' => number_format($price * $qty, 2),
                        'total_price_format' => number_format($price * $qty, 2),
                        'total_price_val' => $price * $qty,
                        'spec_name' => todo_format_specs_text($item['specs'] ?? [], $pid),
                        'product_specs' => todo_format_specs_text($item['specs'] ?? [], $pid),
                        'specs_text' => todo_format_specs_text($item['specs'] ?? [], $pid),
                        'product_unit' => $item['unit'] ?? '',
                        'product_unit_qty' => $item['unit_qty'] ?? 1,
                        'product_unit_text' => $item['unit_text'] ?? (($item['unit_qty'] ?? 1) != 1 ? ($item['unit_qty'] ?? 1) : '') . ($item['unit'] ?? ''),
                        'product_total_unit_text' => (function() use ($qty, $item) {
                            $u = $item['unit'] ?? '';
                            if (empty($u)) return '';
                            $uq = floatval($item['unit_qty'] ?? 1);
                            $total = $qty * $uq;
                            if ($total <= 0) return '';
                            return (floor($total) == $total ? (string)$total : rtrim(rtrim(number_format($total, 2), '0'), '.')) . $u;
                        })(),
                        'product_type' => $item['product_type'] ?? 'physical',
                    ]);
                    
                    // 处理规格信息（HTML 格式，用于条件判断时作为非空字符串）
                    if (!empty($item['specs']) && is_array($item['specs'])) {
                        $specs_html = '';
                        foreach ($item['specs'] as $spec_name => $spec_value) {
                            $specs_html .= '<span>' . htmlspecialchars((string) $spec_name) . ': ' . htmlspecialchars((string) $spec_value) . '</span>';
                        }
                        $loop_data['product_specs'] = $specs_html;
                    }
                    
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 格式化规格信息
     *
     * @param array $specs 规格数组
     * @return string 格式化后的规格字符串
     */
    private function format_specs($specs) {
        if (empty($specs) || !is_array($specs)) {
            return '';
        }
        
        $formatted = [];
        foreach ($specs as $name => $value) {
            $formatted[] = htmlspecialchars($name) . ': ' . htmlspecialchars($value);
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * 处理收藏循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_favorites_loop($content, $data) {
        $pattern = '/\{favorites_loop\}(.*?)\{\/favorites_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['favorites']) && is_array($data['favorites'])) {
                foreach ($data['favorites'] as $favorite) {
                    $loop_data = array_merge($data, ['favorite' => $favorite, 'product' => $favorite]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 处理地址循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_addresses_loop($content, $data) {
        $pattern = '/\{addresses_loop\}(.*?)\{\/addresses_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['addresses']) && is_array($data['addresses'])) {
                foreach ($data['addresses'] as $address) {
                    $loop_data = array_merge($data, ['address' => $address]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 处理文章循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_articles_loop($content, $data) {
        $pattern = '/\{articles_loop\}(.*?)\{\/articles_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['articles']) && is_array($data['articles'])) {
                foreach ($data['articles'] as $article) {
                    $loop_data = array_merge($data, ['article' => $article]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
    
    /**
     * 处理消息循环标签
     *
     * @param string $content 模板内容
     * @param array $data 数据
     * @return string 处理后的内容
     */
    private function process_messages_loop($content, $data) {
        $pattern = '/\{messages_loop\}(.*?)\{\/messages_loop\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $loop_content = $matches[1];
            $output = '';
            
            if (isset($data['messages']) && is_array($data['messages'])) {
                foreach ($data['messages'] as $message) {
                    $loop_data = array_merge($data, ['message' => $message]);
                    $output .= $this->parse_content($loop_content, $loop_data);
                }
            }
            
            return $output;
        }, $content);
    }
}

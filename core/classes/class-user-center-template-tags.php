<?php
/**
 * 用户中心模板标签处理器
 *
 * 处理用户中心相关的所有模板标签
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_User_Center_Template_Tags {
    
    /**
     * 单例实例
     *
     * @var CMS_User_Center_Template_Tags
     */
    private static $instance = null;
    
    /**
     * 当前用户数据
     *
     * @var array
     */
    private $current_user_data = null;
    
    /**
     * 解析货币代码/符号（根据后台 B2C/B2B 模式输出符号或 ISO 代码）
     */
    public function parse_currency_code($attrs = [], $data = []) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (!empty($currency_config['display_symbol'])) {
                return $currency_config['display_symbol'];
            }
            return isset($currency_config['symbol']) ? $currency_config['symbol'] : '';
        }
        return '';
    }

    /**
     * 解析货币符号（强制返回符号如 $、¥、€）
     */
    public function parse_currency_symbol($attrs = [], $data = []) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (!empty($currency_config['symbol'])) {
                return $currency_config['symbol'];
            }
            if (!empty($currency_config['code']) && function_exists('todo_get_currency_symbol')) {
                return todo_get_currency_symbol($currency_config['code']);
            }
        }
        return '$';
    }

    /**
     * 解析 ISO 4217 纯三位代码（专供 Google 跟踪、支付与接口使用）
     */
    public function parse_currency_iso($attrs = [], $data = []) {
        if (function_exists('todo_get_currency_config')) {
            $currency_config = todo_get_currency_config();
            if (!empty($currency_config['code'])) {
                return strtoupper($currency_config['code']);
            }
        }
        return 'USD';
    }

    /**
     * 解析当前语言汇率与基准汇率比值
     */
    public function parse_currency_rate($attrs = [], $data = []) {
        if (function_exists('todo_get_currency_config') && function_exists('todo_get_default_currency_config')) {
            $config = todo_get_currency_config();
            $default_config = todo_get_default_currency_config();
            $current_rate = ($config['status'] == 1 && $config['rate'] > 0) ? $config['rate'] : 1.0;
            $base_rate = ($default_config['rate'] > 0) ? $default_config['rate'] : 1.0;
            return (string) round($current_rate / $base_rate, 4);
        }
        return '1.0';
    }

    /**
     * 解析国家与地区下拉框 HTML 选项 ({{todo:country_options}})
     */
    public function parse_country_options($attrs = [], $data = []) {
        if (!function_exists('todo_render_country_options')) {
            $f = (defined('ABSPATH') ? ABSPATH : dirname(__DIR__, 2) . '/') . 'core/functions/user-functions.php';
            if (file_exists($f)) {
                require_once $f;
            }
        }
        $selected = isset($attrs['selected']) ? $attrs['selected'] : '';
        if (empty($selected) && is_array($data)) {
            if (isset($data['address']['country_code'])) {
                $selected = $data['address']['country_code'];
            } elseif (isset($data['country_code'])) {
                $selected = $data['country_code'];
            } elseif (isset($data['country'])) {
                $selected = $data['country'];
            }
        }
        if (function_exists('todo_render_country_options')) {
            return todo_render_country_options($selected);
        }
        return '';
    }
    
    /**
     * 获取单例实例
     *
     * @return CMS_User_Center_Template_Tags
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
        // 延迟加载用户数据
    }
    
    /**
     * 获取当前用户数据
     *
     * @return array|null
     */
    private function get_current_user_data() {
        if ($this->current_user_data === null) {
            if (function_exists('todo_get_current_user')) {
                $user = todo_get_current_user();
                if (is_object($user)) {
                    // 将对象转换为数组，统一后续访问方式
                    $this->current_user_data = get_object_vars($user);
                } elseif (is_array($user)) {
                    $this->current_user_data = $user;
                } else {
                    $this->current_user_data = null;
                }
            } else {
                $this->current_user_data = null;
            }
        }
        return $this->current_user_data;
    }
    
    /**
     * 解析用户名标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_name($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return isset($attrs['default']) ? $attrs['default'] : __('游客');
        }
        
        return (isset($user['display_name']) && $user['display_name'] !== '') ? $user['display_name'] : __('用户');
    }

    /**
     * 解析用户显示名称标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_display_name($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        return (isset($user['display_name']) && $user['display_name'] !== '') ? $user['display_name'] : __('用户');
    }
    
    /**
     * 解析用户邮箱标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_email($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user || !isset($user['user_email'])) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        return $user['user_email'];
    }
    
    /**
     * 解析用户头像标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_avatar($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        $size = isset($attrs['size']) ? intval($attrs['size']) : 50;
        $default = isset($attrs['default']) ? $attrs['default'] : get_template_directory_uri() . '/users/skin/images/default-avatar.svg';
        
        if (!$user) {
            return $default;
        }

        $avatar = '';
        if (isset($user['avatar']) && is_string($user['avatar'])) {
            $avatar = trim($user['avatar']);
        }
        if ($avatar !== '' && stripos($avatar, 'gravatar.com') !== false) {
            $avatar = '';
        }

        if ($avatar === '') {
            $user_id = 0;
            if (isset($user['ID'])) {
                $user_id = (int) $user['ID'];
            } elseif (isset($user['id'])) {
                $user_id = (int) $user['id'];
            }
            if ($user_id > 0 && function_exists('get_user_avatar_url')) {
                $avatar = (string) get_user_avatar_url($user_id, $size);
            }
        }

        if ($avatar === '') {
            return $default;
        }

        if (function_exists('todo_user_avatar_normalize_url')) {
            return todo_user_avatar_normalize_url($avatar);
        }

        if (strpos($avatar, 'http://') === 0 || strpos($avatar, 'https://') === 0 || strpos($avatar, '//') === 0) {
            return $avatar;
        }
        if (function_exists('home_url')) {
            return home_url($avatar);
        }
        return $avatar;
    }
    
    /**
     * 解析用户余额标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_balance($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return '0.00';
        }
        
        $uid = intval($user['ID'] ?? ($user['id'] ?? 0));
        if ($uid > 0 && function_exists('todo_get_user_balance')) {
            $balance = todo_get_user_balance($uid);
        } elseif (isset($user['balance'])) {
            $balance = floatval($user['balance']);
        } else {
            $balance = 0.00;
        }

        if (!empty($attrs['raw'])) {
            return (string) $balance;
        }

        if (function_exists('todo_format_price')) {
            return todo_format_price($balance, 'number');
        }
        $format = isset($attrs['format']) ? $attrs['format'] : '%.2f';
        return sprintf($format, $balance);
    }
    
    /**
     * 解析用户积分标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_points($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return '0';
        }
        
        $uid = intval($user['ID'] ?? ($user['id'] ?? 0));
        $points = 0;
        if (isset($user['points'])) {
            $points = floatval($user['points']);
        } else if ($uid > 0) {
            global $tododb;
            $raw_pts = (float) get_user_meta($uid, '_user_points', true);
            $orders_tbl = DB_PREFIX . 'orders';
            $ocf_tbl = DB_PREFIX . 'order_custom_fields';
            $sql_locked_pts = $tododb->prepare(
                "SELECT SUM(CAST(ocf1.field_value AS UNSIGNED)) FROM {$ocf_tbl} ocf1
                 INNER JOIN {$orders_tbl} o ON ocf1.order_id = o.id
                 LEFT JOIN {$ocf_tbl} ocf2 ON ocf2.order_id = o.id AND ocf2.field_name = '_points_deducted'
                 WHERE o.user_id = %d
                   AND o.status IN ('pending', 'processing')
                   AND ocf1.field_name = '_points_used'
                   AND (ocf2.field_value IS NULL OR ocf2.field_value != '1')",
                $uid
            );
            $locked_pts = (int) $tododb->get_var($sql_locked_pts);
            $points = max(0, $raw_pts - $locked_pts);
        }

        if (!empty($attrs['raw'])) {
            return (string) $points;
        }
        
        return number_format($points);
    }
    
    /**
     * 解析用户等级标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_level($attrs = [], $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return isset($attrs['default']) ? $attrs['default'] : __('注册会员');
        }
        
        return isset($user['user_level']) ? $user['user_level'] : __('注册会员');
    }
    
    /**
     * 解析订单号标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_order_number($attrs = [], $data = []) {
        if (isset($data['order']['order_number'])) {
            return $data['order']['order_number'];
        }
        if (isset($data['order_number'])) {
            return $data['order_number'];
        }
        return isset($attrs['default']) ? $attrs['default'] : '';
    }
    
    /**
     * 解析订单日期标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_order_date($attrs = [], $data = []) {
        $date = null;
        if (isset($data['order']['order_date'])) {
            $date = $data['order']['order_date'];
        } elseif (isset($data['order_date'])) {
            $date = $data['order_date'];
        }
        
        if (!$date) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : 'Y-m-d H:i:s';
        return date($format, is_numeric($date) ? $date : strtotime($date));
    }
    
    /**
     * 解析订单金额标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_order_amount($attrs = [], $data = []) {
        $amount = null;
        if (isset($data['order']['order_amount'])) {
            $amount = $data['order']['order_amount'];
        } elseif (isset($data['order_amount'])) {
            $amount = $data['order_amount'];
        }
        
        if ($amount === null) {
            return isset($attrs['default']) ? $attrs['default'] : '0.00';
        }

        $format_type = isset($attrs['format_type']) ? $attrs['format_type'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($amount, $format_type);
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : '%.2f';
        $cfg = function_exists('todo_get_currency_config') ? todo_get_currency_config() : [];
        $currency = isset($attrs['currency']) ? $attrs['currency'] : (!empty($cfg['display_symbol']) ? $cfg['display_symbol'] : (!empty($cfg['symbol']) ? $cfg['symbol'] : ''));
        
        return $currency . sprintf($format, floatval($amount));
    }
    
    /**
     * 解析订单状态标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_order_status($attrs = [], $data = []) {
        $status = null;
        if (isset($data['order']['order_status'])) {
            $status = $data['order']['order_status'];
        } elseif (isset($data['order_status'])) {
            $status = $data['order_status'];
        }
        
        if (!$status) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        return $status;
    }
    
    /**
     * 解析订单状态文本标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_order_status_text($attrs = [], $data = []) {
        $status = null;
        if (isset($data['order']['order_status'])) {
            $status = $data['order']['order_status'];
        } elseif (isset($data['order_status'])) {
            $status = $data['order_status'];
        }
        
        if (!$status) {
            return isset($attrs['default']) ? $attrs['default'] : __('未知状态');
        }
        
        $status_map = [
            'pending' => __('待付款'),
            'paid' => __('已付款'),
            'processing' => __('待发货'),
            'shipped' => __('已发货'),
            'delivered' => __('已送达'),
            'completed' => __('已完成'),
            'cancelled' => __('已取消'),
            'refund' => __('售后中'),
            'refunding' => __('退款中'),
            'refunded' => __('已退款'),
            'failed' => __('支付失败')
        ];
        
        return isset($status_map[$status]) ? $status_map[$status] : $status;
    }
    
    /**
     * 解析产品名称标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_uc_product_name($attrs = [], $data = []) {
        if (isset($data['product']['product_title'])) {
            return $data['product']['product_title'];
        }
        if (isset($data['product']['product_name'])) {
            return $data['product']['product_name'];
        }
        if (isset($data['product_title'])) {
            return $data['product_title'];
        }
        if (isset($data['product_name'])) {
            return $data['product_name'];
        }
        return isset($attrs['default']) ? $attrs['default'] : '';
    }

    public function parse_uc_product_title($attrs = [], $data = []) {
        return $this->parse_uc_product_name($attrs, $data);
    }

    public function parse_uc_spec_name($attrs = [], $data = []) {
        if (isset($data['spec_name'])) {
            return $data['spec_name'];
        }
        if (isset($data['specs_text'])) {
            return $data['specs_text'];
        }
        if (isset($data['product_specs']) && is_string($data['product_specs'])) {
            return $data['product_specs'];
        }
        return isset($attrs['default']) ? $attrs['default'] : '';
    }
    
    /**
     * 解析产品价格标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_uc_product_price($attrs = [], $data = []) {
        $price = null;
        if (isset($data['product']['product_price'])) {
            $price = $data['product']['product_price'];
        } elseif (isset($data['product_price'])) {
            $price = $data['product_price'];
        }

        if ($price !== null && $price !== '') {
            if (is_string($price) && preg_match('/[^\d.]/', $price)) {
                $clean_val = floatval(preg_replace('/[^\d.]/', '', $price));
                if ($clean_val > 0) {
                    return $price;
                }
            } elseif (is_numeric($price) && floatval($price) > 0) {
                $format_type = isset($attrs['format_type']) ? $attrs['format_type'] : 'currency';
                if (function_exists('todo_format_price')) {
                    return todo_format_price($price, $format_type);
                }
                return number_format(floatval($price), 2);
            }
        }

        $pid = $data['product_id'] ?? ($data['product']['product_id'] ?? ($data['product']['id'] ?? ($data['id'] ?? 0)));
        if (($price === null || floatval(preg_replace('/[^\d.]/', '', (string)$price)) <= 0) && $pid > 0) {
            // 尝试获取价格
            $candidate_keys = array('price', 'todo_product_price', 'product_price', '_sale_price', '_regular_price', 'wholesale_vip_price');
            foreach ($candidate_keys as $k) {
                $v = get_post_meta($pid, $k, true);
                if ($v !== '') {
                    if ($k === 'product_price' && is_string($v) && strpos($v, '{') !== false) {
                        $decoded = json_decode($v, true);
                        if (is_array($decoded)) {
                            $sv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['sale_price'] ?? 0)));
                            $rv = floatval(preg_replace('/[^\d.]/', '', (string)($decoded['regular_price'] ?? 0)));
                            $p_val = $sv > 0 ? $sv : $rv;
                            if ($p_val > 0) {
                                $price = $p_val;
                                break;
                            }
                        }
                    }
                    $v_clean = preg_replace('/[^\d.]/', '', (string)$v);
                    if (floatval($v_clean) > 0) {
                        $price = floatval($v_clean);
                        break;
                    }
                }
            }

            if ($price === null || floatval($price) <= 0) {
                global $tododb;
                $mfp = $tododb->prefix . 'model_fields_product';
                try {
                    $row = $tododb->get_row("SELECT price, spec_details FROM {$mfp} WHERE post_id = ? LIMIT 1", [$pid]);
                    if ($row) {
                        if ($row->price && floatval($row->price) > 0) {
                            $price = floatval($row->price);
                        }
                        if (($price === null || floatval($price) <= 0) && !empty($row->spec_details)) {
                            $details = json_decode($row->spec_details, true);
                            if (is_array($details)) {
                                $spec_prices = [];
                                foreach ($details as $d) {
                                    $sp = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                                    if ($sp > 0) {
                                        $spec_prices[] = $sp;
                                    }
                                }
                                if (!empty($spec_prices)) {
                                    $price = min($spec_prices);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {}
            }

            if ($price === null || floatval($price) <= 0) {
                $sd = get_post_meta($pid, 'spec_details', true);
                if ($sd) {
                    $details = json_decode($sd, true);
                    if (is_array($details)) {
                        $spec_prices = [];
                        foreach ($details as $d) {
                            $sp = isset($d['users_price']) ? floatval($d['users_price']) : (isset($d['price']) ? floatval($d['price']) : 0);
                            if ($sp > 0) {
                                $spec_prices[] = $sp;
                            }
                        }
                        if (!empty($spec_prices)) {
                            $price = min($spec_prices);
                        }
                    }
                }
            }
        }
        
        if ($price === null) {
            return isset($attrs['default']) ? $attrs['default'] : '0.00';
        }

        $format_type = isset($attrs['format_type']) ? $attrs['format_type'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($price, $format_type);
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : '%.2f';
        $cfg = function_exists('todo_get_currency_config') ? todo_get_currency_config() : [];
        $currency = isset($attrs['currency']) ? $attrs['currency'] : (!empty($cfg['display_symbol']) ? $cfg['display_symbol'] : (!empty($cfg['symbol']) ? $cfg['symbol'] : ''));
        
        return $currency . sprintf($format, floatval($price));
    }
    
    /**
     * 解析产品图片标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_uc_product_image($attrs = [], $data = []) {
        $image = null;
        if (isset($data['product']['product_image'])) {
            $image = $data['product']['product_image'];
        } elseif (isset($data['product_image'])) {
            $image = $data['product_image'];
        }
        
        if (!$image) {
            $image = isset($attrs['default']) ? $attrs['default'] : get_template_directory_uri() . '/users/skin/images/product-placeholder.svg';
        }
        
        if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
            $image = todo_media_url_to_runtime_absolute($image);
        }
        
        return $image;
    }
    
    /**
     * 解析购物车数量标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_count($attrs = [], $data = []) {
        if (isset($data['cart_count'])) {
            return $data['cart_count'];
        }

        if (class_exists('Todo_Cart_Manager') && is_callable(['Todo_Cart_Manager', 'get_cart'])) {
            $cart = Todo_Cart_Manager::get_cart();
            $count = is_array($cart) && isset($cart['count']) ? (int) $cart['count'] : 0;
            return (string) $count;
        }

        $user = $this->get_current_user_data();
        if (!$user) {
            return '0';
        }

        if (function_exists('todo_get_cart_count')) {
            $count = todo_get_cart_count($user['ID']);
            return is_scalar($count) ? (string) $count : '0';
        }

        return '0';
    }
    
    /**
     * 解析购物车总金额标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_total($attrs = [], $data = []) {
        if (isset($data['cart_total'])) {
            $total = $data['cart_total'];
        } else {
            // 从数据库获取购物车总金额
            $user = $this->get_current_user_data();
            if (!$user) {
                $total = 0;
            } elseif (function_exists('todo_get_cart_total')) {
                $total = todo_get_cart_total($user['ID']);
            } else {
                $total = 0;
            }
        }
        
        $format_type = isset($attrs['format_type']) ? $attrs['format_type'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($total, $format_type);
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : '%.2f';
        $cfg = function_exists('todo_get_currency_config') ? todo_get_currency_config() : [];
        $currency = isset($attrs['currency']) ? $attrs['currency'] : (!empty($cfg['display_symbol']) ? $cfg['display_symbol'] : (!empty($cfg['symbol']) ? $cfg['symbol'] : ''));
        
        return $currency . sprintf($format, floatval($total));
    }
    
    /**
     * 解析收藏数量标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_favorites_count($attrs = [], $data = []) {
        if (isset($data['favorites_count'])) {
            return $data['favorites_count'];
        }
        
        // 从数据库获取收藏数量
        $user = $this->get_current_user_data();
        if (!$user) {
            return '0';
        }
        
        if (function_exists('todo_get_favorites_count')) {
            return todo_get_favorites_count($user['ID']);
        }
        
        return '0';
    }

    public function parse_favorite_products_count($attrs = [], $data = []) {
        if (isset($data['favorite_products_count'])) {
            return $data['favorite_products_count'];
        }
        $user = $this->get_current_user_data();
        if (!$user) return '0';
        global $tododb;
        $count = $tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}member_favorites WHERE user_id = ? AND object_type = 'product'", [$user['ID']]);
        return (string)($count ?: 0);
    }

    public function parse_favorite_articles_count($attrs = [], $data = []) {
        if (isset($data['favorite_articles_count'])) {
            return $data['favorite_articles_count'];
        }
        $user = $this->get_current_user_data();
        if (!$user) return '0';
        global $tododb;
        $count = $tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}member_favorites WHERE user_id = ? AND object_type = 'post'", [$user['ID']]);
        return (string)($count ?: 0);
    }
    
    /**
     * 解析文章标题标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_article_title($attrs = [], $data = []) {
        if (!empty($data['article_title'])) {
            return (string) $data['article_title'];
        }
        if (isset($data['article']['title'])) {
            return (string) $data['article']['title'];
        }
        if (isset($data['title'])) {
            return (string) $data['title'];
        }
        if (isset($data['post_title'])) {
            return (string) $data['post_title'];
        }
        return isset($attrs['default']) ? $attrs['default'] : '';
    }

    /**
     * 解析商品分类标签
     */
    public function parse_product_category($attrs = [], $data = []) {
        if (!empty($data['product_category'])) {
            return (string) $data['product_category'];
        }
        if (!empty($data['category'])) {
            return (string) $data['category'];
        }
        if (!empty($data['product_id'])) {
            $pid = intval($data['product_id']);
            $categories = function_exists('get_the_terms') ? (get_the_terms($pid, 'product_category') ?: get_the_terms($pid, 'category')) : [];
            if (!empty($categories) && is_array($categories)) {
                $first_cat = reset($categories);
                $cat_name = is_object($first_cat) ? ($first_cat->name ?? __('未分类')) : (is_array($first_cat) ? ($first_cat['name'] ?? __('未分类')) : __('未分类'));
                return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($cat_name) : $cat_name;
            }
        }
        return isset($attrs['default']) ? $attrs['default'] : __('未分类');
    }

    /**
     * 解析文章分类标签
     */
    public function parse_article_category($attrs = [], $data = []) {
        if (!empty($data['article_category'])) {
            return (string) $data['article_category'];
        }
        if (!empty($data['category'])) {
            return (string) $data['category'];
        }
        if (!empty($data['article_id'])) {
            $aid = intval($data['article_id']);
            $categories = function_exists('get_the_terms') ? get_the_terms($aid, 'category') : [];
            if (!empty($categories) && is_array($categories)) {
                $first_cat = reset($categories);
                $cat_name = is_object($first_cat) ? ($first_cat->name ?? __('未分类')) : (is_array($first_cat) ? ($first_cat['name'] ?? __('未分类')) : __('未分类'));
                return function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($cat_name) : $cat_name;
            }
        }
        return isset($attrs['default']) ? $attrs['default'] : __('未分类');
    }
    
    /**
     * 解析文章摘要标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_article_excerpt($attrs = [], $data = []) {
        $excerpt = null;
        if (isset($data['article']['excerpt'])) {
            $excerpt = $data['article']['excerpt'];
        } elseif (isset($data['excerpt'])) {
            $excerpt = $data['excerpt'];
        }
        
        if (!$excerpt) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        $length = isset($attrs['length']) ? intval($attrs['length']) : 0;
        if ($length > 0 && mb_strlen($excerpt) > $length) {
            $excerpt = mb_substr($excerpt, 0, $length) . '...';
        }
        
        return $excerpt;
    }
    
    /**
     * 解析文章日期标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_article_date($attrs = [], $data = []) {
        $date = null;
        if (isset($data['article']['date'])) {
            $date = $data['article']['date'];
        } elseif (isset($data['date'])) {
            $date = $data['date'];
        }
        
        if (!$date) {
            return isset($attrs['default']) ? $attrs['default'] : '';
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : 'Y-m-d';
        return date($format, is_numeric($date) ? $date : strtotime($date));
    }
    
    /**
     * 解析钱包URL标签
     */
    public function parse_wallet_url($attrs = [], $data = []) {
        if (function_exists('todo_get_user_page_url')) {
            return todo_get_user_page_url('wallet');
        }
        return function_exists('todo_get_site_url') ? todo_get_site_url('/?user=wallet') : '/?user=wallet';
    }

    /**
     * 解析钱包余额标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_wallet_balance($attrs = [], $data = []) {
        if (isset($data['wallet_balance'])) {
            $balance = $data['wallet_balance'];
        } else {
            $user = $this->get_current_user_data();
            if (!$user) {
                $balance = 0;
            } else {
                $balance = isset($user['wallet_balance']) ? $user['wallet_balance'] : 0;
            }
        }
        
        $format_type = isset($attrs['format_type']) ? $attrs['format_type'] : 'currency';
        if (function_exists('todo_format_price')) {
            return todo_format_price($balance, $format_type);
        }
        
        $format = isset($attrs['format']) ? $attrs['format'] : '%.2f';
        $cfg = function_exists('todo_get_currency_config') ? todo_get_currency_config() : [];
        $currency = isset($attrs['currency']) ? $attrs['currency'] : (!empty($cfg['display_symbol']) ? $cfg['display_symbol'] : (!empty($cfg['symbol']) ? $cfg['symbol'] : ''));
        
        return $currency . sprintf($format, floatval($balance));
    }
    
    /**
     * 解析优惠券数量标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_coupons_count($attrs = [], $data = []) {
        $status = isset($attrs['status']) ? sanitize_text_field($attrs['status']) : 'available';
        $user = $this->get_current_user_data();
        $user_id = ($user && (isset($user['ID']) || isset($user['id']))) ? intval($user['ID'] ?? $user['id']) : 0;
        if ($user_id <= 0 && function_exists('todo_get_current_user_id')) {
            $user_id = todo_get_current_user_id();
        }
        if ($user_id <= 0) {
            return '0';
        }

        global $tododb;
        $now_ts = current_time('timestamp');
        $coupons_table = $tododb->prefix . 'coupons';
        $user_coupons_table = $tododb->prefix . 'user_coupons';

        $rows = $tododb->get_results(
            "SELECT c.*, uc.status AS uc_status, uc.assigned_at, uc.used_at
             FROM {$user_coupons_table} uc
             INNER JOIN {$coupons_table} c ON c.id = uc.coupon_id
             WHERE uc.user_id = ?",
            [$user_id],
            'ARRAY_A'
        );

        $cnt = 0;
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $uc_status = isset($r['uc_status']) ? (string)$r['uc_status'] : 'unused';
                $start_ts = !empty($r['start_date']) ? strtotime($r['start_date']) : 0;
                $end_ts = !empty($r['end_date']) ? strtotime($r['end_date']) : 0;
                $is_expired = ($end_ts > 0 && $now_ts > $end_ts);
                $is_expiring = ($end_ts > 0 && !$is_expired && ($end_ts - $now_ts) <= 3 * 86400);
                $is_enabled = !empty($r['status']);

                $my_status = 'available';
                if ($uc_status === 'used') {
                    $my_status = 'used';
                } elseif (!$is_enabled || $is_expired) {
                    $my_status = 'expired';
                }

                if ($status === 'available' && $my_status === 'available') {
                    $cnt++;
                } elseif ($status === 'used' && $my_status === 'used') {
                    $cnt++;
                } elseif ($status === 'expired' && $my_status === 'expired') {
                    $cnt++;
                } elseif ($status === 'expiring' && $my_status === 'available' && $is_expiring) {
                    $cnt++;
                } elseif ($status === 'all') {
                    $cnt++;
                }
            }
        }
        return (string) $cnt;
    }

    /**
     * 解析用户优惠券列表循环块标签
     */
    public function parse_user_coupons($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        $user_id = ($user && (isset($user['ID']) || isset($user['id']))) ? intval($user['ID'] ?? $user['id']) : 0;
        if ($user_id <= 0 && function_exists('todo_get_current_user_id')) {
            $user_id = todo_get_current_user_id();
        }
        if ($user_id <= 0) {
            return CMS_Template_Compiler_V2::render_string($content, array_merge($data, ['coupons' => []]));
        }

        $target_status = isset($attrs['status']) ? sanitize_text_field($attrs['status']) : 'available';

        global $tododb;
        $now_ts = current_time('timestamp');
        $coupons_table = $tododb->prefix . 'coupons';
        $user_coupons_table = $tododb->prefix . 'user_coupons';

        $rows = $tododb->get_results(
            "SELECT c.*, uc.id AS uc_id, uc.status AS uc_status, uc.assigned_at AS uc_assigned_at, uc.used_at AS uc_used_at, uc.order_id AS uc_order_id
             FROM {$user_coupons_table} uc
             INNER JOIN {$coupons_table} c ON c.id = uc.coupon_id
             WHERE uc.user_id = ?
             ORDER BY uc.assigned_at DESC, uc.id DESC",
            [$user_id],
            'ARRAY_A'
        );

        $coupons = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $uc_status = isset($r['uc_status']) ? (string)$r['uc_status'] : 'unused';
                $start_ts = !empty($r['start_date']) ? strtotime($r['start_date']) : 0;
                $end_ts = !empty($r['end_date']) ? strtotime($r['end_date']) : 0;
                $is_expired = ($end_ts > 0 && $now_ts > $end_ts);
                $is_expiring = ($end_ts > 0 && !$is_expired && ($end_ts - $now_ts) <= 3 * 86400);
                $is_enabled = !empty($r['status']);

                $my_status = 'available';
                if ($uc_status === 'used') {
                    $my_status = 'used';
                } elseif (!$is_enabled || $is_expired) {
                    $my_status = 'expired';
                }

                if ($target_status !== 'all' && $target_status !== $my_status) {
                    continue;
                }

                $c_type = 'amount';
                $raw_type = $r['type'] ?? 1;
                if ($raw_type == 2 || $raw_type === 'percent') {
                    $c_type = 'percent';
                }

                $val_num = floatval($r['value'] ?? 0);
                $min_amt = floatval($r['min_amount'] ?? 0);

                if ($c_type === 'amount') {
                    $exchanged_val = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($val_num) : $val_num;
                    $val_display = function_exists('todo_format_price') ? todo_format_price($val_num, 'number') : number_format($exchanged_val, 2, '.', '');
                } else {
                    $val_display = (string) (intval($val_num * 10) / 10);
                }

                if ($min_amt > 0) {
                    $exchanged_min = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($min_amt) : $min_amt;
                    $min_amt_display = function_exists('todo_format_price') ? todo_format_price($min_amt, 'number') : number_format($exchanged_min, 2, '.', '');
                } else {
                    $min_amt_display = 0;
                }

                $raw_name = (string) ($r['name'] ?? '');
                $c_name = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_name) : $raw_name;

                $raw_desc = trim($r['description'] ?? '');
                if ($raw_desc !== '' && $raw_desc !== $raw_name) {
                    $c_desc = function_exists('todo_parse_inline_translations') ? todo_parse_inline_translations($raw_desc) : $raw_desc;
                } else {
                    $curr_sym = function_exists('todo_get_currency_config') 
                        ? ((todo_get_currency_config()['display_symbol'] ?? '') ?: (todo_get_currency_config()['symbol'] ?? '')) 
                        : (function_exists('todo_get_default_currency_config') ? ((todo_get_default_currency_config()['display_symbol'] ?? '') ?: (todo_get_default_currency_config()['symbol'] ?? '')) : '');

                    $desc_parts = [];
                    if ($min_amt > 0) {
                        $min_formatted = function_exists('todo_format_price') ? todo_format_price($min_amt) : ($curr_sym . $min_amt_display);
                        $desc_parts[] = sprintf(__('满 %s 可用'), $min_formatted);
                    } else {
                        $desc_parts[] = __('无门槛');
                    }

                    if ($c_type === 'amount') {
                        $val_formatted = function_exists('todo_format_price') ? todo_format_price($val_num) : ($curr_sym . $val_display);
                        $desc_parts[] = sprintf(__('立减 %s'), $val_formatted);
                    } else {
                        $max_disc_str = '';
                        if (!empty($r['max_discount']) && floatval($r['max_discount']) > 0) {
                            $max_disc_val = floatval($r['max_discount']);
                            $max_disc_formatted = function_exists('todo_format_price') ? todo_format_price($max_disc_val) : ($curr_sym . $max_disc_val);
                            $max_disc_str = sprintf(__('（最高减 %s）'), $max_disc_formatted);
                        }
                        $desc_parts[] = sprintf(__('享 %s 折%s'), $val_display, $max_disc_str);
                    }

                    if (!empty($r['applicable_products'])) {
                        $desc_parts[] = __('指定商品可用');
                    } else {
                        $desc_parts[] = __('全场通用');
                    }

                    $c_desc = implode('，', $desc_parts);
                }

                $coupons[] = [
                    'id' => (int) $r['id'],
                    'user_coupon_id' => (int) ($r['uc_id'] ?? $r['id']),
                    'code' => (string) ($r['code'] ?? ''),
                    'name' => (string) $c_name,
                    'description' => (string) $c_desc,
                    'type' => $c_type,
                    'value' => $val_display,
                    'min_amount' => $min_amt_display,
                    'expire_time' => !empty($r['end_date']) ? date('Y-m-d', strtotime($r['end_date'])) : __('永久有效'),
                    'used_time' => !empty($r['uc_used_at']) ? date('Y-m-d H:i', strtotime($r['uc_used_at'])) : '',
                    'order_no' => !empty($r['uc_order_id']) ? (string)$r['uc_order_id'] : '',
                    'applicable_products' => !empty($r['applicable_products']) ? (string)$r['applicable_products'] : '',
                    'is_transferable' => !empty($r['is_transferable']),
                    'is_expiring' => $is_expiring,
                    'my_status' => $my_status
                ];
            }
        }

        if (empty($coupons)) {
            return '';
        }

        $output = '';
        foreach ($coupons as $c) {
            $item_data = array_merge($data, (array) $c);
            $item_data['coupon'] = (array) $c;
            foreach ($c as $ck => $cv) {
                $item_data['coupon_' . $ck] = $cv;
            }
            $output .= CMS_Template_Compiler_V2::render_string($content, $item_data);
        }
        return $output;
    }
    
    /**
     * 解析当前页面标识
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_current_page($attrs = [], $data = []) {
        if (isset($data['current_page'])) {
            return $data['current_page'];
        }

        if (isset($_GET['user'])) {
            $u = sanitize_text_field((string) $_GET['user']);
            if ($u !== '') {
                return $u;
            }
        }
        
        // 从URL获取当前页面
        $script_name = basename($_SERVER['SCRIPT_NAME'], '.php');
        return $script_name;
    }
    
    /**
     * 解析购物车商品小计标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_subtotal_format($attrs = [], $data = []) {
        if (isset($data['cart']) && isset($data['cart']['subtotal'])) {
            return number_format($data['cart']['subtotal'], 2);
        }
        
        // 获取购物车详细信息
        if (class_exists('Todo_Cart_Manager')) {
            $cart = Todo_Cart_Manager::get_cart_details();
            $subtotal = 0;
            if (isset($cart['items']) && is_array($cart['items'])) {
                foreach ($cart['items'] as $item) {
                    $subtotal += floatval($item['price']) * intval($item['quantity']);
                }
            }
            return number_format($subtotal, 2);
        }
        
        return '0.00';
    }
    
    /**
     * 解析购物车运费标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_shipping_fee_format($attrs = [], $data = []) {
        if (isset($data['cart']) && isset($data['cart']['shipping_fee'])) {
            return number_format($data['cart']['shipping_fee'], 2);
        }
        
        // 默认运费为0
        return '0.00';
    }
    
    /**
     * 解析购物车总计标签
     *
     * @param array $attrs 标签属性
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_total_format($attrs = [], $data = []) {
        if (isset($data['cart']) && isset($data['cart']['total'])) {
            return number_format($data['cart']['total'], 2);
        }
        
        // 计算总计
        if (class_exists('Todo_Cart_Manager')) {
            $cart = Todo_Cart_Manager::get_cart_details();
            $subtotal = 0;
            if (isset($cart['items']) && is_array($cart['items'])) {
                foreach ($cart['items'] as $item) {
                    $subtotal += floatval($item['price']) * intval($item['quantity']);
                }
            }
            $shipping_fee = 0; // 默认运费为0
            $total = $subtotal + $shipping_fee;
            return number_format($total, 2);
        }
        
        return '0.00';
    }
    
    /**
     * 解析购物车URL标签
     *
     * @param array $matches 正则匹配结果
     * @param array $data 模板数据
     * @return string
     */
    public function parse_cart_url($matches, $data) {
        // 返回购物车AJAX处理URL
        return todo_admin_url('admin-ajax.php?action=cart');
    }
    
    /**
     * 解析结算URL标签
     *
     * @param array $matches 正则匹配结果
     * @param array $data 模板数据
     * @return string
     */
    public function parse_checkout_url($matches, $data) {
        if (function_exists('todo_get_user_page_url')) {
            return todo_get_user_page_url('checkout');
        }
        if (function_exists('todo_get_user_center_url')) {
            return todo_get_user_center_url('checkout');
        }
        return '/?user=checkout';
    }
    
    /**
     * 解析用户中心URL标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_url($attrs = [], $content = '', $data = []) {
        $page = isset($attrs['page']) ? $attrs['page'] : '';
        if (!empty($page)) {
            if (function_exists('todo_get_user_page_url')) {
                return rtrim(todo_get_user_page_url($page), '/');
            }
            if (function_exists('todo_get_user_center_url')) {
                return rtrim(todo_get_user_center_url($page), '/');
            }
            return '/?user=' . urlencode((string) $page);
        }

        if (function_exists('todo_get_user_center_url')) {
            return rtrim(todo_get_user_center_url(), '/');
        }

        return '/?user=profile';
    }
    
    /**
     * 解析商城URL标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_shop_url($attrs = [], $content = '', $data = []) {
        // 返回商城首页URL（语言感知）
        $base = function_exists('todo_home_url') ? todo_home_url('/shop/') : (function_exists('home_url') ? home_url('/shop/') : '/shop/');
        $locale = function_exists('current_locale') ? current_locale() : '';
        if (function_exists('get_language_url') && !empty($locale)) {
            return get_language_url($locale, $base);
        }
        return $base;
    }
    
    /**
     * 解析是否为用户页面标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_is_user_page($attrs = [], $content = '', $data = []) {
        $page = isset($attrs['page']) ? $attrs['page'] : '';
        
        // 从URL或数据中获取当前页面
        $current_page = '';
        if (isset($data['current_page'])) {
            $current_page = $data['current_page'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $current_page = basename($uri, '.php');
            $current_page = basename($current_page, '.htm');
        }
        
        if (empty($page)) {
            // 如果没有指定页面，检查是否在用户中心
            return (strpos($current_page, 'user') !== false || strpos($current_page, 'login') !== false || strpos($current_page, 'register') !== false) ? '1' : '0';
        }
        
        return ($current_page === $page) ? '1' : '0';
    }
    

    
    /**
     * 解析用户订单数量标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_order_count($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        $user_id = ($user && isset($user['ID'])) ? intval($user['ID']) : 0;
        if (function_exists('is_user_logged_in') && !is_user_logged_in()) {
            $user_id = 0;
        }
        
        $status = isset($attrs['status']) ? $attrs['status'] : '';
        if ($status === '' && isset($_GET['status'])) {
            $status = sanitize_text_field((string) $_GET['status']);
        }
        
        if (function_exists('todo_get_user_order_count')) {
            return todo_get_user_order_count($user_id, $status);
        }
        
        return '0';
    }
    
    /**
     * 解析用户订单数量标签（复数形式）
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_orders_count($attrs = [], $content = '', $data = []) {
        return $this->parse_user_order_count($attrs, $content, $data);
    }
    
    /**
     * 解析用户购物车数量标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_cart_count($attrs = [], $content = '', $data = []) {
        return $this->parse_cart_count($attrs, $data);
    }
    
    /**
     * 解析用户购物车总金额标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_cart_total($attrs = [], $content = '', $data = []) {
        return $this->parse_cart_total($attrs, $data);
    }
    
    /**
     * 解析用户收藏数量标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_favorites_count($attrs = [], $content = '', $data = []) {
        return $this->parse_favorites_count($attrs, $data);
    }
    
    /**
     * 解析用户地址数量标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_address_count($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return '0';
        }

        $uid = 0;
        if (is_array($user)) {
            if (isset($user['ID'])) {
                $uid = (int) $user['ID'];
            } elseif (isset($user['id'])) {
                $uid = (int) $user['id'];
            }
        } elseif (is_object($user)) {
            if (isset($user->ID)) {
                $uid = (int) $user->ID;
            } elseif (isset($user->id)) {
                $uid = (int) $user->id;
            }
        }

        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }

        if ($uid <= 0) {
            return '0';
        }

        if (function_exists('todo_get_user_address_count')) {
            return (string) todo_get_user_address_count($uid);
        }

        global $tododb;
        if (!$tododb) {
            return '0';
        }

        try {
            return (string) ((int) ($tododb->get_var("SELECT COUNT(*) FROM {$tododb->prefix}member_addresses WHERE user_id = ?", [$uid]) ?: 0));
        } catch (Throwable $e) {
            return '0';
        }
    }
    

    
    /**
     * 解析用户钱包余额标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_wallet_balance($attrs = [], $content = '', $data = []) {
        return $this->parse_wallet_balance($attrs, $data);
    }
    
    /**
     * 解析用户优惠券数量标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容（未使用）
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_coupon_count($attrs = [], $content = '', $data = []) {
        return $this->parse_coupons_count($attrs, $data);
    }
    
    /**
     * 解析用户订单列表标签
     *
     * @param array $attrs 标签属性
     * @param string $content 标签内容
     * @param array $data 模板数据
     * @return string
     */
    public function parse_user_orders($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        $user_id = ($user && isset($user['ID'])) ? intval($user['ID']) : 0;
        if (function_exists('is_user_logged_in') && !is_user_logged_in()) {
            $user_id = 0;
        }
        
        $status = isset($attrs['status']) ? $attrs['status'] : '';
        if ($status === '' && isset($_GET['status'])) {
            $status = sanitize_text_field((string) $_GET['status']);
        }
        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 10;
        $page = isset($attrs['page']) ? intval($attrs['page']) : 0;
        if ($page <= 0) {
            $page = isset($_GET['paged']) ? intval($_GET['paged']) : 0;
        }
        if ($page <= 0) {
            $page = isset($_GET['p']) ? intval($_GET['p']) : 0;
        }
        if ($page <= 0) {
            $page = 1;
        }
        
        $offset = max(0, ($page - 1) * $limit);

        if (function_exists('todo_get_user_orders')) {
            $orders = todo_get_user_orders($user_id, $status, $limit, $offset);
            if (empty($orders)) {
                return '';
            }
            
            $output = '';            foreach ($orders as $order) {
                // 准备每行的数据，确保可以通过 {order_xxx} 访问
                $order_data = array_merge($data, (array) $order);
                $order_data['order'] = (array) $order;
                $output .= CMS_Template_Compiler_V2::render_string($content, $order_data);
            }
            return $output;
        }
        
        return '';
    }

    public function parse_user_addresses($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return '';
        }

        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 0;
        $page = isset($attrs['page']) ? intval($attrs['page']) : 1;

        $uid = 0;
        if (is_array($user)) {
            if (isset($user['ID'])) {
                $uid = (int) $user['ID'];
            } elseif (isset($user['id'])) {
                $uid = (int) $user['id'];
            }
        } elseif (is_object($user)) {
            if (isset($user->ID)) {
                $uid = (int) $user->ID;
            } elseif (isset($user->id)) {
                $uid = (int) $user->id;
            }
        }
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        if ($uid <= 0) {
            return '';
        }

        $addresses = [];
        if (function_exists('todo_get_user_addresses')) {
            $addresses = todo_get_user_addresses($uid, $limit, $page);
        } elseif (function_exists('get_user_addresses')) {
            $addresses = get_user_addresses($uid);
        }
        if (empty($addresses)) {
            return '';
        }

        $output = '';
        foreach ($addresses as $address) {
            $loop_data = array_merge($data, (array) $address);
            $loop_data['address'] = (array) $address;
            $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
        }

        return $output;
    }

    public function parse_user_default_address_text($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) {
            return '';
        }

        $uid = 0;
        if (is_array($user)) {
            if (isset($user['ID'])) {
                $uid = (int) $user['ID'];
            } elseif (isset($user['id'])) {
                $uid = (int) $user['id'];
            }
        } elseif (is_object($user)) {
            if (isset($user->ID)) {
                $uid = (int) $user->ID;
            } elseif (isset($user->id)) {
                $uid = (int) $user->id;
            }
        }
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        if ($uid <= 0) {
            return '';
        }

        $row = null;
        if (function_exists('get_user_default_address')) {
            $row = get_user_default_address($uid);
        } else {
            global $tododb;
            if ($tododb) {
                $table = $tododb->prefix . 'member_addresses';
                $row = $tododb->get_row(
                    "SELECT * FROM {$table} WHERE user_id = ? AND is_default = 1 ORDER BY created_at ASC, id ASC LIMIT 1",
                    [$uid],
                    'ARRAY_A'
                );
            }
        }

        if (empty($row) || !is_array($row)) {
            return '';
        }

        $province = $row['province'] ?? '';
        $city = $row['city'] ?? '';
        $district = $row['district'] ?? '';
        $detail = trim((string) ($row['address'] ?? ($row['detailed_address'] ?? '')));
        $zip = trim((string) ($row['zipcode'] ?? ($row['postal_code'] ?? '')));

        $has_delimiter = (strpos($province, ' | ') !== false);
        $region_type = $row['region_type'] ?? ($has_delimiter ? 'intl' : 'cn');

        if ($has_delimiter || $region_type === 'intl') {
            $country = '';
            $state = '';
            if ($has_delimiter) {
                list($c_tmp, $s_tmp) = explode(' | ', $province, 2);
                $country = trim($c_tmp);
                $state = trim($s_tmp);
            } else {
                $country = trim($province);
            }
            
            $parts = [];
            if ($detail !== '') $parts[] = $detail;
            if ($city !== '') $parts[] = $city;
            $state_zip = $state;
            if ($zip !== '') {
                $state_zip = $state_zip !== '' ? ($state_zip . ' ' . $zip) : $zip;
            }
            if ($state_zip !== '') $parts[] = $state_zip;
            if ($country !== '') $parts[] = $country;
            $text = implode(', ', $parts);
        } else {
            $p_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($province) : '';
            $c_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($city) : '';
            $d_name = function_exists('todo_regions_find_name') ? todo_regions_find_name($district) : '';
            $prov_disp = !empty($p_name) ? $p_name : $province;
            $city_disp = !empty($c_name) ? $c_name : $city;
            $dist_disp = !empty($d_name) ? $d_name : $district;
            
            $parts = array_filter([$prov_disp, $city_disp, $dist_disp, $detail]);
            $text = implode(' ', $parts);
        }
        if ($text === '') {
            return '';
        }

        if (function_exists('esc_html')) {
            return esc_html($text);
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    


    /**
     * 解析用户收藏商品列表
     */
    public function parse_user_favorite_products($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) return '';
        
        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 50;
        if (function_exists('todo_get_user_favorite_products')) {
            $products = todo_get_user_favorite_products($user['ID'], $limit);
            if (empty($products)) return '';
            
            $output = '';
            foreach ($products as $product) {
                $loop_data = array_merge($data, (array)$product);
                $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
            }
            return $output;
        }
        return '';
    }

    /**
     * 解析用户收藏文章列表
     */
    public function parse_user_favorite_articles($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        if (!$user) return '';
        
        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 50;
        if (function_exists('todo_get_user_favorite_articles')) {
            $articles = todo_get_user_favorite_articles($user['ID'], $limit);
            if (empty($articles)) return '';
            
            $output = '';
            foreach ($articles as $article) {
                $loop_data = array_merge($data, (array)$article);
                $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
            }
            return $output;
        }
        return '';
    }

    /**
     * 解析购物车列表（块标签版）
     */
    public function parse_cart_items($attrs = [], $content = '', $data = []) {
        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : 10;
        
        $items = isset($data['cart_items']) && is_array($data['cart_items']) ? $data['cart_items'] : [];
        if (empty($items) && class_exists('Todo_Cart_Manager')) {
            $cart = Todo_Cart_Manager::get_cart_details();
            $items = (isset($cart['items']) && is_array($cart['items'])) ? $cart['items'] : [];
        }

            if (empty($items)) return '';
            
            $output = '';            $i = 0;
            foreach ($items as $cart_key => $item) {
                if ($i >= $limit) {
                    break;
                }
                $i++;

                $it = is_array($item) ? $item : (array) $item;
                $product_id = intval($it['product_id'] ?? 0);
                $qty = intval($it['quantity'] ?? 0);
                $price = floatval($it['price'] ?? ($it['current_price'] ?? ($it['regular_price'] ?? ($it['sale_price'] ?? 0))));
                $total = $price * max(0, $qty);

                $specs = $it['specs'] ?? [];
                if (is_string($specs)) {
                    $decoded = json_decode($specs, true);
                    $specs = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($specs)) {
                    $specs = [];
                }
                $specs_text = '';
                if (!empty($specs)) {
                    if (function_exists('todo_format_specs_text')) {
                        $specs_text = todo_format_specs_text($specs, $product_id);
                    } else {
                        $parts = [];
                        foreach ($specs as $k => $v) {
                            $k = is_string($k) ? trim($k) : '';
                            $v = is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : '');
                            if ($k === '' || $v === '') continue;
                            $parts[] = $k . ':' . $v;
                        }
                        $specs_text = implode('，', $parts);
                    }
                }

                $title = isset($it['title']) ? (string) $it['title'] : '';
                $image = isset($it['image']) ? (string) $it['image'] : '';
                if ($image !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                    $image = todo_media_url_to_runtime_absolute($image);
                }
                $stock = isset($it['stock']) ? intval($it['stock']) : 0;
                $url = ($product_id > 0) ? (function_exists('todo_get_permalink') ? (string) todo_get_permalink($product_id) : (function_exists('get_permalink') ? (string) get_permalink($product_id) : '')) : '';

                // 原价与批发/会员折扣计算
                $regular_price = floatval($it['regular_price'] ?? ($it['original_price'] ?? $price));
                if ($regular_price < $price) {
                    $regular_price = $price;
                }
                $is_wholesale = ($regular_price > $price + 0.001);
                $original_price = $is_wholesale ? number_format($regular_price, 2) : '';

                // 单位文本：对应小程序 item.unit_text
                $unit_qty = floatval($it['unit_qty'] ?? 0);
                $unit = isset($it['unit']) ? (string) $it['unit'] : '';
                $unit_text = '';
                if ($unit_qty > 0 && $unit !== '') {
                    $unit_qty_display = (floor($unit_qty) == $unit_qty) ? number_format($unit_qty, 0) : rtrim(rtrim(number_format($unit_qty, 2), '0'), '.');
                    $unit_text = $unit_qty_display . $unit;
                } elseif ($unit !== '') {
                    $unit_text = $unit;
                }

                $category_id = 0;
                $category_ids = [];
                if ($product_id > 0 && function_exists('get_the_category')) {
                    $cats = get_the_category($product_id);
                    if (!empty($cats) && is_array($cats)) {
                        foreach ($cats as $c) {
                            $cid = is_object($c) ? intval($c->term_id ?? 0) : (is_array($c) ? intval($c['term_id'] ?? 0) : 0);
                            if ($cid > 0) {
                                $category_ids[] = (string) $cid;
                            }
                        }
                        if (!empty($category_ids)) {
                            $category_id = intval($category_ids[0]);
                        }
                    }
                }

                $exchanged_price = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($price) : $price;
                $exchanged_total = $exchanged_price * max(0, $qty);
                $formatted_total = function_exists('todo_format_price') ? todo_format_price($total, 'number') : number_format($exchanged_total, 2);

                $exchanged_regular_price = function_exists('todo_apply_currency_exchange') ? todo_apply_currency_exchange($regular_price) : $regular_price;
                $original_total_val = $exchanged_regular_price * max(0, $qty);
                $saved_amount_val = max(0, $original_total_val - $exchanged_total);

                $formatted_orig_unit = function_exists('todo_format_price') ? todo_format_price($regular_price, 'number') : number_format($exchanged_regular_price, 2);
                $formatted_orig_total = function_exists('todo_format_price') ? todo_format_price($regular_price * max(0, $qty), 'number') : number_format($original_total_val, 2);
                $formatted_saved_total = function_exists('todo_format_price') ? todo_format_price(($regular_price - $price) * max(0, $qty), 'number') : number_format($saved_amount_val, 2);

                $loop_data = array_merge($data, $it, [
                    'item_id' => (string) $cart_key,
                    'image' => $image,
                    'item_quantity' => $qty,
                    'item_total' => $formatted_total,
                    'total_price_val' => $exchanged_total,
                    'total_price_format' => $formatted_total,
                    'original_unit_price_format' => $formatted_orig_unit,
                    'original_total_price_format' => $formatted_orig_total,
                    'saved_amount_format' => $formatted_saved_total,
                    'is_wholesale' => $is_wholesale,
                    'discount_label' => $is_wholesale ? (function_exists('todo_get_user_discount_label') ? todo_get_user_discount_label() : __('高级批发价')) : '',
                    'category_id' => $category_id,
                    'category_ids' => implode(',', $category_ids),
                    'product_id' => $product_id,
                    'product_title' => $title,
                    'product_name' => $title,
                    'product_image' => $image,
                    'product_featured_image' => $image,
                    'product_price' => function_exists('todo_format_price') ? todo_format_price($price, 'currency') : '$' . number_format($exchanged_price, 2),
                    'product_original_price' => $original_price,
                    'product_stock' => $stock,
                    'spec_name' => $specs_text,
                    'specs_text' => $specs_text,
                    'product_specs' => $specs_text,
                    'product_url' => $url,
                    'product_unit_text' => $unit_text,
                    'product_type' => $it['product_type'] ?? 'physical',
                ]);

                if (class_exists('Todo_Error_Handler')) {
                    Todo_Error_Handler::log(
                        '[Checkout Debug] Rendering Cart Item Tag Block',
                        Todo_Error_Handler::LEVEL_INFO,
                        [
                            'product_id' => $product_id,
                            'title' => $title,
                            'price' => $price,
                            'regular_price' => $regular_price,
                            'total_price_format' => $formatted_total,
                            'is_wholesale' => $is_wholesale,
                            'discount_label' => $loop_data['discount_label']
                        ]
                    );
                }

                $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
            }
            return $output;
    }

    /**
     * 解析钱包交易明细列表（块标签 {{todo:wallet_transactions}}）
     */
    public function parse_wallet_transactions($attrs = [], $content = '', $data = []) {
        $user = $this->get_current_user_data();
        $user_id = ($user && isset($user['ID'])) ? intval($user['ID']) : 0;
        if ($user_id <= 0) {
            return '';
        }

        global $tododb;
        if (!$tododb || !isset($tododb->prefix)) {
            return '';
        }

        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';
        $table_mb = $tododb->prefix . 'member_balance';
        $table_bl = $tododb->prefix . 'balance_logs';

        // 映射前端 Tab 分类到数据库中实际存储的类型集合
        $type_category_map = [
            'recharge' => ['recharge', 'admin_add', 'admin'],
            'consume'  => ['consume', 'order_pay', 'admin_deduct'],
            'refund'   => ['refund', 'order_refund'],
        ];

        $where_mb = ["user_id = %d"];
        $params_mb = [$user_id];
        if ($type_filter !== '' && $type_filter !== 'all') {
            if (isset($type_category_map[$type_filter])) {
                $matched_types = $type_category_map[$type_filter];
                $placeholders = implode(',', array_fill(0, count($matched_types), '%s'));
                $where_mb[] = "type IN ({$placeholders})";
                foreach ($matched_types as $mt) {
                    $params_mb[] = $mt;
                }
            } else {
                $where_mb[] = "type = %s";
                $params_mb[] = $type_filter;
            }
        }

        $where_sql = implode(' AND ', $where_mb);
        $rows_mb = [];
        $rows_bl = [];

        try {
            $prep_mb = $tododb->prepare("SELECT id, user_id, amount, balance_after, type, description, order_id AS related_id, (CASE WHEN type = 'consume' THEN 'order' ELSE 'recharge' END) AS related_type, created_at FROM {$table_mb} WHERE {$where_sql} ORDER BY id DESC LIMIT 50", ...$params_mb);
            $rows_mb = $tododb->get_results($prep_mb, ARRAY_A) ?: [];
        } catch (\Throwable $e) {}

        try {
            $prep_bl = $tododb->prepare("SELECT id, user_id, amount, balance_after, type, description, related_id, related_type, created_at FROM {$table_bl} WHERE {$where_sql} ORDER BY id DESC LIMIT 50", ...$params_mb);
            $rows_bl = $tododb->get_results($prep_bl, ARRAY_A) ?: [];
        } catch (\Throwable $e) {}

        $rows = array_merge($rows_mb, $rows_bl);
        if (!empty($rows)) {
            usort($rows, function($a, $b) {
                $t1 = strtotime($a['created_at'] ?? 0);
                $t2 = strtotime($b['created_at'] ?? 0);
                if ($t1 === $t2) {
                    return intval($b['id'] ?? 0) - intval($a['id'] ?? 0);
                }
                return $t2 - $t1;
            });
            $rows = array_slice($rows, 0, 50);
        }

        if (empty($rows) || !is_array($rows)) {
            return '';
        }

        $output = '';
        foreach ($rows as $row) {
            $amt = floatval($row['amount'] ?? 0);
            $amount_type = ($amt >= 0) ? 'income' : 'expense';
            $t_type = $row['type'] ?? 'other';

            // 类型名与逻辑文案适配
            if ($t_type === 'admin_add' || $t_type === 'recharge' || $t_type === 'admin') {
                $t_type = 'recharge';
                $amount_type = 'income';
            } elseif ($t_type === 'admin_deduct' || $t_type === 'order_pay' || $t_type === 'consume') {
                $t_type = 'consume';
                $amount_type = 'expense';
            } elseif ($t_type === 'order_refund' || $t_type === 'refund') {
                $t_type = 'refund';
                $amount_type = 'income';
            }

            $transaction_title = __('账户变动');
            if ($t_type === 'recharge') {
                $transaction_title = __('账户充值');
            } elseif ($t_type === 'consume') {
                $transaction_title = __('账户消费');
            } elseif ($t_type === 'refund') {
                $transaction_title = __('账户退款');
            }

            $rel_id = (!empty($row['related_id'])) ? (string)$row['related_id'] : '';
            $rel_type = (string)($row['related_type'] ?? '');
            $is_order_rel = ($t_type === 'consume' || $rel_type === 'order' || $rel_type === 'order_payment') && $t_type !== 'recharge' && $rel_type !== 'recharge';

            $loop_data = array_merge($data, [
                'transaction_id' => (string) ($row['id'] ?? ''),
                'transaction_type' => (string) $t_type,
                'transaction_title' => $transaction_title,
                'transaction_description' => $row['description'] ?? '',
                'transaction_time' => $row['created_at'] ?? '',
                'transaction_status' => 'success',
                'transaction_amount' => function_exists('todo_format_price') ? todo_format_price(abs($amt), 'number') : number_format(abs($amt), 2),
                'amount_type' => $amount_type,
                'transaction_sign' => ($amount_type === 'income' ? '+' : '-'),
                'balance_after' => function_exists('todo_format_price') ? todo_format_price(floatval($row['balance_after'] ?? 0), 'number') : number_format(floatval($row['balance_after'] ?? 0), 2),
                'order_number' => $is_order_rel ? $rel_id : '',
                'order_id' => $is_order_rel ? $rel_id : '',
            ]);

            $output .= CMS_Template_Compiler_V2::render_string($content, $loop_data);
        }

        return $output;
    }
}

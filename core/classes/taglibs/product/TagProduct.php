<?php
/**
 * 产品标签库示例
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

require_once ABSPATH . 'core/classes/class-template-taglib-base.php';

/**
 * 产品标签库类
 */
class Todo_Template_Taglib_Product extends Todo_Template_Taglib_Base {
    
    protected $taglib_name = 'product';
    protected $tag_prefix = 'product';
    
    /**
     * 注册标签
     */
    protected function register_tags() {
        $this->register_tag_handler('name', 'parse_product_name');
        $this->register_tag_handler('price', 'parse_product_price');
        $this->register_tag_handler('image', 'parse_product_image');
        $this->register_tag_handler('description', 'parse_product_description');
        $this->register_tag_handler('stock', 'parse_product_stock');
        $this->register_tag_handler('sku', 'parse_product_sku');
    }
    
    /**
     * 解析产品名称标签
     * 用法: {product:name}
     */
    public function parse_product_name($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '';
        }
        
        $name = is_array($product) ? ($product['name'] ?? $product['title'] ?? '') : ($product->name ?? $product->title ?? '');
        return esc_html($name);
    }
    
    /**
     * 解析产品价格标签
     * 用法: {product:price format="currency"}
     */
    public function parse_product_price($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '';
        }
        
        $price = is_array($product) ? ($product['price'] ?? 0) : ($product->price ?? 0);
        $format = isset($attrs['format']) ? $attrs['format'] : 'currency';
        
        if ($format === 'currency') {
            return function_exists('todo_format_price') ? todo_format_price((float)$price) : number_format((float)$price, 2);
        }
        
        return (string)$price;
    }
    
    /**
     * 解析产品图片标签
     */
    public function parse_product_image($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '';
        }
        
        $size = isset($attrs['size']) ? $attrs['size'] : 'medium';
        $image_url = '';
        
        if (is_array($product)) {
            $image_url = $product['image_' . $size] ?? $product['image'] ?? '';
        } else {
            $size_key = 'image_' . $size;
            $image_url = $product->$size_key ?? $product->image ?? '';
        }
        
        if ($image_url) {
            $alt = is_array($product) ? ($product['name'] ?? '') : ($product->name ?? '');
            $alt = esc_attr($alt);
            return '<img src="' . esc_url($image_url) . '" alt="' . $alt . '">';
        }
        
        return '';
    }
    
    /**
     * 解析产品描述标签
     * 用法: {product:description length="100"}
     */
    public function parse_product_description($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '';
        }
        
        $description = is_array($product) 
            ? ($product['description'] ?? $product['content'] ?? '') 
            : ($product->description ?? $product->content ?? '');
        
        if (isset($attrs['length'])) {
            $length = intval($attrs['length']);
            if ($length > 0 && strlen($description) > $length) {
                $description = mb_substr($description, 0, $length, 'UTF-8') . '...';
            }
        }
        
        return esc_html($description);
    }
    
    /**
     * 解析产品库存标签
     * 用法: {product:stock}
     */
    public function parse_product_stock($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '0';
        }
        
        $stock = is_array($product) ? ($product['stock'] ?? 0) : ($product->stock ?? 0);
        return (string)intval($stock);
    }
    
    /**
     * 解析产品SKU标签
     * 用法: {product:sku}
     */
    public function parse_product_sku($attrs, $content, $data) {
        $product = $this->get_data($data, 'product');
        if (!$product) {
            return '';
        }
        
        $sku = is_array($product) ? ($product['sku'] ?? '') : ($product->sku ?? '');
        return esc_html($sku);
    }
}


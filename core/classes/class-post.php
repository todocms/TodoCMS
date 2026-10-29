<?php
/**
 * 文章类
 *
 * 处理文章、页面和自定义文章类型的创建、更新和查询
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class CMS_Post {
    use Todo_Translatable;
    /**
     * 文章ID
     *
     * @var int
     */
    private $id = 0;
    
    /**
     * 文章数据
     *
     * @var array
     */
    private $data = [];
    
    /**
     * 文章元数据
     *
     * @var array
     */
    private $meta = [];
    
    /**
     * 文章分类
     *
     * @var array
     */
    private $categories = [];
    
    /**
     * 文章标签
     *
     * @var array
     */
    private $tags = [];
    
    /**
     * 构造函数
     *
     * @param int|array $id_or_data 文章ID或文章数据
     */
    public function __construct($id_or_data = 0) {
        if ($id_or_data) {
            $this->init($id_or_data);
        }
    }
    
    /**
     * 获取文章ID
     *
     * @return int 文章ID
     */
    public function get_id() {
        return $this->id;
    }
    
    /**
     * 设置文章ID
     *
     * @param int $id 文章ID
     */
    public function set_id($id) {
        $this->id = (int) $id;
    }
    
    /**
     * 初始化文章
     *
     * @param int|array $id_or_data 文章ID或文章数据
     * @return bool 是否成功初始化
     */
    public function init($id_or_data) {
        // 如果是数组，直接使用
        if (is_array($id_or_data)) {
            $this->data = $id_or_data;
            $this->id = isset($id_or_data['ID']) ? (int) $id_or_data['ID'] : 0;
            $this->load_meta();
            $this->load_terms();
            return true;
        }
        
        // 如果是数字，按ID查询
        if (is_numeric($id_or_data)) {
            return $this->load_by_id((int) $id_or_data);
        }
        
        return false;
    }
    
    /**
     * 根据ID加载文章
     *
     * @param int $id 文章ID
     * @return bool 是否成功加载
     */
    public function load_by_id($id) {
        global $tododb;
        
        $query = "SELECT * FROM " . TABLE_PREFIX . "posts WHERE ID = ? LIMIT 1";
        $result = $tododb->get_results($query, [$id]);
        
        if (!$result) {
            return false;
        }
        
        $this->data = is_object($result[0]) ? (array)$result[0] : (is_array($result[0]) ? $result[0] : []);
        $this->id = isset($this->data['ID']) ? (int)$this->data['ID'] : 0;
        $this->load_meta();
        $this->load_terms();
        
        return true;
    }
    
    /**
     * 加载文章元数据
     */
    private function load_meta() {
        if (!$this->id) {
            return;
        }
        
        global $tododb;
        
        $query = "SELECT field_key, field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = ?";
        $result = $tododb->get_results($query, [$this->id]);
        
        if ($result) {
            foreach ($result as $row) {
                $this->meta[$row->field_key] = maybe_unserialize($row->field_value);
            }
        }
    }
    
    /**
     * 加载文章分类和标签
     */
    private function load_terms() {
        if (!$this->id) {
            return;
        }
        
        // 这里可以添加加载分类和标签的逻辑
        // 暂时留空，避免更多错误
    }
    
    /**
     * 魔术方法，用于访问私有属性
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        if ($name === 'id') {
            return $this->id;
        }
        return null;
    }
    protected function get_translation_table_name() {
        return TABLE_PREFIX . 'post_translations';
    }
    public function get_title($locale = null) {
        return $this->get_translated('title', $locale, true);
    }
    public function get_content($locale = null) {
        return $this->get_translated('content', $locale, true);
    }
    public function get_excerpt($locale = null) {
        return $this->get_translated('excerpt', $locale, true);
    }
    public function get_slug($locale = null) {
        return $this->get_translated('slug', $locale, true);
    }
    public function get_seo_title($locale = null) {
        return $this->get_translated('seo_title', $locale, true);
    }
    public function get_seo_description($locale = null) {
        return $this->get_translated('seo_description', $locale, true);
    }
    public function get_seo_keywords($locale = null) {
        return $this->get_translated('seo_keywords', $locale, true);
    }
}
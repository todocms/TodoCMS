<?php
/**
 * ORM 基类
 * 
 * 提供基础的ORM功能，包括CRUD操作、关系管理和批量加载
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * ORM 基类
 */
abstract class Todo_ORM_Base {
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    protected static $db = null;
    
    /**
     * 表名（不含前缀）
     * @var string
     */
    protected static $table = '';
    
    /**
     * 主键字段名
     * @var string
     */
    protected static $primary_key = 'ID';
    
    /**
     * 模型数据
     * @var array
     */
    protected $attributes = [];
    
    /**
     * 原始数据（用于检测变更）
     * @var array
     */
    protected $original = [];
    
    /**
     * 是否为新记录
     * @var bool
     */
    protected $exists = false;
    
    /**
     * 已加载的关系
     * @var array
     */
    protected $loaded_relations = [];
    
    /**
     * 待批量加载的关系
     * @var array
     */
    protected static $pending_eager_loads = [];
    
    /**
     * 批量加载缓存
     * @var array
     */
    protected static $eager_cache = [];
    
    /**
     * 构造函数
     * 
     * @param array $attributes 属性数据
     * @param bool $exists 是否已存在
     */
    public function __construct($attributes = [], $exists = false) {
        $this->attributes = $attributes;
        $this->original = $attributes;
        $this->exists = $exists;
        
        if (self::$db === null) {
            global $tododb;
            self::$db = $tododb;
        }
    }
    
    /**
     * 获取表名（含前缀）
     * 
     * @return string
     */
    public static function get_table() {
        global $tododb;
        return $tododb->prefix . static::$table;
    }
    
    /**
     * 获取主键值
     * 
     * @return mixed
     */
    public function get_key() {
        $key = static::$primary_key;
        return isset($this->attributes[$key]) ? $this->attributes[$key] : null;
    }
    
    /**
     * 获取属性值
     * 
     * @param string $key 属性名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get_attribute($key, $default = null) {
        return isset($this->attributes[$key]) ? $this->attributes[$key] : $default;
    }
    
    /**
     * 设置属性值
     * 
     * @param string $key 属性名
     * @param mixed $value 属性值
     */
    public function set_attribute($key, $value) {
        $this->attributes[$key] = $value;
    }
    
    /**
     * 魔术方法：获取属性
     * 
     * @param string $key 属性名
     * @return mixed
     */
    public function __get($key) {
        // 检查是否有关系方法
        if (method_exists($this, $key)) {
            $reflection = new ReflectionMethod($this, $key);
            if ($reflection->isPublic() && $reflection->getNumberOfParameters() === 0) {
                return $this->$key();
            }
        }
        
        return $this->get_attribute($key);
    }
    
    /**
     * 魔术方法：设置属性
     * 
     * @param string $key 属性名
     * @param mixed $value 属性值
     */
    public function __set($key, $value) {
        $this->set_attribute($key, $value);
    }
    
    /**
     * 魔术方法：检查属性是否存在
     * 
     * @param string $key 属性名
     * @return bool
     */
    public function __isset($key) {
        return isset($this->attributes[$key]);
    }
    
    /**
     * 转换为数组
     * 
     * @return array
     */
    public function to_array() {
        return $this->attributes;
    }
    
    /**
     * 查找单个记录
     * 
     * @param mixed $id 主键值
     * @return static|null
     */
    public static function find($id) {
        $table = static::get_table();
        $primary_key = static::$primary_key;
        
        $query = "SELECT * FROM {$table} WHERE {$primary_key} = ? LIMIT 1";
        $result = self::$db->get_results($query, [$id]);
        
        if (empty($result)) {
            return null;
        }
        
        $data = is_object($result[0]) ? (array)$result[0] : $result[0];
        return new static($data, true);
    }
    
    /**
     * 查找多个记录
     * 
     * @param array $ids 主键值数组
     * @return static[]
     */
    public static function find_many($ids) {
        if (empty($ids)) {
            return [];
        }
        
        $ids = array_map('intval', $ids);
        $ids = array_unique($ids);
        
        $table = static::get_table();
        $primary_key = static::$primary_key;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $query = "SELECT * FROM {$table} WHERE {$primary_key} IN ({$placeholders})";
        $results = self::$db->get_results($query, $ids);
        
        $models = [];
        foreach ($results as $row) {
            $data = is_object($row) ? (array)$row : $row;
            $models[] = new static($data, true);
        }
        
        return $models;
    }
    
    /**
     * 创建查询构建器
     * 
     * @return Todo_Query_Builder
     */
    public static function query() {
        return new Todo_Query_Builder(static::class);
    }
    
    /**
     * 保存模型
     * 
     * @return bool
     */
    public function save() {
        $table = static::get_table();
        $primary_key = static::$primary_key;
        
        if ($this->exists) {
            // 更新
            $key_value = $this->get_key();
            $data = $this->attributes;
            unset($data[$primary_key]);
            
            return self::$db->update($table, $data, [$primary_key => $key_value]) !== false;
        } else {
            // 插入
            $result = self::$db->insert($table, $this->attributes);
            if ($result !== false) {
                $this->attributes[$primary_key] = $result;
                $this->exists = true;
                $this->original = $this->attributes;
                return true;
            }
            return false;
        }
    }
    
    /**
     * 删除模型
     * 
     * @return bool
     */
    public function delete() {
        if (!$this->exists) {
            return false;
        }
        
        $table = static::get_table();
        $primary_key = static::$primary_key;
        $key_value = $this->get_key();
        
        $result = self::$db->delete($table, [$primary_key => $key_value]);
        if ($result !== false) {
            $this->exists = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * 批量预加载关系
     * 
     * @param array $models 模型数组
     * @param string $relation 关系名
     * @param callable $loader 加载器回调
     */
    public static function eager_load($models, $relation, $loader) {
        if (empty($models)) {
            return;
        }
        
        // 提取主键值
        $keys = [];
        foreach ($models as $model) {
            $key = $model->get_key();
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        
        if (empty($keys)) {
            return;
        }
        
        // 调用加载器批量加载
        $loader($keys);
    }
    
    /**
     * 标记关系已加载
     * 
     * @param string $relation 关系名
     */
    protected function mark_relation_loaded($relation) {
        $this->loaded_relations[$relation] = true;
    }
    
    /**
     * 检查关系是否已加载
     * 
     * @param string $relation 关系名
     * @return bool
     */
    protected function is_relation_loaded($relation) {
        return isset($this->loaded_relations[$relation]);
    }
}
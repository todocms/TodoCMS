<?php
/**
 * 查询构建器
 * 
 * 提供流畅的查询接口，支持eager loading和批量优化
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 查询构建器类
 */
class Todo_Query_Builder {
    
    /**
     * 模型类名
     * @var string
     */
    protected $model_class;
    
    /**
     * WHERE条件
     * @var array
     */
    protected $wheres = [];
    
    /**
     * 参数数组
     * @var array
     */
    protected $params = [];
    
    /**
     * 排序
     * @var array
     */
    protected $orders = [];
    
    /**
     * 限制数量
     * @var int|null
     */
    protected $limit = null;
    
    /**
     * 偏移量
     * @var int
     */
    protected $offset = 0;
    
    /**
     * 要预加载的关系
     * @var array
     */
    protected $eager_loads = [];
    
    /**
     * 数据库连接
     * @var CMS_DB
     */
    protected static $db = null;
    
    /**
     * 构造函数
     * 
     * @param string $model_class 模型类名
     */
    public function __construct($model_class) {
        $this->model_class = $model_class;
        
        if (self::$db === null) {
            global $tododb;
            self::$db = $tododb;
        }
    }
    
    /**
     * 添加WHERE条件
     * 
     * @param string|array $column 字段名或条件数组
     * @param mixed $operator 操作符或值
     * @param mixed $value 值（当$column为字符串时）
     * @return $this
     */
    public function where($column, $operator = null, $value = null) {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, '=', $val);
            }
            return $this;
        }
        
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND'
        ];
        
        $this->params[] = $value;
        
        return $this;
    }
    
    /**
     * 添加OR WHERE条件
     * 
     * @param string $column 字段名
     * @param mixed $operator 操作符或值
     * @param mixed $value 值
     * @return $this
     */
    public function or_where($column, $operator = null, $value = null) {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR'
        ];
        
        $this->params[] = $value;
        
        return $this;
    }
    
    /**
     * WHERE IN条件
     * 
     * @param string $column 字段名
     * @param array $values 值数组
     * @return $this
     */
    public function where_in($column, $values) {
        if (empty($values)) {
            $this->where($column, 'IN', []);
            return $this;
        }
        
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'column' => $column,
            'operator' => 'IN',
            'value' => "({$placeholders})",
            'boolean' => 'AND'
        ];
        
        $this->params = array_merge($this->params, $values);
        
        return $this;
    }
    
    /**
     * 排序
     * 
     * @param string $column 字段名
     * @param string $direction 方向（ASC/DESC）
     * @return $this
     */
    public function order_by($column, $direction = 'ASC') {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtoupper($direction)
        ];
        
        return $this;
    }
    
    /**
     * 限制数量
     * 
     * @param int $limit 数量
     * @return $this
     */
    public function limit($limit) {
        $this->limit = (int)$limit;
        return $this;
    }
    
    /**
     * 偏移量
     * 
     * @param int $offset 偏移量
     * @return $this
     */
    public function offset($offset) {
        $this->offset = (int)$offset;
        return $this;
    }
    
    /**
     * 预加载关系
     * 
     * @param string|array $relations 关系名或关系数组
     * @return $this
     */
    public function with($relations) {
        if (is_string($relations)) {
            $relations = [$relations];
        }
        
        $this->eager_loads = array_merge($this->eager_loads, $relations);
        
        return $this;
    }
    
    /**
     * 构建SQL查询
     * 
     * @return array [query, params]
     */
    protected function build_query() {
        $table = $this->model_class::get_table();
        $query = "SELECT * FROM {$table}";
        
        // WHERE子句
        if (!empty($this->wheres)) {
            $where_parts = [];
            foreach ($this->wheres as $where) {
                $boolean = !empty($where_parts) ? ' ' . $where['boolean'] . ' ' : '';
                
                if ($where['operator'] === 'IN') {
                    $where_parts[] = $boolean . $where['column'] . ' IN ' . $where['value'];
                } else {
                    $where_parts[] = $boolean . $where['column'] . ' ' . $where['operator'] . ' ?';
                }
            }
            
            $query .= ' WHERE ' . implode('', $where_parts);
        }
        
        // ORDER BY子句
        if (!empty($this->orders)) {
            $order_parts = [];
            foreach ($this->orders as $order) {
                $order_parts[] = $order['column'] . ' ' . $order['direction'];
            }
            $query .= ' ORDER BY ' . implode(', ', $order_parts);
        }
        
        // LIMIT子句
        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->limit;
            if ($this->offset > 0) {
                $query .= ' OFFSET ' . $this->offset;
            }
        }
        
        return [$query, $this->params];
    }
    
    /**
     * 获取所有记录
     * 
     * @return array
     */
    public function get() {
        list($query, $params) = $this->build_query();
        $results = self::$db->get_results($query, $params);
        
        $models = [];
        foreach ($results as $row) {
            $data = is_object($row) ? (array)$row : $row;
            $models[] = new $this->model_class($data, true);
        }
        
        // 预加载关系
        if (!empty($this->eager_loads) && !empty($models)) {
            $this->eager_load_relations($models);
        }
        
        return $models;
    }
    
    /**
     * 获取第一条记录
     * 
     * @return object|null
     */
    public function first() {
        $this->limit(1);
        $results = $this->get();
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * 获取记录数
     * 
     * @return int
     */
    public function count() {
        $table = $this->model_class::get_table();
        $query = "SELECT COUNT(*) as count FROM {$table}";
        
        $where_parts = [];
        foreach ($this->wheres as $where) {
            $boolean = !empty($where_parts) ? ' ' . $where['boolean'] . ' ' : '';
            
            if ($where['operator'] === 'IN') {
                $where_parts[] = $boolean . $where['column'] . ' IN ' . $where['value'];
            } else {
                $where_parts[] = $boolean . $where['column'] . ' ' . $where['operator'] . ' ?';
            }
        }
        
        if (!empty($where_parts)) {
            $query .= ' WHERE ' . implode('', $where_parts);
        }
        
        $result = self::$db->get_var($query, $this->params);
        return (int)$result;
    }
    
    /**
     * 预加载关系
     * 
     * @param array $models 模型数组
     */
    protected function eager_load_relations($models) {
        foreach ($this->eager_loads as $relation) {
            if (method_exists($this->model_class, 'eager_load_' . $relation)) {
                call_user_func([$this->model_class, 'eager_load_' . $relation], $models);
            }
        }
    }
}
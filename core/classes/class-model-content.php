<?php
/**
 * 内容模型类
 *
 * 处理内容模型的创建、更新和查询
 *
 * @package TodoCMS
 * @version 1.0.0
 */

 
class CMS_Model_Content extends CMS_Post {
    /**
     * 内容模型
     *
     * @var array
     */
    private $model = [];
    
    /**
     * 构造函数
     *
     * @param int|array $id_or_data 内容ID或内容数据
     */
    public function __construct($id_or_data = 0) {
        parent::__construct($id_or_data);
    }
    
    /**
     * 加载内容模型
     *
     * @param string $model_name 模型名称
     * @return bool 是否成功加载
     */
    public function load_model($model_name) {
        $model = $this->get_model($model_name);
        
        if (!$model) {
            return false;
        }
        
        $this->model = $model;
        return true;
    }
    
    /**
     * 获取内容模型
     *
     * @param string $model_name 模型名称
     * @return array|bool 内容模型或false
     */
    public function get_model($model_name) {
        global $tododb;
        
        $query = "SELECT * FROM " . TABLE_PREFIX . "content_models WHERE model_name = ? LIMIT 1";
        $result = $tododb->get_results($query, [$model_name]);
        
        if (!$result) {
            return false;
        }
        
        return $result[0];
    }
    
    /**
     * 保存内容
     *
     * @param array $data 内容数据
     * @return bool 是否成功保存
     */
    public function save($data) {
        global $tododb;
        
        // 如果没有ID，则创建新内容
        if (!$this->get_id()) {
            $result = parent::create($data);
            if ($result) {
                $this->set_id($result);
                return true;
            }
            return false;
        }
        
        // 更新现有内容
        return parent::update($data);
    }
}
<?php
/**
 * TodoCMS 内容管理函数
 * 中优先级缺失函数修复
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * CMS_Image 类
 */
function CMS_Image() {
    return new class {
        public function resize($source, $width, $height, $crop = false) {
            if (!file_exists($source)) {
                return false;
            }
            
            $info = getimagesize($source);
            $mime = $info['mime'];
            
            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($source);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($source);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($source);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $image = imagecreatefromwebp($source);
                    } else {
                        return false;
                    }
                    break;
                default:
                    return false;
            }
            
            $original_width = imagesx($image);
            $original_height = imagesy($image);
            
            if ($crop) {
                $ratio = max($width / $original_width, $height / $original_height);
                $new_width = $width;
                $new_height = $height;
            } else {
                $ratio = min($width / $original_width, $height / $original_height);
                $new_width = $original_width * $ratio;
                $new_height = $original_height * $ratio;
            }
            
            $new_image = imagecreatetruecolor($new_width, $new_height);
            
            // 保持透明度
            if ($mime == 'image/png' || $mime == 'image/gif') {
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
                imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
            }
            
            imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);
            
            return $new_image;
        }
        
        public function save($image, $path, $quality = 90) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    return imagejpeg($image, $path, $quality);
                case 'png':
                    return imagepng($image, $path);
                case 'gif':
                    return imagegif($image, $path);
                case 'webp':
                    if (function_exists('imagewebp')) {
                        return imagewebp($image, $path, $quality);
                    }
                    return false;
                default:
                    return false;
            }
        }
    };
}

/**
 * CMS_Tags_Manager 类
 */
function CMS_Tags_Manager() {
    return new class {
        private $db;
        
        public function __construct() {
            $this->db = get_database_connection();
        }
        
        public function get_tags($category_id = null, $model_id = null) {
            $sql = "SELECT t.*, COUNT(pt.post_id) as post_count 
                    FROM tags t 
                    LEFT JOIN post_tags pt ON t.id = pt.tag_id";
            
            $params = [];
            $conditions = [];
            
            if ($category_id) {
                $conditions[] = "t.category_id = ?";
                $params[] = $category_id;
            }
            
            if ($model_id) {
                $conditions[] = "t.model_id = ?";
                $params[] = $model_id;
            }
            
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
            
            $sql .= " GROUP BY t.id ORDER BY t.name";
            
            return $this->db->get_results($sql, $params);
        }
        
        public function create_tag($name, $category_id = null, $model_id = null) {
            $slug = sanitize_title($name);
            
            return $this->db->query("
                INSERT INTO tags (name, slug, category_id, model_id, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ", array($name, $slug, $category_id, $model_id));
        }
        
        public function delete_tag($tag_id) {
            // 删除关联关系
            $this->db->query("DELETE FROM post_tags WHERE tag_id = ?", array($tag_id));
            
            // 删除标签
            return $this->db->query("DELETE FROM tags WHERE id = ?", array($tag_id));
        }
        
        public function get_tags_by_category($category_id) {
            return $this->get_tags($category_id);
        }
        
        public function get_tags_by_model($model_id) {
            return $this->get_tags(null, $model_id);
        }
    };
}

/**
 * 获取字段配置
 * @param string $model_type
 * @return array
 */
function get_field_configs($model_type = '') {
    $db = get_database_connection();
    
    if (!$db) {
        return [];
    }
    
    try {
        if ($model_type) {
            return $db->get_results("SELECT * FROM model_fields WHERE model_type = ? AND field_status = 'active' ORDER BY sort_order", array($model_type));
        } else {
            return $db->get_results("SELECT * FROM model_fields WHERE field_status = 'active' ORDER BY model_type, sort_order");
        }
    } catch (PDOException $e) {
        error_log('Get field configs failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * 检查是否为支持的模型
 * @param string $model_type
 * @return bool
 */
function is_supported_model($model_type) {
    $supported_models = [
        'article',
        'product', 
        'images',
        'download',
        'media',
        'single',
        'special',
        'guestbook',
        'recruit'
    ];
    
    return in_array($model_type, $supported_models);
}

/**
 * CMS_Post_Type_Article 类
 */
function CMS_Post_Type_Article() {
    return new class {
        private $db;
        
        public function __construct() {
            $this->db = get_database_connection();
        }
        
        public function get_fields() {
            return [
                'title' => ['type' => 'text', 'required' => true, 'label' => '标题'],
                'content' => ['type' => 'editor', 'required' => true, 'label' => '内容'],
                'excerpt' => ['type' => 'textarea', 'required' => false, 'label' => '摘要'],
                'featured_image' => ['type' => 'image', 'required' => false, 'label' => '特色图片'],
                'status' => ['type' => 'select', 'required' => true, 'label' => '状态', 'options' => ['draft' => '草稿', 'published' => '已发布']],
                'category_id' => ['type' => 'select', 'required' => true, 'label' => '分类'],
                'tags' => ['type' => 'tags', 'required' => false, 'label' => '标签'],
                'seo_title' => ['type' => 'text', 'required' => false, 'label' => 'SEO标题'],
                'seo_description' => ['type' => 'textarea', 'required' => false, 'label' => 'SEO描述'],
                'seo_keywords' => ['type' => 'text', 'required' => false, 'label' => 'SEO关键词']
            ];
        }
        
        public function save($data) {
            $fields = $this->get_fields();
            $insert_data = [];
            
            foreach ($fields as $field_name => $field_config) {
                if (isset($data[$field_name])) {
                    $insert_data[$field_name] = $data[$field_name];
                }
            }
            
            $insert_data['created_at'] = date('Y-m-d H:i:s');
            $insert_data['updated_at'] = date('Y-m-d H:i:s');
            
            $columns = implode(', ', array_keys($insert_data));
            $placeholders = ':' . implode(', :', array_keys($insert_data));
            
            $sql = "INSERT INTO posts ({$columns}) VALUES ({$placeholders})";
            
            return $this->db->query($sql, $insert_data);
        }
    };
}
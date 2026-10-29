<?php
/**
 * 媒体类
 *
 * 处理媒体文件的上传、处理和管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * 媒体类
 *
 * 处理媒体文件的上传、处理和管理
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 引入格式化函数
require_once ABSPATH . 'core/functions/formatting.php';

class CMS_Media
{
    /**
     * 媒体ID
     *
     * @var int
     */
    private $id = 0;

    /**
     * 媒体数据
     *
     * @var array
     */
    private $data = array();

    /**
     * 构造函数
     *
     * @param int|object $media 媒体ID或媒体对象
     */
    public function __construct($media = 0)
    {
        if (is_object($media)) {
            $this->id = $media->media_id;
            $this->data = $media;
        } elseif (is_numeric($media) && $media > 0) {
            $this->id = $media;
            $this->load();
        }
    }

    /**
     * 加载媒体数据
     *
     * @return bool 是否成功加载数据
     */
    private function load()
    {
        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;

        if (!$this->id) {
            return false;
        }

        $media = $tododb->get_row(
            "SELECT * FROM " . TABLE_PREFIX . "media WHERE media_id = ?",
            [$this->id]
        );

        if (!$media) {
            return false;
        }

        $this->data = $media;
        return true;
    }

    /**
     * 获取媒体ID
     *
     * @return int 媒体ID
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * 检查媒体是否存在
     *
     * @return bool 媒体是否存在
     */
    public function exists()
    {
        return !empty($this->data) && $this->id > 0;
    }

    /**
     * 魔术方法：直接访问数据库字段
     *
     * @param string $name 字段名
     * @return mixed 字段值
     */
    public function __get($name)
    {
        if (is_object($this->data) && isset($this->data->$name)) {
            return $this->data->$name;
        }
        if (is_array($this->data) && array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }
        return null;
    }

    /**
     * 魔术方法：检查数据库字段是否存在
     *
     * @param string $name 字段名
     * @return bool 是否存在
     */
    public function __isset($name)
    {
        if (is_object($this->data) && isset($this->data->$name)) {
            return true;
        }
        if (is_array($this->data) && array_key_exists($name, $this->data)) {
            return true;
        }
        return false;
    }

    /**
     * 获取媒体数据
     *
     * @param string $key 数据键名
     * @return mixed 媒体数据或指定键名的数据
     */
    public function get_data($key = '')
    {
        if (empty($key)) {
            return $this->data;
        }

        if (is_object($this->data) && isset($this->data->$key)) {
            return $this->data->$key;
        }
        if (is_array($this->data) && array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        return null;
    }

    /**
     * 获取媒体标题
     *
     * @return string 媒体标题
     */
    public function get_title()
    {
        $title = $this->get_data('media_title');

        // 如果标题为空，使用文件名作为标题
        if (empty($title)) {
            $meta = $this->get_metadata();
            if (!empty($meta['_todo_attached_file'])) {
                $filename = basename($meta['_todo_attached_file']);
                // 移除文件扩展名
                $title = pathinfo($filename, PATHINFO_FILENAME);
            }
        }

        return $title;
    }

    /**
     * 获取媒体描述
     *
     * @return string 媒体描述
     */
    public function get_description()
    {
        return $this->get_data('media_description');
    }

    /**
     * 获取媒体MIME类型
     *
     * @return string 媒体MIME类型
     */
    public function get_mime_type()
    {
        return $this->get_data('media_type');
    }

    /**
     * 获取媒体文件路径
     *
     * @return string 媒体文件路径
     */
    public function get_file_path()
    {
        $path = $this->get_data('media_path');
        if (empty($path)) {
            return '';
        }

        if (strpos($path, ABSPATH) === 0) {
            return $path;
        }

        return ABSPATH . 'content/uploads/' . ltrim($path, '/');
    }

    /**
     * 获取媒体URL
     *
     * @return string 媒体URL
     */
    public function get_url()
    {
        $path = $this->get_data('media_path') ?: $this->get_data('media_url');
        if (empty($path)) {
            return '';
        }

        // 获取当前媒体文件的指定存储提供商 (storage_provider)
        $provider = null;
        if ($this->id > 0 && class_exists('Todo_Media_Manager')) {
            $provider = Todo_Media_Manager::get_media_meta($this->id, 'storage_provider');
        }

        // 1. 如果该图片记录为本地存储 (local)，只返回本地 URL，避免未上传上云的老图片 404
        if ($provider === 'local') {
            $path_clean = '/' . ltrim($path, '/');
            if (strpos($path_clean, '/content/uploads') !== 0) {
                $path_clean = '/content/uploads' . $path_clean;
            }
            return function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($path_clean) : $path_clean;
        }

        // 2. 如果明确标注为第三方云存储
        if ($provider && $provider !== 'local' && class_exists('Todo_Storage_Manager')) {
            $storage = Todo_Storage_Manager::get_provider_by_type($provider);
            if ($storage) {
                $cloud_path = $path;
                if (strpos($cloud_path, 'content/uploads') === false) {
                    $cloud_path = 'content/uploads/' . ltrim($cloud_path, '/');
                }
                return $storage->get_url($cloud_path);
            }
        }

        // 3. 兜底解析：老文件（无云端元数据标记）默认维持本地 URL，确保历史老图片不 404
        $path_clean = '/' . ltrim($path, '/');
        if (strpos($path_clean, '/content/uploads') !== 0) {
            $path_clean = '/content/uploads' . $path_clean;
        }
        return function_exists('todo_media_url_to_absolute') ? todo_media_url_to_absolute($path_clean) : $path_clean;
    }

    /**
     * 获取媒体缩略图URL
     *
     * @param string $size 缩略图尺寸
     * @return string 缩略图URL
     */
    public function get_thumbnail_url($size = 'thumbnail')
    {
        return $this->get_url();
    }

    /**
     * 获取媒体元数据
     *
     * @return array 元数据数组
     */
    public function get_metadata()
    {
        if (!$this->id) {
            return array();
        }

        $media = is_object($this->data) ? $this->data : null;
        if (!$media) {
            global $tododb;
            $media = $tododb->get_row(
                "SELECT * FROM " . TABLE_PREFIX . "media 
                 WHERE media_id = ?",
                [$this->id]
            );
        }

        $metadata = array();
        if ($media) {
            // 将media表字段转换为元数据格式
            $metadata['_todo_attached_file'] = $media->media_path;

            // 获取文件大小
            $file_size = 0;
            if (!empty($media->media_size)) {
                $file_size = $media->media_size;
            } else {
                // 如果数据库中没有文件大小，尝试从文件系统获取
                $file_path = ABSPATH . 'content/uploads/' . ltrim($media->media_path, '/');
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path);
                }
            }
            $metadata['_filesize'] = $file_size;

            $metadata['_todo_attachment_metadata'] = array(
                'width' => $media->media_width,
                'height' => $media->media_height,
                'file' => $media->media_path,
                'filesize' => $file_size,
                'sizes' => array() // 可以根据需要扩展
            );
        }

        return $metadata;
    }

    /**
     * 获取单个元数据
     *
     * @param string $key 元数据键名
     * @param mixed $default 默认值
     * @return mixed 元数据值
     */
    public function get_meta($key, $default = '')
    {
        if (!$this->id || !$key) {
            return $default;
        }

        global $tododb;
        $media = $tododb->get_row(
            "SELECT * FROM " . TABLE_PREFIX . "media 
             WHERE media_id = ?",
            [$this->id]
        );

        if (!$media) {
            return $default;
        }

        // 直接使用字段名访问媒体属性
        switch ($key) {
            case '_todo_attached_file':
                return isset($media->media_path) ? $media->media_path : $default;
            case '_todo_attachment_metadata':
                return isset($media->media_description) ? $media->media_description : $default;
            case 'media_alt':
                return isset($media->media_alt) ? $media->media_alt : $default;
            case 'media_title':
                return isset($media->media_title) ? $media->media_title : $default;
        }

        return $default;
    }

    /**
     * 更新媒体
     *
     * @param array $data 媒体数据
     * @return bool 是否成功更新
     */
    public function update($data)
    {
        global $tododb;

        if (!$this->id) {
            return false;
        }

        $update_fields = array();
        $allowed_fields = array(
            'media_title',
            'media_description',
            'media_alt',
            'media_caption',
            'seo_title',
            'seo_description',
            'seo_keywords',
            'media_status'
        );
        $params = array();

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $update_fields[] = "`$key` = ?";
                $params[] = $value;
            }
        }

        if (empty($update_fields)) {
            return false;
        }

        $sql = "UPDATE " . TABLE_PREFIX . "media SET " . implode(', ', $update_fields) . " WHERE media_id = ?";
        $params[] = intval($this->id);
        // 兼容 CMS_DB / Extended_PDO / 原生 PDO
        if (is_object($tododb) && method_exists($tododb, 'pdo_prepare')) {
            $stmt = call_user_func([$tododb, 'pdo_prepare'], $sql);
            $result = $stmt ? $stmt->execute($params) : false;
        } elseif ($tododb instanceof PDO) {
            $stmt = $tododb->prepare($sql);
            $result = $stmt ? $stmt->execute($params) : false;
            try {
                if ($stmt instanceof PDOStatement) {
                    $stmt->closeCursor();
                }
            } catch (Throwable $e) {
            }
        } else {
            $result = $tododb->query($sql, $params);
        }

        if ($result) {
            $this->load();
            return true;
        }

        return false;
    }

    /**
     * 删除媒体
     *
     * @param bool $force_delete 是否强制删除文件
     * @return bool 是否成功删除
     */
    public function delete($force_delete = true)
    {
        global $tododb;

        if (!$this->id) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::delete - 媒体ID为空");
            }
            return false;
        }

        // 获取文件路径
        $file_path = $this->get_file_path();

        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("CMS_Media::delete - 媒体ID: {$this->id}, 文件路径: {$file_path}");
        }

        // 删除数据库记录（使用预处理并返回影响行数）
        $affected = $tododb->query("DELETE FROM " . TABLE_PREFIX . "media WHERE media_id = ?", [intval($this->id)]);
        $result = ($affected > 0);

        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("CMS_Media::delete - 数据库删除结果: affected_rows={$affected}, success=" . ($result ? 'true' : 'false'));
        }

        // 删除文件
        if ($force_delete && !empty($file_path)) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::delete - 开始删除文件: {$file_path}");
                error_log("CMS_Media::delete - 文件存在: " . (file_exists($file_path) ? 'yes' : 'no'));
                if (file_exists($file_path)) {
                    error_log("CMS_Media::delete - 文件权限: " . substr(sprintf('%o', fileperms($file_path)), -4));
                    error_log("CMS_Media::delete - 文件可写: " . (is_writable($file_path) ? 'yes' : 'no'));
                    error_log("CMS_Media::delete - 目录可写: " . (is_writable(dirname($file_path)) ? 'yes' : 'no'));
                }
            }

            if (file_exists($file_path)) {
                // 删除原始文件
                if (unlink($file_path)) {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log("CMS_Media::delete - 成功删除原始文件: {$file_path}");
                    }
                } else {
                    if (defined('TODO_DEBUG') && TODO_DEBUG) {
                        error_log("CMS_Media::delete - 删除原始文件失败: {$file_path}");
                        error_log("CMS_Media::delete - 错误信息: " . error_get_last()['message']);
                    }
                    // 即使文件删除失败，也继续执行，因为数据库记录已经删除
                }
            } else {
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log("CMS_Media::delete - 文件不存在，跳过删除: {$file_path}");
                }
            }

            // 同步删除云端对象存储文件
            if (class_exists('Todo_Storage_Manager')) {
                $provider = null;
                if (class_exists('Todo_Media_Manager')) {
                    $provider = Todo_Media_Manager::get_media_meta($this->id, 'storage_provider');
                }
                $storage = null;
                if ($provider && $provider !== 'local') {
                    $storage = Todo_Storage_Manager::get_provider_by_type($provider);
                } else {
                    $storage_mgr = Todo_Storage_Manager::get_instance();
                    if (!$storage_mgr->is_local()) {
                        $storage = $storage_mgr;
                    }
                }
                
                if ($storage) {
                    $rel_key = $this->get_data('media_path') ?: $this->get_data('media_url');
                    if (!empty($rel_key)) {
                        if (strpos($rel_key, 'content/uploads') === false) {
                            $rel_key = 'content/uploads/' . ltrim($rel_key, '/');
                        }
                        $storage->delete($rel_key);
                    }
                }
            }
        }

        if ($result) {
            $this->id = 0;
            $this->data = array();
            return true;
        }

        return false;
    }

    /**
     * 上传媒体文件
     *
     * @param array $file 上传的文件信息
     * @param array $args 附加参数
     * @return int|bool 成功返回媒体ID，失败返回false
     */
    public static function upload($file, $args = array())
    {
        // 开始处理文件上传

        $enable_upload = true;
        if (function_exists('get_option')) {
            $enable_upload = (bool) get_option('enable_upload', true);
        }
        if (!$enable_upload) {
            return false;
        }

        $max_allowed = null;
        if (class_exists('TodoUploadConfig')) {
            $max_allowed = (int) TodoUploadConfig::get_max_upload_size();
        } elseif (function_exists('get_option')) {
            $mb = get_option('max_upload_size', null);
            if ($mb !== null && is_numeric($mb) && (int) $mb > 0) {
                $max_allowed = (int) $mb * 1024 * 1024;
            }
        }
        if ($max_allowed === null) {
            $parse_size = function ($size) {
                $unit = preg_replace('/[^bkmgtpezy]/i', '', $size ?? '');
                $num = preg_replace('/[^0-9\.]/', '', $size ?? '');
                $num = ($num === '' ? 0 : (float) $num);
                if ($unit) {
                    return (int) round($num * pow(1024, stripos('bkmgtpezy', $unit[0])));
                }
                return (int) round($num);
            };
            $php_size = $parse_size(ini_get('upload_max_filesize'));
            $post_size = $parse_size(ini_get('post_max_size'));
            $max_allowed = min($php_size > 0 ? $php_size : PHP_INT_MAX, $post_size > 0 ? $post_size : PHP_INT_MAX);
            if ($max_allowed === PHP_INT_MAX) {
                $max_allowed = 0;
            }
        }
        if ((int) $max_allowed <= 0) {
            return false;
        }

        // 检查文件是否有效
        if (empty($file) || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::upload - 文件无效或临时文件名为空");
            }
            return false;
        }

        $incoming_size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($incoming_size <= 0 && is_string($file['tmp_name']) && $file['tmp_name'] !== '' && file_exists($file['tmp_name'])) {
            $incoming_size = (int) filesize($file['tmp_name']);
        }
        if ($incoming_size > 0 && $incoming_size > $max_allowed) {
            return false;
        }

        // 检查系统文件
        $filename = $file['name'];
        $blocked_files = ['.DS_Store', 'Thumbs.db', '.htaccess', '.gitignore', '.git', '.svn'];
        $blocked_patterns = ['/^\./', '/~$/', '/\.tmp$/', '/\.temp$/'];

        // 检查是否为被阻止的文件名
        if (in_array($filename, $blocked_files)) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::upload - 系统文件不允许上传: " . $filename);
            }
            return false;
        }

        // 检查是否匹配被阻止的模式
        foreach ($blocked_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                if (defined('TODO_DEBUG') && TODO_DEBUG) {
                    error_log("CMS_Media::upload - 文件名模式不允许: " . $filename);
                }
                return false;
            }
        }

        // 检查文件类型
        $filetype = todo_check_filetype($file['name']);
        if (empty($filetype['ext'])) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::upload - 文件类型检查失败: " . $file['name']);
            }
            return false;
        }

        // 校验后台上传策略（全部受后端配置动态管理，不使用硬编码黑名单）
        if (class_exists('TodoUploadConfig') && !TodoUploadConfig::is_type_allowed($filetype['type'], $filetype['ext'])) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                error_log("CMS_Media::upload - 文件类型未在后台允许列表中: " . $file['name']);
            }
            return false;
        }

        // 文件类型检查通过

        // 获取上传目录
        $upload_dir = todo_upload_dir();

        // 生成唯一文件名
        $filename = todo_unique_filename($upload_dir['path'], $file['name']);

        // 构建完整的文件路径
        $new_file = $upload_dir['path'] . '/' . $filename;

        // realpath 前缀校验，确保上传路径位于 /content/uploads 下
        $uploads_base = (defined('ABSPATH') ? rtrim(ABSPATH, '/') : rtrim(TODO_ROOT, '/')) . '/content/uploads';
        $base_real = realpath($uploads_base);
        $dir_real = realpath($upload_dir['path']);
        if ($base_real === false || $dir_real === false || strpos($dir_real, $base_real) !== 0) {
            return false;
        }

        // 允许两种来源：HTTP 上传 或 系统临时文件（由 download_url 创建）
        $source_tmp = $file['tmp_name'];
        $is_http_upload = is_uploaded_file($source_tmp);
        $is_downloaded_temp = false;

        if (!$is_http_upload && file_exists($source_tmp)) {
            $tmp_dir = rtrim(sys_get_temp_dir(), '/');
            $real_tmp = realpath($source_tmp);
            $real_tmp_dir = $tmp_dir ? realpath($tmp_dir) : false;
            if ($real_tmp && $real_tmp_dir && strpos($real_tmp, $real_tmp_dir) === 0 && preg_match('/todo_download_/i', basename($source_tmp))) {
                $is_downloaded_temp = true;
            }
        }

        if (!$is_http_upload && !$is_downloaded_temp) {
            return false;
        }

        // 将文件移动到上传目录
        $move_ok = false;
        if ($is_http_upload) {
            $move_ok = move_uploaded_file($source_tmp, $new_file);
        } else {
            // 下载的临时文件使用 rename/copy
            $move_ok = @rename($source_tmp, $new_file);
            if (!$move_ok) {
                $move_ok = @copy($source_tmp, $new_file);
                if ($move_ok) {
                    @unlink($source_tmp);
                }
            }
        }
        if (!$move_ok) {
            return false;
        }
        @chmod($new_file, 0644);

        // 设置文件权限
        $stat = stat(dirname($new_file));
        $perms = $stat['mode'] & 0000666;
        chmod($new_file, $perms);

        // 准备媒体数据
        // 使用原始文件名生成标题，确保去除重复扩展名
        $original_name_without_ext = remove_duplicate_extensions($file['name']);
        $original_name_without_ext = preg_replace('/\.[^.]+$/', '', $original_name_without_ext);

        // 获取文件信息
        $file_size = filesize($new_file);
        $file_path = $upload_dir['subdir'] . '/' . $filename;
        $file_url = $upload_dir['baseurl'] . $upload_dir['subdir'] . '/' . $filename;
        if (function_exists('todo_media_url_to_relative')) {
            $file_url = todo_media_url_to_relative($file_url);
        }
        $current_user_id = get_current_user_id();

        // 如果是图像，获取尺寸与 ThumbHash
        $media_width = 0;
        $media_height = 0;
        $thumbhash_code = '';
        if (strpos($filetype['type'], 'image/') === 0) {
            $image_info = getimagesize($new_file);
            if ($image_info) {
                $media_width = $image_info[0];
                $media_height = $image_info[1];
            }
            if (class_exists('ThumbHash')) {
                $thumbhash_code = ThumbHash::fromImageFile($new_file);
            }
        }

        $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
        $media_site_id = 0;
        if ($is_admin_context && isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) {
            $media_site_id = (int) $_GET['site'];
        } elseif (!$is_admin_context && function_exists('todo_current_site_id')) {
            $media_site_id = (int) todo_current_site_id();
        }

        $media_data = array(
            'site_id' => $media_site_id,
            'media_title' => $original_name_without_ext,
            'media_filename' => $filename,
            'media_path' => $file_path,
            'media_url' => $file_url,
            'media_type' => $filetype['type'],
            'media_size' => $file_size,
            'media_width' => $media_width,
            'media_height' => $media_height,
            'media_alt' => isset($args['media_alt']) ? $args['media_alt'] : '',
            'media_description' => isset($args['media_description']) ? $args['media_description'] : '',
            'upload_user' => $current_user_id,
            'upload_time' => current_time('mysql'),
            'usage_count' => 0,
            'download_count' => 0,
            'is_attached' => 0,
            'media_status' => 'active',
            'thumbhash' => $thumbhash_code
        );

        // 插入媒体记录
        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;
        $fields = array();
        $placeholders = array();
        $params = array();

        foreach ($media_data as $key => $value) {
            $fields[] = "`$key`";
            $placeholders[] = '?';
            $params[] = $value;
        }

        $sql = "INSERT INTO " . TABLE_PREFIX . "media (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";

        // 调试输出SQL语句
        if (defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("CMS_Media::upload SQL: " . $sql);
        }

        // 兼容 CMS_DB / Extended_PDO / 原生 PDO
        if (is_object($tododb) && method_exists($tododb, 'pdo_prepare')) {
            $stmt = call_user_func([$tododb, 'pdo_prepare'], $sql);
            $result = $stmt ? $stmt->execute($params) : false;
        } elseif ($tododb instanceof PDO) {
            $stmt = $tododb->prepare($sql);
            $result = $stmt ? $stmt->execute($params) : false;
            try {
                if ($stmt instanceof PDOStatement) {
                    $stmt->closeCursor();
                }
            } catch (Throwable $e) {
            }
        } else {
            $result = $tododb->query($sql, $params);
        }

        // 调试输出数据库错误
        if (!$result && defined('TODO_DEBUG') && TODO_DEBUG) {
            error_log("CMS_Media::upload DB Error: " . $tododb->last_error());
        }

        if (!$result) {
            unlink($new_file);
            return false;
        }

        // 获取插入的媒体ID
        $media_id = $tododb->insert_id();

        // 如果是图像，生成缩略图和元数据（必须在可能删除本地临时文件之前执行）
        if (strpos($filetype['type'], 'image/') === 0) {
            $category_id = isset($args['category_id']) ? $args['category_id'] : null;
            self::generate_image_metadata($new_file, $filetype['type'], $category_id, $args);
        }

        // 云存储推送
        if ($media_id && class_exists('Todo_Storage_Manager')) {
            $storage_mgr = Todo_Storage_Manager::get_instance();
            $provider_type = $storage_mgr->get_provider_type();
            
            if (!$storage_mgr->is_local()) {
                $relative_path = 'content/uploads/' . ltrim($file_path, '/');
                $upload_res = $storage_mgr->upload($new_file, $relative_path, $filetype['type']);
                
                if (function_exists('is_todo_error') && is_todo_error($upload_res)) {
                    if (class_exists('Todo_Logger')) {
                        Todo_Logger::error("云存储推送失败 ({$provider_type}): " . $upload_res->get_error_message());
                    }
                } else {
                    // 上传云端成功，根据系统设置决定是否删除本地物理文件（释放服务器磁盘空间）
                    $keep_local = (bool) get_option('storage_keep_local', '0');
                    if (!$keep_local && file_exists($new_file)) {
                        @unlink($new_file);
                    }
                }
                
                if (class_exists('Todo_Media_Manager')) {
                    Todo_Media_Manager::save_media_meta($media_id, 'storage_provider', $provider_type);
                }
            }
        }

        return $media_id;
    }

    /**
     * 生成图像元数据
     *
     * @param string $file 图像文件路径
     * @param string $mime_type 图像MIME类型
     * @param int|null $category_id 分类ID
     * @param array $args 附加参数
     * @return array 图像元数据
     */
    private static function generate_image_metadata($file, $mime_type, $category_id = null, $args = array())
    {
        // 获取图像尺寸
        $size = @getimagesize($file);
        if (!$size) {
            return array();
        }

        list($width, $height) = $size;

        // 基本元数据
        $metadata = array(
            'width' => $width,
            'height' => $height,
            'file' => basename($file),
            'sizes' => array()
        );

        // TodoCMS只保存原图，不生成缩略图
        // 所有图片处理在前端设计时进行裁剪

        return $metadata;
    }

    /**
     * 获取媒体列表
     *
     * @param array $args 查询参数
     * @return array 媒体列表
     */
    public static function get_media($args = array())
    {
        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;

        $defaults = array(
            'number' => 20,
            'offset' => 0,
            'orderby' => 'upload_time',
            'order' => 'DESC',
            'mime_type' => '',
            'search' => '',
            'directory' => '',
            'site_id' => null, // Phase 4
            'include_usage' => false,
            'recalculate_usage' => false
        );

        // 确保$args是数组
        if (!is_array($args)) {
            $args = array();
        }

        $args = array_merge($defaults, $args);

        $query = "SELECT m.* FROM " . TABLE_PREFIX . "media m WHERE 1=1";
        
        $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
        if ($args['site_id'] === null) {
            if ($is_admin_context) {
                if (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) {
                    $args['site_id'] = (int) $_GET['site'];
                }
            } elseif (function_exists('todo_current_site_id')) {
                $args['site_id'] = todo_current_site_id();
            }
        }

        if ($args['site_id'] !== null) {
            $query .= " AND (m.site_id = ? OR m.site_id = 0)";
            $params[] = intval($args['site_id']);
        }

        // 添加MIME类型过滤
        if (!empty($args['mime_type'])) {
            if ($args['mime_type'] === 'unattached') {
                $query .= " AND m.is_attached = 0";
            } elseif ($args['mime_type'] === 'unrecited') {
                // 未附加（全局未引用）：系统任意位置均未引用该媒体
                self::ensure_unrecited_cache_ready($tododb);
                $cache_table = TABLE_PREFIX . 'media_unrecited_cache';
                $query .= " AND EXISTS (SELECT 1 FROM {$cache_table} uc WHERE uc.media_id = m.media_id)";
            } elseif ($args['mime_type'] === 'document' || $args['mime_type'] === 'application') {
                $query .= " AND (m.media_type COLLATE utf8mb4_unicode_ci LIKE 'application/%' OR m.media_type COLLATE utf8mb4_unicode_ci LIKE 'text/%')";
            } else {
                $query .= " AND m.media_type COLLATE utf8mb4_unicode_ci LIKE ?";
                $params[] = $args['mime_type'] . '%';
            }
        }

        // 添加搜索条件
        if (!empty($args['search'])) {
            $query .= " AND (m.media_title COLLATE utf8mb4_unicode_ci LIKE ? OR m.media_description COLLATE utf8mb4_unicode_ci LIKE ?)";
            $search_term = '%' . $args['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // 添加目录筛选条件
        if (!empty($args['directory'])) {
            $query .= " AND m.media_path COLLATE utf8mb4_unicode_ci LIKE ?";
            $directory_pattern = $args['directory'] . '/%';
            $params[] = $directory_pattern;
        }

        // 验证和映射排序字段
        $valid_orderby = array(
            'date' => 'upload_time',
            'upload_time' => 'upload_time',
            'title' => 'media_title',
            'media_title' => 'media_title',
            'name' => 'media_filename',
            'media_filename' => 'media_filename',
            'ID' => 'media_id'
        );

        $orderby_field = isset($valid_orderby[$args['orderby']]) ? $valid_orderby[$args['orderby']] : 'upload_time';

        // 添加排序
        $order_dir = in_array(strtoupper($args['order']), array('ASC', 'DESC')) ? strtoupper($args['order']) : 'DESC';
        $query .= " ORDER BY m.{$orderby_field} {$order_dir}";

        // 添加分页 - 使用占位符
        $query .= " LIMIT ? OFFSET ?";
        $params[] = intval($args['number']);
        $params[] = intval($args['offset']);

        $rows = $tododb->get_results($query, $params);
        if (!empty($args['include_usage']) && !empty($args['recalculate_usage'])) {
            self::populate_usage_counts($tododb, $rows);
        }
        return $rows;
    }

    private static function populate_usage_counts($tododb, &$rows)
    {
        if (empty($rows) || !is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->media_id)) {
                continue;
            }
            $computed = self::calculate_media_usage_count($tododb, $row);
            $computed_int = (int) $computed;
            $old_usage = isset($row->usage_count) ? (int) $row->usage_count : 0;
            $old_attached = isset($row->is_attached) ? (int) $row->is_attached : 0;
            $new_attached = $computed_int > 0 ? 1 : 0;

            $row->usage_count = $computed_int;
            $row->is_attached = $new_attached;

            if ($old_usage !== $computed_int || $old_attached !== $new_attached) {
                try {
                    $tododb->query(
                        "UPDATE " . TABLE_PREFIX . "media SET usage_count = ?, is_attached = ? WHERE media_id = ?",
                        [$computed_int, $new_attached, (int) $row->media_id]
                    );
                } catch (Throwable $e) {
                }
            }
        }
    }

    public static function recalculate_usage_count($media_id)
    {
        $media_id = (int) $media_id;
        if ($media_id <= 0) {
            return ['usage_count' => 0, 'is_attached' => 0];
        }

        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;

        $row = $tododb->get_row(
            "SELECT * FROM " . TABLE_PREFIX . "media WHERE media_id = ?",
            [$media_id]
        );
        if (!$row || !is_object($row)) {
            return ['usage_count' => 0, 'is_attached' => 0];
        }

        $details = self::get_all_references($row);
        $computed_int = $details['usage_count'];
        $new_attached = $details['is_attached'];

        try {
            $tododb->query(
                "UPDATE " . TABLE_PREFIX . "media SET usage_count = ?, is_attached = ? WHERE media_id = ?",
                [$computed_int, $new_attached, $media_id]
            );
        } catch (Throwable $e) {}

        return ['usage_count' => $computed_int, 'is_attached' => $new_attached];
    }

    /**
     * 获取媒体引用的详细信息 (统一种子逻辑)
     * 
     * @param int|object $media 媒体ID或对象
     * @return array 包含引用列表和总数的详细信息
     */
    public static function get_all_references($media)
    {
        global $cms_db;
        $tododb = $cms_db ?: new CMS_DB();
        
        if (is_numeric($media)) {
            $media_id = (int)$media;
            $media = $tododb->get_row("SELECT * FROM " . TABLE_PREFIX . "media WHERE media_id = ?", [$media_id]);
        } elseif (is_object($media) && method_exists($media, 'get_id')) {
            $media_id = $media->get_id();
        } elseif (is_object($media) && isset($media->media_id)) {
            $media_id = (int)$media->media_id;
        } else {
            return ['usage_count' => 0, 'is_attached' => 0, 'references' => []];
        }

        if (!$media) {
            return ['usage_count' => 0, 'is_attached' => 0, 'references' => []];
        }

        // 确保 $media_id 已被赋值
        if (!isset($media_id) || $media_id <= 0) {
            $media_id = (is_object($media) && isset($media->media_id)) ? (int)$media->media_id : 0;
        }

        if ($media_id <= 0) {
            return ['usage_count' => 0, 'is_attached' => 0, 'references' => []];
        }
        $candidates = [];
        $add = function ($v) use (&$candidates) {
            if (!is_string($v) || ($v = trim($v)) === '') return;
            $candidates[] = $v;
        };

        $add(isset($media->media_url) ? (string)$media->media_url : '');
        $add(isset($media->media_path) ? (string)$media->media_path : '');
        $add(isset($media->media_filename) ? (string)$media->media_filename : '');

        if (!empty($media->media_path)) {
            $add('/' . ltrim($media->media_path, '/'));
            $add(basename($media->media_path));
        }
        if (!empty($media->media_url)) {
            $add(basename($media->media_url));
            $p = @parse_url($media->media_url, PHP_URL_PATH);
            if ($p) {
                $add($p);
                $add(basename($p));
            }
        }

        $add('"id":' . $media_id);
        $add('"media_id":' . $media_id);
        $add('todo-image-' . $media_id);
        $add('data-id="' . $media_id . '"');

        $candidates = array_values(array_unique(array_filter($candidates)));
        if (empty($candidates)) return ['usage_count' => 0, 'is_attached' => 0, 'references' => []];

        $escape_like = function ($s) use ($tododb) {
            return method_exists($tododb, 'esc_like') ? $tododb->esc_like($s) : $s;
        };

        $patterns = [];
        foreach ($candidates as $c) {
            $patterns[] = '%' . $escape_like($c) . '%';
        }

        $build_like_group = function ($field_expr, $patterns, &$params) {
            $parts = [];
            foreach ($patterns as $p) {
                $parts[] = "{$field_expr} LIKE ?";
                $params[] = $p;
            }
            return empty($parts) ? '0=1' : '(' . implode(' OR ', $parts) . ')';
        };

        $found_post_ids = [];
        $content_references = [];
        $prefix = TABLE_PREFIX;

        // 1. 搜索文章 (Content, Featured Image)
        try {
            if (self::db_table_exists($tododb, 'posts')) {
                $params = [];
                $where_content = $build_like_group('p.post_content', $patterns, $params);
                $where_featured = $build_like_group('p.featured_image', $patterns, $params);
                $sql = "SELECT p.ID, p.post_title FROM {$prefix}posts p WHERE p.post_status != 'trash' AND ({$where_content} OR {$where_featured})";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $found_post_ids[$row->ID] = true;
                        $content_references[] = [
                            'type' => 'post',
                            'post_id' => $row->ID,
                            'post_title' => $row->post_title,
                            'reference_text' => $row->post_title . ' (文章内容/特色图片)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // 2. 搜索文章自定义字段与 Meta
        $meta_tables = ['post_custom_fields' => 'post_id', 'postmeta' => 'post_id'];
        foreach ($meta_tables as $table => $id_col) {
            try {
                if (self::db_table_exists($tododb, $table) && self::db_table_exists($tododb, 'posts')) {
                    $val_col = ($table === 'postmeta') ? 'meta_value' : 'field_value';
                    $params = [];
                    $where = $build_like_group("cf.{$val_col}", $patterns, $params);
                    $sql = "SELECT DISTINCT cf.{$id_col} as pid, p.post_title FROM {$prefix}{$table} cf INNER JOIN {$prefix}posts p ON p.ID = cf.{$id_col} WHERE p.post_status != 'trash' AND {$where}";
                    $rows = $tododb->get_results($sql, $params);
                    if ($rows) {
                        foreach ($rows as $row) {
                            if (!isset($found_post_ids[$row->pid])) {
                                $found_post_ids[$row->pid] = true;
                                $content_references[] = [
                                    'type' => 'post_meta',
                                    'post_id' => $row->pid,
                                    'post_title' => $row->post_title,
                                    'reference_text' => $row->post_title . ' (自定义字段/Meta)'
                                ];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {}
        }

        // 3. 搜索数据模型动态字段
        try {
            $model_fields_tables = self::get_model_fields_text_columns($tododb);
            if (!empty($model_fields_tables) && self::db_table_exists($tododb, 'posts')) {
                foreach ($model_fields_tables as $table_name => $cols) {
                    if (empty($cols) || !in_array('post_id', $cols, true)) continue;
                    $searchable = array_values(array_filter($cols, function ($c) {
                        return !in_array($c, ['post_id', 'field_id', 'created_at', 'updated_at']);
                    }));
                    if (empty($searchable)) continue;

                    $params = [];
                    $or_groups = [];
                    foreach ($searchable as $col) {
                        if (preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                            $or_groups[] = $build_like_group("mf.`{$col}`", $patterns, $params);
                        }
                    }
                    if (empty($or_groups)) continue;

                    $sql = "SELECT DISTINCT mf.post_id as pid, p.post_title FROM `{$table_name}` mf INNER JOIN {$prefix}posts p ON p.ID = mf.post_id WHERE p.post_status != 'trash' AND (" . implode(' OR ', $or_groups) . ")";
                    $rows = $tododb->get_results($sql, $params);
                    if ($rows) {
                        foreach ($rows as $row) {
                            if (!isset($found_post_ids[$row->pid])) {
                                $found_post_ids[$row->pid] = true;
                                $content_references[] = [
                                    'type' => 'model_field',
                                    'post_id' => $row->pid,
                                    'post_title' => $row->post_title,
                                    'reference_text' => $row->post_title . ' (数据模型字段)'
                                ];
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        $other_usage_count = 0;

        // 4. 系统配置
        try {
            if (self::db_table_exists($tododb, 'system_config')) {
                $params = [];
                $where = $build_like_group('sc.config_value', $patterns, $params);
                $sql = "SELECT config_key FROM {$prefix}system_config sc WHERE {$where}";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $label = $row->config_key;
                        if ($label === 'site_logo') $label = '网站Logo';
                        if ($label === 'site_favicon') $label = '网站图标(Favicon)';
                        $content_references[] = [
                            'type' => 'system_config',
                            'post_id' => 0,
                            'post_title' => $label,
                            'reference_text' => $label . ' (系统配置)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // 5. 用户资料
        try {
            if (self::db_table_exists($tododb, 'user_meta') && self::db_table_exists($tododb, 'users')) {
                $params = [];
                $where = $build_like_group('um.meta_value', $patterns, $params);
                $sql = "SELECT DISTINCT um.user_id, u.display_name FROM {$prefix}user_meta um INNER JOIN {$prefix}users u ON u.ID = um.user_id WHERE {$where}";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $dn = !empty($row->display_name) ? $row->display_name : ('用户#' . $row->user_id);
                        $content_references[] = [
                            'type' => 'user_meta',
                            'post_id' => $row->user_id,
                            'post_title' => $dn,
                            'reference_text' => $dn . ' (用户资料)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        $total_posts = count($found_post_ids);

        // 6. 友情链接
        try {
            if (self::db_table_exists($tododb, 'links')) {
                $params = [];
                $where = $build_like_group('logo', $patterns, $params);
                $sql = "SELECT id, name FROM {$prefix}links WHERE {$where}";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $content_references[] = [
                            'type' => 'link',
                            'post_id' => $row->id,
                            'post_title' => $row->name,
                            'reference_text' => $row->name . ' (友情链接)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // 7. 广告
        try {
            if (self::db_table_exists($tododb, 'ads')) {
                $params1 = [];
                $where_image_url = $build_like_group('image_url', $patterns, $params1);
                $params2 = [];
                $where_images = $build_like_group('images', $patterns, $params2);
                $params3 = [];
                $where_content = $build_like_group('content', $patterns, $params3);
                $merged_params = array_merge($params1, $params2, $params3);
                $sql = "SELECT id, title FROM {$prefix}ads WHERE {$where_image_url} OR {$where_images} OR {$where_content}";
                $rows = $tododb->get_results($sql, $merged_params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $ad_title = !empty($row->title) ? $row->title : ('广告#' . $row->id);
                        $content_references[] = [
                            'type' => 'ad',
                            'post_id' => $row->id,
                            'post_title' => $ad_title,
                            'reference_text' => $ad_title . ' (广告)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // 8. 栏目附加属性 (term_attributes)
        try {
            if (self::db_table_exists($tododb, 'term_attributes') && self::db_table_exists($tododb, 'terms')) {
                $params = [];
                $where = $build_like_group('ta.attributes', $patterns, $params);
                $sql = "SELECT t.term_id, t.name FROM {$prefix}term_attributes ta INNER JOIN {$prefix}terms t ON t.term_id = ta.term_id WHERE {$where}";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $content_references[] = [
                            'type' => 'term_attribute',
                            'post_id' => $row->term_id,
                            'post_title' => $row->name,
                            'reference_text' => $row->name . ' (栏目属性)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // 9. 站点配置 (sites)
        try {
            if (self::db_table_exists($tododb, 'sites')) {
                $params = [];
                $where = $build_like_group('site_logo', $patterns, $params);
                $sql = "SELECT id, name FROM {$prefix}sites WHERE {$where}";
                $rows = $tododb->get_results($sql, $params);
                if ($rows) {
                    foreach ($rows as $row) {
                        $other_usage_count++;
                        $content_references[] = [
                            'type' => 'site',
                            'post_id' => $row->id,
                            'post_title' => $row->name,
                            'reference_text' => $row->name . ' (站点配置)'
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        return [
            'usage_count' => $total_posts + $other_usage_count,
            'is_attached' => ($total_posts > 0) ? 1 : 0,
            'references' => $content_references
        ];
    }

    private static function calculate_media_usage_count($tododb, $media)
    {
        $details = self::get_all_references($media);
        return (int)$details['usage_count'];
    }

    /**
     * 获取媒体数量
     *
     * @param array $args 查询参数
     * @return int 媒体数量
     */
    public static function get_media_count($args = array())
    {
        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;

        $query = "SELECT COUNT(*) FROM " . TABLE_PREFIX . "media m";
        $params = array();
        $where_added = false;

        $is_admin_context = (defined('IS_ADMIN') && IS_ADMIN);
        if (!isset($args['site_id']) || $args['site_id'] === null) {
            if ($is_admin_context) {
                if (isset($_GET['site']) && is_numeric($_GET['site']) && (int) $_GET['site'] > 0) {
                    $args['site_id'] = (int) $_GET['site'];
                }
            } elseif (function_exists('todo_current_site_id')) {
                $args['site_id'] = todo_current_site_id();
            }
        }

        if (isset($args['site_id']) && $args['site_id'] !== null) {
            $query .= " WHERE (m.site_id = ? OR m.site_id = 0)";
            $params[] = intval($args['site_id']);
            $where_added = true;
        }

        // 添加MIME类型过滤
        if (!empty($args['mime_type'])) {
            if ($args['mime_type'] === 'unattached') {
                $query .= $where_added ? " AND m.is_attached = 0" : " WHERE m.is_attached = 0";
                $where_added = true;
            } elseif ($args['mime_type'] === 'unrecited') {
                // 未附加（全局未引用）：系统任意位置均未引用该媒体
                self::ensure_unrecited_cache_ready($tododb);
                $cache_table = TABLE_PREFIX . 'media_unrecited_cache';
                $query .= $where_added ? " AND EXISTS (SELECT 1 FROM {$cache_table} uc WHERE uc.media_id = m.media_id)" : " WHERE EXISTS (SELECT 1 FROM {$cache_table} uc WHERE uc.media_id = m.media_id)";
                $where_added = true;
            } elseif ($args['mime_type'] === 'document' || $args['mime_type'] === 'application') {
                $query .= $where_added ? " AND (m.media_type COLLATE utf8mb4_unicode_ci LIKE 'application/%' OR m.media_type COLLATE utf8mb4_unicode_ci LIKE 'text/%')" : " WHERE (m.media_type COLLATE utf8mb4_unicode_ci LIKE 'application/%' OR m.media_type COLLATE utf8mb4_unicode_ci LIKE 'text/%')";
                $where_added = true;
            } else {
                $query .= $where_added ? " AND m.media_type COLLATE utf8mb4_unicode_ci LIKE ?" : " WHERE m.media_type COLLATE utf8mb4_unicode_ci LIKE ?";
                $params[] = $args['mime_type'] . '%';
                $where_added = true;
            }
        }

        // 添加搜索条件
        if (!empty($args['search'])) {
            $query .= $where_added ? " AND" : " WHERE";
            $query .= " (m.media_title COLLATE utf8mb4_unicode_ci LIKE ? OR m.media_description COLLATE utf8mb4_unicode_ci LIKE ?)";
            $search_term = '%' . $args['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $where_added = true;
        }

        // 添加目录筛选条件
        if (!empty($args['directory'])) {
            $query .= $where_added ? " AND" : " WHERE";
            $query .= " m.media_path COLLATE utf8mb4_unicode_ci LIKE ?";
            $directory_pattern = $args['directory'] . '/%';
            $params[] = $directory_pattern;
            $where_added = true;
        }

        return $tododb->get_var($query, $params);
    }

    private static function ensure_unrecited_cache_ready($tododb)
    {
        try {
            if (!self::db_table_exists($tododb, 'media')) {
                return;
            }

            $table = TABLE_PREFIX . 'media_unrecited_cache';

            if (!self::db_table_exists($tododb, 'media_unrecited_cache')) {
                return;
            }

            $ttl = 300;
            if (function_exists('todo_get_option')) {
                $v = todo_get_option('media_unrecited_cache_ttl', null);
                if ($v !== null && is_numeric($v)) {
                    $ttl = max(30, (int) $v);
                }
            } elseif (function_exists('get_option')) {
                $v = get_option('media_unrecited_cache_ttl');
                if ($v !== null && is_numeric($v)) {
                    $ttl = max(30, (int) $v);
                }
            }

            $last = null;
            if (is_object($tododb) && method_exists($tododb, 'get_var')) {
                $last = $tododb->get_var("SELECT MAX(computed_at) FROM `{$table}`");
            }

            $needs_refresh = true;
            if ($last) {
                $ts = strtotime((string) $last);
                if ($ts !== false && (time() - $ts) < $ttl) {
                    $needs_refresh = false;
                }
            }

            if (!$needs_refresh) {
                return;
            }

            if (is_object($tododb) && method_exists($tododb, 'query')) {
                $tododb->query("TRUNCATE TABLE `{$table}`");
            }

            $where = self::build_unrecited_media_where_sql($tododb, 'm');
            $insert = "INSERT INTO `{$table}` (`media_id`, `computed_at`) SELECT m.media_id, NOW() FROM " . TABLE_PREFIX . "media m WHERE {$where}";
            if (is_object($tododb) && method_exists($tododb, 'query')) {
                $tododb->query($insert);
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * 构建“未附加（全局未引用）”媒体的 WHERE 子句
     *
     * @param CMS_DB|PDO $tododb 数据库连接
     * @param string $media_alias 媒体表别名
     * @return string SQL 片段（不含 WHERE 关键字）
     */
    private static function build_unrecited_media_where_sql($tododb, $media_alias = 'm')
    {
        $prefix = TABLE_PREFIX;
        $ma = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $media_alias);
        if ($ma === '') {
            $ma = 'm';
        }

        $url_to_path = "(CASE WHEN LOCATE('://', {$ma}.media_url) > 0 THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING({$ma}.media_url, LOCATE('/', {$ma}.media_url, LOCATE('://', {$ma}.media_url) + 3)), '?', 1), '#', 1) ELSE {$ma}.media_url END)";

        $candidates = [
            "{$ma}.media_url",
            $url_to_path,
            "{$ma}.media_path",
            "CONCAT('/', {$ma}.media_path)",
            "SUBSTRING_INDEX({$ma}.media_path, '/', -1)",
            "SUBSTRING_INDEX({$ma}.media_url, '/', -1)",
            "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX({$ma}.media_url, '?', 1), '#', 1), '/', -1)",
            "{$ma}.media_filename",
        ];

        $coll = 'utf8mb4_unicode_ci';
        $like_any = function ($field_expr) use ($candidates, $ma, $coll) {
            $field = "({$field_expr} COLLATE {$coll})";
            $parts = [];
            foreach ($candidates as $c) {
                $cand = "({$c} COLLATE {$coll})";
                $parts[] = "({$cand} <> '' AND {$field} LIKE (CONCAT('%', {$cand}, '%') COLLATE {$coll}))";
            }
            $parts[] = "({$ma}.media_id > 0 AND {$field} LIKE (CONCAT('%\"id\":', {$ma}.media_id, '%') COLLATE {$coll}))";
            $parts[] = "({$ma}.media_id > 0 AND {$field} LIKE (CONCAT('%\"media_id\":', {$ma}.media_id, '%') COLLATE {$coll}))";
            $parts[] = "({$ma}.media_id > 0 AND {$field} LIKE (CONCAT('%todo-image-', {$ma}.media_id, '%') COLLATE {$coll}))";
            $parts[] = "({$ma}.media_id > 0 AND {$field} LIKE (CONCAT('%data-id=\"', {$ma}.media_id, '\"%') COLLATE {$coll}))";
            return '(' . implode(' OR ', $parts) . ')';
        };

        $clauses = [];

        // posts 表：正文/特色图像中出现 URL / path / filename 即视为引用
        if (self::db_table_exists($tododb, 'posts')) {
            $posts = $prefix . 'posts';
            $clauses[] = "NOT EXISTS ("
                . "SELECT 1 FROM {$posts} p "
                . "WHERE p.post_status != 'trash' AND ("
                . $like_any('p.post_content')
                . " OR " . $like_any('p.featured_image')
                . ")"
                . ")";
        }

        // post_custom_fields 表：自定义字段存储的 JSON/文本中出现即视为引用
        if (self::db_table_exists($tododb, 'post_custom_fields') && self::db_table_exists($tododb, 'posts')) {
            $pcf = $prefix . 'post_custom_fields';
            $posts = $prefix . 'posts';
            $clauses[] = "NOT EXISTS ("
                . "SELECT 1 FROM {$pcf} cf "
                . "INNER JOIN {$posts} pcf_post ON pcf_post.ID = cf.post_id "
                . "WHERE pcf_post.post_status != 'trash' AND "
                . $like_any('cf.field_value')
                . ")";
        }

        // postmeta 表：元数据可能包含媒体 URL/path（兼容部分扩展/旧逻辑）
        if (self::db_table_exists($tododb, 'postmeta') && self::db_table_exists($tododb, 'posts')) {
            $pm = $prefix . 'postmeta';
            $posts = $prefix . 'posts';
            $clauses[] = "NOT EXISTS ("
                . "SELECT 1 FROM {$pm} pm "
                . "INNER JOIN {$posts} pm_post ON pm_post.ID = pm.post_id "
                . "WHERE pm_post.post_status != 'trash' AND "
                . $like_any('pm.meta_value')
                . ")";
        }

        // system_config 表：站点配置（logo/封面等）可能引用媒体
        if (self::db_table_exists($tododb, 'system_config')) {
            $sc = $prefix . 'system_config';
            $clauses[] = "NOT EXISTS ("
                . "SELECT 1 FROM {$sc} sc "
                . "WHERE " . $like_any('sc.config_value')
                . ")";
        }

        if (self::db_table_exists($tododb, 'user_meta')) {
            $um = $prefix . 'user_meta';
            $clauses[] = "NOT EXISTS ("
                . "SELECT 1 FROM {$um} um "
                . "WHERE " . $like_any('um.meta_value')
                . ")";
        }

        // model_fields_* 表：模型固定字段（大量 JSON/文本字段）中出现即视为引用
        $model_fields_tables = self::get_model_fields_text_columns($tododb);
        if (!empty($model_fields_tables) && self::db_table_exists($tododb, 'posts')) {
            $posts = $prefix . 'posts';
            foreach ($model_fields_tables as $table_name => $cols) {
                if (empty($cols) || !in_array('post_id', $cols, true)) {
                    continue;
                }
                $searchable = array_values(array_filter($cols, function ($c) {
                    return $c !== 'post_id' && $c !== 'field_id' && $c !== 'created_at' && $c !== 'updated_at';
                }));
                if (empty($searchable)) {
                    continue;
                }

                $or = [];
                foreach ($searchable as $col) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                        continue;
                    }
                    $or[] = $like_any("mf.`{$col}`");
                }
                if (empty($or)) {
                    continue;
                }

                $clauses[] = "NOT EXISTS ("
                    . "SELECT 1 FROM `{$table_name}` mf "
                    . "INNER JOIN {$posts} mf_post ON mf_post.ID = mf.post_id "
                    . "WHERE mf_post.post_status != 'trash' AND ("
                    . implode(' OR ', $or)
                    . ")"
                    . ")";
            }
        }

        if (empty($clauses)) {
            return "1=1";
        }

        return '(' . implode(' AND ', $clauses) . ')';
    }

    private static function db_table_exists($tododb, $table_name)
    {
        $short = (string) $table_name;
        $short = preg_replace('/[^a-zA-Z0-9_]/', '', $short);
        if ($short === '') {
            return false;
        }

        try {
            $full = (strpos($short, TABLE_PREFIX) === 0) ? $short : (TABLE_PREFIX . $short);
            if (function_exists('table_exists')) {
                return (bool) table_exists($full);
            }
            if (is_object($tododb) && method_exists($tododb, 'table_exists')) {
                return (bool) $tododb->table_exists($short);
            }
            if (is_object($tododb) && method_exists($tododb, 'get_var')) {
                $cnt = $tododb->get_var(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                    [$full]
                );
                return $cnt !== false && $cnt !== null && (int) $cnt > 0;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    private static function get_model_fields_text_columns($tododb)
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        if (!is_object($tododb) || !method_exists($tododb, 'get_results')) {
            return $cache;
        }

        $base = TABLE_PREFIX . 'model_fields_';
        $escaped_base = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $base);
        $pattern = $escaped_base . '%';

        try {
            $tables = $tododb->get_results(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE '\\\\'",
                [$pattern]
            );
        } catch (Throwable $e) {
            return $cache;
        }

        if (empty($tables) || !is_array($tables)) {
            return $cache;
        }

        foreach ($tables as $row) {
            $table_name = null;
            if (is_object($row) && isset($row->table_name)) {
                $table_name = $row->table_name;
            } elseif (is_array($row) && isset($row['table_name'])) {
                $table_name = $row['table_name'];
            }
            if (!is_string($table_name) || $table_name === '') {
                continue;
            }

            try {
                $cols = $tododb->get_results(
                    "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?",
                    [$table_name]
                );
            } catch (Throwable $e) {
                continue;
            }

            if (empty($cols) || !is_array($cols)) {
                continue;
            }

            $col_names = [];
            foreach ($cols as $c) {
                $name = null;
                $type = null;
                if (is_object($c)) {
                    $name = $c->column_name ?? null;
                    $type = $c->data_type ?? null;
                } elseif (is_array($c)) {
                    $name = $c['column_name'] ?? null;
                    $type = $c['data_type'] ?? null;
                }
                if (!is_string($name) || $name === '' || !is_string($type) || $type === '') {
                    continue;
                }
                $type = strtolower($type);
                if ($name === 'post_id') {
                    $col_names[] = $name;
                    continue;
                }
                if (!in_array($type, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'json'], true)) {
                    continue;
                }
                $col_names[] = $name;
            }

            if (!empty($col_names)) {
                $cache[$table_name] = array_values(array_unique($col_names));
            }
        }

        return $cache;
    }

    /**
     * 获取媒体文件名
     *
     * @return string 媒体文件名
     */
    public function get_filename()
    {
        $filename = $this->get_data('media_filename');
        if (!empty($filename)) {
            return $filename;
        }

        $path = $this->get_data('media_path');
        return !empty($path) ? basename($path) : '';
    }

    /**
     * 获取媒体上传日期
     *
     * @param string $format 日期格式
     * @return string 媒体上传日期
     */
    public function get_date($format = 'Y-m-d H:i:s')
    {
        $date = $this->get_data('upload_time');
        if (empty($date)) {
            return '';
        }

        if ($format === 'Y-m-d H:i:s') {
            return $date;
        }

        return date($format, strtotime($date));
    }

    /**
     * 获取媒体Alt文本
     *
     * @return string 媒体Alt文本
     */
    public function get_alt_text()
    {
        $meta = $this->get_metadata();
        return isset($meta['_todo_attachment_image_alt']) ? $meta['_todo_attachment_image_alt'] : '';
    }

    /**
     * 获取媒体Alt文本（别名方法）
     *
     * @return string 媒体Alt文本
     */
    public function get_alt()
    {
        return $this->get_alt_text();
    }

    /**
     * 获取媒体标题（caption）
     *
     * @return string 媒体标题
     */
    public function get_caption()
    {
        return $this->get_data('media_alt');
    }

    /**
     * 更新媒体元数据
     *
     * @param string $key 元数据键名
     * @param mixed $value 元数据值
     * @return bool 是否成功更新
     */
    public function update_meta($key, $value)
    {
        if (!$this->id) {
            return false;
        }

        return todo_update_post_meta($this->id, $key, $value);
    }

    /**
     * 检查媒体是否为图片
     *
     * @return bool 是否为图片
     */
    public function is_image()
    {
        if (!$this->exists()) {
            return false;
        }

        $mime_type = $this->get_data('media_type');
        return strpos($mime_type, 'image/') === 0;
    }

    /**
     * 获取附件信息
     *
     * @param int $attachment_id 附件ID
     * @return array|false 附件信息数组或false
     */
    public static function get_attachment($attachment_id)
    {
        global $cms_db;
        if (!$cms_db) {
            $cms_db = new CMS_DB();
        }
        $tododb = $cms_db;

        if (!$attachment_id || !is_numeric($attachment_id)) {
            return false;
        }

        $query = "SELECT * FROM " . TABLE_PREFIX . "media WHERE media_id = ?";
        $media = $tododb->get_row($query, array($attachment_id));

        if (!$media) {
            return false;
        }

        $media_obj = new self($media);
        return array(
            'id' => $media->media_id,
            'title' => $media->media_title,
            'filename' => $media->media_filename,
            'url' => $media_obj->get_url(),
            'mime_type' => $media->media_type,
            'date' => $media->upload_time
        );
    }

    /**
     * 获取未记录的文件（文件系统中存在但未在媒体库中记录的文件）
     * 调用 unlinked-files.php 中的逻辑以确保功能一致性
     *
     * @param array $args 查询参数
     * @return array 未记录的文件列表
     */
    public static function get_norecorded_files($args = array())
    {

        // 默认参数
        $defaults = array(
            'number' => 20,
            'offset' => 0,
            'orderby' => 'date',
            'order' => 'DESC',
            'search' => '',
            'directory' => ''
        );

        $args = array_merge($defaults, $args);

        // 获取上传目录信息
        $upload_dir = todo_upload_dir(false); // 不创建目录，只获取信息
        $upload_path = $upload_dir['basedir'];
        $upload_url = $upload_dir['baseurl'];

        if (!is_dir($upload_path)) {
            return array();
        }

        // 扫描文件系统获取所有文件
        $all_files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($upload_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_path = $file->getPathname();
                $relative_path = str_replace($upload_path . '/', '', $file_path);

                // 检查文件是否已在媒体库中
                if (!self::is_file_in_media_library($relative_path)) {
                    $all_files[] = array(
                        'path' => $relative_path,
                        'full_path' => $file_path,
                        'name' => basename($file_path),
                        'size' => filesize($file_path),
                        'modified' => filemtime($file_path),
                        'mime_type' => self::get_file_mime_type($file_path)
                    );
                }
            }
        }

        // 应用搜索和目录筛选
        $filtered_files = array();
        foreach ($all_files as $file_info) {
            // 应用目录筛选
            if (!empty($args['directory'])) {
                if (strpos($file_info['path'], $args['directory'] . '/') !== 0) {
                    continue;
                }
            }

            // 应用搜索筛选
            if (!empty($args['search'])) {
                if (strpos($file_info['name'], $args['search']) === false) {
                    continue;
                }
            }

            // 构建媒体对象格式
            $media_info = array(
                'media_id' => 0, // 未记录文件没有ID
                'media_title' => $file_info['name'],
                'media_filename' => sanitize_title($file_info['name']),
                'media_description' => '',
                'media_alt' => '',
                'upload_time' => date('Y-m-d H:i:s', $file_info['modified']),
                'media_type' => $file_info['mime_type'],
                'media_url' => $upload_url . '/' . $file_info['path'],
                'media_path' => $file_info['path'],
                'file_size' => $file_info['size'],
                'is_unrecorded' => true // 标记为未记录文件
            );

            $filtered_files[] = (object) $media_info;
        }

        // 排序
        usort($filtered_files, function ($a, $b) use ($args) {
            $field = $args['orderby'] === 'title' ? 'media_title' : 'upload_time';
            $result = strcmp($a->$field, $b->$field);
            return $args['order'] === 'DESC' ? -$result : $result;
        });

        // 分页
        $total = count($filtered_files);
        $paged_files = array_slice($filtered_files, $args['offset'], $args['number']);

        return $paged_files;
    }

    /**
     * 获取文件MIME类型
     *
     * @param string $file_path 文件路径
     * @return string MIME类型
     */
    private static function get_file_mime_type($file_path)
    {
        $mime = null;

        // 优先使用 finfo 检测真实 MIME
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $detected = @finfo_file($f, $file_path);
                @finfo_close($f);
                if ($detected)
                    $mime = $detected;
            }
        }

        // 图片文件的额外兜底
        if (!$mime && function_exists('getimagesize')) {
            $img = @getimagesize($file_path);
            if (is_array($img) && !empty($img['mime'])) {
                $mime = $img['mime'];
            }
        }

        // 额外兜底：mime_content_type
        if (!$mime && function_exists('mime_content_type')) {
            $detected = @mime_content_type($file_path);
            if ($detected)
                $mime = $detected;
        }

        // 最终兜底：根据统一的扩展名映射
        if (!$mime) {
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if (function_exists('todo_get_mime_by_extension')) {
                $mime = todo_get_mime_by_extension($ext);
            }
            if (!$mime) {
                $mime = 'application/octet-stream';
            }
        }

        return $mime;
    }

    /**
     * 检查文件是否已在媒体库中
     *
     * @param string $file_path 文件相对路径
     * @return bool 是否已在媒体库中
     */
    private static function is_file_in_media_library($file_path)
    {
        // 获取数据库连接
        $tododb = get_database_connection();
        if (!$tododb) {
            return false;
        }

        $norm = is_string($file_path) ? ltrim($file_path, '/') : '';
        if ($norm === '') {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM {$tododb->prefix}media WHERE media_path = ?";
        $count = $tododb->get_var($sql, [$norm]);

        return intval($count) > 0;
    }
}

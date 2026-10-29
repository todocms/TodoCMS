<?php
/**
 * 云存储管理器 (Cloud Storage Manager)
 *
 * @package TodoCMS
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 存储提供商接口
 */
interface Todo_Storage_Provider_Interface {
    /**
     * 上传文件到云端
     * @param string $file_path 本地文件绝对路径
     * @param string $destination 云端存储路径（相对路径键）
     * @param string $mime_type 文件的 MIME 类型
     * @return bool|TODO_Error 成功返回 true，失败返回 TODO_Error 对象
     */
    public function upload($file_path, $destination, $mime_type);

    /**
     * 从云端删除文件
     * @param string $file_key 云端存储路径（相对路径键）
     * @return bool
     */
    public function delete($file_key);

    /**
     * 获取云端文件的公网URL
     * @param string $file_key 云端存储路径（相对路径键）
     * @return string
     */
    public function get_url($file_key);

    /**
     * 检查云端文件是否存在
     * @param string $file_key 云端存储路径（相对路径键）
     * @return bool
     */
    public function exists($file_key);
}

/**
 * 七牛云 (Kodo) 存储提供商
 */
class Todo_Qiniu_Storage implements Todo_Storage_Provider_Interface {
    private $ak;
    private $sk;
    private $bucket;
    private $domain;

    public function __construct() {
        $this->ak = get_option('storage_qiniu_ak', '');
        $this->sk = get_option('storage_qiniu_sk', '');
        $this->bucket = get_option('storage_qiniu_bucket', '');
        $this->domain = get_option('storage_qiniu_domain', '');
    }

    private function base64_urlSafeEncode($data) {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
    }

    private function sign($data) {
        return hash_hmac('sha1', $data, $this->sk, true);
    }

    private function get_upload_token($key = null) {
        $deadline = time() + 3600;
        $putPolicy = [
            'scope' => $this->bucket . ($key ? ':' . $key : ''),
            'deadline' => $deadline,
        ];
        $putPolicyJson = json_encode($putPolicy);
        $encodedPutPolicy = $this->base64_urlSafeEncode($putPolicyJson);
        $sign = $this->sign($encodedPutPolicy);
        $encodedSign = $this->base64_urlSafeEncode($sign);
        return $this->ak . ':' . $encodedSign . ':' . $encodedPutPolicy;
    }

    public function upload($file_path, $destination, $mime_type) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket)) {
            return new TODO_Error('qiniu_config_error', '七牛云配置不完整');
        }

        $token = $this->get_upload_token($destination);
        
        // 候选上传区域域名 (覆盖七牛云全量官方区域：z0 华东、z1 华北、z2 华南、na0 北美、as0 东南亚、cn-east-2 华东2)
        $upload_hosts = [
            'https://upload.qiniup.com',
            'https://upload-z2.qiniup.com',
            'https://upload-z1.qiniup.com',
            'https://upload-na0.qiniup.com',
            'https://upload-as0.qiniup.com',
            'https://upload-cn-east-2.qiniup.com',
            'https://up.qiniup.com',
            'https://up-z2.qiniup.com',
            'https://up-z1.qiniup.com',
            'https://up-na0.qiniup.com',
            'https://up-as0.qiniup.com',
            'https://up-cn-east-2.qiniup.com'
        ];

        $last_response = '';
        $last_code = 0;

        foreach ($upload_hosts as $upload_url) {
            $ch = curl_init();
            $fields = [
                'token' => $token,
                'key' => $destination,
                'file' => new CURLFile($file_path, $mime_type, basename($file_path))
            ];
            
            curl_setopt($ch, CURLOPT_URL, $upload_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                return true;
            }

            $last_code = $http_code;
            $last_response = $response;

            // 解析七牛返回的建议区域 Host (如 "please use up-z2.qiniup.com")
            if (!empty($response) && preg_match('/(?:use|please use)\s+([a-z0-9\.\-]+)/i', $response, $matches)) {
                $suggested_host = 'https://' . ltrim($matches[1], '/');
                if (!in_array($suggested_host, $upload_hosts)) {
                    $upload_hosts[] = $suggested_host;
                }
            }

            // 如果报错是区域不匹配 (400 或 631)，继续尝试下一个节点
            if ($http_code === 400 || $http_code === 631) {
                continue;
            } else {
                break;
            }
        }
        
        return new TODO_Error('qiniu_upload_error', '七牛云上传失败 (HTTP ' . $last_code . '): ' . $last_response);
    }

    public function delete($file_key) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket)) {
            return false;
        }

        $entry = $this->bucket . ':' . $file_key;
        $encodedEntry = $this->base64_urlSafeEncode($entry);
        $path = '/delete/' . $encodedEntry;
        $token = $this->ak . ':' . $this->base64_urlSafeEncode($this->sign($path . "\n"));
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://rs.qiniu.com' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: QBox ' . $token,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200 || $http_code === 612; // 612 = no such file or directory
    }

    public function get_url($file_key) {
        $domain = rtrim($this->domain, '/');
        if (strpos($domain, 'http') !== 0) {
            $domain = 'http://' . $domain;
        }
        return $domain . '/' . ltrim($file_key, '/');
    }

    public function exists($file_key) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket)) {
            return false;
        }

        $entry = $this->bucket . ':' . $file_key;
        $encodedEntry = $this->base64_urlSafeEncode($entry);
        $path = '/stat/' . $encodedEntry;
        $token = $this->ak . ':' . $this->base64_urlSafeEncode($this->sign($path . "\n"));
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://rs.qiniu.com' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: QBox ' . $token
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
}

/**
 * 阿里云 OSS 存储提供商
 */
class Todo_Aliyun_Storage implements Todo_Storage_Provider_Interface {
    private $ak;
    private $sk;
    private $bucket;
    private $endpoint;
    private $domain;

    public function __construct() {
        $this->ak = trim(get_option('storage_aliyun_ak', ''));
        $this->sk = trim(get_option('storage_aliyun_sk', ''));
        $this->bucket = trim(get_option('storage_aliyun_bucket', ''));
        $endpoint = trim(get_option('storage_aliyun_endpoint', ''));
        $this->endpoint = rtrim(preg_replace('#^https?://#i', '', $endpoint), '/');
        $this->domain = rtrim(trim(get_option('storage_aliyun_domain', '')), '/');
    }

    private function sign($method, $uri, $date, $contentType = '') {
        $stringToSign = "{$method}\n\n{$contentType}\n{$date}\n{$uri}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->sk, true));
        return "OSS {$this->ak}:{$signature}";
    }

    public function upload($file_path, $destination, $mime_type) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket) || empty($this->endpoint)) {
            return new TODO_Error('aliyun_config_error', '阿里云OSS配置不完整');
        }

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $uri = '/' . $this->bucket . '/' . ltrim($destination, '/');
        $url = 'https://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($destination, '/');
        
        $signature = $this->sign('PUT', $uri, $date, $mime_type);
        
        $ch = curl_init();
        // 因为可能是大文件，使用 CURL_INFILE 处理
        $file_handle = fopen($file_path, 'r');
        $file_size = filesize($file_path);
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $signature,
            'Date: ' . $date,
            'Content-Type: ' . $mime_type,
            'Content-Length: ' . $file_size
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($file_handle);
        
        if ($http_code === 200) {
            return true;
        }
        
        return new TODO_Error('aliyun_upload_error', '阿里云OSS上传失败 (HTTP ' . $http_code . '): ' . $response);
    }

    public function delete($file_key) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket) || empty($this->endpoint)) {
            return false;
        }

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $uri = '/' . $this->bucket . '/' . ltrim($file_key, '/');
        $url = 'https://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($file_key, '/');
        
        $signature = $this->sign('DELETE', $uri, $date);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $signature,
            'Date: ' . $date
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 204; // 204 No Content
    }

    public function get_url($file_key) {
        if (!empty($this->domain)) {
            $domain = rtrim($this->domain, '/');
            if (strpos($domain, 'http') !== 0) {
                $domain = 'http://' . $domain;
            }
            return $domain . '/' . ltrim($file_key, '/');
        }
        return 'https://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($file_key, '/');
    }

    public function exists($file_key) {
        if (empty($this->ak) || empty($this->sk) || empty($this->bucket) || empty($this->endpoint)) {
            return false;
        }

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $uri = '/' . $this->bucket . '/' . ltrim($file_key, '/');
        $url = 'https://' . $this->bucket . '.' . $this->endpoint . '/' . ltrim($file_key, '/');
        
        $signature = $this->sign('HEAD', $uri, $date);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $signature,
            'Date: ' . $date
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
}

/**
 * 腾讯云 COS 存储提供商
 */
class Todo_Tencent_Storage implements Todo_Storage_Provider_Interface {
    private $secret_id;
    private $secret_key;
    private $bucket;
    private $region;
    private $domain;

    public function __construct() {
        $this->secret_id = trim(get_option('storage_tencent_secret_id', ''));
        $this->secret_key = trim(get_option('storage_tencent_secret_key', ''));
        $this->bucket = trim(get_option('storage_tencent_bucket', ''));
        $region = trim(get_option('storage_tencent_region', ''));
        $this->region = rtrim(preg_replace('#^https?://#i', '', $region), '/');
        $this->domain = rtrim(trim(get_option('storage_tencent_domain', '')), '/');
    }

    private function get_host() {
        return $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
    }

    private function sign($method, $uri, $headers) {
        $key_time = time() . ';' . (time() + 3600);
        $sign_key = hash_hmac('sha1', $key_time, $this->secret_key, true);

        // 构造 HttpString
        $http_parameters = '';
        $header_keys = array_keys($headers);
        sort($header_keys);
        $header_list = implode(';', $header_keys);
        $header_kv_pairs = [];
        foreach ($header_keys as $k) {
            $header_kv_pairs[] = strtolower($k) . '=' . urlencode($headers[$k]);
        }
        $http_headers = implode('&', $header_kv_pairs);
        $http_string = strtolower($method) . "\n" . $uri . "\n" . $http_parameters . "\n" . $http_headers . "\n";

        // StringToSign
        $sha1_http_string = sha1($http_string);
        $string_to_sign = "sha1\n{$key_time}\n{$sha1_http_string}\n";

        // Signature
        $signature = hash_hmac('sha1', $string_to_sign, $sign_key, true);

        $param_list = '';
        return "q-sign-algorithm=sha1&q-ak={$this->secret_id}&q-sign-time={$key_time}&q-key-time={$key_time}&q-header-list={$header_list}&q-url-param-list={$param_list}&q-signature=" . bin2hex($signature);
    }

    public function upload($file_path, $destination, $mime_type) {
        if (empty($this->secret_id) || empty($this->secret_key) || empty($this->bucket) || empty($this->region)) {
            return new TODO_Error('tencent_config_error', '腾讯云COS配置不完整');
        }

        $host = $this->get_host();
        $uri = '/' . ltrim($destination, '/');
        $url = 'https://' . $host . $uri;

        $file_content = file_get_contents($file_path);
        $content_length = strlen($file_content);

        $headers = [
            'host' => $host,
            'content-type' => $mime_type,
            'content-length' => (string)$content_length,
        ];

        $authorization = $this->sign('put', $uri, $headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
            'Content-Type: ' . $mime_type,
            'Content-Length: ' . $content_length,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            return true;
        }

        return new TODO_Error('tencent_upload_error', '腾讯云COS上传失败 (HTTP ' . $http_code . '): ' . $response);
    }

    public function delete($file_key) {
        if (empty($this->secret_id) || empty($this->secret_key) || empty($this->bucket) || empty($this->region)) {
            return false;
        }

        $host = $this->get_host();
        $uri = '/' . ltrim($file_key, '/');
        $url = 'https://' . $host . $uri;

        $headers = [
            'host' => $host,
        ];

        $authorization = $this->sign('delete', $uri, $headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 204; // 204 No Content
    }

    public function get_url($file_key) {
        if (!empty($this->domain)) {
            $domain = rtrim($this->domain, '/');
            if (strpos($domain, 'http') !== 0) {
                $domain = 'https://' . $domain;
            }
            return $domain . '/' . ltrim($file_key, '/');
        }
        return 'https://' . $this->get_host() . '/' . ltrim($file_key, '/');
    }

    public function exists($file_key) {
        if (empty($this->secret_id) || empty($this->secret_key) || empty($this->bucket) || empty($this->region)) {
            return false;
        }

        $host = $this->get_host();
        $uri = '/' . ltrim($file_key, '/');
        $url = 'https://' . $host . $uri;

        $headers = [
            'host' => $host,
        ];

        $authorization = $this->sign('head', $uri, $headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
        ]);

        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 200;
    }
}

/**
 * 云存储管理器核心类
 */
class Todo_Storage_Manager {
    private static $instance = null;
    private $provider = null;
    private $provider_type = 'local';

    private function __construct() {
        $this->provider_type = get_option('storage_provider', 'local');
        
        if ($this->provider_type === 'qiniu') {
            $this->provider = new Todo_Qiniu_Storage();
        } elseif ($this->provider_type === 'aliyun') {
            $this->provider = new Todo_Aliyun_Storage();
        } elseif ($this->provider_type === 'tencent') {
            $this->provider = new Todo_Tencent_Storage();
        }
    }

    /**
     * 获取单例实例
     * @return Todo_Storage_Manager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取当前启用的提供商类型
     * @return string
     */
    public function get_provider_type() {
        return $this->provider_type;
    }

    /**
     * 是否为本地存储
     * @return bool
     */
    public function is_local() {
        return $this->provider_type === 'local' || $this->provider === null;
    }

    /**
     * 获取指定提供商实例（用于强制访问某个提供商，如历史数据同步）
     * @param string $type
     * @return Todo_Storage_Provider_Interface|null
     */
    public static function get_provider_by_type($type) {
        if ($type === 'qiniu') {
            return new Todo_Qiniu_Storage();
        } elseif ($type === 'aliyun') {
            return new Todo_Aliyun_Storage();
        } elseif ($type === 'tencent') {
            return new Todo_Tencent_Storage();
        }
        return null;
    }

    /**
     * 上传文件到云端（带重试机制）
     * 
     * @param string $file_path 本地临时或已保存文件的绝对路径
     * @param string $destination 云端存储的目标相对路径 (如 content/uploads/2026/05/abc.jpg)
     * @param string $mime_type 文件的 MIME
     * @return bool|TODO_Error
     */
    public function upload($file_path, $destination, $mime_type) {
        if ($this->is_local()) {
            return true; // 本地不需要二次云端上传
        }
        
        // 网络容错：尝试 3 次
        $max_retries = 3;
        $attempt = 0;
        $result = false;
        
        // 规整目标路径，确保无前置斜杠
        $destination = ltrim($destination, '/');
        
        while ($attempt < $max_retries) {
            $result = $this->provider->upload($file_path, $destination, $mime_type);
            if ($result === true) {
                return true;
            }
            $attempt++;
            if ($attempt < $max_retries) {
                sleep(1); // 网络抖动延时重试
            }
        }
        
        return $result; // 返回最后一次错误
    }

    /**
     * 从云端删除文件
     * 
     * @param string $file_key 相对路径键
     * @return bool
     */
    public function delete($file_key) {
        if ($this->is_local()) {
            return false;
        }
        return $this->provider->delete(ltrim($file_key, '/'));
    }

    /**
     * 获取云端 URL
     * 
     * @param string $file_key 相对路径键
     * @return string
     */
    public function get_url($file_key) {
        if ($this->is_local()) {
            // fallback
            return '';
        }
        return $this->provider->get_url($file_key);
    }
    
    /**
     * 检查云端文件是否存在
     * 
     * @param string $file_key 相对路径键
     * @return bool
     */
    public function exists($file_key) {
        if ($this->is_local()) {
            return file_exists(ABSPATH . ltrim($file_key, '/'));
        }
        return $this->provider->exists(ltrim($file_key, '/'));
    }
}

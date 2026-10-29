<?php
/**
 * TodoCMS兼容错误类
 *
 * @package TodoCMS
 * @version 1.0.0
 */

/**
 * TodoCMS兼容错误类
 */
class TODO_Error {
    /**
     * 错误代码
     *
     * @var array
     */
    private $errors = array();
    
    /**
     * 错误数据
     *
     * @var array
     */
    private $error_data = array();
    
    /**
     * 构造函数
     *
     * @param string|int $code 错误代码
     * @param string $message 错误消息
     * @param mixed $data 错误数据
     */
    public function __construct($code = '', $message = '', $data = '') {
        if (empty($code)) {
            return;
        }
        
        $this->add($code, $message, $data);
    }
    
    /**
     * 添加错误
     *
     * @param string|int $code 错误代码
     * @param string $message 错误消息
     * @param mixed $data 错误数据
     */
    public function add($code, $message, $data = '') {
        $this->errors[$code][] = $message;
        
        if (!empty($data)) {
            $this->error_data[$code] = $data;
        }
    }
    
    /**
     * 获取错误代码
     *
     * @return array 错误代码数组
     */
    public function get_error_codes() {
        if (empty($this->errors)) {
            return array();
        }
        
        return array_keys($this->errors);
    }
    
    /**
     * 获取第一个错误代码
     *
     * @return string|int 第一个错误代码
     */
    public function get_error_code() {
        $codes = $this->get_error_codes();
        if (empty($codes)) {
            return '';
        }
        
        return $codes[0];
    }
    
    /**
     * 获取错误消息
     *
     * @param string|int $code 错误代码
     * @return array 错误消息数组
     */
    public function get_error_messages($code = '') {
        if (empty($code)) {
            $all_messages = array();
            foreach ($this->errors as $code => $messages) {
                $all_messages = array_merge($all_messages, $messages);
            }
            return $all_messages;
        }
        
        if (isset($this->errors[$code])) {
            return $this->errors[$code];
        } else {
            return array();
        }
    }
    
    /**
     * 获取第一个错误消息
     *
     * @param string|int $code 错误代码
     * @return string 第一个错误消息
     */
    public function get_error_message($code = '') {
        if (empty($code)) {
            $code = $this->get_error_code();
        }
        
        $messages = $this->get_error_messages($code);
        if (empty($messages)) {
            return '';
        }
        
        return $messages[0];
    }
    
    /**
     * 获取错误数据
     *
     * @param string|int $code 错误代码
     * @return mixed 错误数据
     */
    public function get_error_data($code = '') {
        if (empty($code)) {
            $code = $this->get_error_code();
        }
        
        if (isset($this->error_data[$code])) {
            return $this->error_data[$code];
        }
        
        return null;
    }
    
    /**
     * 检查是否有错误
     *
     * @return bool 是否有错误
     */
    public function has_errors() {
        return !empty($this->errors);
    }
}
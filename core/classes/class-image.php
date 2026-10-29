<?php
/**
 * 图像处理类
 *
 * 处理图像的缩放、裁剪、旋转等操作
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit('Direct access denied.');
}
 
class CMS_Image {
    /**
     * 图像资源
     *
     * @var resource|GdImage|null
     */
    private $image = null;
    
    /**
     * 图像类型
     *
     * @var string
     */
    private $type = '';
    
    /**
     * 图像宽度
     *
     * @var int
     */
    private $width = 0;
    
    /**
     * 图像高度
     *
     * @var int
     */
    private $height = 0;
    
    /**
     * 图像文件路径
     *
     * @var string
     */
    private $file = '';
    
    /**
     * 构造函数
     *
     * @param string $file 图像文件路径
     */
    public function __construct($file = '') {
        if (!empty($file)) {
            $this->load($file);
        }
    }
    
    /**
     * 析构函数
     */
    public function __destruct() {
        $this->destroy();
    }
    
    /**
     * 加载图像
     *
     * @param string $file 图像文件路径
     * @return bool 是否成功加载
     */
    public function load($file) {
        if (!file_exists($file)) {
            return false;
        }
        
        $this->file = $file;
        $info = getimagesize($file);
        
        if (!$info) {
            return false;
        }
        
        $this->width = $info[0];
        $this->height = $info[1];
        
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $this->image = imagecreatefromjpeg($file);
                $this->type = 'jpeg';
                break;
            case IMAGETYPE_PNG:
                $this->image = imagecreatefrompng($file);
                $this->type = 'png';
                break;
            case IMAGETYPE_GIF:
                $this->image = imagecreatefromgif($file);
                $this->type = 'gif';
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $this->image = imagecreatefromwebp($file);
                    $this->type = 'webp';
                }
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagecreatefrombmp')) {
                    $this->image = imagecreatefrombmp($file);
                    $this->type = 'bmp';
                }
                break;
            default:
                return false;
        }
        
        if (!$this->image) {
            return false;
        }
        
        // 保持透明度
        if ($this->type == 'png' || $this->type == 'gif') {
            imagealphablending($this->image, false);
            imagesavealpha($this->image, true);
        }
        
        return true;
    }
    
    /**
     * 保存图像
     *
     * @param string $file 保存路径
     * @param int $quality 图像质量（0-100）
     * @param string $type 图像类型（可选，默认使用原图类型）
     * @return bool 是否成功保存
     */
    public function save($file, $quality = 90, $type = '') {
        if (!$this->image) {
            return false;
        }
        
        // 如果未指定类型，使用原图类型
        if (empty($type)) {
            $type = $this->type;
        }
        
        // 确保目录存在
        $dir = dirname($file);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $result = false;
        
        switch ($type) {
            case 'jpeg':
            case 'jpg':
                $result = imagejpeg($this->image, $file, $quality);
                break;
            case 'png':
                // PNG质量范围是0-9，需要转换
                $png_quality = 9 - round(($quality / 100) * 9);
                $result = imagepng($this->image, $file, $png_quality);
                break;
            case 'gif':
                $result = imagegif($this->image, $file);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $result = imagewebp($this->image, $file, $quality);
                }
                break;
            case 'bmp':
                if (function_exists('imagebmp')) {
                    $result = imagebmp($this->image, $file);
                }
                break;
        }
        
        return $result;
    }
    
    /**
     * 调整图像大小
     *
     * @param int $width 新宽度
     * @param int $height 新高度
     * @param bool $crop 是否裁剪
     * @return bool 是否成功调整大小
     */
    public function resize($width, $height, $crop = false) {
        if (!$this->image) {
            return false;
        }
        
        // 如果宽度或高度为0，保持原始比例
        if ($width == 0) {
            $width = $this->width * ($height / $this->height);
        }
        
        if ($height == 0) {
            $height = $this->height * ($width / $this->width);
        }
        
        // 如果不裁剪，保持原始比例
        if (!$crop) {
            // 计算缩放比例
            $ratio_orig = $this->width / $this->height;
            $ratio_new = $width / $height;
            
            if ($ratio_new < $ratio_orig) {
                // 宽度限制
                $height = $width / $ratio_orig;
            } else {
                // 高度限制
                $width = $height * $ratio_orig;
            }
        }
        
        // 创建新图像
        $new_image = imagecreatetruecolor($width, $height);
        
        // 保持透明度
        if ($this->type == 'png' || $this->type == 'gif') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $width, $height, $transparent);
        }
        
        // 调整大小
        if ($crop) {
            // 计算裁剪坐标
            $ratio_orig = $this->width / $this->height;
            $ratio_new = $width / $height;
            
            if ($ratio_new > $ratio_orig) {
                // 宽度优先
                $src_w = $this->width;
                $src_h = $this->width / $ratio_new;
                $src_x = 0;
                $src_y = ($this->height - $src_h) / 2;
            } else {
                // 高度优先
                $src_w = $this->height * $ratio_new;
                $src_h = $this->height;
                $src_x = ($this->width - $src_w) / 2;
                $src_y = 0;
            }
            
            // 裁剪并调整大小
            imagecopyresampled($new_image, $this->image, 0, 0, $src_x, $src_y, $width, $height, $src_w, $src_h);
        } else {
            // 直接调整大小
            imagecopyresampled($new_image, $this->image, 0, 0, 0, 0, $width, $height, $this->width, $this->height);
        }
        
        // 替换原图像
        $this->destroy();
        $this->image = $new_image;
        $this->width = $width;
        $this->height = $height;
        
        return true;
    }
    
    /**
     * 裁剪图像
     *
     * @param int $x 起始X坐标
     * @param int $y 起始Y坐标
     * @param int $width 裁剪宽度
     * @param int $height 裁剪高度
     * @return bool 是否成功裁剪
     */
    public function crop($x, $y, $width, $height) {
        if (!$this->image) {
            return false;
        }
        
        // 确保裁剪区域在图像范围内
        if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 || 
            $x + $width > $this->width || $y + $height > $this->height) {
            return false;
        }
        
        // 创建新图像
        $new_image = imagecreatetruecolor($width, $height);
        
        // 保持透明度
        if ($this->type == 'png' || $this->type == 'gif') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $width, $height, $transparent);
        }
        
        // 裁剪图像
        imagecopy($new_image, $this->image, 0, 0, $x, $y, $width, $height);
        
        // 替换原图像
        $this->destroy();
        $this->image = $new_image;
        $this->width = $width;
        $this->height = $height;
        
        return true;
    }
    
    /**
     * 旋转图像
     *
     * @param float $angle 旋转角度
     * @param int $bg_color 背景颜色（RGB）
     * @return bool 是否成功旋转
     */
    public function rotate($angle, $bg_color = 0xFFFFFF) {
        if (!$this->image) {
            return false;
        }
        
        // 旋转图像
        $transparent = imagecolorallocatealpha($this->image, 255, 255, 255, 127);
        $rotated = imagerotate($this->image, $angle, $transparent);
        
        if (!$rotated) {
            return false;
        }
        
        // 替换原图像
        $this->destroy();
        $this->image = $rotated;
        $this->width = imagesx($rotated);
        $this->height = imagesy($rotated);
        
        return true;
    }
    
    /**
     * 翻转图像
     *
     * @param bool $horizontal 是否水平翻转
     * @return bool 是否成功翻转
     */
    public function flip($horizontal = true) {
        if (!$this->image) {
            return false;
        }
        
        // 创建新图像
        $new_image = imagecreatetruecolor($this->width, $this->height);
        
        // 保持透明度
        if ($this->type == 'png' || $this->type == 'gif') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $this->width, $this->height, $transparent);
        }
        
        // 翻转图像
        if ($horizontal) {
            // 水平翻转
            for ($x = 0; $x < $this->width; $x++) {
                imagecopy($new_image, $this->image, $this->width - $x - 1, 0, $x, 0, 1, $this->height);
            }
        } else {
            // 垂直翻转
            for ($y = 0; $y < $this->height; $y++) {
                imagecopy($new_image, $this->image, 0, $this->height - $y - 1, 0, $y, $this->width, 1);
            }
        }
        
        // 替换原图像
        $this->destroy();
        $this->image = $new_image;
        
        return true;
    }
    
    /**
     * 添加水印
     *
     * @param string $watermark 水印图像路径
     * @param string $position 水印位置（top-left, top-right, bottom-left, bottom-right, center）
     * @param int $opacity 不透明度（0-100）
     * @return bool 是否成功添加水印
     */
    public function watermark($watermark, $position = 'bottom-right', $opacity = 50) {
        if (!$this->image || !file_exists($watermark)) {
            return false;
        }
        
        // 加载水印图像
        $watermark_info = getimagesize($watermark);
        $watermark_width = $watermark_info[0];
        $watermark_height = $watermark_info[1];
        
        $watermark_image = null;
        
        switch ($watermark_info[2]) {
            case IMAGETYPE_JPEG:
                $watermark_image = imagecreatefromjpeg($watermark);
                break;
            case IMAGETYPE_PNG:
                $watermark_image = imagecreatefrompng($watermark);
                break;
            case IMAGETYPE_GIF:
                $watermark_image = imagecreatefromgif($watermark);
                break;
            default:
                return false;
        }
        
        if (!$watermark_image) {
            return false;
        }
        
        // 计算水印位置
        $x = 0;
        $y = 0;
        
        switch ($position) {
            case 'top-left':
                $x = 10;
                $y = 10;
                break;
            case 'top-right':
                $x = $this->width - $watermark_width - 10;
                $y = 10;
                break;
            case 'bottom-left':
                $x = 10;
                $y = $this->height - $watermark_height - 10;
                break;
            case 'bottom-right':
                $x = $this->width - $watermark_width - 10;
                $y = $this->height - $watermark_height - 10;
                break;
            case 'center':
                $x = ($this->width - $watermark_width) / 2;
                $y = ($this->height - $watermark_height) / 2;
                break;
        }
        
        // 设置水印透明度
        if ($opacity < 100) {
            // 创建透明水印
            $transparent = imagecolorallocatealpha($watermark_image, 255, 255, 255, 127 * (100 - $opacity) / 100);
            imagefill($watermark_image, 0, 0, $transparent);
        }
        
        // 添加水印
        imagecopy($this->image, $watermark_image, $x, $y, 0, 0, $watermark_width, $watermark_height);
        
        // 释放水印资源
        imagedestroy($watermark_image);
        
        return true;
    }
    
    /**
     * 添加文字水印
     *
     * @param string $text 水印文字
     * @param string $font 字体文件路径
     * @param int $size 字体大小
     * @param string $color 字体颜色（十六进制，如 #FFFFFF）
     * @param string $position 水印位置
     * @return bool 是否成功添加文字水印
     */
    public function text_watermark($text, $font, $size = 12, $color = '#FFFFFF', $position = 'bottom-right') {
        if (!$this->image || !file_exists($font)) {
            return false;
        }
        
        // 解析颜色
        $color = str_replace('#', '', $color);
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        
        // 创建颜色
        $text_color = imagecolorallocate($this->image, $r, $g, $b);
        
        // 计算文字大小
        $box = imagettfbbox($size, 0, $font, $text);
        $text_width = abs($box[4] - $box[0]);
        $text_height = abs($box[5] - $box[1]);
        
        // 计算文字位置
        $x = 0;
        $y = 0;
        
        switch ($position) {
            case 'top-left':
                $x = 10;
                $y = 10 + $text_height;
                break;
            case 'top-right':
                $x = $this->width - $text_width - 10;
                $y = 10 + $text_height;
                break;
            case 'bottom-left':
                $x = 10;
                $y = $this->height - 10;
                break;
            case 'bottom-right':
                $x = $this->width - $text_width - 10;
                $y = $this->height - 10;
                break;
            case 'center':
                $x = ($this->width - $text_width) / 2;
                $y = ($this->height + $text_height) / 2;
                break;
        }
        
        // 添加文字水印
        imagettftext($this->image, $size, 0, $x, $y, $text_color, $font, $text);
        
        return true;
    }
    
    /**
     * 应用滤镜效果
     *
     * @param string $filter 滤镜类型（grayscale, sepia, negative, blur, sharpen）
     * @param int $arg 滤镜参数（可选）
     * @return bool 是否成功应用滤镜
     */
    public function filter($filter, $arg = 0) {
        if (!$this->image) {
            return false;
        }
        
        switch ($filter) {
            case 'grayscale':
                imagefilter($this->image, IMG_FILTER_GRAYSCALE);
                break;
            case 'sepia':
                imagefilter($this->image, IMG_FILTER_GRAYSCALE);
                imagefilter($this->image, IMG_FILTER_COLORIZE, 90, 60, 40);
                break;
            case 'negative':
                imagefilter($this->image, IMG_FILTER_NEGATE);
                break;
            case 'blur':
                if (function_exists('imagefilter')) {
                    for ($i = 0; $i < $arg; $i++) {
                        imagefilter($this->image, IMG_FILTER_GAUSSIAN_BLUR);
                    }
                }
                break;
            case 'sharpen':
                $matrix = [
                    [-1, -1, -1],
                    [-1, 16, -1],
                    [-1, -1, -1]
                ];
                $divisor = array_sum(array_map('array_sum', $matrix));
                $offset = 0;
                
                if (function_exists('imageconvolution')) {
                    imageconvolution($this->image, $matrix, $divisor, $offset);
                }
                break;
            default:
                return false;
        }
        
        return true;
    }
    
    /**
     * 获取图像宽度
     *
     * @return int 图像宽度
     */
    public function get_width() {
        return $this->width;
    }
    
    /**
     * 获取图像高度
     *
     * @return int 图像高度
     */
    public function get_height() {
        return $this->height;
    }
    
    /**
     * 获取图像类型
     *
     * @return string 图像类型
     */
    public function get_type() {
        return $this->type;
    }
    
    /**
     * 销毁图像资源
     */
    public function destroy() {
        if ($this->image) {
            imagedestroy($this->image);
            $this->image = null;
        }
    }
}

/**
 * 图像编辑器类
 *
 * 用于处理图像编辑操作
 */
class CMS_Image_Editor {
    /**
     * 图像对象
     *
     * @var CMS_Image
     */
    private $image;
    
    /**
     * 原始文件路径
     *
     * @var string
     */
    private $file;
    
    /**
     * 构造函数
     *
     * @param string $file 图像文件路径
     */
    public function __construct($file) {
        $this->file = $file;
        $this->image = new CMS_Image($file);
    }
    
    /**
     * 调整图像大小
     *
     * @param int $width 新宽度
     * @param int $height 新高度
     * @param bool $crop 是否裁剪
     * @return bool|CMS_Error 成功返回true，失败返回错误对象
     */
    public function resize($width, $height, $crop = false) {
        if (!$this->image) {
            return new CMS_Error('image_resize_error', '无法加载图像进行调整大小');
        }
        
        $result = $this->image->resize($width, $height, $crop);
        
        if (!$result) {
            return new CMS_Error('image_resize_error', '调整图像大小失败');
        }
        
        return true;
    }
    
    /**
     * 裁剪图像
     *
     * @param int $x 起始X坐标
     * @param int $y 起始Y坐标
     * @param int $width 裁剪宽度
     * @param int $height 裁剪高度
     * @return bool|CMS_Error 成功返回true，失败返回错误对象
     */
    public function crop($x, $y, $width, $height) {
        if (!$this->image) {
            return new CMS_Error('image_crop_error', '无法加载图像进行裁剪');
        }
        
        $result = $this->image->crop($x, $y, $width, $height);
        
        if (!$result) {
            return new CMS_Error('image_crop_error', '裁剪图像失败');
        }
        
        return true;
    }
    
    /**
     * 旋转图像
     *
     * @param float $angle 旋转角度
     * @return bool|CMS_Error 成功返回true，失败返回错误对象
     */
    public function rotate($angle) {
        if (!$this->image) {
            return new CMS_Error('image_rotate_error', '无法加载图像进行旋转');
        }
        
        $result = $this->image->rotate($angle);
        
        if (!$result) {
            return new CMS_Error('image_rotate_error', '旋转图像失败');
        }
        
        return true;
    }
    
    /**
     * 翻转图像
     *
     * @param bool $horizontal 是否水平翻转
     * @return bool|CMS_Error 成功返回true，失败返回错误对象
     */
    public function flip($horizontal) {
        if (!$this->image) {
            return new CMS_Error('image_flip_error', '无法加载图像进行翻转');
        }
        
        $result = $this->image->flip($horizontal);
        
        if (!$result) {
            return new CMS_Error('image_flip_error', '翻转图像失败');
        }
        
        return true;
    }
    
    /**
     * 保存图像
     *
     * @param string $file 保存路径（可选，默认覆盖原文件）
     * @param string $mime_type MIME类型（可选）
     * @return array|CMS_Error 成功返回图像信息数组，失败返回错误对象
     */
    public function save($file = null, $mime_type = null) {
        if (!$this->image) {
            return new CMS_Error('image_save_error', '无法保存图像');
        }
        
        // 如果未指定保存路径，使用原文件路径
        if (null === $file) {
            $file = $this->file;
        }
        
        // 确定图像类型
        $type = '';
        if ($mime_type) {
            switch ($mime_type) {
                case 'image/jpeg':
                    $type = 'jpeg';
                    break;
                case 'image/png':
                    $type = 'png';
                    break;
                case 'image/gif':
                    $type = 'gif';
                    break;
                case 'image/webp':
                    $type = 'webp';
                    break;
            }
        }
        
        // 保存图像
        $result = $this->image->save($file, 90, $type);
        
        if (!$result) {
            return new CMS_Error('image_save_error', '保存图像失败');
        }
        
        // 返回图像信息
        return [
            'path' => $file,
            'file' => basename($file),
            'width' => $this->image->get_width(),
            'height' => $this->image->get_height(),
            'mime-type' => $mime_type ?: 'image/' . $this->image->get_type(),
        ];
    }
    
    /**
     * 获取图像尺寸
     *
     * @return array 包含宽度和高度的数组
     */
    public function get_size() {
        return [
            'width' => $this->image->get_width(),
            'height' => $this->image->get_height(),
        ];
    }
}
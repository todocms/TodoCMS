<?php
/**
 * 滑动验证码类
 *
 * @package TodoCMS
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Todo_Sliding_Captcha {
    
    /**
     * 验证码图片的宽度
     */
    private $width = 300;
    
    /**
     * 验证码图片的高度
     */
    private $height = 150;
    
    /**
     * 拼图块的宽度
     */
    private $piece_width = 50;
    
    /**
     * 拼图块的高度
     */
    private $piece_height = 50;
    
    /**
     * 容差值（像素）
     */
    private $tolerance = 5;

    /**
     * 构造函数
     */
    public function __construct() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * 生成验证码
     *
     * @return array 包含背景图、滑块图、Y轴坐标的数组
     */
    public function create() {
        // 生成 PoW Challenge Seed (用于前端算力验证)
        $pow_seed = bin2hex(random_bytes(8));
        $difficulty = 3; // 难度系数 (需要找到 hash 前缀为 3 个 '0' 的 nonce)
        $_SESSION['todo_pow_challenge'] = [
            'seed' => $pow_seed,
            'difficulty' => $difficulty,
            'created_at' => time()
        ];

        // 创建背景图
        $im_bg = imagecreatetruecolor($this->width, $this->height);
        
        // 填充背景（随机颜色噪点与形状）
        $this->fill_background($im_bg);
        
        // 生成拼图位置
        $target_x = rand($this->piece_width + 20, $this->width - $this->piece_width - 10);
        $target_y = rand(10, $this->height - $this->piece_height - 10);
        
        // 保存目标X坐标到会话
        $_SESSION['todo_captcha_x'] = $target_x;
        $_SESSION['todo_captcha_created_at'] = microtime(true);
        
        // 随机选择形状
        $shapes = ['circle', 'square', 'triangle', 'star', 'heart'];
        $shape_type = $shapes[array_rand($shapes)];
        
        // 随机旋转角度 (0-360度)
        $rotation_angle = rand(0, 360);
        
        // 创建滑块图（透明背景）
        $im_piece = imagecreatetruecolor($this->piece_width, $this->piece_height);
        imagealphablending($im_piece, false);
        imagesavealpha($im_piece, true);
        $transparent = imagecolorallocatealpha($im_piece, 0, 0, 0, 127);
        imagefill($im_piece, 0, 0, $transparent);
        
        // 使用遮罩提取滑块内容
        $mask = imagecreatetruecolor($this->piece_width, $this->piece_height);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        
        $opaque = imagecolorallocatealpha($mask, 255, 255, 255, 0);
        $this->draw_shape($mask, $shape_type, $this->piece_width/2, $this->piece_height/2, $this->piece_width/2 - 2, $opaque, true, $rotation_angle);
        
        $temp_piece = imagecreatetruecolor($this->piece_width, $this->piece_height);
        imagecopy($temp_piece, $im_bg, 0, 0, $target_x, $target_y, $this->piece_width, $this->piece_height);
        
        for ($x = 0; $x < $this->piece_width; $x++) {
            for ($y = 0; $y < $this->piece_height; $y++) {
                $alpha = (imagecolorat($mask, $x, $y) >> 24) & 0x7F;
                if ($alpha == 0) {
                    $color = imagecolorat($temp_piece, $x, $y);
                    imagesetpixel($im_piece, $x, $y, $color);
                }
            }
        }
        imagedestroy($mask);
        imagedestroy($temp_piece);
        
        $white = imagecolorallocatealpha($im_piece, 255, 255, 255, 127);
        $this->draw_shape($im_piece, $shape_type, $this->piece_width/2, $this->piece_height/2, $this->piece_width/2 - 2, $white, false, $rotation_angle);
        
        $this->create_hole($im_bg, $target_x, $target_y, $shape_type, $rotation_angle);
        
        // WebP 优先压缩输出（降幅可达 60-80%），若服务器不支持 WebP 则回退到 PNG
        $supports_webp = function_exists('imagewebp');
        $mime_type = $supports_webp ? 'image/webp' : 'image/png';

        ob_start();
        if ($supports_webp) {
            imagewebp($im_bg, null, 75);
        } else {
            imagepng($im_bg);
        }
        $bg_data = base64_encode(ob_get_clean());
        imagedestroy($im_bg);
        
        ob_start();
        if ($supports_webp) {
            imagewebp($im_piece, null, 75);
        } else {
            imagepng($im_piece);
        }
        $piece_data = base64_encode(ob_get_clean());
        imagedestroy($im_piece);
        
        return [
            'bg' => 'data:' . $mime_type . ';base64,' . $bg_data,
            'piece' => 'data:' . $mime_type . ';base64,' . $piece_data,
            'y' => $target_y,
            'width' => $this->width,
            'height' => $this->height,
            'piece_width' => $this->piece_width,
            'piece_height' => $this->piece_height,
            'pow_seed' => $pow_seed,
            'pow_difficulty' => $difficulty
        ];
    }

    /**
     * 校验 PoW 前端算力
     */
    public function verify_pow($nonce) {
        if (!isset($_SESSION['todo_pow_challenge'])) {
            return false;
        }
        $challenge = $_SESSION['todo_pow_challenge'];
        $seed = $challenge['seed'];
        $difficulty = $challenge['difficulty'];

        // 验证计算结果: sha256(seed + nonce) 前 N 个字符必须为 '0'
        $hash = hash('sha256', $seed . (string)$nonce);
        $target_prefix = str_repeat('0', $difficulty);
        $is_valid = (substr($hash, 0, $difficulty) === $target_prefix);

        // 只有当验证成功时才销毁 Token；如果失败则保留，避免网络抖动或未获取新验证码时二次尝试报错
        if ($is_valid) {
            unset($_SESSION['todo_pow_challenge']);
        }

        return $is_valid;
    }

    /**
     * 验证位置及行为轨迹 (Behavioral Track Analysis)
     *
     * @param int $x_offset 用户滑动的X偏移量
     * @param array $track 鼠标/触控轨迹点集合 [{x, y, t}]
     * @return bool
     */
    public function verify($x_offset, $track = []) {
        if (!isset($_SESSION['todo_captcha_x'])) {
            return false;
        }
        
        $target_x = $_SESSION['todo_captcha_x'];
        $created_at = $_SESSION['todo_captcha_created_at'] ?? 0;

        // 1. 位置匹配验证
        if (abs($x_offset - $target_x) > $this->tolerance) {
            return false;
        }

        // 2. 时间合法性验证 (反应时间不能低于 150ms，且不能超过 5 分钟)
        $now = microtime(true);
        if ($created_at > 0) {
            $duration = ($now - $created_at) * 1000;
            if ($duration < 150 || $duration > 300000) {
                return false;
            }
        }

        // 3. 拖拽行为轨迹学校验 (人类移动物理特征分析)
        if (!empty($track) && is_array($track) && count($track) >= 4) {
            $count = count($track);
            $has_y_jitter = false; // 是否有 Y 轴微抖动/微调
            $velocities = [];
            
            for ($i = 1; $i < $count; $i++) {
                $prev = $track[$i - 1];
                $curr = $track[$i];
                
                $dx = ($curr['x'] ?? 0) - ($prev['x'] ?? 0);
                $dy = ($curr['y'] ?? 0) - ($prev['y'] ?? 0);
                $dt = ($curr['t'] ?? 0) - ($prev['t'] ?? 0);
                
                if (abs($dy) > 0) {
                    $has_y_jitter = true;
                }
                
                if ($dt > 0) {
                    $velocities[] = sqrt($dx * $dx + $dy * $dy) / $dt;
                }
            }

            // 人类拖动必然存在速度变化（加速->减速/停顿）与抖动
            if (count($velocities) >= 3) {
                $v_start = $velocities[0];
                $v_mid = $velocities[intval(count($velocities) / 2)];
                $v_end = $velocities[count($velocities) - 1];

                // 如果速度完全匀速（如标准直线软件注入），拒绝
                $is_perfectly_uniform = true;
                foreach ($velocities as $v) {
                    if (abs($v - $v_start) > 0.05) {
                        $is_perfectly_uniform = false;
                        break;
                    }
                }
                if ($is_perfectly_uniform) {
                    return false;
                }
            }
        }

        return true;
    }
    
    /**
     * 绘制形状
     * 
     * @param resource $im 图像资源
     * @param string $type 形状类型
     * @param int $cx 中心X
     * @param int $cy 中心Y
     * @param int $r 半径
     * @param int $color 颜色
     * @param bool $filled 是否填充
     * @param int $angle 旋转角度 (度)
     */
    private function draw_shape($im, $type, $cx, $cy, $r, $color, $filled = true, $angle = 0) {
        // 转换角度为弧度
        $rad = deg2rad($angle);
        
        switch ($type) {
            case 'circle':
                // 圆形旋转无视觉变化，除非有纹理，这里保持原样
                if ($filled) {
                    imagefilledellipse($im, $cx, $cy, $r*2, $r*2, $color);
                } else {
                    imagearc($im, $cx, $cy, $r*2, $r*2, 0, 360, $color);
                }
                break;
                
            case 'square':
                // 方形转为多边形以支持旋转
                $points = [
                    $cx - $r, $cy - $r, // 左上
                    $cx + $r, $cy - $r, // 右上
                    $cx + $r, $cy + $r, // 右下
                    $cx - $r, $cy + $r  // 左下
                ];
                
                // 旋转点
                $rotated_points = $this->rotate_points($points, $cx, $cy, $rad);
                
                if ($filled) {
                    imagefilledpolygon($im, $rotated_points, 4, $color);
                } else {
                    imagepolygon($im, $rotated_points, 4, $color);
                }
                break;
                
            case 'triangle':
                // 正三角形
                $points = [
                    $cx, $cy - $r, // 顶点
                    $cx + $r * 0.866, $cy + $r * 0.5, // 右下
                    $cx - $r * 0.866, $cy + $r * 0.5  // 左下
                ];
                
                $rotated_points = $this->rotate_points($points, $cx, $cy, $rad);
                
                if ($filled) {
                    imagefilledpolygon($im, $rotated_points, 3, $color);
                } else {
                    imagepolygon($im, $rotated_points, 3, $color);
                }
                break;
                
            case 'star':
                // 五角星
                $points = [];
                for ($i = 0; $i < 10; $i++) {
                    // 加上旋转角度
                    $point_angle = $i * 36 * M_PI / 180 - M_PI / 2 + $rad; 
                    $len = ($i % 2 == 0) ? $r : $r * 0.4; // 外半径r，内半径0.4r
                    $points[] = $cx + $len * cos($point_angle);
                    $points[] = $cy + $len * sin($point_angle);
                }
                if ($filled) {
                    imagefilledpolygon($im, $points, 10, $color);
                } else {
                    imagepolygon($im, $points, 10, $color);
                }
                break;
                
            case 'heart':
                // 心形拟合
                $points = [];
                $step = 0.1;
                $scale = $r / 16; 
                
                for ($t = 0; $t < 2 * M_PI; $t += $step) {
                    $x = 16 * pow(sin($t), 3);
                    $y = -(13 * cos($t) - 5 * cos(2*$t) - 2 * cos(3*$t) - cos(4*$t)); // y反转
                    
                    // 原始坐标
                    $px = $cx + $x * $scale;
                    $py = $cy + $y * $scale;
                    
                    // 旋转坐标
                    $points[] = $cx + ($px - $cx) * cos($rad) - ($py - $cy) * sin($rad);
                    $points[] = $cy + ($px - $cx) * sin($rad) + ($py - $cy) * cos($rad);
                }
                
                $num_points = count($points) / 2;
                if ($filled) {
                    imagefilledpolygon($im, $points, $num_points, $color);
                } else {
                    imagepolygon($im, $points, $num_points, $color);
                }
                break;
        }
    }

    /**
     * 旋转多边形顶点
     */
    private function rotate_points($points, $cx, $cy, $rad) {
        $rotated = [];
        for ($i = 0; $i < count($points); $i += 2) {
            $x = $points[$i];
            $y = $points[$i+1];
            
            $rotated[] = $cx + ($x - $cx) * cos($rad) - ($y - $cy) * sin($rad);
            $rotated[] = $cy + ($x - $cx) * sin($rad) + ($y - $cy) * cos($rad);
        }
        return $rotated;
    }

    /**
     * 填充背景
     */
    private function fill_background($im) {
        // 随机背景色
        $bg_color = imagecolorallocate($im, rand(200, 255), rand(200, 255), rand(200, 255));
        imagefill($im, 0, 0, $bg_color);
        
        // 添加一些干扰线和点
        for ($i = 0; $i < 50; $i++) {
            $color = imagecolorallocate($im, rand(100, 200), rand(100, 200), rand(100, 200));
            imageline($im, rand(0, $this->width), rand(0, $this->height), rand(0, $this->width), rand(0, $this->height), $color);
        }
        
        for ($i = 0; $i < 200; $i++) {
            $color = imagecolorallocate($im, rand(100, 200), rand(100, 200), rand(100, 200));
            imagesetpixel($im, rand(0, $this->width), rand(0, $this->height), $color);
        }
        
        // 尝试加载 content/uploads 目录下的图片作为背景（如果存在）
        // 这里简化处理，只用噪点生成
        
        // 添加文字干扰
        $text_color = imagecolorallocate($im, rand(150, 200), rand(150, 200), rand(150, 200));
        imagestring($im, 5, rand(10, 50), rand(10, 50), 'Todo CMS', $text_color);
    }
    
    /**
     * 在背景上挖洞
     */
    private function create_hole($im, $x, $y, $shape_type = 'circle') {
        $cx = $x + $this->piece_width/2;
        $cy = $y + $this->piece_height/2;
        $r = $this->piece_width/2 - 2;
        
        // 在背景对应位置画一个半透明的形状
        $hole_color = imagecolorallocatealpha($im, 255, 255, 255, 0);
        $this->draw_shape($im, $shape_type, $cx, $cy, $r, $hole_color, true);
        
        $border_color = imagecolorallocatealpha($im, 255, 255, 255, 80);
        $this->draw_shape($im, $shape_type, $cx, $cy, $r, $border_color, false);
    }
}

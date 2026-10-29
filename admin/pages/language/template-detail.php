<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
  define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// 防止直接访问
if (!defined('ABSPATH')) {
  exit('Direct access denied');
}

// 权限校验（与语言页保持一致）
if (!is_user_logged_in()) {
  todo_redirect(todo_admin_url('index.php?page=dashboard'));
  exit;
}

// 页面标题
$page_title = '模板扫描';
$page_subtitle = '';

// 获取当前设备类型（pc/mobile），默认 pc
$device_type = isset($_GET['type']) && in_array($_GET['type'], ['pc', 'mobile'], true) ? $_GET['type'] : 'pc';

// 解析当前主题（支持外部传入 theme 参数查看任意已安装主题）
$requested_theme = isset($_GET['theme']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['theme']) : '';
if ($requested_theme !== '') {
  // 校验主题目录是否存在
  $check_dir = ABSPATH . THEMES_DIR . '/' . $requested_theme;
  if (is_dir($check_dir)) {
    $active_theme = $requested_theme;
  } else {
    $active_theme = get_theme_name();
    $errors[] = '指定的主题不存在: ' . esc_html($requested_theme) . '，已回退到当前激活主题';
  }
} else {
  $active_theme = get_theme_name();
}
if (empty($active_theme)) {
  $active_theme = 'default';
}

// 使用 CMS_Theme 获取正确的模板目录（支持 pc/mobile 子目录）
$theme_base = ABSPATH . THEMES_DIR . '/' . $active_theme;
$real_theme_base = realpath($theme_base);
if (!$real_theme_base) {
  $real_theme_base = $theme_base;
}

$theme_obj = new CMS_Theme($active_theme);
$template_dir = $theme_obj->get_template_directory($device_type);
$real_template_dir = realpath($template_dir);
if (!$real_template_dir) {
  $real_template_dir = $template_dir;
}

/**
 * 递归收集主题目录下所有 .htm/.html 模板文件
 */
if (!function_exists('get_theme_template_files')) {
  function get_theme_template_files($base_dir, $device_type = null) {
    $files = [];
    $real_base = realpath($base_dir);
    if (!$real_base || !is_dir($real_base)) {
      return $files;
    }

    $rii = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($real_base, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
      if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        if (in_array($ext, ['htm', 'html'], true)) {
          $file_path = $file->getPathname();
          $rel_path = str_replace($real_base . '/', '', $file_path);
          $files[] = [
            'path' => $file_path,
            'rel_path' => $rel_path,
            'basename' => $file->getFilename(),
            'device' => $device_type,
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
          ];
        }
      }
    }

    usort($files, function ($a, $b) {
      return strcmp($a['rel_path'], $b['rel_path']);
    });

    return $files;
  }
}

/**
 * 提取模板及 JS 中所有已标记的语言标签
 *
 * 支持格式：
 * 1. {{todo:t text="原文"}}
 * 2. {{todo:t text='原文'}}
 * 3. {{todo:t|text="原文"}}
 * 4. {{todo:t|text='原文'}}
 * 5. {{todo:t|text=原文}}
 * 6. __t("原文")
 * 7. __t('原文')
 *
 * @param string $content 模板或 JS 内容
 * @return array [['full_tag', 'original_text', 'context', 'format'], ...]
 */
if (!function_exists('extract_all_language_tags')) {
  function extract_all_language_tags($content) {
    $tags = [];

    // 1. 模板标签：{{todo:t text="原文"}} / {{todo:t|text='原文'}} / {{todo:t|text=原文}} 等
    $pattern_tpl = '/\{\{todo:t\s*(?:\|\s*)?text\s*[=:]\s*(?:(["\'])(.*?)\1|([^\s|}]+))(?:\|\s*([^}]*))?\s*\}\}/i';
    if (preg_match_all($pattern_tpl, $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $raw_text = !empty($m[2]) ? stripslashes($m[2]) : (isset($m[3]) ? $m[3] : '');
        $text = trim($raw_text);
        if ($text !== '') {
          $tags[] = [
            'full_tag' => $m[0],
            'original_text' => $text,
            'context' => isset($m[4]) ? trim($m[4]) : null,
            'format' => 'v3',
          ];
        }
      }
    }

    // 2. JS / PHP 翻译函数：__('原文') / __t("原文") / _e("原文") / _x("原文", ...) / esc_html__("原文") 等
    $pattern_jt = '/(?:^|[^\w$])(?:__|__t|_e|_x|_ex|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(\s*(["\'])(.*?)\1/u';
    if (preg_match_all($pattern_jt, $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $text = trim(stripslashes($m[2]));
        if ($text !== '') {
          $tags[] = [
            'full_tag' => $m[0],
            'original_text' => $text,
            'context' => null,
            'format' => 'func_t',
          ];
        }
      }
    }

    // 按在内容中出现的顺序排序
    usort($tags, function ($a, $b) use ($content) {
      $posA = strpos($content, $a['full_tag']);
      $posB = strpos($content, $b['full_tag']);
      return $posA - $posB;
    });

    return $tags;
  }
}

/**
 * 递归扫描主题下所有 .htm, .html, .js 文件中已标记的多语言文本
 *
 * @param string $theme_base 主题根目录绝对路径
 * @param string|null $device_filter 可选设备筛选 (pc / mobile)
 * @return array 提取到的所有唯一原文数组
 */
if (!function_exists('scan_theme_tagged_strings')) {
  function scan_theme_tagged_strings($theme_base, $device_filter = null) {
    global $tododb;

    $tagged_dict = [];
    $real_base = realpath($theme_base);
    if ($real_base && is_dir($real_base)) {
      $scan_dirs = [$real_base];
      if ($device_filter && in_array($device_filter, ['pc', 'mobile'], true)) {
        $device_dir = $real_base . '/' . $device_filter;
        if (is_dir($device_dir)) {
          $scan_dirs = [$device_dir];
          if (is_dir($real_base . '/assets')) {
            $scan_dirs[] = $real_base . '/assets';
          }
        }
      }

      $files = [];
      foreach ($scan_dirs as $s_dir) {
        $real_s_dir = realpath($s_dir);
        if (!$real_s_dir || !is_dir($real_s_dir)) {
          continue;
        }
        $rii = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($real_s_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
          if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['htm', 'html', 'js', 'php'], true)) {
              $files[] = $file->getPathname();
            }
          }
        }
      }

      // 补充包含后端核心模板标签与系统核心接口文件
      $core_extra_files = [
        ABSPATH . 'core/classes/class-html-template-tags.php',
        ABSPATH . 'core/classes/class-user-center-template-tags.php',
        ABSPATH . 'core/classes/class-order-manager.php',
        ABSPATH . 'core/classes/class-cart-manager.php',
        ABSPATH . 'core/classes/class-firewall-manager.php',
        ABSPATH . 'core/functions/user-center-template.php',
        ABSPATH . 'core/functions/url-functions.php',
        ABSPATH . 'core/functions/security-extend.php',
        ABSPATH . 'core/functions/media.php',
        ABSPATH . 'api/captcha/slider.php',
        ABSPATH . 'api/user/register-handler.php',
        ABSPATH . 'api/user/forgot-password-handler.php',
        ABSPATH . 'api/user/login-handler.php',
        ABSPATH . 'api/user/mobile-login-handler.php',
        ABSPATH . 'api/user/send-email-code.php',
        ABSPATH . 'api/user/recharge-handler.php',
        ABSPATH . 'api/guestbook/submit.php',
        ABSPATH . 'api/sms/send.php',
        ABSPATH . 'admin/ajax/comment-handler.php',
        ABSPATH . 'admin/ajax/email-subscription-handler.php',
        ABSPATH . 'admin/ajax/cart-handler.php',
        ABSPATH . 'includes/ajax/payment-process.php',
        ABSPATH . 'includes/ajax/checkout-process.php',
        ABSPATH . 'includes/ajax/cart-handler.php',
        ABSPATH . 'includes/ajax/product-info.php',
        ABSPATH . 'includes/ajax/coupon-handler.php',
        ABSPATH . 'includes/ajax/address-handler.php',
        ABSPATH . 'includes/ajax/order-handler.php',
        ABSPATH . 'includes/ajax/cancel-order.php',
        ABSPATH . 'includes/ajax/confirm-receive.php',
        ABSPATH . 'includes/ajax/create-payment.php',
        ABSPATH . 'includes/ajax/payment-status.php',
        ABSPATH . 'includes/ajax/review-handler.php',
        ABSPATH . 'includes/ajax/favorites-handler.php',
        ABSPATH . 'includes/ajax/history-handler.php'
      ];
      foreach ($core_extra_files as $cef) {
        if (file_exists($cef)) {
          $files[] = $cef;
        }
      }

      $files = array_unique($files);

      foreach ($files as $file_path) {
        $content = file_get_contents($file_path);
        if ($content === false) {
          continue;
        }

        $tags = extract_all_language_tags($content);
        foreach ($tags as $t) {
          $text = trim($t['original_text']);
          if ($text !== '') {
            $tagged_dict[$text] = true;
          }
        }
      }
    }

    // ===== 2. 扫描 page=tags 所有标签 =====
    if ($tododb && method_exists($tododb, 'table_exists') && method_exists($tododb, 'get_col')) {
      // {$prefix}tags 数据表中所有独立标签名称 (tag_title)
      if ($tododb->table_exists('tags')) {
        $db_tags = $tododb->get_col("SELECT DISTINCT tag_title FROM {$tododb->prefix}tags WHERE tag_title IS NOT NULL AND tag_title != ''");
        if (is_array($db_tags)) {
          foreach ($db_tags as $t_title) {
            $t_title = trim($t_title);
            if (function_exists('todo_parse_inline_translations')) {
              $t_title = todo_parse_inline_translations($t_title);
            }
            $t_title = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $t_title));
            if ($t_title !== '') {
              $tagged_dict[$t_title] = true;
            }
          }
        }
      }
    }

    // ===== 3. 扫描 doc_properties 所有文档属性 =====
    if ($tododb && method_exists($tododb, 'table_exists') && method_exists($tododb, 'get_col') && $tododb->table_exists('doc_properties')) {
      $db_props = $tododb->get_col("SELECT DISTINCT name FROM {$tododb->prefix}doc_properties WHERE name IS NOT NULL AND name != '' UNION SELECT DISTINCT display_name FROM {$tododb->prefix}doc_properties WHERE display_name IS NOT NULL AND display_name != ''");
      if (is_array($db_props)) {
        foreach ($db_props as $p_name) {
          $p_name = trim($p_name);
          if (function_exists('todo_parse_inline_translations')) {
            $p_name = todo_parse_inline_translations($p_name);
          }
          $p_name = trim(preg_replace('/\[[a-z]{2}(?:-[a-z]{2})?\|.*?\]/is', '', $p_name));
          if ($p_name !== '') {
            $tagged_dict[$p_name] = true;
          }
        }
      }
    }

    // ===== 4. 扫描 user_levels 所有会员等级 =====
    if ($tododb && method_exists($tododb, 'table_exists') && method_exists($tododb, 'get_col') && $tododb->table_exists('user_levels')) {
      $db_levels = $tododb->get_col("SELECT DISTINCT level_name FROM {$tododb->prefix}user_levels WHERE level_name IS NOT NULL AND level_name != ''");
      if (is_array($db_levels)) {
        foreach ($db_levels as $l_name) {
          $l_name = trim($l_name);
          if ($l_name !== '') {
            $tagged_dict[$l_name] = true;
          }
        }
      }
    }

    // 预设全站高频通用自定义词汇组（保证导出包覆盖通用 UI/功能提示词）
    $custom_vocab_group = [
      '批发价', '高级批发价', '视频', '专题', '留言'
    ];
    foreach ($custom_vocab_group as $cv) {
      $cv = trim($cv);
      if ($cv !== '') {
        $tagged_dict[$cv] = true;
      }
    }

    // 严密去重并返回
    return array_values(array_unique(array_keys($tagged_dict)));
  }
}

/**
 * 检测模板中未被多语言标签包裹的文本片段
 *
 * 策略：
 *  1. 去掉 <script>、<style>、<pre>、<code> 块
 *  2. 去掉所有{{todo:...}} 模板引擎标签
 *  3. 去掉已知的多语言标签原文
 *  4. 剥离 HTML 标签
 *  5. 提取剩余含中文/日文/韩文，或长度 >= 4 的纯英文文本行
 *
 * @param string $content 模板内容
 * @param array  $tagged_texts 已标记的语言标签原文列表
 * @return array 候选文本行
 */
if (!function_exists('detect_untagged_text')) {
  function detect_untagged_text($content, $tagged_texts = []) {
    $working = $content;

    // 0. 移除 HTML 注释 <!-- ... -->
    $working = preg_replace('/<!--[\s\S]*?-->/', '', $working);
    // 移除 PHP 代码块
    $working = preg_replace('/<\?(php|=)?[\s\S]*?\?>/i', '', $working);

    // 1. 移除 <script>...</script> 块
    $working = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $working);
    // 移除 <style>...</style> 块
    $working = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $working);
    // 移除 <pre>...</pre> 和 <code>...</code> 块
    $working = preg_replace('/<(pre|code)\b[^>]*>[\s\S]*?<\/(pre|code)>/i', '', $working);

    // 1.5 移除 JS/CSS 注释（// 及 /* ... */）
    $working = preg_replace('/\/\*[\s\S]*?\*\//', '', $working);
    $working = preg_replace('/^[ \t]*\/\/.*/m', '', $working);

    // 2. 移除所有 TodoCMS 模板引擎标签及大括号变量/表达式
    // 移除闭合标签：{{/todo:order.items}}、{{/todo:if}}、{{/todo:loop}} 等 (支持点号、下划线、减号)
    $working = preg_replace('/\{\{\/todo:[a-zA-Z0-9_.-]+\}\}/i', '', $working);
    // 移除开标签/块标签/带参数标签：{{todo:if ...}}、{{todo:order.items}}、{{todo:t text="..."}} 等
    $working = preg_replace('/\{\{todo:[a-zA-Z0-9_.-]+[^}]*\}\}/i', '', $working);
    // 移除任何其他剩余的 {{todo:...}} 形式标签
    $working = preg_replace('/\{\{todo:[^}]*\}\}/i', '', $working);
    // 移除任意双大括号表达式与变量：如 {{index + 1}}、{{index}}、{{var.name}} 等
    $working = preg_replace('/\{\{[\s\S]*?\}\}/', '', $working);

    // 3. 去掉已知的语言标签原文（避免误报为未标记）
    foreach ($tagged_texts as $text) {
      $text = preg_quote(trim($text), '/');
      if (mb_strlen($text) >= 1) {
        $working = preg_replace('/' . $text . '/u', '', $working);
      }
    }

    // 3.5. 提取 HTML 标签中的内联可翻译文本属性（如 placeholder, title, alt, value, aria-label, data-tip 等）
    $attr_candidates = [];
    $attr_pattern = '/\b(?:placeholder|title|alt|value|aria-label|label|data-confirm|data-tip|data-notice|data-title|data-label)\s*=\s*(["\'])(.*?)\1/i';
    if (preg_match_all($attr_pattern, $working, $attr_matches, PREG_SET_ORDER)) {
      foreach ($attr_matches as $m) {
        $val = trim($m[2]);
        if ($val !== '' && strpos($val, '{{') === false && strpos($val, '<') === false) {
          // 过滤纯标识符/slug（如 buy_now, guest_email 等无空格且仅含英文字母下划线减号的非自然语言）
          if (preg_match('/^[a-z0-9_-]+$/i', $val) && !preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $val)) {
            continue;
          }
          $attr_candidates[] = $val;
        }
      }
    }

    // 4. 将所有 HTML 标签 <...> 替换为换行符，隔离不同 HTML 节点的文本
    $working = preg_replace('/<[^>]+>/', "\n", $working);
    // 解码 HTML 实体
    $working = html_entity_decode($working, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 5. 按行分割，提取有意义的文本
    $raw_lines = preg_split('/\r\n|\r|\n/', $working);
    $lines = array_merge($raw_lines, $attr_candidates);
    $candidates = [];
    $seen = [];

    foreach ($lines as $line) {
      $line = trim($line);
      // 清理首尾非字词符号（如括号、冒号、空格等，保留中文、字母、数字）
      $line = preg_replace('/^[^\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}a-zA-Z0-9]+|[^\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}a-zA-Z0-9]+$/u', '', $line);
      $line = trim($line);
      if ($line === '' || mb_strlen($line) < 2) {
        continue;
      }

      // 去重
      $key = md5(mb_strtolower($line));
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;

      // 包含中文/日文/韩文 → 直接视为候选
      if (preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $line)) {
        $candidates[] = $line;
        continue;
      }

      // 纯英文/数字/符号：长度 >= 4 且包含至少 2 个字母
      if (preg_match('/^[A-Za-z0-9\s\-_.,!?\'"\/:;()&@#$%^*+=\[\]{}|\\~`<>]+$/', $line)) {
        $alpha_count = preg_match_all('/[A-Za-z]/', $line);
        if ($alpha_count >= 2 && mb_strlen($line) >= 4) {
          $candidates[] = $line;
        }
      }
    }

    return $candidates;
  }
}

/**
 * 对单个模板文件执行完整扫描
 */
if (!function_exists('scan_template_file_full')) {
  function scan_template_file_full($file_path, $rel_path = '', $device = '') {
    if (!file_exists($file_path)) {
      return ['success' => false, 'error' => '文件不存在: ' . $file_path];
    }

    $content = file_get_contents($file_path);
    if ($content === false) {
      return ['success' => false, 'error' => '无法读取文件: ' . $file_path];
    }

    // 语法校验
    $open_double = substr_count($content, '{{');
    $close_double = substr_count($content, '}}');
    $syntax_errors = [];
    $syntax_warnings = [];

    if ($open_double !== $close_double) {
      $lines = explode("\n", $content);
      $cumulative_diff = 0;
      $diff_per_line = [];
      foreach ($lines as $idx => $line) {
        $opens = substr_count($line, '{{');
        $closes = substr_count($line, '}}');
        $line_diff = $opens - $closes;
        $cumulative_diff += $line_diff;
        $diff_per_line[$idx + 1] = ['diff' => $line_diff, 'cumulative' => $cumulative_diff, 'line' => $line];
      }

      $total_diff = $open_double - $close_double;
      $problem_lines = [];

      // 找出累计差异异常的行（累计值突然小于0，或最后累计值不为0的位置）
      foreach ($diff_per_line as $ln => $info) {
        if ($info['diff'] !== 0 && $info['cumulative'] < 0) {
          // 当前行导致累计出现负值 → 多余 }} 在此行或之前
          $problem_lines[] = "第 {$ln} 行 (累积={$info['cumulative']})";
        }
      }

      if ($total_diff > 0) {
        // 开括号多于闭括号 → 缺 {{/tag}} 或 }} 
        // 找到累积值变为非零且之后没有归零的位置
        $started_at = null;
        foreach ($diff_per_line as $ln => $info) {
          if ($info['cumulative'] > 0 && $started_at === null) {
            $started_at = $ln;
          }
        }
        if ($started_at !== null) {
          $problem_lines[] = "第 {$started_at} 行起累积差值 = +{$total_diff}（可能缺少 }} 闭合）";
        }
      }

      $detail = '';
      if (!empty($problem_lines)) {
        $detail = '；疑点: ' . implode(', ', array_slice($problem_lines, 0, 5));
        if (count($problem_lines) > 5) {
          $detail .= ' 等共 ' . count($problem_lines) . ' 处异常';
        }
      }

      $syntax_errors[] = "双括号不匹配（开={$open_double}, 闭={$close_double}，差值={$total_diff}）{$detail}";
    }

    // 检测空的多语言标签
    $total_empty = preg_match_all('/\{\{todo:t[^}]*text\s*[=:]\s*["\']["\']\s*\}\}/i', $content, $m);
    if ($total_empty > 0) {
      $syntax_warnings[] = "存在空的 text=\"\" 多语言标签（" . $total_empty . " 处）";
    }

    // 提取语言标签
    $tags = extract_all_language_tags($content);
    $tagged_texts = array_column($tags, 'original_text');

    // 检测未标记文本
    $untagged = detect_untagged_text($content, $tagged_texts);

    return [
      'success' => true,
      'file_path' => $file_path,
      'rel_path' => $rel_path,
      'device' => $device,
      'tag_count' => count($tags),
      'untagged_count' => count($untagged),
      'tags' => $tags,
      'untagged' => $untagged,
      'validation' => [
        'is_valid' => empty($syntax_errors),
        'errors' => $syntax_errors,
        'warnings' => $syntax_warnings,
      ],
    ];
  }
}

/**
 * 自动为模板中的未标记纯文本包裹 {{todo:t text="..."}}
 *
 * @param string $file_path 模板绝对路径
 * @param array  $untagged_texts 待包裹候选文本列表
 * @param string $real_theme_base 主题根目录绝对路径（用于路径校验）
 * @return array ['success' => bool, 'message' => string, 'results' => array, 'wrapped_count' => int]
 */
if (!function_exists('auto_wrap_template_untagged_text')) {
  /**
   * 自动为模板中的未标记纯文本包裹 {{todo:t text="..."}}
   *
   * 包含严格的去重、防抖、长句优先匹配与动态占位隔离机制，彻底杜绝标签嵌套
   *
   * @param string $file_path 模板绝对路径
   * @param array  $untagged_texts 待包裹候选文本列表
   * @param string $real_theme_base 主题根目录绝对路径（用于路径校验）
   * @return array ['success' => bool, 'message' => string, 'results' => array, 'wrapped_count' => int]
   */
  function auto_wrap_template_untagged_text($file_path, $untagged_texts, $real_theme_base) {
    if (!$file_path || !file_exists($file_path)) {
      return ['success' => false, 'message' => '文件不存在', 'results' => [], 'wrapped_count' => 0];
    }

    $real_file = realpath($file_path);
    if (!$real_file || !$real_theme_base || strpos($real_file, $real_theme_base) !== 0) {
      return ['success' => false, 'message' => '非法的文件访问路径', 'results' => [], 'wrapped_count' => 0];
    }

    $ext = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['htm', 'html'], true)) {
      return ['success' => false, 'message' => '不支持的文件格式', 'results' => [], 'wrapped_count' => 0];
    }

    if (!is_writable($real_file)) {
      return ['success' => false, 'message' => '文件不可写: ' . basename($real_file), 'results' => [], 'wrapped_count' => 0];
    }

    $content = file_get_contents($real_file);
    if ($content === false) {
      return ['success' => false, 'message' => '无法读取文件内容', 'results' => [], 'wrapped_count' => 0];
    }

    $results = [];
    $wrapped_count = 0;
    $working_content = $content;

    // 1. 严格去重与整理输入文本列表
    $clean_texts = [];
    foreach ($untagged_texts as $t) {
      $t = trim((string)$t);
      if ($t !== '' && !in_array($t, $clean_texts, true)) {
        $clean_texts[] = $t;
      }
    }

    // 2. 按文本长度从长到短严格排序（长句优先匹配，避免子词提前匹配导致的标签嵌套）
    usort($clean_texts, function ($a, $b) {
      return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });

    // 3. 遮罩保护 HTML 注释、PHP 代码块、<script>/<style>/<pre>/<code> 块、JS/CSS注释 及 {{...}} 表达式
    $tag_placeholders = [];

    // 遮罩 HTML 注释 <!-- ... -->
    $working_content = preg_replace_callback('/<!--[\s\S]*?-->/', function($m) use (&$tag_placeholders) {
      $key = '___TODO_NO_TOUCH_' . count($tag_placeholders) . '___';
      $tag_placeholders[$key] = $m[0];
      return $key;
    }, $working_content);

    // 遮罩 PHP 代码块
    $working_content = preg_replace_callback('/<\?(php|=)?[\s\S]*?\?>/i', function($m) use (&$tag_placeholders) {
      $key = '___TODO_NO_TOUCH_' . count($tag_placeholders) . '___';
      $tag_placeholders[$key] = $m[0];
      return $key;
    }, $working_content);

    // 遮罩 <script>/<style>/<pre>/<code> 块
    $working_content = preg_replace_callback('/<(script|style|pre|code)\b[^>]*>[\s\S]*?<\/(script|style|pre|code)>/i', function($m) use (&$tag_placeholders) {
      $key = '___TODO_NO_TOUCH_' . count($tag_placeholders) . '___';
      $tag_placeholders[$key] = $m[0];
      return $key;
    }, $working_content);

    // 遮罩 JS/CSS 注释（/* ... */ 及 单行 // 注释）
    $working_content = preg_replace_callback('/\/\*[\s\S]*?\*\/|^[ \t]*\/\/.*/m', function($m) use (&$tag_placeholders) {
      $key = '___TODO_NO_TOUCH_' . count($tag_placeholders) . '___';
      $tag_placeholders[$key] = $m[0];
      return $key;
    }, $working_content);

    // 遮罩模板表达式 {{...}}
    $working_content = preg_replace_callback('/\{\{[\s\S]*?\}\}/', function($m) use (&$tag_placeholders) {
      $key = '___TODO_TAG_HOLDER_' . count($tag_placeholders) . '___';
      $tag_placeholders[$key] = $m[0];
      return $key;
    }, $working_content);

    // 跟踪已经成功包裹过的纯文本集合
    $processed_texts = [];

    // 4. 逐项检测并包裹文本
    foreach ($clean_texts as $text) {
      if (isset($processed_texts[$text])) {
        continue;
      }

      // 校验 1：检测文本本身是否包含 HTML 标签
      if (preg_match('/<[^>]+>/', $text)) {
        $results[$text] = [
          'success' => false,
          'msg' => '包含HTML标签，无法自动包裹'
        ];
        continue;
      }

      // 校验 2：检测文本是否包含双引号（会破坏 {{todo:t text="..."}} 属性语法）
      if (strpos($text, '"') !== false) {
        $results[$text] = [
          'success' => false,
          'msg' => '包含双引号，无法自动包裹'
        ];
        continue;
      }

      // 校验 3：使用字符边界断言检查文本是否作为独立的词组/节点存在于代码中
      // 防破坏机制：防止将子词（如“积分抵扣”）替换到更大的词组（如“使用积分抵扣”）内部造成“使用{{todo:t text="积分抵扣"}}”结构损坏
      $boundary_pattern = '/(?<![\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}a-zA-Z0-9])' . preg_quote($text, '/') . '(?![\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}a-zA-Z0-9])/u';

      if (!preg_match($boundary_pattern, $working_content)) {
        // 区分失败原因：是被已处理的长句包含进去了，还是本身在原代码中含有 HTML 标签
        $is_inside_longer_processed = false;
        foreach ($processed_texts as $ptext => $pstatus) {
          if ($pstatus && strpos($ptext, $text) !== false) {
            $is_inside_longer_processed = true;
            break;
          }
        }
        if ($is_inside_longer_processed) {
          $results[$text] = [
            'success' => false,
            'msg' => '已随更长段落完成包裹，跳过嵌套'
          ];
        } else {
          $results[$text] = [
            'success' => false,
            'msg' => '检测到非独立词组或含有HTML标签，不执行包裹'
          ];
        }
        continue;
      }

      // 校验通过：生成新标签，并用【新占位符】替换，动态隔离新标签！
      // 智能引号选择：若当前替换位置位于 HTML 标签属性内部（如 placeholder="..."），使用单引号 text='...' 避免双引号嵌套导致编辑器语法警告
      $replace_count = 0;
      $working_content = preg_replace_callback($boundary_pattern, function($m) use (&$tag_placeholders, $text, $working_content) {
        $match_text = is_array($m[0]) ? $m[0][0] : $m[0];
        $match_pos = is_array($m[0]) ? $m[0][1] : strpos($working_content, $match_text);
        $before_str = ($match_pos !== false) ? substr($working_content, 0, $match_pos) : '';

        $last_open = strrpos($before_str, '<');
        $last_close = strrpos($before_str, '>');
        $is_inside_tag = ($last_open !== false && ($last_close === false || $last_open > $last_close));

        $quote_char = '"';
        if ($is_inside_tag) {
          $quote_char = "'";
          if (preg_match('/=\s*[\'"][^\'"\n]*$/', $before_str, $qm)) {
            if (strpos($qm[0], "'") !== false) {
              $quote_char = '"';
            }
          }
        }

        $new_tag = '{{todo:t text=' . $quote_char . $text . $quote_char . '}}';
        $placeholder_key = '___TODO_TAG_HOLDER_' . count($tag_placeholders) . '___';
        $tag_placeholders[$placeholder_key] = $new_tag;

        return $placeholder_key;
      }, $working_content, -1, $replace_count, PREG_OFFSET_CAPTURE);

      if ($replace_count > 0) {
        $wrapped_count += $replace_count;
        $processed_texts[$text] = true;
        $results[$text] = [
          'success' => true,
          'msg' => '包裹成功'
        ];
      } else {
        $results[$text] = [
          'success' => false,
          'msg' => '未找到匹配可包裹位置'
        ];
      }
    }

    // 5. 一次性还原所有占位符（包含旧标签和新生成的标签）
    if (!empty($tag_placeholders)) {
      $working_content = str_replace(array_keys($tag_placeholders), array_values($tag_placeholders), $working_content);
    }

    // 6. 语法校验：双括号匹对检查
    $open_braces = substr_count($working_content, '{{');
    $close_braces = substr_count($working_content, '}}');
    if ($open_braces !== $close_braces) {
      return [
        'success' => false,
        'message' => '语法校验未通过（双括号不匹配），更新已撤销',
        'results' => $results,
        'wrapped_count' => 0
      ];
    }

    // 7. 安全保存回模板文件
    if ($wrapped_count > 0) {
      $bytes = file_put_contents($real_file, $working_content);
      if ($bytes === false) {
        return [
          'success' => false,
          'message' => '写入文件失败: ' . basename($real_file),
          'results' => $results,
          'wrapped_count' => 0
        ];
      }
    }

    return [
      'success' => true,
      'message' => "成功为您自动包裹了 {$wrapped_count} 处纯文本多语言标签",
      'results' => $results,
      'wrapped_count' => $wrapped_count
    ];
  }
}


// 获取 pc 和 mobile 两边的文件列表
$pc_files = get_theme_template_files($theme_obj->get_template_directory('pc'), 'pc');

$mobile_files = [];
if (is_dir($theme_base . '/mobile')) {
  $mobile_files = get_theme_template_files($theme_obj->get_template_directory('mobile'), 'mobile');
}
$all_files = array_merge($pc_files, $mobile_files);

// 获取并校验 file 参数
$raw_file = isset($_GET['file']) ? $_GET['file'] : '';
$decoded_file = urldecode($raw_file);

$real_file_path = $decoded_file ? realpath($decoded_file) : false;
$errors = [];
$show_detail = false;

if ($real_file_path) {
  $ext = strtolower(pathinfo($real_file_path, PATHINFO_EXTENSION));
  $allowed_exts = ['htm', 'html'];
  if (!in_array($ext, $allowed_exts, true)) {
    $errors[] = '不支持的模板文件类型: .' . $ext;
  }
  if (!$real_theme_base || strpos($real_file_path, $real_theme_base) !== 0) {
    $errors[] = '非法的文件访问：仅允许查看当前主题目录下的模板文件';
  }
  if (empty($errors)) {
    $show_detail = true;
    // 自动检测文件所属设备类型
    $pc_base = realpath($theme_obj->get_template_directory('pc'));
    if ($pc_base && strpos($real_file_path, $pc_base) === 0) {
      $device_type = 'pc';
    } else {
      $mobile_base = realpath($theme_obj->get_template_directory('mobile'));
      if ($mobile_base && strpos($real_file_path, $mobile_base) === 0) {
        $device_type = 'mobile';
      }
    }
  }
}

$device_label = $device_type === 'mobile' ? '移动端' : 'PC端';

// ===== 构建文件索引（用于前后导航）=====
$file_index = [];
// 按设备类型分组的扁平列表
$pc_flat = [];
$mobile_flat = [];
foreach ($pc_files as $f) {
  $pc_flat[] = $f['path'];
}
foreach ($mobile_files as $f) {
  $mobile_flat[] = $f['path'];
}
$current_flat = ($device_type === 'mobile') ? $mobile_flat : $pc_flat;
$current_idx = $show_detail ? array_search($real_file_path, $current_flat) : false;
$prev_file = ($current_idx !== false && $current_idx > 0) ? $current_flat[$current_idx - 1] : null;
$next_file = ($current_idx !== false && $current_idx < count($current_flat) - 1) ? $current_flat[$current_idx + 1] : null;

// 页面副标题
if ($show_detail && $real_file_path) {
  $basename = basename($real_file_path);
  $page_subtitle = $basename;
}

// ===== 处理一键自动包裹 POST 请求 =====
$auto_wrap_notice = null;
$auto_wrap_results = [];

if ($show_detail && $real_file_path && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_wrap_untagged') {
  if (class_exists('Todo_Security_Manager') && !Todo_Security_Manager::verify_request_csrf('template_auto_wrap')) {
    $errors[] = '安全验证失败（CSRF Token 无效），请刷新页面后重试。';
  } else {
    $target_file = isset($_POST['target_file']) ? $_POST['target_file'] : $real_file_path;
    $selected_items = isset($_POST['selected_untagged']) && is_array($_POST['selected_untagged']) ? $_POST['selected_untagged'] : [];

    if (empty($selected_items)) {
      $auto_wrap_notice = ['type' => 'warning', 'msg' => '请至少勾选一项待标记文本进行一键处理。'];
    } else {
      $wrap_res = auto_wrap_template_untagged_text($target_file, $selected_items, $real_theme_base);
      $auto_wrap_results = $wrap_res['results'];
      if ($wrap_res['success']) {
        $auto_wrap_notice = ['type' => 'success', 'msg' => $wrap_res['message']];
      } else {
        $auto_wrap_notice = ['type' => 'error', 'msg' => $wrap_res['message']];
      }
    }
  }
}

// ===== 处理导出已标记文本语言包请求 =====
$requested_action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
if ($requested_action === 'export_tagged') {
  if (class_exists('Todo_Security_Manager') && $_SERVER['REQUEST_METHOD'] === 'POST' && !Todo_Security_Manager::verify_request_csrf('template_export_tagged')) {
    $errors[] = '安全验证失败（CSRF Token 无效），请刷新页面后重试。';
  } else {
    $device_filter = isset($_REQUEST['device']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['device']) : null;
    $tagged_list = scan_theme_tagged_strings($real_theme_base, $device_filter);

    // 构建键值对：左侧词汇，右侧留空 '' 方便直接翻译/拷贝
    $export_data = [];
    foreach ($tagged_list as $text) {
      $export_data[$text] = '';
    }

    if (ob_get_level()) {
      ob_end_clean();
    }

    $dev_suffix = $device_filter ? '_' . $device_filter : '';
    $timestamp = date('YmdHis');
    $filename = 'lang_pack_tagged_' . $active_theme . $dev_suffix . '_' . $timestamp . '.php';

    header('Content-Type: application/x-php; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $php_content = "<?php\n";
    $php_content .= "/**\n";
    $php_content .= " * Language pack (theme-level, flat file)\n";
    $php_content .= " * Prompt: Translate the vocabulary; target language: en-US; strictly preserve the formatting; preferably provide a PHP code block or a downloadable '.php' file.\n";
    $php_content .= " */\n";
    $php_content .= "if (!defined('ABSPATH')) { exit('Direct access denied'); }\n\n";
    $php_content .= "return array(\n";
    foreach ($export_data as $key => $val) {
      $escaped_key = addcslashes($key, "'\\");
      $escaped_val = addcslashes($val, "'\\");
      $php_content .= "    '{$escaped_key}' => '{$escaped_val}',\n";
    }
    $php_content .= ");\n";

    echo $php_content;
    exit;
  }
}

// ===== 全量预扫描（列表视图用）=====
// 按设备类型独立统计
$all_device_results = [];
$device_stats = []; // ['pc' => [...stats...], 'mobile' => [...stats...]]

$scan_devices = ['pc' => $pc_files];
if (is_dir($theme_base . '/mobile')) {
  $scan_devices['mobile'] = $mobile_files;
}

foreach ($scan_devices as $dev_key => $dev_files) {
  $dev_results = [];
  $stats = [
    'total_files' => count($dev_files),
    'total_tags' => 0,
    'total_untagged' => 0,
    'files_with_errors' => 0,
    'files_with_warnings' => 0,
    'files_with_zero_tags' => 0,
  ];
  foreach ($dev_files as $f) {
    $res = scan_template_file_full($f['path'], $f['rel_path'], $dev_key);
    $dev_results[] = $res;
    if ($res['success']) {
      $stats['total_tags'] += $res['tag_count'];
      $stats['total_untagged'] += $res['untagged_count'];
      if (!empty($res['validation']['errors'])) {
        $stats['files_with_errors']++;
      }
      if (!empty($res['validation']['warnings'])) {
        $stats['files_with_warnings']++;
      }
      if ($res['tag_count'] === 0 && $res['untagged_count'] > 0) {
        $stats['files_with_zero_tags']++;
      }
    }
  }
  $all_device_results[$dev_key] = $dev_results;
  $device_stats[$dev_key] = $stats;
}

// ===== 列表排序 =====
$sort_orderby = isset($_GET['orderby']) ? (string) $_GET['orderby'] : 'tag_count';
$sort_order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'asc' : 'desc';
$allowed_sort_keys = ['tag_count', 'untagged_count', 'syntax', 'status'];

if (!in_array($sort_orderby, $allowed_sort_keys, true)) {
  $sort_orderby = 'tag_count';
  $sort_order = 'desc';
}

// 对 pc / mobile 的 results 分别排序
foreach (['pc', 'mobile'] as $dk) {
  if (!isset($all_device_results[$dk])) continue;
  usort($all_device_results[$dk], function ($a, $b) use ($sort_orderby) {
    if (!$a['success']) return 1;
    if (!$b['success']) return -1;

    if ($sort_orderby === 'syntax') {
      $va = count($a['validation']['errors']);
      $vb = count($b['validation']['errors']);
    } elseif ($sort_orderby === 'status') {
      // 状态优先级：已完成=0 < 部分标记=1 < 未标记=2 < 语法错误=3
      $map = function ($r) {
        if (!empty($r['validation']['errors'])) return 3;
        if ($r['tag_count'] === 0 && $r['untagged_count'] > 0) return 2;
        if ($r['untagged_count'] > 0) return 1;
        return 0;
      };
      $va = $map($a);
      $vb = $map($b);
    } else {
      $va = $a[$sort_orderby];
      $vb = $b[$sort_orderby];
    }
    return $va - $vb;
  });
  // 降序时翻转
  if ($sort_order === 'desc') {
    $all_device_results[$dk] = array_reverse($all_device_results[$dk]);
  }
}

$sort_base_url = todo_admin_url('index.php?page=language-template-detail');
?>
<div class="admin-page">
  <div class="admin-page-header">
    <h2 class="admin-page-title">
      <?php if (!empty($page_subtitle)): ?>
        <?php echo esc_html($page_subtitle); ?>
      <?php else: ?>
        <?php echo esc_html($page_title); ?>
      <?php endif; ?>
    </h2>
    <div class="admin-page-actions flex items-center gap-2">
      <?php if ($show_detail): ?>
        <a href="<?php echo todo_admin_url('index.php?page=language-template-detail'); ?>" class="button button-secondary">返回模板扫描</a>
      <?php else: ?>
        <a href="<?php echo todo_admin_url('index.php?page=language'); ?>" class="button button-secondary">返回语言首页</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="notice notice-error is-dismissible">
      <p><?php echo esc_html(implode('；', $errors)); ?></p><button type="button" class="notice-dismiss">✕</button>
    </div>
  <?php endif; ?>

  <?php if ($show_detail): ?>
    <?php
    $result = scan_template_file_full($real_file_path, basename($real_file_path), $device_type);
    ?>
    <?php if (!$result['success']): ?>
      <div class="notice notice-error is-dismissible">
        <p><?php echo esc_html($result['error']); ?></p><button type="button" class="notice-dismiss">✕</button>
      </div>
    <?php else: ?>
      <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
              <h3 class="admin-card-title">模板概览</h3>
              <div class="admin-card-subtitle">
                <strong><?php echo esc_html($active_theme); ?></strong> 主题，<strong><?php echo esc_html($device_label); ?></strong> <code style="word-break:break-all;"><?php echo esc_html($result['file_path']); ?></code>
              </div>
            </div>
        </div>
        <div class="admin-card-content">
          <div style="display:flex; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
            <div style="flex:1; min-width:120px; padding:12px; background:#f0f6fc; border-radius:4px; text-align:center;">
              <div style="font-size:28px; font-weight:bold; color:#0073aa;"><?php echo (int) $result['tag_count']; ?></div>
              <div style="color:#666; font-size:13px;">已标记标签</div>
            </div>
            <div style="flex:1; min-width:120px; padding:12px; background:<?php echo $result['untagged_count'] > 0 ? '#fef7ee' : '#f0faf0'; ?>; border-radius:4px; text-align:center;">
              <div style="font-size:28px; font-weight:bold; color:<?php echo $result['untagged_count'] > 0 ? '#d97706' : '#16a34a'; ?>;"><?php echo (int) $result['untagged_count']; ?></div>
              <div style="color:#666; font-size:13px;"><?php echo $result['untagged_count'] > 0 ? '待标记文本' : '无遗漏'; ?></div>
            </div>
            <div style="flex:1; min-width:120px; padding:12px; background:<?php echo !empty($result['validation']['errors']) ? '#fef2f2' : '#f0faf0'; ?>; border-radius:4px; text-align:center;">
              <div style="font-size:28px; font-weight:bold; color:<?php echo !empty($result['validation']['errors']) ? '#dc2626' : '#16a34a'; ?>;"><?php echo !empty($result['validation']['errors']) ? count($result['validation']['errors']) : '✓'; ?></div>
              <div style="color:#666; font-size:13px;">语法错误</div>
            </div>
          </div>
          <?php if (!empty($result['validation']['errors'])): ?>
            <div class="notice notice-error is-dismissible">
              <p>错误：<?php echo esc_html(implode('；', $result['validation']['errors'])); ?></p><button type="button" class="notice-dismiss">✕</button>
            </div>
          <?php endif; ?>
          <?php if (!empty($result['validation']['warnings'])): ?>
            <div class="notice notice-warning is-dismissible">
              <p>警告：<?php echo esc_html(implode('；', $result['validation']['warnings'])); ?></p><button type="button" class="notice-dismiss">✕</button>
            </div>
          <?php endif; ?>
          <div class="flex items-center justify-between gap-2">
            <div>
              <?php if ($prev_file): ?>
                <a href="<?php echo esc_url(todo_admin_url('index.php?page=language-template-detail&file=' . urlencode($prev_file) . '&type=' . $device_type)); ?>"
                  class="button" style="font-size:12px;">❮ 上一个</a>
              <?php else: ?>
                <span class="button" style="font-size:12px; opacity:0.4;">❮ 上一个</span>
              <?php endif; ?>
            </div>
            <div style="text-align:center;">
              <strong style="word-break:break-all;"><?php echo esc_html($result['rel_path']); ?></strong>
              <br>
                <small style="color:#666;">
                <?php echo ($current_idx !== false) ? (' 第 ' . ($current_idx + 1) . ' / ' . count($current_flat) . ' 个') : ''; ?>文件
                </small>
            </div>
            <div>
              <?php if ($next_file): ?>
                <a href="<?php echo esc_url(todo_admin_url('index.php?page=language-template-detail&file=' . urlencode($next_file) . '&type=' . $device_type)); ?>"
                  class="button" style="font-size:12px;">下一个 ❯</a>
              <?php else: ?>
                <span class="button" style="font-size:12px; opacity:0.4;">下一个 ❯</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
              <h3 class="admin-card-title">已标记语言标签</h3>
              <div class="admin-card-subtitle">
                <?php echo count($result['tags']); ?> 个
              </div>
            </div>
        </div>
        <div class="admin-card-content">
        <?php if (empty($result['tags'])): ?>
          <div class="no-items">
              <p>尚未设置任何语言标签<br/>建议将需要翻译的文本用 <code>{{todo:t text="原文"}}</code> 包裹</p>
          </div>
        <?php else: ?>
          <table class="admin-table">
            <thead>
              <tr>
                <th style="width:40%; min-width: 200px;">原文</th>
                <th style="width:30%;">上下文</th>
                <th style="width:30%; min-width: 200px;">完整标签</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($result['tags'] as $tag): ?>
                <tr>
                  <td><?php echo esc_html($tag['original_text']); ?></td>
                  <td><?php echo isset($tag['context']) && $tag['context'] !== null ? esc_html($tag['context']) : '<em class="tb-meta">无</em>'; ?></td>
                  <td><code style="font-size:12px; word-break:break-all;"><?php echo esc_html($tag['full_tag']); ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        </div>
      </div>

      <div class="admin-card">
        <form method="post" action="" id="form-auto-wrap" style="margin:0;" onsubmit="return handleAutoWrapSubmit(this);">
          <?php if (class_exists('Todo_Security_Manager')): ?>
            <?php echo Todo_Security_Manager::csrf_field('template_auto_wrap'); ?>
          <?php endif; ?>
          <input type="hidden" name="action" value="auto_wrap_untagged">
          <input type="hidden" name="target_file" value="<?php echo esc_attr($real_file_path); ?>">

          <div class="admin-card-header no-border">
              <div class="admin-card-title-section">
                <h3 class="admin-card-title">待标记文本</h3>
                <div class="admin-card-subtitle">
                  <?php echo $result['untagged_count']; ?> 处<?php echo $result['untagged_count'] > 0 ? '，需处理' : '，已完成'; ?>
                </div>
              </div>
              <div class="admin-card-actions">
                <?php if (!empty($result['untagged'])): ?>
                  <button type="submit" class="button button-secondary">一键处理</button>
                <?php endif; ?>
              </div>
          </div>

          <div class="admin-card-content">
          <?php if (empty($result['untagged'])): ?>
            <div class="no-items">
                <p>恭喜，未找到未包裹的纯文本。</p>
            </div>
          <?php else: ?>
            <table class="admin-table">
              <thead>
                <tr>
                  <th style="width:40px; text-align:center;">
                    <input type="checkbox" id="check-all-untagged" checked title="全选 / 反选" onclick="var chks = document.querySelectorAll('.untagged-checkbox'); for(var i=0; i<chks.length; i++) chks[i].checked = this.checked;">
                  </th>
                  <th style="width:50px;">#</th>
                  <th style="width:35%; min-width: 150px;">文本内容</th>
                  <th style="width:35%; min-width: 150px;">建议包裹代码</th>
                  <th style="width:20%;">状态 / 处理结果</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                foreach ($result['untagged'] as $idx => $text): 
                  $res_msg = '';
                  $res_is_success = false;
                  $res_is_error = false;

                  if (isset($auto_wrap_results[$text])) {
                    $res_is_success = $auto_wrap_results[$text]['success'];
                    $res_is_error = !$res_is_success;
                    $res_msg = $auto_wrap_results[$text]['msg'];
                  } else {
                    $res_is_success = true;
                    $res_msg = '待处理（纯文本）';
                  }
                ?>
                  <tr>
                    <td style="text-align:center;">
                      <input type="checkbox" name="selected_untagged[]" value="<?php echo esc_attr($text); ?>" class="untagged-checkbox" checked>
                    </td>
                    <td><?php echo $idx + 1; ?></td>
                    <td style="color:#d97706;"><?php echo esc_html($text); ?></td>
                    <td>
                      <code style="font-size:12px; word-break:break-all;">{{todo:t text="<?php echo esc_html($text); ?>"}}</code>
                    </td>
                    <td>
                      <?php if ($res_is_error): ?>
                        <span style="color:#dc2626; font-size:12px; line-height: normal;"><?php echo esc_html($res_msg); ?></span>
                      <?php else: ?>
                        <span style="color:#16a34a; font-size:12px; line-height: normal;"><?php echo esc_html($res_msg); ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="description m-t-20">
              提示：勾选需要处理的词组后点击“一键处理”，无需翻译的常用词（如 Country / Region ）可取消勾选排除在处理队列之外。
            </p>
          <?php endif; ?>
          </div>
        </form>
      </div>

      <script>
      function handleAutoWrapSubmit(form) {
        var checked = form.querySelectorAll('input[name="selected_untagged[]"]:checked');
        if (checked.length === 0) {
          TodoModal.warning('请至少勾选一项待标记文本进行一键处理。');
          return false;
        }

        var count = checked.length;
        var msg = '确定要自动包裹选中的 ' + count + ' 项未标记纯文本吗？';

        if (typeof TodoModal !== 'undefined' && typeof TodoModal.confirm === 'function') {
          TodoModal.confirm({
            title: '确认一键自动包裹',
            message: msg,
            type: 'warning',
            confirmText: '确认处理',
            cancelText: '取消',
            onConfirm: function() {
              var btn = form.querySelector('button[type="submit"]');
              if (btn) {
                btn.disabled = true;
                btn.innerHTML = '处理中...';
              }
              form.submit();
            }
          });
          return false;
        }
        return true;
      }
      </script>
    <?php endif; ?>

  <?php else: ?>
    <?php 
    $device_groups = [
      'pc' => ['label' => is_dir($theme_base . '/mobile') ? 'PC端' : 'PC端 / 默认', 'files' => $pc_files, 'results' => $all_device_results['pc'], 'stats' => $device_stats['pc']],
    ];
    if (is_dir($theme_base . '/mobile')) {
      $device_groups['mobile'] = ['label' => '移动端', 'files' => $mobile_files, 'results' => $all_device_results['mobile'], 'stats' => $device_stats['mobile']];
    }
    ?>
    <?php foreach ($device_groups as $dev_key => $dev_info): $s = $dev_info['stats']; ?>
      <div class="admin-card">
        <div class="admin-card-header no-border">
            <div class="admin-card-title-section">
              <h3 class="admin-card-title"><?php echo esc_html($dev_info['label']); ?></h3>
              <div class="admin-card-subtitle">
                <?php echo esc_html($active_theme); ?> 主题, <?php echo count($dev_info['files']); ?> 个文件
              </div>
            </div>
            <div class="admin-card-actions">
              <form method="post" action="<?php echo esc_url(todo_admin_url('index.php?page=language-template-detail&theme=' . urlencode($active_theme))); ?>" style="display:inline-block;">
                <input type="hidden" name="action" value="export_tagged">
                <input type="hidden" name="device" value="<?php echo esc_attr($dev_key); ?>">
                <?php if (class_exists('Todo_Security_Manager')): ?>
                  <?php echo Todo_Security_Manager::csrf_field('template_export_tagged'); ?>
                <?php endif; ?>
                <button type="submit" class="button button-secondary">导出“已标记”语言包</button>
              </form>
            </div>
        </div>
        <div class="admin-card-content flex gap-2 flex-wrap" style="padding-bottom: 0;">
          <div style="flex:1; min-width:100px; padding:16px; background:#f0f6fc; border-radius:4px; text-align:center;">
            <div style="font-size:32px; font-weight:bold; color:#0073aa;"><?php echo $s['total_files']; ?></div>
            <div style="color:#666; font-size:13px;">模板文件总数</div>
          </div>
          <div style="flex:1; min-width:100px; padding:16px; background:#ecfdf5; border-radius:4px; text-align:center;">
            <div style="font-size:32px; font-weight:bold; color:#059669;"><?php echo $s['total_tags']; ?></div>
            <div style="color:#666; font-size:13px;">已标记标签</div>
          </div>
          <div style="flex:1; min-width:100px; padding:16px; background:<?php echo $s['total_untagged'] > 0 ? '#fef7ee' : '#f0faf0'; ?>; border-radius:4px; text-align:center;">
            <div style="font-size:32px; font-weight:bold; color:<?php echo $s['total_untagged'] > 0 ? '#d97706' : '#16a34a'; ?>;"><?php echo $s['total_untagged']; ?></div>
            <div style="color:#666; font-size:13px;">待标记文本</div>
          </div>
          <div style="flex:1; min-width:100px; padding:16px; background:<?php echo $s['files_with_zero_tags'] > 0 ? '#fef7ee' : '#f0faf0'; ?>; border-radius:4px; text-align:center;">
            <div style="font-size:32px; font-weight:bold; color:<?php echo $s['files_with_zero_tags'] > 0 ? '#ccc' : '#16a34a'; ?>;"><?php echo $s['files_with_zero_tags']; ?></div>
            <div style="color:#666; font-size:13px;">零标签文件</div>
          </div>
          <div style="flex:1; min-width:100px; padding:16px; background:<?php echo $s['files_with_errors'] > 0 ? '#fef2f2' : '#f0faf0'; ?>; border-radius:4px; text-align:center;">
            <div style="font-size:32px; font-weight:bold; color:<?php echo $s['files_with_errors'] > 0 ? '#dc2626' : '#16a34a'; ?>;"><?php echo $s['files_with_errors']; ?></div>
            <div style="color:#666; font-size:13px;">语法错误文件</div>
          </div>
        </div>
        <div class="admin-card-content">
          <?php if (empty($dev_info['files'])): ?>
            <div class="no-items">
              <p>该设备类型下未找到模板文件（.htm / .html）。</p>
            </div>
          <?php else: ?>
            <table class="admin-table">
              <thead>
                <tr>
                  <th>文件名</th>
                  <th style="width:200px;">相对路径</th>
                  <?php echo todo_admin_render_sort_th('已标记', 'tag_count', '', array('query' => $_GET, 'base_url' => $sort_base_url, 'current_orderby' => $sort_orderby, 'current_order' => $sort_order, 'style' => 'width: 80px;min-width: 80px;')); ?>
                  <?php echo todo_admin_render_sort_th('待标记', 'untagged_count', '', array('query' => $_GET, 'base_url' => $sort_base_url, 'current_orderby' => $sort_orderby, 'current_order' => $sort_order, 'style' => 'width: 80px;min-width: 80px;')); ?>
                  <?php echo todo_admin_render_sort_th('语法', 'syntax', '', array('query' => $_GET, 'base_url' => $sort_base_url, 'current_orderby' => $sort_orderby, 'current_order' => $sort_order, 'style' => 'width: 80px;min-width: 80px;')); ?>
                  <?php echo todo_admin_render_sort_th('状态', 'status', '', array('query' => $_GET, 'base_url' => $sort_base_url, 'current_orderby' => $sort_orderby, 'current_order' => $sort_order, 'style' => 'width: 100px;min-width: 100px;')); ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dev_info['results'] as $res): ?>
                  <?php 
                    $status_class = '';
                    $status_text = '';
                    if (!empty($res['validation']['errors'])) {
                      $status_class = 'status-error';
                      $status_text = '语法错误';
                    } elseif ($res['tag_count'] === 0 && $res['untagged_count'] > 0) {
                      $status_class = 'status-warning';
                      $status_text = '未标记';
                    } elseif ($res['untagged_count'] > 0) {
                      $status_class = 'status-info';
                      $status_text = '部分标记';
                    } else {
                      $status_class = 'status-success';
                      $status_text = '已完成';
                    }
                  ?>
                  <tr>
                    <td>
                      <a href="<?php echo esc_url(todo_admin_url('index.php?page=language-template-detail&file=' . urlencode($res['file_path']) . '&type=' . $dev_key)); ?>">
                        <?php echo esc_html($res['rel_path']); ?>
                      </a>
                    </td>
                    <td><code class="tb-meta"><?php echo esc_html($res['rel_path']); ?></code></td>
                    <td style="text-align:center; font-weight:bold; color:#0073aa;<?php if($res['tag_count'] == 0) echo ' filter: grayscale(1);opacity:0.2;'; ?>"><?php echo (int) $res['tag_count']; ?></td>
                    <td style="text-align:center; font-weight:bold; color:#ff6600;<?php if($res['untagged_count'] == 0) echo ' filter: grayscale(1);opacity:0.2;'; ?>;"><?php echo (int) $res['untagged_count']; ?></td>
                    <td style="text-align:center;">
                      <?php if (!empty($res['validation']['errors'])): ?>
                        <span style="color:#dc2626;" title="<?php echo esc_html(implode(', ', $res['validation']['errors'])); ?>"><?php echo count($res['validation']['errors']); ?> err</span>
                      <?php elseif (!empty($res['validation']['warnings'])): ?>
                        <span style="color:#d97706;" title="<?php echo esc_html(implode(', ', $res['validation']['warnings'])); ?>">⚠</span>
                      <?php else: ?>
                        <span style="color:#16a34a;">✓</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span style="display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px;
                        <?php
                          switch ($status_text) {
                            case '已完成': echo 'background:#d1fae5; color:#065f46;'; break;
                            case '部分标记': echo 'background:#dbeafe; color:#1e40af;'; break;
                            case '未标记': echo 'background:#fef3c7; color:#92400e;'; break;
                            case '语法错误': echo 'background:#fee2e2; color:#991b1b;'; break;
                          }
                        ?>">
                        <?php echo esc_html($status_text); ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script type="text/javascript">
document.addEventListener('submit', function(e) {
  var form = e.target;
  if (form && form.querySelector('input[name="action"][value="export_tagged"]')) {
    setTimeout(function() {
      if (window.parent && window.parent.TodoCMS && window.parent.TodoCMS.stopLoading) {
        window.parent.TodoCMS.stopLoading();
      }
    }, 800);
  }
});
</script>

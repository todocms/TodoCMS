<?php

if (!defined('ABSPATH')) {
    exit;
}

// 检查用户权限
if (!current_user_can('switch_themes') && !current_user_can('edit_themes') && !current_user_can('customize') && !current_user_can('edit_theme_options') && !current_user_can('manage_themes') && !current_user_can('manage_options')) {
    todo_die('您没有足够的权限访问此页面。');
}

function todo_template_tags_manual_guess_group($tag_name)
{
    $tag_name = (string) $tag_name;
    if ($tag_name === '') {
        return '其他';
    }
    
    // 逻辑控制与标准语法分组
    if (strpos($tag_name, 'todo:') === 0) {
        $logic_name = substr($tag_name, 5);
        if (in_array($logic_name, ['if', 'else', 'elseif', 'else-if'], true)) {
            return '标准语法 / 条件渲染';
        }
        if (in_array($logic_name, ['for', 'key', 'for-item', 'for-index'], true)) {
            return '标准语法 / 列表渲染';
        }
        if (in_array($logic_name, ['template', 'use', 'include', 'import'], true)) {
            return '标准语法 / 模板与引用';
        }
        if (in_array($logic_name, ['set', 'constant', 'yield', 'extends'], true)) {
            return '标准语法 / 逻辑与布局';
        }
    }

    $clean_name = str_replace('todo:', '', $tag_name);

    $prefix_map = [
        'site_' => '站点 / 基础',
        'page_' => '页面 / 基础',
        'meta_' => '页面 / SEO',
        'og_' => '页面 / SEO',
        'user_' => '用户 / 会员',
        'post_' => '内容 / Post',
        'category_' => '分类 / Category',
        'tag_' => '标签 / Tag',
        'nav_' => '导航 / 菜单',
    ];
    foreach ($prefix_map as $p => $g) {
        if (strpos($clean_name, $p) === 0) {
            return $g;
        }
    }
    if (in_array($clean_name, ['if', 'else', 'elseif'], true)) {
        return '模板语法 / 条件';
    }
    if (in_array($clean_name, ['loop', 'foreach'], true)) {
        return '模板语法 / 循环';
    }
    if (in_array($clean_name, ['include', 'block', 'extend', 'literal'], true)) {
        return '模板语法 / 组织';
    }
    if (strpos($clean_name, 'get_') === 0) {
        return '主题 / 结构';
    }
    if (in_array($clean_name, ['t', 'translate_text', 'language_switcher'], true)) {
        return '多语言 / 翻译';
    }
    if (in_array($clean_name, ['csrf_token', 'captcha_url'], true)) {
        return '安全 / 表单';
    }
    if (strpos($clean_name, 'pagination') === 0) {
        return '模板语法 / 分页';
    }
    return '其他';
}

function todo_template_tags_manual_parse_spec($markdown)
{
    $lines = preg_split("/\r\n|\n|\r/", (string) $markdown);
    $stack = [];
    $paths = [];

    foreach ($lines as $i => $line) {
        if (preg_match('/^(#{2,4})\s+(.+?)\s*$/u', $line, $m)) {
            $level = strlen($m[1]);
            $title = trim($m[2]);
            while (!empty($stack) && end($stack)['level'] >= $level) {
                array_pop($stack);
            }
            $stack[] = ['level' => $level, 'title' => $title];
        }
        $paths[$i] = implode(' / ', array_map(function ($x) {
            return $x['title'];
        }, $stack));
    }

    $tags = [];
    $order = [];
    $full = implode("\n", $lines);

    foreach ($lines as $i => $line) {
        $found = [];
        // 1. 匹配 {{todo:tag}} 或 {{/todo:tag}}
        if (preg_match_all('/\{\{\/?todo:([a-zA-Z0-9_\-:]+)/u', $line, $m1)) {
            foreach ($m1[1] as $t) {
                $found[] = 'todo:' . $t;
            }
        }
        // 2. 匹配 <todo:tag> 或 <div todo:if="...">
        if (preg_match_all('/<(?:todo:)?([a-zA-Z0-9_\-]+)\s+todo:([a-zA-Z0-9_\-]+)/u', $line, $m3)) {
            foreach ($m3[2] as $t) {
                $found[] = 'todo:' . $t;
            }
        }
        // 3. 匹配独立标签 <todo:template>
        if (preg_match_all('/<todo:([a-zA-Z0-9_\-]+)/u', $line, $m4)) {
            foreach ($m4[1] as $t) {
                $found[] = 'todo:' . $t;
            }
        }
        // 4. 匹配内联 `todo:tag` 或 `tag`
        if (preg_match_all('/`(todo:[a-z][a-z0-9_\-:]*)`/u', $line, $m2)) {
            foreach ($m2[1] as $t) {
                $found[] = $t;
            }
        }
        if (empty($found)) {
            continue;
        }
        $found = array_values(array_unique($found));
        foreach ($found as $name) {
            if (!isset($tags[$name])) {
                $is_block = (strpos($full, '{{/todo:' . $name . '}}') !== false);
                $tags[$name] = [
                    'name' => $name,
                    'group' => $paths[$i] ?: '未分类',
                    'line' => $i + 1,
                    'is_block' => $is_block,
                    'snippets' => [],
                ];
                $order[] = $name;
            }
            $snippet = trim($line);
            if ($snippet !== '' && count($tags[$name]['snippets']) < 6) {
                $tags[$name]['snippets'][] = $snippet;
            }
        }
    }

    $groups = [];
    foreach ($tags as $tag) {
        $g = $tag['group'] ?: '未分类';
        if (!isset($groups[$g])) {
            $groups[$g] = [];
        }
        $groups[$g][] = $tag['name'];
    }
    ksort($groups, SORT_NATURAL);
    foreach ($groups as $g => $names) {
        sort($names, SORT_NATURAL);
        $groups[$g] = $names;
    }

    $tag_list = array_values($tags);
    usort($tag_list, function ($a, $b) {
        if ($a['group'] === $b['group']) {
            return strnatcasecmp($a['name'], $b['name']);
        }
        return strnatcasecmp($a['group'], $b['group']);
    });

    $params = [
        'global' => [
            ['name' => 'default', 'type' => 'string', 'desc' => '空值回退'],
            ['name' => 'escape', 'type' => 'html|attr|none', 'desc' => '输出转义策略（按标签实现可能不同）'],
            ['name' => 'length', 'type' => 'number', 'desc' => '文本截断长度（按标签实现可能不同）'],
            ['name' => 'unit', 'type' => 'chars|words', 'desc' => '截断单位（按标签实现可能不同）'],
            ['name' => 'format', 'type' => 'string', 'desc' => '格式化参数（如日期/输出格式）'],
        ],
        'by_tag' => [
            'term_attribute' => [
                ['name' => 'key', 'type' => 'string', 'desc' => '属性键名'],
                ['name' => 'name', 'type' => 'string', 'desc' => 'key 的别名'],
                ['name' => 'taxonomy', 'type' => 'category|post_tag', 'desc' => '分类法'],
                ['name' => 'id', 'type' => 'number', 'desc' => '术语ID'],
                ['name' => 'type', 'type' => 'image', 'desc' => '字段类型（image 返回图片HTML或URL）'],
                ['name' => 'size', 'type' => 'thumbnail|medium|large|full', 'desc' => '图片尺寸'],
                ['name' => 'default', 'type' => 'string', 'desc' => '默认值'],
                ['name' => 'format', 'type' => 'html|raw', 'desc' => '输出格式'],
            ],
            'category_img' => [
                ['name' => 'id', 'type' => 'number', 'desc' => '分类ID（不传则取当前分类）'],
                ['name' => 'taxonomy', 'type' => 'category|post_tag', 'desc' => '分类法'],
                ['name' => 'key', 'type' => 'number', 'desc' => '图片序号（0为第一张）'],
                ['name' => 'index', 'type' => 'number', 'desc' => 'key 的别名'],
                ['name' => 'size', 'type' => 'thumbnail|medium|large|full', 'desc' => '按附件ID取图时的尺寸'],
                ['name' => 'default', 'type' => 'string', 'desc' => '默认图（支持 theme:/assets/... 或 /assets/...）'],
            ],
        ],
    ];

    return [
        'groups' => $groups,
        'tags' => $tag_list,
        'params' => $params,
    ];
}

$spec_path = ABSPATH . 'docs/todo-template-tags-spec.md';
$spec_md = file_exists($spec_path) ? (string) file_get_contents($spec_path) : '';
$data = todo_template_tags_manual_parse_spec($spec_md);

$overrides = [];
$override_file = ADMIN_DIR . '/pages/theme/template-tags-overrides.php';
if (file_exists($override_file)) {
    $tmp = require $override_file;
    if (is_array($tmp)) {
        $overrides = $tmp;
    }
}

$builtin_tags = ['if', 'else', 'elseif', 'loop', 'include', 'foreach', 'block', 'extend', 'literal'];
$registered = [];
if (class_exists('CMS_HTML_Template_Tags')) {
    $h = CMS_HTML_Template_Tags::get_instance();
    foreach (get_class_methods($h) as $m) {
        if (strpos($m, 'parse_') === 0) {
            $registered[] = substr($m, 6);
        }
    }
}
if (class_exists('CMS_User_Center_Template_Tags')) {
    $uc = CMS_User_Center_Template_Tags::get_instance();
    foreach (get_class_methods($uc) as $m) {
        if (strpos($m, 'parse_') === 0) {
            $registered[] = substr($m, 6);
        }
    }
}
if (class_exists('CMS_Template_Compiler_V2')) {
    $methods = get_class_methods('CMS_Template_Compiler_V2');
    foreach ($methods as $m) {
        if (strpos($m, 'parse_') === 0) {
            $registered[] = substr($m, 6);
        }
    }
}
$registered = array_values(array_unique(array_filter($registered)));

$spec_tag_map = [];
if (isset($data['tags']) && is_array($data['tags'])) {
    foreach ($data['tags'] as $t) {
        if (is_array($t) && isset($t['name'])) {
            $spec_tag_map[$t['name']] = $t;
        }
    }
}

$all_names = array_unique(array_merge(array_keys($spec_tag_map), $registered, $builtin_tags, array_keys($overrides)));
sort($all_names, SORT_NATURAL);

$tags = [];
foreach ($all_names as $name) {
    $from_spec = isset($spec_tag_map[$name]) ? $spec_tag_map[$name] : null;
    $ov = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
    $group = '';
    if (isset($from_spec['group']) && $from_spec['group'] !== '') {
        $group = (string) $from_spec['group'];
    } elseif (isset($ov['group']) && $ov['group'] !== '') {
        $group = (string) $ov['group'];
    } else {
        $group = todo_template_tags_manual_guess_group($name);
    }

    $is_block = false;
    if (isset($ov['is_block'])) {
        $is_block = (bool) $ov['is_block'];
    } elseif ($spec_md !== '' && preg_match('/\{\/todo:' . preg_quote($name, '/') . '\}/', $spec_md)) {
        $is_block = true;
    } elseif (isset($from_spec['is_block'])) {
        $is_block = (bool) $from_spec['is_block'];
    }

    $tags[] = [
        'name' => $name,
        'group' => $group,
        'line' => isset($from_spec['line']) ? (int) $from_spec['line'] : 0,
        'is_block' => $is_block,
        'snippets' => isset($from_spec['snippets']) ? $from_spec['snippets'] : [],
        'source' => isset($from_spec) ? 'spec' : (in_array($name, $builtin_tags, true) ? 'builtin' : 'code'),
        'title' => isset($ov['title']) ? (string) $ov['title'] : '',
        'tag' => isset($ov['tag']) ? (string) $ov['tag'] : '',
        'intro' => isset($ov['intro']) ? (string) $ov['intro'] : '',
    ];
}

$groups = [];
foreach ($tags as $t) {
    $g = isset($t['group']) && $t['group'] !== '' ? (string) $t['group'] : '其他';
    if (!isset($groups[$g])) {
        $groups[$g] = [];
    }
    $groups[$g][] = $t['name'];
}
ksort($groups, SORT_NATURAL);
foreach ($groups as $g => $names) {
    $names = array_values(array_unique($names));
    sort($names, SORT_NATURAL);
    $groups[$g] = $names;
}

$data['groups'] = $groups;
$data['tags'] = $tags;
$data['overrides'] = $overrides;

$active_tag = isset($_GET['tag']) ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_GET['tag']) : '';

?>

<link rel="stylesheet" href="<?php echo todo_admin_url('assets/css/template-tags-manual.css'); ?>?ver=<?php echo defined('TODO_VERSION') ? TODO_VERSION : time(); ?>">

<div class="todo-tag-manual" data-active="<?php echo esc_attr($active_tag); ?>">
    <div class="todo-tag-manual-sidebar">
        <div class="todo-tag-manual-search">
            <input type="search" id="todoTagSearch" placeholder="搜索标签/标题/场景(如: 详情页)..." autocomplete="off">
            <div class="todo-tag-quick-filters">
                <span class="quick-filter active" data-q="">全部</span>
                <span class="quick-filter" data-q="详情页">详情页</span>
                <span class="quick-filter" data-q="列表页">列表页</span>
                <span class="quick-filter" data-q="首页">首页</span>
                <span class="quick-filter" data-q="SEO">SEO</span>
                <span class="quick-filter" data-q="商品">商品/电商</span>
                <span class="quick-filter" data-q="用户中心">用户中心</span>
            </div>
        </div>
        <div class="todo-tag-manual-tree" id="todoTagTree"></div>
    </div>
    <div class="todo-tag-manual-content">
        <div class="todo-tag-manual-content-inner" id="todoTagContent"></div>
        <div class="todo-tag-manual-nav">
            <a href="javascript:void(0);" id="todoTagPrev" class="btn-normal">上一篇</a>
            <a href="javascript:void(0);" id="todoTagNext" class="btn-normal">下一篇</a>
        </div>
    </div>
</div>

<script>
window.__TODO_TEMPLATE_TAGS_MANUAL__ = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.__TODO_TEMPLATE_TAGS_MANUAL_ACTIVE__ = <?php echo json_encode($active_tag, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.__TODO_TEMPLATE_TAGS_MANUAL_PAGE_URL__ = <?php echo json_encode(todo_admin_url('index.php?page=template-tags'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo todo_admin_url('assets/js/template-tags-manual.js'); ?>?ver=<?php echo defined('TODO_VERSION') ? TODO_VERSION : time(); ?>"></script>

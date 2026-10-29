<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TodoCMS 模板标签手册覆盖数据源 (template-tags-overrides.php)
 * 
 * 经全方位代码解析比对核准：
 * 1. 彻底剔除不存在/已废弃的幽灵标签；
 * 2. 补齐所有当前真实支持的商品电商、用户中心、系统钩子、多媒体循环与分类扩展标签；
 * 3. 规范化统一全部属性结构（name, type, desc），全面支持全文检索与场景快速筛选。
 *
 * @package TodoCMS
 * @version 3.2.0
 */

return array (
  'if' => 
  array (
    'group' => '模板语法 / 条件',
    'tag' => '全局, 条件判断, 首页, 列表页, 详情页',
    'is_block' => true,
    'title' => '条件判断 if',
    'intro' => '用于模板中条件渲染，属于编译期语法标签。',
    'usage' => 
    array (
      0 => '{{todo:if condition="表达式"}}
  ...
{{todo:else}}
  ...
{{/todo:if}}',
      1 => '{{todo:if posts}}
  ...
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'condition',
        'type' => 'string',
        'desc' => '条件表达式（引号包裹）',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:if condition="{{post_title}} != \'\'"}}
  <h1>{{post_title}}</h1>
{{/todo:if}}',
      1 => '{{todo:if posts}}
  ...
{{todo:else}}
  <p>暂无内容</p>
{{/todo:if}}',
    ),
    'notes' => 
    array (
      0 => '支持 {{todo:else}}、{{todo:elseif}} 组合。',
      1 => '条件表达式的可用变量取决于模板编译器实现与运行上下文。',
    ),
    'related' => 
    array (
      0 => 'else',
      1 => 'elseif',
    ),
  ),
  'else' => 
  array (
    'group' => '模板语法 / 条件',
    'tag' => '全局, 条件判断, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '条件分支 else',
    'intro' => '配合 {{todo:if}} 使用的分支标签。',
    'usage' => 
    array (
      0 => '{{todo:if condition="..."}}
  ...
{{todo:else}}
  ...
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'if',
      1 => 'elseif',
    ),
  ),
  'elseif' => 
  array (
    'group' => '模板语法 / 条件',
    'tag' => '全局, 条件判断, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '条件分支 elseif',
    'intro' => '配合 {{todo:if}} 使用的中间分支标签。',
    'usage' => 
    array (
      0 => '{{todo:if condition="..."}}
  ...
{{todo:elseif condition="..."}}
  ...
{{todo:else}}
  ...
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'condition',
        'type' => 'string',
        'desc' => '条件表达式（引号包裹）',
      ),
    ),
    'related' => 
    array (
      0 => 'if',
      1 => 'else',
    ),
  ),
  'loop' => 
  array (
    'group' => '模板语法 / 循环',
    'tag' => '全局, 循环遍历, 首页, 列表页, 详情页',
    'is_block' => true,
    'title' => '通用循环 loop',
    'intro' => '用于遍历数据集合，属于编译期语法标签，参数以管道符/逗号分隔。',
    'usage' => 
    array (
      0 => '{{todo:loop posts|per_page=12}}
  ...
{{/todo:loop}}',
      1 => '{{todo:loop special_featured_posts}}
  ...
{{/todo:loop}}',
      2 => '{{todo:loop related_posts_by_tags|limit=6}}
  ...
{{/todo:loop}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => '<data_source>',
        'type' => 'string',
        'desc' => '数据源（posts / categories / special_featured_posts / related_posts_by_tags 等）',
      ),
      1 => 
      array (
        'name' => 'per_page',
        'type' => 'number',
        'desc' => '每页数量（posts 适用）',
      ),
      2 => 
      array (
        'name' => 'model',
        'type' => 'string',
        'desc' => '模型筛选',
      ),
      3 => 
      array (
        'name' => 'parent',
        'type' => 'number',
        'desc' => '父级 ID（分类专用）',
      ),
      4 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '最大条数',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:loop posts|per_page=12}}
  <a href="{{permalink}}">{{title}}</a>
{{/todo:loop}}',
      1 => '{{todo:loop categories|parent=2,limit=5}}
  <a href="{{url}}">{{name}}</a>
{{/todo:loop}}',
      2 => '{{todo:loop special_featured_posts}}
  <a href="{{todo:post_url}}">{{todo:post_title}}</a>
{{/todo:loop}}',
      3 => '{{todo:loop related_posts_by_tags|limit=6}}
  <a href="{{todo:post_url}}">{{todo:post_title}}</a>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'special_featured_posts',
      1 => 'related_posts_by_tags',
      2 => 'pagination',
    ),
  ),
  'special_featured_posts' => 
  array (
    'group' => '模板语法 / 循环',
    'tag' => '详情页, 文章详情页, 专题页, 推荐阅读',
    'is_block' => true,
    'title' => '手工指定文章/ID组循环 special_featured_posts',
    'intro' => '遍历当前文章自定义字段 `related_posts` 中填写的文章ID列表（如 \'120,121,222\'），严格按后台填写的顺序渲染已发布的文章。',
    'usage' => 
    array (
      0 => '{{todo:if related_posts}}
  {{todo:loop special_featured_posts}}
    <a href="{{todo:post_url}}">{{todo:post_title}}</a>
  {{/todo:loop}}
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '可选，最大展示条数',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:if related_posts}}
<div class="recommended-articles">
  <h3>推荐阅读</h3>
  {{todo:loop special_featured_posts}}
    <article>
      <a href="{{todo:post_url}}">
        {{todo:post_thumbnail class="thumb"}}
        <h4>{{todo:post_title}}</h4>
        <span>{{todo:post_date}}</span>
      </a>
    </article>
  {{/todo:loop}}
</div>
{{/todo:if}}',
    ),
    'notes' => 
    array (
      0 => '需在后台模型字段或自定义字段中为文章添加 field_key 为 `related_posts` 的字段。',
      1 => '字段值支持逗号分隔（120,121,222）、换行分隔、空格分隔或 JSON 数组格式。',
      2 => '循环内部完全支持所有的文章标签：{{todo:post_title}}、{{todo:post_url}}、{{todo:post_thumbnail}}、{{todo:post_date}}、{{todo:views}}、{{todo:post_excerpt}} 等。',
      3 => '未填写该字段的文章将被外层 {{todo:if related_posts}} 自动安全过滤隐藏。',
    ),
    'related' => 
    array (
      0 => 'loop',
      1 => 'related_posts_by_tags',
    ),
  ),
  'related_posts_by_tags' => 
  array (
    'group' => '模板语法 / 循环',
    'tag' => '详情页, 文章详情页, 相关文章',
    'is_block' => true,
    'title' => '按标签关联相关文章 related_posts_by_tags',
    'intro' => '自动获取与当前文章共享相同 Tag 标签的其他已发布文章，按共享标签数量从多到少、发布时间倒序排列。',
    'usage' => 
    array (
      0 => '{{todo:if related_posts_by_tags}}
  {{todo:loop related_posts_by_tags|limit=6}}
    <a href="{{todo:post_url}}">{{todo:post_title}}</a>
  {{/todo:loop}}
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '展示文章条数（默认 6）',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:if related_posts_by_tags}}
<div class="related-list">
  {{todo:loop related_posts_by_tags|limit=6}}
    <article>
      <a href="{{todo:post_url}}">{{todo:post_title}}</a>
      <span>{{todo:views}} 次阅读</span>
    </article>
  {{/todo:loop}}
</div>
{{/todo:if}}',
    ),
    'notes' => 
    array (
      0 => '只能在文章/资讯详情页（single 上下文）使用。',
      1 => '自动排除当前文章本身，且支持站群站点隔离与多语言自动匹配。',
    ),
    'related' => 
    array (
      0 => 'loop',
      1 => 'special_featured_posts',
    ),
  ),
  'include' => 
  array (
    'group' => '模板语法 / 组织',
    'tag' => '全局, 模块引入, 公共组件, 页头页尾',
    'is_block' => false,
    'title' => '模板包含 include',
    'intro' => '引入同主题目录内的模板片段，属于编译期语法标签。',
    'usage' => 
    array (
      0 => '{{todo:include file="header.html"}}',
      1 => '{{todo:include file="users/users_header.htm"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'file',
        'type' => 'string',
        'desc' => '相对当前模板目录的文件路径',
      ),
    ),
    'notes' => 
    array (
      0 => '建议将公共片段拆到 header/footer/sidebar 等文件，提高复用。',
    ),
  ),
  't' => 
  array (
    'group' => '多语言 / 翻译',
    'tag' => '全局, 多语言, 翻译, 公共组件',
    'is_block' => true,
    'title' => '文本翻译 t',
    'intro' => '用于短文本翻译，支持属性形式与块形式。',
    'usage' => 
    array (
      0 => '{{todo:t text="次浏览"}}',
      1 => '{{todo:t context="header"}}首页{{/todo:t}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'text',
        'type' => 'string',
        'desc' => '待翻译文本（优先级最高）',
      ),
      1 => 
      array (
        'name' => 'context',
        'type' => 'string',
        'desc' => '翻译上下文（便于同词不同义）',
      ),
      2 => 
      array (
        'name' => 'lang',
        'type' => 'string',
        'desc' => '指定语言（默认当前语言）',
      ),
      3 => 
      array (
        'name' => 'escape',
        'type' => 'html|attr|none',
        'desc' => '输出转义方式',
      ),
    ),
    'examples' => 
    array (
      0 => '<span>{{todo:t text="上一页"}}</span>',
      1 => '<title>{{todo:t text="首页"}} - {{todo:site_title}}</title>',
    ),
  ),
  'theme_url' => 
  array (
    'group' => '站点 / 主题',
    'tag' => '全局, 静态资源, 模板引入',
    'title' => '主题目录地址 theme_url',
    'intro' => '返回当前主题目录的 URL（不含结尾斜杠）。',
    'usage' => 
    array (
      0 => '{{todo:theme_url}}/assets/images/logo.png',
    ),
    'examples' => 
    array (
      0 => '&lt;img src=&quot;{{todo:theme_url}}/assets/images/logo.png&quot; alt=&quot;Logo&quot;&gt;',
      1 => '&lt;link rel=&quot;stylesheet&quot; href=&quot;{{todo:theme_url}}/assets/css/style.css&quot;&gt;',
    ),
    'notes' => 
    array (
      0 => '直接拼接路径字符串，不支持表达式运算。',
    ),
  ),
  'theme_css' => 
  array (
    'group' => '站点 / 主题',
    'tag' => '全局, 页面头部, 静态资源',
    'title' => '主题样式引入 theme_css',
    'intro' => '输出 <link> 标签引入主题内的 CSS 文件，自动添加版本号。',
    'usage' => 
    array (
      0 => '{{todo:theme_css file="assets/css/style.css"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'file',
        'type' => 'string',
        'desc' => '相对主题目录的 CSS 路径，或以 / 开头的站点根路径',
      ),
      1 => 
      array (
        'name' => 'media',
        'type' => 'string',
        'desc' => '媒体查询属性（可选）',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:theme_css file="assets/css/style.css"}}
{{todo:theme_css file="assets/css/mobile.css" media="(max-width:768px)"}}',
    ),
  ),
  'theme_js' => 
  array (
    'group' => '站点 / 主题',
    'tag' => '全局, 页面底部, 静态资源',
    'title' => '主题脚本引入 theme_js',
    'intro' => '输出 <script> 标签引入主题内的 JS 文件，自动添加版本号。',
    'usage' => 
    array (
      0 => '{{todo:theme_js file="assets/js/main.js"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'file',
        'type' => 'string',
        'desc' => '相对主题目录的 JS 路径',
      ),
      1 => 
      array (
        'name' => 'defer',
        'type' => '1|true|defer',
        'desc' => '是否 defer 加载',
      ),
      2 => 
      array (
        'name' => 'async',
        'type' => '1|true|async',
        'desc' => '是否 async 加载',
      ),
    ),
    'examples' => 
    array (
      0 => '{{todo:theme_js file="assets/js/main.js" defer="1"}}',
    ),
  ),
  'site_url' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 站点信息, 链接跳转',
    'title' => '站点地址 site_url',
    'intro' => '返回站点首页 URL（含多语言前缀）。',
    'usage' => 
    array (
      0 => '<a href="{{todo:site_url}}/">首页</a>',
    ),
    'examples' => 
    array (
      0 => '<a href="{{todo:site_url}}/">返回首页</a>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。当未获取到站点地址时的兜底 URL。',
      ),
      1 => 
      array (
        'name' => 'raw',
        'type' => 'bool',
        'desc' => '可选。是否输出原始未经多语言与子目录修饰的纯粹根域名，默认 false。',
      ),
    ),
  ),
  'site_title' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页面头部, 站点标题, SEO',
    'title' => '站点标题 site_title',
    'intro' => '返回站点标题（受多语言选项影响）。',
    'usage' => 
    array (
      0 => '<h1>{{todo:site_title}}</h1>',
    ),
    'examples' => 
    array (
      0 => '<title>{{todo:page_title}} - {{todo:site_title}}</title>',
    ),
  ),
  'site_logo' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页头页尾, 站点Logo',
    'title' => '站点 Logo site_logo',
    'intro' => '返回站点 Logo 图片 URL。',
    'usage' => 
    array (
      0 => '&lt;img src=&quot;{{todo:site_logo}}&quot; alt=&quot;{{todo:site_title}}&quot;&gt;',
    ),
    'examples' => 
    array (
      0 => '&lt;a href=&quot;{{todo:site_url}}/&quot;&gt;&lt;img src=&quot;{{todo:site_logo}}&quot; alt=&quot;{{todo:site_title}}&quot;&gt;&lt;/a&gt;',
    ),
  ),
  'site_description' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页面头部, 站点描述, SEO',
    'title' => '站点描述 site_description',
    'intro' => '返回站点描述文字（支持多语言）。',
    'usage' => 
    array (
      0 => '<p>{{todo:site_description}}</p>',
    ),
  ),
  'site_copyright' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页尾, 版权信息',
    'title' => '版权信息 site_copyright',
    'intro' => '返回站点版权声明文本（支持多语言，含换行）。',
    'usage' => 
    array (
      0 => '<footer>{{todo:site_copyright}}</footer>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。未配置版权信息时的默认兜底文本。',
      ),
    ),
  ),
  'site_icp' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页尾, 备案信息',
    'title' => 'ICP 备案号 site_icp',
    'intro' => '返回 ICP 备案号文字。',
    'usage' => 
    array (
      0 => '<span>{{todo:site_icp}}</span>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。未配置 ICP 备案号时的默认兜底文本。',
      ),
    ),
  ),
  'current_year' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页尾, 当前年份',
    'title' => '当前年份 current_year',
    'intro' => '返回当前年份，常用于版权行。',
    'usage' => 
    array (
      0 => '© {{todo:current_year}} {{todo:site_title}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => '可选。年份格式，默认 "Y"（如 2026），支持 "y"（如 26）。',
      ),
    ),
  ),
  'page_title' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, 页面标题, SEO',
    'title' => '页面标题 page_title',
    'intro' => '返回完整页面 <title>，自动适配分类/标签/文章/首页等上下文。',
    'usage' => 
    array (
      0 => '<title>{{todo:page_title}}</title>',
    ),
    'related' => 
    array (
      0 => 'meta_keywords',
      1 => 'meta_description',
    ),
  ),
  'meta_keywords' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, SEO关键词, 详情页, 列表页',
    'title' => 'SEO 关键词 meta_keywords',
    'intro' => '返回当前页面 SEO keywords，自动读取文章/分类/站点设置。',
    'usage' => 
    array (
      0 => '<meta name="keywords" content="{{todo:meta_keywords}}">',
    ),
    'related' => 
    array (
      0 => 'meta_description',
      1 => 'page_title',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章或页面的 ID，默认获取当前页 SEO 关键词。',
      ),
    ),
  ),
  'meta_description' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, SEO描述, 详情页, 列表页',
    'title' => 'SEO 描述 meta_description',
    'intro' => '返回当前页面 SEO description，自动读取文章/分类/站点设置。',
    'usage' => 
    array (
      0 => '<meta name="description" content="{{todo:meta_description}}">',
    ),
    'related' => 
    array (
      0 => 'meta_keywords',
      1 => 'page_title',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章或页面的 ID，默认获取当前页 SEO 描述。',
      ),
    ),
  ),
  'canonical_url' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, 标准链接, SEO',
    'title' => '规范链接 canonical_url',
    'intro' => '返回当前页面的规范化 URL，用于 SEO 去重。',
    'usage' => 
    array (
      0 => '<link rel="canonical" href="{{todo:canonical_url}}">',
    ),
  ),
  'og_title' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, OpenGraph, 社交分享',
    'title' => 'OG 标题 og_title',
    'intro' => '返回 Open Graph 标题，用于社交分享。',
    'usage' => 
    array (
      0 => '<meta property="og:title" content="{{todo:og_title}}">',
    ),
    'related' => 
    array (
      0 => 'og_description',
      1 => 'og_image',
    ),
  ),
  'og_description' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, OpenGraph, 社交分享',
    'title' => 'OG 描述 og_description',
    'intro' => '返回 Open Graph 描述。',
    'usage' => 
    array (
      0 => '<meta property="og:description" content="{{todo:og_description}}">',
    ),
    'related' => 
    array (
      0 => 'og_title',
      1 => 'og_image',
    ),
  ),
  'og_image' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, OpenGraph, 封面分享',
    'title' => 'OG 图片 og_image',
    'intro' => '返回 Open Graph 图片 URL。',
    'usage' => 
    array (
      0 => '<meta property="og:image" content="{{todo:og_image}}">',
    ),
    'related' => 
    array (
      0 => 'og_title',
      1 => 'og_description',
    ),
  ),
  'get_header' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 页面头部, 引入头部组件',
    'title' => '输出头部 get_header',
    'intro' => '输出主题头部（等价于 include header.html）。',
    'usage' => 
    array (
      0 => '{{todo:get_header}}',
    ),
    'related' => 
    array (
      0 => 'get_footer',
    ),
  ),
  'get_footer' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 页面底部, 引入底部组件',
    'title' => '输出底部 get_footer',
    'intro' => '输出主题底部（等价于 include footer.html）。',
    'usage' => 
    array (
      0 => '{{todo:get_footer}}',
    ),
    'related' => 
    array (
      0 => 'get_header',
    ),
  ),
  'breadcrumb' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '详情页, 列表页, 面包屑导航',
    'title' => '面包屑导航 breadcrumb',
    'intro' => '输出当前页面的面包屑导航 HTML。',
    'usage' => 
    array (
      0 => '{{todo:breadcrumb}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'separator',
        'type' => 'string',
        'desc' => '可选。面包屑各级之间的分隔符 HTML 或字符，默认 "&gt;"。',
      ),
      1 => 
      array (
        'name' => 'show_current',
        'type' => 'bool',
        'desc' => '可选。是否在末尾显示当前文章或栏目标题，默认 true。',
      ),
      2 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => '可选。输出结构类型，如 "html" 或 "json-ld" 结构化数据。',
      ),
    ),
  ),
  'csrf_token' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单交互, 安全认证, CSRF',
    'title' => 'CSRF Token csrf_token',
    'intro' => '输出 CSRF 令牌字符串，用于表单提交防护。',
    'usage' => 
    array (
      0 => '<input type="hidden" name="_todononce" value="{{todo:csrf_token}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'action',
        'type' => 'string',
        'desc' => '可选。CSRF 保护的操作动作标识，默认通用。',
      ),
    ),
    'examples' => 
    array (
      0 => '<form method="post">
  <input type="hidden" name="_todononce" value="{{todo:csrf_token}}">
  ...
</form>',
    ),
    'related' => 
    array (
      0 => 'csrf_field',
      1 => 'captcha_url',
    ),
  ),
  'csrf_field' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单交互, 隐藏表单域, 安全认证',
    'title' => 'CSRF 隐藏字段 csrf_field',
    'intro' => '直接输出完整的 <input type="hidden"> CSRF 隐藏字段，推荐用此标签代替手动拼接。',
    'usage' => 
    array (
      0 => '<form method="post">
  {{todo:csrf_field}}
  ...
</form>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'action',
        'type' => 'string',
        'desc' => '可选。关联表单的动作名。',
      ),
      1 => 
      array (
        'name' => 'field',
        'type' => 'string',
        'desc' => '可选。隐藏 input 的 name 字段名，默认 "csrf_token"。',
      ),
    ),
    'related' => 
    array (
      0 => 'csrf_token',
    ),
  ),
  'captcha_url' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单交互, 验证码图片',
    'title' => '验证码图片地址 captcha_url',
    'intro' => '返回验证码图片的 URL（含时间戳防缓存），用于 <img> 或滑块验证码接口。',
    'usage' => 
    array (
      0 => '<img id="captchaImg" src="{{todo:captcha_url}}" alt="验证码">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'type' => 'string',
        'desc' => '可选。验证码类型：default（默认字符）、math（算术题）、slide（滑块）。',
      ),
    ),
    'examples' => 
    array (
      0 => '&lt;img id=&quot;captchaImg&quot; src=&quot;{{todo:captcha_url}}&quot; alt=&quot;验证码&quot;
     onclick=&quot;this.src=&#39;{{todo:captcha_url}}&#39;&quot; style=&quot;cursor:pointer&quot;&gt;',
      1 => '// 滑块验证码接口
fetch(&#39;{{todo:captcha_url type=&quot;slider&quot;}}&#39;)',
    ),
    'notes' => 
    array (
      0 => '每次渲染都含时间戳，确保不被浏览器缓存。',
      1 => '点击刷新可通过脚本重新设置 src：el.src = &#39;{{todo:captcha_url}}&amp;r=&#39; + Date.now()',
    ),
    'related' => 
    array (
      0 => 'csrf_token',
      1 => 'captcha_enabled',
    ),
  ),
  'captcha_enabled' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单交互, 验证码开关判断',
    'title' => '验证码开关 captcha_enabled',
    'intro' => '返回 true/false，指示后台是否开启验证码功能。',
    'usage' => 
    array (
      0 => '{{todo:if captcha_enabled}}
  <img src="{{todo:captcha_url}}">
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'captcha_url',
    ),
  ),
  'pagination' => 
  array (
    'group' => '模板语法 / 分页',
    'tag' => '列表页, 文章列表页, 分类页, 搜索页, 分页导航',
    'title' => '分页 pagination',
    'intro' => '输出列表分页 HTML，需在列表循环上下文中使用。',
    'usage' => 
    array (
      0 => '{{todo:pagination}}',
    ),
    'notes' => 
    array (
      0 => '通常配合 {{todo:loop posts|per_page=...}} 等列表循环使用。',
    ),
    'related' => 
    array (
      0 => 'loop',
    ),
  ),
  'term_attribute' => 
  array (
    'group' => '分类 / 自定义字段',
    'tag' => '列表页, 分类页, 栏目自定义字段',
    'title' => '术语属性 term_attribute',
    'intro' => '读取分类/标签的自定义属性（支持多语言）。',
    'usage' => 
    array (
      0 => '{{todo:term_attribute key="banner" taxonomy="category"}}',
      1 => '{{todo:term_attribute name="banner" type="image" default=""}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'key',
        'type' => 'string',
        'desc' => '属性键名（必填）',
      ),
      1 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => 'key 的别名',
      ),
      2 => 
      array (
        'name' => 'taxonomy',
        'type' => 'category|post_tag',
        'desc' => '分类法',
      ),
      3 => 
      array (
        'name' => 'id',
        'type' => 'number',
        'desc' => '术语 ID（默认取当前查询对象）',
      ),
      4 => 
      array (
        'name' => 'type',
        'type' => 'image',
        'desc' => '字段类型（image 返回图片 HTML 或 URL）',
      ),
      5 => 
      array (
        'name' => 'size',
        'type' => 'thumbnail|medium|large|full',
        'desc' => '图片尺寸（type=image 时）',
      ),
      6 => 
      array (
        'name' => 'format',
        'type' => 'html|raw',
        'desc' => '输出格式',
      ),
      7 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '默认值',
      ),
    ),
    'related' => 
    array (
      0 => 'category_img',
    ),
  ),
  'category_img' => 
  array (
    'group' => '分类 / 媒体',
    'tag' => '列表页, 分类页, 栏目封面大图',
    'title' => '分类图片 category_img',
    'intro' => '读取后台栏目 栏目图片（多图组件）并返回指定序号的图片 URL。',
    'usage' => 
    array (
      0 => '{{todo:category_img|default=/assets/images/banner.jpg}}',
      1 => '{{todo:category_img|key=1,default=/assets/images/banner.jpg}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'number',
        'desc' => '分类 ID（不传则取当前分类）',
      ),
      1 => 
      array (
        'name' => 'taxonomy',
        'type' => 'category|post_tag',
        'desc' => '分类法',
      ),
      2 => 
      array (
        'name' => 'key',
        'type' => 'number',
        'desc' => '图片序号（0 为第一张）',
      ),
      3 => 
      array (
        'name' => 'index',
        'type' => 'number',
        'desc' => 'key 的别名',
      ),
      4 => 
      array (
        'name' => 'size',
        'type' => 'thumbnail|medium|large|full',
        'desc' => '图片尺寸',
      ),
      5 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '默认图（支持 /assets/... 路径）',
      ),
    ),
    'examples' => 
    array (
      0 => '<div style="background-image:url({{todo:category_img|default=/assets/images/banner.jpg}});"></div>',
    ),
    'related' => 
    array (
      0 => 'term_attribute',
    ),
  ),
  'nav_menu' => 
  array (
    'group' => '导航 / 菜单',
    'tag' => '全局, 页头页尾, 导航菜单',
    'title' => '导航菜单 nav_menu',
    'intro' => '输出指定位置的导航菜单 HTML。',
    'usage' => 
    array (
      0 => '{{todo:nav_menu theme_location="primary"}}',
      1 => '{{todo:nav_menu name="main-menu" class="nav-list"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'theme_location',
        'type' => 'string',
        'desc' => '菜单位置（primary / footer 等）',
      ),
      1 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => 'theme_location 的别名',
      ),
      2 => 
      array (
        'name' => 'container',
        'type' => 'string',
        'desc' => '容器标签（默认 nav）',
      ),
      3 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '菜单 ul 的 class',
      ),
    ),
  ),
  'login_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 登录链接',
    'title' => '登录地址 login_url',
    'intro' => '返回用户登录页 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:login_url}}">登录</a>',
    ),
  ),
  'logout_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 用户中心, 退出登录',
    'title' => '退出地址 logout_url',
    'intro' => '返回退出登录 URL（含 nonce 安全验证）。',
    'usage' => 
    array (
      0 => '<a href="{{todo:logout_url}}">退出</a>',
    ),
  ),
  'register_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 注册链接',
    'title' => '注册地址 register_url',
    'intro' => '返回用户注册页 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:register_url}}">注册</a>',
    ),
  ),
  'site_name' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 站点信息, 品牌名称',
    'title' => '站点名称 site_name',
    'intro' => '返回站点名称（纯文本，不含 HTML）。',
    'usage' => 
    array (
      0 => '{{todo:site_name}}',
    ),
    'examples' => 
    array (
      0 => '<meta name="application-name" content="{{todo:site_name}}">',
    ),
    'related' => 
    array (
      0 => 'site_title',
      1 => 'site_url',
    ),
  ),
  'site_keywords' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页面头部, 站点关键词, SEO',
    'title' => '站点关键词 site_keywords',
    'intro' => '返回站点全局 SEO 关键词。',
    'usage' => 
    array (
      0 => '<meta name="keywords" content="{{todo:site_keywords}}">',
    ),
    'related' => 
    array (
      0 => 'site_description',
    ),
  ),
  'public_url' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 静态资源, 公共资源路径',
    'title' => '公共资源地址 public_url',
    'intro' => '返回 CMS 公共静态资源目录的 URL（含系统内置 CSS/JS 等）。',
    'usage' => 
    array (
      0 => '{{todo:public_url}}/assets/js/app.js',
    ),
    'notes' => 
    array (
      0 => '与 theme_url 不同，此路径指向 CMS 核心公共目录，而非当前主题目录。',
    ),
  ),
  'admin_url' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 后台管理链接',
    'title' => '后台地址 admin_url',
    'intro' => '返回管理后台入口 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:admin_url}}">管理后台</a>',
    ),
  ),
  'charset' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页面头部, 字符编码',
    'title' => '字符集 charset',
    'intro' => '返回站点字符集（通常为 UTF-8）。',
    'usage' => 
    array (
      0 => '<meta charset="{{todo:charset}}">',
    ),
  ),
  'html_attributes' => 
  array (
    'group' => '站点 / 基础',
    'tag' => '全局, 页面头部, HTML属性, 多语言',
    'title' => 'HTML 根属性 html_attributes',
    'intro' => '输出 <html> 标签上的多语言属性（lang、dir 等），支持多语言方向。',
    'usage' => 
    array (
      0 => '<html {{todo:html_attributes}}>',
    ),
    'examples' => 
    array (
      0 => '<html {{todo:html_attributes}}>
  <head>...</head>
</html>',
    ),
  ),
  'language_switcher' => 
  array (
    'group' => '多语言 / 翻译',
    'tag' => '全局, 页头页尾, 导航栏, 语言切换',
    'title' => '语言切换器 language_switcher',
    'intro' => '输出多语言切换链接列表 HTML。',
    'usage' => 
    array (
      0 => '{{todo:language_switcher}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '容器 CSS class',
      ),
      1 => 
      array (
        'name' => 'format',
        'type' => 'list|dropdown',
        'desc' => '输出格式（默认 list）',
      ),
    ),
    'examples' => 
    array (
      0 => '<nav class="lang-switch">{{todo:language_switcher class="lang-list"}}</nav>',
    ),
    'related' => 
    array (
      0 => 't',
    ),
  ),
  'request_param' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单交互, URL参数读取',
    'title' => '请求参数 request_param',
    'intro' => '安全读取 GET/POST 请求参数，自动转义输出，防止 XSS。',
    'usage' => 
    array (
      0 => '{{todo:request_param name="keyword"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '参数名（必填）',
      ),
      1 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '默认值',
      ),
      2 => 
      array (
        'name' => 'method',
        'type' => 'get|post|any',
        'desc' => '取值来源（默认 any）',
      ),
      3 => 
      array (
        'name' => 'escape',
        'type' => 'html|attr|none',
        'desc' => '转义方式（默认 html）',
      ),
    ),
    'examples' => 
    array (
      0 => '<input value="{{todo:request_param name=\\"keyword\\" default=\\"\\"}}" type="text">',
    ),
    'notes' => 
    array (
      0 => '默认对输出进行 HTML 实体编码，若用于 JS 变量请使用 escape="none" 并自行处理。',
    ),
    'related' => 
    array (
      0 => 'csrf_token',
    ),
  ),
  'third_party_login_url' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 登录页, 用户中心, 第三方登录',
    'title' => '第三方登录地址 third_party_login_url',
    'intro' => '返回指定第三方平台（微信、QQ 等）的 OAuth 登录跳转 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:third_party_login_url provider=\\"wechat\\"}}">微信登录</a>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'provider',
        'type' => 'string',
        'desc' => '平台标识（wechat / qq / weibo 等）',
      ),
    ),
    'related' => 
    array (
      0 => 'login_url',
    ),
  ),
  'class_if' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 动态样式, 交互高亮',
    'title' => '条件 class class_if',
    'intro' => '在 HTML 属性中内联输出条件 CSS class，解决属性内无法嵌套块级 if 的问题。',
    'usage' => 
    array (
      0 => '<li class="{{todo:class_if condition=\\"is_home\\" value=\\"active\\"}}">首页</li>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'condition',
        'type' => 'string',
        'desc' => '条件（支持系统布尔变量）',
      ),
      1 => 
      array (
        'name' => 'value',
        'type' => 'string',
        'desc' => '条件为真时输出的 class 名',
      ),
      2 => 
      array (
        'name' => 'else',
        'type' => 'string',
        'desc' => '条件为假时输出的 class 名（可选）',
      ),
    ),
    'related' => 
    array (
      0 => 'value_if',
    ),
  ),
  'value_if' => 
  array (
    'group' => '安全 / 表单',
    'tag' => '全局, 表单回显, 动态取值',
    'title' => '条件值 value_if',
    'intro' => '在 HTML 属性中内联输出条件值，与 class_if 类似但用于任意属性值。',
    'usage' => 
    array (
      0 => '<input {{todo:value_if condition=\\"is_checked\\" value=\\"checked\\"}}>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'condition',
        'type' => 'string',
        'desc' => '条件',
      ),
      1 => 
      array (
        'name' => 'value',
        'type' => 'string',
        'desc' => '条件为真时的输出值',
      ),
      2 => 
      array (
        'name' => 'else',
        'type' => 'string',
        'desc' => '条件为假时的输出值（可选）',
      ),
    ),
    'related' => 
    array (
      0 => 'class_if',
    ),
  ),
  'meta_title' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 页面头部, SEO标题, 详情页, 列表页',
    'title' => 'Meta 标题 meta_title',
    'intro' => '返回当前页面 <title> 标签内容，与 page_title 等价，语义更明确。',
    'usage' => 
    array (
      0 => '<title>{{todo:meta_title}}</title>',
    ),
    'related' => 
    array (
      0 => 'page_title',
      1 => 'meta_description',
    ),
  ),
  'current_url' => 
  array (
    'group' => '页面 / SEO',
    'tag' => '全局, 当前页面网址',
    'title' => '当前页面 URL current_url',
    'intro' => '返回当前请求的完整 URL（含协议、域名、路径、查询串）。',
    'usage' => 
    array (
      0 => '{{todo:current_url}}',
    ),
    'related' => 
    array (
      0 => 'canonical_url',
      1 => 'site_url',
    ),
  ),
  'post_title' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章详情页, 商品详情页, 列表页, 文章标题',
    'title' => '文章标题 post_title',
    'intro' => '返回当前文章标题（支持多语言），在循环体内也可使用。',
    'usage' => 
    array (
      0 => '<h1>{{todo:post_title}}</h1>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'escape',
        'type' => 'html|attr|none',
        'desc' => '转义方式（默认 html）',
      ),
    ),
    'related' => 
    array (
      0 => 'post_content',
      1 => 'post_url',
    ),
  ),
  'post_content' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章详情页, 单页, 文章正文内容',
    'title' => '文章内容 post_content',
    'intro' => '输出当前文章的完整正文 HTML（已过滤 XSS）。',
    'usage' => 
    array (
      0 => '<div class="article-body">{{todo:post_content}}</div>',
    ),
    'related' => 
    array (
      0 => 'post_excerpt',
      1 => 'post_title',
    ),
  ),
  'post_excerpt' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章列表页, 文章摘要简介',
    'title' => '文章摘要 post_excerpt',
    'intro' => '返回文章摘要；若未设置则自动截取正文前 N 字。',
    'usage' => 
    array (
      0 => '<p>{{todo:post_excerpt}}</p>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'length',
        'type' => 'number',
        'desc' => '自动截取字数（默认 120）',
      ),
      1 => 
      array (
        'name' => 'more',
        'type' => 'string',
        'desc' => '截断后的省略符（默认 ...）',
      ),
    ),
    'related' => 
    array (
      0 => 'post_content',
    ),
  ),
  'post_date' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章列表页, 发布时间',
    'title' => '发布时间 post_date',
    'intro' => '返回文章发布时间。',
    'usage' => 
    array (
      0 => '<time>{{todo:post_date}}</time>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => 'PHP date() 格式，默认 Y-m-d',
      ),
    ),
    'examples' => 
    array (
      0 => '<time datetime="{{todo:post_date format=\\"c\\"}}">{{todo:post_date format=\\"Y年m月d日\\"}}</time>',
    ),
    'related' => 
    array (
      0 => 'post_modified',
    ),
  ),
  'post_modified' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章详情页, 更新时间',
    'title' => '修改时间 post_modified',
    'intro' => '返回文章最后修改时间。',
    'usage' => 
    array (
      0 => '<span>更新：{{todo:post_modified}}</span>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => 'PHP date() 格式，默认 Y-m-d',
      ),
    ),
    'related' => 
    array (
      0 => 'post_date',
    ),
  ),
  'post_url' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章列表页, 文章链接',
    'title' => '文章链接 post_url',
    'intro' => '返回当前文章的 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:post_url}}">阅读全文</a>',
    ),
    'related' => 
    array (
      0 => 'post_title',
      1 => 'permalink',
    ),
  ),
  'post_id' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章ID',
    'title' => '文章 ID post_id',
    'intro' => '返回当前文章的数字 ID。',
    'usage' => 
    array (
      0 => '<div id="post-{{todo:post_id}}">',
    ),
  ),
  'post_slug' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章别名',
    'title' => '文章别名 post_slug',
    'intro' => '返回文章的 URL slug（英文别名）。',
    'usage' => 
    array (
      0 => '{{todo:post_slug}}',
    ),
  ),
  'post_thumbnail' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章列表页, 缩略图, 封面图',
    'title' => '特色图片标签 post_thumbnail',
    'intro' => '输出完整的 <img> 特色图片标签（含 alt、尺寸属性）。',
    'usage' => 
    array (
      0 => '{{todo:post_thumbnail}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'thumbnail|medium|large|full',
        'desc' => '图片尺寸',
      ),
      1 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => 'img 的 CSS class',
      ),
      2 => 
      array (
        'name' => 'alt',
        'type' => 'string',
        'desc' => 'alt 文字（默认取文章标题）',
      ),
      3 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '无图时显示的默认图 URL',
      ),
    ),
    'related' => 
    array (
      0 => 'post_thumbnail_url',
    ),
  ),
  'post_thumbnail_url' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章列表页, 封面图地址',
    'title' => '特色图片地址 post_thumbnail_url',
    'intro' => '返回特色图片的原始 URL（不含 img 标签）。',
    'usage' => 
    array (
      0 => '<img src="{{todo:post_thumbnail_url}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'thumbnail|medium|large|full',
        'desc' => '图片尺寸',
      ),
      1 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '无图时的默认 URL',
      ),
    ),
    'related' => 
    array (
      0 => 'post_thumbnail',
    ),
  ),
  'post_tags_string' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章详情页, 标签字符串',
    'title' => '标签字符串 post_tags_string',
    'intro' => '返回当前文章的所有标签名，以逗号拼接成字符串。',
    'usage' => 
    array (
      0 => '<meta name="keywords" content="{{todo:post_tags_string}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'separator',
        'type' => 'string',
        'desc' => '分隔符（默认英文逗号）',
      ),
    ),
    'related' => 
    array (
      0 => 'post_tags',
    ),
  ),
  'comment_count' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 评论数量',
    'title' => '评论数 comment_count',
    'intro' => '返回当前文章的评论数量。',
    'usage' => 
    array (
      0 => '<span>{{todo:comment_count}} 条评论</span>',
    ),
    'related' => 
    array (
      0 => 'views',
    ),
  ),
  'views' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 文章详情页, 阅读量, 浏览数',
    'title' => '浏览量 views',
    'intro' => '返回当前文章的浏览次数。',
    'usage' => 
    array (
      0 => '<span>{{todo:views}} 次浏览</span>',
    ),
    'related' => 
    array (
      0 => 'comment_count',
    ),
  ),
  'category' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 列表页, 所属栏目分类',
    'title' => '所属分类名 category',
    'intro' => '返回当前文章的主分类名称。',
    'usage' => 
    array (
      0 => '<span>{{todo:category}}</span>',
    ),
    'related' => 
    array (
      0 => 'category_name',
    ),
  ),
  'post_current_price' => 
  array (
    'group' => '文章 / 产品',
    'tag' => '详情页, 商品详情页, 商品列表页, 售价价格',
    'title' => '当前价格 post_current_price',
    'intro' => '返回产品当前售价（已自动包含货币符号并格式化）。注意：系统已自带货币符号，无需在标签前手动添加 $ 或 {{todo:currency_code}}，避免生成双货币符号。',
    'usage' => 
    array (
      0 => '<span class="price">{{todo:post_current_price}}</span>',
    ),
    'related' => 
    array (
      0 => 'post_original_price',
    ),
  ),
  'post_original_price' => 
  array (
    'group' => '文章 / 产品',
    'tag' => '详情页, 商品详情页, 商品列表页, 原价划线价',
    'title' => '原始价格 post_original_price',
    'intro' => '返回产品的原始划线价（已自动包含货币符号）。无需在标签前手动添加货币符号。',
    'usage' => 
    array (
      0 => '<del class="original-price">{{todo:post_original_price}}</del>',
    ),
    'related' => 
    array (
      0 => 'post_current_price',
    ),
  ),
  'post_stock' => 
  array (
    'group' => '文章 / 产品',
    'tag' => '详情页, 商品详情页, 商品列表页, 库存数量',
    'title' => '库存 post_stock',
    'intro' => '返回产品库存数量（-1 表示不限库存）。',
    'usage' => 
    array (
      0 => '<span>库存：{{todo:post_stock}}</span>',
    ),
  ),
  'post_spec_type' => 
  array (
    'group' => '文章 / 产品',
    'tag' => '详情页, 商品详情页, 商品多规格判断',
    'title' => '规格类型 post_spec_type',
    'intro' => '返回产品规格模式标识（single / multi 等）。',
    'usage' => 
    array (
      0 => '{{todo:if condition="{{post_spec_type}} == \'multi\'"}}...{{/todo:if}}',
    ),
  ),
  'prev_post' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 上一篇',
    'title' => '上一篇（块） prev_post',
    'intro' => '输出上一篇文章的导航块 HTML（含标题与链接）。',
    'usage' => 
    array (
      0 => '{{todo:prev_post}}',
    ),
    'related' => 
    array (
      0 => 'next_post',
      1 => 'prev_post_url',
    ),
  ),
  'next_post' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 下一篇',
    'title' => '下一篇（块） next_post',
    'intro' => '输出下一篇文章的导航块 HTML（含标题与链接）。',
    'usage' => 
    array (
      0 => '{{todo:next_post}}',
    ),
    'related' => 
    array (
      0 => 'prev_post',
      1 => 'next_post_url',
    ),
  ),
  'prev_post_url' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 上一篇链接',
    'title' => '上一篇链接 prev_post_url',
    'intro' => '返回上一篇文章的 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:prev_post_url}}">上一篇</a>',
    ),
    'related' => 
    array (
      0 => 'prev_post_title',
    ),
  ),
  'prev_post_title' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 上一篇标题',
    'title' => '上一篇标题 prev_post_title',
    'intro' => '返回上一篇文章的标题文本。',
    'usage' => 
    array (
      0 => '<a href="{{todo:prev_post_url}}">{{todo:prev_post_title}}</a>',
    ),
    'related' => 
    array (
      0 => 'prev_post_url',
    ),
  ),
  'next_post_url' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 下一篇链接',
    'title' => '下一篇链接 next_post_url',
    'intro' => '返回下一篇文章的 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:next_post_url}}">下一篇</a>',
    ),
    'related' => 
    array (
      0 => 'next_post_title',
    ),
  ),
  'next_post_title' => 
  array (
    'group' => '文章 / 导航',
    'tag' => '详情页, 文章详情页, 下一篇标题',
    'title' => '下一篇标题 next_post_title',
    'intro' => '返回下一篇文章的标题文本。',
    'usage' => 
    array (
      0 => '<a href="{{todo:next_post_url}}">{{todo:next_post_title}}</a>',
    ),
    'related' => 
    array (
      0 => 'next_post_url',
    ),
  ),
  'author_name' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者用户名',
    'title' => '作者用户名 author_name',
    'intro' => '返回文章作者的用户名（登录名）。',
    'usage' => 
    array (
      0 => '<span>{{todo:author_name}}</span>',
    ),
    'related' => 
    array (
      0 => 'author_display_name',
    ),
  ),
  'author_display_name' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者昵称',
    'title' => '作者昵称 author_display_name',
    'intro' => '返回文章作者的显示名称（昵称）。',
    'usage' => 
    array (
      0 => '<span>作者：{{todo:author_display_name}}</span>',
    ),
    'related' => 
    array (
      0 => 'author_name',
      1 => 'author_avatar',
    ),
  ),
  'author_email' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者邮箱',
    'title' => '作者邮箱 author_email',
    'intro' => '返回文章作者的邮箱地址（注意隐私）。',
    'usage' => 
    array (
      0 => '{{todo:author_email}}',
    ),
    'related' => 
    array (
      0 => 'author_name',
    ),
  ),
  'author_url' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者主页链接',
    'title' => '作者主页链接 author_url',
    'intro' => '返回作者的个人主页或用户中心 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:author_url}}">{{todo:author_display_name}}</a>',
    ),
  ),
  'author_description' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者个人简介',
    'title' => '作者简介 author_description',
    'intro' => '返回作者的个人简介文本。',
    'usage' => 
    array (
      0 => '<p>{{todo:author_description}}</p>',
    ),
    'related' => 
    array (
      0 => 'author_display_name',
    ),
  ),
  'author_avatar' => 
  array (
    'group' => '文章 / 作者',
    'tag' => '详情页, 文章详情页, 作者头像图片',
    'title' => '作者头像 author_avatar',
    'intro' => '输出作者头像 <img> 标签（或头像 URL）。',
    'usage' => 
    array (
      0 => '<img src="{{todo:author_avatar}}" class="avatar">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'number',
        'desc' => '头像尺寸 px（默认 48）',
      ),
      1 => 
      array (
        'name' => 'format',
        'type' => 'url|html',
        'desc' => '输出格式（默认 url）',
      ),
    ),
    'related' => 
    array (
      0 => 'author_display_name',
    ),
  ),
  'post_categories' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '详情页, 文章详情页, 所属分类列表',
    'title' => '文章分类列表 post_categories',
    'intro' => '输出当前文章所属的全部分类，以带链接的 HTML 列表呈现。',
    'usage' => 
    array (
      0 => '{{todo:post_categories}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'separator',
        'type' => 'string',
        'desc' => '分隔符（默认逗号空格）',
      ),
      1 => 
      array (
        'name' => 'link',
        'type' => '0|1',
        'desc' => '是否输出链接（默认 1）',
      ),
    ),
    'related' => 
    array (
      0 => 'post_tags',
      1 => 'category',
    ),
  ),
  'post_tags' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '详情页, 文章详情页, 文章标签列表',
    'title' => '文章标签列表 post_tags',
    'intro' => '输出当前文章的所有标签（带链接）。',
    'usage' => 
    array (
      0 => '<div class="tags">{{todo:post_tags}}</div>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'separator',
        'type' => 'string',
        'desc' => '分隔符（默认空格）',
      ),
      1 => 
      array (
        'name' => 'link',
        'type' => '0|1',
        'desc' => '是否输出链接（默认 1）',
      ),
    ),
    'related' => 
    array (
      0 => 'post_categories',
      1 => 'tag_name',
    ),
  ),
  'tag_name' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 标签页, 当前标签名称',
    'title' => '当前标签名 tag_name',
    'intro' => '返回当前标签归档页的标签名称。',
    'usage' => 
    array (
      0 => '<h1>标签：{{todo:tag_name}}</h1>',
    ),
    'related' => 
    array (
      0 => 'tag_url',
    ),
  ),
  'tag_url' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 标签页, 当前标签链接',
    'title' => '当前标签链接 tag_url',
    'intro' => '返回当前标签归档页的 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:tag_url}}">{{todo:tag_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'tag_name',
    ),
  ),
  'category_name' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 栏目名称',
    'title' => '分类页面 - 分类名 category_name',
    'intro' => '在分类列表页中输出当前分类的名称。',
    'usage' => 
    array (
      0 => '<h1>{{todo:category_name}}</h1>',
    ),
    'related' => 
    array (
      0 => 'category_slug',
      1 => 'category_id',
      2 => 'category_url',
    ),
  ),
  'category_slug' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 栏目标题别名',
    'title' => '分类页面 - 分类别名 category_slug',
    'intro' => '在分类列表页中输出当前分类的别名（slug）。',
    'usage' => 
    array (
      0 => '<a href="{{todo:category_url}}" title="{{todo:category_slug}}">{{todo:category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'category_name',
      1 => 'category_url',
    ),
  ),
  'category_id' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 栏目ID',
    'title' => '分类页面 - 分类ID category_id',
    'intro' => '在分类列表页中输出当前分类的 ID。',
    'usage' => 
    array (
      0 => '<body class="cat-{{todo:category_id}}">',
    ),
    'related' => 
    array (
      0 => 'category_name',
    ),
  ),
  'category_url' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 栏目链接',
    'title' => '分类链接 category_url',
    'intro' => '返回当前分类的 URL。文章循环内返回主分类链接，分类列表页中返回当前分类链接。',
    'usage' => 
    array (
      0 => '<a href="{{todo:category_url}}">{{todo:category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'category_name',
      1 => 'category_slug',
    ),
  ),
  'parent_category_name' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 父级栏目名称',
    'title' => '分类页面 - 父分类名 parent_category_name',
    'intro' => '在分类列表页中输出当前分类的父分类名称。',
    'usage' => 
    array (
      0 => '<a href="{{todo:parent_category_url}}">{{todo:parent_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'parent_category_slug',
      1 => 'parent_category_id',
      2 => 'parent_category_url',
    ),
  ),
  'parent_category_slug' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 父级栏目标题别名',
    'title' => '分类页面 - 父分类别名 parent_category_slug',
    'intro' => '在分类列表页中输出当前分类的父分类别名。',
    'usage' => 
    array (
      0 => '<a href="{{todo:parent_category_url}}" title="{{todo:parent_category_slug}}">{{todo:parent_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'parent_category_name',
      1 => 'parent_category_url',
    ),
  ),
  'parent_category_id' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 父级栏目ID',
    'title' => '分类页面 - 父分类ID parent_category_id',
    'intro' => '在分类列表页中输出当前分类的父分类 ID。',
    'usage' => 
    array (
      0 => '<body class="parent-cat-{{todo:parent_category_id}}">',
    ),
    'related' => 
    array (
      0 => 'parent_category_name',
    ),
  ),
  'parent_category_url' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 父级栏目链接',
    'title' => '分类页面 - 父分类链接 parent_category_url',
    'intro' => '在分类列表页中输出当前分类的父分类 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:parent_category_url}}">{{todo:parent_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'parent_category_name',
      1 => 'parent_category_slug',
    ),
  ),
  'top_category_name' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 顶级分类名称',
    'title' => '分类页面 - 顶级分类名 top_category_name',
    'intro' => '在分类列表页中输出当前分类所属的顶级分类名称。',
    'usage' => 
    array (
      0 => '<a href="{{todo:top_category_url}}">{{todo:top_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'top_category_slug',
      1 => 'top_category_id',
      2 => 'top_category_url',
    ),
  ),
  'top_category_slug' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 顶级分类别名',
    'title' => '分类页面 - 顶级分类别名 top_category_slug',
    'intro' => '在分类列表页中输出当前分类所属的顶级分类别名。',
    'usage' => 
    array (
      0 => '<a href="{{todo:top_category_url}}" title="{{todo:top_category_slug}}">{{todo:top_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'top_category_name',
      1 => 'top_category_url',
    ),
  ),
  'top_category_id' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 顶级分类ID',
    'title' => '分类页面 - 顶级分类ID top_category_id',
    'intro' => '在分类列表页中输出当前分类所属的顶级分类 ID。',
    'usage' => 
    array (
      0 => '<body class="top-cat-{{todo:top_category_id}}">',
    ),
    'related' => 
    array (
      0 => 'top_category_name',
    ),
  ),
  'top_category_url' => 
  array (
    'group' => '分类 / 标签',
    'tag' => '列表页, 分类页, 顶级分类链接',
    'title' => '分类页面 - 顶级分类链接 top_category_url',
    'intro' => '在分类列表页中输出当前分类所属的顶级分类 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:top_category_url}}">{{todo:top_category_name}}</a>',
    ),
    'related' => 
    array (
      0 => 'top_category_name',
      1 => 'top_category_slug',
    ),
  ),
  'articles' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 文章列表页, 文章推荐块',
    'is_block' => true,
    'title' => '文章列表循环 articles',
    'intro' => '循环输出文章列表，支持分类筛选、分页、排序等参数。',
    'usage' => 
    array (
      0 => '{{todo:articles per_page="10"}}
  <a href="{{todo:post_url}}">{{todo:post_title}}</a>
{{/todo:articles}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'per_page',
        'type' => 'number',
        'desc' => '每页数量（默认 10）',
      ),
      1 => 
      array (
        'name' => 'id',
        'type' => 'number|string',
        'desc' => '包含的分类 ID，支持多 ID 逗号分隔（如 id="7,16"）',
      ),
      2 => 
      array (
        'name' => 'category',
        'type' => 'number|string',
        'desc' => '分类 ID 或 slug，支持多 ID 逗号分隔（如 category="7,16"）',
      ),
      3 => 
      array (
        'name' => 'tag',
        'type' => 'string',
        'desc' => '标签 slug',
      ),
      4 => 
      array (
        'name' => 'orderby',
        'type' => 'date|views|id',
        'desc' => '排序字段',
      ),
      5 => 
      array (
        'name' => 'order',
        'type' => 'ASC|DESC',
        'desc' => '排序方向',
      ),
      6 => 
      array (
        'name' => 'model',
        'type' => 'string',
        'desc' => '内容模型筛选',
      ),
      7 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '返回数量上限',
      ),
      8 => 
      array (
        'name' => 'noid',
        'type' => 'string',
        'desc' => '排除的文章ID，逗号分隔（如 noid="6,8"）',
      ),
      9 => 
      array (
        'name' => 'doc_properties',
        'type' => 'string',
        'desc' => '筛选文档属性ID，逗号分隔',
      ),
      10 => 
      array (
        'name' => 'visibility',
        'type' => 'string',
        'desc' => '文章可见性筛选（如 visibility="readable" 或 "public,readable"、"any"）',
      ),
    ),
    'related' => 
    array (
      0 => 'pagination',
    ),
  ),
  'products' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 商品列表页, 商城推荐块',
    'is_block' => true,
    'title' => '产品列表循环 products',
    'intro' => '循环输出产品列表，用法与 articles 类似，额外支持价格排序。',
    'usage' => 
    array (
      0 => '{{todo:products per_page="12" category="1"}}
  ...
{{/todo:products}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'per_page',
        'type' => 'number',
        'desc' => '每页数量',
      ),
      1 => 
      array (
        'name' => 'category',
        'type' => 'number',
        'desc' => '分类 ID',
      ),
      2 => 
      array (
        'name' => 'orderby',
        'type' => 'date|price|views',
        'desc' => '排序字段',
      ),
    ),
    'related' => 
    array (
      0 => 'articles',
      1 => 'pagination',
    ),
  ),
  'jobs' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 招聘列表页, 职位循环',
    'is_block' => true,
    'title' => '职位列表循环 jobs',
    'intro' => '循环输出职位招聘列表。',
    'usage' => 
    array (
      0 => '{{todo:jobs per_page="10"}}
  ...
{{/todo:jobs}}',
    ),
    'related' => 
    array (
      0 => 'articles',
    ),
  ),
  'specials' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 专题列表页, 专题循环',
    'is_block' => true,
    'title' => '专题列表循环 specials',
    'intro' => '循环输出专题/活动列表。',
    'usage' => 
    array (
      0 => '{{todo:specials per_page="6"}}
  ...
{{/todo:specials}}',
    ),
    'related' => 
    array (
      0 => 'articles',
    ),
  ),
  'guestbooks' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '列表页, 留言板, 留言列表循环',
    'is_block' => true,
    'title' => '留言列表循环 guestbooks',
    'intro' => '循环输出留言/评论列表。',
    'usage' => 
    array (
      0 => '{{todo:guestbooks per_page="10"}}
  ...
{{/todo:guestbooks}}',
    ),
  ),
  'single_pages' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 单页列表循环',
    'is_block' => true,
    'title' => '单页列表循环 single_pages',
    'intro' => '循环输出单页内容（关于、联系等）列表。',
    'usage' => 
    array (
      0 => '{{todo:single_pages}}
  ...
{{/todo:single_pages}}',
    ),
  ),
  'images' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 图集列表页, 相册循环',
    'is_block' => true,
    'title' => '图片列表循环 images',
    'intro' => '循环输出图片类型内容。',
    'usage' => 
    array (
      0 => '{{todo:images per_page="12"}}
  <img src="{{todo:post_thumbnail_url}}">
{{/todo:images}}',
    ),
  ),
  'downloads' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 下载列表页, 资源循环',
    'is_block' => true,
    'title' => '下载列表循环 downloads',
    'intro' => '循环输出下载资源列表。',
    'usage' => 
    array (
      0 => '{{todo:downloads per_page="10"}}
  ...
{{/todo:downloads}}',
    ),
  ),
  'media' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 列表页, 视频列表页, 影音循环',
    'is_block' => true,
    'title' => '媒体列表循环 media',
    'intro' => '循环输出媒体库文件列表。',
    'usage' => 
    array (
      0 => '{{todo:media per_page="12"}}
  ...
{{/todo:media}}',
    ),
  ),
  'search_results' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '搜索页, 搜索结果列表循环',
    'is_block' => true,
    'title' => '搜索结果循环 search_results',
    'intro' => '循环输出搜索结果列表，自动读取当前搜索关键词。',
    'usage' => 
    array (
      0 => '{{todo:search_results per_page="10"}}
  ...
{{/todo:search_results}}',
    ),
    'related' => 
    array (
      0 => 'search_query',
      1 => 'found_posts',
    ),
  ),
  'archives' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '列表页, 归档列表页, 时间归档循环',
    'is_block' => true,
    'title' => '归档列表循环 archives',
    'intro' => '循环输出按月/年归档的文章列表。',
    'usage' => 
    array (
      0 => '{{todo:archives}}
  <a href="{{todo:url}}">{{todo:label}} ({{todo:count}})</a>
{{/todo:archives}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'type' => 'monthly|yearly',
        'desc' => '归档类型（默认 monthly）',
      ),
      1 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '最大条数',
      ),
    ),
  ),
  'links' => 
  array (
    'group' => '循环 / 列表',
    'tag' => '首页, 页脚, 友情链接列表循环',
    'is_block' => true,
    'title' => '友情链接循环 links',
    'intro' => '循环输出友情链接列表。',
    'usage' => 
    array (
      0 => '{{todo:links}}
  <a href="{{todo:url}}" target="_blank">{{todo:name}}</a>
{{/todo:links}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'group',
        'type' => 'string',
        'desc' => '链接分组名称',
      ),
      1 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '最大数量',
      ),
    ),
  ),
  'if_home' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 首页, 条件判断',
    'is_block' => true,
    'title' => '首页条件 if_home',
    'intro' => '当当前页面为首页时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_home}}
  <h1>欢迎来到首页</h1>
{{/todo:if_home}}',
    ),
    'related' => 
    array (
      0 => 'if_single',
      1 => 'if_category',
    ),
  ),
  'if_single' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 详情页, 文章详情页, 条件判断',
    'is_block' => true,
    'title' => '文章详情条件 if_single',
    'intro' => '当当前页面为文章详情页时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_single}}
  ...
{{/todo:if_single}}',
    ),
    'related' => 
    array (
      0 => 'if_home',
      1 => 'if_category',
    ),
  ),
  'if_page' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 单页, 独立页, 条件判断',
    'is_block' => true,
    'title' => '单页条件 if_page',
    'intro' => '当当前页面为单页（page）时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_page}}
  ...
{{/todo:if_page}}',
    ),
  ),
  'if_category' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 列表页, 分类页, 条件判断',
    'is_block' => true,
    'title' => '分类页条件 if_category',
    'intro' => '当当前页面为分类归档页时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_category}}
  <h1>{{todo:current_page_title}}</h1>
{{/todo:if_category}}',
    ),
    'related' => 
    array (
      0 => 'if_tag',
      1 => 'if_archive',
    ),
  ),
  'if_tag' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 列表页, 标签页, 条件判断',
    'is_block' => true,
    'title' => '标签页条件 if_tag',
    'intro' => '当当前页面为标签归档页时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_tag}}
  <h1>标签：{{todo:tag_name}}</h1>
{{/todo:if_tag}}',
    ),
    'related' => 
    array (
      0 => 'if_category',
      1 => 'tag_name',
    ),
  ),
  'if_archive' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 列表页, 归档页, 条件判断',
    'is_block' => true,
    'title' => '归档页条件 if_archive',
    'intro' => '当前页面为任意归档页（分类/标签/日期）时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_archive}}
  ...
{{/todo:if_archive}}',
    ),
  ),
  'if_search' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 搜索页, 条件判断',
    'is_block' => true,
    'title' => '搜索页条件 if_search',
    'intro' => '当当前页面为搜索结果页时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_search}}
  <h1>搜索：{{todo:search_query}}</h1>
{{/todo:if_search}}',
    ),
    'related' => 
    array (
      0 => 'search_query',
    ),
  ),
  'if_404' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '全局, 404错误页, 条件判断',
    'is_block' => true,
    'title' => '404 条件 if_404',
    'intro' => '当当前请求为 404 Not Found 时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_404}}
  <h1>页面未找到</h1>
{{/todo:if_404}}',
    ),
  ),
  'if_post_tags' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '详情页, 文章详情页, 是否有标签判断',
    'is_block' => true,
    'title' => '文章有标签条件 if_post_tags',
    'intro' => '当当前文章存在标签时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_post_tags}}
  {{todo:post_tags}}
{{/todo:if_post_tags}}',
    ),
    'related' => 
    array (
      0 => 'post_tags',
    ),
  ),
  'if_has_post_thumbnail' => 
  array (
    'group' => '条件 / 页面类型',
    'tag' => '详情页, 列表页, 是否有封面图判断',
    'is_block' => true,
    'title' => '有特色图片条件 if_has_post_thumbnail',
    'intro' => '当前文章存在特色图片时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_has_post_thumbnail}}
  {{todo:post_thumbnail}}
{{/todo:if_has_post_thumbnail}}',
    ),
    'related' => 
    array (
      0 => 'post_thumbnail',
    ),
  ),
  'if_user_logged_in' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 登录状态判断',
    'is_block' => true,
    'title' => '已登录条件 if_user_logged_in',
    'intro' => '当访客已登录时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_user_logged_in}}
  <a href="{{todo:logout_url}}">退出</a>
{{/todo:if_user_logged_in}}',
    ),
    'related' => 
    array (
      0 => 'if_user_not_logged_in',
    ),
  ),
  'if_user_not_logged_in' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 未登录状态判断',
    'is_block' => true,
    'title' => '未登录条件 if_user_not_logged_in',
    'intro' => '当访客未登录时渲染内容。',
    'usage' => 
    array (
      0 => '{{todo:if_user_not_logged_in}}
  <a href="{{todo:login_url}}">登录</a>
{{/todo:if_user_not_logged_in}}',
    ),
    'related' => 
    array (
      0 => 'if_user_logged_in',
      1 => 'login_url',
    ),
  ),
  'header' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 页面头部, 引入header',
    'title' => '输出头部 header',
    'intro' => '输出主题头部模板（header.html），是 get_header 的别名。',
    'usage' => 
    array (
      0 => '{{todo:header}}',
    ),
    'related' => 
    array (
      0 => 'footer',
      1 => 'sidebar',
      2 => 'get_header',
    ),
  ),
  'footer' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 页面底部, 引入footer',
    'title' => '输出底部 footer',
    'intro' => '输出主题底部模板（footer.html），是 get_footer 的别名。',
    'usage' => 
    array (
      0 => '{{todo:footer}}',
    ),
    'related' => 
    array (
      0 => 'header',
      1 => 'get_footer',
    ),
  ),
  'sidebar' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '详情页, 列表页, 侧边栏',
    'title' => '输出侧边栏 sidebar',
    'intro' => '输出主题侧边栏模板（sidebar.html）。',
    'usage' => 
    array (
      0 => '{{todo:sidebar}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '侧边栏文件名（默认 sidebar.html）',
      ),
    ),
    'related' => 
    array (
      0 => 'header',
      1 => 'footer',
    ),
  ),
  'breadcrumb_list' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '详情页, 列表页, 面包屑导航数据',
    'title' => '面包屑列表数据 breadcrumb_list',
    'intro' => '输出面包屑数据的 HTML <ol> 列表，便于自定义样式。',
    'usage' => 
    array (
      0 => '{{todo:breadcrumb_list}}',
    ),
    'related' => 
    array (
      0 => 'breadcrumb',
      1 => 'breadcrumb_json',
    ),
  ),
  'breadcrumb_json' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 页面头部, SEO结构化数据',
    'title' => '面包屑结构化数据 breadcrumb_json',
    'intro' => '输出 BreadcrumbList JSON-LD 结构化数据，用于 SEO 富摘要。',
    'usage' => 
    array (
      0 => '{{todo:breadcrumb_json}}',
    ),
    'notes' => 
    array (
      0 => '放在 <head> 内或页面顶部，自动输出 <script type="application/ld+json">。',
    ),
    'related' => 
    array (
      0 => 'breadcrumb',
      1 => 'seo_meta',
    ),
  ),
  'current_page_title' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 列表页, 详情页, 页面标题',
    'title' => '当前页面标题文字 current_page_title',
    'intro' => '返回当前页面的可读标题文字（分类名、搜索词、文章标题等），适合用于 <h1>。',
    'usage' => 
    array (
      0 => '<h1>{{todo:current_page_title}}</h1>',
    ),
    'related' => 
    array (
      0 => 'page_title',
      1 => 'current_page_type',
    ),
  ),
  'current_page_type' => 
  array (
    'group' => '主题 / 结构',
    'tag' => '全局, 条件判断, 页面类型',
    'title' => '当前页面类型 current_page_type',
    'intro' => '返回当前页面类型标识字符串（home / single / category / tag / search / 404 等）。',
    'usage' => 
    array (
      0 => '<body class="page-{{todo:current_page_type}}">',
    ),
    'related' => 
    array (
      0 => 'current_page_title',
    ),
  ),
  'custom_field' => 
  array (
    'group' => '文章 / 自定义字段',
    'tag' => '详情页, 列表页, 文章详情页, 自定义字段输出',
    'title' => '自定义字段 custom_field',
    'intro' => '读取当前文章的自定义字段值，支持多种字段类型（文本、图片、选项等）。',
    'usage' => 
    array (
      0 => '{{todo:custom_field key="banner"}}',
      1 => '{{todo:custom_field key="price" default="0"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'key',
        'type' => 'string',
        'desc' => '字段键名（必填）',
      ),
      1 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '默认值',
      ),
      2 => 
      array (
        'name' => 'type',
        'type' => 'image|text|url',
        'desc' => '字段类型（影响输出格式）',
      ),
      3 => 
      array (
        'name' => 'size',
        'type' => 'thumbnail|medium|large|full',
        'desc' => '图片尺寸（type=image 时）',
      ),
      4 => 
      array (
        'name' => 'escape',
        'type' => 'html|attr|none',
        'desc' => '转义方式',
      ),
    ),
    'examples' => 
    array (
      0 => '<img src="{{todo:custom_field key=\\"banner\\" type=\\"image\\"}}" alt="">',
      1 => '<span>价格：{{todo:custom_field key=\\"price\\" default=\\"面议\\"}}</span>',
    ),
    'related' => 
    array (
      0 => 'model_field',
      1 => 'term_attribute',
    ),
  ),
  'model_field' => 
  array (
    'group' => '文章 / 自定义字段',
    'tag' => '详情页, 列表页, 文章详情页, 数据模型字段',
    'title' => '模型字段 model_field',
    'intro' => 'custom_field 的别名，语义更贴近「模型自定义字段」场景，用法完全相同。',
    'usage' => 
    array (
      0 => '{{todo:model_field key="spec"}}',
    ),
    'related' => 
    array (
      0 => 'custom_field',
    ),
  ),
  'global_var' => 
  array (
    'group' => '站点 / 全局变量',
    'tag' => '全局, 全局变量, 站点配置',
    'title' => '全局变量 global_var',
    'intro' => '输出后台「全局变量」管理中定义的自定义变量值，可在任意页面调用。',
    'usage' => 
    array (
      0 => '{{todo:global_var name="lei_zi"}}',
      1 => '{{todo:global_var name="phone" default="暂无"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '全局变量的字段标识（必填）',
      ),
      1 => 
      array (
        'name' => 'key',
        'type' => 'string',
        'desc' => 'name 的别名',
      ),
      2 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '变量不存在时的默认值',
      ),
    ),
    'examples' => 
    array (
      0 => '<span>电话：{{todo:global_var name=\\"phone\\" default=\\"暂无\\"}}</span>',
      1 => '<p>{{todo:global_var name=\\"site_notice\\"}}</p>',
    ),
  ),
  'search_query' => 
  array (
    'group' => '搜索 / 查询',
    'tag' => '搜索页, 当前搜索关键词',
    'title' => '搜索关键词 search_query',
    'intro' => '返回当前搜索请求的关键词（已转义）。',
    'usage' => 
    array (
      0 => '<h1>搜索：{{todo:search_query}}</h1>',
    ),
    'related' => 
    array (
      0 => 'found_posts',
      1 => 'search_form',
    ),
  ),
  'found_posts' => 
  array (
    'group' => '搜索 / 查询',
    'tag' => '搜索页, 列表页, 搜索结果总数',
    'title' => '搜索结果数 found_posts',
    'intro' => '返回当前搜索命中的总文章数。',
    'usage' => 
    array (
      0 => '<p>共找到 {{todo:found_posts}} 条结果</p>',
    ),
    'related' => 
    array (
      0 => 'search_query',
    ),
  ),
  'search_form' => 
  array (
    'group' => '搜索 / 查询',
    'tag' => '全局, 首页, 搜索框表单',
    'title' => '搜索表单 search_form',
    'intro' => '输出站点搜索表单 HTML。',
    'usage' => 
    array (
      0 => '{{todo:search_form}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'placeholder',
        'type' => 'string',
        'desc' => '输入框占位文字',
      ),
      1 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '表单 CSS class',
      ),
    ),
    'related' => 
    array (
      0 => 'search_query',
    ),
  ),
  'current_user_name' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 用户中心, 当前用户名',
    'title' => '当前用户名 current_user_name',
    'intro' => '返回当前已登录用户的用户名（未登录返回空）。',
    'usage' => 
    array (
      0 => '<span>你好，{{todo:current_user_name}}</span>',
    ),
    'related' => 
    array (
      0 => 'if_user_logged_in',
    ),
  ),
  'user_center_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 页头, 用户中心首页链接',
    'title' => '用户中心地址 user_center_url',
    'intro' => '返回用户中心首页 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:user_center_url}}">个人中心</a>',
    ),
    'related' => 
    array (
      0 => 'login_url',
      1 => 'logout_url',
    ),
  ),
  'user_page_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 子页面跳转链接',
    'title' => '用户子页面地址 user_page_url',
    'intro' => '返回用户中心指定子页面的 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:user_page_url page=\\"orders\\"}}">我的订单</a>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'type' => 'string',
        'desc' => '子页面标识（orders / favorites / address 等）',
      ),
    ),
    'related' => 
    array (
      0 => 'user_center_url',
    ),
  ),
  'user_action_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 表单提交操作地址',
    'title' => '用户操作地址 user_action_url',
    'intro' => '返回用户特定操作的 AJAX/表单提交 URL（含 nonce 验证）。',
    'usage' => 
    array (
      0 => '<form action="{{todo:user_action_url action=\\"update_profile\\"}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'action',
        'type' => 'string',
        'desc' => '操作标识',
      ),
    ),
    'related' => 
    array (
      0 => 'csrf_token',
    ),
  ),
  'single_ads' => 
  array (
    'group' => '广告 / 推广',
    'tag' => '全局, 首页, 详情页, 单个广告位',
    'title' => '单个广告位 single_ads',
    'intro' => '输出指定广告位的单条广告 HTML。',
    'usage' => 
    array (
      0 => '{{todo:single_ads slot="banner_top"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'slot',
        'type' => 'string',
        'desc' => '广告位标识（必填）',
      ),
    ),
    'related' => 
    array (
      0 => 'group_ads',
    ),
  ),
  'group_ads' => 
  array (
    'group' => '广告 / 推广',
    'tag' => '全局, 首页, 广告分组, 轮播图',
    'title' => '广告组 group_ads',
    'is_block' => true,
    'intro' => '循环输出指定广告位的多条广告。',
    'usage' => 
    array (
      0 => '{{todo:group_ads slot="sidebar"}}
  <a href="{{todo:ad_url}}"><img src="{{todo:ad_img}}"></a>
{{/todo:group_ads}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'slot',
        'type' => 'string',
        'desc' => '广告位标识',
      ),
      1 => 
      array (
        'name' => 'limit',
        'type' => 'number',
        'desc' => '最多显示条数',
      ),
    ),
    'related' => 
    array (
      0 => 'single_ads',
    ),
  ),
  'if_debug' => 
  array (
    'group' => '调试 / 开发',
    'tag' => '全局, 开发调试, 调试模式判断',
    'is_block' => true,
    'title' => '调试模式条件 if_debug',
    'intro' => '仅在开启调试模式（TODO_DEBUG=true）时渲染内容，线上自动隐藏。',
    'usage' => 
    array (
      0 => '{{todo:if_debug}}
  {{todo:dump}}
{{/todo:if_debug}}',
    ),
    'related' => 
    array (
      0 => 'dump',
      1 => 'query_count',
    ),
  ),
  'current_template' => 
  array (
    'group' => '调试 / 开发',
    'tag' => '全局, 开发调试, 当前模板文件名',
    'title' => '当前模板路径 current_template',
    'intro' => '返回当前渲染的模板文件路径，用于主题开发调试。',
    'usage' => 
    array (
      0 => '<!-- template: {{todo:current_template}} -->',
    ),
    'related' => 
    array (
      0 => 'if_debug',
    ),
  ),
  'query_count' => 
  array (
    'group' => '调试 / 开发',
    'tag' => '全局, 开发调试, SQL查询次数',
    'title' => '数据库查询次数 query_count',
    'intro' => '返回本次请求执行的数据库查询总次数，用于性能分析。',
    'usage' => 
    array (
      0 => '<!-- queries: {{todo:query_count}} -->',
    ),
    'related' => 
    array (
      0 => 'if_debug',
    ),
  ),
  'dump' => 
  array (
    'group' => '调试 / 开发',
    'tag' => '全局, 开发调试, 变量打印转储',
    'title' => '变量转储 dump',
    'intro' => '输出当前模板上下文所有变量的 var_dump，仅调试模式下可见。',
    'usage' => 
    array (
      0 => '{{todo:dump}}',
    ),
    'notes' => 
    array (
      0 => '生产环境请勿暴露此标签，建议配合 {{todo:if_debug}} 使用。',
    ),
    'related' => 
    array (
      0 => 'if_debug',
      1 => 'query_count',
    ),
  ),
  'user.name' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 用户名, 会员信息',
    'title' => '用户名 user.name',
    'intro' => '返回当前登录用户的登录用户名。',
    'usage' => 
    array (
      0 => '{{todo:user.name}}',
    ),
    'related' => 
    array (
      0 => 'user.display_name',
    ),
  ),
  'user.display_name' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 昵称, 会员信息',
    'title' => '用户昵称 user.display_name',
    'intro' => '返回当前登录用户的昵称（显示名）。',
    'usage' => 
    array (
      0 => '<span>{{todo:user.display_name}}</span>',
    ),
    'related' => 
    array (
      0 => 'user.name',
      1 => 'user.avatar',
    ),
  ),
  'user.email' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 绑定邮箱, 会员信息',
    'title' => '用户邮箱 user.email',
    'intro' => '返回当前登录用户的邮箱地址。',
    'usage' => 
    array (
      0 => '{{todo:user.email}}',
    ),
  ),
  'user.avatar' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 用户头像, 会员信息',
    'title' => '用户头像 user.avatar',
    'intro' => '返回当前登录用户头像 URL 或输出图片标签。',
    'usage' => 
    array (
      0 => '&lt;img src=&quot;{{todo:user.avatar}}&quot; class=&quot;avatar&quot;&gt;',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'number',
        'desc' => '头像尺寸 px（默认 48）',
      ),
      1 => 
      array (
        'name' => 'format',
        'type' => 'url|html',
        'desc' => '输出格式（默认 url）',
      ),
    ),
    'related' => 
    array (
      0 => 'user.display_name',
    ),
  ),
  'user.balance' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 钱包余额, 资金账户',
    'title' => '用户余额 user.balance',
    'intro' => '返回当前登录用户的账户余额（格式化金额）。',
    'usage' => 
    array (
      0 => '<span>余额：{{todo:currency_code}}{{todo:user.balance}}</span>',
    ),
    'related' => 
    array (
      0 => 'user.points',
    ),
  ),
  'user.points' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 用户积分, 会员资产',
    'title' => '用户积分 user.points',
    'intro' => '返回当前登录用户的积分数量。',
    'usage' => 
    array (
      0 => '<span>积分：{{todo:user.points}}</span>',
    ),
    'related' => 
    array (
      0 => 'user.balance',
    ),
  ),
  'user.level' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 会员等级ID',
    'title' => '用户等级 user.level',
    'intro' => '返回当前登录用户的会员等级数字。',
    'usage' => 
    array (
      0 => '{{todo:user.level}}',
    ),
    'related' => 
    array (
      0 => 'user.level_name',
    ),
  ),
  'user.level_name' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 会员等级名称',
    'title' => '用户等级名 user.level_name',
    'intro' => '返回当前登录用户的会员等级名称。',
    'usage' => 
    array (
      0 => '<span class="badge">{{todo:user.level_name}}</span>',
    ),
    'related' => 
    array (
      0 => 'user.level',
    ),
  ),
  'user.last_login_time' => 
  array (
    'group' => '用户中心 / 信息',
    'tag' => '用户中心, 最近登录时间',
    'title' => '最近登录时间 user.last_login_time',
    'intro' => '返回当前用户最后一次登录时间。',
    'usage' => 
    array (
      0 => '<span>上次登录：{{todo:user.last_login_time}}</span>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => 'PHP date() 格式，默认 Y-m-d H:i',
      ),
    ),
  ),
  'user_orders' => 
  array (
    'group' => '用户中心 / 订单',
    'tag' => '用户中心, 订单列表循环, 电商交易',
    'is_block' => true,
    'title' => '用户订单循环 user_orders',
    'intro' => '循环输出当前用户的订单列表。',
    'usage' => 
    array (
      0 => '{{todo:user_orders per_page="10"}}
  <div>{{todo:order_no}} - {{todo:order_status}}</div>
{{/todo:user_orders}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'per_page',
        'type' => 'number',
        'desc' => '每页数量',
      ),
      1 => 
      array (
        'name' => 'status',
        'type' => 'pending|paid|completed|all',
        'desc' => '订单状态筛选',
      ),
    ),
    'related' => 
    array (
      0 => 'user_orders_count',
    ),
  ),
  'user_orders_count' => 
  array (
    'group' => '用户中心 / 订单',
    'tag' => '用户中心, 订单数量统计',
    'title' => '订单总数 user_orders_count',
    'intro' => '返回当前用户订单总数。',
    'usage' => 
    array (
      0 => '<span>{{todo:user_orders_count}}</span>',
    ),
    'related' => 
    array (
      0 => 'user_orders',
    ),
  ),
  'user_cart_count' => 
  array (
    'group' => '用户中心 / 购物车',
    'tag' => '全局, 页头, 购物车数量, 商城',
    'title' => '购物车数量 user_cart_count',
    'intro' => '返回当前用户购物车中的商品总件数。',
    'usage' => 
    array (
      0 => '<span class="cart-badge">{{todo:user_cart_count}}</span>',
    ),
    'related' => 
    array (
      0 => 'user_cart_total',
    ),
  ),
  'user_cart_total' => 
  array (
    'group' => '用户中心 / 购物车',
    'tag' => '全局, 页头, 购物车总额, 商城',
    'title' => '购物车金额 user_cart_total',
    'intro' => '返回当前用户购物车的合计金额（格式化）。',
    'usage' => 
    array (
      0 => '<span>合计：{{todo:currency_code}}{{todo:user_cart_total}}</span>',
    ),
    'related' => 
    array (
      0 => 'user_cart_count',
    ),
  ),
  'user_favorites_count' => 
  array (
    'group' => '用户中心 / 收藏',
    'tag' => '用户中心, 我的收藏数量',
    'title' => '收藏数量 user_favorites_count',
    'intro' => '返回当前用户收藏的内容总数。',
    'usage' => 
    array (
      0 => '<span>{{todo:user_favorites_count}} 件收藏</span>',
    ),
  ),
  'user_coupon_count' => 
  array (
    'group' => '用户中心 / 优惠券',
    'tag' => '用户中心, 优惠券数量',
    'title' => '优惠券数量 user_coupon_count',
    'intro' => '返回当前用户持有的有效优惠券数量。',
    'usage' => 
    array (
      0 => '<span>{{todo:user_coupon_count}} 张优惠券</span>',
    ),
  ),
  'user_wallet_balance' => 
  array (
    'group' => '用户中心 / 钱包',
    'tag' => '用户中心, 钱包可用余额',
    'title' => '钱包余额 user_wallet_balance',
    'intro' => '返回当前用户的钱包余额。与 user.balance 等价，语义更明确。',
    'usage' => 
    array (
      0 => '<span>钱包：{{todo:currency_code}}{{todo:user_wallet_balance}}</span>',
    ),
    'related' => 
    array (
      0 => 'user.balance',
    ),
  ),
  'user_address_count' => 
  array (
    'group' => '用户中心 / 地址',
    'tag' => '用户中心, 收货地址数量',
    'title' => '地址数量 user_address_count',
    'intro' => '返回当前用户已保存的收货地址数量。',
    'usage' => 
    array (
      0 => '<span>{{todo:user_address_count}} 个地址</span>',
    ),
  ),
  'user_url' => 
  array (
    'group' => '用户中心 / 链接',
    'tag' => '全局, 用户中心主页链接',
    'title' => '用户中心首页 user_url',
    'intro' => '返回用户中心首页 URL（与 user_center_url 等价）。',
    'usage' => 
    array (
      0 => '<a href="{{todo:user_url}}">个人中心</a>',
    ),
    'related' => 
    array (
      0 => 'user_center_url',
    ),
  ),
  'shop_url' => 
  array (
    'group' => '用户中心 / 链接',
    'tag' => '全局, 商城首页链接',
    'title' => '商城首页地址 shop_url',
    'intro' => '返回商城首页 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:shop_url}}">去购物</a>',
    ),
  ),
  'is_user_page' => 
  array (
    'group' => '用户中心 / 链接',
    'tag' => '全局, 用户中心, 页面类型判断',
    'title' => '是否用户中心页 is_user_page',
    'intro' => '返回布尔值，指示当前页面是否为用户中心页面，可配合 class_if 使用。',
    'usage' => 
    array (
      0 => '<body class="{{todo:class_if condition=\\"is_user_page\\" value=\\"user-center\\"}}">',
    ),
    'related' => 
    array (
      0 => 'class_if',
      1 => 'user_center_url',
    ),
  ),
  'todo_head' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 头部, SEO, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '主题头部核心钩子 todo_head',
    'intro' => 'TodoCMS 主题标准头部注入标签（相当于 wp_head）。自动触发 todo_enqueue_scripts 与 todo_head 钩子，统一输出排队的 CSS 样式表、头部 JS 脚本及多语言/SEO 元信息。应放置在模板 <head> 闭合前。',
    'usage' => 
    array (
      0 => '{{todo:todo_head}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'indent',
        'type' => 'string|int',
        'desc' => '可选。输出 HTML 每行的前置缩进空格数（默认 4）或自定义前缀字符串。',
      ),
    ),
    'examples' => 
    array (
      0 => '<head>
  <meta charset="UTF-8">
  <title>{{todo:meta_title}}</title>
  {{todo:todo_head}}
</head>',
    ),
    'notes' => 
    array (
      0 => '所有现代化主题必须包含此标签，插件及系统基础脚本（如评论验证、多语言字典）依赖此钩子正常加载。',
    ),
    'related' => 
    array (
      0 => 'todo_body_open',
      1 => 'todo_footer',
      2 => 'meta_title',
    ),
  ),
  'todo_body_open' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '页面 Body 开始钩子 todo_body_open',
    'intro' => '紧跟在 <body> 开始标签之后输出的动作钩子。常用于系统无障碍跳跃链接、全站遮罩层或第三方全局分析追踪代码注入。',
    'usage' => 
    array (
      0 => '{{todo:todo_body_open}}',
    ),
    'examples' => 
    array (
      0 => '<body class="{{todo:body_class}}">
  {{todo:todo_body_open}}
  <header>...</header>',
    ),
    'notes' => 
    array (
      0 => '标准 HTML 规范推荐紧随 <body> 开口放置。',
    ),
    'related' => 
    array (
      0 => 'todo_head',
      1 => 'todo_footer',
      2 => 'body_class',
    ),
  ),
  'todo_footer' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 页脚, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '主题页脚核心钩子 todo_footer',
    'intro' => 'TodoCMS 主题标准底部注入标签（相当于 wp_footer）。自动触发 todo_footer 钩子，并在 </body> 闭合前统一输出排队的延迟 JavaScript 脚本、全局统计代码与插件挂载脚本。',
    'usage' => 
    array (
      0 => '{{todo:todo_footer}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'indent',
        'type' => 'string|int',
        'desc' => '可选。输出 HTML 的每行前置缩进量。',
      ),
    ),
    'examples' => 
    array (
      0 => '<footer>...</footer>
{{todo:todo_footer}}
</body>',
    ),
    'notes' => 
    array (
      0 => '必须放置在 </body> 闭合标签之前，确保页面 DOM 元素加载就绪后再执行关联脚本。',
    ),
    'related' => 
    array (
      0 => 'todo_head',
      1 => 'todo_body_open',
    ),
  ),
  'body_class' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 样式, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '页面 Body 类名 body_class',
    'intro' => '根据当前所处页面类型智能输出一组 CSS class 类名（如 home, single-post, category-news, page-id-5, logged-in 等），便于进行差异化样式排版控制。',
    'usage' => 
    array (
      0 => '<body class="{{todo:body_class}}">',
      1 => '<body class="{{todo:body_class class="custom-theme-dark"}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '可选。额外追加的自定义 CSS 类名，用空格分隔。',
      ),
    ),
    'notes' => 
    array (
      0 => '无需手动拼写复杂的页面判断，系统会自动识别多语言与页面类型上下文。',
    ),
    'related' => 
    array (
      0 => 'todo_body_open',
    ),
  ),
  'site_favicon' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 头部, 首页, 详情页',
    'is_block' => false,
    'title' => '站点 Favicon 图标 site_favicon',
    'intro' => '获取网站后台设置的浏览器 Favicon 标签图标 URL。支持绝对 URL 自动转换及兜底图片配置。',
    'usage' => 
    array (
      0 => '<link rel="shortcut icon" href="{{todo:site_favicon}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。后台未上传时的兜底图标 URL（默认指向 /favicon.ico）。',
      ),
    ),
    'related' => 
    array (
      0 => 'site_logo',
      1 => 'site_title',
    ),
  ),
  'the_custom_logo' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 头部, 首页, 导航',
    'is_block' => false,
    'title' => '站点自定义 Logo 标签 the_custom_logo',
    'intro' => '输出包含首页跳转链接 <a> 及后台上传的 Logo <img> 完整 HTML 结构。自动带有图片 alt 属性（站点标题）及高分辨率 srcset 适配。',
    'usage' => 
    array (
      0 => '{{todo:the_custom_logo}}',
      1 => '{{todo:the_custom_logo class="brand-logo" link_class="brand-link"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '可选。附加给 <img> 图片标签的 CSS 类名。',
      ),
      1 => 
      array (
        'name' => 'link_class',
        'type' => 'string',
        'desc' => '可选。附加给 <a> 链接外层的 CSS 类名。',
      ),
    ),
    'related' => 
    array (
      0 => 'has_custom_logo',
      1 => 'site_logo',
    ),
  ),
  'has_custom_logo' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 条件判断, 头部, 首页',
    'is_block' => false,
    'title' => '是否有自定义 Logo has_custom_logo',
    'intro' => '判断后台是否设置了网站 Logo。可用于模板中条件分支判断，如果无 Logo 则回退显示文本站点标题。',
    'usage' => 
    array (
      0 => '{{todo:if has_custom_logo}}
  {{todo:the_custom_logo}}
{{todo:else}}
  <h1>{{todo:site_title}}</h1>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'the_custom_logo',
      1 => 'site_logo',
    ),
  ),
  'cookie_table' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 隐私合规, 详情页',
    'is_block' => false,
    'title' => 'Cookie 政策声明表格 cookie_table',
    'intro' => '输出当前站点使用的 Cookie 清单及用途分类说明表格 HTML（符合 GDPR/个人信息合规规范）。',
    'usage' => 
    array (
      0 => '{{todo:cookie_table}}',
    ),
    'related' => 
    array (
      0 => 'site_copyright',
    ),
  ),
  'unified_notice' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 首页, 详情页',
    'is_block' => false,
    'title' => '系统统一提示通知 unified_notice',
    'intro' => '输出系统后台配置的全站重要通知、停机维护弹窗或全局公告 HTML 组件。',
    'usage' => 
    array (
      0 => '{{todo:unified_notice}}',
    ),
    'related' => 
    array (
      0 => 'show_ads',
    ),
  ),
  'show_ads' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 广告, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '广告位投放渲染 show_ads',
    'intro' => '根据后台广告管理中设置的广告位代码或广告ID，动态渲染已启用的横幅广告、图文广告或自定义广告代码。',
    'usage' => 
    array (
      0 => '{{todo:show_ads position="header_banner"}}',
      1 => '{{todo:show_ads id="1"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'position',
        'type' => 'string',
        'desc' => '可选。后台定义的广告位标识（英文别名）。',
      ),
      1 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定具体广告编号。',
      ),
    ),
    'related' => 
    array (
      0 => 'unified_notice',
    ),
  ),
  'option' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 首页, 列表页, 详情页',
    'is_block' => false,
    'title' => '万能后台配置项 option',
    'intro' => '获取网站后台 options 表中的任意配置键值。具备多语言感知能力，自动提取当前语言环境对应的翻译值。',
    'usage' => 
    array (
      0 => '{{todo:option name="contact_phone"}}',
      1 => '{{todo:option name="service_email" default="support@example.com"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '必填。配置项名称（如 site_address, hotline 等）。',
      ),
      1 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。未配置时的默认兜底内容。',
      ),
    ),
    'related' => 
    array (
      0 => 'if_option',
      1 => 'site_title',
    ),
  ),
  'if_option' => 
  array (
    'group' => '全局配置',
    'tag' => '全局, 条件判断, 首页, 详情页',
    'is_block' => false,
    'title' => '判断配置项开启 if_option',
    'intro' => '判断系统某项配置是否已开启（值为 \'1\'、\'true\'、\'yes\' 或非空），常用于功能开关判断。',
    'usage' => 
    array (
      0 => '{{todo:if if_option name="enable_online_service"}}
  <div class="live-chat">...</div>
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '必填。被检测的配置项键名。',
      ),
      1 => 
      array (
        'name' => 'value',
        'type' => 'string',
        'desc' => '可选。匹配的特定值，未传则仅检测非空开启。',
      ),
    ),
    'related' => 
    array (
      0 => 'option',
    ),
  ),
  'site_switcher' => 
  array (
    'group' => '多语言与国际化',
    'tag' => '全局, 多语言, 头部, 页脚',
    'is_block' => false,
    'title' => '多语言站点切换器 site_switcher',
    'intro' => '输出已配置的所有语言/多站点即时切换器组件（支持下拉选框、国旗图标、无刷新跳转菜单）。',
    'usage' => 
    array (
      0 => '{{todo:site_switcher}}',
      1 => '{{todo:site_switcher type="dropdown" class="lang-select"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'type' => 'string',
        'desc' => '可选。展示形式：dropdown（下拉菜单）、flags（国旗横排）、select（原生下拉框）。默认 dropdown。',
      ),
      1 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '可选。外层包裹容器的 CSS 类名。',
      ),
    ),
    'related' => 
    array (
      0 => 'current_language',
      1 => 'translate_text',
    ),
  ),
  'current_language' => 
  array (
    'group' => '多语言与国际化',
    'tag' => '全局, 多语言, 头部',
    'is_block' => false,
    'title' => '当前语言代码 current_language',
    'intro' => '输出当前访客正在浏览的有效语言代号（如 zh-CN, en-US, ja-JP 等），常用于 <html lang="..."> 或前端 JS 国际化环境标记。',
    'usage' => 
    array (
      0 => '<html lang="{{todo:current_language}}">',
    ),
    'related' => 
    array (
      0 => 'site_switcher',
      1 => 'translate_text',
    ),
  ),
  'translate_text' => 
  array (
    'group' => '多语言与国际化',
    'tag' => '全局, 多语言, 详情页, 列表页',
    'is_block' => false,
    'title' => '行内文本翻译 translate_text',
    'intro' => '对简短模板静态文本执行即时多语言翻译，支持 escape 转义保护与上下文词典归属。',
    'usage' => 
    array (
      0 => '{{todo:translate_text text="阅读更多"}}',
      1 => '{{todo:translate_text text="Submit" context="form" escape="attr"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'text',
        'type' => 'string',
        'desc' => '必填。待翻译的词句原文。',
      ),
      1 => 
      array (
        'name' => 'context',
        'type' => 'string',
        'desc' => '可选。翻译语境上下文（如 theme, form, product）。',
      ),
      2 => 
      array (
        'name' => 'escape',
        'type' => 'string',
        'desc' => '可选。转义模式：html（默认）、attr（用于属性值引号安全）或 none。',
      ),
    ),
    'related' => 
    array (
      0 => 'lang_pack_json',
      1 => 'current_language',
    ),
  ),
  'lang_pack_json' => 
  array (
    'group' => '多语言与国际化',
    'tag' => '全局, 多语言, 页脚',
    'is_block' => false,
    'title' => '语言翻译包 JSON lang_pack_json',
    'intro' => '将当前语言环境下注册的前端国际化词典导出为 JSON 对象字符串，供前端 JavaScript 脚本无缝对接翻译。',
    'usage' => 
    array (
      0 => '<script>window.TODO_I18N = {{todo:lang_pack_json}};</script>',
    ),
    'related' => 
    array (
      0 => 'translate_text',
    ),
  ),
  'anywhere_post' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '全局, 详情页, 列表页, 首页',
    'is_block' => false,
    'title' => '指定文章任意调用 anywhere_post',
    'intro' => '万能跨栏目调用标签。通过指定文章 ID 调取该文章的标题、摘要、缩略图、URL、日期、正文或自定义字段数据，无需编写复杂的查询循环。',
    'usage' => 
    array (
      0 => '{{todo:anywhere_post id="12" field="title"}}',
      1 => '{{todo:anywhere_post id="12" field="thumbnail_url"}}',
      2 => '{{todo:anywhere_post id="12" field="excerpt" length="80"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '必填。目标文章/产品的主键 ID（也可用 post_id）。',
      ),
      1 => 
      array (
        'name' => 'field',
        'type' => 'string',
        'desc' => '可选。读取的字段：title（标题）、excerpt/summary（摘要）、link/url/permalink（链接）、thumbnail/image（缩略图HTML）、thumbnail_url/image_url（缩略图地址）、date（日期）、content（正文）、json（完整JSON对象）或模型自定义字段名。默认 content。',
      ),
      2 => 
      array (
        'name' => 'length',
        'type' => 'int',
        'desc' => '可选。当 field 为 excerpt 或 title 时截取的字符数，默认 150。',
      ),
      3 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => '可选。当 field 为 date 时的日期格式（如 Y-m-d），默认后台全局设置。',
      ),
    ),
    'examples' => 
    array (
      0 => '<h3>{{todo:anywhere_post id="88" field="title"}}</h3>
<img src="{{todo:anywhere_post id="88" field="thumbnail_url"}}">
<p>{{todo:anywhere_post id="88" field="excerpt" length="60"}}</p>',
    ),
    'notes' => 
    array (
      0 => '极为适合首页推荐位、指定企业简介单篇、页脚指定联系我们文章等跨版块调取场景。',
    ),
    'related' => 
    array (
      0 => 'post_title',
      1 => 'post_thumbnail_url',
      2 => 'post_excerpt',
    ),
  ),
  'post_category' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章列表页, 列表页',
    'is_block' => false,
    'title' => '文章所属主分类名称 post_category',
    'intro' => '输出当前文章（或指定 ID 文章）所属的主分类名称文本。',
    'usage' => 
    array (
      0 => '{{todo:post_category}}',
      1 => '{{todo:post_category id="12"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。文章 ID，未指定则取当前详情页或循环文章。',
      ),
      1 => 
      array (
        'name' => 'taxonomy',
        'type' => 'string',
        'desc' => '可选。分类法体系，默认为 "category"。',
      ),
    ),
    'related' => 
    array (
      0 => 'post_category_url',
      1 => 'post_categories_links',
      2 => 'category_name',
    ),
  ),
  'post_category_url' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章列表页, 列表页',
    'is_block' => false,
    'title' => '文章所属主分类链接 post_category_url',
    'intro' => '输出当前文章所属主分类的链接访问 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:post_category_url}}">{{todo:post_category}}</a>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章 ID。',
      ),
      1 => 
      array (
        'name' => 'taxonomy',
        'type' => 'string',
        'desc' => '可选。分类法体系。',
      ),
    ),
    'related' => 
    array (
      0 => 'post_category',
      1 => 'post_categories_links',
    ),
  ),
  'post_categories_links' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章列表页, 列表页',
    'is_block' => false,
    'title' => '文章多分类带链接列表 post_categories_links',
    'intro' => '输出当前文章关联的所有分类带有超链接的 HTML 列表，多分类之间自动使用指定分隔符连接。',
    'usage' => 
    array (
      0 => '{{todo:post_categories_links}}',
      1 => '{{todo:post_categories_links separator=" / "}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章 ID。',
      ),
      1 => 
      array (
        'name' => 'separator',
        'type' => 'string',
        'desc' => '可选。分类之间的连接符，默认为 ", "。',
      ),
      2 => 
      array (
        'name' => 'taxonomy',
        'type' => 'string',
        'desc' => '可选。分类法体系。',
      ),
    ),
    'related' => 
    array (
      0 => 'post_category',
      1 => 'post_tags',
    ),
  ),
  'post_views' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章列表页, 列表页',
    'is_block' => false,
    'title' => '文章浏览阅读量 post_views',
    'intro' => '输出文章被阅读浏览的总次数（数字）。在详情页调用时通常会自动递增一次计数并记录防刷统计。',
    'usage' => 
    array (
      0 => '{{todo:post_views}} 次浏览',
      1 => '{{todo:post_views id="10"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'post_comment_count',
      1 => 'post_likes',
    ),
  ),
  'post_comment_count' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 文章列表页, 列表页',
    'is_block' => false,
    'title' => '文章评论总数 post_comment_count',
    'intro' => '输出当前文章已通过审核的有效评论数量数字。',
    'usage' => 
    array (
      0 => '{{todo:post_comment_count}} 评',
      1 => '{{todo:post_comment_count id="10"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定文章 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'comments_count',
      1 => 'post_views',
    ),
  ),
  'post_property' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 模型字段',
    'is_block' => false,
    'title' => '文章扩展属性字段 post_property',
    'intro' => '获取当前模型文章绑定的单项扩展属性数据（如规格参数、作者单位、下载地址等）。',
    'usage' => 
    array (
      0 => '{{todo:post_property name="download_url"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'name',
        'type' => 'string',
        'desc' => '必填。属性名标识。',
      ),
      1 => 
      array (
        'name' => 'field',
        'type' => 'string',
        'desc' => '可选。获取属性对象的子字段（如 value, name）。默认 value。',
      ),
    ),
    'related' => 
    array (
      0 => 'post_properties',
      1 => 'model_field',
    ),
  ),
  'post_properties' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 模型字段, 循环遍历',
    'is_block' => true,
    'title' => '文章扩展属性循环 post_properties',
    'intro' => '块循环标签。遍历当前文章绑定的所有自定义扩展属性列表（输出 name、value 等子项）。',
    'usage' => 
    array (
      0 => '{{todo:post_properties}}
  <li>{{name}}: {{value}}</li>
{{/todo:post_properties}}',
    ),
    'related' => 
    array (
      0 => 'post_property',
    ),
  ),
  'category_image' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, 首页, 导航',
    'is_block' => false,
    'title' => '栏目封面大图 category_image',
    'intro' => '获取当前分类栏目（或指定分类 ID）设置的横幅 Banner/封面图完整 URL。支持别名 {{todo:cat_image}}。',
    'usage' => 
    array (
      0 => '<div style="background-image: url(\'{{todo:category_image}}\')"></div>',
      1 => '{{todo:category_image id="5" size="full"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。分类栏目 ID，默认当前列表栏目。',
      ),
      1 => 
      array (
        'name' => 'size',
        'type' => 'string',
        'desc' => '可选。图片尺寸，如 full（原始）、thumb（缩略图），默认 full。',
      ),
      2 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。未上传图片时的默认兜底图片路径。',
      ),
    ),
    'notes' => 
    array (
      0 => '别名 {{todo:cat_image}} 具备完全相同的解析行为。',
    ),
    'related' => 
    array (
      0 => 'category_featured_image',
      1 => 'category_name',
    ),
  ),
  'category_featured_image' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, 首页',
    'is_block' => false,
    'title' => '栏目特色焦点图 category_featured_image',
    'intro' => '获取分类栏目配置的精选特色大图 URL，适用于头部大幅焦点海报。',
    'usage' => 
    array (
      0 => '<img src="{{todo:category_featured_image}}" alt="{{todo:category_name}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定分类 ID。',
      ),
      1 => 
      array (
        'name' => 'size',
        'type' => 'string',
        'desc' => '可选。图片尺寸。',
      ),
      2 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。兜底图片地址。',
      ),
    ),
    'related' => 
    array (
      0 => 'category_image',
    ),
  ),
  'cat_seo_title' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, SEO',
    'is_block' => false,
    'title' => '分类独立 SEO 标题 cat_seo_title',
    'intro' => '输出当前分类栏目后台独立设置的 SEO 优化标题（区别于用于菜单显示的栏目名称）。',
    'usage' => 
    array (
      0 => '<title>{{todo:cat_seo_title}}</title>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定分类 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'cat_seo_keywords',
      1 => 'cat_seo_description',
      2 => 'meta_title',
    ),
  ),
  'cat_seo_keywords' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, SEO',
    'is_block' => false,
    'title' => '分类独立 SEO 关键词 cat_seo_keywords',
    'intro' => '输出当前分类栏目后台填写的 SEO Keywords 关键词串。支持别名 {{todo:category_keywords}}。',
    'usage' => 
    array (
      0 => '<meta name="keywords" content="{{todo:cat_seo_keywords}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定分类 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'cat_seo_title',
      1 => 'cat_seo_description',
    ),
  ),
  'cat_seo_description' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, SEO',
    'is_block' => false,
    'title' => '分类独立 SEO 描述 cat_seo_description',
    'intro' => '输出当前分类栏目后台填写的 SEO Description 摘要文本。',
    'usage' => 
    array (
      0 => '<meta name="description" content="{{todo:cat_seo_description}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定分类 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'cat_seo_title',
      1 => 'cat_seo_keywords',
    ),
  ),
  'cat_is_hidden' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, 导航, 条件判断',
    'is_block' => false,
    'title' => '栏目是否在导航中隐藏 cat_is_hidden',
    'intro' => '判断分类栏目是否设置了“导航中隐藏”（返回 \'1\' 或 \'0\'）。用于自建循环导航时跳过不希望公开在主菜单呈现的内部栏目。',
    'usage' => 
    array (
      0 => '{{todo:if cat_is_hidden == \'0\'}}
  <a href="{{url}}">{{name}}</a>
{{/todo:if}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'type' => 'int',
        'desc' => '可选。指定分类 ID。',
      ),
    ),
    'related' => 
    array (
      0 => 'category_name',
      1 => 'nav',
    ),
  ),
  'super_nav' => 
  array (
    'group' => '分类 / 栏目',
    'tag' => '列表页, 搜索, 导航',
    'is_block' => false,
    'title' => '超级多维聚合筛选导航 super_nav',
    'intro' => 'TodoCMS 独有的超级聚合筛选导航。自动解析当前栏目绑定的子分类、属性特征及标签，一键输出支持高亮选中状态的多条件筛选交互菜单。',
    'usage' => 
    array (
      0 => '{{todo:super_nav}}',
      1 => '{{todo:super_nav category_id="3" active_class="active"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'category_id',
        'type' => 'int',
        'desc' => '可选。指定要聚合的根分类 ID。',
      ),
      1 => 
      array (
        'name' => 'taxonomy',
        'type' => 'string',
        'desc' => '可选。分类法体系，默认 "category"。',
      ),
      2 => 
      array (
        'name' => 'class',
        'type' => 'string',
        'desc' => '可选。外层包裹 div 的 CSS 类名。',
      ),
      3 => 
      array (
        'name' => 'active_class',
        'type' => 'string',
        'desc' => '可选。当前选中筛选条件的激活高亮 CSS 类名，默认 "current-cat"。',
      ),
    ),
    'notes' => 
    array (
      0 => '非常适合商城商品中心、房产汽车列表、招聘筛选等需要多维度过滤的场景。',
    ),
    'related' => 
    array (
      0 => 'categories',
      1 => 'search_url',
    ),
  ),
  'post_specs' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 电商',
    'is_block' => false,
    'title' => '商品多规格配置 JSON post_specs',
    'intro' => '输出商品多规格配置组（如颜色、尺码、版本）的 JSON 字符串。包含规格组名称、多语言值及关联的规格预览小图/色块。已自动完成多语言翻译及路径规范化。',
    'usage' => 
    array (
      0 => '<script>var specConfig = {{todo:post_specs}};</script>',
    ),
    'examples' => 
    array (
      0 => '<div id="app" :data-specs="\'{{todo:post_specs}}\'"></div>',
    ),
    'notes' => 
    array (
      0 => '在商品详情页由前端 Vue/React/原生 JS 直接解析用于渲染多维规格选择器。',
    ),
    'related' => 
    array (
      0 => 'post_spec_details',
      1 => 'post_custom_params',
      2 => 'format_price',
    ),
  ),
  'post_spec_details' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 电商',
    'is_block' => false,
    'title' => '商品规格 SKU 明细 JSON post_spec_details',
    'intro' => '输出多规格商品的全部 SKU 组合数据 JSON。包含每个组合的 SKU ID、划线原价、会员实付价、实际库存、SKU 专用主图及已折算的货币价格。',
    'usage' => 
    array (
      0 => '<script>var skuMap = {{todo:post_spec_details}};</script>',
    ),
    'related' => 
    array (
      0 => 'post_specs',
      1 => 'post_price',
      2 => 'post_stock',
    ),
  ),
  'post_custom_params' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页',
    'is_block' => false,
    'title' => '产品技术参数 JSON post_custom_params',
    'intro' => '输出产品自定义参数组（如功耗、材质、尺寸、重量、产地）的 JSON 字符串，已支持多语言行内翻译解析。',
    'usage' => 
    array (
      0 => '<script>var productParams = {{todo:post_custom_params}};</script>',
    ),
    'related' => 
    array (
      0 => 'post_unit_text',
      1 => 'post_specs',
    ),
  ),
  'post_unit' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 列表页',
    'is_block' => false,
    'title' => '商品计量单位 post_unit',
    'intro' => '输出产品或商品的基本计量单位（如 件, 台, 箱, kg, 套）。',
    'usage' => 
    array (
      0 => '<span>每{{todo:post_unit}}起售</span>',
    ),
    'related' => 
    array (
      0 => 'post_unit_qty',
      1 => 'post_unit_text',
    ),
  ),
  'post_unit_qty' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页',
    'is_block' => false,
    'title' => '商品单位数量 post_unit_qty',
    'intro' => '输出商品单包装包含的单位数量基数（默认 \'1\'）。',
    'usage' => 
    array (
      0 => '起售数量: {{todo:post_unit_qty}}',
    ),
    'related' => 
    array (
      0 => 'post_unit',
      1 => 'post_unit_text',
    ),
  ),
  'post_unit_text' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 列表页',
    'is_block' => false,
    'title' => '商品规格单位文本 post_unit_text',
    'intro' => '输出组合单位数量与单位的友好展示文本（如 "1件", "2台", "500g"）。',
    'usage' => 
    array (
      0 => '<span class="unit">{{todo:post_unit_text}}</span>',
    ),
    'related' => 
    array (
      0 => 'post_unit',
      1 => 'post_unit_qty',
    ),
  ),
  'post_marketing_feature' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页',
    'is_block' => false,
    'title' => '产品营销卖点特色 post_marketing_feature',
    'intro' => '输出商品后台配置的一句话营销特色、核心卖点文案（支持多语言自动翻译）。',
    'usage' => 
    array (
      0 => '<p class="feature-tag">{{todo:post_marketing_feature}}</p>',
    ),
    'related' => 
    array (
      0 => 'post_product_services',
    ),
  ),
  'post_product_services' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页',
    'is_block' => false,
    'title' => '商品保障服务 JSON post_product_services',
    'intro' => '输出商品绑定的服务承诺保障项（如正品保障、顺丰包邮、七天无理由）的 JSON 数组。',
    'usage' => 
    array (
      0 => '<script>var services = {{todo:post_product_services}};</script>',
    ),
    'related' => 
    array (
      0 => 'post_product_services_display',
    ),
  ),
  'post_product_services_display' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页',
    'is_block' => false,
    'title' => '商品保障展示配置 post_product_services_display',
    'intro' => '输出包含服务标识 code、多语言 label 及图标 URL 的完整服务展示 JSON。',
    'usage' => 
    array (
      0 => '<div data-services=\'{{todo:post_product_services_display}}\'></div>',
    ),
    'related' => 
    array (
      0 => 'post_product_services',
    ),
  ),
  'post_is_on_sale' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 列表页, 条件判断',
    'is_block' => false,
    'title' => '商品是否在售 post_is_on_sale',
    'intro' => '判断当前商品是否处于在架在售状态（返回 \'1\' 或 \'0\'）。常用于下架商品禁用购买按钮。',
    'usage' => 
    array (
      0 => '{{todo:if post_is_on_sale == \'1\'}}
  <button class="btn-buy">立即购买</button>
{{todo:else}}
  <button class="btn-disabled" disabled>已下架</button>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'post_sale_status',
      1 => 'post_show_stock',
    ),
  ),
  'post_sale_status' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 列表页',
    'is_block' => false,
    'title' => '商品销售状态 post_sale_status',
    'intro' => '输出商品销售状态代码：online（正常上架在售）或 warehouse（仓库下架备货中）。',
    'usage' => 
    array (
      0 => '<span class="status-{{todo:post_sale_status}}">{{todo:post_sale_status}}</span>',
    ),
    'related' => 
    array (
      0 => 'post_is_on_sale',
    ),
  ),
  'post_show_stock' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 详情页, 条件判断',
    'is_block' => false,
    'title' => '是否显示剩余库存 post_show_stock',
    'intro' => '根据后台商品设置判断是否允许前端向用户展示剩余库存具体数字（返回 \'1\' 或 \'0\'）。',
    'usage' => 
    array (
      0 => '{{todo:if post_show_stock == \'1\'}}
  <span>仅剩 {{todo:post_stock}} 件</span>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'post_stock',
    ),
  ),
  'currency_code' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 全局',
    'is_block' => false,
    'title' => '当前国际货币代码 currency_code',
    'intro' => '输出当前站点当前语言下生效的货币代码（如 CNY, USD, EUR, JPY, GBP）。',
    'usage' => 
    array (
      0 => '<span>结算货币: {{todo:currency_code}}</span>',
    ),
    'related' => 
    array (
      0 => 'currency_rate',
      1 => 'format_price',
      2 => 'post_price',
    ),
  ),
  'currency_rate' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 全局',
    'is_block' => false,
    'title' => '当前货币对基准汇率 currency_rate',
    'intro' => '输出当前多语言货币对系统基准货币的汇率比例数值（如 1.0, 7.24 等）。',
    'usage' => 
    array (
      0 => '<span data-rate="{{todo:currency_rate}}"></span>',
    ),
    'related' => 
    array (
      0 => 'currency_code',
      1 => 'format_price',
    ),
  ),
  'format_price' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 全局, 详情页',
    'is_block' => false,
    'title' => '通用金额价格格式化 format_price',
    'intro' => '将传入的任意浮点数字格式化为符合后台货币规范的金额文本（自动应用符号、千分位及小数位排版）。',
    'usage' => 
    array (
      0 => '{{todo:format_price price="199.5"}}',
      1 => '{{todo:format_price price="199.5" show_symbol="0"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'price',
        'type' => 'float|string',
        'desc' => '必填。待格式化的纯数字金额。',
      ),
      1 => 
      array (
        'name' => 'currency',
        'type' => 'string',
        'desc' => '可选。指定目标货币代码，默认当前激活货币。',
      ),
      2 => 
      array (
        'name' => 'show_symbol',
        'type' => 'bool',
        'desc' => '可选。是否显示货币符号前缀（如 ￥, $），默认 true。',
      ),
    ),
    'related' => 
    array (
      0 => 'currency_code',
      1 => 'post_price',
    ),
  ),
  'cart_count' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 全局, 导航',
    'is_block' => false,
    'title' => '购物车商品数量 cart_count',
    'intro' => '输出当前访问者/会员购物车内的商品总件数。广泛用于网站顶部导航栏的购物车红色角标数字。',
    'usage' => 
    array (
      0 => '<span class="badge cart-badge">{{todo:cart_count}}</span>',
    ),
    'related' => 
    array (
      0 => 'cart_url',
      1 => 'cart_total_format',
    ),
  ),
  'cart_total' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 用户中心',
    'is_block' => false,
    'title' => '购物车总计原值 cart_total',
    'intro' => '输出购物车中全部商品合计的原始未格式化浮点金额数值。',
    'usage' => 
    array (
      0 => '{{todo:cart_total}}',
    ),
    'related' => 
    array (
      0 => 'cart_total_format',
      1 => 'cart_subtotal_format',
    ),
  ),
  'cart_subtotal_format' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 用户中心',
    'is_block' => false,
    'title' => '购物车商品小计金额 cart_subtotal_format',
    'intro' => '输出购物车已选商品小计金额格式化文本（带当前货币符号）。',
    'usage' => 
    array (
      0 => '商品小计: {{todo:cart_subtotal_format}}',
    ),
    'related' => 
    array (
      0 => 'cart_total_format',
      1 => 'cart_shipping_fee_format',
    ),
  ),
  'cart_shipping_fee_format' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 用户中心',
    'is_block' => false,
    'title' => '购物车运费金额 cart_shipping_fee_format',
    'intro' => '输出根据当前收货地址预估的运费格式化文本（满包邮时自动提示）。',
    'usage' => 
    array (
      0 => '运费: {{todo:cart_shipping_fee_format}}',
    ),
    'related' => 
    array (
      0 => 'cart_total_format',
    ),
  ),
  'cart_total_format' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 用户中心',
    'is_block' => false,
    'title' => '购物车应付最终总额 cart_total_format',
    'intro' => '输出购物车最终结算应付总额（含运费并扣除优惠券后的格式化文本）。',
    'usage' => 
    array (
      0 => '应付总额: <strong>{{todo:cart_total_format}}</strong>',
    ),
    'related' => 
    array (
      0 => 'cart_subtotal_format',
      1 => 'checkout_url',
    ),
  ),
  'cart_url' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 全局, 导航',
    'is_block' => false,
    'title' => '购物车页面链接 cart_url',
    'intro' => '获取网站购物车页面的完整访问 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:cart_url}}">我的购物车</a>',
    ),
    'related' => 
    array (
      0 => 'checkout_url',
      1 => 'cart_count',
    ),
  ),
  'checkout_url' => 
  array (
    'group' => '商品 / 电商',
    'tag' => '商品, 电商, 用户中心',
    'is_block' => false,
    'title' => '收银台结算链接 checkout_url',
    'intro' => '获取进入订单确认结算页面的 URL 地址。',
    'usage' => 
    array (
      0 => '<a href="{{todo:checkout_url}}" class="btn-checkout">去结算</a>',
    ),
    'related' => 
    array (
      0 => 'cart_url',
    ),
  ),
  'user_name' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局',
    'is_block' => false,
    'title' => '会员用户名 user_name',
    'intro' => '输出当前登录会员的登录账号名称（用户名）。未登录时返回空。',
    'usage' => 
    array (
      0 => '{{todo:user_name}}',
    ),
    'related' => 
    array (
      0 => 'user_display_name',
      1 => 'user_email',
    ),
  ),
  'user_display_name' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局',
    'is_block' => false,
    'title' => '会员昵称显示名称 user_display_name',
    'intro' => '输出当前登录会员的昵称/显示名称（若未设置昵称则回退显示用户名）。',
    'usage' => 
    array (
      0 => '欢迎回来，{{todo:user_display_name}}！',
    ),
    'related' => 
    array (
      0 => 'user_name',
      1 => 'user_avatar',
    ),
  ),
  'user_email' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '会员绑定邮箱 user_email',
    'intro' => '输出当前登录会员绑定的安全电子邮箱地址。',
    'usage' => 
    array (
      0 => '{{todo:user_email}}',
    ),
    'related' => 
    array (
      0 => 'user_name',
    ),
  ),
  'user_avatar' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局',
    'is_block' => false,
    'title' => '会员头像 URL user_avatar',
    'intro' => '输出当前登录会员的头像图片绝对 URL（自动支持本地上传头像或 Gravatar 兜底）。',
    'usage' => 
    array (
      0 => '<img src="{{todo:user_avatar}}" alt="avatar">',
      1 => '{{todo:user_avatar size="80"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'int',
        'desc' => '可选。头像尺寸像素，默认 96。',
      ),
    ),
    'related' => 
    array (
      0 => 'user_display_name',
    ),
  ),
  'user_balance' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '会员账户余额 user_balance',
    'intro' => '输出会员账户钱包当前的可用现金余额数值（支持格式化）。',
    'usage' => 
    array (
      0 => '钱包余额: ￥{{todo:user_balance}}',
    ),
    'related' => 
    array (
      0 => 'wallet_url',
      1 => 'wallet_balance',
      2 => 'user_points',
    ),
  ),
  'user_points' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '会员积分总额 user_points',
    'intro' => '输出当前会员持有的可用积分总额。',
    'usage' => 
    array (
      0 => '当前积分: {{todo:user_points}} 分',
    ),
    'related' => 
    array (
      0 => 'user_balance',
      1 => 'user_level',
    ),
  ),
  'user_level' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '会员等级名称 user_level',
    'intro' => '输出当前登录会员的会员等级标识或名称（如 VIP1, 黄金会员）。',
    'usage' => 
    array (
      0 => '<span class="badge-vip">{{todo:user_level}}</span>',
    ),
    'related' => 
    array (
      0 => 'user_points',
    ),
  ),
  'user_order_count' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 电商',
    'is_block' => false,
    'title' => '会员订单数量统计 user_order_count',
    'intro' => '输出当前登录会员的订单总数，支持根据不同状态参数筛选统计（常用于会员中心待付款、待发货、待收货图标数字气泡）。',
    'usage' => 
    array (
      0 => '全部订单: {{todo:user_order_count}}',
      1 => '待付款: {{todo:user_order_count status="pending"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'status',
        'type' => 'string',
        'desc' => '可选。订单状态：all（全部）、pending（待付款）、paid（已支付待发货）、shipped（已发货待收货）、completed（已完成）、cancelled（已取消）。默认 all。',
      ),
    ),
    'related' => 
    array (
      0 => 'cart_count',
      1 => 'coupons_count',
    ),
  ),
  'user_default_address_text' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 电商',
    'is_block' => false,
    'title' => '会员默认收件地址 user_default_address_text',
    'intro' => '输出当前会员设为默认收件地址的完整文本拼合串（收件人、联系电话、省市区与详细地址）。',
    'usage' => 
    array (
      0 => '<p class="shipping-addr">{{todo:user_default_address_text}}</p>',
    ),
    'related' => 
    array (
      0 => 'checkout_url',
    ),
  ),
  'coupons_count' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 电商',
    'is_block' => false,
    'title' => '会员优惠券张数 coupons_count',
    'intro' => '输出会员账户内当前可用、未过期的优惠券有效总张数。',
    'usage' => 
    array (
      0 => '我的优惠券({{todo:coupons_count}})',
    ),
    'related' => 
    array (
      0 => 'user_order_count',
    ),
  ),
  'wallet_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '会员钱包中心链接 wallet_url',
    'intro' => '获取会员中心在线充值与资金明细页面的完整 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:wallet_url}}">我的钱包</a>',
    ),
    'related' => 
    array (
      0 => 'wallet_balance',
      1 => 'user_balance',
    ),
  ),
  'wallet_balance' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '钱包资金余额 wallet_balance',
    'intro' => '输出会员钱包资金的实际可用金额数值。',
    'usage' => 
    array (
      0 => '{{todo:wallet_balance}}',
    ),
    'related' => 
    array (
      0 => 'wallet_url',
      1 => 'user_balance',
    ),
  ),
  'favorites_count' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局, 导航',
    'is_block' => false,
    'title' => '会员收藏夹总条数 favorites_count',
    'intro' => '输出当前登录会员收藏的文章与商品条目总数。支持按类型分别获取文章收藏数（favorite_articles_count）与商品收藏数（favorite_products_count）。',
    'usage' => 
    array (
      0 => '我的收藏 ({{todo:favorites_count}})',
    ),
    'related' => 
    array (
      0 => 'cart_count',
    ),
  ),
  'if_users_can_register' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '全局, 用户中心, 条件判断',
    'is_block' => false,
    'title' => '是否允许公开注册 if_users_can_register',
    'intro' => '判断系统后台是否开启了“允许新用户公开注册”开关。若已关闭，模板可据此隐藏注册按钮。',
    'usage' => 
    array (
      0 => '{{todo:if if_users_can_register}}
  <a href="{{todo:register_url}}">免费注册</a>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'register_url',
      1 => 'login_url',
    ),
  ),
  'if_qq_login_enabled' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局, 条件判断',
    'is_block' => false,
    'title' => '是否开启 QQ 快捷登录 if_qq_login_enabled',
    'intro' => '判断系统后台是否配置并启用了 QQ OAuth 快捷登录互联接口。',
    'usage' => 
    array (
      0 => '{{todo:if if_qq_login_enabled}}
  <a href="{{todo:qq_login_url}}" class="btn-qq">QQ快捷登录</a>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'qq_login_url',
      1 => 'third_party_login_enabled',
    ),
  ),
  'if_mobile_login_enabled' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局, 条件判断',
    'is_block' => false,
    'title' => '是否开启手机验证码登录 if_mobile_login_enabled',
    'intro' => '判断系统后台是否启用了短信网关与手机验证码快速登录/注册功能。',
    'usage' => 
    array (
      0 => '{{todo:if if_mobile_login_enabled}}
  <a href="{{todo:mobile_login_url}}">手机快捷登录</a>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'mobile_login_url',
    ),
  ),
  'third_party_login_enabled' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局, 条件判断',
    'is_block' => false,
    'title' => '是否有启用的快捷登录 third_party_login_enabled',
    'intro' => '判断是否存在任一已配置生效的第三方快捷登录方式（QQ、微信、微博等）。',
    'usage' => 
    array (
      0 => '{{todo:if third_party_login_enabled}}
  <div class="social-login">...</div>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'if_qq_login_enabled',
    ),
  ),
  'qq_login_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心, 全局',
    'is_block' => false,
    'title' => 'QQ 快捷登录授权链接 qq_login_url',
    'intro' => '获取发起 QQ OAuth 登录授权的跳转链接 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:qq_login_url}}">QQ登录</a>',
    ),
    'related' => 
    array (
      0 => 'if_qq_login_enabled',
    ),
  ),
  'mobile_login_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '手机登录接口链接 mobile_login_url',
    'intro' => '获取手机短信验证码登录入口或处理端点 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:mobile_login_url}}">短信登录</a>',
    ),
    'related' => 
    array (
      0 => 'if_mobile_login_enabled',
    ),
  ),
  'login_api_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '异步登录 API 提交地址 login_api_url',
    'intro' => '获取前端 Ajax 提交用户名与密码的统一身份认证接口 URL。',
    'usage' => 
    array (
      0 => '<form action="{{todo:login_api_url}}" method="post">',
    ),
    'related' => 
    array (
      0 => 'login_url',
    ),
  ),
  'qr_code_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '扫码登录二维码 API qr_code_url',
    'intro' => '获取动态生成扫码登录二维码图片的后端接口地址（支持微信扫码登录等场景）。',
    'usage' => 
    array (
      0 => '<img src="{{todo:qr_code_url}}" alt="扫码登录">',
    ),
    'related' => 
    array (
      0 => 'login_api_url',
    ),
  ),
  'forgot_password_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '找回密码页面链接 forgot_password_url',
    'intro' => '获取找回密码引导重置页面的访问 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:forgot_password_url}}">忘记密码？</a>',
    ),
    'related' => 
    array (
      0 => 'forgot_password_api_url',
    ),
  ),
  'forgot_password_api_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '找回密码处理 API forgot_password_api_url',
    'intro' => '找回密码验证码核验及新密码提交的后端异步接口地址。',
    'usage' => 
    array (
      0 => '<form action="{{todo:forgot_password_api_url}}" method="post">',
    ),
    'related' => 
    array (
      0 => 'forgot_password_url',
    ),
  ),
  'recharge_api_url' => 
  array (
    'group' => '用户 / 会员',
    'tag' => '用户中心',
    'is_block' => false,
    'title' => '在线充值发起接口 recharge_api_url',
    'intro' => '会员中心发起在线充值生成支付订单的 API 接口 URL。',
    'usage' => 
    array (
      0 => '<form action="{{todo:recharge_api_url}}" method="post">',
    ),
    'related' => 
    array (
      0 => 'wallet_url',
    ),
  ),
  'comments_enabled' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页, 条件判断',
    'is_block' => false,
    'title' => '是否允许发表评论 comments_enabled',
    'intro' => '判断当前文章详情或全站是否允许提交评论（返回 \'1\' 或 \'0\'）。用于在关闭评论时自动隐藏表单并展示友好提示。',
    'usage' => 
    array (
      0 => '{{todo:if comments_enabled == \'1\'}}
  <form id="comment-form">...</form>
{{todo:else}}
  <p>该内容已关闭评论</p>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'user_can_comment',
      1 => 'comments_count',
    ),
  ),
  'user_can_comment' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页, 条件判断',
    'is_block' => false,
    'title' => '当前用户是否有权评论 user_can_comment',
    'intro' => '判断当前访问者是否有权限参与发表评论（系统设置为仅登录会员评论时，游客返回 \'0\'）。',
    'usage' => 
    array (
      0 => '{{todo:if user_can_comment == \'1\'}}
  <textarea></textarea>
{{todo:else}}
  <p>请先 <a href="{{todo:login_url}}">登录</a> 后发表评论</p>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'comments_enabled',
    ),
  ),
  'comment_id' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '评论 ID comment_id',
    'intro' => '输出当前评论条目的唯一主键编号，用于 HTML 元素锚点定位（如 id="comment-{{todo:comment_id}}"）。',
    'usage' => 
    array (
      0 => '<li id="comment-{{todo:comment_id}}">',
    ),
    'related' => 
    array (
      0 => 'comment_author',
      1 => 'comment_content',
    ),
  ),
  'comment_author' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '评论者昵称 comment_author',
    'intro' => '输出提交该条评论的访客昵称或注册会员名称（已做 XSS 安全过滤）。',
    'usage' => 
    array (
      0 => '<span class="author">{{todo:comment_author}}</span>',
    ),
    'related' => 
    array (
      0 => 'comment_avatar',
      1 => 'comment_date',
    ),
  ),
  'comment_date' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '评论发表时间 comment_date',
    'intro' => '输出评论发表的具体日期时间，支持格式化参数。',
    'usage' => 
    array (
      0 => '{{todo:comment_date}}',
      1 => '{{todo:comment_date format="Y-m-d H:i"}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'format',
        'type' => 'string',
        'desc' => '可选。PHP 日期格式（如 Y-m-d, m-d H:i），默认 Y-m-d H:i:s。',
      ),
    ),
    'related' => 
    array (
      0 => 'comment_author',
    ),
  ),
  'comment_content' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '评论内容正文 comment_content',
    'intro' => '输出该条评论的正文内容，自动执行换行转 <br> 及安全字符保护。',
    'usage' => 
    array (
      0 => '<div class="comment-body">{{todo:comment_content}}</div>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'escape',
        'type' => 'string',
        'desc' => '可选。转义模式：html（默认）、none。',
      ),
    ),
    'related' => 
    array (
      0 => 'comment_author',
    ),
  ),
  'comment_avatar' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '评论者头像 comment_avatar',
    'intro' => '输出发表该条评论的用户头像图片 URL 或 <img> 标签。',
    'usage' => 
    array (
      0 => '<img src="{{todo:comment_avatar}}" alt="{{todo:comment_author}}">',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'size',
        'type' => 'int',
        'desc' => '可选。头像像素尺寸，默认 48。',
      ),
      1 => 
      array (
        'name' => 'default',
        'type' => 'string',
        'desc' => '可选。无头像时的兜底默认图片。',
      ),
    ),
    'related' => 
    array (
      0 => 'comment_author',
    ),
  ),
  'comment_reply_link' => 
  array (
    'group' => '互动 / 评论',
    'tag' => '详情页',
    'is_block' => false,
    'title' => '回复此评论链接 comment_reply_link',
    'intro' => '输出触发楼中楼回复当前评论的快捷超链接或 JS 点击触发属性。',
    'usage' => 
    array (
      0 => '<a href="#respond" {{todo:comment_reply_link}}>回复</a>',
    ),
    'related' => 
    array (
      0 => 'comment_id',
    ),
  ),
  'pagination_prev_url' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '列表页, 搜索',
    'is_block' => false,
    'title' => '上一页链接 pagination_prev_url',
    'intro' => '仅输出列表上一页的跳转 URL 地址。适合移动端滑动翻页、极简悬浮翻页条等不需要全套数字分页 HTML 的自定义设计场景。',
    'usage' => 
    array (
      0 => '<a href="{{todo:pagination_prev_url}}" class="btn-prev">上一页</a>',
    ),
    'related' => 
    array (
      0 => 'pagination_next_url',
      1 => 'pagination_first_url',
      2 => 'pagination_last_url',
      3 => 'pagination',
    ),
  ),
  'pagination_next_url' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '列表页, 搜索',
    'is_block' => false,
    'title' => '下一页链接 pagination_next_url',
    'intro' => '仅输出列表下一页的跳转 URL 地址。常用于无限滚动加载更多或自定义前后导航键。',
    'usage' => 
    array (
      0 => '<a href="{{todo:pagination_next_url}}" class="btn-next">下一页</a>',
    ),
    'related' => 
    array (
      0 => 'pagination_prev_url',
      1 => 'pagination_total_pages',
    ),
  ),
  'pagination_first_url' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '列表页, 搜索',
    'is_block' => false,
    'title' => '第一页首页链接 pagination_first_url',
    'intro' => '输出列表第 1 页（首页）的标准规范 URL（自动剔除 /page/1 保持规范化 SEO）。',
    'usage' => 
    array (
      0 => '<a href="{{todo:pagination_first_url}}">首页</a>',
    ),
    'related' => 
    array (
      0 => 'pagination_last_url',
    ),
  ),
  'pagination_last_url' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '列表页, 搜索',
    'is_block' => false,
    'title' => '最后一页链接 pagination_last_url',
    'intro' => '输出当前列表最后一页的链接 URL。',
    'usage' => 
    array (
      0 => '<a href="{{todo:pagination_last_url}}">尾页</a>',
    ),
    'related' => 
    array (
      0 => 'pagination_first_url',
      1 => 'pagination_total_pages',
    ),
  ),
  'pagination_total_pages' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '列表页, 搜索',
    'is_block' => false,
    'title' => '总分页数 pagination_total_pages',
    'intro' => '输出当前列表经过总数与每页行数计算得到的最大分页总数（纯整数）。',
    'usage' => 
    array (
      0 => '共 {{todo:pagination_total_pages}} 页',
    ),
    'related' => 
    array (
      0 => 'pagination_next_url',
      1 => 'pagination',
    ),
  ),
  'search_url' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '全局, 搜索, 首页, 导航',
    'is_block' => false,
    'title' => '搜索表单提交链接 search_url',
    'intro' => '输出全站搜索结果页面的标准提交 URL，支持预设默认搜索词。',
    'usage' => 
    array (
      0 => '<form action="{{todo:search_url}}" method="get">
  <input type="search" name="s" value="{{todo:search_keyword}}">
</form>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'keyword',
        'type' => 'string',
        'desc' => '可选。预置默认搜索关键词。',
      ),
    ),
    'related' => 
    array (
      0 => 'search_keyword',
      1 => 'search_locked',
      2 => 'hot_searches',
    ),
  ),
  'search_keyword' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '搜索, 列表页',
    'is_block' => false,
    'title' => '当前搜索关键词 search_keyword',
    'intro' => '获取当前搜索结果页面中访客实际输入的检索关键词（已做 XSS 与转义保护）。',
    'usage' => 
    array (
      0 => '<h1>关于 “{{todo:search_keyword}}” 的搜索结果</h1>',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'escape',
        'type' => 'string',
        'desc' => '可选。转义模式：html（默认）、attr、none。',
      ),
    ),
    'related' => 
    array (
      0 => 'search_url',
      1 => 'search_results',
    ),
  ),
  'search_locked' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '搜索, 条件判断',
    'is_block' => false,
    'title' => '搜索频率锁定判断 search_locked',
    'intro' => '判断当前 IP 是否因高频快速搜索触发了系统的限流保护（返回 \'1\' 或 \'0\'）。',
    'usage' => 
    array (
      0 => '{{todo:if search_locked == \'1\'}}
  <p class="alert">您的搜索过于频繁，请稍后再试。</p>
{{/todo:if}}',
    ),
    'related' => 
    array (
      0 => 'search_keyword',
    ),
  ),
  'hot_searches' => 
  array (
    'group' => '搜索 / 分页',
    'tag' => '搜索, 首页, 全局, 导航',
    'is_block' => true,
    'title' => '热门搜索词循环 hot_searches',
    'intro' => '块循环标签。遍历后台预设或系统根据热度统计的热门搜索词列表。',
    'usage' => 
    array (
      0 => '{{todo:hot_searches limit="6"}}
  <a href="{{todo:search_url}}?s={{keyword}}">{{keyword}}</a>
{{/todo:hot_searches}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'type' => 'int',
        'desc' => '可选。输出的热门词条数，默认 8。',
      ),
    ),
    'related' => 
    array (
      0 => 'search_url',
    ),
  ),
  'timeline_events' => 
  array (
    'group' => '媒体 / 扩展',
    'tag' => '详情页, 单页, 循环遍历',
    'is_block' => true,
    'title' => '时间轴发展历程循环 timeline_events',
    'intro' => '块循环标签。遍历文章、单篇或专题中配置的发展历程/时间线节点（输出 year, date, title, description, image 等属性）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="timeline_events"}}
  <div class="timeline-item">
    <span class="year">{{year}}</span>
    <h4>{{title}}</h4>
    <p>{{description}}</p>
  </div>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'gallery_images',
    ),
  ),
  'gallery_images' => 
  array (
    'group' => '媒体 / 扩展',
    'tag' => '详情页, 画廊, 媒体, 循环遍历',
    'is_block' => true,
    'title' => '画廊多图列表循环 gallery_images',
    'intro' => '块循环标签。遍历当前模型文章的多图画廊相册（自动输出 url 原图、thumb 缩略图、title 图片说明）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="gallery_images"}}
  <a href="{{url}}" title="{{title}}">
    <img src="{{thumb}}" alt="{{title}}">
  </a>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'post_images',
      1 => 'featured_images',
    ),
  ),
  'media_sources' => 
  array (
    'group' => '媒体 / 扩展',
    'tag' => '详情页, 媒体, 循环遍历',
    'is_block' => true,
    'title' => '音视频多码率媒体源 media_sources',
    'intro' => '块循环标签。遍历当前音视频文章的多清晰度/多码率播放源（输出 source_url 播放地址、source_type 格式、quality 清晰度标签如 1080P/720P）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="media_sources"}}
  <source src="{{source_url}}" type="{{source_type}}" data-quality="{{quality}}">
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'subtitle_tracks',
      1 => 'playlist',
    ),
  ),
  'subtitle_tracks' => 
  array (
    'group' => '媒体 / 扩展',
    'tag' => '详情页, 媒体, 循环遍历',
    'is_block' => true,
    'title' => '视频多语言外挂字幕 subtitle_tracks',
    'intro' => '块循环标签。遍历当前视频文章绑定的多语言外挂字幕轨道（输出 track_url 字幕文件地址、track_lang 语言代号、track_label 显示标签）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="subtitle_tracks"}}
  <track src="{{track_url}}" srclang="{{track_lang}}" label="{{track_label}}">
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'media_sources',
    ),
  ),
  'playlist' => 
  array (
    'group' => '媒体 / 扩展',
    'tag' => '详情页, 媒体, 循环遍历',
    'is_block' => true,
    'title' => '音视频专辑播放列表 playlist',
    'intro' => '块循环标签。遍历音视频合集或专栏的选集列表（输出 media_url 媒体地址、title 剧集标题、thumbnail 剧照封面、duration 时长）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="playlist"}}
  <li data-url="{{media_url}}">
    <img src="{{thumbnail}}">
    <span>{{title}}</span>
    <time>{{duration}}</time>
  </li>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'media_sources',
    ),
  ),
  'requirements' => 
  array (
    'group' => '招聘模型',
    'tag' => '详情页, 招聘, 循环遍历',
    'is_block' => true,
    'title' => '招聘岗位任职要求循环 requirements',
    'intro' => '块循环标签。遍历招聘模型中职位设置的逐条任职资格要求（输出 requirement 文本）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="requirements"}}
  <li>{{requirement}}</li>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'benefits',
      1 => 'related_jobs',
    ),
  ),
  'benefits' => 
  array (
    'group' => '招聘模型',
    'tag' => '详情页, 招聘, 循环遍历',
    'is_block' => true,
    'title' => '招聘岗位薪酬福利循环 benefits',
    'intro' => '块循环标签。遍历招聘模型中职位提供的薪酬福利待遇要点（输出 benefit 文本及图标）。',
    'usage' => 
    array (
      0 => '{{todo:loop items="benefits"}}
  <span class="badge-benefit">{{benefit}}</span>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'requirements',
      1 => 'related_jobs',
    ),
  ),
  'related_content' => 
  array (
    'group' => '文章 / 内容',
    'tag' => '详情页, 循环遍历',
    'is_block' => true,
    'title' => '跨模型智能关联内容循环 related_content',
    'intro' => '块循环标签。根据当前内容的标签、分类及相关性算法，智能跨模型查询推荐的内容集合。',
    'usage' => 
    array (
      0 => '{{todo:loop items="related_content" limit="4"}}
  <a href="{{url}}">{{title}}</a>
{{/todo:loop}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'type' => 'int',
        'desc' => '可选。返回关联内容条数，默认 6。',
      ),
    ),
    'related' => 
    array (
      0 => 'related_posts_by_tags',
      1 => 'special_featured_posts',
    ),
  ),
  'related_jobs' => 
  array (
    'group' => '招聘模型',
    'tag' => '详情页, 招聘, 循环遍历',
    'is_block' => true,
    'title' => '相关职位推荐列表 related_jobs',
    'intro' => '块循环标签。在职位详情页中自动查找同部门或技能相似的其他开放招聘职位列表。',
    'usage' => 
    array (
      0 => '{{todo:loop items="related_jobs" limit="4"}}
  <a href="{{url}}">{{title}} - {{salary}}</a>
{{/todo:loop}}',
    ),
    'params' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'type' => 'int',
        'desc' => '可选。数量限制，默认 4。',
      ),
    ),
    'related' => 
    array (
      0 => 'requirements',
      1 => 'benefits',
    ),
  ),
  'special_categories' => 
  array (
    'group' => '专题 / 聚合',
    'tag' => '详情页, 专题, 循环遍历',
    'is_block' => true,
    'title' => '专题关联分类循环 special_categories',
    'intro' => '块循环标签。在专题详情页中遍历当前专题绑定的所有内容分类板块。',
    'usage' => 
    array (
      0 => '{{todo:loop items="special_categories"}}
  <a href="{{url}}">{{name}} ({{count}})</a>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'special_tags',
      1 => 'special_featured_posts',
      2 => 'specials',
    ),
  ),
  'special_tags' => 
  array (
    'group' => '专题 / 聚合',
    'tag' => '详情页, 专题, 循环遍历',
    'is_block' => true,
    'title' => '专题聚合标签循环 special_tags',
    'intro' => '块循环标签。在专题详情页中遍历当前专题归纳聚合的核心热门标签集合。',
    'usage' => 
    array (
      0 => '{{todo:loop items="special_tags"}}
  <a href="{{url}}" class="tag-item">{{name}}</a>
{{/todo:loop}}',
    ),
    'related' => 
    array (
      0 => 'special_categories',
      1 => 'specials',
    ),
  ),
);

# TodoCMS Default HTML Theme

这是一个基于纯HTML模板的TodoCMS主题，使用模板标签语法来实现动态内容展示。
这是系统默认主题由 TodoCMS 官方维护，更新会被覆盖；仅供开发参考，请勿用于正式环境。
不定期更新标签的用法及规则，主题开发过程中请参照该主题示例。

## 主题特性

-  **纯HTML模板** - 使用HTML文件而非PHP文件作为模板
- ️ **模板标签** - 支持TodoCMS/TodoCMS风格的模板标签
-  **性能优化** - 轻量级代码，快速加载
-  **易于定制** - 清晰的HTML结构，便于修改
-  **SEO友好** - 语义化HTML标记

## 文件结构

```
default/
├── assets/css/style.css          # 主样式文件
├── index.html         # 首页模板
├── header.html        # 头部模板
├── footer.html        # 底部模板

├── archive.html       # 归档页面模板
├── lists_search.html  # 搜索结果模板
├── 404.html           # 404错误页面模板
├── sidebar.html       # 侧边栏模板
├── assets/js/
│   └── theme.js       # 主题JavaScript文件
└── README.md          # 说明文档
```

## 样式定制

主题使用CSS变量来管理颜色和尺寸，便于定制：

```css
:root {
    --primary-color: #007cba;
    --secondary-color: #666;
    --background-color: #fff;
    --text-color: #333;
    --border-color: #ddd;
    --container-width: 1200px;
}
```

## JavaScript功能

主题包含以下JavaScript功能：

-  移动端导航菜单
-  搜索表单增强
- ️ 图片懒加载
-  返回顶部按钮
-  评论表单验证
-  平滑滚动

## 浏览器支持

- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+
- IE 11（部分功能）

## 安装使用

1. 将主题文件夹上传到 `/content/themes/` 目录
2. 在TodoCMS后台激活主题
3. 根据需要自定义样式和功能

## 自定义开发

### 添加新模板

1. 在主题目录创建新的HTML文件
2. 使用TodoCMS模板标签语法

### 修改样式

1. 编辑`style.css`文件
2. 使用CSS变量保持一致性
3. 遵循响应式设计原则

### 扩展功能

1. 在`js/theme.js`中添加JavaScript功能
2. 使用TodoCMS的钩子和过滤器

## 注意事项

- 确保服务器支持HTML模板解析
- 模板标签语法需要TodoCMS核心支持
- 建议在开发环境中测试所有功能
- 定期备份自定义修改

## 更新日志

### 1.0.0
- 初始版本发布
- 基础HTML模板结构
- 完整的模板标签支持
- 响应式设计
- JavaScript增强功能

## 技术支持

如有问题或建议，请访问：
- 官方网站：http://todocms.com/
- 文档中心：http://docs.todocms.com/
- 社区论坛：http://community.todocms.com/

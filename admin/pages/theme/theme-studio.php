<?php
/**
 * TodoCMS 主题工作室 (Theme Studio)
 *
 * 核心功能：
 * 1. 左侧：theme 目录下的主题文件夹下拉 + 对应主题的文件树形列表与快速预览
 * 2. 中间：官方原对标下拉、素材包下拉、新主题目录名、主题模式(PC/Mobile)、多个可立即执行的预设任务
 * 3. 基础包规范构建：头尾分离、模板齐全、自动克隆官方原 users 用户中心、导入 template-tags.md 标签手册
 * 4. 规格说明生成：theme-ai-spec.json 明确要求在 functions.php 中初始化插入默认数据、默认图片存放在 pc/assets/images/data/、样式与语言规范
 * 5. 严格格式约束：严禁 AI 生成 .tsx / React / SPA 前端工程，强制输出纯原生 TodoCMS 模板标签 .html 与静态资源
 * 6. Google AI Studio 专属投喂提示词生成与一键打包
 *
 * @package TodoCMS
 * @subpackage Administration
 */

if (!defined('ABSPATH')) {
    exit;
}

// 权限检查
if (!current_user_can('edit_themes') && !current_user_can('manage_themes') && !current_user_can('manage_options')) {
    todo_die('抱歉，您没有权限访问主题工作室。');
}

$current_select_theme = sanitize_key($_GET['theme'] ?? 'default');
$csrf_token = Todo_Security_Manager::generate_csrf_token('theme_studio_nonce');
$common_nonce = Todo_Security_Manager::generate_csrf_token('common_ajax_nonce');
$ajax_url = todo_admin_url('ajax/theme-studio-handler.php');
$tags_doc_url = todo_admin_url('index.php?page=template-tags');
$is_demo_mode = function_exists('todo_is_demo_admin_session') && todo_is_demo_admin_session();
?>

<div class="wrap theme-studio-wrap">
    <!-- 顶部工坊导航栏 -->
    <div class="studio-header-bar">
        <div class="studio-header-left">
            <div class="studio-brand-badge">
                <span class="studio-icon">🎨</span>
                <div class="studio-title-group">
                    <h1 class="studio-main-title">TodoCMS 主题工作室</h1>
                    <p class="studio-sub-title">对标官方原规范，构建开箱即用的主题地基包与全量 AI 规格说明书</p>
                </div>
            </div>
        </div>
        <div class="studio-header-right">
            <a href="<?php echo todo_admin_url('index.php?page=themes'); ?>" class="studio-btn studio-btn-ghost">
                返回主题列表
            </a>
            <a href="https://aistudio.google.com/" target="_blank" class="studio-btn studio-btn-primary studio-ai-link">
                Google AI Studio <span class="hot-badge">Prompt</span>
            </a>
        </div>
    </div>

    <!-- 主体两栏布局 -->
    <div class="studio-workspace-grid">
        <!-- ======================= 左侧：主题与文件资源浏览器 ======================= -->
        <aside class="studio-sidebar-panel">
            <div class="sidebar-header">
                <div class="panel-title-row">
                    <h3>主题资源</h3>
                    <button type="button" class="btn-sub-action" id="btnRefreshTree" title="刷新文件树" style="height: 26px; line-height: 26px; font-size: 12px;">
                        刷新
                    </button>
                </div>
                <!-- 主题选择下拉框 -->
                <div class="form-group theme-select-group">
                    <select id="explorerThemeSelect" class="studio-select">
                        <option value="default">加载中...</option>
                    </select>
                </div>
                <!-- 当前主题基本徽章 -->
                <div class="theme-meta-pills" id="themeMetaPills">
                    <span class="pill pill-name" id="pillThemeName">default</span>
                    <span class="pill pill-ver" id="pillThemeVer">v1.0.0</span>
                    <span class="pill pill-users" id="pillThemeUsers" title="用户中心对接状态">Users 对接</span>
                </div>
                <!-- 文件筛选搜索 -->
                <div class="search-box">
                    <input type="text" id="fileSearchInput" placeholder="快速过滤文件 (如 index, users)..." class="studio-input">
                </div>
            </div>

            <!-- 文件树容器 -->
            <div class="file-tree-container" id="fileTreeContainer">
                <div class="tree-loading">
                    正在读取主题文件树...
                </div>
            </div>

            <!-- 底部文件状态 -->
            <div class="sidebar-footer">
                <span id="treeStats">共 0 个文件</span>
                <span class="tip-help">点击文件可快速预览</span>
            </div>
        </aside>

        <!-- ======================= 中间：主题工坊核心操作区 ======================= -->
        <main class="studio-main-panel">
            <!-- 核心配置表单卡片 -->
            <div class="studio-card">
                <div class="card-head">
                    <div class="card-title" style="justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="step-num">1</span>
                            <h3>新主题构建与基准对标配置</h3>
                            <span class="local-save-badge" id="localSaveBadge" title="表单修改已自动暂存在浏览器本地，刷新不丢失" style="display: none; font-size: 11px; font-weight: normal; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 10px;">
                                已存草稿
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                            <button type="button" id="btnResetFormDraft" class="btn-sub-action" title="重置表单并清除本地暂存" style="height: 26px; line-height: 26px; font-size: 12px;">
                                重置
                            </button>
                        </div>
                    </div>
                    <span class="card-desc" style="margin-top: 6px;">对标官方原标准体系，注入规范头尾分离、克隆 users 用户中心及数据自填充机制（表单已开启本地实时保存，刷新页面不丢失）</span>
                </div>
                <div class="card-body">
                    <div class="config-grid">
                        <!-- 对标官方原主题 -->
                        <div class="form-item">
                            <label class="item-label">
                                <span>官方对标基准主题</span>
                                <span class="label-tip">作为模板体系与 users 用户中心克隆源</span>
                            </label>
                            <select id="selectSourceTheme" class="studio-select">
                                <option value="default" selected>default (官方默认标准主题)</option>
                            </select>
                        </div>

                        <!-- 素材包选择 -->
                        <div class="form-item">
                            <label class="item-label">
                                <span>参考素材包 / 强对标物</span>
                                <span class="label-tip">优先直接参考目标的DOM要素、UI布局及JS动效，再由Prompt补充色彩风格</span>
                            </label>
                            <div class="input-with-action">
                                <select id="selectMaterialPkg" class="studio-select">
                                    <option value="">加载素材包列表中...</option>
                                </select>
                                <button type="button" class="btn-sub-action" id="btnHelpMaterial" title="素材包存放提示">
                                    ?
                                </button>
                            </div>
                            <div id="wrapCustomMaterialUrl" style="display: none; margin-top: 6px;">
                                <div class="label-tip" style="color: #6366f1; margin-bottom: 4px; font-weight: 500;">
                                    强参考法则：AI 将优先直接对标此 URL 站点的 DOM 布局排版与 JS 交互动效
                                </div>
                                <input type="url" id="inputCustomMaterialUrl" class="studio-input" placeholder="如 https://example.com" value="">
                            </div>
                        </div>

                        <!-- 要创建的主题文件夹名称 -->
                        <div class="form-item">
                            <label class="item-label">
                                <span>新主题文件夹名 (Slug)</span>
                                <span class="label-tip">限英文字母、数字、下划线 (如 my_theme)</span>
                            </label>
                            <input type="text" id="inputTargetSlug" class="studio-input" placeholder="例如：my-style" value="">
                        </div>

                        <!-- 主题模式 (PC / Mobile) -->
                        <div class="form-item">
                            <label class="item-label">
                                <span>主题架构模式</span>
                                <span class="label-tip">决定生成的目录架构</span>
                            </label>
                            <select id="selectThemeMode" class="studio-select">
                                <option value="pc_mobile" selected>PC + Mobile 独立双端架构 (推荐规范)</option>
                                <option value="pc_only">纯 PC 端 / 自适应响应式</option>
                                <option value="mobile_only">纯移动端 (H5/Mobile)</option>
                            </select>
                        </div>

                        <!-- 主题名称 -->
                        <div class="form-item">
                            <label class="item-label">主题展示名称</label>
                            <input type="text" id="inputThemeName" class="studio-input" placeholder="例如：我的风格网站" value="">
                        </div>

                        <!-- 版本与作者 -->
                        <div class="form-item-duo">
                            <div class="form-item">
                                <label class="item-label">版本号</label>
                                <input type="text" id="inputThemeVersion" class="studio-input" value="1.0.0">
                            </div>
                            <div class="form-item">
                                <label class="item-label">作者</label>
                                <input type="text" id="inputThemeAuthor" class="studio-input" value="TodoCMS AI Studio">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 预设任务矩阵 -->
            <div class="studio-card">
                <div class="card-head">
                    <div class="card-title">
                        <span class="step-num">2</span>
                        <h3>可以立即执行的预设任务</h3>
                    </div>
                    <span class="card-desc">支持单步递进构建，或一键自动化生成投喂级主题资产包</span>
                </div>
                <div class="card-body">
                    <div class="preset-tasks-grid">
                        <!-- 任务 1：构建规范基础主题地基包 -->
                        <div class="task-card" id="taskCardScaffold">
                            <div class="task-header">
                                <div class="task-icon-circle bg-purple">1</div>
                                <div>
                                    <h4 class="task-title">构建基础主题地基包</h4>
                                    <span class="task-tag">脚手架工程</span>
                                </div>
                            </div>
                            <ul class="task-feature-list">
                                <li>严格克隆官方原 <strong>users 用户中心</strong> (保持 19 个模板交互规范)</li>
                                <li>同步全量静态基础设施 (<strong>Tailwind 原子库、Motion 动效引擎、CSRF防重脚本与语言包</strong>)</li>
                                <li>生成健全头尾分离模板 (38 套模型与组件模板，严禁缺失)</li>
                                <li>导入官方 <strong>template-tags.md</strong> 标签体系手册并注入 functions.php</li>
                            </ul>
                            <div class="task-actions">
                                <button type="button" class="studio-btn studio-btn-gradient" id="btnRunScaffold">
                                    立即构建基础包
                                </button>
                            </div>
                        </div>

                        <!-- 任务 2：生成全量 AI 规格说明书 (JSON) -->
                        <div class="task-card" id="taskCardSpec">
                            <div class="task-header">
                                <div class="task-icon-circle bg-blue">2</div>
                                <div>
                                    <h4 class="task-title">生成全量 AI 规格说明</h4>
                                    <span class="task-tag">theme-ai-spec.json</span>
                                </div>
                            </div>
                            <ul class="task-feature-list">
                                <li><strong>【硬性禁令】</strong>严禁生成 .tsx / React / Vue / SPA 工程</li>
                                <li>明确数据初始化插入结构与默认图像引用规则</li>
                                <li>严格要求样式、JS 与多语言 (lang) 规范化入队</li>
                                <li>声明自定义标签命名与数据初始化对应预留</li>
                            </ul>
                            <div class="task-actions">
                                <button type="button" class="studio-btn studio-btn-cyan" id="btnRunGenerateSpec">
                                    生成并预览规格 JSON
                                </button>
                            </div>
                        </div>

                        <!-- 任务 3：一键全流程打包与导出 -->
                        <div class="task-card task-card-highlight" id="taskCardOnekey">
                            <div class="task-header">
                                <div class="task-icon-circle bg-gradient">3</div>
                                <div>
                                    <h4 class="task-title">一键全流程自动化打包</h4>
                                    <span class="task-tag badge-recommended">⭐ 推荐一步到位</span>
                                </div>
                            </div>
                            <ul class="task-feature-list">
                                <li>自动流水线执行：地基构建 + users 克隆 + 全量静态基础设施同步</li>
                                <li>生成包含 Tailwind + Motion 双引擎、CSRF 守卫与演示数据的 functions.php</li>
                                <li>自动生成 52 项全量拓扑 AI 规格并打包为专属 ZIP (绝不丢项)</li>
                                <li>产物可直接投喂给 Google AI Studio 开展精细化设计</li>
                            </ul>
                            <div class="task-actions">
                                <button type="button" class="studio-btn studio-btn-golden" id="btnRunOnekey">
                                    一键打包 AI 投喂就绪包
                                </button>
                            </div>
                        </div>

                        <!-- 任务 4：Google AI Studio 投喂 Prompt 专区 -->
                        <div class="task-card" id="taskCardPrompt">
                            <div class="task-header">
                                <div class="task-icon-circle bg-indigo">4</div>
                                <div>
                                    <h4 class="task-title">Google AI Studio 投喂指导</h4>
                                    <span class="task-tag">AI Prompt</span>
                                </div>
                            </div>
                            <ul class="task-feature-list">
                                <li>适配 <strong>Google AI Studio</strong> 终极契约提示词 (含 52 项资产保护清单)</li>
                                <li>严厉红线：<strong>严禁仅交付 style.css/theme.js 残缺包！必须保全 users 及全套静态基础设施</strong></li>
                                <li>附带 5 轮深度自检自测校验规范与 Tailwind + Motion 双引擎指令</li>
                                <li>支持一键复制全部 Prompt 文本</li>
                            </ul>
                            <div class="task-actions">
                                <button type="button" class="studio-btn studio-btn-purple" id="btnViewPrompt">
                                    复制投喂 Prompt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 控制台实时执行日志区 -->
            <div class="studio-card studio-terminal-card">
                <div class="terminal-head">
                    <span class="terminal-title">控制台</span>
                    <div class="terminal-actions">
                        <button type="button" class="btn-terminal-clear" id="btnClearConsole" title="清空日志">
                            清屏
                        </button>
                    </div>
                </div>
                <div class="terminal-body" id="studioTerminal">
                    <div class="term-line term-line-info">✨ 欢迎进入 TodoCMS 主题工作室！就绪状态。</div>
                    <div class="term-line term-line-tip">💡 提示：请选择官方对标基准与素材包，点击预设任务即可自动生成基础主题包与规格说明。</div>
                </div>
            </div>

            <!-- 快捷产物交付区 (完成任务后动态展现) -->
            <div class="delivery-banner" id="deliveryBanner" style="display: none;">
                <div class="delivery-content">
                    <div class="delivery-icon">🎉</div>
                    <div class="delivery-info">
                        <h4 id="deliveryTitle">主题构建成功！</h4>
                        <p id="deliverySub">主题已位于 <code id="deliveryPath">content/themes/...</code>，可立即激活体验或导出给 AI 精加工。</p>
                    </div>
                </div>
                <div class="delivery-btns">
                    <a href="javascript:void(0);" id="btnDownloadZip" class="studio-btn studio-btn-golden" style="display:none;">
                        下载 AI 全量采样工程包 (.zip)
                    </a>
                    <a href="<?php echo todo_admin_url('index.php?page=themes'); ?>" class="studio-btn studio-btn-gradient">
                        前往主题列表激活
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ======================= 模态框 1：代码 / 图片预览器 ======================= -->
<div class="studio-modal-overlay" id="filePreviewModal" style="display:none;">
    <div class="studio-modal-dialog">
        <div class="modal-header">
            <div class="modal-title-row">
                <h3 id="previewModalTitle">文件预览</h3>
            </div>
            <button type="button" class="modal-close-btn" id="btnClosePreviewModal">&times;</button>
        </div>
        <div class="modal-body" id="previewModalBody">
            <div class="code-preview-wrap">
                <pre><code id="previewCodeContent"></code></pre>
            </div>
            <div class="image-preview-wrap" id="previewImageWrap" style="display:none; text-align:center; padding:20px;">
                <img id="previewImgElem" src="" alt="预览" style="max-width:100%; max-height:500px; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.15);">
            </div>
        </div>
        <div class="modal-footer">
            <span class="file-size-info" id="previewFileSize"></span>
            <button type="button" class="studio-btn studio-btn-ghost" id="btnCopyFileContent">
                复制内容
            </button>
            <button type="button" class="studio-btn studio-btn-primary" id="btnConfirmClosePreview">关闭</button>
        </div>
    </div>
</div>

<!-- ======================= 模态框 2：Google AI Studio 投喂 Prompt 抽屉 ======================= -->
<div class="studio-modal-overlay" id="promptModal" style="display:none;">
    <div class="studio-modal-dialog studio-modal-lg">
        <div class="modal-header">
            <div class="modal-title-row">
                <h3>Google AI Studio 专属开发任务契约与 Prompt</h3>
            </div>
            <button type="button" class="modal-close-btn" id="btnClosePromptModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="prompt-alert-banner" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px 18px; border-radius:10px; margin-bottom:16px;">
                <strong style="display:block; font-size:14px; margin-bottom:4px;">🎯 Google AI Studio 终极防跑偏与最佳实践指南：</strong>
                <p style="margin:0; font-size:13px; line-height:1.6; color:#7f1d1d;">
                    1. <strong>【核心避坑】使用 Chat Prompt，勿用 "Build an App"</strong>：请进入 Google AI Studio 点击 <strong>Create new prompt ➡️ Chat prompt</strong> 上传工程包 ZIP 并附带本 Prompt。严禁使用首页的 "Build an app"（该模式为 React/Vite 专有沙箱，会混入无用的 <code>package.json</code> 和 <code>vite.config.ts</code>）！<br>
                    2. <strong>【跨行业形态解耦】拒绝开盲盒</strong>：若参考素材属于其他行业（如卫浴瓷砖 vs 鲜花艺术），提示词已内置<strong>《跨行业形态转译协议》</strong>与<strong>《蓝图映射表》</strong>，AI 将严格对标素材包的 DOM 布局骨架、栅格间距与微交互，并注入本主题的高端业务文案！<br>
                    3. <strong>【三步走递进式生成】</strong>：单次输出难以覆盖全量代码。推荐让 AI <strong>第 1 步</strong>先输出核心门面（<code>index.html</code> + <code>header.html</code> + <code>footer.html</code> + <code>style.css</code>）；<strong>第 2 步</strong>输出商品/文章列表与详情；<strong>第 3 步</strong>输出 <code>functions.php</code> 演示数据注入。<br>
                    4. <strong>【全量基础设施零丢失】</strong>：工程包已预装 <code>pc/users/</code>（19套模板）、Tailwind 原子库、Motion 双动效引擎及多语言包。从 AI Studio 采纳成果时，务必保留全量静态资产！
                </p>
            </div>
            <div class="code-preview-wrap">
                <textarea id="promptTextarea" class="prompt-textarea" readonly></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="studio-btn studio-btn-golden" id="btnModalDownloadZip">
                下载 AI 全量采样工程包 (.zip)
            </button>
            <button type="button" class="studio-btn studio-btn-cyan" id="btnCopyPromptToClipboard">
                一键复制完整 Prompt
            </button>
        </div>
    </div>
</div>

<!-- ======================= 样式体系 (Modern Premium Light & Indigo Console) ======================= -->
<style>
/* 根变量与现代化浅色明亮主题 (Modern Light Theme) */
:root,
.theme-studio-wrap {
    --studio-bg: #f8fafc;
    --studio-card-bg: #fff;
    --studio-sidebar-bg: #fff;
    --studio-border: #e2e8f0;
    --studio-border-hover: rgba(99, 102, 241, 0.5);
    --studio-text: #1e293b;
    --studio-text-muted: #a6b1c0;
    --studio-primary: #4f46e5;
    --studio-primary-hover: #4338ca;
    --studio-cyan: #0891b2;
    --studio-golden: #d97706;
    --studio-purple: #7c3aed;
    --studio-success: #059669;
    --studio-error: #dc2626;
    
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
    color: var(--studio-text, #1e293b);
}

/* 顶部工作区栏 (Light Header) */
.studio-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px 0;
    margin-bottom: 24px;
}

.studio-brand-badge {
    display: flex;
    align-items: center;
    gap: 16px;
}
.studio-icon {
    font-size: 38px;
    background: #fff;
    padding: 12px;
    border-radius: 14px;
    border: 1px solid #dcdcdc;
    line-height: 1;
}
.studio-main-title {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
}
.studio-sub-title {
    margin: 0;
    font-size: 13px;
    color: var(--studio-text-muted);
}
.studio-header-right {
    display: flex;
    gap: 12px;
    align-items: center;
}

/* 按钮风格体系 */
.studio-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 9px 18px;
    font-size: 13px;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid transparent;
}
.studio-btn:hover {
    color: rgba(255, 255, 255, 0.75);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.studio-btn-ghost {
    background: #fff;
    color: #334155;
    border-color: #cbd5e1;
}
.studio-btn-ghost:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #94a3b8;
}
.studio-btn-primary {
    background: var(--studio-primary, #4f46e5);
    color: #fff;
}
.studio-btn-primary:hover {
    background: var(--studio-primary-hover, #4338ca);
}
.studio-btn-gradient {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.25);
}
.studio-btn-cyan {
    background: linear-gradient(135deg, #0891b2, #06b6d4);
    color: #fff;
    box-shadow: 0 4px 14px rgba(6, 182, 212, 0.25);
}
.studio-btn-golden {
    background: linear-gradient(135deg, #d97706, #f59e0b);
    color: #fff;
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.25);
}
.studio-btn-purple {
    background: linear-gradient(135deg, #7c3aed, #8b5cf6);
    color: #fff;
    box-shadow: 0 4px 14px rgba(139, 92, 246, 0.25);
}
.hot-badge {
    background: #4494ef;
    color: #fff;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 700;
}

/* 核心两栏网格布局 */
.studio-workspace-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 1200px) {
    .studio-workspace-grid {
        grid-template-columns: 1fr;
    }
}

/* 左侧栏面板 (Light Sidebar) */
.studio-sidebar-panel {
    background: var(--studio-sidebar-bg);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.sidebar-header {
    padding: 20px 20px 14px;
    border-bottom: 1px solid var(--studio-border);
}
.panel-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.panel-title-row h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
    flex: 1;
}

.theme-select-group {
    margin-bottom: 12px;
}
.theme-select-group .form-label {
    display: block;
    font-size: 12px;
    color: var(--studio-text-muted);
    margin-bottom: 6px;
}
.studio-select, .studio-input {
    width: 100%;
    min-width: unset !important;
    border-radius: 8px !important;
    padding: 9px 12px;
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.studio-select:focus, .studio-input:focus {
    border-color: var(--studio-primary, #4f46e5);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.theme-meta-pills {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.theme-meta-pills .pill {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 500;
}
.pill-name { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
.pill-ver { background: #ecfeff; color: #0891b2; border: 1px solid #a5f3fc; }
.pill-users { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }

.search-box {
    position: relative;
}
.search-box .search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--studio-text-muted);
    font-size: 12px;
}

/* 文件树视图 (Light Tree View) */
.file-tree-container {
    max-height: 1000px;
    overflow-y: auto;
    padding: 12px 10px;
    background: #fff;
}
.tree-loading {
    padding: 40px 16px;
    text-align: center;
    color: var(--studio-text-muted);
    font-size: 13px;
}
.tree-node {
    user-select: none;
}
.tree-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 13px;
    cursor: pointer;
    color: #334155;
    transition: background 0.15s, color 0.15s;
}
.tree-row:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.tree-row.active {
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 600;
}
.tree-row .tree-icon {
    width: 12px;
    height: 12px;
    line-height: 10px;
    text-align: center;
    font-size: 18px;
    color: var(--studio-text-muted);
}
.tree-row .tree-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tree-row .tree-meta {
    font-size: 11px;
    color: #94a3b8;
}
.tree-children {
    padding-left: 16px;
}

.sidebar-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--studio-border);
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--studio-text-muted);
}

/* 右侧核心面板卡片 (Light Cards) */
.studio-main-panel {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.studio-card {
    background: var(--studio-card-bg);
    border-radius: 16px;
    overflow: hidden;
}
.card-head {
    padding: 20px 24px;
    border-bottom: 1px solid var(--studio-border);
}
.card-title {
    display: flex;
    align-items: center;
    gap: 0 12px;
}
.card-title h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
    color: #0f172a;
}
.step-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #333;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.card-desc {
    font-size: 13px;
    color: var(--studio-text-muted);
    display: block;
    margin-left: 36px;
}
.card-body {
    padding: 24px;
}

/* 参数配置网格 */
.config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}
.form-item {
    display: flex;
    flex-direction: column;
    justify-content: end;
    gap: 8px;
}
.form-item-duo {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.item-label {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.label-tip {
    font-size: 11px;
    color: var(--studio-text-muted);
    font-weight: normal;
}
.input-with-action {
    display: flex;
    gap: 8px;
}
.btn-sub-action {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #475569;
    line-height: 35px;
    border-radius: 8px;
    padding: 0 12px;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-sub-action:hover {
    color: #0f172a;
    background: #e2e8f0;
}

/* 预设任务卡片网格 (Light Preset Tasks) */
.preset-tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}
.task-card {
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s, background-color 0.2s, box-shadow 0.2s;
}
.task-card:hover {
    background-color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 5px 25px -5px rgba(125, 125, 125, 0.2);
}
.task-card-highlight {
    background: linear-gradient(145deg, #f5f3ff 0%, #faf5ff 100%);
}
.task-card-highlight:hover {
    box-shadow: 0 5px 25px -5px rgba(128, 95, 107, 0.43);
}
.task-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
    line-height: normal;
}
.task-icon-circle {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
}
.bg-purple { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
.bg-blue { background: linear-gradient(135deg, #0284c7, #06b6d4); }
.bg-gradient { background: linear-gradient(135deg, #f59e0b, #ef4444); }
.bg-indigo { background: linear-gradient(135deg, #4f46e5, #4338ca); }

.task-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
}
.task-tag {
    font-size: 11px;
    color: var(--studio-text-muted);
}
.badge-recommended {
    color: #b45309;
    font-weight: 600;
}
.task-feature-list {
    list-style: none;
    margin: 0 0 20px 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
    color: #475569;
    line-height: 1.5;
}
.task-feature-list li {
    display: flex;
    align-items: flex-start;
    gap: 0 8px;
    flex-wrap: wrap;
}
.task-actions {
    margin-top: auto;
}
.task-actions .studio-btn {
    width: 100%;
    justify-content: center;
    padding: 11px 16px;
    font-size: 14px;
}

/* 控制台日志卡片 (Light Clean Dev Console) */
.studio-terminal-card {
    background: #fff;
}
.terminal-head {
    padding: 12px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--studio-border);
}
.terminal-title {
    font-size: 12px;
    color: #475569;
    font-family: monospace;
    font-weight: 600;
}
.btn-terminal-clear {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 12px;
    cursor: pointer;
    transition: color 0.2s;
}
.btn-terminal-clear:hover {
    color: #0f172a;
}
.terminal-body {
    padding: 16px 20px;
    max-height: 240px;
    overflow-y: auto;
    font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 13px;
    line-height: 1.6;
    color: #334155;
}
.term-line {
    margin-bottom: 4px;
    word-break: break-all;
}
.term-line-success { color: #059669; font-weight: 600; }
.term-line-warn { color: #d97706; }
.term-line-error { color: #dc2626; font-weight: 600; }
.term-line-tip { color: #64748b; font-style: italic; }

/* 交付横幅 (Light Delivery Banner) */
.delivery-banner {
    background: linear-gradient(135deg, #ecfdf5 0%, #f0fdfa 100%);
    border: 1px solid #6ee7b7;
    border-radius: 16px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    animation: fadeIn 0.3s ease;
}
.delivery-content {
    display: flex;
    align-items: center;
    gap: 16px;
}
.delivery-icon {
    font-size: 36px;
}
.delivery-info h4 {
    margin: 0 0 4px 0;
    font-size: 16px;
    color: #065f46;
}
.delivery-info p {
    margin: 0;
    font-size: 13px;
    color: #047857;
}
.delivery-info code {
    background: #d1fae5;
    padding: 2px 6px;
    border-radius: 4px;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.delivery-btns {
    display: flex;
    gap: 12px;
}

/* 模态框样式 (Light Modals) */
.studio-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(4px);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.studio-modal-dialog {
    background: #fff;
    border: 1px solid var(--studio-border);
    border-radius: 16px;
    width: 100%;
    max-width: 820px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalPop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.studio-modal-lg {
    max-width: 960px;
}
.modal-header {
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-inline-end: 10px;
}
.modal-title-row h3 {
    margin: 0;
    font-size: 17px;
    color: #0f172a;
}
.modal-close-btn {
    background: transparent;
    border: none;
    font-size: 24px;
    color: var(--studio-text-muted);
    cursor: pointer;
    line-height: 1;
}
.modal-close-btn:hover {
    color: #0f172a;
}
.modal-body {
    padding: 0 24px;
    overflow-y: auto;
    flex: 1;
    background: #fff;
}
.modal-footer {
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}
.code-preview-wrap {
    background: #f8fafc;
    margin: 0 -24px;
    padding: 24px;
    max-height: 500px;
    overflow: auto;
}
.code-preview-wrap pre {
    margin: 0;
    font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 13px;
    color: #0f172a;
    line-height: 1.5;
}
.prompt-alert-banner {
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    color: #3730a3;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 16px;
    line-height: 1.5;
}
.prompt-textarea {
    width: stretch;
    min-height: 420px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 0;
    color: #0f172a;
    font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 13px;
    line-height: 1.6;
    margin: -24px;
    padding: 24px;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s, background-color 0.2s;
}
.prompt-textarea:focus {
    border: 2px solid #333 !important;
}
.file-size-info {
    margin-right: auto;
    font-size: 12px;
    color: var(--studio-text-muted);
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 991.98px) {
    .studio-icon { display: none; }
    .file-tree-container { max-height: 400px; }
    .config-grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));}
    .preset-tasks-grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .task-card:not(.task-card-highlight) { padding: 10px; padding-inline-start: 0; padding-inline-end: 0; }
    .task-card.task-card-highlight { padding: 10px; margin-inline-start: -10px; margin-inline-end: -10px; }
    .modal-footer { flex-wrap: wrap; }
}
</style>

<!-- ======================= 交互逻辑体系 JavaScript ======================= -->
<script>
(function() {
    'use strict';

    // 状态管理
    var state = {
        isDemo: <?php echo json_encode($is_demo_mode); ?>,
        ajaxUrl: <?php echo json_encode($ajax_url); ?>,
        commonNonce: <?php echo json_encode($common_nonce); ?>,
        currentTheme: <?php echo json_encode($current_select_theme); ?>,
        themes: [],
        materials: [],
        currentTreeData: [],
        activeFileNode: null,
        lastCreatedSlug: ''
    };

    // DOM 元素引用
    var dom = {
        themeSelect: document.getElementById('explorerThemeSelect'),
        sourceSelect: document.getElementById('selectSourceTheme'),
        materialSelect: document.getElementById('selectMaterialPkg'),
        selectMaterial: document.getElementById('selectMaterialPkg'),
        wrapCustomMaterialUrl: document.getElementById('wrapCustomMaterialUrl'),
        inputCustomMaterialUrl: document.getElementById('inputCustomMaterialUrl'),
        treeContainer: document.getElementById('fileTreeContainer'),
        btnRefreshTree: document.getElementById('btnRefreshTree'),
        fileSearchInput: document.getElementById('fileSearchInput'),
        treeStats: document.getElementById('treeStats'),
        pillThemeName: document.getElementById('pillThemeName'),
        pillThemeVer: document.getElementById('pillThemeVer'),
        pillThemeUsers: document.getElementById('pillThemeUsers'),
        
        inputTargetSlug: document.getElementById('inputTargetSlug'),
        inputThemeName: document.getElementById('inputThemeName'),
        inputThemeVersion: document.getElementById('inputThemeVersion'),
        inputThemeAuthor: document.getElementById('inputThemeAuthor'),
        selectThemeMode: document.getElementById('selectThemeMode'),
        localSaveBadge: document.getElementById('localSaveBadge'),
        btnResetFormDraft: document.getElementById('btnResetFormDraft'),
        
        btnRunScaffold: document.getElementById('btnRunScaffold'),
        btnRunGenerateSpec: document.getElementById('btnRunGenerateSpec'),
        btnRunOnekey: document.getElementById('btnRunOnekey'),
        btnViewPrompt: document.getElementById('btnViewPrompt'),
        btnHelpMaterial: document.getElementById('btnHelpMaterial'),
        
        terminal: document.getElementById('studioTerminal'),
        btnClearConsole: document.getElementById('btnClearConsole'),
        deliveryBanner: document.getElementById('deliveryBanner'),
        deliveryTitle: document.getElementById('deliveryTitle'),
        deliverySub: document.getElementById('deliverySub'),
        deliveryPath: document.getElementById('deliveryPath'),
        btnDownloadZip: document.getElementById('btnDownloadZip'),
        btnModalDownloadZip: document.getElementById('btnModalDownloadZip'),
        
        filePreviewModal: document.getElementById('filePreviewModal'),
        previewModalTitle: document.getElementById('previewModalTitle'),
        previewCodeContent: document.getElementById('previewCodeContent'),
        previewImgElem: document.getElementById('previewImgElem'),
        previewImageWrap: document.getElementById('previewImageWrap'),
        previewFileSize: document.getElementById('previewFileSize'),
        btnClosePreviewModal: document.getElementById('btnClosePreviewModal'),
        btnConfirmClosePreview: document.getElementById('btnConfirmClosePreview'),
        btnCopyFileContent: document.getElementById('btnCopyFileContent'),
        
        promptModal: document.getElementById('promptModal'),
        promptTextarea: document.getElementById('promptTextarea'),
        btnClosePromptModal: document.getElementById('btnClosePromptModal'),
        btnCopyPromptToClipboard: document.getElementById('btnCopyPromptToClipboard')
    };

    // =========================================================
    // 本地临时保存 (LocalStorage Draft) 状态管理
    // =========================================================
    var STORAGE_CONFIG_KEY = 'todocms_theme_studio_form_draft_v1';
    var saveDraftTimer = null;

    // 读取本地配置草稿数据
    function getStoredDraft() {
        if (!window.localStorage) return null;
        try {
            var raw = localStorage.getItem(STORAGE_CONFIG_KEY);
            if (!raw) return null;
            var data = JSON.parse(raw);
            return (data && typeof data === 'object') ? data : null;
        } catch(e) {
            return null;
        }
    }

    // 保存核心配置表单到本地 localStorage
    function saveFormDraft() {
        if (!window.localStorage) return;
        var data = {
            source_theme: dom.sourceSelect ? dom.sourceSelect.value : 'default',
            material_pkg: dom.materialSelect ? dom.materialSelect.value : '',
            custom_material_url: dom.inputCustomMaterialUrl ? dom.inputCustomMaterialUrl.value.trim() : '',
            target_slug: dom.inputTargetSlug ? dom.inputTargetSlug.value : '',
            theme_mode: dom.selectThemeMode ? dom.selectThemeMode.value : 'pc_mobile',
            theme_name: dom.inputThemeName ? dom.inputThemeName.value : '',
            theme_version: dom.inputThemeVersion ? dom.inputThemeVersion.value : '1.0.0',
            theme_author: dom.inputThemeAuthor ? dom.inputThemeAuthor.value : 'TodoCMS AI Studio',
            updated_at: new Date().getTime()
        };
        try {
            localStorage.setItem(STORAGE_CONFIG_KEY, JSON.stringify(data));
            if (dom.localSaveBadge) {
                dom.localSaveBadge.style.display = 'inline-block';
            }
        } catch(e) {}
    }

    // 防抖自动保存
    function scheduleSaveFormDraft() {
        if (saveDraftTimer) clearTimeout(saveDraftTimer);
        saveDraftTimer = setTimeout(saveFormDraft, 200);
    }

    // 同步控制“自定义参考 URL”输入框显示与隐藏
    function syncCustomMaterialUrlVisibility() {
        var isCustom = dom.materialSelect && dom.materialSelect.value === '__custom_url__';
        if (dom.wrapCustomMaterialUrl) {
            dom.wrapCustomMaterialUrl.style.display = isCustom ? 'block' : 'none';
            if (isCustom && dom.inputCustomMaterialUrl && !dom.inputCustomMaterialUrl.value) {
                dom.inputCustomMaterialUrl.focus();
            }
        }
    }

    // 页面初次加载时回填静态字段（文本输入框与模式选择）
    function restoreStaticFormDraft() {
        var draft = getStoredDraft();
        if (!draft) return;

        if (draft.target_slug !== undefined && dom.inputTargetSlug) {
            dom.inputTargetSlug.value = draft.target_slug;
        }
        if (draft.custom_material_url !== undefined && dom.inputCustomMaterialUrl) {
            dom.inputCustomMaterialUrl.value = draft.custom_material_url;
        }
        if (draft.theme_mode !== undefined && dom.selectThemeMode) {
            dom.selectThemeMode.value = draft.theme_mode;
        }
        if (draft.theme_name !== undefined && dom.inputThemeName) {
            dom.inputThemeName.value = draft.theme_name;
        }
        if (draft.theme_version !== undefined && dom.inputThemeVersion) {
            dom.inputThemeVersion.value = draft.theme_version;
        }
        if (draft.theme_author !== undefined && dom.inputThemeAuthor) {
            dom.inputThemeAuthor.value = draft.theme_author;
        }
        if (dom.localSaveBadge) {
            dom.localSaveBadge.style.display = 'inline-block';
        }
    }

    // 清除草稿并重置表单为默认值
    function resetFormDraft() {
        if (!confirm('确定要清除本地保存的配置草稿并重置为初始默认值吗？')) {
            return;
        }
        try {
            if (window.localStorage) {
                localStorage.removeItem(STORAGE_CONFIG_KEY);
            }
        } catch(e) {}

        if (dom.inputTargetSlug) dom.inputTargetSlug.value = '';
        if (dom.inputCustomMaterialUrl) dom.inputCustomMaterialUrl.value = '';
        if (dom.inputThemeName) dom.inputThemeName.value = '';
        if (dom.inputThemeVersion) dom.inputThemeVersion.value = '1.0.0';
        if (dom.inputThemeAuthor) dom.inputThemeAuthor.value = 'TodoCMS AI Studio';
        if (dom.selectThemeMode) dom.selectThemeMode.value = 'pc_mobile';
        if (dom.sourceSelect) dom.sourceSelect.value = 'default';
        if (dom.materialSelect) {
            var demoOpt = dom.materialSelect.querySelector('option[value="demo-corporate"]');
            dom.materialSelect.value = demoOpt ? 'demo-corporate' : '';
        }
        syncCustomMaterialUrlVisibility();
        if (dom.localSaveBadge) {
            dom.localSaveBadge.style.display = 'none';
        }
        log('ℹ️ 已清除本地暂存草稿，表单已重置为系统默认配置。', 'info');
    }

    // 控制台日志输出工具
    function log(text, type) {
        type = type || 'info';
        var line = document.createElement('div');
        line.className = 'term-line term-line-' + type;
        var time = new Date().toTimeString().split(' ')[0];
        line.innerHTML = '<span style="color:#64748b;">[' + time + ']</span> ' + text;
        dom.terminal.appendChild(line);
        dom.terminal.scrollTop = dom.terminal.scrollHeight;
    }

    // 通用 AJAX 请求封装
    function request(action, params) {
        params = params || {};
        var fd = new FormData();
        fd.append('studio_action', action);
        fd.append('_todononce', state.commonNonce);

        for (var key in params) {
            if (params.hasOwnProperty(key)) {
                fd.append(key, params[key]);
            }
        }

        return fetch(state.ajaxUrl, {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            return res.json();
        });
    }

    // 初始化工作区
    function initWorkspace() {
        log('正在初始化主题工坊工作区配置...', 'info');
        request('get_workspace_meta')
            .then(function(res) {
                if (!res.success) {
                    log('获取工作区数据失败: ' + (res.message || '未知错误'), 'error');
                    return;
                }

                state.themes = res.data.themes || [];
                state.materials = res.data.materials || [];

                // 渲染主题下拉
                renderThemesDropdown(res.data.active_theme);
                // 渲染素材包下拉
                renderMaterialsDropdown();

                // 首次加载文件树
                loadThemeTree(state.currentTheme);
                log('工作区初始化完成，对标基准主题就绪。', 'success');
            })
            .catch(function(err) {
                log('网络通信异常: ' + err.message, 'error');
            });
    }

    // 渲染主题下拉框
    function renderThemesDropdown(activeTheme) {
        dom.themeSelect.innerHTML = '';
        dom.sourceSelect.innerHTML = '';

        var foundCurrent = false;
        var draft = getStoredDraft();
        var targetSourceTheme = (draft && draft.source_theme !== undefined) ? draft.source_theme : 'default';

        state.themes.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t.slug;
            opt.textContent = t.name + (t.is_active ? ' [当前激活]' : '');
            if (t.slug === state.currentTheme) {
                opt.selected = true;
                foundCurrent = true;
            }
            dom.themeSelect.appendChild(opt);

            // 渲染对标源主题下拉
            var srcOpt = document.createElement('option');
            srcOpt.value = t.slug;
            srcOpt.textContent = t.name + (t.is_official ? ' ⭐官方原' : '');
            if (t.slug === targetSourceTheme) {
                srcOpt.selected = true;
            }
            dom.sourceSelect.appendChild(srcOpt);
        });

        if (dom.sourceSelect && targetSourceTheme) {
            dom.sourceSelect.value = targetSourceTheme;
        }

        if (!foundCurrent && dom.themeSelect.options.length > 0) {
            state.currentTheme = dom.themeSelect.options[0].value;
            dom.themeSelect.options[0].selected = true;
        }

        updateThemeMetaPills();
    }

    // 渲染素材包下拉
    function renderMaterialsDropdown() {
        dom.materialSelect.innerHTML = '';
        var draft = getStoredDraft();
        var hasSavedMaterial = draft && draft.material_pkg !== undefined;
        var targetMaterial = hasSavedMaterial ? draft.material_pkg : 'demo-corporate';

        var defOpt = document.createElement('option');
        defOpt.value = '';
        defOpt.textContent = '-- 无参考素材 (纯净骨架) --';
        if (targetMaterial === '') {
            defOpt.selected = true;
        }
        dom.materialSelect.appendChild(defOpt);

        state.materials.forEach(function(m) {
            var opt = document.createElement('option');
            opt.value = m.slug;
            opt.textContent = '📦 ' + m.name;
            if (m.slug === targetMaterial) {
                opt.selected = true;
            }
            dom.materialSelect.appendChild(opt);
        });

        // 最后一个选项：自定义参考 URL (强对标外部站点/原型)
        var customOpt = document.createElement('option');
        customOpt.value = '__custom_url__';
        customOpt.textContent = '🌐 自定义参考 URL (强对标外部站点/原型)';
        if (targetMaterial === '__custom_url__') {
            customOpt.selected = true;
        }
        dom.materialSelect.appendChild(customOpt);

        if (dom.materialSelect && targetMaterial !== undefined) {
            dom.materialSelect.value = targetMaterial;
        }

        syncCustomMaterialUrlVisibility();
    }

    // 更新左侧主题元数据徽章
    function updateThemeMetaPills() {
        var current = state.themes.find(function(t) { return t.slug === state.currentTheme; });
        if (current) {
            dom.pillThemeName.textContent = current.slug;
            dom.pillThemeVer.textContent = 'v' + (current.version || '1.0.0');
            dom.pillThemeUsers.style.display = current.has_users ? 'inline-block' : 'none';
        }
    }

    // 加载指定主题的文件树
    function loadThemeTree(themeSlug) {
        dom.treeContainer.innerHTML = '<div class="tree-loading">正在加载 [' + themeSlug + '] 文件树...</div>';
        
        request('get_theme_tree', { theme: themeSlug })
            .then(function(res) {
                if (!res.success) {
                    dom.treeContainer.innerHTML = '<div class="tree-loading" style="color:#ef4444;">无法读取文件: ' + (res.message || '') + '</div>';
                    return;
                }
                state.currentTreeData = res.data || [];
                renderTree(state.currentTreeData);
                updateStats(state.currentTreeData);
            })
            .catch(function(err) {
                dom.treeContainer.innerHTML = '<div class="tree-loading" style="color:#ef4444;">加载失败: ' + err.message + '</div>';
            });
    }

    // 渲染树状结构
    function renderTree(treeData, filterText) {
        filterText = (filterText || '').toLowerCase().trim();
        dom.treeContainer.innerHTML = '';

        if (!treeData || treeData.length === 0) {
            dom.treeContainer.innerHTML = '<div class="tree-loading">该主题目录为空</div>';
            return;
        }

        var frag = document.createDocumentFragment();

        function buildNode(item) {
            if (filterText && !matchesFilter(item, filterText)) {
                return null;
            }

            var nodeDiv = document.createElement('div');
            nodeDiv.className = 'tree-node';

            var rowDiv = document.createElement('div');
            rowDiv.className = 'tree-row';
            rowDiv.dataset.path = item.path;
            rowDiv.dataset.isDir = item.is_dir ? '1' : '0';

            // 文件夹状态指示器，无需任何图标库（仅当是文件夹时创建并追加）
            var iconSpan = null;
            if (item.is_dir) {
                iconSpan = document.createElement('span');
                iconSpan.className = 'tree-icon';
                iconSpan.textContent = '▾';
                rowDiv.appendChild(iconSpan);
            }

            var nameSpan = document.createElement('span');
            nameSpan.className = 'tree-name';
            nameSpan.textContent = item.name;

            var metaSpan = document.createElement('span');
            metaSpan.className = 'tree-meta';
            metaSpan.textContent = item.is_dir ? '' : formatBytes(item.size);

            rowDiv.appendChild(nameSpan);
            rowDiv.appendChild(metaSpan);

            nodeDiv.appendChild(rowDiv);

            if (item.is_dir && item.children && item.children.length > 0) {
                var childContainer = document.createElement('div');
                childContainer.className = 'tree-children';
                var hasVisibleChild = false;

                item.children.forEach(function(child) {
                    var childNode = buildNode(child);
                    if (childNode) {
                        childContainer.appendChild(childNode);
                        hasVisibleChild = true;
                    }
                });

                if (hasVisibleChild) {
                    nodeDiv.appendChild(childContainer);
                }

                // 文件夹点击展开/折叠
                rowDiv.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isHidden = childContainer.style.display === 'none';
                    childContainer.style.display = isHidden ? 'block' : 'none';
                    if (iconSpan) {
                        iconSpan.textContent = isHidden ? '▾' : '▸';
                    }
                });
            } else if (!item.is_dir) {
                // 文件点击预览
                rowDiv.addEventListener('click', function(e) {
                    e.stopPropagation();
                    document.querySelectorAll('.tree-row.active').forEach(function(el) { el.classList.remove('active'); });
                    rowDiv.classList.add('active');
                    previewFile(item.path);
                });
            }

            return nodeDiv;
        }

        treeData.forEach(function(item) {
            var n = buildNode(item);
            if (n) {
                frag.appendChild(n);
            }
        });

        dom.treeContainer.appendChild(frag);
    }

    // 辅助过滤
    function matchesFilter(item, q) {
        if (item.name.toLowerCase().indexOf(q) !== -1 || item.path.toLowerCase().indexOf(q) !== -1) {
            return true;
        }
        if (item.is_dir && item.children) {
            for (var i = 0; i < item.children.length; i++) {
                if (matchesFilter(item.children[i], q)) {
                    return true;
                }
            }
        }
        return false;
    }


    // 字节单位格式化
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // 统计文件总数
    function updateStats(treeData) {
        var count = 0;
        function countRec(items) {
            items.forEach(function(it) {
                if (!it.is_dir) count++;
                if (it.children) countRec(it.children);
            });
        }
        countRec(treeData);
        dom.treeStats.textContent = '共 ' + count + ' 个文件';
    }

    // 文件预览
    function previewFile(relPath) {
        log('正在读取文件: ' + relPath + ' ...', 'info');
        request('read_file_content', {
            theme: state.currentTheme,
            file_path: relPath
        })
        .then(function(res) {
            if (!res.success) {
                log('读取失败: ' + res.message, 'error');
                return;
            }

            dom.previewModalTitle.textContent = res.file_name + ' (' + relPath + ')';

            if (res.is_image) {
                dom.previewCodeContent.parentElement.parentElement.style.display = 'none';
                dom.previewImageWrap.style.display = 'block';
                dom.previewImgElem.src = res.data_url;
                dom.previewFileSize.textContent = '图片类型: ' + res.mime;
            } else {
                dom.previewImageWrap.style.display = 'none';
                dom.previewCodeContent.parentElement.parentElement.style.display = 'block';
                dom.previewCodeContent.textContent = res.content;
                dom.previewFileSize.textContent = '文件大小: ' + formatBytes(res.size);
            }

            dom.filePreviewModal.style.display = 'flex';
        })
        .catch(function(err) {
            log('预览读取异常: ' + err.message, 'error');
        });
    }

    // =========================================================
    // 任务执行逻辑
    // =========================================================

    // 任务 1：构建基础主题地基包 (Scaffold)
    dom.btnRunScaffold.addEventListener('click', function() {
        if (state.isDemo) {
            return;
        }

        var targetSlug = dom.inputTargetSlug.value.trim();
        if (!targetSlug) {
            alert('请填写新主题文件夹名称 (Slug)！');
            dom.inputTargetSlug.focus();
            return;
        }

        var matSlug = dom.materialSelect ? dom.materialSelect.value : '';
        var customUrl = (dom.inputCustomMaterialUrl && matSlug === '__custom_url__') ? dom.inputCustomMaterialUrl.value.trim() : '';
        if (matSlug === '__custom_url__' && !customUrl) {
            alert('您已选择“自定义参考 URL”，请输入目标对标站点或原型网址！');
            dom.inputCustomMaterialUrl.focus();
            return;
        }

        log('🚀 触发任务 1：开始构建基础主题地基包 [' + targetSlug + '] ...', 'info');
        dom.btnRunScaffold.disabled = true;

        var params = {
            source_theme: dom.sourceSelect.value,
            target_slug: targetSlug,
            material_slug: matSlug,
            custom_material_url: customUrl,
            theme_mode: dom.selectThemeMode.value,
            theme_name: dom.inputThemeName.value.trim(),
            theme_version: dom.inputThemeVersion.value.trim(),
            theme_author: dom.inputThemeAuthor.value.trim()
        };

        request('build_scaffold', params)
            .then(function(res) {
                dom.btnRunScaffold.disabled = false;
                if (!res.success) {
                    log('构建失败: ' + res.message, 'error');
                    alert('构建失败: ' + res.message);
                    return;
                }

                if (res.logs && res.logs.length > 0) {
                    res.logs.forEach(function(l) {
                        log(l, 'success');
                    });
                }

                state.lastCreatedSlug = res.theme_slug;
                showDelivery(res.theme_slug, res.target_dir);

                // 重新刷新左侧主题列表并选中新主题
                initWorkspace();
            })
            .catch(function(err) {
                dom.btnRunScaffold.disabled = false;
                log('构建异常: ' + err.message, 'error');
            });
    });

    // 任务 2：生成并预览 AI 规格说明 JSON
    dom.btnRunGenerateSpec.addEventListener('click', function() {
        var targetSlug = dom.inputTargetSlug.value.trim() || state.currentTheme;
        var matSlug = dom.materialSelect ? dom.materialSelect.value : '';
        var customUrl = (dom.inputCustomMaterialUrl && matSlug === '__custom_url__') ? dom.inputCustomMaterialUrl.value.trim() : '';
        log('📄 触发任务 2：生成 AI 全量规格说明书 (theme-ai-spec.json) ...', 'info');

        request('generate_ai_spec', {
            theme_slug: targetSlug,
            theme_name: dom.inputThemeName.value.trim() || targetSlug,
            theme_version: dom.inputThemeVersion.value.trim(),
            theme_mode: dom.selectThemeMode.value,
            material_slug: matSlug,
            custom_material_url: customUrl
        })
        .then(function(res) {
            if (!res.success) {
                log('生成规格说明失败: ' + res.message, 'error');
                return;
            }

            dom.previewModalTitle.textContent = '全量 AI 规格说明书 (theme-ai-spec.json)';
            dom.previewImageWrap.style.display = 'none';
            dom.previewCodeContent.parentElement.parentElement.style.display = 'block';
            dom.previewCodeContent.textContent = JSON.stringify(res.spec_json, null, 2);
            dom.previewFileSize.textContent = '规格规范已就绪（严格禁止 .tsx/SPA）';
            dom.filePreviewModal.style.display = 'flex';

            log('✅ 成功生成全量规格说明书，已载入预览窗口。', 'success');
        })
        .catch(function(err) {
            log('生成异常: ' + err.message, 'error');
        });
    });

    // 任务 3：一键全流程自动化打包 (One-Key Package)
    dom.btnRunOnekey.addEventListener('click', function() {
        if (state.isDemo) {
            return;
        }

        var targetSlug = dom.inputTargetSlug.value.trim();
        if (!targetSlug) {
            alert('请填写新主题文件夹名称 (Slug)！');
            dom.inputTargetSlug.focus();
            return;
        }

        var matSlug = dom.materialSelect ? dom.materialSelect.value : '';
        var customUrl = (dom.inputCustomMaterialUrl && matSlug === '__custom_url__') ? dom.inputCustomMaterialUrl.value.trim() : '';
        if (matSlug === '__custom_url__' && !customUrl) {
            alert('您已选择“自定义参考 URL”，请输入目标对标站点或原型网址！');
            dom.inputCustomMaterialUrl.focus();
            return;
        }

        log('⚡ 触发任务 3：一键全流程自动化打包流水线启动 ...', 'warn');
        dom.btnRunOnekey.disabled = true;

        var params = {
            source_theme: dom.sourceSelect.value,
            target_slug: targetSlug,
            material_slug: matSlug,
            custom_material_url: customUrl,
            theme_mode: dom.selectThemeMode.value,
            theme_name: dom.inputThemeName.value.trim(),
            theme_version: dom.inputThemeVersion.value.trim(),
            theme_author: dom.inputThemeAuthor.value.trim()
        };

        // 第一步：先构建地基包
        request('build_scaffold', params)
            .then(function(res) {
                if (!res.success) {
                    throw new Error(res.message);
                }
                if (res.logs) {
                    res.logs.forEach(function(l) { log(l, 'info'); });
                }

                // 第二步：执行全要素 AI 采样工程包打包
                log('📦 地基构建完成，正在压缩打包全要素 AI 采样工程包 (.zip) ...', 'info');
                return request('onekey_package', { 
                    target_slug: targetSlug,
                    material_slug: matSlug,
                    custom_material_url: customUrl
                });
            })
            .then(function(pkgRes) {
                dom.btnRunOnekey.disabled = false;
                if (!pkgRes.success) {
                    log('打包失败: ' + pkgRes.message, 'error');
                    return;
                }

                log('🎉 一键打包成功！生成全要素工程包: ' + pkgRes.zip_filename + ' (' + formatBytes(pkgRes.file_size) + ')', 'success');
                showDelivery(targetSlug, 'content/themes/' + targetSlug, pkgRes.download_url);

                // 重新刷新左侧主题列表并选中新主题
                initWorkspace();
            })
            .catch(function(err) {
                dom.btnRunOnekey.disabled = false;
                log('流水线执行异常: ' + err.message, 'error');
            });
    });

    // 关闭当前窗口及父级框架的顶层加载进度条 (.admin-topbar-loading)
    function closeTopbarLoading() {
        try {
            if (window.TodoCMS && typeof window.TodoCMS.stopLoading === 'function') {
                window.TodoCMS.stopLoading();
            }
        } catch(e) {}
        try {
            if (window.parent && window.parent.TodoCMS && typeof window.parent.TodoCMS.stopLoading === 'function') {
                window.parent.TodoCMS.stopLoading();
            }
        } catch(e) {}
        try {
            var localBars = document.querySelectorAll('.admin-topbar-loading');
            for (var i = 0; i < localBars.length; i++) {
                localBars[i].style.display = 'none';
            }
        } catch(e) {}
        try {
            if (window.parent && window.parent.document) {
                var parentBars = window.parent.document.querySelectorAll('.admin-topbar-loading');
                for (var j = 0; j < parentBars.length; j++) {
                    parentBars[j].style.display = 'none';
                }
            }
        } catch(e) {}
    }

    // 安全静默下载，避免页面发生 unload 导致父框架 topbar-loading 永久运行
    function silentDownloadFile(url) {
        if (!url) return;
        var iframe = document.getElementById('studioSilentDownloadIframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'studioSilentDownloadIframe';
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
        }
        iframe.src = url;

        // 立即关闭并多频次确保关闭进度条
        closeTopbarLoading();
        setTimeout(closeTopbarLoading, 200);
        setTimeout(closeTopbarLoading, 800);
        setTimeout(closeTopbarLoading, 2000);
    }

    // 统一下载 AI 全量采样工程包
    function triggerDownloadBundle(targetSlug, materialSlug, customUrl) {
        if (state.isDemo) {
            return;
        }

        targetSlug = targetSlug || dom.inputTargetSlug.value.trim() || state.currentTheme;
        materialSlug = materialSlug || (dom.materialSelect ? dom.materialSelect.value : '');
        customUrl = customUrl !== undefined ? customUrl : ((dom.inputCustomMaterialUrl && materialSlug === '__custom_url__') ? dom.inputCustomMaterialUrl.value.trim() : '');
        log('📦 正在准备并打包当前主题 AI 全量采样工程包 (' + targetSlug + ') ...', 'info');
        return request('onekey_package', { 
            target_slug: targetSlug, 
            material_slug: materialSlug,
            custom_material_url: customUrl
        })
        .then(function(pkgRes) {
            if (!pkgRes.success) {
                log('打包失败: ' + pkgRes.message, 'error');
                alert('打包失败: ' + pkgRes.message);
                return;
            }
            log('🎉 打包成功！文件: ' + pkgRes.zip_filename + ' (' + formatBytes(pkgRes.file_size) + ')，正在启动下载...', 'success');
            showDelivery(targetSlug, 'content/themes/' + targetSlug, pkgRes.download_url);
            silentDownloadFile(pkgRes.download_url);
        })
        .catch(function(err) {
            log('打包异常: ' + err.message, 'error');
            alert('打包异常: ' + err.message);
        });
    }

    if (dom.btnDownloadZip) {
        dom.btnDownloadZip.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var downloadUrl = this.getAttribute('data-download-url');
            if (downloadUrl) {
                silentDownloadFile(downloadUrl);
            }
        });
    }

    if (dom.btnModalDownloadZip) {
        dom.btnModalDownloadZip.addEventListener('click', function() {
            triggerDownloadBundle();
        });
    }

    // 任务 4：查看并复制 Google AI Studio 投喂 Prompt
    dom.btnViewPrompt.addEventListener('click', function() {
        var targetSlug = dom.inputTargetSlug.value.trim() || state.currentTheme;
        var matSlug = (dom.materialSelect && dom.materialSelect.value) ? dom.materialSelect.value : '';
        var customUrl = (dom.inputCustomMaterialUrl && matSlug === '__custom_url__') ? dom.inputCustomMaterialUrl.value.trim() : '';
        log('📋 准备针对 Google AI Studio 的定制提示词 ...', 'info');

        request('generate_ai_spec', {
            theme_slug: targetSlug,
            theme_name: dom.inputThemeName.value.trim() || targetSlug,
            theme_version: dom.inputThemeVersion.value.trim(),
            theme_mode: dom.selectThemeMode.value,
            material_slug: matSlug,
            custom_material_url: customUrl
        })
        .then(function(res) {
            if (!res.success) {
                log('获取 Prompt 失败: ' + res.message, 'error');
                return;
            }

            dom.promptTextarea.value = res.prompt_markdown;
            dom.promptModal.style.display = 'flex';
            log('Google AI Studio 投喂 Prompt 已就绪。', 'success');
        })
        .catch(function(err) {
            log('异常: ' + err.message, 'error');
        });
    });

    // 复制 Prompt
    dom.btnCopyPromptToClipboard.addEventListener('click', function() {
        dom.promptTextarea.select();
        document.execCommand('copy');
        dom.btnCopyPromptToClipboard.innerHTML = '复制成功！已写入剪贴板';
        setTimeout(function() {
            dom.btnCopyPromptToClipboard.innerHTML = '一键复制完整 Prompt';
        }, 2000);
    });

    // 复制文件预览内容
    dom.btnCopyFileContent.addEventListener('click', function() {
        var text = dom.previewCodeContent.textContent;
        navigator.clipboard.writeText(text).then(function() {
            dom.btnCopyFileContent.innerHTML = '已复制';
            setTimeout(function() {
                dom.btnCopyFileContent.innerHTML = '复制内容';
            }, 2000);
        });
    });

    // 展示产物横幅
    function showDelivery(slug, dir, downloadUrl) {
        dom.deliveryTitle.textContent = '新主题 [' + slug + '] 基础包构建成功！';
        dom.deliveryPath.textContent = dir;
        if (downloadUrl) {
            dom.btnDownloadZip.setAttribute('data-download-url', downloadUrl);
            dom.btnDownloadZip.href = 'javascript:void(0);';
            dom.btnDownloadZip.style.display = 'inline-flex';
        } else {
            dom.btnDownloadZip.removeAttribute('data-download-url');
            dom.btnDownloadZip.style.display = 'none';
        }
        dom.deliveryBanner.style.display = 'flex';
        dom.deliveryBanner.scrollIntoView({ behavior: 'smooth' });
    }

    // 素材包提示
    dom.btnHelpMaterial.addEventListener('click', function() {
        alert('参考素材与强对标物说明：\n\n1. 本地素材包：将包含设计图片或 HTML 原型的素材目录存入 content/materials/，刷新本页即可选用。\n2. 自定义参考 URL：下拉最后一项支持输入外部站点或原型网址。\n\n【🎯 强参考核心法则】：\n一旦选定参考素材或输入对标 URL，AI 将优先直接参考目标的 DOM 层面要素与交互结构（UI 布局划分、排版网格及 JS 交互动效），再根据 Prompt 完成视觉层面风格调性色彩补充！');
    });

    // 搜索过滤文件
    dom.fileSearchInput.addEventListener('input', function(e) {
        renderTree(state.currentTreeData, e.target.value);
    });

    // 切换主题浏览
    dom.themeSelect.addEventListener('change', function() {
        state.currentTheme = this.value;
        updateThemeMetaPills();
        loadThemeTree(state.currentTheme);
    });

    // 刷新按钮
    dom.btnRefreshTree.addEventListener('click', function() {
        loadThemeTree(state.currentTheme);
    });

    // 清屏
    dom.btnClearConsole.addEventListener('click', function() {
        dom.terminal.innerHTML = '<div class="term-line term-line-info">控制台日志已清空。</div>';
    });

    // 模态框关闭
    dom.btnClosePreviewModal.addEventListener('click', function() { dom.filePreviewModal.style.display = 'none'; });
    dom.btnConfirmClosePreview.addEventListener('click', function() { dom.filePreviewModal.style.display = 'none'; });
    dom.btnClosePromptModal.addEventListener('click', function() { dom.promptModal.style.display = 'none'; });

    // =========================================================
    // 监听核心表单修改并自动保存草稿到本地 localStorage
    // =========================================================
    if (dom.inputTargetSlug) dom.inputTargetSlug.addEventListener('input', scheduleSaveFormDraft);
    if (dom.inputThemeName) dom.inputThemeName.addEventListener('input', scheduleSaveFormDraft);
    if (dom.inputThemeVersion) dom.inputThemeVersion.addEventListener('input', scheduleSaveFormDraft);
    if (dom.inputThemeAuthor) dom.inputThemeAuthor.addEventListener('input', scheduleSaveFormDraft);
    if (dom.selectThemeMode) dom.selectThemeMode.addEventListener('change', scheduleSaveFormDraft);
    if (dom.sourceSelect) dom.sourceSelect.addEventListener('change', scheduleSaveFormDraft);
    if (dom.materialSelect) {
        dom.materialSelect.addEventListener('change', function() {
            syncCustomMaterialUrlVisibility();
            scheduleSaveFormDraft();
        });
    }
    if (dom.inputCustomMaterialUrl) dom.inputCustomMaterialUrl.addEventListener('input', scheduleSaveFormDraft);
    if (dom.btnResetFormDraft) dom.btnResetFormDraft.addEventListener('click', resetFormDraft);

    // 页面初次加载时回填本地暂存的静态字段
    restoreStaticFormDraft();

    // 启动初始化
    initWorkspace();

})();
</script>

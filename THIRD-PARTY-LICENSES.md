# 第三方开源软件授权说明 (Third-Party Licenses)

本文档说明了 TodoCMS 及其相关模块中使用的第三方开源组件及其授权协议。

## 1. 项目授权概述

TodoCMS 核心代码及其专有功能采用 **Proprietary (私有)** 授权协议。本软件中包含的第三方开源组件均根据其各自的开源协议进行分发。

---

## 2. 第三方组件清单

### 2.1 UEditor 附属组件
位于 `admin/assets/js/ueditor/third-party/` 目录下。

| 组件名称 | 版本 | 授权协议 | 备注 |
| :--- | :--- | :--- | :--- |
| **SyntaxHighlighter** | v3.0.83 | 双重授权 MIT / GPL | 本项目明确选择 MIT 协议使用。 |
| **WebUploader** | v0.1.2 | MIT / BSD | 百度开源文件上传组件。 |
| **Video.js** | v4.3.0 | Apache 2.0 | Brightcove 开源视频播放器。 |
| **ZeroClipboard** | v2.0.0 | MIT | 剪贴板工具。 |
| **CodeMirror** | v2.2 | MIT | 代码编辑器。 |
| **xss.js** | — | MIT | XSS 内容过滤工具。 |

### 2.2 前端基础库
位于 `admin/assets/js/` 及各主题目录下。

| 组件名称 | 版本 | 授权协议 | 备注 |
| :--- | :--- | :--- | :--- |
| **jQuery** | v3.6.0 | MIT | OpenJS Foundation。 |
| **jQuery UI** | v1.13.2 | MIT | 文件头明确标注 "Licensed MIT"。 |
| **Bootstrap** | v5.1.3 | MIT | Twitter, Inc. |

### 2.3 主题媒体播放库
位于 `content/themes/default/pc/assets/js/` 目录下。

| 组件名称 | 版本 | 授权协议 | 备注 |
| :--- | :--- | :--- | :--- |
| **dash.js** | v4.7.4 | Apache 2.0 | DASH 自适应流媒体播放。 |
| **flv.js** | v1.6.2 | Apache 2.0 | FLV 格式播放（Bilibili 开源）。 |
| **hls.js** | v1.4.10 | Apache 2.0 | HLS 流媒体播放。 |

### 2.4 后端依赖（Composer）
位于 `vendor/` 目录下。

| 组件名称 | 授权协议 | 备注 |
| :--- | :--- | :--- |
| **Composer Autoloader** | MIT | Nils Adermann, Jordi Boggiano。 |

> ⚠️ **重要说明**：经审计，`composer.json` 未声明任何外部 `require` 依赖，`vendor/` 目录中仅存在 Composer 官方的类自动加载器（MIT 协议），不存在任何第三方 PHP 包。

---

## 3. GPL 传染性风险评估

经对全项目代码库（`core/`、`includes/`、`admin/`、`content/`、`vendor/`）中的全部 `.js`、`.css`、`.php`、`.json`、`.md` 文件进行系统扫描，结果如下：

| 检查项目 | 扫描结果 | 风险等级 |
| :--- | :--- | :--- |
| 核心 PHP 业务代码（`core/`、`includes/`）含 GPL | ✅ 未发现 | 无风险 |
| Composer 依赖包含 GPL | ✅ 无外部 PHP 依赖包 | 无风险 |
| SyntaxHighlighter（MIT/GPL 双重授权）| ⚠️ 含 GPL 选项 | **低风险**（已选择 MIT）|
| jQuery UI（MIT）| ✅ MIT 授权 | 无风险 |
| Video.js（Apache 2.0）| ✅ Apache 2.0 | 无风险 |
| ZeroClipboard / CodeMirror / xss.js | ✅ MIT | 无风险 |
| 媒体播放库（dash / flv / hls）| ✅ Apache 2.0 | 无风险 |
| AGPL / Affero GPL | ✅ 未发现 | 无风险 |

**综合结论：项目中不存在 GPL 传染性风险，无需强制开源任何专有代码。**

---

## 4. 兼容性声明

对于提供双重或多重授权（如同时提供 MIT 与 GPL）的第三方组件，TodoCMS 均明确选择以 **MIT 协议**进行集成。

具体声明：

- **SyntaxHighlighter v3.0.83**（Copyright © 2004-2010 Alex Gorbatchev）  
  该组件双重授权于 MIT 和 GPL。**本项目选择 MIT 协议使用该组件。**

此举旨在确保 TodoCMS 核心业务代码的私有性，不受 GPL 等具有"传染性"（Copyleft）开源协议的约束。

---

*审计完成日期：2026-05-08*  
*审计范围：全项目 .js / .css / .php / .json / .md 文件，含 AGPL 及 Copyleft 关键词扫描*

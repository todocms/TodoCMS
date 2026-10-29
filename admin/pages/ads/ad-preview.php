<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 广告预览页面
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    todo_die('Direct access not permitted');
}

// 初始化广告管理器
$ads_manager = Todo_Ads_Manager::init();

// 检查权限
if (!current_user_can('manage_ads')) {
    todo_die('您没有管理广告的权限。');
}

// 获取广告ID
$ad_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ad_id <= 0) {
    todo_die('无效的广告ID。');
}

// 获取广告信息
$ad = Todo_Ads_Manager::get_ad($ad_id);
if (!$ad) {
    todo_die('广告不存在。');
}
// 转换为对象格式
$ad = (object)$ad;

$content_decoded = array();
if (!empty($ad->content)) {
    $decoded = json_decode((string) $ad->content, true);
    if (is_array($decoded)) {
        $content_decoded = $decoded;
    }
}

$image_groups = array();
$media_groups = array();
if (!empty($content_decoded)) {
    if (isset($content_decoded['type'])) {
        if ($content_decoded['type'] === 'image_groups' && !empty($content_decoded['groups']) && is_array($content_decoded['groups'])) {
            $image_groups = $content_decoded['groups'];
        }
        if ($content_decoded['type'] === 'media_groups' && !empty($content_decoded['groups']) && is_array($content_decoded['groups'])) {
            $media_groups = $content_decoded['groups'];
        }
    } elseif (!empty($content_decoded['groups']) && is_array($content_decoded['groups'])) {
        $maybe_groups = $content_decoded['groups'];
        $first = isset($maybe_groups[0]) && is_array($maybe_groups[0]) ? $maybe_groups[0] : array();
        if (isset($first['images'])) {
            $image_groups = $maybe_groups;
        } elseif (isset($first['medias'])) {
            $media_groups = $maybe_groups;
        }
    }
}

$fallback_images = array();
if (!empty($ad->image_url)) {
    $fallback_images[] = array('url' => (string) $ad->image_url, 'title' => (string) ($ad->title ?? ''), 'alt' => (string) ($ad->title ?? ''));
}
if (!empty($ad->images)) {
    $decoded_images = json_decode((string) $ad->images, true);
    if (is_array($decoded_images)) {
        foreach ($decoded_images as $it) {
            if (is_string($it)) {
                $u = trim($it);
                if ($u !== '') {
                    $fallback_images[] = array('url' => $u, 'title' => '', 'alt' => '');
                }
            } elseif (is_array($it) && !empty($it['url'])) {
                $fallback_images[] = array(
                    'url' => (string) $it['url'],
                    'title' => isset($it['title']) ? (string) $it['title'] : '',
                    'alt' => isset($it['alt']) ? (string) $it['alt'] : '',
                );
            }
        }
    }
}

$video_url = '';
$video_type = 'mp4';
if ($ad->type === 'video') {
    if (!empty($content_decoded) && !empty($content_decoded['video_url'])) {
        $video_url = (string) $content_decoded['video_url'];
        if (!empty($content_decoded['video_type'])) {
            $video_type = (string) $content_decoded['video_type'];
        }
    } elseif (!empty($ad->content) && is_string($ad->content)) {
        $maybe_url = trim((string) $ad->content);
        if ($maybe_url !== '' && preg_match('#^https?://#i', $maybe_url)) {
            $video_url = $maybe_url;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(strtolower(function_exists('current_locale') ? current_locale() : 'zh-CN')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="generator" content="TodoCMS">
    <title>广告预览 - <?php echo esc_html($ad->title); ?></title>
    <style>
        .preview-container {
            margin: 0 auto 20px;
            background: #fff;
            padding: 20px;
            border-radius: 3px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        }
        .preview-header {
            margin-bottom: 15px;
        }
        .preview-header h1 {
            margin: 0;
            font-size: 24px;
            color: #23282d;
        }
        .preview-info {
            background: #f9f9f9;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .preview-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .preview-info th,
        .preview-info td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .preview-info th {
            background: #f1f1f1;
            font-weight: 500;
            width: 120px;
        }
        .ad-preview {
            border: 1px dashed #ddd;
            padding: 20px;
            text-align: center;
            border-radius: 4px;
        }
        .ad-image {
            max-width: 100%;
            height: auto;
            border-radius: 4px;
        }
        .ad-text {
            font-size: 16px;
            line-height: 1.5;
            color: #333;
        }
        .ad-code {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
            white-space: pre-line;
            text-align: left;
        }
        .ad-video {
            max-width: 100%;
            height: auto;
        }
        .ad-group {
            margin: 0 0 15px 0;
            padding: 12px;
            text-align: left;
        }
        .ad-group-title {
            font-weight: 500;
            margin: 0 0 10px 0;
            color: #23282d;
        }
        .ad-media-grid,
        .ad-image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 10px;
        }
        .ad-image-item,
        .ad-media-item {
            border: 1px solid #eee;
            border-radius: 4px;
            padding: 8px;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
        }
        .ad-image-item img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 4px;
        }
        .ad-item-meta {
            margin-bottom: 10px;
            font-size: 12px;
            color: #666;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="preview-header">
            <h3>广告预览</h3>
        </div>

        <div class="preview-info">
            <table>
                <tr>
                    <th>广告名称</th>
                    <td><?php echo esc_html($ad->title); ?></td>
                </tr>

                <tr>
                    <th>广告类型</th>
                    <td>
                        <?php 
                        $types = array(
                            'image' => '图片广告',
                            'text' => '文字广告',
                            'code' => '代码广告',
                            'video' => '视频广告',
                            'media' => '多媒体广告',
                            'gallery' => '轮播广告'
                        );
                        echo isset($types[$ad->type]) ? $types[$ad->type] : $ad->type;
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>状态</th>
                    <td>
                        <?php $astatus = intval($ad->status); ?>
                        <span class="status-<?php echo $astatus === 1 ? 'active' : 'inactive'; ?>">
                            <?php echo $astatus === 1 ? '激活' : '停用'; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>排序</th>
                    <td><?php echo intval($ad->sort_order); ?></td>
                </tr>
                <?php if ($ad->start_date): ?>
                <tr>
                    <th>开始时间</th>
                    <td><?php echo date('Y-m-d H:i', strtotime($ad->start_date)); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($ad->end_date): ?>
                <tr>
                    <th>结束时间</th>
                    <td><?php echo date('Y-m-d H:i', strtotime($ad->end_date)); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <div class="ad-preview">
            <h3>广告效果预览</h3>
            
            <?php if ($ad->type === 'image'): ?>
                <?php if (!empty($image_groups)): ?>
                    <?php foreach ($image_groups as $idx => $grp): ?>
                        <?php
                        $grp = is_array($grp) ? $grp : array();
                        $gt = isset($grp['title']) ? (string) $grp['title'] : '';
                        $gl = isset($grp['link']) ? (string) $grp['link'] : '';
                        $tg = isset($grp['target']) ? (string) $grp['target'] : '';
                        if ($tg !== '_blank') { $tg = '_self'; }
                        $imgs = isset($grp['images']) && is_array($grp['images']) ? $grp['images'] : array();
                        $gcontent = isset($grp['content']) ? (string) $grp['content'] : '';
                        ?>
                        <div class="ad-group">
                            <div class="ad-group-title"><?php echo esc_html($gt !== '' ? $gt : ('图片组 #' . ($idx + 1))); ?></div>
                            <?php if ($gcontent !== ''): ?>
                                <div class="ad-item-meta"><?php echo function_exists('todo_kses_post') ? todo_kses_post($gcontent) : esc_html($gcontent); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($imgs)): ?>
                                <div class="ad-image-grid">
                                    <?php foreach ($imgs as $it): ?>
                                        <?php
                                        $it = is_array($it) ? $it : array();
                                        $u = isset($it['url']) ? trim((string) $it['url']) : '';
                                        if ($u === '') { continue; }
                                        $tt = isset($it['title']) ? (string) $it['title'] : '';
                                        $alt = isset($it['alt']) ? (string) $it['alt'] : '';
                                        $final_alt = $alt !== '' ? $alt : ($tt !== '' ? $tt : (string) ($ad->title ?? ''));
                                        ?>
                                        <div class="ad-image-item">
                                            <?php if ($gl !== ''): ?>
                                                <a href="<?php echo esc_url($gl); ?>" target="<?php echo esc_attr($tg); ?>">
                                                    <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr($final_alt); ?>">
                                                </a>
                                            <?php else: ?>
                                                <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr($final_alt); ?>">
                                            <?php endif; ?>
                                            <?php if ($tt !== ''): ?>
                                                <div class="ad-item-meta"><?php echo esc_html($tt); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p>未设置图片</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (!empty($fallback_images)): ?>
                    <div class="ad-image-grid">
                        <?php foreach ($fallback_images as $it): ?>
                            <?php
                            $it = is_array($it) ? $it : array();
                            $u = isset($it['url']) ? trim((string) $it['url']) : '';
                            if ($u === '') { continue; }
                            $tt = isset($it['title']) ? (string) $it['title'] : '';
                            $alt = isset($it['alt']) ? (string) $it['alt'] : '';
                            $final_alt = $alt !== '' ? $alt : ($tt !== '' ? $tt : (string) ($ad->title ?? ''));
                            ?>
                            <div class="ad-image-item">
                                <?php if (!empty($ad->link_url)): ?>
                                    <a href="<?php echo esc_url($ad->link_url); ?>" target="<?php echo esc_attr($ad->target); ?>">
                                        <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr($final_alt); ?>">
                                    </a>
                                <?php else: ?>
                                    <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr($final_alt); ?>">
                                <?php endif; ?>
                                <?php if ($tt !== ''): ?>
                                    <div class="ad-item-meta"><?php echo esc_html($tt); ?></div>
                                <?php endif; ?>
                                <div class="ad-item-meta"><?php echo esc_html($u); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>未设置图片</p>
                <?php endif; ?>
                
            <?php elseif ($ad->type === 'text'): ?>
                <div class="ad-text">
                    <?php if ($ad->link_url): ?>
                        <a href="<?php echo esc_url($ad->link_url); ?>" target="<?php echo esc_attr($ad->target); ?>">
                            <?php echo nl2br(esc_html($ad->content)); ?>
                        </a>
                    <?php else: ?>
                        <?php echo nl2br(esc_html($ad->content)); ?>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($ad->type === 'code'): ?>
                <?php
                // 支持结构化JSON的代码内容（html_codes.items），否则按原字符串显示
                $codes = array();
                if (!empty($ad->content)) {
                    $maybe = json_decode($ad->content, true);
                    if (is_array($maybe) && isset($maybe['type']) && $maybe['type'] === 'html_codes' && !empty($maybe['items']) && is_array($maybe['items'])) {
                        foreach ($maybe['items'] as $it) {
                            if (is_array($it) && isset($it['html'])) {
                                $codes[] = (string)$it['html'];
                            } elseif (is_string($it)) {
                                $codes[] = $it;
                            }
                        }
                    }
                }
                if (empty($codes)) {
                    $codes = array((string)$ad->content);
                }
                ?>
                <?php foreach ($codes as $idx => $code): ?>
                    <div class="ad-code" style="margin-top: 20px;">
                        <div style="font-weight: 500; line-height: 0; font-size: 16px; color: #23282d;">代码 #<?php echo ($idx+1); ?></div>
                        <?php echo esc_html($code); ?>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                    <strong>注意：</strong>代码广告在实际页面中会被执行，此处仅显示代码内容。
                </div>
                
            <?php elseif ($ad->type === 'video'): ?>
                <?php if ($video_url !== ''): ?>
                    <video controls class="ad-video">
                        <source src="<?php echo esc_url($video_url); ?>" 
                                type="video/<?php echo esc_attr($video_type); ?>">
                        您的浏览器不支持视频播放。
                    </video>
                    <?php if ($ad->link_url): ?>
                        <p><a href="<?php echo esc_url($ad->link_url); ?>" target="<?php echo esc_attr($ad->target); ?>">点击查看更多</a></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>未设置视频URL</p>
                <?php endif; ?>
            
            <?php elseif ($ad->type === 'media'): ?>
                <?php if (!empty($media_groups)): ?>
                    <?php foreach ($media_groups as $idx => $grp): ?>
                        <?php
                        $grp = is_array($grp) ? $grp : array();
                        $gt = isset($grp['title']) ? (string) $grp['title'] : '';
                        $gl = isset($grp['link']) ? (string) $grp['link'] : '';
                        $tg = isset($grp['target']) ? (string) $grp['target'] : '';
                        if ($tg !== '_blank') { $tg = '_self'; }
                        $items = isset($grp['medias']) && is_array($grp['medias']) ? $grp['medias'] : array();
                        $gcontent = isset($grp['content']) ? (string) $grp['content'] : '';
                        ?>
                        <div class="ad-group">
                            <div class="ad-group-title"><?php echo esc_html($gt !== '' ? $gt : ('媒体组 #' . ($idx + 1))); ?></div>
                            <?php if ($gcontent !== ''): ?>
                                <div class="ad-item-meta"><?php echo function_exists('todo_kses_post') ? todo_kses_post($gcontent) : esc_html($gcontent); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($items)): ?>
                                <div class="ad-media-grid">
                                    <?php foreach ($items as $it): ?>
                                        <?php
                                        $it = is_array($it) ? $it : array();
                                        $u = isset($it['url']) ? trim((string) $it['url']) : '';
                                        if ($u === '') { continue; }
                                        $ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                                        ?>
                                        <div class="ad-media-item">
                                            <?php if (in_array($ext, array('mp4', 'webm', 'ogg'), true)): ?>
                                                <?php if ($gl !== ''): ?><a href="<?php echo esc_url($gl); ?>" target="<?php echo esc_attr($tg); ?>"><?php endif; ?>
                                                <video controls class="ad-video">
                                                    <source src="<?php echo esc_url($u); ?>" type="video/<?php echo esc_attr($ext !== '' ? $ext : 'mp4'); ?>">
                                                </video>
                                                <?php if ($gl !== ''): ?></a><?php endif; ?>
                                            <?php elseif (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'), true)): ?>
                                                <?php if ($gl !== ''): ?><a href="<?php echo esc_url($gl); ?>" target="<?php echo esc_attr($tg); ?>"><?php endif; ?>
                                                <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr((string) ($ad->title ?? '')); ?>">
                                                <?php if ($gl !== ''): ?></a><?php endif; ?>
                                                <div class="ad-item-meta"><?php echo esc_html($u); ?></div>
                                            <?php else: ?>
                                                <a href="<?php echo esc_url($u); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($u); ?></a>
                                                <?php if ($gl !== ''): ?>
                                                    <div class="ad-item-meta">
                                                        <a href="<?php echo esc_url($gl); ?>" target="<?php echo esc_attr($tg); ?>">打开跳转链接</a>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p>未设置媒体</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>未设置媒体内容</p>
                <?php endif; ?>
            
            <?php elseif ($ad->type === 'gallery'): ?>
                <?php if (!empty($fallback_images)): ?>
                    <div class="ad-image-grid">
                        <?php foreach ($fallback_images as $it): ?>
                            <?php
                            $it = is_array($it) ? $it : array();
                            $u = isset($it['url']) ? trim((string) $it['url']) : '';
                            if ($u === '') { continue; }
                            $tt = isset($it['title']) ? (string) $it['title'] : '';
                            $alt = isset($it['alt']) ? (string) $it['alt'] : '';
                            $final_alt = $alt !== '' ? $alt : ($tt !== '' ? $tt : (string) ($ad->title ?? ''));
                            ?>
                            <div class="ad-image-item">
                                <img src="<?php echo esc_url($u); ?>" alt="<?php echo esc_attr($final_alt); ?>">
                                <?php if ($tt !== ''): ?>
                                    <div class="ad-item-meta"><?php echo esc_html($tt); ?></div>
                                <?php endif; ?>
                                <div class="ad-item-meta"><?php echo esc_html($u); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>未设置图片</p>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

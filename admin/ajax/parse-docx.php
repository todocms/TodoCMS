<?php
/**
 * DOCX Parser for UEditor Import
 * Parses a .docx file and extracts basic text, paragraphs, tables, and images.
 */

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');
}
if (file_exists(ABSPATH . 'config.php')) {
    require_once ABSPATH . 'config.php';
}
require_once ABSPATH . 'core/bootstrap.php';

// 初始化 Session
if (class_exists('Todo_Session_Manager')) {
    Todo_Session_Manager::init();
}

header('Content-Type: application/json; charset=utf-8');

// CSRF 验证
if (class_exists('Todo_Security_Manager')) {
    if (!Todo_Security_Manager::verify_request_csrf('parse_docx', '_todononce')) {
        echo json_encode(['success' => false, 'message' => 'CSRF 验证失败。']);
        exit;
    }
}

$file_path = isset($_POST['file_path']) ? trim($_POST['file_path']) : '';
if (empty($file_path)) {
    echo json_encode(['success' => false, 'message' => '未提供文件路径。']);
    exit;
}

// 确保路径安全
$clean_path = ltrim(str_replace(['../', '..\\'], '', $file_path), '/');

// 如果路径带有包含 /content/uploads/ 的前缀（例如 todocms/content/uploads/...），剥离 /content/uploads/ 之前的部分
if (($uploads_pos = strpos($clean_path, 'content/uploads/')) !== false) {
    $clean_path = substr($clean_path, $uploads_pos);
}

$possible_paths = [
    TODO_ROOT . '/' . $clean_path,
    TODO_ROOT . '/content/uploads/' . ltrim(str_replace('content/uploads/', '', $clean_path), '/'),
];

$absolute_path = '';
foreach ($possible_paths as $p) {
    if (file_exists($p) && is_file($p)) {
        $absolute_path = $p;
        break;
    }
}

if (empty($absolute_path) || strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION)) !== 'docx') {
    echo json_encode(['success' => false, 'message' => '无效的文件或文件不存在（路径：' . $file_path . '）。']);
    exit;
}

// DOCX 解析核心类
class SimpleDocxParser {
    private $zip;
    private $relations = [];
    private $imageMap = []; // relId => public_url
    private $uploadDir;
    private $uploadUrlBase;

    public function __construct() {
        // 设置图片提取的目标目录，使用当天的日期
        $date_dir = date('Y/m/d');
        $this->uploadDir = TODO_ROOT . '/content/uploads/' . $date_dir;
        $this->uploadUrlBase = todo_get_site_url() . '/content/uploads/' . $date_dir;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function parse($filePath) {
        $this->zip = new ZipArchive();
        if ($this->zip->open($filePath) !== TRUE) {
            return false;
        }

        $this->extractRelations();
        $this->extractImages();
        
        $xmlContent = $this->zip->getFromName('word/document.xml');
        $this->zip->close();
        
        if (!$xmlContent) return '';

        return $this->parseDocumentXML($xmlContent);
    }

    private function extractRelations() {
        $relsContent = $this->zip->getFromName('word/_rels/document.xml.rels');
        if (!$relsContent) return;

        $xml = simplexml_load_string($relsContent);
        if ($xml) {
            foreach ($xml->Relationship as $rel) {
                $this->relations[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
    }

    private function extractImages() {
        foreach ($this->relations as $id => $target) {
            if (strpos($target, 'media/') !== false) {
                $targetPath = 'word/' . $target; // DOCX 内部路径
                $content = $this->zip->getFromName($targetPath);
                if ($content) {
                    // 生成唯一文件名
                    $ext = pathinfo($target, PATHINFO_EXTENSION);
                    $filename = 'word_img_' . time() . '_' . substr(md5($id . rand()), 0, 8) . '.' . $ext;
                    $savePath = $this->uploadDir . '/' . $filename;
                    file_put_contents($savePath, $content);
                    $this->imageMap[$id] = $this->uploadUrlBase . '/' . $filename;
                }
            }
        }
    }

    private function parseDocumentXML($xmlContent) {
        $dom = new DOMDocument();
        // 忽略警告，因为 OpenXML 命名空间可能导致一些问题
        @$dom->loadXML($xmlContent);
        
        $body = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'body')->item(0);
        if (!$body) return '';

        return $this->parseNode($body);
    }

    private function parseNode($node) {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $nodeName = $child->localName; // 忽略命名空间前缀
            
            if ($nodeName === 'p') {
                $pContent = $this->parseNode($child);
                if (trim($pContent) !== '' || strpos($pContent, '<img') !== false) {
                    $html .= '<p>' . $pContent . '</p>';
                } else {
                    $html .= '<p><br/></p>';
                }
            } elseif ($nodeName === 'r') {
                // 运行节点，里面有文本、图片等
                $html .= $this->parseNode($child);
            } elseif ($nodeName === 't') {
                $html .= htmlspecialchars($child->textContent, ENT_QUOTES, 'UTF-8');
            } elseif ($nodeName === 'drawing') {
                // 解析图片 (DrawingML)
                $html .= $this->parseDrawing($child);
            } elseif ($nodeName === 'pict') {
                // 解析图片 (VML - 旧版兼容)
                $html .= $this->parsePict($child);
            } elseif ($nodeName === 'tbl') {
                $html .= '<table border="1" cellspacing="0" cellpadding="0" style="border-collapse: collapse; width: 100%; margin-bottom: 15px;"><tbody>';
                $html .= $this->parseNode($child);
                $html .= '</tbody></table>';
            } elseif ($nodeName === 'tr') {
                $html .= '<tr>' . $this->parseNode($child) . '</tr>';
            } elseif ($nodeName === 'tc') {
                $html .= '<td style="border: 1px solid #ccc; padding: 5px;">' . $this->parseNode($child) . '</td>';
            } elseif ($nodeName === 'br') {
                $html .= '<br/>';
            } else {
                // 递归继续查找
                $html .= $this->parseNode($child);
            }
        }
        return $html;
    }

    private function parseDrawing($node) {
        // 寻找 a:blip 元素以获取 r:embed ID
        $blips = $node->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip');
        if ($blips->length > 0) {
            $blip = $blips->item(0);
            $rId = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
            if ($rId && isset($this->imageMap[$rId])) {
                return '<img src="' . htmlspecialchars($this->imageMap[$rId], ENT_QUOTES, 'UTF-8') . '" style="max-width: 100%;" />';
            }
        }
        return '';
    }

    private function parsePict($node) {
        // 寻找 v:imagedata 元素获取 r:id
        $imagedatas = $node->getElementsByTagNameNS('urn:schemas-microsoft-com:vml', 'imagedata');
        if ($imagedatas->length > 0) {
            $imgData = $imagedatas->item(0);
            $rId = $imgData->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($rId && isset($this->imageMap[$rId])) {
                return '<img src="' . htmlspecialchars($this->imageMap[$rId], ENT_QUOTES, 'UTF-8') . '" style="max-width: 100%;" />';
            }
        }
        return '';
    }
}

$parser = new SimpleDocxParser();
$html = $parser->parse($absolute_path);

if ($html === false) {
    echo json_encode(['success' => false, 'message' => '解析失败，无法打开 DOCX 文件。']);
} else {
    echo json_encode(['success' => true, 'data' => ['html' => $html]]);
}

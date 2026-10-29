<?php
/**
 * UEditor 文件列表处理
 */

/** @var string $action */
/** @var array $CONFIG */

/* 判断类型 */
switch ($action) {
    /* 列出文件 */
    case 'listfile':
        $allowFiles = $CONFIG['fileManagerAllowFiles'];
        $listSize = $CONFIG['fileManagerListSize'];
        $path = $CONFIG['fileManagerListPath'];
        break;
    /* 列出图片 */
    case 'listimage':
    default:
        $allowFiles = $CONFIG['imageManagerAllowFiles'];
        $listSize = $CONFIG['imageManagerListSize'];
        $path = $CONFIG['imageManagerListPath'];
}

$allowFiles = substr(str_replace(",", "|", join("", $allowFiles)), 1);

/* 获取参数 */
$size = isset($_GET['size']) ? htmlspecialchars($_GET['size']) : $listSize;
$start = isset($_GET['start']) ? htmlspecialchars($_GET['start']) : 0;
$end = $start + $size;

/* 获取文件列表 */
$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$path = $documentRoot . (substr($path, 0, 1) == "/" ? "" : "./") . $path;
if (!is_dir($path)) {
    result(array(
        "state" => "路径不存在",
        "list" => array(),
        "start" => $start,
        "total" => 0
    ));
}

$files = getfiles($path, $allowFiles);
if (!count($files)) {
    result(array(
        "state" => "no match file",
        "list" => array(),
        "start" => $start,
        "total" => count($files)
    ));
}

/* 获取指定范围的列表 */
$len = count($files);
for ($i = min($end, $len) - 1, $list = array(); $i < $len && $i >= 0 && $i >= $start; $i--){
    $list[] = $files[$i];
}

/* 返回数据 */
result(array(
    "state" => "SUCCESS",
    "list" => $list,
    "start" => $start,
    "total" => count($files)
));

/**
 * 遍历获取目录下的指定类型的文件
 * @param string $path
 * @param string $allowFiles
 * @param array $files
 * @return array|null
 */
function getfiles($path, $allowFiles, &$files = array())
{
    if (!is_dir($path)) return null;
    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if(substr($path, strlen($path) - 1) != '/') $path .= '/';
    $handle = opendir($path);
    while (false !== ($file = readdir($handle))) {
        if ($file != '.' && $file != '..') {
            $path2 = $path . $file;
            if (is_dir($path2)) {
                getfiles($path2, $allowFiles, $files);
            } else {
                if (preg_match("/\.($allowFiles)$/i", $file)) {
                    $url = $path2;
                    if ($documentRoot !== '' && strpos($path2, $documentRoot) === 0) {
                        $url = substr($path2, strlen($documentRoot));
                    }
                    $files[] = array(
                        'url'=> $url,
                        'mtime'=> filemtime($path2)
                    );
                }
            }
        }
    }
    return $files;
}
?>

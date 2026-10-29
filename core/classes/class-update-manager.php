<?php
/**
 * Todo_Update_Manager
 * 
 * Proxy class for Todo_Core_Kernel update functionality.
 * This class maintains backward compatibility while delegating to the core kernel.
 * 
 * @package TodoCMS
 * @version 1.0.0
 */

// Direct access protection

require_once ABSPATH . '/core/config/update.php';

class Todo_Update_Manager
{
    private static function get_backup_dir()
    {
        $dir = rtrim((string) ABSPATH, '/') . '/content/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function build_excludes()
    {
        $root = rtrim((string) ABSPATH, '/') . '/';
        $items = [
            $root . 'content/backups',
            $root . 'content/cache',
            $root . 'content/uploads',
            $root . 'content/updates',
            $root . 'logs',
        ];
        $normalized = [];
        foreach ($items as $p) {
            $rp = realpath($p);
            $normalized[] = rtrim($rp !== false ? $rp : $p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        return $normalized;
    }

    private static function is_excluded_path($absPath, array $excludeAbsPrefixes)
    {
        $p = realpath($absPath);
        $p = $p !== false ? $p : $absPath;
        $p = rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($excludeAbsPrefixes as $ex) {
            if (strpos($p, $ex) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function sql_escape($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $s = str_replace(["\\", "\0", "\n", "\r", "\x1a", "'"], ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'"], $value);
            return "'" . $s . "'";
        }
        $s = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($s === false) {
            $s = (string) $value;
        }
        $s = str_replace(["\\", "\0", "\n", "\r", "\x1a", "'"], ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'"], $s);
        return "'" . $s . "'";
    }

    private static function dump_database_to_file($outputFile)
    {
        global $tododb;
        if (!$tododb || !is_object($tododb) || !method_exists($tododb, 'get_col')) {
            return false;
        }

        $fp = @fopen($outputFile, 'wb');
        if (!$fp) {
            return false;
        }

        @fwrite($fp, "-- TodoCMS Database Dump\n");
        @fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        @fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        try {
            $tables = $tododb->get_col("SHOW TABLES");
            if (!is_array($tables)) {
                $tables = [];
            }

            foreach ($tables as $table) {
                $table = (string) $table;
                if ($table === '') {
                    continue;
                }

                @fwrite($fp, "\n-- ----------------------------\n");
                @fwrite($fp, "-- Table structure for `$table`\n");
                @fwrite($fp, "-- ----------------------------\n");
                @fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

                $create_sql = '';
                $results = $tododb->get_results("SHOW CREATE TABLE `{$table}`");
                if (is_array($results) && isset($results[0])) {
                    $row = $results[0];
                    if (is_object($row)) {
                        if (isset($row->{'Create Table'})) {
                            $create_sql = (string) $row->{'Create Table'};
                        } elseif (isset($row->{'create table'})) {
                            $create_sql = (string) $row->{'create table'};
                        } elseif (isset($row->{'CREATE TABLE'})) {
                            $create_sql = (string) $row->{'CREATE TABLE'};
                        } elseif (isset($row->{"Create Table"})) {
                            $create_sql = (string) $row->{"Create Table"};
                        }
                    } elseif (is_array($row)) {
                        foreach (['Create Table', 'create table', 'CREATE TABLE'] as $k) {
                            if (isset($row[$k])) {
                                $create_sql = (string) $row[$k];
                                break;
                            }
                        }
                        if ($create_sql === '' && count($row) >= 2) {
                            $vals = array_values($row);
                            $create_sql = (string) ($vals[1] ?? '');
                        }
                    }
                }

                if ($create_sql !== '') {
                    @fwrite($fp, $create_sql . ";\n\n");
                }

                $count = null;
                if (method_exists($tododb, 'get_var')) {
                    $count = $tododb->get_var("SELECT COUNT(*) FROM `{$table}`");
                }
                $count = is_numeric($count) ? (int) $count : 0;

                @fwrite($fp, "-- ----------------------------\n");
                @fwrite($fp, "-- Records of `$table` ({$count})\n");
                @fwrite($fp, "-- ----------------------------\n");

                if ($count <= 0) {
                    @fwrite($fp, "\n");
                    continue;
                }

                $batch = 500;
                for ($offset = 0; $offset < $count; $offset += $batch) {
                    $rows = $tododb->query("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch}");
                    if (!is_array($rows) || empty($rows)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $cols = array_keys($row);
                        $vals = [];
                        foreach ($cols as $c) {
                            $vals[] = self::sql_escape($row[$c]);
                        }
                        $colSql = '`' . implode('`, `', array_map(function ($c) {
                            return str_replace('`', '``', (string) $c);
                        }, $cols)) . '`';
                        $valSql = implode(', ', $vals);
                        @fwrite($fp, "INSERT INTO `{$table}` ({$colSql}) VALUES ({$valSql});\n");
                    }
                }
                @fwrite($fp, "\n");
            }
        } catch (Throwable $e) {
            @fclose($fp);
            return false;
        }

        @fwrite($fp, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        @fclose($fp);
        return true;
    }

    private static function create_backup_zip($zipPath)
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ziparchive_missing'];
        }

        $backupDir = self::get_backup_dir();
        $tmpSql = $backupDir . '/database_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.sql';
        if (!self::dump_database_to_file($tmpSql)) {
            if (is_file($tmpSql)) {
                @unlink($tmpSql);
            }
            return ['ok' => false, 'error' => 'db_dump_failed'];
        }

        $tmpZip = $zipPath . '.tmp';
        if (is_file($tmpZip)) {
            @unlink($tmpZip);
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpSql);
            return ['ok' => false, 'error' => 'zip_open_failed'];
        }

        $zip->addFile($tmpSql, 'database.sql');

        $root = rtrim((string) ABSPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $excludeAbsPrefixes = self::build_excludes();

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $fileInfo) {
            $abs = $fileInfo->getPathname();
            if (self::is_excluded_path($abs, $excludeAbsPrefixes)) {
                continue;
            }
            $rel = substr($abs, strlen($root));
            if ($rel === false || $rel === '') {
                continue;
            }
            if ($fileInfo->isDir()) {
                $zip->addEmptyDir(rtrim(str_replace('\\', '/', $rel), '/') . '/');
                continue;
            }
            if ($fileInfo->isFile()) {
                $zip->addFile($abs, str_replace('\\', '/', $rel));
            }
        }

        $zip->close();
        @unlink($tmpSql);

        if (@rename($tmpZip, $zipPath)) {
            return ['ok' => true];
        }
        if (@copy($tmpZip, $zipPath)) {
            @unlink($tmpZip);
            return ['ok' => true];
        }
        @unlink($tmpZip);
        return ['ok' => false, 'error' => 'zip_finalize_failed'];
    }

    /**
     * Check for updates
     * @return array Update information
     */
    public static function check_updates()
    {
        return Todo_Core_Kernel::check_updates();
    }

    /**
     * Request download token and URL
     * @param string $version Version to download
     * @return array Download information
     */
    public static function request_download($version)
    {
        return Todo_Core_Kernel::request_download($version);
    }

    /**
     * Download update package
     * @param string $url Download URL
     * @return array Download result
     */
    public static function download_package($url)
    {
        return Todo_Core_Kernel::download_package($url);
    }

    /**
     * Backup before upgrade
     * @return array Backup result
     */
    public static function backup_before_upgrade()
    {
        $backupDir = self::get_backup_dir();
        $name = 'todocms-backup-' . date('Ymd_His') . '-' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8) . '.zip';
        $zipPath = rtrim($backupDir, '/') . '/' . $name;

        $res = self::create_backup_zip($zipPath);
        if (!empty($res['ok'])) {
            return ['ok' => true, 'path' => $zipPath];
        }

        $fallback = null;
        try {
            $fallback = Todo_Core_Kernel::backup_before_upgrade();
        } catch (Throwable $e) {
            $fallback = null;
        }
        if (is_array($fallback) && !empty($fallback['ok'])) {
            return $fallback;
        }
        return ['ok' => false, 'error' => $res['error'] ?? 'backup_failed'];
    }

    /**
     * Install update package
     * @param string $zipPath Path to update package
     * @return array Installation result
     */
    public static function install_update($zipPath)
    {
        $prevHandler = set_error_handler(function ($severity, $message, $file, $line) use (&$prevHandler) {
            if (($severity === E_WARNING || $severity === E_USER_WARNING) && is_string($message)) {
                if (strpos($message, 'mkdir():') !== false && (strpos($message, 'File exists') !== false || strpos($message, '文件已存在') !== false)) {
                    return true;
                }
            }
            if (is_callable($prevHandler)) {
                return (bool) call_user_func($prevHandler, $severity, $message, $file, $line);
            }
            return false;
        }, E_WARNING | E_USER_WARNING);
        try {
            return Todo_Core_Kernel::install_update($zipPath);
        } finally {
            restore_error_handler();
        }
    }
}

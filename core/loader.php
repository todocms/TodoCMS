<?php
/**
 * Todo Myioncube Runtime Loader (Fragmented v3.1)
 * Advanced Scope-Linked Source Protection.
 */

namespace Myioncube;

if (!defined('TODO_MYIONCUBE_LOADER')) {
    define('TODO_MYIONCUBE_LOADER', true);
}

require_once __DIR__ . '/codebook.php';

class Loader
{
    private static $storage = [];

    private static function get_logical_hash()
    {
        $files = [__FILE__, __DIR__ . '/codebook.php'];
        $logical = '';
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            $code = file_get_contents($file);
            $tokens = token_get_all($code);
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) continue;
                    $logical .= $token[1];
                } else {
                    $logical .= $token;
                }
            }
            $logical .= '|';
        }
        return md5($logical);
    }

    public static function logical_hash()
    {
        return self::get_logical_hash();
    }

    private static function calc_kernel_sig($path)
    {
        if (!$path || !file_exists($path)) return '';
        $code = file_get_contents($path);
        $code = preg_replace("/'sig'\\s*=>\\s*'[a-f0-9]{32}'/", "'sig' => '<sig>'", $code, 1);
        return md5($code);
    }

    private static function is_debugger_present()
    {
        return false;
        if (!extension_loaded('xdebug') && !extension_loaded('zend_debugger')) return false;

        $mode = ini_get('xdebug.mode');
        if ($mode && $mode !== 'off' && strpos($mode, 'debug') !== false) {
            if (ini_get('xdebug.start_with_request') === 'yes') {
                return true;
            }
            if ((isset($_GET['XDEBUG_SESSION_START']) && $_GET['XDEBUG_SESSION_START'] !== '')
                || (isset($_GET['XDEBUG_TRIGGER']) && $_GET['XDEBUG_TRIGGER'] !== '')
                || (isset($_COOKIE['XDEBUG_SESSION']) && $_COOKIE['XDEBUG_SESSION'] !== '')
                || (isset($_COOKIE['XDEBUG_TRIGGER']) && $_COOKIE['XDEBUG_TRIGGER'] !== '')
                || (isset($_SERVER['XDEBUG_TRIGGER']) && $_SERVER['XDEBUG_TRIGGER'] !== '')
                || (isset($_SERVER['PHP_IDE_CONFIG']) && $_SERVER['PHP_IDE_CONFIG'] !== '')) {
                return true;
            }
        }
        if (function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active()) return true;
        if (extension_loaded('zend_debugger')) return true;
        return false;
    }

    private static function harden_runtime()
    {
        error_reporting(0);
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
    }

    public static function dispatch_kernel($className, $blocks, $codebook_str, $key, $msalt, $props = [], $kernel_path = '', $kernel_sig = '')
    {
        self::harden_runtime();
        if (self::is_debugger_present()) {
            return false;
        }
        if ($kernel_path && $kernel_sig) {
            $sig = self::calc_kernel_sig($kernel_path);
            if (!$sig || $sig !== $kernel_sig) {
                return false;
            }
        }
        if (!class_exists($className, false)) {
            $prop_code = "";
            foreach ($props as $name => $val) {
                if (is_string($val)) {
                    $val = base64_decode($val);
                }
                $exported = var_export($val, true);
                $prop_code .= "    public static \$$name = $exported;\n";
            }

            $shell_code = "class $className {
                $prop_code
                public \$id = 0;
                public \$data = null;
                public \$meta = [];
                public \$roles = [];
                public \$capabilities = [];
                public \$__myioncube_state = [];
                public function __construct(...\$a) {
                    \Myioncube\Loader::execute_fragment('$className', '__construct', \$a, \$this);
                }
                public static function __callStatic(\$n, \$a) {
                    return \Myioncube\Loader::execute_fragment('$className', \$n, \$a);
                }
                public function __call(\$n, \$a) {
                    return \Myioncube\Loader::execute_fragment('$className', \$n, \$a, \$this);
                }
                public function __get(\$n) {
                    \$r = \Myioncube\Loader::execute_fragment('$className', '__get', [\$n], \$this);
                    if (\$r !== null) {
                        return \$r;
                    }
                    return \$this->__myioncube_state[\$n] ?? null;
                }
                public function __set(\$n, \$v) {
                    \$r = \Myioncube\Loader::execute_fragment('$className', '__set', [\$n, \$v], \$this);
                    if (\$r !== null) {
                        return \$r;
                    }
                    \$this->__myioncube_state[\$n] = \$v;
                    return null;
                }
                public function __isset(\$n) {
                    \$r = \Myioncube\Loader::execute_fragment('$className', '__isset', [\$n], \$this);
                    if (\$r !== null) {
                        return (bool)\$r;
                    }
                    return isset(\$this->__myioncube_state[\$n]);
                }
                public function __unset(\$n) {
                    \$r = \Myioncube\Loader::execute_fragment('$className', '__unset', [\$n], \$this);
                    if (\$r !== null) {
                        return \$r;
                    }
                    unset(\$this->__myioncube_state[\$n]);
                }
            }";
            eval($shell_code);
        }

        self::$storage[$className] = [
            'blocks' => $blocks,
            'map' => $codebook_str,
            'key' => $key,
            'ihash' => self::get_logical_hash(),
            'msalt' => $msalt
        ];

        return true;
    }

    public static function execute_fragment($className, $method, $args, $instance = null)
    {
        self::harden_runtime();
        if (!isset(self::$storage[$className])) {
            return null;
        }
        $cfg = self::$storage[$className];
        $lookup_key = md5($method . $cfg['msalt']);
        
        if (!isset($cfg['blocks'][$lookup_key])) {
            return null;
        }
        $payload = implode('', $cfg['blocks'][$lookup_key]);
        $json = self::decrypt($payload, $cfg['map'], $cfg['key'], $cfg['ihash'], $method);
        
        if (!$json) return null;
        
        $m = json_decode($json, true);
        if (!$m || !isset($m['b'])) return null;

        $source = preg_replace('/^<\?php\s*/i', '', $m['b']);
        $argNames = $m['a'] ?? [];

        $wrapper = function() use ($source, $args, $argNames) {
            $vars = [];
            foreach ($argNames as $i => $name) {
                $vars[$name] = array_key_exists($i, $args) ? $args[$i] : null;
            }
            extract($vars); 
            return eval($source);
        };

        if ($instance) {
            $bound = \Closure::bind($wrapper, $instance, get_class($instance));
        } else {
            $bound = \Closure::bind($wrapper, null, $className);
        }

        return $bound();
    }

    private static function decrypt($payload, $codebook_str, $key, $ihash, $method_salt = '')
    {
        $codebook = Codebook::from_string($codebook_str);
        $data = base64_decode($payload);
        $real_key = $key . $ihash . $method_salt; 
        $keyLen = strlen($real_key);
        $len = strlen($data);
        
        $dec = '';
        for ($i = 0; $i < $len; $i++) {
            $dec .= $data[$i] ^ $real_key[$i % $keyLen];
        }
        
        $decrypted = $codebook->translate($dec, false);
        $inflated = @gzinflate($decrypted);
        return $inflated ?: null;
    }
}

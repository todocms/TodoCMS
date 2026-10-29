<?php
/**
 * 固定链接处理类
 *
 * 处理URL重写和固定链接结构解析
 *
 * @package TodoCMS
 * @version 1.0.0
 */
class Todo_Permalink {
    public static function parse($request_uri) {
        $structure = function_exists('get_option') ? get_option('permalink_structure', '/%postname%/') : '/%postname%/';
        if (!$structure) { $structure = '/%postname%/'; }
        $request_uri = strtok($request_uri, '?');
        $request_uri = ltrim($request_uri, '/');
        $rules = self::generateRules($structure);
        foreach ($rules as $regex => $query) {
            if (preg_match('#' . $regex . '#', $request_uri, $matches)) {
                $query = preg_replace_callback('/\$matches\[([0-9]+)\]/', function($m) use ($matches){ return isset($matches[$m[1]]) ? $matches[$m[1]] : ''; }, $query);
                $vars = array();
                parse_str(parse_url($query, PHP_URL_QUERY), $vars);
                return $vars;
            }
        }
        return false;
    }
    public static function generateRules($structure) {
        $rules = array();
        foreach (self::expandOptionalStructures($structure, array('%author%','%breadcrumb%')) as $st) {
            $compiled = self::compileStructure($st);
            $rules[$compiled['regex']] = self::buildQueryFromKeys($compiled['query_keys']);
            if (class_exists('LanguageRegistry')) {
                $registry = LanguageRegistry::get_instance();
                if ($registry && method_exists($registry, 'get_routing_mode') && $registry->get_routing_mode() === 'path_prefix') {
                    $map = $registry->path_prefix_map();
                    if (is_array($map)) {
                        foreach ($map as $locale => $prefix) {
                            $prefix = trim((string)$prefix, '/');
                            if ($prefix === '') { continue; }
                            $langRegex = '^' . preg_quote($prefix, '/') . '\/' . ltrim($compiled['regex'], '^');
                            $rules[$langRegex] = self::buildQueryFromKeys($compiled['query_keys']);
                        }
                    }
                }
            }
        }
        return $rules;
    }
    private static function expandOptionalStructures($structure, $optional_tokens) {
        $variants = array(); $queue = array($structure); $seen = array();
        while (!empty($queue)) {
            $curr = array_shift($queue);
            if (isset($seen[$curr])) { continue; }
            $seen[$curr] = true; $variants[] = $curr;
            foreach ($optional_tokens as $token) {
                if (strpos($curr, $token) !== false) {
                    $without = $curr;
                    $without = preg_replace('#/' . preg_quote($token, '#') . '/#', '/', $without);
                    $without = preg_replace('#/' . preg_quote($token, '#') . '$#', '', $without);
                    $without = preg_replace('#^' . preg_quote($token, '#') . '/#', '', $without);
                    $without = str_replace($token, '', $without);
                    $without = preg_replace('#/{2,}#', '/', $without);
                    if ($without === '' || $without[0] !== '/') { $without = '/' . ltrim($without, '/'); }
                    if (!isset($seen[$without])) { $queue[] = $without; }
                }
            }
        }
        return array_values(array_unique($variants));
    }
    private static function compileStructure($structure) {
        if (class_exists('Todo_Core_Kernel') && is_callable(['Todo_Core_Kernel', 'compile_permalink_structure'])) {
            return Todo_Core_Kernel::compile_permalink_structure($structure);
        }
        exit('Security Error: Core Routing Engine Missing');
    }
    private static function buildQueryFromKeys($keys) {
        if (empty($keys)) { return 'index.php?'; }
        $pairs = array(); $i = 1;
        foreach ($keys as $key) { $pairs[] = $key . '=$matches[' . $i++ . ']'; }
        return 'index.php?' . implode('&', $pairs);
    }
}

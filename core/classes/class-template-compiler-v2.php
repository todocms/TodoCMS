<?php
/**
 * TodoCMS 模板编译器 V3 —— 扫描器 + 递归下降解析器
 *
 * 一次遍历 O(n)，无正则回溯，原生支持任意深度嵌套的 @if / @loop 等块标签。
 * 将模板编译为纯 PHP 文件并写入缓存。
 *
 * 支持的语法（与现有系统兼容）：
 *   {{ var }}               变量输出
 *   {{ var.name }}          嵌套变量
 *   {{ var + 1 }}           简单运算
 *   {$var}                  简写变量输出
 *   {{todo:tag_name}}       自闭合标签
 *   {{todo:tag_name|params}}带参标签
 *   {{todo:if condition}}...{{todo:elseif cond}}...{{todo:else}}...{{/todo:if}}
 *   {{todo:loop items|params}}...{{/todo:loop}}
 *   {{todo:include file="xxx"}}
 *   {{todo:block_tag params}}...{{/todo:block_tag}}
 *   {{literal}}...{{/literal}}
 *   {Text|todo_lang|context}
 *   {{todo:function(args)}} 函数调用语法
 *
 * @package TodoCMS
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// 1. Token 定义
// ============================================================================

class TplToken
{
    const T_TEXT    = 1;   // 纯文本
    const T_ECHO    = 2;   // {{ expr }} 变量输出
    const T_RAW     = 3;   // {$var} 简写输出
    const T_OPEN    = 4;   // {{todo:name params}} 开始标签
    const T_CLOSE   = 5;   // {{/todo:name}} 结束标签
    const T_LANG    = 6;   // {Text|todo_lang|context}
    const T_LITERAL = 7;   // {{literal}}...{{/literal}} 内容

    public $type;
    public $value;       // 文本 / 表达式 / 字面量
    public $name;        // 标签名（T_OPEN / T_CLOSE）
    public $params;      // 原始参数字符串（T_OPEN）
    public $langText;    // 语言标签文本
    public $langCtx;     // 语言标签上下文
    public $pos;         // 源码位置（调试用）

    // TagPairer 动态属性
    public $paired  = false; // T_OPEN 是否与 T_CLOSE 配对
    public $closeIdx = -1;   // 配对的 T_CLOSE 索引
    public $orphan   = false; // T_CLOSE 是否为孤立的

    public function __construct($type, $value = '')
    {
        $this->type  = $type;
        $this->value = $value;
    }
}

// ============================================================================
// 2. 扫描器 —— 逐字符推进，一次遍历 O(n)
// ============================================================================

class TplScanner
{
    private $src;
    private $len;
    private $pos;

    /**
     * 扫描模板字符串，返回 Token 流
     *
     * @param string $content
     * @return TplToken[]
     */
    public function scan($content)
    {
        $this->src = $content;
        $this->len = strlen($content);
        $this->pos = 0;

        $tokens = [];

        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];

            // ---- {{literal}}...{{/literal}} ----
            if ($c === '{' && substr($this->src, $this->pos, 11) === '{{literal}}') {
                $this->pos += 11;
                $end = strpos($this->src, '{{/literal}}', $this->pos);
                if ($end === false) {
                    $end = $this->len;
                }
                $content = substr($this->src, $this->pos, $end - $this->pos);
                // 转义 PHP 标签，避免执行
                $content = str_replace(['<?php', '?>'], ['&lt;?php', '?&gt;'], $content);
                $tokens[] = $this->makeToken(TplToken::T_LITERAL, $content);
                $this->pos = ($end < $this->len) ? $end + 12 : $end;
                continue;
            }

            // ---- {{/todo:TAGNAME}} ----
            if ($c === '{' && substr($this->src, $this->pos, 8) === '{{/todo:') {
                $start  = $this->pos + 8;
                $end    = $this->findDoubleBraceEnd($this->pos + 2);
                if ($end === false) {
                    // 未闭合，当文本处理
                    $tok = $this->makeToken(TplToken::T_TEXT, substr($this->src, $this->pos, 2));
                    $this->pos += 2;
                    $tokens[] = $tok;
                    continue;
                }
                $inner  = substr($this->src, $start, $end - $start);
                $name   = $this->extractName($inner);
                $tok    = $this->makeToken(TplToken::T_CLOSE);
                $tok->name = $name;
                $tokens[] = $tok;
                $this->pos = $end + 2;
                continue;
            }

            // ---- {{todo:TAGNAME params...}} ----
            if ($c === '{' && substr($this->src, $this->pos, 7) === '{{todo:') {
                $start  = $this->pos + 7;
                $end    = $this->findDoubleBraceEnd($this->pos + 2);
                if ($end === false) {
                    $tok = $this->makeToken(TplToken::T_TEXT, substr($this->src, $this->pos, 2));
                    $this->pos += 2;
                    $tokens[] = $tok;
                    continue;
                }
                $inner  = substr($this->src, $start, $end - $start);
                $parsed = $this->parseOpenTagInner($inner);

                $tok    = $this->makeToken(TplToken::T_OPEN);
                $tok->name   = $parsed['name'];
                $tok->params = $parsed['params'];
                $tokens[] = $tok;
                $this->pos = $end + 2;
                continue;
            }

            // ---- {{ expr }} 变量输出 ----
            if ($c === '{' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '{') {
                $start  = $this->pos + 2;
                $end    = $this->findDoubleBraceEnd($this->pos + 2);
                if ($end === false) {
                    $tok = $this->makeToken(TplToken::T_TEXT, substr($this->src, $this->pos, 2));
                    $this->pos += 2;
                    $tokens[] = $tok;
                    continue;
                }
                $expr = trim(substr($this->src, $start, $end - $start));
                if ($expr !== '') {
                    $tokens[] = $this->makeToken(TplToken::T_ECHO, $expr);
                }
                $this->pos = $end + 2;
                continue;
            }

            // ---- {$var} 简写输出 ----
            if ($c === '{' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '$') {
                $open  = $this->pos;
                $end   = strpos($this->src, '}', $open + 2);
                if ($end === false) {
                    $tokens[] = $this->makeToken(TplToken::T_TEXT, substr($this->src, $open, 1));
                    $this->pos = $open + 1;
                    continue;
                }
                $var = trim(substr($this->src, $open + 2, $end - $open - 2));
                $tokens[] = $this->makeToken(TplToken::T_RAW, $var);
                $this->pos = $end + 1;
                continue;
            }

            // ---- {Text|todo_lang|context} 语言标签 ----
            if ($c === '{') {
                $close = strpos($this->src, '}', $this->pos + 1);
                if ($close !== false) {
                    $inner = substr($this->src, $this->pos + 1, $close - $this->pos - 1);
                    if (strpos($inner, '|todo_lang') !== false) {
                        $parts = explode('|', $inner);
                        $text    = $parts[0];
                        $context = isset($parts[2]) ? $parts[2] : '';
                        $tok = $this->makeToken(TplToken::T_LANG);
                        $tok->langText = trim($text);
                        $tok->langCtx  = trim($context);
                        $tokens[] = $tok;
                        $this->pos = $close + 1;
                        continue;
                    }
                }
                // 未匹配任何特殊模式 → 单个 '{' 当普通文本输出
                $tokens[] = $this->makeToken(TplToken::T_TEXT, '{');
                $this->pos++;
                continue;
            }

            // ---- 纯文本：推进到下一个 '{' ----
            $next = $this->pos;
            while ($next < $this->len && $this->src[$next] !== '{') {
                $next++;
            }
            if ($next > $this->pos) {
                $tokens[] = $this->makeToken(TplToken::T_TEXT, substr($this->src, $this->pos, $next - $this->pos));
            }
            $this->pos = $next;
        }

        return $tokens;
    }

    /**
     * 查找匹配的 }}，正确处理嵌套的 {{ }}
     */
    private function findDoubleBraceEnd($start)
    {
        $depth = 1;
        $pos   = $start;
        while ($pos < $this->len - 1) {
            if ($this->src[$pos] === '{' && $this->src[$pos + 1] === '{') {
                $depth++;
                $pos += 2;
            } elseif ($this->src[$pos] === '}' && $this->src[$pos + 1] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $pos;
                }
                $pos += 2;
            } else {
                $pos++;
            }
        }
        return false;
    }

    /**
     * 解析 {{todo:NAME PARAMS}} 内部的 name 和 params
     */
    private function parseOpenTagInner($inner)
    {
        $inner = trim($inner);
        $len   = strlen($inner);
        $i     = 0;

        // 读取 tag name（字母数字、下划线、点、@）
        $name = '';
        while ($i < $len && (ctype_alnum($inner[$i]) || $inner[$i] === '_' || $inner[$i] === '.' || $inner[$i] === '@')) {
            $name .= $inner[$i];
            $i++;
        }

        // 函数调用语法 {{todo:function(args)}}
        $args = '';
        if ($i < $len && $inner[$i] === '(') {
            $depth = 1;
            $j     = $i + 1;
            while ($j < $len && $depth > 0) {
                if ($inner[$j] === '(') {
                    $depth++;
                } elseif ($inner[$j] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $j++;
            }
            if ($depth === 0) {
                $args  = substr($inner, $i + 1, $j - $i - 1);
                $i     = $j + 1;
            }
        }

        // 剩余的是参数
        $params = trim(substr($inner, $i));

        // 如果参数以 | 开头，去掉
        if (isset($params[0]) && $params[0] === '|') {
            $params = substr($params, 1);
        }

        // 去掉尾部 /
        $params = rtrim($params, ' /');

        // 如果有函数调用参数，合并
        if ($args !== '') {
            // 旧的兼容方式：把函数参数作为 args 属性
            $argsEscaped = str_replace("'", "\\'", $args);
            $params = trim("args='" . $argsEscaped . "' " . $params);
        }

        return ['name' => $name, 'params' => $params];
    }

    /**
     * 从 {{/todo:NAME}} 内部提取 name
     */
    private function extractName($inner)
    {
        $inner = trim($inner);
        $name  = '';
        $len   = strlen($inner);
        $i     = 0;
        while ($i < $len && (ctype_alnum($inner[$i]) || $inner[$i] === '_' || $inner[$i] === '.')) {
            $name .= $inner[$i];
            $i++;
        }
        return $name;
    }

    private function makeToken($type, $value = '')
    {
        $tok        = new TplToken($type, $value);
        $tok->pos   = $this->pos;
        return $tok;
    }
}

// ============================================================================
// 3. AST 节点定义
// ============================================================================

abstract class TplNode
{
    abstract public function compile(TplCompiler $c);
}

class TplTextNode extends TplNode
{
    public $text;
    public function __construct($text) { $this->text = $text; }
    public function compile(TplCompiler $c) { return $this->text; }
}

class TplEchoNode extends TplNode
{
    public $expr;
    public function __construct($expr) { $this->expr = $expr; }
    public function compile(TplCompiler $c) { return $c->compileEcho($this); }
}

class TplRawEchoNode extends TplNode
{
    public $var;
    public function __construct($var) { $this->var = $var; }
    public function compile(TplCompiler $c) { return $c->compileRawEcho($this); }
}

class TplIfNode extends TplNode
{
    public $condition;
    /** @var TplNode[] */
    public $body    = [];
    /** @var array{condition:string, body:TplNode[]}[] */
    public $elseifs = [];
    /** @var TplNode[] */
    public $else    = [];

    public function __construct($condition) { $this->condition = $condition; }
    public function compile(TplCompiler $c) { return $c->compileIf($this); }
}

class TplLoopNode extends TplNode
{
    /** @var string 数据源名（如 posts） */
    public $source;
    /** @var string 原始参数 */
    public $params;
    /** @var TplNode[] */
    public $body = [];
    public $uid = '';

    public function __construct($source, $params) {
        $this->source = $source;
        $this->params = $params;
    }
    public function compile(TplCompiler $c) { return $c->compileLoop($this); }
}

class TplIncludeNode extends TplNode
{
    public $file;
    public function __construct($file) { $this->file = $file; }
    public function compile(TplCompiler $c) { return $c->compileInclude($this); }
}

class TplTagNode extends TplNode
{
    public $name;
    public $params;
    /** @var TplNode[]|null null 表示自闭合 */
    public $body;

    public function __construct($name, $params, $body = null) {
        $this->name   = $name;
        $this->params = $params;
        $this->body   = $body;
    }
    public function compile(TplCompiler $c) { return $c->compileTag($this); }
}

class TplLanguageNode extends TplNode
{
    public $text;
    public $context;

    public function __construct($text, $context) {
        $this->text    = $text;
        $this->context = $context;
    }
    public function compile(TplCompiler $c) { return $c->compileLanguage($this); }
}

class TplLiteralNode extends TplNode
{
    public $content;
    public function __construct($content) { $this->content = $content; }
    public function compile(TplCompiler $c) { return $this->content; }
}

// ============================================================================
// 4. 标签配对器 —— 确定哪些标签是自闭合，哪些是块标签
// ============================================================================

class TplTagPairer
{
    /**
     * 对 Token 流进行配对分析，标记 T_OPEN 节点是否需要匹配 T_CLOSE
     * 返回带有 paired 标记的 tokens
     *
     * @param TplToken[] $tokens
     * @return TplToken[]
     */
    public static function pair(array $tokens)
    {
        // 已知关键字：一定不是块标签（由解析器特殊处理）
        $keywords = ['if', 'else', 'elseif', 'loop', 'include', 'literal'];

        // 用栈匹配 open/close
        $stack  = [];
        $result = $tokens;

        foreach ($result as $i => $token) {
            if ($token->type === TplToken::T_OPEN && !in_array($token->name, $keywords)) {
                $token->paired    = false;
                $token->closeIdx  = -1;
                $stack[] = ['name' => $token->name, 'idx' => $i];
            } elseif ($token->type === TplToken::T_CLOSE) {
                $found = false;
                for ($j = count($stack) - 1; $j >= 0; $j--) {
                    if ($stack[$j]['name'] === $token->name) {
                        // 匹配成功
                        $openIdx = $stack[$j]['idx'];
                        $result[$openIdx]->paired   = true;
                        $result[$openIdx]->closeIdx = $i;
                        // 弹出该层及以上的所有未匹配 open
                        array_splice($stack, $j);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // 孤立的 close token，标记为文本原样输出
                    $token->orphan = true;
                }
            }
        }

        // 栈中剩余的 open 是未闭合的 → 自闭合
        return $result;
    }
}

// ============================================================================
// 5. 解析器 —— 递归下降构建 AST
// ============================================================================

class TplParser
{
    /** @var TplToken[] */
    private $tokens;
    private $pos  = 0;
    private $count = 0;

    /**
     * @param TplToken[] $tokens 经过 TagPairer 配对处理的 token 流
     * @param string $templatePath
     * @return TplNode[]
     */
    public function parse(array $tokens, $templatePath = '')
    {
        $this->tokens       = $tokens;
        $this->pos          = 0;
        $this->count        = count($tokens);

        return $this->parseBlock(null);
    }

    /**
     * 解析块级内容，直到遇到指定的结束标签
     *
     * @param string|null $until 遇到此名称的 T_CLOSE 时停止
     * @return TplNode[]
     */
    private function parseBlock($until)
    {
        $nodes = [];

        while ($this->pos < $this->count) {
            $token = $this->tokens[$this->pos];

            // 结束条件
            if ($until !== null && $token->type === TplToken::T_CLOSE && $token->name === $until) {
                break;
            }

            switch ($token->type) {
                case TplToken::T_TEXT:
                    $nodes[] = new TplTextNode($token->value);
                    $this->pos++;
                    break;

                case TplToken::T_ECHO:
                    $nodes[] = new TplEchoNode($token->value);
                    $this->pos++;
                    break;

                case TplToken::T_RAW:
                    $nodes[] = new TplRawEchoNode($token->value);
                    $this->pos++;
                    break;

                case TplToken::T_LITERAL:
                    $nodes[] = new TplLiteralNode($token->value);
                    $this->pos++;
                    break;

                case TplToken::T_LANG:
                    $nodes[] = new TplLanguageNode($token->langText, $token->langCtx);
                    $this->pos++;
                    break;

                case TplToken::T_OPEN:
                    // elseif / else 不在此处理 —— 留给 parseIf 接管
                    if ($until === 'if' && ($token->name === 'elseif' || $token->name === 'else')) {
                        break 2;
                    }
                    $nodes = array_merge($nodes, $this->parseOpenTag());
                    break;

                case TplToken::T_CLOSE:
                    // 孤立的 close token → 原样文本输出
                    if (!empty($token->orphan)) {
                        $nodes[] = new TplTextNode('{{/todo:' . $token->name . '}}');
                        $this->pos++;
                        break;
                    }
                    // 未预期的 close token，当文本
                    if ($until === null) {
                        $nodes[] = new TplTextNode('{{/todo:' . $token->name . '}}');
                        $this->pos++;
                        break;
                    }
                    // 不是我们期待的结束标签 → 文本
                    if ($token->name !== $until) {
                        $nodes[] = new TplTextNode('{{/todo:' . $token->name . '}}');
                        $this->pos++;
                        break;
                    }
                    // 匹配的结束标签，返回给上层处理
                    break 2;

                default:
                    $this->pos++;
                    break;
            }
        }

        return $nodes;
    }

    /**
     * 处理 T_OPEN 标签
     * @return TplNode[]
     */
    private function parseOpenTag()
    {
        $token  = $this->tokens[$this->pos];
        $name   = $token->name;
        $params = $token->params;
        $this->pos++; // consume

        // ---- if 块 ----
        if ($name === 'if') {
            return [$this->parseIf($params)];
        }

        // ---- elseif / else（孤立的，当文本） ----
        if ($name === 'elseif' || $name === 'else') {
            return [new TplTextNode('{{todo:' . $name . ($params ? ' ' . $params : '') . '}}')];
        }

        // ---- loop ----
        if ($name === 'loop') {
            return [$this->parseLoop($params)];
        }

        // ---- include ----
        if ($name === 'include') {
            return [$this->parseInclude($params)];
        }

        // ---- literal（已在扫描阶段处理，但以防万一） ----
        if ($name === 'literal') {
            return [new TplTextNode('{{literal}}')];
        }

        // ---- 配对的块标签 ----
        if (!empty($token->paired)) {
            $body = $this->parseBlock($name);
            // 消费 T_CLOSE
            if ($this->pos < $this->count
                && $this->tokens[$this->pos]->type === TplToken::T_CLOSE
                && $this->tokens[$this->pos]->name === $name) {
                $this->pos++;
            }
            return [new TplTagNode($name, $params, $body)];
        }

        // ---- 自闭合标签 ----
        return [new TplTagNode($name, $params, null)];
    }

    private function parseIf($condition)
    {
        $node         = new TplIfNode($condition);
        $node->body   = $this->parseBlock('if');

        // 处理 elseif / else
        while ($this->pos < $this->count) {
            $token = $this->tokens[$this->pos];

            if ($token->type === TplToken::T_CLOSE && $token->name === 'if') {
                $this->pos++;
                break;
            }

            if ($token->type === TplToken::T_OPEN && $token->name === 'elseif') {
                $this->pos++;
                $node->elseifs[] = [
                    'condition' => $token->params,
                    'body'      => $this->parseBlock('if')
                ];
                continue;
            }

            if ($token->type === TplToken::T_OPEN && $token->name === 'else') {
                $this->pos++;
                $node->else = $this->parseBlock('if');
                continue;
            }

            // 其他情况跳出
            break;
        }

        return $node;
    }

    private function parseLoop($params)
    {
        // 从 params 中提取数据源名称
        $parts  = preg_split('/[\s\|]+/', trim($params), 2);
        $source = $parts[0] ?? '';
        $rest   = $parts[1] ?? '';

        // 如果第一个 part 没有 =（不是 key=value 格式），它是 source
        if ($source !== '' && strpos($source, '=') !== false) {
            $rest   = $params;
            $source = '';
        }

        // 如果 source 包含 |，取第一个
        if (($pipePos = strpos($source, '|')) !== false) {
            $rest   = substr($source, $pipePos + 1) . ($rest ? ' ' . $rest : '');
            $source = substr($source, 0, $pipePos);
        }

        // 空 source 默认 'items'
        if ($source === '') {
            $source = 'items';
        }

        $node         = new TplLoopNode($source, $rest);
        $node->body   = $this->parseBlock('loop');

        if ($this->pos < $this->count
            && $this->tokens[$this->pos]->type === TplToken::T_CLOSE
            && $this->tokens[$this->pos]->name === 'loop') {
            $this->pos++;
        }

        return $node;
    }

    private function parseInclude($params)
    {
        // 提取 file="xxx" 的值
        $file = '';
        // 支持 file="xxx" 或 file='xxx' 或直接管道语法
        if (preg_match('/file\s*=\s*["\']([^"\']+)["\']/', $params, $m)) {
            $file = $m[1];
        } elseif (trim($params) !== '' && strpos($params, '=') === false) {
            // {{todo:include|header}} 或 {{todo:include header}}
            $file = trim($params, '| ');
        }

        return new TplIncludeNode($file);
    }
}

// ============================================================================
// 6. 编译器 —— AST → PHP 代码
// ============================================================================

class TplCompiler
{
    private $templatePath  = '';
    private $dependencies  = [];
    private $uidCounter    = 0;
    private $currentNodes  = null;
    private $currentIndex  = -1;

    public function __construct($templatePath = '')
    {
        $this->templatePath = $templatePath;
    }

    /**
     * 编译 AST 节点列表为 PHP 代码
     *
     * @param TplNode[] $nodes
     * @return string
     */
    public function compile(array $nodes)
    {
        $prevNodes = $this->currentNodes;
        $prevIndex = $this->currentIndex;

        $code = '';
        $this->assignLoopUids($nodes);
        $this->currentNodes = $nodes;
        $n = count($nodes);
        for ($i = 0; $i < $n; $i++) {
            $this->currentIndex = $i;
            $node = $nodes[$i];
            $code .= $node->compile($this);
        }

        $this->currentNodes = $prevNodes;
        $this->currentIndex = $prevIndex;
        return $code;
    }

    /**
     * 获取编译过程中发现的依赖文件
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    // ---- 节点编译方法 ----

    public function compileEcho(TplEchoNode $node)
    {
        $expr = $node->expr;

        // @index / @index_1
        if ($expr === '@index') {
            return '<?php echo isset($index) ? $index : ""; ?>';
        }
        if ($expr === '@index_1') {
            return '<?php echo isset($index_1) ? $index_1 : ""; ?>';
        }

        // 含运算符的表达式
        if (preg_match('/[\+\-\*\/%]/', $expr)) {
            $phpExpr = preg_replace_callback(
                '/([a-zA-Z_][a-zA-Z0-9_.]*)/',
                function ($m) {
                    $v = $m[1];
                    if ($v === 'index' || $v === 'index_1' || $v === 'loop_index') {
                        return '$' . $v;
                    }
                    return 'CMS_Template_Compiler_V2::get_nested_var(isset($data)?$data:[],\'' . $v . '\')';
                },
                $expr
            );
            // 把 . 转换为数组访问
            $phpExpr = preg_replace('/\.([a-zA-Z_][a-zA-Z0-9_]*)/', "['$1']", $phpExpr);
            $phpExpr = str_replace('$index', '$index', $phpExpr);
            $phpExpr = str_replace('$index_1', '$index_1', $phpExpr);
            return '<?php echo ' . $phpExpr . '; ?>';
        }

        // 点号路径
        if (strpos($expr, '.') !== false || strpos($expr, '[') !== false) {
            $escaped = addslashes($expr);
            return '<?php $__val = CMS_Template_Compiler_V2::get_nested_var(isset($data) ? $data : [], \'' . $escaped . '\'); echo $__val !== null ? esc_html(is_array($__val) ? \'\' : (string)$__val) : \'\'; ?>';
        }

        // 简单变量
        return '<?php echo (isset($data) && is_array($data) && array_key_exists(\'' . $expr . '\', $data)) ? esc_html($data[\'' . $expr . '\']) : (isset($' . $expr . ') ? esc_html($' . $expr . ') : \'\'); ?>';
    }

    public function compileRawEcho(TplRawEchoNode $node)
    {
        $var = $node->var;
        if (strpos($var, '.') !== false) {
            $parts  = explode('.', $var);
            $access = '$' . array_shift($parts);
            foreach ($parts as $p) {
                $access .= "['{$p}']";
            }
            return '<?php echo isset(' . $access . ') ? esc_html(' . $access . ') : \'\'; ?>';
        }
        return '<?php echo (isset($data) && is_array($data) && array_key_exists(\'' . $var . '\', $data)) ? esc_html($data[\'' . $var . '\']) : (isset($' . $var . ') ? esc_html($' . $var . ') : \'\'); ?>';
    }

    public function compileIf(TplIfNode $node)
    {
        $rawCond = trim((string) $node->condition);
        $tmpCond = $rawCond;
        if (preg_match('/^condition\s*=\s*["\'](.+)["\']$/s', $tmpCond, $m)) {
            $tmpCond = trim($m[1]);
        }
        $neg = false;
        if ($tmpCond !== '' && $tmpCond[0] === '!') {
            $neg = true;
            $tmpCond = trim(substr($tmpCond, 1));
        }

        if ($tmpCond === 'pagination') {
            $loopNode = $this->findNearestLoopNodeForPagination();
            if ($loopNode instanceof TplLoopNode && is_string($loopNode->uid) && $loopNode->uid !== '') {
                $uid = addslashes($loopNode->uid);
                $source = addslashes($loopNode->source);
                $paramsExpr = $this->compileParamsToArray($loopNode->params);
                $pre = '<?php if (!isset($__todo_loop_ctx) || !is_array($__todo_loop_ctx)) { $__todo_loop_ctx = []; } ?>';
                $pre .= '<?php $__todo_loop_uid = \'' . $uid . '\'; if (!isset($__todo_loop_ctx[$__todo_loop_uid])) { $__todo_pre_parent = (isset($data) && is_array($data)) ? $data : []; $__todo_pre_items = CMS_Template_Compiler_V2::get_loop_data(\'' . $source . '\', $__todo_pre_parent, ' . $paramsExpr . '); if (!empty(CMS_Template_Compiler_V2::$loop_pagination)) { $__pagination = CMS_Template_Compiler_V2::$loop_pagination; $__base_url = function_exists(\'todo_get_current_url\') ? todo_get_current_url() : (function_exists(\'todo_home_url\') ? todo_home_url(\'/\') : \'/\'); if (function_exists(\'get_language_url\') && function_exists(\'current_locale\')) { $__base_url = get_language_url(current_locale(), $__base_url); } $__page_param = isset($__pagination[\'pagination_page_param\']) ? $__pagination[\'pagination_page_param\'] : \'paged\'; $__mid_size = isset($__pagination[\'pagination_mid_size\']) ? max(0, intval($__pagination[\'pagination_mid_size\'])) : 2; $__end_size = isset($__pagination[\'pagination_end_size\']) ? max(0, intval($__pagination[\'pagination_end_size\'])) : 1; $__pagination[\'pagination_prev_url\'] = ($__pagination[\'pagination_prev_page\'] > 0) ? CMS_Template_Compiler_V2::build_paginated_url($__pagination[\'pagination_prev_page\'], $__base_url, $__page_param) : \'\'; $__pagination[\'pagination_next_url\'] = ($__pagination[\'pagination_next_page\'] > 0) ? CMS_Template_Compiler_V2::build_paginated_url($__pagination[\'pagination_next_page\'], $__base_url, $__page_param) : \'\'; $__pagination[\'pagination_pages\'] = CMS_Template_Compiler_V2::build_pagination_pages($__pagination[\'pagination_current_page\'], $__pagination[\'pagination_total_pages\'], $__base_url, $__page_param, $__mid_size, $__end_size); $__todo_pre_parent = array_merge($__todo_pre_parent, $__pagination); CMS_Template_Compiler_V2::$loop_pagination = []; } $__todo_loop_ctx[$__todo_loop_uid] = [\'items\' => $__todo_pre_items, \'data\' => $__todo_pre_parent]; } $_tmp_pscope = (isset($__todo_loop_ctx[$__todo_loop_uid][\'data\']) && is_array($__todo_loop_ctx[$__todo_loop_uid][\'data\'])) ? $__todo_loop_ctx[$__todo_loop_uid][\'data\'] : ((isset($data) && is_array($data)) ? $data : []); $__todo_pagination_scope = (!empty(CMS_Template_Compiler_V2::$last_loop_pagination)) ? CMS_Template_Compiler_V2::$last_loop_pagination : $_tmp_pscope; ?>';
                $condExpr = '((is_array($__todo_pagination_scope) && array_key_exists(\'pagination\', $__todo_pagination_scope)) ? CMS_Template_Compiler_V2::is_truthy($__todo_pagination_scope[\'pagination\']) : false)';
                if ($neg) $condExpr = '!(' . $condExpr . ')';
                $out = $pre . '<?php $__todo_prev_data__pagination = isset($data) ? $data : null; $data = $__todo_pagination_scope; ?>';
                $out .= '<?php if (' . $condExpr . '): ?>';
            } else {
                $fallbackCondExpr = $this->compileCondition($rawCond);
                // 优先使用 $last_loop_pagination（由 render_loop_helper 中的块标签路径写入，
                // 如 {{todo:products|per_page=2}} 这类 TplTagNode 渲染后设置的分页元数据）。
                // 其次回退到 $__todo_last_loop_data（TplLoopNode 循环结束后写入），
                // 最终兜底到外层 $data。
                $scopeExpr = '(!empty(CMS_Template_Compiler_V2::$last_loop_pagination)) ? CMS_Template_Compiler_V2::$last_loop_pagination : ((isset($__todo_last_loop_data) && is_array($__todo_last_loop_data)) ? $__todo_last_loop_data : ((isset($data) && is_array($data)) ? $data : []))';
                $condExpr = '((is_array($__todo_pagination_scope) && array_key_exists(\'pagination\', $__todo_pagination_scope)) ? CMS_Template_Compiler_V2::is_truthy($__todo_pagination_scope[\'pagination\']) : (' . $fallbackCondExpr . '))';
                if ($neg) $condExpr = '!(' . $condExpr . ')';
                $out = '<?php $__todo_pagination_scope = ' . $scopeExpr . '; $__todo_prev_data__pagination = isset($data) ? $data : null; $data = $__todo_pagination_scope; ?>';
                $out .= '<?php if (' . $condExpr . '): ?>';
            }
            $out .= $this->compile($node->body);

            foreach ($node->elseifs as $eif) {
                $out .= '<?php elseif (' . $this->compileCondition($eif['condition']) . '): ?>';
                $out .= $this->compile($eif['body']);
            }

            if (!empty($node->else)) {
                $out .= '<?php else: ?>';
                $out .= $this->compile($node->else);
            }

            $out .= '<?php endif; $data = $__todo_prev_data__pagination; ?>';
            return $out;
        }

        $condExpr = $this->compileCondition($rawCond);

        $out = '<?php if (' . $condExpr . '): ?>';
        $out .= $this->compile($node->body);

        foreach ($node->elseifs as $eif) {
            $out .= '<?php elseif (' . $this->compileCondition($eif['condition']) . '): ?>';
            $out .= $this->compile($eif['body']);
        }

        if (!empty($node->else)) {
            $out .= '<?php else: ?>';
            $out .= $this->compile($node->else);
        }

        $out .= '<?php endif; ?>';
        return $out;
    }

    /**
     * 编译 if / elseif 条件表达式
     */
    private function compileCondition($raw)
    {
        $raw = trim($raw);

        // condition="expr" 格式
        if (preg_match('/^condition\s*=\s*["\'](.+)["\']$/s', $raw, $m)) {
            $raw = trim($m[1]);
        }

        // 空条件
        if ($raw === '') {
            return 'false';
        }

        // 处理条件中的嵌套 {{todo:xxx}} 标签 → 替换为 call_taglib_tag 调用
        $raw = $this->resolveNestedTagsInCondition($raw);

        // !function_name 格式
        $negation = false;
        if ($raw !== '' && $raw[0] === '!') {
            $negation = true;
            $raw      = substr($raw, 1);
        }

        // 点号变量（如 item.title、user.avatar）→ 使用 get_nested_var 与 is_truthy 检查
        if (strpos($raw, '.') !== false && !preg_match('/[<>=!\s\(]/', $raw)) {
            $escaped = addslashes($raw);
            $cond = "CMS_Template_Compiler_V2::is_truthy(CMS_Template_Compiler_V2::get_nested_var(isset(\$data) ? \$data : [], '{$escaped}'))";
            return $negation ? "!({$cond})" : $cond;
        }

        // 多条件 OR/AND 逻辑 → 交由 evaluate_todo_condition 运行时评估
        if (strpos($raw, '||') !== false || strpos($raw, '&&') !== false) {
            $escaped = addslashes($raw);
            $cond = 'CMS_Template_Compiler_V2::evaluate_todo_condition(\'' . $escaped . '\', isset($data) ? $data : [])';
            return $negation ? '!(' . $cond . ')' : $cond;
        }

        $loopBases = ['posts', 'categories', 'tags', 'post_tags', 'search_results', 'hot_searches', 'related_posts_by_tags', 'special_featured_posts', 'special_categories', 'special_tags', 'related_content'];
        $loopRaw = $raw;
        $loopBase = $raw;
        if (strpos($raw, '|') !== false) {
            list($loopBase) = explode('|', $raw, 2);
            $loopBase = trim($loopBase);
        }
        if (in_array($loopBase, $loopBases, true)) {
            $escaped = addslashes($loopRaw);
            $cond = '(is_array($__tmp_ld = CMS_Template_Compiler_V2::get_loop_data(\'' . $escaped . '\', isset($data) ? $data : [])) && !empty($__tmp_ld))';
            return $negation ? '!(' . $cond . ')' : $cond;
        }

        // 包含比较运算符 → 通过 evaluate_todo_condition 评估
        if (preg_match('/[<>=!]/', $raw) && !preg_match('/^(true|false|null)$/i', $raw)) {
            $escaped = addslashes($raw);
            $cond = 'CMS_Template_Compiler_V2::evaluate_todo_condition(\'' . $escaped . '\', isset($data) ? $data : [])';
            return $negation ? '!(' . $cond . ')' : $cond;
        }

        // 特殊条件函数
        if ($raw === 'user_logged_in') {
            $cond = '(function_exists(\'is_user_logged_in\') && is_user_logged_in())';
            return $negation ? '!(' . $cond . ')' : $cond;
        }
        if ($raw === 'user_not_logged_in') {
            $cond = '!(function_exists(\'is_user_logged_in\') && is_user_logged_in())';
            return $negation ? '!(' . $cond . ')' : $cond;
        }

        // 简单条件：优先检查 $data 中的变量数据是否存在且为真，再回退调用标签库
        $escaped = addslashes($raw);
        $cond = '(' .
            '(isset($data[\'' . $escaped . '\']) && CMS_Template_Compiler_V2::is_truthy($data[\'' . $escaped . '\']))' .
            ' || ' .
            'CMS_Template_Compiler_V2::is_truthy(CMS_Template_Compiler_V2::call_taglib_tag(\'' . $escaped . '\', \'\', isset($data) ? $data : []))' .
        ')';
        return $negation ? '!(' . $cond . ')' : $cond;
    }

    /**
     * 将条件中的 {{todo:xxx params}} 嵌套标签替换为 call_taglib_tag 调用
     */
    private function resolveNestedTagsInCondition($expr)
    {
        if (strpos($expr, '{{todo:') === false && strpos($expr, '{{') === false) {
            return $expr;
        }

        return preg_replace_callback(
            '/\{\{todo:([a-zA-Z_][a-zA-Z0-9_.]*)\s*([^}]*)\}\}/',
            function ($m) {
                $tag    = $m[1];
                $params = trim($m[2]);
                $params = rtrim($params, ' /');
                if ($params !== '' && $params[0] === '|') {
                    $params = substr($params, 1);
                }
                $paramsEsc = str_replace("'", "\\'", $params);
                return sprintf(
                    'CMS_Template_Compiler_V2::call_taglib_tag(\'%s\', \'%s\', isset($data) ? $data : [])',
                    addslashes($tag),
                    $paramsEsc
                );
            },
            $expr
        );
    }

    public function compileLoop(TplLoopNode $node)
    {
        $hash   = $node->uid !== '' ? $node->uid : $this->nextUid($node->source . $node->params);
        $source = $node->source;
        $params = $node->params;

        // 编译参数为 PHP 数组
        $paramsExpr = $this->compileParamsToArray($params);

        $itemsVar  = '$__items_' . $hash;
        $parentVar = '$__parent_' . $hash;
        $indexVar  = '$__idx_' . $hash;
        $prevVar   = '$__prev_' . $hash;
        $prevPostVar = '$__prev_post_' . $hash;

        $uidEsc = addslashes($hash);
        $withAssigned = PHP_EOL .
            'if (!isset($__todo_loop_ctx) || !is_array($__todo_loop_ctx)) { $__todo_loop_ctx = []; }' . PHP_EOL .
            '$__todo_loop_uid = \'' . $uidEsc . '\';' . PHP_EOL .
            $prevVar   . ' = isset($GLOBALS[\'todo_post\']) ? $GLOBALS[\'todo_post\'] : null;' . PHP_EOL .
            $prevPostVar . ' = isset($GLOBALS[\'post\']) ? $GLOBALS[\'post\'] : null;' . PHP_EOL .
            'if (isset($__todo_loop_ctx[$__todo_loop_uid])) {' . PHP_EOL .
            '    $__todo_cached = $__todo_loop_ctx[$__todo_loop_uid];' . PHP_EOL .
            '    ' . $parentVar . ' = (isset($__todo_cached[\'data\']) && is_array($__todo_cached[\'data\'])) ? $__todo_cached[\'data\'] : ((isset($data) && is_array($data)) ? $data : []);' . PHP_EOL .
            '    ' . $itemsVar . ' = (isset($__todo_cached[\'items\']) && is_array($__todo_cached[\'items\'])) ? $__todo_cached[\'items\'] : [];' . PHP_EOL .
            '} else {' . PHP_EOL .
            '    ' . $parentVar . ' = (isset($data) && is_array($data)) ? $data : [];' . PHP_EOL .
            '    ' . $itemsVar  . ' = CMS_Template_Compiler_V2::get_loop_data(\'' . addslashes($source) . '\', isset($data) ? $data : [], ' . $paramsExpr . ');' . PHP_EOL .
            '    if (!empty(CMS_Template_Compiler_V2::$loop_pagination)) {' . PHP_EOL .
            '        $__pagination = CMS_Template_Compiler_V2::$loop_pagination;' . PHP_EOL .
            '        $__base_url = function_exists(\'todo_get_current_url\') ? todo_get_current_url() : (function_exists(\'todo_home_url\') ? todo_home_url(\'/\') : \'/\');' . PHP_EOL .
            '        if (function_exists(\'get_language_url\') && function_exists(\'current_locale\')) { $__base_url = get_language_url(current_locale(), $__base_url); }' . PHP_EOL .
            '        $__page_param = isset($__pagination[\'pagination_page_param\']) ? $__pagination[\'pagination_page_param\'] : \'paged\';' . PHP_EOL .
            '        $__mid_size = isset($__pagination[\'pagination_mid_size\']) ? max(0, intval($__pagination[\'pagination_mid_size\'])) : 2;' . PHP_EOL .
            '        $__end_size = isset($__pagination[\'pagination_end_size\']) ? max(0, intval($__pagination[\'pagination_end_size\'])) : 1;' . PHP_EOL .
            '        $__pagination[\'pagination_prev_url\'] = ($__pagination[\'pagination_prev_page\'] > 0) ? CMS_Template_Compiler_V2::build_paginated_url($__pagination[\'pagination_prev_page\'], $__base_url, $__page_param) : \'\';' . PHP_EOL .
            '        $__pagination[\'pagination_next_url\'] = ($__pagination[\'pagination_next_page\'] > 0) ? CMS_Template_Compiler_V2::build_paginated_url($__pagination[\'pagination_next_page\'], $__base_url, $__page_param) : \'\';' . PHP_EOL .
            '        $__pagination[\'pagination_pages\'] = CMS_Template_Compiler_V2::build_pagination_pages($__pagination[\'pagination_current_page\'], $__pagination[\'pagination_total_pages\'], $__base_url, $__page_param, $__mid_size, $__end_size);' . PHP_EOL .
            '        ' . $parentVar . ' = array_merge(' . $parentVar . ', $__pagination);' . PHP_EOL .
            '        CMS_Template_Compiler_V2::$last_loop_pagination = $__pagination;' . PHP_EOL .
            '        CMS_Template_Compiler_V2::$loop_pagination = [];' . PHP_EOL .
            '    }' . PHP_EOL .
            '    $__todo_loop_ctx[$__todo_loop_uid] = [\'items\' => ' . $itemsVar . ', \'data\' => ' . $parentVar . '];' . PHP_EOL .
            '}' . PHP_EOL .
            'if (!empty(' . $itemsVar . ') && is_array(' . $itemsVar . ')):' . PHP_EOL .
            '    ' . $indexVar . ' = 0;' . PHP_EOL .
            '    foreach (' . $itemsVar . ' as $__item):' . PHP_EOL .
            '        $index = ' . $indexVar . '++;' . PHP_EOL .
            '        $index_1 = $index + 1;' . PHP_EOL .
            '        $data = CMS_Template_Compiler_V2::merge_loop_context(' . $parentVar . ', $__item, $index, $index_1);' . PHP_EOL .
            '        if (is_object($__item) && isset($__item->ID)) {' . PHP_EOL .
            '            $GLOBALS[\'post\'] = $__item;' . PHP_EOL .
            '            if (function_exists(\'setup_postdata\')) { setup_postdata($__item); }' . PHP_EOL .
            '        } elseif (is_array($__item) && isset($__item[\'ID\'])) {' . PHP_EOL .
            '            $GLOBALS[\'post\'] = (object)$__item;' . PHP_EOL .
            '            if (function_exists(\'setup_postdata\')) { setup_postdata($GLOBALS[\'post\']); }' . PHP_EOL .
            '        }' . PHP_EOL .
            '        $GLOBALS[\'todo_post\'] = is_object($__item) ? $__item : (object)$__item;' . PHP_EOL .
            '?>';

        $body = $this->compile($node->body);

        $close = '<?php endforeach;' . PHP_EOL .
            '    unset($__todo_loop_ctx[$__todo_loop_uid]);' . PHP_EOL .
            '    $data = ' . $parentVar . ';' . PHP_EOL .
            '    $__todo_last_loop_data = $data;' . PHP_EOL .
            '    if (function_exists(\'todo_reset_postdata\')) { todo_reset_postdata(); }' . PHP_EOL .
            '    if (' . $prevVar . ' !== null) { $GLOBALS[\'todo_post\'] = ' . $prevVar . '; } else { unset($GLOBALS[\'todo_post\']); }' . PHP_EOL .
            '    if (' . $prevPostVar . ' !== null) { $GLOBALS[\'post\'] = ' . $prevPostVar . '; } else { unset($GLOBALS[\'post\']); }' . PHP_EOL .
            'endif; ?>';

        return '<?php ' . $withAssigned . $body . $close;
    }

    public function compileInclude(TplIncludeNode $node)
    {
        if (empty($node->file)) {
            return '<!-- Error: No file specified for include -->';
        }

        $basePath = $this->templatePath;
        $safeFile = $this->validateIncludePath($node->file, $basePath);

        if ($safeFile === false) {
            return '<!-- Security: Invalid include file: ' . $node->file . ' -->';
        }

        // 记录依赖
        $templateDir = dirname($basePath);
        $fullPath    = $templateDir . '/' . ltrim($safeFile, '/');
        if (!file_exists($fullPath)) {
            foreach (['html', 'htm', 'php'] as $ext) {
                $tryPath = preg_replace('/\.(html|htm|php)$/i', '', $fullPath) . '.' . $ext;
                if (file_exists($tryPath)) {
                    $fullPath = $tryPath;
                    break;
                }
            }
        }
        if (file_exists($fullPath)) {
            $depKey = md5($fullPath);
            if (!isset($this->dependencies[$depKey])) {
                $this->dependencies[$depKey] = [
                    'full_path' => $fullPath,
                    'relative'  => $safeFile,
                    'mtime'     => filemtime($fullPath),
                ];
            }
        }

        $safeEscaped = addslashes($safeFile);
        return '<?php try { $__include_file = get_template_directory() . \'/' . $safeEscaped . '\'; echo CMS_Template_Compiler_V2::render_template($__include_file, get_defined_vars()); } catch (Exception $e) { error_log(\'TodoCMS Template Compiler: Include error - \' . $e->getMessage()); } ?>';
    }

    public function compileTag(TplTagNode $node)
    {
        $name   = $node->name;
        $params = $node->params;

        // 注册的自定义标签优先级
        $paramsExpr = $this->compileParamsToArray($params);

        // 块标签
        if ($node->body !== null) {
            if ($this->shouldPassRawBlockToTaglib($name)) {
                $rawBody = $this->serializeNodesToTemplate($node->body);
                return '<?php echo CMS_Template_Compiler_V2::call_taglib_tag(\'' . addslashes($name) . '\', ' . $paramsExpr . ', isset($data) ? $data : [], null, ' . var_export($rawBody, true) . '); ?>';
            }
            $bodyCode = $this->compile($node->body);
            return '<?php ob_start(); ?>' . $bodyCode . '<?php $__block_content = ob_get_clean(); echo CMS_Template_Compiler_V2::call_taglib_tag(\'' . addslashes($name) . '\', ' . $paramsExpr . ', isset($data) ? $data : [], null, $__block_content); ?>';
        }

        // 自闭合标签
        return '<?php echo CMS_Template_Compiler_V2::call_taglib_tag(\'' . addslashes($name) . '\', ' . $paramsExpr . ', isset($data) ? $data : []); ?>';
    }

    private function shouldPassRawBlockToTaglib($tagName)
    {
        if (in_array($tagName, [
            'post_categories', 'post_tags', 'post_property', 'post_properties',
            'archives', 'links', 'categories', 'tags', 'articles',
            'products', 'jobs', 'specials', 'guestbooks', 'single_pages',
            'search_results', 'images', 'downloads', 'media',
            'cart_items', 'user_addresses', 'payment_methods', 'shipping_methods', 'user_orders',
            'c', 'order.payment_records', 'order.logs',
            'review_images', 'reviews', 'items'
        ], true)) {
            return true;
        }

        if (preg_match('/(_images|_items|_records|_logs|_orders|_products|_list|_reviews|\.items|\.records|\.logs)$/i', $tagName)) {
            return true;
        }

        return !isset(self::$registered_tags[$tagName]);
    }

    private function serializeNodesToTemplate($nodes)
    {
        $out = '';
        foreach ((array) $nodes as $n) {
            $out .= $this->serializeNodeToTemplate($n);
        }
        return $out;
    }

    private function serializeNodeToTemplate($node)
    {
        if ($node instanceof TplTextNode) {
            return (string) $node->text;
        }
        if ($node instanceof TplEchoNode) {
            return '{{' . $node->expr . '}}';
        }
        if ($node instanceof TplRawEchoNode) {
            return '{$' . $node->var . '}';
        }
        if ($node instanceof TplLanguageNode) {
            $ctx = $node->context !== null ? (string) $node->context : '';
            return '{' . (string) $node->text . '|todo_lang|' . $ctx . '}';
        }
        if ($node instanceof TplIncludeNode) {
            $file = (string) $node->file;
            if ($file !== '') {
                return '{{todo:include file="' . $file . '"}}';
            }
            return '{{todo:include}}';
        }
        if ($node instanceof TplIfNode) {
            $out = '{{todo:if ' . (string) $node->condition . '}}';
            $out .= $this->serializeNodesToTemplate($node->body);
            foreach ((array) $node->elseifs as $eif) {
                $cond = isset($eif['condition']) ? (string) $eif['condition'] : '';
                $out .= '{{todo:elseif ' . $cond . '}}';
                $out .= $this->serializeNodesToTemplate(isset($eif['body']) ? $eif['body'] : []);
            }
            if (!empty($node->else)) {
                $out .= '{{todo:else}}';
                $out .= $this->serializeNodesToTemplate($node->else);
            }
            $out .= '{{/todo:if}}';
            return $out;
        }
        if ($node instanceof TplLoopNode) {
            $params = trim((string) $node->params);
            $open = '{{todo:loop ' . (string) $node->source . ($params !== '' ? '|' . $params : '') . '}}';
            return $open . $this->serializeNodesToTemplate($node->body) . '{{/todo:loop}}';
        }
        if ($node instanceof TplTagNode) {
            $params = trim((string) $node->params);
            $open = '{{todo:' . (string) $node->name . ($params !== '' ? ' ' . $params : '') . '}}';
            if ($node->body === null) {
                return $open;
            }
            return $open . $this->serializeNodesToTemplate($node->body) . '{{/todo:' . (string) $node->name . '}}';
        }
        return '';
    }

    public function compileLanguage(TplLanguageNode $node)
    {
        $textEsc    = "'" . str_replace("'", "\\'", $node->text) . "'";
        if (!empty($node->context)) {
            $ctxEsc = "'" . str_replace("'", "\\'", $node->context) . "'";
        } else {
            $ctxEsc = "isset(\$data['__todo_lang_context']) ? \$data['__todo_lang_context'] : null";
        }
        return '<?php echo function_exists(\'todo_language_manager\') ? todo_language_manager()->translate(' . $textEsc . ', ' . $ctxEsc . ') : ' . $textEsc . '; ?>';
    }

    // ---- 工具方法 ----

    /**
     * 将标签参数字符串编译为 PHP 数组表达式
     */
    private function compileParamsToArray($params)
    {
        $params = trim((string) $params);
        if ($params === '') {
            return '[]';
        }

        // 如果已经是 PHP 代码，直接用
        if (strpos($params, '<?php') !== false) {
            return var_export($params, true);
        }

        $attrs = $this->parseTagAttrs($params);
        if (empty($attrs)) {
            // 没有 key=value，整个 params 作为默认参数传入
            return "'" . str_replace("'", "\\'", $params) . "'";
        }

        return var_export($attrs, true);
    }

    /**
     * 解析 key=value 格式的参数（兼容空格、管道、逗号分隔）
     */
    private function parseTagAttrs($str)
    {
        $attrs = [];
        if (empty($str)) {
            return $attrs;
        }

        // 统一分隔符（| 转为空格，保持逗号用于带引号的值如 key="val1,val2"）
        $str = str_replace('|', ' ', $str);

        // key="value" / key='value' / key=value
        if (preg_match_all('/([a-zA-Z0-9_]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|([^\s]+))/', $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name  = $m[1];
                $value = '';
                if (isset($m[2]) && $m[2] !== '') {
                    $value = stripslashes($m[2]);
                } elseif (isset($m[3]) && $m[3] !== '') {
                    $value = stripslashes($m[3]);
                } elseif (isset($m[4]) && $m[4] !== '') {
                    $value = $m[4];
                }
                $attrs[$name] = $value;
            }
        }

        return $attrs;
    }

    /**
     * 验证 include 路径安全性
     */
    private function validateIncludePath($file, $basePath)
    {
        if (class_exists('Todo_Template_Include_Security')) {
            $result = Todo_Template_Include_Security::validate($file, $basePath);
            if ($result['valid']) {
                return $result['normalized_path'];
            }
        }
        // 回退：基本安全检查
        $file = basename($file);
        if (empty($file)) {
            return false;
        }
        // 不允许路径遍历
        if (strpos($file, '..') !== false) {
            return false;
        }
        return $file;
    }

    private function nextUid($seed)
    {
        return substr(md5($seed . $this->uidCounter++), 0, 8);
    }

    private function assignLoopUids(array $nodes)
    {
        foreach ($nodes as $n) {
            if ($n instanceof TplLoopNode) {
                if (!is_string($n->uid) || $n->uid === '') {
                    $n->uid = substr(md5($this->templatePath . '|' . $this->uidCounter++ . '|' . $n->source . '|' . $n->params), 0, 8);
                }
            }
        }
    }

    private function findNearestLoopNodeForPagination()
    {
        if (!is_array($this->currentNodes) || $this->currentIndex < 0) return null;
        $nodes = $this->currentNodes;
        $n = count($nodes);
        $prev = null; $prevIdx = -1;
        for ($i = $this->currentIndex - 1; $i >= 0; $i--) {
            if ($nodes[$i] instanceof TplLoopNode) { $prev = $nodes[$i]; $prevIdx = $i; break; }
        }
        $next = null; $nextIdx = -1;
        for ($i = $this->currentIndex + 1; $i < $n; $i++) {
            if ($nodes[$i] instanceof TplLoopNode) { $next = $nodes[$i]; $nextIdx = $i; break; }
        }
        if ($prev === null && $next === null) return null;

        $hasPaginationBetween = function ($fromIdx, $toIdx) use ($nodes) {
            if ($fromIdx < 0 || $toIdx < 0) return false;
            if ($fromIdx > $toIdx) return false;
            for ($j = $fromIdx; $j <= $toIdx; $j++) {
                $nn = $nodes[$j];
                if ($nn instanceof TplIfNode && $this->isPaginationIfNode($nn)) {
                    return true;
                }
            }
            return false;
        };

        $prevOk = false;
        if ($prev !== null && $prevIdx >= 0) {
            $prevOk = !$hasPaginationBetween($prevIdx + 1, $this->currentIndex - 1);
        }
        $nextOk = false;
        if ($next !== null && $nextIdx >= 0) {
            $nextOk = !$hasPaginationBetween($this->currentIndex + 1, $nextIdx - 1);
        }

        if ($prevOk) return $prev;
        if ($nextOk) return $next;
        if ($prev !== null) return $prev;
        return $next;
    }

    private function isPaginationIfNode(TplIfNode $node)
    {
        $tmpCond = trim((string) $node->condition);
        if (preg_match('/^condition\s*=\s*["\'](.+)["\']$/s', $tmpCond, $m)) {
            $tmpCond = trim($m[1]);
        }
        if ($tmpCond !== '' && $tmpCond[0] === '!') {
            $tmpCond = trim(substr($tmpCond, 1));
        }
        return $tmpCond === 'pagination';
    }
}

// ============================================================================
// 7. 引擎外壳 —— 完整的编译型模板引擎，取代 Todo_Compiled_Template_Engine
// ============================================================================

class CMS_Template_Compiler_V2
{
    const VERSION = '3.0.15';

    /** @var string|null */
    private static $compileDir = null;
    /** @var string|null */
    private static $templateDir = null;
    /** @var bool */
    private static $enabled = true;
    /** @var array */
    private static $taglibs = [];
    /** @var array */
    private static $registered_tags = [];

    /**
     * 流式输出缓冲（用于Early Flush时不丢失缓存内容）
     * @var string
     */
    public static $stream_buffer = '';

    /**
     * Early Flush 截取的头部 HTML 内容
     * @var string
     */
    public static $early_flushed_head = '';

    /**
     * 当前请求是否触发了 Early Flush
     * @var bool
     */
    public static $has_early_flushed = false;

    /**
     * loop_posts 计算的分页元数据，compileLoop 渲染后注入模板数据
     * 键: 每页条数 (per_page)，值: pagination / total_pages 等
     */
    public static $loop_pagination = [];

    /**
     * 最近一次循环的分页数据（持久保留，不清空）
     */
    public static $last_loop_pagination = [];

    /**
     * 标签名别名映射 —— 将模板中的短名映射到 taglib 实际方法名
     * 键: 模板标签名 → 值: 实际方法后缀 (parse_{value})
     */
    private static $tagAliases = [
        'permalink'      => 'post_url',
        'featured_image' => 'post_thumbnail',
        'author'         => 'author_display_name',
        'author_url'     => 'author_url',
    ];
    /** @var string */
    private static $tag_prefix = 'todo';
    /** @var array */
    private static $stats = [
        'compiled'    => 0,
        'cache_hits'  => 0,
        'cache_misses' => 0,
        'errors'      => 0,
    ];
    /** @var array */
    private static $dependencies_cache = [];
    /** @var array */
    private static $user_cache = [];
    /** @var array */
    private static $model_row_cache = [];
    /** @var array */
    private static $post_custom_fields_cache = [];
    /** @var array */
    private static $table_exists_cache = [];

    /**
     * 获取用户数据，支持静态内存缓存与 Todo_Cache_Manager
     */
    public static function get_user_by_id($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return null;

        if (array_key_exists($user_id, self::$user_cache)) {
            return self::$user_cache[$user_id];
        }

        $cache_key = 'user_row_' . $user_id;
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'users', 'global');
            if ($cached !== false && $cached !== null) {
                $res = ($cached === 'none') ? null : (object) $cached;
                self::$user_cache[$user_id] = $res;
                return $res;
            }
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            self::$user_cache[$user_id] = null;
            return null;
        }

        $prefix = isset($tododb->prefix) ? $tododb->prefix : (defined('TABLE_PREFIX') ? TABLE_PREFIX : 'todo_');
        $row = $tododb->get_row("SELECT * FROM {$prefix}users WHERE ID = ? LIMIT 1", [$user_id]);
        $res = $row ? $row : null;
        self::$user_cache[$user_id] = $res;

        if (class_exists('Todo_Cache_Manager')) {
            $save = $res ? (array) $res : 'none';
            Todo_Cache_Manager::set($cache_key, $save, 86400, 'users', 'global');
        }

        return $res;
    }

    /**
     * 获取模型扩展表数据行，支持静态内存缓存与 Todo_Cache_Manager
     */
    public static function get_model_table_row($table, $post_id)
    {
        $post_id = (int) $post_id;
        if (empty($table) || $post_id <= 0) return null;

        $cache_id = $table . '_' . $post_id;
        if (array_key_exists($cache_id, self::$model_row_cache)) {
            return self::$model_row_cache[$cache_id];
        }

        $cache_key = 'model_row_' . md5($cache_id);
        if (class_exists('Todo_Cache_Manager')) {
            $cached = Todo_Cache_Manager::get($cache_key, 'model_fields', 'global');
            if ($cached !== false && $cached !== null) {
                $res = ($cached === 'none') ? null : (array) $cached;
                self::$model_row_cache[$cache_id] = $res;
                return $res;
            }
        }

        global $tododb;
        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            self::$model_row_cache[$cache_id] = null;
            return null;
        }

        // 检查扩展数据表是否存在，若不存在则提前返回 null 并记录缓存，避免引发 SQL 1146 异常
        $table_exists = false;
        if (method_exists($tododb, 'table_exists')) {
            $table_exists = $tododb->table_exists($table);
        } elseif (function_exists('todo_db_table_exists')) {
            $table_exists = todo_db_table_exists($table);
        } else {
            $table_exists = (bool) $tododb->get_var("SHOW TABLES LIKE '{$table}'");
        }

        if (!$table_exists) {
            self::$model_row_cache[$cache_id] = null;
            if (class_exists('Todo_Cache_Manager')) {
                Todo_Cache_Manager::set($cache_key, 'none', 86400, 'model_fields', 'global');
            }
            return null;
        }

        try {
            $row = $tododb->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
        } catch (Throwable $e) {
            $row = null;
        }
        $res = is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : null);
        self::$model_row_cache[$cache_id] = $res;

        if (class_exists('Todo_Cache_Manager')) {
            $save = $res ? $res : 'none';
            Todo_Cache_Manager::set($cache_key, $save, 86400, 'model_fields', 'global');
        }

        return $res;
    }

    // ======================================================================
    // Init & Configuration
    // ======================================================================

    public static function init()
    {
        self::$templateDir = function_exists('get_template_directory') ? get_template_directory() : (ABSPATH . 'content/themes/default');
        self::$compileDir  = null;

        $primary  = rtrim(ABSPATH . 'content/cache/compiled/', "/\\") . '/';
        $tmpBase  = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
        $fallback = rtrim($tmpBase, "/\\") . '/todocms-compiled/';

        foreach ([$primary, $fallback] as $dir) {
            $dir = rtrim((string) $dir, "/\\") . '/';
            if (!is_dir($dir)) {
                $created = @mkdir($dir, 0755, true);
                if (!$created && !is_dir($dir) && function_exists('todo_mkdir_p')) {
                    todo_mkdir_p($dir);
                }
            }
            if (!is_dir($dir)) {
                if ($dir === $primary) {
                    error_log("[Template Compiler V2] Directory does not exist: " . $dir);
                }
                continue;
            }
            if (!is_writable($dir)) {
                @chmod($dir, 0755);
            }
            if (!is_writable($dir)) {
                if ($dir === $primary) {
                    error_log("[Template Compiler V2] Directory not writable: " . $dir);
                }
                continue;
            }
            self::$compileDir = $dir;
            $indexFile = self::$compileDir . 'index.php';
            if (!file_exists($indexFile)) {
                @file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
            }
            break;
        }

        if (self::$compileDir === null) {
            error_log("[Template Compiler V2] No writable compile directory found");
            return false;
        }

        // 版本变更检测
        $versionFile = self::$compileDir . '.compiler_version';
        $oldVersion  = file_exists($versionFile) ? @file_get_contents($versionFile) : false;
        if ($oldVersion !== self::VERSION) {
            if ($oldVersion !== false) {
                self::clear_all_cache();
            }
            @file_put_contents($versionFile, self::VERSION);
        }

        // 注册默认标签库
        self::registerDefaultTaglib();

        // 从 Taglib_Manager 同步

        return true;
    }

    public static function set_enabled($enabled)
    {
        self::$enabled = (bool) $enabled;
    }

    public static function register_taglib($name, $handler)
    {
        self::$taglibs[$name] = $handler;
    }

    public static function register_tag($tag_name, $callback)
    {
        self::$registered_tags[$tag_name] = $callback;
    }

    private static function registerDefaultTaglib()
    {
        if (!class_exists('CMS_HTML_Template_Tags')) {
            $f = ABSPATH . 'core/classes/class-html-template-tags.php';
            if (file_exists($f)) require_once $f;
        }
        if (class_exists('CMS_HTML_Template_Tags')) {
            self::$taglibs['default'] = CMS_HTML_Template_Tags::get_instance();
            // 注册需要带参数回退的标签
            self::$registered_tags['date_iso'] = function ($attrs, $content, $data) {
                $h = CMS_HTML_Template_Tags::get_instance();
                return $h->parse_post_date(array_merge($attrs, ['format' => 'c']), $content ?? '', $data);
            };
        }
    }

    // ======================================================================
    // Compile Pipeline
    // ======================================================================

    /**
     * 编译模板文件并返回编译后 PHP 文件路径
     *
     * @param string $templatePath
     * @param array $options
     * @return string|false
     */
    public static function compile($templatePath, $options = [])
    {
        if (self::$compileDir === null && !self::init()) {
            error_log("[Template Compiler V2] compile failed: init() returned false for {$templatePath}");
            return false;
        }

        if (!self::$enabled || empty($templatePath) || !file_exists($templatePath)) {
            if (!file_exists($templatePath)) {
                self::$stats['errors']++;
                error_log("[Template Compiler V2] compile failed: template not found: {$templatePath}");
            } elseif (!self::$enabled) {
                error_log("[Template Compiler V2] compile failed: compiler disabled for {$templatePath}");
            }
            return false;
        }

        $templateMtime = filemtime($templatePath);
        $compileKey    = md5($templatePath . $templateMtime . serialize($options) . self::VERSION);
        $compileFile   = self::$compileDir . $compileKey . '.php';

        // 缓存命中
        if (self::checkCache($compileFile, $templatePath)) {
            self::$stats['cache_hits']++;
            return $compileFile;
        }

        // 读取模板
        $content = file_get_contents($templatePath);
        if ($content === false) {
            self::$stats['errors']++;
            error_log("[Template Compiler V2] compile failed: cannot read template: {$templatePath}");
            return false;
        }

        // 模板继承
        if (class_exists('Todo_Template_Inheritance')) {
            $content = Todo_Template_Inheritance::process($content, $templatePath);
            if ($content === false || $content === '' || $content === null) {
                error_log("[Template Compiler V2] compile failed: Todo_Template_Inheritance::process returned empty for {$templatePath}");
                // 回退到原始内容
                $content = file_get_contents($templatePath);
            }
        }

        self::$dependencies_cache = [];

        // 编译
        try {
            $phpCode = self::compileContent($content, $templatePath);
        } catch (Throwable $e) {
            self::$stats['errors']++;
            error_log("[Template Compiler V2] Compilation exception for {$templatePath}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return false;
        }

        if ($phpCode === false || $phpCode === '') {
            self::$stats['errors']++;
            error_log("[Template Compiler V2] compile failed: compileContent returned empty/false for {$templatePath}");
            return false;
        }

        // 注入依赖信息
        if (!empty(self::$dependencies_cache)) {
            $depParts = [];
            foreach (self::$dependencies_cache as $dep) {
                $file  = $dep['full_path'] ?? ($dep['relative'] ?? '');
                $mtime = $dep['mtime'] ?? 0;
                if ($file && $mtime > 0) {
                    $depParts[] = $file . '|' . $mtime;
                }
            }
            if (!empty($depParts)) {
                $depStr = "// Dependencies: " . implode(',', $depParts) . "\n";
                $phpCode = preg_replace('/^<\?php/', "<?php\n" . $depStr, $phpCode, 1);
            }
        }

        // 写入缓存
        if (@file_put_contents($compileFile, $phpCode) === false) {
            self::$stats['errors']++;
            error_log("[Template Compiler V2] compile failed: cannot write compiled file: {$compileFile}");
            return false;
        }
        @chmod($compileFile, 0644);

        self::$stats['compiled']++;
        self::$stats['cache_misses']++;

        return $compileFile;
    }

    /**
     * 编译模板内容为 PHP 代码
     */
    public static function compileContent($content, $templatePath = '')
    {
        $scanner = new TplScanner();
        $tokens  = $scanner->scan($content);
        $tokens  = TplTagPairer::pair($tokens);

        $parser = new TplParser();
        $ast    = $parser->parse($tokens, $templatePath);

        $compiler = new TplCompiler($templatePath);
        $body     = $compiler->compile($ast);

        // 收集依赖
        $deps = $compiler->getDependencies();
        foreach ($deps as $k => $v) {
            self::$dependencies_cache[$k] = $v;
        }

        $safePath = str_replace(["\r", "\n", "\t"], ' ', (string) $templatePath);
        $safePath = trim(preg_replace('/\s+/', ' ', $safePath));
        if (strlen($safePath) > 500) {
            $safePath = substr($safePath, 0, 500) . '...';
        }

        $php  = "<?php\n";
        $php .= "// Compiled by CMS_Template_Compiler_V2 v" . self::VERSION . " — DO NOT EDIT\n";
        $php .= "// Source: {$safePath}\n";
        $php .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $php .= "\$__original_data = isset(\$data) && is_array(\$data) ? \$data : [];\n";
        $php .= "if (!empty(\$__original_data)) {\n";
        $php .= "    extract(\$__original_data, EXTR_SKIP);\n";
        $php .= "}\n";
        $php .= "\$data = \$__original_data;\n\n";
        $php .= "?>\n";
        // 转义 <?xml 防止被 PHP short_open_tag 解析为 PHP 代码
        $body = str_replace('<?xml ', '<?php echo "<?xml "; ?>', $body);
        $php .= $body;

        return $php;
    }

    private static function checkCache($compileFile, $templatePath)
    {
        if (!self::$enabled) {
            return false;
        }

        if (!is_file($compileFile)) {
            return false;
        }

        // 源文件修改时间 vs 缓存文件修改时间
        if (file_exists($templatePath) && filemtime($templatePath) > filemtime($compileFile)) {
            return false;
        }

        // 检查依赖
        $firstLine = self::readFirstLines($compileFile, 5);
        if (preg_match('#//\s*Dependencies:\s*(.+)$#m', $firstLine, $m)) {
            $depList = explode(',', trim($m[1]));
            foreach ($depList as $dep) {
                $parts = explode('|', trim($dep));
                if (count($parts) >= 2) {
                    $depFile  = $parts[0];
                    $depMtime = (int) $parts[1];
                    if (file_exists($depFile) && filemtime($depFile) > $depMtime) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private static function readFirstLines($file, $count)
    {
        $fp = @fopen($file, 'r');
        if (!$fp) return '';
        $lines = '';
        for ($i = 0; $i < $count && !feof($fp); $i++) {
            $lines .= fgets($fp, 4096);
        }
        fclose($fp);
        return $lines;
    }

    // ======================================================================
    // Render
    // ======================================================================

    public static function render_template($templatePath, $data = [])
    {
        $compileFile = self::compile($templatePath);
        if (!$compileFile) {
            if (defined('TODO_DEBUG') && TODO_DEBUG) {
                throw new Exception("Failed to compile template: {$templatePath}");
            }
            return '';
        }
        return self::execute($compileFile, $data);
    }

    public static function render_string($content, $data = [])
    {
        if (function_exists('todo_register_user_center_tags')) {
            todo_register_user_center_tags();
        }
        if (self::$compileDir === null && !self::init()) {
            return '';
        }

        $contentHash = md5($content . self::VERSION);
        $compileFile = self::$compileDir . 'string_' . $contentHash . '.php';

        if (!file_exists($compileFile)) {
            $phpCode = self::compileContent($content, 'string:' . substr($content, 0, 20));
            if ($phpCode === false || @file_put_contents($compileFile, $phpCode) === false) {
                return '';
            }
        }

        return self::execute($compileFile, $data);
    }

    /**
     * 执行编译后的 PHP 文件
     */
    public static function execute($compileFile, $data = [])
    {
        if (!file_exists($compileFile)) {
            error_log("[Template Compiler V2] File not found: {$compileFile}");
            return '';
        }

        try {
            $originalData = $data;
            extract($data, EXTR_SKIP);
            if (!isset($data) || !is_array($data)) {
                $data = $originalData;
            }

            ob_start();

            // // 运行时诊断：输出模板数据顶层结构，便于调试标签解析问题（仅当非 API 请求时输出）
            // if (!defined('DOING_AJAX') || !DOING_AJAX) {
            //     $top_keys = is_array($originalData) ? array_keys($originalData) : ['not_array'];
            //     $posts_count = (is_array($originalData) && isset($originalData['posts'])) ? count($originalData['posts']) : 'N/A';
            //     echo '<!-- template-data: topKeys=[' . implode(',', $top_keys) . '] postsCount=' . $posts_count . ' -->';
            // }
            include $compileFile;
            $output = ob_get_clean();

            // 仅对编译产物本身的语法/致命错误才删除缓存；HTML 注释类模式不再触发删除
            if (preg_match('/\bParse error\b|\bFatal error\b|\bsyntax error\b/i', $output)) {
                error_log("[Template Compiler V2] PHP error in compiled output, deleting cache: {$compileFile}");
                self::clear_cache_by_file($compileFile);
                return '';
            }

            return $output;
        } catch (ParseError $e) {
            // 编译后 PHP 有语法错误 → 删除缓存让其重新编译
            error_log("[Template Compiler V2] ParseError, deleting cache: " . $e->getMessage());
            self::clear_cache_by_file($compileFile);
            return "<!-- Template ParseError: {$e->getMessage()} -->";
        } catch (Throwable $e) {
            // 运行时异常（如缺少函数/类）→ 保留编译文件，避免反复编译-删除循环
            // 清理输出缓冲区，防止已 echo 的内容（如调试注释）泄漏到 API JSON 响应中
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log("[Template Compiler V2] Runtime exception (cache kept): " . $e->getMessage());
            return '';
        }
    }

    // ======================================================================
    // Runtime Helpers —— 从编译后 PHP 中调用的工具方法
    // ======================================================================

    public static function format_post_item($row, $cat_id = 0)
    {
        if (empty($row)) return [];

        $id = is_object($row)
            ? (isset($row->ID) ? intval($row->ID) : 0)
            : (isset($row['ID']) ? intval($row['ID']) : 0);
        if ($id <= 0) {
            return is_object($row) ? get_object_vars($row) : (array) $row;
        }

        $title     = is_object($row) ? (string) ($row->post_title ?? '') : (string) ($row['post_title'] ?? '');
        $excerpt   = is_object($row) ? (string) ($row->post_excerpt ?? '') : (string) ($row['post_excerpt'] ?? '');
        $content   = is_object($row) ? (string) ($row->post_content ?? '') : (string) ($row['post_content'] ?? '');

        if ($id > 0) {
            if (function_exists('todo_get_post_multilang')) {
                $ml_data = todo_get_post_multilang($id, null, ['fallback' => true]);
                if (is_array($ml_data)) {
                    if (!empty($ml_data['title'])) {
                        $title = (string) $ml_data['title'];
                    }
                    if (!empty($ml_data['excerpt'])) {
                        $excerpt = (string) $ml_data['excerpt'];
                    }
                }
            } elseif (function_exists('todo_get_the_title')) {
                $ml_title = todo_get_the_title($id);
                if (!empty($ml_title)) {
                    $title = (string) $ml_title;
                }
            }
        }

        if (function_exists('todo_parse_inline_translations')) {
            $title = todo_parse_inline_translations($title);
            $excerpt = todo_parse_inline_translations($excerpt);
        }

        $date      = is_object($row) ? (string) ($row->post_date ?? '') : (string) ($row['post_date'] ?? '');
        $permalink = function_exists('todo_get_permalink')
            ? todo_get_permalink($id)
            : (function_exists('get_permalink') ? get_permalink($id) : (function_exists('todo_home_url') ? todo_home_url('?p=' . $id) : ''));

        $thumb = '';
        $fi    = is_object($row) ? ($row->featured_image ?? '') : ($row['featured_image'] ?? '');
        if (!empty($fi)) {
            if (function_exists('todo_match_featured_image_by_lang')) {
                $matched_url = todo_match_featured_image_by_lang($fi);
                if (!empty($matched_url)) {
                    $thumb = $matched_url;
                }
            }
            if ($thumb === '' && is_string($fi) && (strpos($fi, 'http') === 0 || strpos($fi, '/') === 0)) {
                if (strpos($fi, 'a:') !== 0 && strpos($fi, 's:') !== 0) {
                    $thumb = $fi;
                }
            }
            if ($thumb === '' && is_string($fi) && (strpos($fi, 'a:') === 0 || strpos($fi, 's:') === 0)) {
                $unserialized = function_exists('maybe_unserialize') ? maybe_unserialize($fi) : @unserialize($fi);
                if (is_array($unserialized) && !empty($unserialized)) {
                    $first = reset($unserialized);
                    $thumb = is_array($first) ? ($first['url'] ?? '') : (is_string($first) ? $first : '');
                } elseif (is_string($unserialized)) {
                    $thumb = $unserialized;
                }
            }
            if ($thumb === '' && is_string($fi) && ($fi[0] === '[' || $fi[0] === '{')) {
                $decoded = json_decode($fi, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $first = reset($decoded);
                    $thumb = is_array($first) ? ($first['url'] ?? '') : (is_string($first) ? $first : '');
                }
            }
            if ($thumb === '' && is_array($fi)) {
                $first = isset($fi['url']) ? $fi['url'] : reset($fi);
                $thumb = is_array($first) ? ($first['url'] ?? '') : (is_string($first) ? $first : '');
            }
        }
        if ($thumb === '' && function_exists('get_the_post_thumbnail_url')) {
            $thumb = get_the_post_thumbnail_url($id) ?: '';
        }

        // 将相对路径转为绝对 URL / 云存储 CDN URL (规则 1)
        if ($thumb !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
            $thumb = todo_media_url_to_runtime_absolute($thumb);
        }

        $authorId   = is_object($row) ? (int) ($row->post_author ?? 0) : (int) ($row['post_author'] ?? 0);
        $authorName = '';
        if ($authorId > 0 && function_exists('get_the_author_meta')) {
            $authorName = get_the_author_meta('display_name', $authorId);
        }
        if (empty($authorName)) {
            $authorName = '管理员';
        }

        $views          = is_object($row) ? (int) ($row->views ?? 0) : (int) ($row['views'] ?? 0);
        $commentsCount  = is_object($row) ? (int) ($row->comment_count ?? 0) : (int) ($row['comment_count'] ?? 0);
        $likes          = is_object($row) ? (int) ($row->like_count ?? 0) : (int) ($row['like_count'] ?? 0);

        // 将文章内容中的相对路径转为绝对 URL（RSS 等场景需要）
        $content = self::make_content_urls_absolute($content);

        $formatted = [
            'post_id'                => $id,
            'article_id'             => $id,
            'article_category_id'    => $cat_id,
            'article_title'          => $title,
            'article_excerpt'        => $excerpt,
            'article_content'        => $content,
            'article_created_at'     => $date,
            'publish_date'           => $date,
            'article_url'            => $permalink,
            'url'                    => $permalink,
            'permalink'              => $permalink,
            'special_url'            => $permalink,
            'special_id'             => $id,
            'article_featured_image' => $thumb,
            'article_author_name'    => $authorName,
            'article_views'          => $views,
            'article_comments_count' => $commentsCount,
            'article_likes'          => $likes,
            'likes'                  => $likes,
        ];

        $post_type = is_object($row) ? ($row->post_type ?? '') : ($row['post_type'] ?? '');
        $custom_fields = self::load_post_custom_fields($id, $post_type);
        $formatted['guest_name']    = $custom_fields['contact_name'] ?? '';
        $formatted['guest_email']   = $custom_fields['contact_email'] ?? '';
        $formatted['guest_phone']   = $custom_fields['contact_phone'] ?? '';
        $formatted['guest_subject'] = $title;
        $formatted['guest_message'] = $content;
        $formatted['guest_date']    = $date;
        $formatted['guest_id']      = $id;

        $original = is_object($row) ? get_object_vars($row) : (array) $row;
        return array_merge($original, $custom_fields, $formatted);
    }

    /**
     * 批量预加载自定义字段及模型扩展表数据，解决 N+1 查询瓶颈
     */
    public static function prime_post_custom_fields_batch(array $post_ids, array $rows = [])
    {
        global $tododb;
        if (empty($post_ids)) {
            return;
        }

        $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
        $uncached_ids = [];
        foreach ($post_ids as $pid) {
            if ($pid > 0 && !array_key_exists($pid, self::$post_custom_fields_cache)) {
                $uncached_ids[] = $pid;
            }
        }

        if (empty($uncached_ids)) {
            return;
        }

        foreach ($uncached_ids as $pid) {
            self::$post_custom_fields_cache[$pid] = [];
        }

        if (!$tododb && class_exists('CMS_DB')) {
            $tododb = new CMS_DB();
        }

        if (!$tododb) {
            return;
        }

        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (isset($tododb->prefix) ? $tododb->prefix : 'todo_');

        // 1. 批量查询 post_custom_fields 表
        $in_str = implode(',', array_fill(0, count($uncached_ids), '?'));
        try {
            $cf_rows = $tododb->get_results(
                "SELECT post_id, field_key, field_value FROM {$prefix}post_custom_fields WHERE post_id IN ({$in_str})",
                $uncached_ids
            );
            if (is_array($cf_rows)) {
                foreach ($cf_rows as $r) {
                    $pid = is_object($r) ? intval($r->post_id ?? 0) : intval($r['post_id'] ?? 0);
                    $key = is_object($r) ? ($r->field_key ?? '') : ($r['field_key'] ?? '');
                    $val = is_object($r) ? ($r->field_value ?? '') : ($r['field_value'] ?? '');
                    if ($pid > 0 && $key !== '') {
                        self::$post_custom_fields_cache[$pid][$key] = $val;
                    }
                }
            }
        } catch (Throwable $e) {
        }

        // 2. 批量拉取模型扩展表字段
        $post_types_by_id = [];
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $pid = is_object($r) ? intval($r->ID ?? 0) : intval($r['ID'] ?? 0);
                $pt  = is_object($r) ? ($r->post_type ?? '') : ($r['post_type'] ?? '');
                if ($pid > 0 && !empty($pt)) {
                    $post_types_by_id[$pid] = $pt;
                }
            }
        }

        $missing_pt_ids = array_diff($uncached_ids, array_keys($post_types_by_id));
        if (!empty($missing_pt_ids)) {
            $m_in = implode(',', array_fill(0, count($missing_pt_ids), '?'));
            try {
                $pt_rows = $tododb->get_results(
                    "SELECT ID, post_type FROM {$prefix}posts WHERE ID IN ({$m_in})",
                    array_values($missing_pt_ids)
                );
                if (is_array($pt_rows)) {
                    foreach ($pt_rows as $ptr) {
                        $pid = is_object($ptr) ? intval($ptr->ID ?? 0) : intval($ptr['ID'] ?? 0);
                        $pt  = is_object($ptr) ? ($ptr->post_type ?? '') : ($ptr['post_type'] ?? '');
                        if ($pid > 0 && !empty($pt)) {
                            $post_types_by_id[$pid] = $pt;
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $grouped_by_pt = [];
        foreach ($uncached_ids as $pid) {
            $pt = $post_types_by_id[$pid] ?? '';
            if ($pt !== '') {
                $grouped_by_pt[$pt][] = $pid;
            }
        }

        foreach ($grouped_by_pt as $pt => $g_pids) {
            $clean_pt = preg_replace('/[^a-zA-Z0-9_]/', '', $pt);
            if (empty($clean_pt)) continue;
            $model_table = $prefix . 'model_fields_' . $clean_pt;

            if (!array_key_exists($model_table, self::$table_exists_cache)) {
                if (method_exists($tododb, 'table_exists')) {
                    self::$table_exists_cache[$model_table] = $tododb->table_exists($model_table);
                } elseif (function_exists('todo_db_table_exists')) {
                    self::$table_exists_cache[$model_table] = todo_db_table_exists($model_table);
                } else {
                    self::$table_exists_cache[$model_table] = (bool) $tododb->get_var("SHOW TABLES LIKE '{$model_table}'");
                }
            }

            if (self::$table_exists_cache[$model_table]) {
                $g_in = implode(',', array_fill(0, count($g_pids), '?'));
                try {
                    $m_rows = $tododb->get_results(
                        "SELECT * FROM `{$model_table}` WHERE post_id IN ({$g_in})",
                        $g_pids,
                        'ARRAY_A'
                    );
                    if (is_array($m_rows)) {
                        foreach ($m_rows as $mr) {
                            $mpid = intval($mr['post_id'] ?? 0);
                            if ($mpid > 0) {
                                $cache_id = $model_table . '_' . $mpid;
                                self::$model_row_cache[$cache_id] = $mr;
                                foreach ($mr as $mk => $mv) {
                                    if (!in_array($mk, ['field_id', 'post_id', 'created_at', 'updated_at'], true)) {
                                        if (!array_key_exists($mk, self::$post_custom_fields_cache[$mpid])) {
                                            self::$post_custom_fields_cache[$mpid][$mk] = $mv;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                }
            }
        }
    }

    /**
     * 加载 post_custom_fields 及模型特定扩展表中的自定义字段
     */
    private static function load_post_custom_fields($post_id, $post_type = '')
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return [];
        }

        if (array_key_exists($post_id, self::$post_custom_fields_cache)) {
            return self::$post_custom_fields_cache[$post_id];
        }

        self::prime_post_custom_fields_batch([$post_id]);
        return self::$post_custom_fields_cache[$post_id] ?? [];
    }

    public static function is_truthy($value)
    {
        if (is_array($value) || is_object($value)) return !empty($value);
        if ($value === true || $value === 1 || $value === '1') return true;
        if ($value === false || $value === 0 || $value === '0' || $value === '' || $value === null) return false;
        if (is_string($value)) {
            $n = strtolower(trim($value));
            if (in_array($n, ['false', 'null', 'no', 'off'], true)) return false;
            if (in_array($n, ['true', 'yes', 'on'], true)) return true;
        }
        return !empty($value);
    }

    public static function get_nested_var($data, $path)
    {
        if (empty($path)) return $data;
        $path  = str_replace(['[', ']'], ['.', ''], $path);
        $parts = explode('.', $path);
        $val   = $data;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (is_array($val) && array_key_exists($part, $val)) {
                $val = $val[$part];
            } elseif (is_object($val) && isset($val->$part)) {
                $val = $val->$part;
            } else {
                return null;
            }
        }
        return $val;
    }

    public static function merge_loop_context($parent_data, $item, $index, $index_1)
    {
        $merged = is_array($parent_data) ? $parent_data : [];
        // 移除父级上下文泄露的键，避免循环内取到父文章数据而非循环项
        // 循环体内 $GLOBALS['todo_post'] 已经正确指向循环项
        unset($merged['post'], $merged['item'], $merged['post_id'], $merged['title']);
        if (is_array($item)) {
            $merged = array_merge($merged, $item);
            if (!isset($merged['image_url']) && isset($item['url'])) {
                $merged['image_url'] = $item['url'];
            }
            if (!isset($merged['image_url']) && isset($item['src'])) {
                $merged['image_url'] = $item['src'];
            }
        } elseif (is_object($item)) {
            $obj_vars = get_object_vars($item);
            $merged = array_merge($merged, $obj_vars);
            if (!isset($merged['image_url']) && isset($obj_vars['url'])) {
                $merged['image_url'] = $obj_vars['url'];
            }
            if (!isset($merged['image_url']) && isset($obj_vars['src'])) {
                $merged['image_url'] = $obj_vars['src'];
            }
        } else {
            $str_item = (string) $item;
            $merged = array_merge($merged, [
                'item'      => $item,
                'value'     => $str_item,
                'image_url' => $str_item,
                'url'       => $str_item,
                'src'       => $str_item,
            ]);
        }

        $loop_post_id = 0;
        if (isset($merged['ID'])) {
            $loop_post_id = (int) $merged['ID'];
        } elseif (isset($merged['post_id'])) {
            $loop_post_id = (int) $merged['post_id'];
        }

        if ($loop_post_id > 0) {
            if (!isset($merged['category_name']) || $merged['category_name'] === '') {
                $categories = function_exists('todo_get_the_category') ? todo_get_the_category($loop_post_id) : (function_exists('get_the_category') ? get_the_category($loop_post_id) : []);
                if (!empty($categories)) {
                    $first = $categories[0];
                    $merged['category_name'] = is_object($first) ? (string) ($first->name ?? '') : (string) ($first['name'] ?? '');
                } else {
                    $merged['category_name'] = '';
                }
            }
            if (isset($merged['category_name']) && is_string($merged['category_name']) && $merged['category_name'] !== '') {
                if (function_exists('todo_parse_inline_translations')) {
                    $merged['category_name'] = todo_parse_inline_translations($merged['category_name']);
                }
                if (function_exists('todo_translate')) {
                    $merged['category_name'] = todo_translate($merged['category_name']);
                }
            }
            if (!isset($merged['category']) || $merged['category'] === '') {
                $merged['category'] = $merged['category_name'];
            }

            $real_views = 0;
            if (isset($merged['view_count'])) {
                $real_views = (int) $merged['view_count'];
            } elseif (isset($merged['views'])) {
                $real_views = (int) $merged['views'];
            } elseif (function_exists('get_post_views')) {
                $real_views = (int) get_post_views($loop_post_id);
            }

            $views_boost = 0;
            if (function_exists('get_post_meta')) {
                $views_boost = (int) get_post_meta($loop_post_id, '_views_boost', true);
            }

            $merged['real_views'] = $real_views;
            $merged['views_boost'] = $views_boost;
            $merged['content_views'] = max(0, $real_views + $views_boost);
            $merged['views'] = $merged['content_views'];
            $merged['post_views'] = $merged['content_views'];
            if (!isset($merged['view_count'])) {
                $merged['view_count'] = $real_views;
            }
        }

        // 为 post 对象添加短名别名 (post_title → title, post_content → content 等)
        if (isset($merged['post_title']) && !isset($merged['title'])) $merged['title'] = $merged['post_title'];
        if (isset($merged['post_content']) && !isset($merged['content'])) $merged['content'] = $merged['post_content'];
        if (isset($merged['post_excerpt']) && !isset($merged['excerpt'])) $merged['excerpt'] = $merged['post_excerpt'];
        if (isset($merged['post_excerpt']) && !isset($merged['summary'])) $merged['summary'] = $merged['post_excerpt'];
        if (isset($merged['post_name']) && !isset($merged['slug'])) $merged['slug'] = $merged['post_name'];
        if (isset($merged['post_date']) && !isset($merged['publish_date'])) $merged['publish_date'] = $merged['post_date'];
        // content_* 别名 (与搜索模板兼容)
        if (isset($merged['post_type']) && !isset($merged['content_type'])) $merged['content_type'] = $merged['post_type'];
        if (isset($merged['post_date']) && !isset($merged['content_date'])) $merged['content_date'] = $merged['post_date'];
        
        if ($loop_post_id > 0) {
            $loop_permalink = function_exists('todo_get_permalink')
                ? todo_get_permalink($loop_post_id)
                : (function_exists('get_permalink') ? get_permalink($loop_post_id) : '');

            if (empty($merged['url'])) {
                $merged['url'] = $loop_permalink;
            }
            if (empty($merged['permalink'])) {
                $merged['permalink'] = $merged['url'];
            }
            if (empty($merged['content_url'])) {
                $merged['content_url'] = $merged['url'];
            }
            if (empty($merged['special_url'])) {
                $merged['special_url'] = $merged['url'];
            }
            if (empty($merged['special_id'])) {
                $merged['special_id'] = $loop_post_id;
            }
        }
        if (isset($merged['post_title']) && !isset($merged['content_title'])) $merged['content_title'] = $merged['post_title'];
        if (isset($merged['featured_image']) && !isset($merged['content_thumbnail'])) {
            $fi = $merged['featured_image'];
            if (!empty($fi)) {
                $extracted_url = self::resolve_featured_image_to_url($fi);
                if ($extracted_url !== '') {
                    $merged['content_thumbnail'] = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($extracted_url) : $extracted_url;
                }
            }
            if (!isset($merged['content_thumbnail'])) $merged['content_thumbnail'] = '';
        }
        if (isset($merged['content_thumbnail']) && !isset($merged['post_featured_image'])) $merged['post_featured_image'] = $merged['content_thumbnail'];
        if (isset($merged['post_excerpt']) && !isset($merged['content_summary'])) $merged['content_summary'] = $merged['post_excerpt'];

        // 招聘模型的 company_logo 来自 multi_file 字段，模板里需要的是首张图片 URL。
        if (isset($merged['company_logo']) && is_string($merged['company_logo']) && $merged['company_logo'] !== '' && $merged['company_logo'] !== '[]') {
            $logo_url = '';
            $logo_raw = $merged['company_logo'];
            if (strpos($logo_raw, 'http') === 0 || strpos($logo_raw, '/') === 0) {
                $logo_url = $logo_raw;
            } elseif ($logo_raw[0] === '[' || $logo_raw[0] === '{') {
                $decoded_logo = json_decode($logo_raw, true);
                if (is_array($decoded_logo) && !empty($decoded_logo)) {
                    $first_logo = reset($decoded_logo);
                    if (is_string($first_logo) && $first_logo !== '') {
                        $logo_url = $first_logo;
                    } elseif (is_array($first_logo) && !empty($first_logo['url'])) {
                        $logo_url = (string) $first_logo['url'];
                    }
                }
            } elseif (strpos($logo_raw, 'a:') === 0) {
                $unserialized_logo = @unserialize($logo_raw);
                if (is_array($unserialized_logo) && !empty($unserialized_logo)) {
                    $first_logo = reset($unserialized_logo);
                    if (is_string($first_logo) && $first_logo !== '') {
                        $logo_url = $first_logo;
                    }
                }
            }

            if ($logo_url !== '') {
                $merged['company_logo_raw'] = $logo_raw;
                $merged['company_logo'] = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($logo_url) : $logo_url;
            }
        }

        // 自动规范化循环上下文中的所有媒体图片 URL 属性 (image, product_image, thumbnail, avatar, cover 等)
        if (function_exists('todo_media_url_to_runtime_absolute')) {
            foreach ($merged as $mk => $mv) {
                if (is_string($mv) && $mv !== '' && (strpos($mv, '/content/') !== false || strpos($mv, 'content/') !== false)) {
                    if (preg_match('/(image|thumb|thumbnail|logo|avatar|cover|pic|photo|url)$/i', $mk) || strpos($mk, 'image') !== false) {
                        $merged[$mk] = todo_media_url_to_runtime_absolute($mv);
                    }
                }
            }
        }

        // 搜索高亮别名
        $search_kw = function_exists('get_search_query') ? trim((string) get_search_query()) : (function_exists('todo_get_search_context') ? trim((string) todo_get_search_context()) : '');
        if (isset($merged['post_title']) && !isset($merged['highlighted_title'])) {
            $merged['highlighted_title'] = ($search_kw !== '') ? self::highlight_keywords($merged['post_title'], $search_kw) : $merged['post_title'];
        }
        if (isset($merged['post_excerpt']) && !isset($merged['highlighted_summary'])) {
            $merged['highlighted_summary'] = ($search_kw !== '') ? self::highlight_keywords($merged['post_excerpt'], $search_kw) : $merged['post_excerpt'];
        }
        $merged['item']         = $item;
        $merged['index']        = $index;
        $merged['index_1']      = $index_1;
        $merged['loop_index']   = $index;
        return $merged;
    }

    public static function call_taglib_tag($tag_name, $params, $data, $taglib_name = null, $content = null)
    {
        if (is_array($params)) {
            $attrs = $params;
        } else {
            $attrs = self::parseTagParams($params);
        }

        if (is_array($attrs) && is_array($data) && !empty($data) && !in_array($tag_name, ['class_if', 'value_if', 't'], true)) {
            foreach ($attrs as $k => $v) {
                if ($k === 'text' && $tag_name === 't') continue;
                if (is_string($v) && $v !== '' && strpos($v, 'http://') !== 0 && strpos($v, 'https://') !== 0 && strpos($v, '/') !== 0) {
                    $nestedVal = self::get_nested_var($data, $v);
                    if ($nestedVal !== null && (is_string($nestedVal) || is_numeric($nestedVal))) {
                        $attrs[$k] = (string)$nestedVal;
                    }
                }
            }
        }

        if (empty(self::$taglibs)) {
            self::registerDefaultTaglib();
        }

        if ($content !== null) {
            $attrs['content'] = $content;
        }

        if ($taglib_name !== null && isset(self::$taglibs[$taglib_name])) {
            return self::callTagInLib($tag_name, $attrs, $data, self::$taglibs[$taglib_name], $content);
        }

        foreach (self::$taglibs as $lib_config) {
            $result = self::callTagInLib($tag_name, $attrs, $data, $lib_config, $content);
            if ($result !== null) return $result;
        }

        if (isset(self::$registered_tags[$tag_name])) {
            $cb = self::$registered_tags[$tag_name];
            try {
                if (is_array($cb) && count($cb) === 2) {
                    $ref = new \ReflectionMethod($cb[0], $cb[1]);
                } else {
                    $ref = new \ReflectionFunction($cb);
                }
                $argc = $ref->getNumberOfParameters();
            } catch (\Exception $e) {
                $argc = 3;
            }
            if ($argc >= 3) {
                return call_user_func($cb, $attrs, $content ?? '', $data);
            }
            if ($argc === 2) {
                return call_user_func($cb, $attrs, $data);
            }
            if ($argc === 1) {
                return call_user_func($cb, $attrs);
            }
            return call_user_func($cb);
        }

        // 块标签回退：当标签名对应数据中的数组时，循环渲染块内容
        // 用于 order.items / order.payment_records / order.logs 等数组块
        if ($content !== null && is_array($data)) {
            $blockVal = self::get_nested_var($data, $tag_name);
            if (is_array($blockVal)) {
                $out = '';
                $i = 0;
                foreach ($blockVal as $item) {
                    if (is_array($item)) {
                        $item_ctx = $item;
                        if (!isset($item_ctx['image_url']) && isset($item_ctx['url'])) {
                            $item_ctx['image_url'] = $item_ctx['url'];
                        }
                        if (!isset($item_ctx['image_url']) && isset($item_ctx['src'])) {
                            $item_ctx['image_url'] = $item_ctx['src'];
                        }
                        if (!isset($item_ctx['value']) && isset($item_ctx['url'])) {
                            $item_ctx['value'] = $item_ctx['url'];
                        }
                        if (!isset($item_ctx['url']) && isset($item_ctx['value'])) {
                            $item_ctx['url'] = $item_ctx['value'];
                        }
                        $ctx = array_merge($data, $item_ctx);
                    } else {
                        $str_item = (string) $item;
                        $ctx = array_merge($data, [
                            'item'      => $item,
                            'value'     => $str_item,
                            'image_url' => $str_item,
                            'url'       => $str_item,
                            'src'       => $str_item,
                        ]);
                    }
                    $ctx['index'] = $i;
                    $ctx['index_1'] = $i + 1;
                    $out .= self::render_string($content, $ctx);
                    $i++;
                }
                return $out;
            }
        }

        if (is_array($data) && !in_array($tag_name, ['post_thumbnail', 'featured_image', 'post_content'], true)) {
            $val = self::get_nested_var($data, $tag_name);
            if (($val === null || $val === '') && isset($attrs['default'])) {
                return (string) $attrs['default'];
            }
            if ($val !== null) {
                if (is_string($val) || is_numeric($val) || is_bool($val)) {
                    $result = (string) $val;
                    if (is_string($val) && function_exists('todo_media_url_to_runtime_absolute') && (strpos($val, '/content/') !== false || strpos($val, 'content/') !== false) && strpos($val, '<') === false && strpos($val, '[') !== 0 && strpos($val, '{') !== 0) {
                        $result = todo_media_url_to_runtime_absolute($result);
                    }
                    if (is_string($val) && function_exists('todo_normalize_content_root_relative_urls') && strpos($val, '<') !== false && (strpos($val, 'content/') !== false || strpos($val, '<img') !== false || strpos($val, '<a') !== false)) {
                        $result = todo_normalize_content_root_relative_urls($result);
                    }
                    if (is_string($val) && function_exists('todo_parse_inline_translations')) {
                        $result = todo_parse_inline_translations($result);
                    }
                    $is_non_trans_field = preg_match('/(?:avatar|email|url|logo|image|path|link|thumbnail|display_name|username|balance|balance_format|transaction_title|spec_name|specs_text|unit_text|product_type|category_slug|category_description|amount|price|money|cost|total|sum|discount|date|time|created_at|updated_at|registered|expire|expired_at|count|num|number|id|code|token|ip|phone|mobile|points)$/i', $tag_name);
                    if (!$is_non_trans_field && is_string($result) && function_exists('todo_translate')) {
                        $result = todo_translate($result);
                    }
                    // RSS 模式下将文章内容中的相对路径转为绝对 URL（处理数据库已编码的实体）
                    if ($tag_name === 'article_content' && !empty($GLOBALS['TODO_IS_RSS'])) {
                        $result = self::make_content_urls_absolute($result);
                    }
                    return $result;
                }
                if (is_object($val) && method_exists($val, '__toString')) {
                    $result = (string) $val;
                    if (function_exists('todo_parse_inline_translations')) {
                        $result = todo_parse_inline_translations($result);
                    }
                    $is_non_trans_field = preg_match('/(?:avatar|email|url|logo|image|path|link|thumbnail|display_name|username|balance|balance_format|transaction_title|spec_name|specs_text|unit_text|product_type|category_slug|category_description|amount|price|money|cost|total|sum|discount|date|time|created_at|updated_at|registered|expire|expired_at|count|num|number|id|code|token|ip|phone|mobile|points)$/i', $tag_name);
                    if (!$is_non_trans_field && function_exists('todo_translate')) {
                        $result = todo_translate($result);
                    }
                    return $result;
                }
            }

            if (isset($data['post'])) {
                $column_aliases = [
                    'likes'         => 'like_count',
                    'views'         => 'view_count',
                    'comment_count' => 'comment_count',
                ];
                $db_col = $column_aliases[$tag_name] ?? $tag_name;
                if (is_object($data['post']) && isset($data['post']->$db_col)) {
                    return (string) $data['post']->$db_col;
                }
                if (is_array($data['post']) && isset($data['post'][$db_col])) {
                    return (string) $data['post'][$db_col];
                }
            }
        }

        $global = self::get_global_variable($tag_name);
        if ($global !== null && $global !== '' && $global !== '0') return $global;

        return '';
    }

    public static function tag_exists($tag_name, $taglib_name = null)
    {
        if (isset(self::$registered_tags[$tag_name])) return true;

        $libs = ($taglib_name !== null && isset(self::$taglibs[$taglib_name]))
            ? [$taglib_name => self::$taglibs[$taglib_name]]
            : self::$taglibs;

        foreach ($libs as $lib_config) {
            $handler = is_array($lib_config) ? $lib_config['handler'] : $lib_config;
            if (!$handler) continue;

            $type = is_array($lib_config) && isset($lib_config['type']) ? $lib_config['type'] : 'legacy';
            if ($type === 'taglib_base' && $handler instanceof Todo_Template_Taglib_Base) {
                if ($handler->get_tag_handler($tag_name) !== null) return true;
            } elseif (method_exists($handler, 'parse_' . $tag_name)) {
                return true;
            }
        }

        return false;
    }

    private static function callTagInLib($tag_name, $attrs, $data, $lib_config, $content = null)
    {
        $handler = is_array($lib_config) ? $lib_config['handler'] : $lib_config;
        if (!$handler) return null;

        $type = is_array($lib_config) && isset($lib_config['type']) ? $lib_config['type'] : 'legacy';
        if ($type === 'taglib_base' && class_exists('Todo_Template_Taglib_Base') && $handler instanceof Todo_Template_Taglib_Base) {
            $callback = $handler->get_tag_handler($tag_name);
            if ($callback !== null && is_callable($callback)) {
                return call_user_func($callback, $attrs, $content ?? '', $data);
            }
        } else {
            $methodName = 'parse_' . $tag_name;
            if (method_exists($handler, $methodName)) {
                return call_user_func([$handler, $methodName], $attrs, $content ?? '', $data);
            }
            // 兼容 {{todo:get_header}} → parse_header 等 get_ 前缀标签
            if (strpos($tag_name, 'get_') === 0) {
                $altName = 'parse_' . substr($tag_name, 4);
                if (method_exists($handler, $altName)) {
                    return call_user_func([$handler, $altName], $attrs, $content ?? '', $data);
                }
            }
            // 通用短名回退：{{todo:title}} → parse_post_title, {{todo:permalink}} → parse_post_url 等
            // 先查显式别名
            if (isset(self::$tagAliases[$tag_name])) {
                $aliasedMethod = 'parse_' . self::$tagAliases[$tag_name];
                if (method_exists($handler, $aliasedMethod)) {
                    return call_user_func([$handler, $aliasedMethod], $attrs, $content ?? '', $data);
                }
            }
            // 再尝试 parse_post_ 前缀回退
            $postMethod = 'parse_post_' . $tag_name;
            if (method_exists($handler, $postMethod)) {
                return call_user_func([$handler, $postMethod], $attrs, $content ?? '', $data);
            }
        }

        return null;
    }

    private static function parseTagParams($params_str)
    {
        if (is_array($params_str)) return $params_str;
        $attrs = [];
        if (empty($params_str)) return $attrs;

        if (preg_match_all('/([a-zA-Z0-9_]+)(?:=(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|([^\s,|]+)))?/', $params_str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name  = $match[1];
                $value = '';
                if (isset($match[2]) && $match[2] !== '') {
                    $value = stripslashes($match[2]);
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $value = stripslashes($match[3]);
                } elseif (isset($match[4]) && $match[4] !== '') {
                    $value = $match[4];
                }
                $attrs[$name] = $value;
            }
        }

        return $attrs;
    }

    // ======================================================================
    // 强制观看者角色（用于缓存预生成）
    // ======================================================================

    private static $forcedViewerRole = null;

    public static function set_forced_viewer_role($role)
    {
        self::$forcedViewerRole = $role;
    }

    // ======================================================================
    // Cache Management
    // ======================================================================

    public static function clear_cache($templatePath = null)
    {
        if (self::$compileDir === null && !self::init()) return;

        if ($templatePath && file_exists($templatePath)) {
            $templateMtime = filemtime($templatePath);
            // 必须与 compile() 中的 hash 公式一致：path + mtime + serialize(options) + VERSION
            $compileKey    = md5($templatePath . $templateMtime . 'a:0:{}' . self::VERSION);
            $compileFile   = self::$compileDir . $compileKey . '.php';
            if (file_exists($compileFile)) @unlink($compileFile);
        } else {
            $files = glob(self::$compileDir . '*.php');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f) && basename($f) !== 'index.php') @unlink($f);
                }
            }
        }
    }

    public static function clear_template_html_cache($locale = null)
    {
        $base = '';
        if (function_exists('get_cache_dir')) {
            $base = (string) get_cache_dir('templates');
        }
        if ($base === '') {
            $base = ABSPATH . 'content/cache/templates/';
        }
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

        $target = $base;
        if ($locale !== null && $locale !== '') {
            $loc = (string) $locale;
            if (function_exists('canonical_locale')) {
                $loc = canonical_locale($loc);
            } else {
                $loc = str_replace('_', '-', $loc);
            }
            $loc = preg_replace('/[^a-zA-Z0-9\\-]/', '_', $loc);
            if ($loc !== '') {
                $target = $base . $loc . '/';
            }
        }

        if (!is_dir($target)) {
            return true;
        }
        return self::purge_template_html_cache_dir($target);
    }

    private static function purge_template_html_cache_dir($dir)
    {
        $dir = rtrim((string) $dir, '/') . '/';
        $items = glob($dir . '*');
        if (!$items) return true;

        $ok = true;
        foreach ($items as $p) {
            $name = basename($p);
            if (is_dir($p)) {
                $ok = self::purge_template_html_cache_dir($p) && $ok;
                continue;
            }
            if (in_array($name, ['.htaccess', 'index.php', '.gitkeep'], true)) {
                continue;
            }
            if (!@unlink($p)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function clear_cache_by_file($compileFile)
    {
        if (file_exists($compileFile)) @unlink($compileFile);
    }

    public static function clear_all_cache()
    {
        if (self::$compileDir === null && !self::init()) return true;
        if (!is_dir(self::$compileDir)) return true;

        $files   = glob(self::$compileDir . '*.php');
        $cleared = 0;
        if ($files) {
            foreach ($files as $f) {
                if (is_file($f) && basename($f) !== 'index.php' && @unlink($f)) {
                    $cleared++;
                }
            }
        }
        // 也清除字符串缓存
        $stringFiles = glob(self::$compileDir . 'string_*.php');
        if ($stringFiles) {
            foreach ($stringFiles as $f) @unlink($f);
        }

        self::$stats = ['compiled' => 0, 'cache_hits' => 0, 'cache_misses' => 0, 'errors' => 0];
        return true;
    }

    public static function is_cache_valid($templatePath, $options = [])
    {
        if (!self::$enabled || !file_exists($templatePath)) return false;
        $templateMtime = filemtime($templatePath);
        $compileKey    = md5($templatePath . $templateMtime . serialize($options));
        $compileFile   = self::$compileDir . $compileKey . '.php';
        return self::checkCache($compileFile, $templatePath);
    }

    public static function get_stats()
    {
        return self::$stats;
    }

    public static function get_engine_stats()
    {
        return [
            'enabled'      => self::$enabled,
            'compile_dir'  => self::$compileDir,
            'taglibs_count' => count(self::$taglibs),
            'tags_count'   => count(self::$registered_tags),
            'stats'        => self::$stats,
        ];
    }

    // ======================================================================
    // 8. 条件求值引擎（从 CMS_Template_Parser 迁移）
    // ======================================================================

    /**
     * 评估条件表达式
     */
    public static function evaluate_todo_condition($condition, $data)
    {
        if (!is_string($condition)) {
            return false;
        }
        $condition = htmlspecialchars_decode(trim($condition));

        // 剥离最外层多余的匹配括号，例如: "(A || B || C) && (D || E)" -> 如果最外圈整体被 () 包裹则剥离
        while (strlen($condition) > 2 && $condition[0] === '(' && substr($condition, -1) === ')') {
            $inner = substr($condition, 1, -1);
            $depth = 0;
            $balanced = true;
            for ($i = 0; $i < strlen($inner); $i++) {
                if ($inner[$i] === '(') $depth++;
                elseif ($inner[$i] === ')') $depth--;
                if ($depth < 0) {
                    $balanced = false;
                    break;
                }
            }
            if ($balanced && $depth === 0) {
                $condition = trim($inner);
            } else {
                break;
            }
        }

        if (preg_match('/^option(?:\\s+|\\|)(.+)$/i', $condition, $m)) {
            $paramsRaw = trim((string) $m[1]);
            if ($paramsRaw === '') {
                return false;
            }
            $val = self::call_taglib_tag('option', $paramsRaw, $data);
            if (is_string($val)) {
                return trim($val) !== '';
            }
            if (is_scalar($val) || $val === null) {
                return trim((string) $val) !== '';
            }
            return !empty($val);
        }

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*)$/', $condition, $m)) {
            $var_name = $m[1];
            $base = explode('.', $var_name)[0];
            $status_checks = [
                'user_logged_in'        => 'is_user_logged_in',
                'user_not_logged_in'    => function() { return !is_user_logged_in(); },
                'lang'                  => function() { return function_exists('current_locale') ? strtolower(explode('-', current_locale())[0]) : (function_exists('todo_get_current_language') ? strtolower(explode('-', todo_get_current_language())[0]) : 'zh'); },
                'current_locale'        => function() { return function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN'); },
                'locale'                => function() { return function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN'); },
                'is_home'               => 'todo_is_home',
                'is_single'             => 'todo_is_single',
                'is_page'               => 'todo_is_page',
                'is_category'           => 'todo_is_category',
                'is_tag'                => 'todo_is_tag',
                'is_search'             => 'todo_is_search',
                'is_paged'              => 'todo_is_paged',
                'is_author'             => 'todo_is_author',
                'is_archive'            => 'todo_is_archive',
                'is_404'                => 'todo_is_404',
                'is_debug'              => function() { return defined('TODO_DEBUG') && TODO_DEBUG; },
                'has_post_thumbnail'    => function() { global $post; return $post && function_exists('todo_has_post_thumbnail') && todo_has_post_thumbnail($post->ID); },
                'has_post_tags'         => function() { global $post; return $post && function_exists('has_tag') && has_tag('', $post->ID); },
                'site_logo'             => function() { return function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : todo_get_option('site_logo'); },
                'search_locked'         => function() { return defined('SEARCH_LOCKED') && SEARCH_LOCKED; },
                'search_sensitive_blocked' => function() { return defined('SEARCH_SENSITIVE_BLOCKED') && SEARCH_SENSITIVE_BLOCKED; },
                'cart_count'            => function() {
                    if (class_exists('Todo_Cart_Manager') && is_callable(['Todo_Cart_Manager', 'get_cart'])) {
                        $cart = Todo_Cart_Manager::get_cart();
                        return is_array($cart) && isset($cart['count']) ? (int) $cart['count'] : 0;
                    }
                    return 0;
                },
                'favorites_count'       => function() {
                    return function_exists('todo_get_favorites_count') ? todo_get_favorites_count() : 0;
                },
                'user_favorites_count'  => function() {
                    return function_exists('todo_get_favorites_count') ? todo_get_favorites_count() : 0;
                },
                'favorite_products_count' => function() {
                    return function_exists('todo_get_favorites_count') ? todo_get_favorites_count(null, 'product') : 0;
                },
                'favorite_articles_count' => function() {
                    return function_exists('todo_get_favorites_count') ? todo_get_favorites_count(null, 'post') : 0;
                }
            ];
            if (isset($status_checks[$var_name])) {
                $check = $status_checks[$var_name];
                return is_callable($check) ? (bool)$check() : (function_exists($check) ? (bool)$check() : false);
            }
            if (in_array($base, ['posts', 'tags', 'post_tags', 'search_results', 'related_posts_by_tags', 'featured_images', 'post_images', 'products_images', 'gallery_images', 'show_ads'], true)) {
                try {
                    self::$in_condition_eval = true;
                    $items = self::get_loop_data($base, $data);
                    self::$in_condition_eval = false;
                    return is_array($items) && count($items) > 0;
                } catch (Exception $e) { 
                    self::$in_condition_eval = false;
                    return false; 
                }
            }
            return !empty(self::get_todo_variable($condition, $data));
        }

        // 逻辑运算符（||, &&）必须在比较运算符之前处理，确保运算符优先级正确
        // 例如 "post_stock || post_spec_type == 'multi'" 应先按 || 拆分，再各自递归解析
        if (strpos($condition, '&&') !== false) {
            foreach (explode('&&', $condition) as $part) {
                if (!self::evaluate_todo_condition(trim($part), $data)) return false;
            }
            return true;
        }
        if (strpos($condition, '||') !== false) {
            foreach (explode('||', $condition) as $part) {
                if (self::evaluate_todo_condition(trim($part), $data)) return true;
            }
            return false;
        }

        if (preg_match('/^\s*(.*?)\s*(===|==|!==|!=|>=|<=|>|<)\s*(.*?)\s*$/', $condition, $cm)) {
            $leftRaw = $cm[1]; $op = $cm[2]; $rightRaw = $cm[3];
            $resolve = function ($expr) use ($data) {
                $expr = trim($expr); $len = strlen($expr);
                if (strpos($expr, 'todo:') === 0) {
                    $expr = substr($expr, 5);
                    $len = strlen($expr);
                }
                if ($len >= 2 && (($expr[0] === '"' && $expr[$len - 1] === '"') || ($expr[0] === "'" && $expr[$len - 1] === "'"))) {
                    return substr($expr, 1, -1);
                }
                if (is_numeric($expr)) return $expr + 0;
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_.\-\|\s=]*)$/', $expr)) {
                    return self::get_todo_variable($expr, $data);
                }
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([^)]*)\)$/', $expr, $m)) {
                    $fname = $m[1];
                    if (function_exists($fname)) {
                        try { $args = self::parse_function_params('(' . $m[2] . ')'); return call_user_func_array($fname, $args); }
                        catch (Exception $e) { return ''; }
                    }
                }
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+|\|)(.+)$/', $expr)) {
                    return trim(self::call_taglib_tag(explode('|', str_replace(' ', '|', $expr))[0], substr($expr, strlen(explode('|', str_replace(' ', '|', $expr))[0])+1), $data));
                }
                return $expr;
            };
            $left = $resolve($leftRaw); $right = $resolve($rightRaw);
            $clean_num = function($v) {
                if (is_numeric($v)) return (float)$v;
                if (is_string($v)) {
                    // 去除货币符号等非数字字符（保留数字和小数点）
                    $cleaned = preg_replace('/[^\d.]/', '', $v);
                    if ($cleaned !== '' && is_numeric($cleaned)) {
                        return (float)$cleaned;
                    }
                }
                return null;
            };
            $leftNum = $clean_num($left);
            $rightNum = $clean_num($right);
            $isNumComp = ($leftNum !== null && $rightNum !== null);

            switch ($op) {
                case '===':
                case '==': return $isNumComp ? ($leftNum == $rightNum) : ((string) $left === (string) $right);
                case '!==':
                case '!=': return $isNumComp ? ($leftNum != $rightNum) : ((string) $left !== (string) $right);
                case '>': return $isNumComp ? ($leftNum > $rightNum) : (strcmp((string) $left, (string) $right) > 0);
                case '<': return $isNumComp ? ($leftNum < $rightNum) : (strcmp((string) $left, (string) $right) < 0);
                case '>=': return $isNumComp ? ($leftNum >= $rightNum) : (strcmp((string) $left, (string) $right) >= 0);
                case '<=': return $isNumComp ? ($leftNum <= $rightNum) : (strcmp((string) $left, (string) $right) <= 0);
            }
        }
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\(([^)]*)\)$/', $condition, $matches)) {
            $function_name = $matches[1]; $params = $matches[2];
            if (function_exists($function_name)) {
                try { $args = self::parse_function_params('(' . $params . ')'); return (bool) call_user_func_array($function_name, $args); }
                catch (Exception $e) { return false; }
            }
        }
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+|\|)(.+)$/', $condition)) {
            $parsed = self::call_taglib_tag(explode('|', str_replace(' ', '|', $condition))[0], substr($condition, strlen(explode('|', str_replace(' ', '|', $condition))[0])+1), $data);
            return is_string($parsed) ? !empty(trim($parsed)) : !empty($parsed);
        }
        return false;
    }

    /**
     * 获取循环数据（从 CMS_Template_Parser 完整迁移）
     */
    public static function get_loop_data($items_name, $data, $params = [])
    {
        $base_name = $items_name;
        $parsed_args = $params;

        if (empty($parsed_args) && strpos($items_name, '|') !== false) {
            list($base_name, $pipe_params) = explode('|', $items_name, 2);
            $base_name = trim($base_name);
            try { $parsed_args = self::parse_function_params('(' . trim($pipe_params) . ')'); }
            catch (Exception $e) { $parsed_args = []; }
        }

        if ($base_name === 'categories' && is_array($parsed_args) && !array_key_exists('parent', $parsed_args)) {
            foreach (['parent_id', 'id'] as $k) {
                if (array_key_exists($k, $parsed_args)) { $parsed_args['parent'] = $parsed_args[$k]; break; }
            }
        }

        // 当提供了任意过滤/限制/属性/分类参数时，必须跳过短路，走 loop_posts 执行带过滤与分页的查询
        $has_pagination_params = !empty($parsed_args);

        if (!$has_pagination_params && isset($data[$items_name]) && is_array($data[$items_name])) {
            $debug_items = $data[$items_name];
            $debug_count = count($debug_items);
            $debug_first_keys = ($debug_count > 0 && is_array($debug_items[0])) ? array_keys($debug_items[0]) : [];
            return $data[$items_name];
        }
        if (!$has_pagination_params && $base_name !== $items_name && isset($data[$base_name]) && is_array($data[$base_name])) return $data[$base_name];

        global $post, $todo_query;

        switch ($base_name) {
            case 'products':
            case 'jobs':
            case 'specials':
            case 'special_list':
            case 'guestbooks':
            case 'single_pages':
            case 'images':
            case 'downloads':
            case 'media':
            case 'archives':
            case 'search_results':
            case 'posts':
                return self::loop_posts($data, $parsed_args, $base_name);
            case 'post_images':
            case 'featured_images':
            case 'anywhere_post_images':
            case 'anywhere_post':
                return self::resolve_featured_images_loop($data, $parsed_args);
            case 'gallery_images':
                return self::loop_model_field_list('gallery_images', $data, $parsed_args, ['url', 'thumb', 'title']);
            case 'media_sources':
                return self::loop_model_field_list('media_sources', $data, $parsed_args, ['source_url', 'source_type', 'quality']);
            case 'subtitle_tracks':
                return self::loop_model_field_list('subtitle_tracks', $data, $parsed_args, ['track_url', 'track_lang', 'track_label']);
            case 'playlist':
                return self::loop_model_field_list('playlist', $data, $parsed_args, ['media_url', 'title', 'thumbnail', 'duration']);
            case 'timeline_events':
                return self::loop_timeline_events($data, $parsed_args);
            case 'requirements':
                return self::loop_simple_text_list('requirements', $data, 'requirement');
            case 'benefits':
                return self::loop_benefits($data);
            case 'related_content':
                return self::loop_related_content($data, $parsed_args);
            case 'related_jobs':
                return self::loop_related_jobs($data, $parsed_args);
            case 'related_posts_by_tags':
                return self::loop_related_posts_by_tags($data, $parsed_args);
            case 'special_featured_posts':
                return self::loop_special_featured_posts($data, $parsed_args);
            case 'special_categories':
                return self::loop_special_categories($data, $parsed_args);
            case 'special_tags':
                return self::loop_special_tags($data, $parsed_args);
            case 'tags':
            case 'post_tags':
                return self::loop_tags($data, $parsed_args);
            default:
                $target_var_name = '';
                if ($base_name === 'global_var' || $base_name === 'global_vars') {
                    $target_var_name = $parsed_args['name'] ?? $parsed_args['key'] ?? '';
                } else {
                    $target_var_name = $base_name;
                }

                if ($target_var_name !== '') {
                    $global_vars = get_option('global_vars', []);
                    if (is_array($global_vars) && isset($global_vars[$target_var_name])) {
                        $raw_val = $global_vars[$target_var_name];
                        if (class_exists('CMS_Html_Template_Tags')) {
                            $resolved = CMS_Html_Template_Tags::resolve_category_images_array($raw_val);
                            if (!empty($resolved)) {
                                return $resolved;
                            }
                        }
                        if (is_string($raw_val) && (strpos($raw_val, '[') === 0 || strpos($raw_val, '{') === 0)) {
                            $raw_val = json_decode($raw_val, true);
                        }
                        if (is_array($raw_val)) {
                            $formatted_items = [];
                            foreach ($raw_val as $k => $item) {
                                $row = is_array($item) ? $item : ['value' => $item];
                                if (isset($row['url']) && function_exists('todo_media_url_to_runtime_absolute')) {
                                    $row['url'] = todo_media_url_to_runtime_absolute($row['url']);
                                }
                                if (isset($row['src']) && function_exists('todo_media_url_to_runtime_absolute')) {
                                    $row['src'] = todo_media_url_to_runtime_absolute($row['src']);
                                }
                                $formatted_items[] = $row;
                            }
                            return $formatted_items;
                        }
                    }
                }

                // 优先从模板上下文 $data 中读取已知数组（如 se_zi_duan_images / zhui_tu_images）
                if (isset($data[$base_name]) && is_array($data[$base_name])) {
                    return $data[$base_name];
                }
                if (isset($data['post_' . $base_name]) && is_array($data['post_' . $base_name])) {
                    return $data['post_' . $base_name];
                }

                // 实时基于当前文章/商品的自定义字段自动解包（如 se_zi_duan_images 自动调取当前文章的 se_zi_duan 字段）
                if (substr($base_name, -7) === '_images') {
                    $raw_field_key = substr($base_name, 0, -7);
                    global $post, $current_post;
                    $cur_p = $current_post ?: ($post ?: (function_exists('get_current_post') ? get_current_post() : null));
                    if ($cur_p && isset($cur_p->ID)) {
                        $pid = intval($cur_p->ID);
                        global $tododb;
                        $raw_val = null;
                        if (isset($tododb)) {
                            $raw_val = $tododb->get_var($tododb->prepare("SELECT field_value FROM " . TABLE_PREFIX . "post_custom_fields WHERE post_id = %d AND (field_key = %s OR field_key = %s) LIMIT 1", $pid, $raw_field_key, 'model_field_' . $raw_field_key));
                        }
                        if (empty($raw_val) && function_exists('get_post_meta')) {
                            $raw_val = get_post_meta($pid, $raw_field_key, true);
                        }
                        if (!empty($raw_val) && class_exists('CMS_Html_Template_Tags')) {
                            $resolved = CMS_Html_Template_Tags::resolve_category_images_array($raw_val);
                            if (!empty($resolved)) {
                                return $resolved;
                            }
                        }
                    }
                }

                try {
                    if (method_exists('CMS_HTML_Template_Tags', 'parse_' . $base_name) && class_exists('CMS_HTML_Template_Tags')) {
                        $handler = CMS_HTML_Template_Tags::get_instance();
                        if (method_exists($handler, 'parse_' . $base_name)) {
                            return call_user_func([$handler, 'parse_' . $base_name], $parsed_args, '', $data);
                        }
                    }
                } catch (Throwable $e) {}
                return [];
        }
    }

    private static $request_loop_cache = [];
    private static $in_condition_eval = false;

    private static function loop_posts($data, $parsed_args, $base_name) {
        global $post, $todo_query, $tododb;
        if (!is_array($parsed_args)) $parsed_args = [];

        $request_uri = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (function_exists('todo_server_string') ? todo_server_string('REQUEST_URI', '') : ($_SERVER['REQUEST_URI'] ?? ''));
        $req_cache_key = $base_name . '|' . md5(serialize($parsed_args) . '|' . $request_uri);
        if (isset(self::$request_loop_cache[$req_cache_key])) {
            $cached = self::$request_loop_cache[$req_cache_key];
            if (isset($cached['pagination'])) {
                self::$loop_pagination = $cached['pagination'];
            }
            if (isset($cached['todo_query']) && is_object($todo_query)) {
                $todo_query->max_num_pages = $cached['todo_query']->max_num_pages ?? 1;
                $todo_query->post_count = $cached['todo_query']->post_count ?? 0;
                $todo_query->found_posts = $cached['todo_query']->found_posts ?? 0;
            }
            return $cached['res'];
        }
        if (!isset($parsed_args['limit']) && isset($parsed_args['per_page'])) $parsed_args['limit'] = $parsed_args['per_page'];
        $cat_ids = [];
        $raw_cat = null;
        if (isset($parsed_args['category_id']) && $parsed_args['category_id'] !== '') {
            $raw_cat = $parsed_args['category_id'];
        } elseif (isset($parsed_args['categories']) && $parsed_args['categories'] !== '') {
            $raw_cat = $parsed_args['categories'];
        } elseif (isset($parsed_args['category']) && $parsed_args['category'] !== '') {
            $raw_cat = $parsed_args['category'];
        } elseif (isset($parsed_args['id']) && $parsed_args['id'] !== '') {
            $raw_cat = $parsed_args['id'];
        }
        if ($raw_cat !== null && $raw_cat !== '') {
            $cat_raw_arr = is_array($raw_cat) ? $raw_cat : explode(',', (string)$raw_cat);
            $parsed_cat_ids = array_values(array_filter(array_map('intval', $cat_raw_arr), function ($v) { return $v > 0; }));
            if (!empty($parsed_cat_ids) && count($parsed_cat_ids) === count($cat_raw_arr)) {
                $cat_ids = $parsed_cat_ids;
            } elseif (count($cat_raw_arr) === 1) {
                $slug = sanitize_title((string)$raw_cat);
                if ($slug !== '') {
                    $parsed_args['category_slug'] = $slug;
                }
            }
        }
        if (empty($cat_ids) && isset($parsed_args['category_slug']) && function_exists('get_category_by_slug')) {
            $cat = get_category_by_slug((string) $parsed_args['category_slug']);
            if (is_object($cat) && isset($cat->term_id)) $cat_ids = [intval($cat->term_id)];
        }
        if (empty($cat_ids) && is_array($data) && isset($data['category_id']) && intval($data['category_id']) > 0) {
            $cat_ids = [intval($data['category_id'])];
        }
        $default_posts_per_page = function_exists('get_option') ? (int) get_option('posts_per_page', 10) : 10;
        if ($default_posts_per_page <= 0) $default_posts_per_page = 10;
        $per_page = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : $default_posts_per_page;
        $mid_size = isset($parsed_args['mid_size']) ? max(0, intval($parsed_args['mid_size'])) : 2;
        $end_size = isset($parsed_args['end_size']) ? max(0, intval($parsed_args['end_size'])) : 1;

        $page_param = '';
        if (isset($parsed_args['pager']) && is_string($parsed_args['pager']) && $parsed_args['pager'] !== '') {
            $page_param = sanitize_key($parsed_args['pager']);
        } elseif (isset($parsed_args['page_param']) && is_string($parsed_args['page_param']) && $parsed_args['page_param'] !== '') {
            $page_param = sanitize_key($parsed_args['page_param']);
        }
        if ($page_param === '') {
            $has_custom_query_filters = !empty($cat_ids) || 
                                        !empty($parsed_args['category_slug']) || 
                                        !empty($parsed_args['category']) || 
                                        !empty($parsed_args['categories']) || 
                                        !empty($parsed_args['model']) || 
                                        !empty($parsed_args['tags']) || 
                                        !empty($parsed_args['tag']) || 
                                        !empty($parsed_args['properties']) || 
                                        !empty($parsed_args['doc_properties']) || 
                                        !empty($parsed_args['doc_properties_strict']);
            if ($has_custom_query_filters) {
                $page_param = 'none';
            } else {
                $page_param = 'paged';
            }
        }
        if ($page_param === 'main') $page_param = 'paged';

        $default_paged = 0;
        if ($page_param === 'paged') {
            $default_paged = function_exists('todo_get_paged_context') ? todo_get_paged_context('paged', 1) : 1;
        } else {
            $k1 = 'paged_' . $page_param;
            $default_paged = function_exists('todo_get_paged_context') ? todo_get_paged_context($page_param, 1) : 1;
        }
        if ($default_paged <= 0) $default_paged = 1;
        $page = isset($parsed_args['page']) ? max(1, intval($parsed_args['page'])) : $default_paged;
        $offset = ($page - 1) * $per_page;
        if (isset($parsed_args['offset'])) { $offset = max(0, intval($parsed_args['offset'])) + (($page - 1) * $per_page); }
        $model_id_filter = 0;
        $model_key = '';
        if (isset($parsed_args['model']) && $parsed_args['model'] !== '') {
            $raw_model = trim((string) $parsed_args['model']);
            if ($raw_model !== '') {
                if (ctype_digit($raw_model)) {
                    $model_id_filter = intval($raw_model);
                } else {
                    $model_key = sanitize_key($raw_model);
                }
            }
        }
        $cat_id = !empty($cat_ids) ? $cat_ids[0] : 0;
        if ($model_id_filter <= 0 && $model_key === '' && $cat_id > 0 && class_exists('Todo_Model_Manager')) {
            $model_key = Todo_Model_Manager::get_category_model($cat_id) ?: '';
        }
        $orderby = isset($parsed_args['orderby']) ? $parsed_args['orderby'] : 'post_date';
        $orderby_map = [
            'views' => 'view_count',
            'hits' => 'view_count',
            'view_count' => 'view_count',
            'date' => 'post_date',
            'id' => 'ID',
            'title' => 'post_title',
            'modified' => 'post_modified',
            'comment_count' => 'comment_count',
        ];
        if (isset($orderby_map[$orderby])) {
            $orderby = $orderby_map[$orderby];
        } else {
            $orderby = sanitize_key($orderby);
            if ($orderby === '') $orderby = 'post_date';
        }
        $order = isset($parsed_args['order']) ? strtoupper($parsed_args['order']) : 'DESC';
        if ($order !== 'ASC' && $order !== 'DESC') $order = 'DESC';

        $tag_slugs = []; $tag_id_filter = 0;
        $raw_tags_parts = [];
        if (isset($parsed_args['tag']) && $parsed_args['tag'] !== '') $raw_tags_parts[] = (string) $parsed_args['tag'];
        if (isset($parsed_args['tags']) && $parsed_args['tags'] !== '') $raw_tags_parts[] = (string) $parsed_args['tags'];
        if (empty($raw_tags_parts)) {
            $tag_context = function_exists('todo_get_query_context_value') ? todo_get_query_context_value('tag', todo_get_string('tag')) : todo_get_string('tag');
            $tag_id_context = function_exists('todo_get_query_context_value') ? todo_get_query_context_value('tag_id', todo_get_string('tag_id')) : todo_get_string('tag_id');
            if (!empty($tag_context)) $raw_tags_parts[] = (string) $tag_context;
            if (!empty($tag_id_context)) $raw_tags_parts[] = (string) $tag_id_context;
        }
        $raw_tags = trim(implode(',', $raw_tags_parts));
        if ($raw_tags !== '') {
            if (is_numeric(trim($raw_tags))) $tag_id_filter = intval(trim($raw_tags));
            else { foreach (strpos($raw_tags, ',') !== false ? explode(',', $raw_tags) : [$raw_tags] as $p) { $p = trim($p); if ($p !== '') $tag_slugs[] = sanitize_title($p); } }
        }
        if ($tag_id_filter <= 0 && empty($tag_slugs) && function_exists('is_tag') && is_tag() && function_exists('get_current_tag')) {
            $ctx_tag = get_current_tag();
            if ($ctx_tag) { if (isset($ctx_tag->tag_id) && $ctx_tag->tag_id > 0) $tag_id_filter = intval($ctx_tag->tag_id); elseif (isset($ctx_tag->id) && $ctx_tag->id > 0) $tag_id_filter = intval($ctx_tag->id); }
        }

        if (!$tododb) return [];
        $posts_table = $tododb->prefix . 'posts';
        $joins = ''; $wheres = []; $params = [];
        $wheres[] = "p.post_status = 'publish'";
        $vis_meta_sub = "(SELECT m_vis.meta_value FROM {$tododb->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' LIMIT 1)";
        if (isset($parsed_args['visibility']) && $parsed_args['visibility'] !== 'any') {
            $vis_val = sanitize_key($parsed_args['visibility']);
            $wheres[] = "(COALESCE({$vis_meta_sub}, p.visibility) = ?)";
            $params[] = $vis_val;
        } elseif (!isset($parsed_args['visibility']) && (!defined('IS_ADMIN') || !IS_ADMIN)) {
            $wheres[] = "(p.visibility = 'public' AND NOT EXISTS (SELECT 1 FROM {$tododb->prefix}postmeta m_vis WHERE m_vis.post_id = p.ID AND m_vis.meta_key = '_visibility' AND m_vis.meta_value <> 'public'))";
        }

        $prop_codes = [];
        $url_prop_codes = [];
        $get_prop_raw = '';
        if (function_exists('todo_get_query_context_value')) {
            $get_prop_raw = (string) todo_get_query_context_value('doc_properties', '');
            if ($get_prop_raw === '') {
                $get_prop_raw = (string) todo_get_query_context_value('property', '');
            }
        }
        if ($get_prop_raw === '' && function_exists('todo_get_string')) {
            $get_prop_raw = todo_get_string('doc_properties', todo_get_string('property'));
        }
        if (!empty($get_prop_raw)) {
            foreach (strpos((string)$get_prop_raw, ',') !== false ? explode(',', (string)$get_prop_raw) : [(string)$get_prop_raw] as $pv) { $pv = trim($pv); if ($pv !== '') $url_prop_codes[] = sanitize_key($pv); }
        }
        $tag_prop_codes = [];
        $tag_prop_raw = $parsed_args['doc_properties'] ?? '';
        if ($tag_prop_raw !== '') {
            foreach (strpos((string)$tag_prop_raw, ',') !== false ? explode(',', (string)$tag_prop_raw) : [(string)$tag_prop_raw] as $pv) { $pv = trim($pv); if ($pv !== '') $tag_prop_codes[] = sanitize_key($pv); }
        }
        $is_v2_strict = !empty($url_prop_codes) || !empty($parsed_args['doc_properties_strict']);
        $prop_codes = !empty($url_prop_codes) ? $url_prop_codes : $tag_prop_codes;
        $need_prop_sort = !empty($prop_codes);
        $prop_sort_sql = ''; // SQL 级置顶排序：匹配属性的文章排最前

        if ($need_prop_sort) {
            $prop_ids = [];
            $str_codes = [];
            foreach ($prop_codes as $pc) {
                if (ctype_digit((string) $pc)) {
                    $prop_ids[] = intval($pc);
                } else {
                    $str_codes[] = $pc;
                }
            }
            if (!empty($str_codes) && $tododb) {
                $in_pc = implode(',', array_fill(0, count($str_codes), '?'));
                try {
                    $p_rows = $tododb->get_results(
                        "SELECT id FROM {$tododb->prefix}doc_properties WHERE value IN ({$in_pc})",
                        $str_codes
                    );
                    if (is_array($p_rows)) {
                        foreach ($p_rows as $pr) {
                            $pid_v = is_object($pr) ? intval($pr->id ?? 0) : intval($pr['id'] ?? 0);
                            if ($pid_v > 0) $prop_ids[] = $pid_v;
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            if (!empty($prop_ids)) {
                if ($is_v2_strict) {
                    // 仅在 URL 筛选或显式 strict 时做 WHERE 硬性过滤（多属性满足任意一个即可）
                    $dp_sub_wheres = [];
                    foreach ($prop_ids as $pi) {
                        $dp_sub_wheres[] = "FIND_IN_SET('{$pi}', p.doc_properties) > 0";
                    }
                    if (!empty($dp_sub_wheres)) {
                        $wheres[] = "(" . implode(" OR ", $dp_sub_wheres) . ")";
                    }
                }

                // 注意：不在 SQL ORDER BY 中强制拼装 FIND_IN_SET CASE 表达式，
                // 否则会导致整表 10万+ 记录逐行计算并全表 filesort，耗时高达数百毫秒。
                // 真正的置顶排序已在下方 $need_prop_sort PHP 结果集处理中精排（行 3774+）。
                $prop_sort_sql = '';
            }
        }

        if (!defined('IS_ADMIN') || !IS_ADMIN) {
            $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
            $current_lang = str_replace('_', '-', $current_lang);
            $default_lang = function_exists('get_default_language') ? get_default_language() : 'zh-CN';
            $default_lang = str_replace('_', '-', $default_lang);
            if ($current_lang !== $default_lang) {
                $wheres[] = "EXISTS (SELECT 1 FROM {$tododb->prefix}postmeta WHERE post_id = p.ID AND meta_key = '_enabled_languages' AND JSON_CONTAINS(meta_value, ?))";
                $params[] = '"' . $current_lang . '"';
            }
        }

        // 业务需求：站点偏好过滤（系统级默认站点 + 偏好站点）
        $site_id_arg_provided = (array_key_exists('site_id', $parsed_args) && $parsed_args['site_id'] !== null && $parsed_args['site_id'] !== '');
        if ($site_id_arg_provided) {
            $current_site_id = (int) $parsed_args['site_id'];
        } elseif (defined('IS_ADMIN') && IS_ADMIN) {
            $current_site_id = function_exists('todo_get_site_context_id') ? (int) todo_get_site_context_id(0) : 0;
            if ($current_site_id <= 0 && !empty($GLOBALS['todo_doing_cache_warmup'])) {
                $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
            }
        } else {
            $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        }

        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
        if ($current_site_id > 0 && $has_sp) {
            $site_posts_table = $tododb->prefix . 'site_posts';
            $joins .= " LEFT JOIN {$site_posts_table} sp ON (p.ID = sp.post_id AND sp.site_id = {$current_site_id})";
            $wheres[] = "((sp.post_id IS NOT NULL AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_posts_table} spx WHERE spx.post_id = p.ID))";
        }
        if (!empty($cat_ids)) {
            $cat_in = implode(',', $cat_ids);
            // 标准关系表驱动 JOIN，走 object_id 与 term_taxonomy_id 联合索引，毫秒级定位
            $joins .= " INNER JOIN {$tododb->prefix}term_relationships tr_cat ON tr_cat.object_id = p.ID INNER JOIN {$tododb->prefix}term_taxonomy tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'category' AND tt_cat.term_id IN ({$cat_in})";
        }
        if ($tag_id_filter > 0) {
            $joins .= " INNER JOIN {$tododb->prefix}post_tags pt_filter ON pt_filter.post_id = p.ID";
            $wheres[] = "pt_filter.tag_id = ?"; $params[] = $tag_id_filter;
        } elseif (!empty($tag_slugs)) {
            $joins .= " INNER JOIN {$tododb->prefix}post_tags pt_filter ON pt_filter.post_id = p.ID INNER JOIN {$tododb->prefix}tags t_filter ON t_filter.tag_id = pt_filter.tag_id";
            $in = implode(',', array_fill(0, count($tag_slugs), '?'));
            $wheres[] = "t_filter.tag_slug IN ({$in})";
            foreach ($tag_slugs as $ts) $params[] = $ts;
        }
        if ($model_id_filter > 0) {
            $wheres[] = 'p.model_id = ?'; $params[] = $model_id_filter;
        } elseif ($model_key !== '' && class_exists('Todo_Model_Manager')) {
            $mid = Todo_Model_Manager::get_model_id_by_key($model_key) ?: 0;
            if ($mid > 0) { $wheres[] = 'p.model_id = ?'; $params[] = $mid; }
        }

        $date_year = 0;
        $date_month = 0;
        $date_day = 0;
        $explicit_date = false;
        if (isset($parsed_args['year']) && $parsed_args['year'] !== '') { $date_year = intval($parsed_args['year']); $explicit_date = true; }
        elseif (isset($parsed_args['archive_year']) && $parsed_args['archive_year'] !== '') { $date_year = intval($parsed_args['archive_year']); $explicit_date = true; }

        if (isset($parsed_args['month']) && $parsed_args['month'] !== '') { $date_month = intval($parsed_args['month']); $explicit_date = true; }
        elseif (isset($parsed_args['archive_month']) && $parsed_args['archive_month'] !== '') { $date_month = intval($parsed_args['archive_month']); $explicit_date = true; }

        if (isset($parsed_args['day']) && $parsed_args['day'] !== '') { $date_day = intval($parsed_args['day']); $explicit_date = true; }
        elseif (isset($parsed_args['archive_day']) && $parsed_args['archive_day'] !== '') { $date_day = intval($parsed_args['archive_day']); $explicit_date = true; }

        if (!$explicit_date && is_array($data)) {
            if ($date_year <= 0 && isset($data['archive_year'])) $date_year = intval($data['archive_year']);
            if ($date_month <= 0 && isset($data['archive_month'])) $date_month = intval($data['archive_month']);
            if ($date_day <= 0 && isset($data['archive_day'])) $date_day = intval($data['archive_day']);
        }

        if (!$explicit_date) {
            if ($date_year <= 0 && isset($GLOBALS['archive_year'])) $date_year = intval($GLOBALS['archive_year']);
            if ($date_month <= 0 && isset($GLOBALS['archive_month'])) $date_month = intval($GLOBALS['archive_month']);
            if ($date_day <= 0 && isset($GLOBALS['archive_day'])) $date_day = intval($GLOBALS['archive_day']);
        }

        $is_archive_ctx = false;
        if (function_exists('todo_get_current_page_context') && todo_get_current_page_context('') === 'archive') $is_archive_ctx = true;
        if (function_exists('todo_get_query_context_value') && (todo_get_query_context_value('archive_year', todo_get_string('archive_year')) !== '' || todo_get_query_context_value('archive_month', todo_get_string('archive_month')) !== '' || todo_get_query_context_value('archive_day', todo_get_string('archive_day')) !== '')) $is_archive_ctx = true;
        if (function_exists('todo_get_query_context_value') && (todo_get_query_context_value('year', todo_get_string('year')) !== '' || todo_get_query_context_value('month', todo_get_string('month')) !== '' || todo_get_query_context_value('monthnum', todo_get_string('monthnum')) !== '' || todo_get_query_context_value('day', todo_get_string('day')) !== '')) $is_archive_ctx = true;

        if ($explicit_date || $is_archive_ctx) {
            if ($date_year <= 0) $date_year = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('archive_year', todo_get_string('archive_year'))) : 0;
            if ($date_month <= 0) $date_month = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('archive_month', todo_get_string('archive_month'))) : 0;
            if ($date_day <= 0) $date_day = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('archive_day', todo_get_string('archive_day'))) : 0;
            if ($date_year <= 0) $date_year = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('year', todo_get_string('year'))) : 0;
            if ($date_month <= 0) $date_month = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('month', todo_get_string('month'))) : 0;
            if ($date_month <= 0) $date_month = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('monthnum', todo_get_string('monthnum'))) : 0;
            if ($date_day <= 0) $date_day = function_exists('todo_get_query_context_value') ? intval(todo_get_query_context_value('day', todo_get_string('day'))) : 0;

            if ($date_year > 0) {
                $wheres[] = "YEAR(p.post_date) = ?"; $params[] = $date_year;
                if ($date_month > 0) { $wheres[] = "MONTH(p.post_date) = ?"; $params[] = $date_month; }
                if ($date_day > 0) { $wheres[] = "DAY(p.post_date) = ?"; $params[] = $date_day; }
            }
        }
        $search_keyword = '';
        if (isset($parsed_args['keyword']) && $parsed_args['keyword'] !== '') {
            $search_keyword = trim($parsed_args['keyword']);
        } elseif ($base_name === 'search_results') {
            if (function_exists('get_search_query') && get_search_query() !== '') {
                $search_keyword = get_search_query();
            } elseif (function_exists('todo_get_search_context') && todo_get_search_context() !== '') {
                $search_keyword = todo_get_search_context();
            }
        }

        // 搜索被频率限制锁定或触发敏感词拦截时，不执行实际查询但保持 keyword 供模板展示
        if ($base_name === 'search_results' && ((defined('SEARCH_LOCKED') && SEARCH_LOCKED) || (defined('SEARCH_SENSITIVE_BLOCKED') && SEARCH_SENSITIVE_BLOCKED))) {
            if (!is_object($todo_query)) $todo_query = new stdClass();
            $todo_query->max_num_pages = 0;
            $todo_query->post_count = 0;
            $todo_query->found_posts = 0;
            self::$loop_pagination = [
                'per_page'                 => $per_page,
                'total_pages'              => 0,
                'pagination'               => 0,
                'pagination_total'         => 0,
                'pagination_prev_page'     => 0,
                'pagination_next_page'     => 0,
                'pagination_current_page'  => $page,
                'pagination_total_pages'   => 0,
                'pagination_page_param'    => $page_param,
                'pagination_mid_size'      => $mid_size,
                'pagination_end_size'      => $end_size,
            ];
            return [];
        }

        $max_results = 0;
        if ($search_keyword !== '') {
            // ====== 加载搜索配置 ======
            $sc_raw = function_exists('todo_get_option') ? todo_get_option('search_config', '') : '';
            if (empty($sc_raw) && function_exists('get_option')) {
                $sc_raw = get_option('search_config', '');
            }
            $sc     = !empty($sc_raw) ? json_decode($sc_raw, true) : [];
            if (!is_array($sc)) $sc = [];
            $search_mode   = $sc['search_mode'] ?? 'smart';
            $segment_match = $sc['segment_match'] ?? 'any';
            $max_results   = isset($sc['max_results']) ? max(0, (int)$sc['max_results']) : 0;
            $blocked_cats  = $sc['blocked_cats'] ?? [];

            // ====== 屏蔽栏目：排除被选中的栏目 ======
            if (!empty($blocked_cats) && is_array($blocked_cats)) {
                $in_ids = implode(',', array_map('intval', $blocked_cats));
                $wheres[] = "p.category_id NOT IN ({$in_ids}) AND NOT EXISTS (
                    SELECT 1 FROM {$tododb->prefix}term_relationships tr_block 
                    WHERE tr_block.object_id = p.ID AND tr_block.term_taxonomy_id IN ({$in_ids})
                )";
            }

            // ====== 多语言搜索支持 ======
            $curr_lang = function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN');
            $canonical_lang = function_exists('canonical_locale') ? canonical_locale($curr_lang) : str_replace('_', '-', $curr_lang);
            $alt_lang = str_replace('-', '_', $canonical_lang);

            $default_lang = function_exists('get_default_language') ? get_default_language() : (function_exists('todo_get_default_language') ? todo_get_default_language() : 'zh-CN');
            $canonical_default = function_exists('canonical_locale') ? canonical_locale($default_lang) : str_replace('_', '-', $default_lang);
            $is_default_lang = (strcasecmp($canonical_lang, $canonical_default) === 0);

            $has_trans_table = method_exists($tododb, 'table_exists') ? $tododb->table_exists('post_translations') : true;
            $need_trans_join = $has_trans_table && !$is_default_lang;
            if ($need_trans_join) {
                $esc_lang1 = $tododb->esc_like($canonical_lang);
                $esc_lang2 = $tododb->esc_like($alt_lang);
                $joins .= " LEFT JOIN {$tododb->prefix}post_translations ptr ON (ptr.post_id = p.ID AND (ptr.language_code = '{$esc_lang1}' OR ptr.language_code = '{$esc_lang2}'))";
            }

            // ====== 搜索模式 ======
            switch ($search_mode) {
                case 'exact':
                    // 标题完全匹配
                    if ($need_trans_join) {
                        $wheres[] = "(p.post_title = ? OR ptr.title = ? OR ptr.seo_title = ?)";
                        $params[] = $search_keyword;
                        $params[] = $search_keyword;
                        $params[] = $search_keyword;
                    } else {
                        $wheres[] = "p.post_title = ?";
                        $params[] = $search_keyword;
                    }
                    break;
                case 'segment':
                    // 标题分词
                    $token_groups = self::get_search_token_groups($search_keyword);
                    if ($segment_match === 'all') {
                        // 全部词汇：必须全部命中主分词组中的每个词元（例如同时包含 "高性能" 和 "缓存"）
                        $all_tokens = !empty($token_groups) ? $token_groups[0] : [$search_keyword];
                        foreach ($all_tokens as $seg) {
                            $seg = trim($seg);
                            if ($seg === '') continue;
                            $like_val = '%' . $tododb->esc_like($seg) . '%';
                            if ($need_trans_join) {
                                $wheres[] = "(p.post_title LIKE ? OR ptr.title LIKE ? OR ptr.seo_title LIKE ? OR ptr.seo_keywords LIKE ?)";
                                $params[] = $like_val;
                                $params[] = $like_val;
                                $params[] = $like_val;
                                $params[] = $like_val;
                            } else {
                                $wheres[] = "p.post_title LIKE ?";
                                $params[] = $like_val;
                            }
                        }
                    } else {
                        // any: 匹配任意一个分词即显示
                        $any_tokens = [];
                        foreach ($token_groups as $tg) {
                            foreach ($tg as $w) {
                                if (mb_strlen($w, 'UTF-8') >= 2) {
                                    $any_tokens[] = $w;
                                }
                            }
                        }
                        if (empty($any_tokens)) {
                            $any_tokens = [$search_keyword];
                        } else {
                            $any_tokens = array_values(array_unique($any_tokens));
                        }
                        $seg_wheres = [];
                        foreach ($any_tokens as $seg) {
                            $like_val = '%' . $tododb->esc_like($seg) . '%';
                            if ($need_trans_join) {
                                $seg_wheres[] = "(p.post_title LIKE ? OR ptr.title LIKE ? OR ptr.seo_title LIKE ? OR ptr.seo_keywords LIKE ?)";
                                $params[] = $like_val;
                                $params[] = $like_val;
                                $params[] = $like_val;
                                $params[] = $like_val;
                            } else {
                                $seg_wheres[] = "p.post_title LIKE ?";
                                $params[] = $like_val;
                            }
                        }
                        if (!empty($seg_wheres)) {
                            $wheres[] = '(' . implode(' OR ', $seg_wheres) . ')';
                        }
                    }
                    break;
                default:
                    // smart: 纯 PHP 智能分词交叉检索（零外部依赖，毫秒级交叉命中"高性能*缓存"等跳词复合搜索）
                    $token_groups = self::get_search_token_groups($search_keyword);
                    $like_full = '%' . $tododb->esc_like($search_keyword) . '%';

                    // 主分支：原词全匹配（标题或内容完整命中）
                    $smart_branches = ["(p.post_title LIKE ? OR p.post_content LIKE ?)"];
                    $smart_params = [$like_full, $like_full];

                    if (!empty($token_groups)) {
                        foreach ($token_groups as $group) {
                            if (empty($group)) continue;
                            // 标题交叉命中分支：当前分组的所有词元必须全部在标题中出现（AND 逻辑，如既有'高性能'又有'缓存'）
                            $title_and = [];
                            foreach ($group as $tok) {
                                $title_and[] = "p.post_title LIKE ?";
                                $smart_params[] = '%' . $tododb->esc_like($tok) . '%';
                            }
                            $smart_branches[] = "(" . implode(' AND ', $title_and) . ")";

                            // 正文交叉命中分支：词元在 2~4 个时同时检查正文交叉匹配
                            if (count($group) >= 2 && count($group) <= 4) {
                                $content_and = [];
                                foreach ($group as $tok) {
                                    $content_and[] = "p.post_content LIKE ?";
                                    $smart_params[] = '%' . $tododb->esc_like($tok) . '%';
                                }
                                $smart_branches[] = "(" . implode(' AND ', $content_and) . ")";
                            }
                        }
                    }

                    if ($need_trans_join) {
                        $smart_branches[] = "(ptr.title LIKE ? OR ptr.content LIKE ?)";
                        $smart_params[] = $like_full;
                        $smart_params[] = $like_full;
                    }

                    $wheres[] = "(" . implode(" OR ", $smart_branches) . ")";
                    foreach ($smart_params as $sp) {
                        $params[] = $sp;
                    }
                    break;
            }

            // 注意：不再在 ORDER BY 里拼装 MIN(CASE WHEN ...) 聚合函数！
            // 聚合函数强制导致整表产生临时表并全表 filesort（2.7秒/次）。
            // 保持正常时间倒序索引流式返回，秒级出结果。

        }
        // noid 排除参数：过滤指定分类 ID 的文章
        $noid = [];
        if (isset($parsed_args['noid']) && $parsed_args['noid'] !== '') {
            $noid_raw = is_array($parsed_args['noid']) ? $parsed_args['noid'] : explode(',', (string) $parsed_args['noid']);
            $noid = array_values(array_filter(array_map('intval', (array) $noid_raw), function ($v) { return $v > 0; }));
        }
        if (!empty($noid)) {
            $noid_in = implode(',', $noid);
            $wheres[] = "( (p.category_id IS NULL OR p.category_id NOT IN ({$noid_in})) AND NOT EXISTS (SELECT 1 FROM {$tododb->prefix}term_relationships tr_noid JOIN {$tododb->prefix}term_taxonomy tt_noid ON tr_noid.term_taxonomy_id = tt_noid.term_taxonomy_id WHERE tr_noid.object_id = p.ID AND tt_noid.taxonomy = 'category' AND tt_noid.term_id IN ({$noid_in})) )";
        }

        // 产品模型：排除已下架商品（sale_status = 'warehouse'）
        if ($base_name === 'products') {
            $joins .= " LEFT JOIN {$tododb->prefix}model_fields_product mfp ON mfp.post_id = p.ID";
            $wheres[] = "(mfp.sale_status IS NULL OR mfp.sale_status <> 'warehouse')";
        }

        $where_sql = !empty($wheres) ? ' WHERE ' . implode(' AND ', $wheres) : '';
        $group_by = !empty($joins) ? ' GROUP BY p.ID' : '';

        $is_capped_search = ($base_name === 'search_results' && $max_results > 0);
        if ($is_capped_search && $offset >= $max_results) {
            $res = [];
        } else {
            $fetch_limit = $per_page;
            if ($is_capped_search) {
                $fetch_limit = min($per_page, $max_results - $offset);
            }
            $sql = "SELECT p.* FROM {$posts_table} p{$joins}{$where_sql}{$group_by} ORDER BY" . ($prop_sort_sql !== '' ? " {$prop_sort_sql}," : '') . " p.{$orderby} {$order}, p.ID DESC LIMIT ? OFFSET ?";
            $params[] = $fetch_limit; $params[] = $offset;
            $res = $tododb->get_results($sql, $params);
        }

        if ($base_name === 'search_results' || $base_name === 'posts' ||
            $base_name === 'products' || $base_name === 'jobs' || $base_name === 'specials' || $base_name === 'special_list' ||
            $base_name === 'guestbooks' || $base_name === 'single_pages' ||
            $base_name === 'images' || $base_name === 'downloads' || $base_name === 'media') {
            if (!is_object($todo_query)) $todo_query = new stdClass();
            
            // 短路优化 1：若仅处于 {{todo:if ...}} 条件判断阶段，无需耗费数十毫秒甚至上秒进行整表 COUNT
            // 短路优化 2：第一页若查到的记录数小于每页限制（如查到 0~9 条），总数必然等于结果数，直接免去 COUNT
            if (!empty(self::$in_condition_eval)) {
                $total_count = count($res);
            } elseif ($offset === 0 && count($res) < $per_page) {
                $total_count = count($res);
            } else {
                $count_distinct = ($joins !== '') ? 'DISTINCT ' : '';
                if ($is_capped_search) {
                    $cap_limit = $max_results + 1;
                    $count_sql = "SELECT COUNT(*) FROM (SELECT {$count_distinct}p.ID FROM {$posts_table} p{$joins}{$where_sql} LIMIT {$cap_limit}) AS _cnt_sub";
                } else {
                    $count_sql = "SELECT COUNT({$count_distinct}p.ID) FROM {$posts_table} p{$joins}{$where_sql}";
                }
                $count_params = array_slice($params, 0, count($params) - 2);
                $count_cache_key = 'cnt_' . md5($count_sql . '|' . serialize($count_params));
                $total_count = function_exists('todo_cache_get') ? todo_cache_get($count_cache_key, 'query_count') : false;
                if ($total_count === false || $total_count === null) {
                    $total_count = $tododb->get_var($count_sql, $count_params);
                    if ($is_capped_search && (int)$total_count > $max_results) {
                        $total_count = $max_results;
                    }
                    if (function_exists('todo_cache_set')) {
                        todo_cache_set($count_cache_key, (int) $total_count, 300, 'query_count');
                    }
                }
                $total_count = (int) $total_count;
            }
            if ($is_capped_search && $total_count > $max_results) {
                $total_count = $max_results;
            }
            $todo_query->max_num_pages = ceil($total_count / $per_page);
            $todo_query->post_count = count($res);
            $todo_query->found_posts = $total_count;

            // 计算分页元数据，供 compileLoop 注入模板
            $total_pages = max(1, intval(ceil($total_count / $per_page)));
            self::$loop_pagination = [
                'per_page'                 => $per_page,
                'total_pages'              => $total_pages,
                'pagination'               => ($total_pages > 1) ? 1 : 0,
                'pagination_total'         => $total_count,
                'pagination_prev_page'     => ($page > 1) ? ($page - 1) : 0,
                'pagination_next_page'     => ($page < $total_pages) ? ($page + 1) : 0,
                'pagination_current_page'  => $page,
                'pagination_total_pages'   => $total_pages,
                'pagination_page_param'    => $page_param,
                'pagination_mid_size'      => $mid_size,
                'pagination_end_size'      => $end_size,
            ];
        }

        // 更新搜索日志的结果数
        if ($base_name === 'search_results' && $search_keyword !== '' && isset($total_count)) {
            $log_table = TABLE_PREFIX . 'search_logs';
            if ($tododb->table_exists($log_table)) {
                $tododb->query(
                    "UPDATE {$log_table} SET results_count = ? WHERE keyword = ? ORDER BY id DESC LIMIT 1",
                    [(int)$total_count, $search_keyword]
                );
            }
            // 同步更新搜索聚合表的结果数
            $hot_table = TABLE_PREFIX . 'search_hot';
            if ($tododb->table_exists($hot_table)) {
                $tododb->query(
                    "UPDATE {$hot_table} SET results_count = ? WHERE keyword = ?",
                    [(int)$total_count, $search_keyword]
                );
            }
        }

        if ($need_prop_sort) {
            // 解析属性ID：数字直接使用，字符串查表转换
            $prop_ids = []; $code_ints = []; $code_strs = [];
            foreach ($prop_codes as $pc) { if (ctype_digit($pc)) $code_ints[] = intval($pc); else $code_strs[] = $pc; }
            $prop_ids = $code_ints;
            if (!empty($code_strs)) {
                $in = implode(',', array_fill(0, count($code_strs), '?'));
                $prop_table = $tododb->prefix . 'doc_properties';
                $map = $tododb->get_results(
                    "SELECT id FROM {$prop_table} WHERE is_active = 1 AND (value IN ($in) OR name IN ($in))",
                    array_merge($code_strs, $code_strs)
                );
                foreach ((array) $map as $m) { $prop_ids[] = is_array($m) ? intval($m['id']) : (isset($m->id) ? intval($m->id) : 0); }
                $prop_ids = array_values(array_filter($prop_ids, function($v){return $v>0;}));
            }

            // 置顶模式：匹配属性的文章排前面，其余保持原序（SQL 已做初排，此处精排）
            $matched = []; $unmatched = [];
            foreach ((array) $res as $row) {
                $props = [];
                if (isset($row->doc_properties) && !empty($row->doc_properties)) {
                    $props = array_values(array_filter(array_map('intval', explode(',', trim($row->doc_properties))), function($id){return $id>0;}));
                }
                $match_count = 0; $max_attr_priority = 999;
                foreach ($prop_ids as $pi) {
                    if (in_array($pi, $props, true)) {
                        $match_count++;
                        $attr_p = ($pi == 1) ? 1 : (($pi == 2) ? 2 : 3);
                        if ($attr_p < $max_attr_priority) $max_attr_priority = $attr_p;
                    }
                }
                if ($match_count > 0) {
                    $sort_key = sprintf('%03d_%03d', (999 - $match_count), $max_attr_priority);
                    $matched[] = ['row' => $row, 'sort' => $sort_key];
                } else {
                    $unmatched[] = $row;
                }
            }
            usort($matched, function($a, $b) { return strcmp($a['sort'], $b['sort']); });
            $res = [];
            foreach ($matched as $m) $res[] = $m['row'];
            foreach ($unmatched as $r) $res[] = $r;
        }
        if (!empty($res) && class_exists('Todo_Post_ORM')) {
            $post_ids = [];
            foreach ($res as $row) { $pid = isset($row->ID) ? $row->ID : (is_array($row) && isset($row['ID']) ? $row['ID'] : 0); if ($pid > 0) $post_ids[] = $pid; }
            if (!empty($post_ids)) {
                Todo_Post_ORM::eager_load_all($post_ids, ['meta' => true, 'terms' => true, 'multilang' => true, 'tags' => true, 'categories' => true, 'author' => true]);
                if (class_exists('CMS_User') && method_exists('CMS_User', 'prime_cache')) {
                    $author_ids = [];
                    foreach ($res as $row) { $aid = is_object($row) ? (isset($row->post_author) ? $row->post_author : 0) : (isset($row['post_author']) ? $row['post_author'] : 0); if ($aid > 0) $author_ids[] = $aid; }
                    if (!empty($author_ids)) CMS_User::prime_cache(array_unique($author_ids));
                }
                
                $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
                $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);
                if ($apply_i18n && $language_code && function_exists('todo_get_posts_multilang_batch')) {
                    if (strpos($language_code, '_') !== false) {
                        $language_code = str_replace('_', '-', $language_code);
                    }
                    $translations = todo_get_posts_multilang_batch($post_ids, $language_code, [
                        'include_seo' => true,
                        'fallback' => true,
                        'status_allowed' => ['approved', 'draft']
                    ]);
                    foreach ($res as $idx => $row) {
                        $pid = is_object($row) ? (isset($row->ID) ? $row->ID : 0) : (isset($row['ID']) ? $row['ID'] : 0);
                        if ($pid > 0 && isset($translations[$pid])) {
                            $agg = $translations[$pid];
                            if (is_object($row)) {
                                $row = clone $row;
                                $res[$idx] = $row;
                                if (isset($agg['title'])) $row->post_title = $agg['title'];
                                if (isset($agg['content'])) $row->post_content = $agg['content'];
                                if (isset($agg['excerpt'])) $row->post_excerpt = $agg['excerpt'];
                                if (isset($agg['slug'])) $row->post_name = $agg['slug'];
                            } else {
                                if (isset($agg['title'])) $row['post_title'] = $agg['title'];
                                if (isset($agg['content'])) $row['post_content'] = $agg['content'];
                                if (isset($agg['excerpt'])) $row['post_excerpt'] = $agg['excerpt'];
                                if (isset($agg['slug'])) $row['post_name'] = $agg['slug'];
                                $res[$idx] = $row;
                            }
                        }
                    }
                }
            }
        }
        if (!empty($res)) {
            $p_ids = [];
            $img_urls = [];
            foreach ((array) $res as $row) {
                $pid = is_object($row) ? (isset($row->ID) ? intval($row->ID) : 0) : (isset($row['ID']) ? intval($row['ID']) : 0);
                if ($pid > 0) $p_ids[] = $pid;
                $fi = is_object($row) ? ($row->featured_image ?? '') : ($row['featured_image'] ?? '');
                if (is_string($fi) && $fi !== '') $img_urls[] = $fi;
            }
            if (!empty($p_ids)) {
                self::prime_post_custom_fields_batch($p_ids, $res);
            }
            if (!empty($img_urls) && class_exists('CMS_HTML_Template_Tags') && method_exists('CMS_HTML_Template_Tags', 'prefetch_media_info')) {
                CMS_HTML_Template_Tags::prefetch_media_info($img_urls);
            }
        }
        // 对每行应用 format_post_item，确保 article_* 别名字段可用
        $formatted = [];
        foreach ((array) $res as $row) {
            $formatted[] = self::format_post_item($row, $cat_id);
        }

        // 存入请求级记忆
        if (isset($req_cache_key)) {
            self::$request_loop_cache[$req_cache_key] = [
                'res' => $formatted,
                'pagination' => self::$loop_pagination ?? [],
                'todo_query' => is_object($todo_query) ? clone $todo_query : null,
            ];
        }

        return $formatted;
    }

    private static function loop_model_field_list($field_name, $data, $parsed_args, $keys) {
        $post_id = isset($data['post_id']) ? intval($data['post_id']) : (isset($data['ID']) ? intval($data['ID']) : (isset($data['id']) ? intval($data['id']) : (isset($data['article_id']) ? intval($data['article_id']) : 0)));
        if ($post_id <= 0) {
            if (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) $post_id = intval($data['post']->ID);
        }
        if ($post_id <= 0) { global $post; if ($post && isset($post->ID)) $post_id = intval($post->ID); }
        if ($post_id <= 0) return [];

        $post_obj = null;
        if (isset($data['post']) && is_object($data['post'])) $post_obj = $data['post'];
        else { global $post; if ($post && is_object($post)) $post_obj = $post; }
        $model_key = ($post_obj && isset($post_obj->post_type)) ? (string) $post_obj->post_type : '';
        if ($model_key === '') $model_key = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';

        $raw = null; global $tododb;
        if ($model_key !== '' && isset($tododb)) {
            $table = $tododb->prefix . 'model_fields_' . $model_key;
            $exists = function_exists('table_exists') ? table_exists($table) : (preg_match('/^[a-zA-Z0-9_]+$/', $table) ? (bool)$tododb->get_var("SHOW TABLES LIKE '{$table}'") : false);
            if ($exists) {
                $row = self::get_model_table_row($table, $post_id);
                if (is_array($row) && array_key_exists($field_name, $row) && $row[$field_name] !== null && $row[$field_name] !== '') $raw = maybe_unserialize($row[$field_name]);
            }
        }
        if ($raw === null || $raw === '' || $raw === []) {
            $meta = function_exists('get_post_meta') ? get_post_meta($post_id, $field_name, true) : null;
            if ($meta !== '' && $meta !== null) $raw = $meta;
        }
        if (is_string($raw) && trim($raw) === '') return [];
        if (is_string($raw)) { $decoded = json_decode(trim($raw), true); if (is_array($decoded)) $raw = $decoded; else { $parts = preg_split('/[\r\n,]+/', trim($raw)); $raw = array_values(array_filter(array_map('trim', (array)$parts), function($v){return $v!=='';})); } }
        if (!is_array($raw)) return [];

        $items = [];
        // 规范化 URL 值：补全 CDN / 云存储绝对 URL
        $normalize_url = function ($v, $k) {
            if (($k === 'url' || $k === 'thumb' || $k === 'image' || $k === 'src') && is_string($v) && $v !== '' && function_exists('todo_media_url_to_runtime_absolute')) {
                return todo_media_url_to_runtime_absolute($v);
            }
            return $v;
        };
        foreach ($raw as $it) {
            if (is_string($it)) { $u = trim($it); if ($u === '') continue; $items[] = [reset($keys) => $u]; continue; }
            if (!is_array($it)) continue;
            $item = $it;
            foreach ($keys as $idx => $k) {
                $raw_val = $it[$k] ?? '';
                if (is_array($raw_val)) {
                    $item[$k] = $raw_val;
                } else {
                    $val = is_scalar($raw_val) ? (string)$raw_val : '';
                    $item[$k] = $normalize_url($val, $k);
                }
            }
            $items[] = $item;
        }
        return $items;
    }

    private static function loop_simple_text_list($field_name, $data, $key_name) {
        $raw = self::get_todo_variable($field_name, $data);
        if (is_array($raw)) $list = $raw;
        else { $t = is_string($raw) ? trim($raw) : ''; if ($t === '') return []; $decoded = json_decode($t, true); if (is_array($decoded)) $list = $decoded; else { $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $t)), function($v){return $v!=='';})); } }
        $items = [];
        foreach ($list as $line) { if (is_string($line)) { $s = trim($line); if ($s !== '') $items[] = [$key_name => $s]; } }
        return $items;
    }

    private static function loop_benefits($data) {
        $raw = self::get_todo_variable('benefits', $data);
        if (is_array($raw)) $list = $raw;
        else { $t = is_string($raw) ? trim($raw) : ''; if ($t === '') return []; $decoded = json_decode($t, true); if (is_array($decoded)) $list = $decoded; else { $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $t)), function($v){return $v!=='';})); } }
        $items = [];
        foreach ($list as $line) {
            if (is_array($line)) { $items[] = ['icon' => isset($line['icon']) ? (string)$line['icon'] : '', 'name' => isset($line['name']) ? (string)$line['name'] : '']; continue; }
            if (!is_string($line)) continue;
            $s = trim($line); if ($s === '') continue;
            if (strpos($s, '|') !== false) { $parts = array_map('trim', explode('|', $s, 2)); $items[] = ['icon' => $parts[0], 'name' => $parts[1] ?? '']; }
            else { $items[] = ['icon' => '', 'name' => $s]; }
        }
        return $items;
    }

    private static function loop_related_content($data, $parsed_args) {
        $post_id = isset($data['post_id']) ? intval($data['post_id']) : (isset($data['ID']) ? intval($data['ID']) : (isset($data['id']) ? intval($data['id']) : (isset($data['article_id']) ? intval($data['article_id']) : 0)));
        if ($post_id <= 0) { if (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) $post_id = intval($data['post']->ID); }
        if ($post_id <= 0) { global $post; if ($post && isset($post->ID)) $post_id = intval($post->ID); }
        if ($post_id <= 0) return [];
        global $tododb; if (!isset($tododb)) return [];
        if (is_array($parsed_args) && !isset($parsed_args['limit']) && isset($parsed_args['per_page'])) $parsed_args['limit'] = $parsed_args['per_page'];
        $per_page = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : 12;
        $page = isset($parsed_args['page']) ? max(1, intval($parsed_args['page'])) : 1;
        $base_offset = isset($parsed_args['offset']) ? max(0, intval($parsed_args['offset'])) : 0;
        $offset = $base_offset + (($page - 1) * $per_page);
        $need = $offset + $per_page;
        $mode_raw = self::get_todo_variable('special_mode', $data);
        $mode = is_scalar($mode_raw) ? trim((string)$mode_raw) : '';
        if ($mode === '' || !in_array($mode, ['manual','rule','mixed'], true)) $mode = 'mixed';
        $ids_raw = self::get_todo_variable('related_posts', $data);
        $manual_ids = [];
        if (is_array($ids_raw)) $manual_ids = array_values(array_unique(array_filter(array_map('intval', $ids_raw), function($v){return $v>0;})));
        else { $t = is_scalar($ids_raw) ? trim((string)$ids_raw) : ''; if ($t !== '') { $d = json_decode($t, true); $arr = is_array($d) ? $d : preg_split('/[\r\n,]+/', $t); foreach ((array)$arr as $v) { if (is_numeric($v)) $manual_ids[] = intval($v); } $manual_ids = array_values(array_unique(array_filter($manual_ids, function($v){return $v>0;}))); } }
        $rule_cat_ids = []; $cat_raw = self::get_todo_variable('related_categories', $data);
        $cat_parts = is_array($cat_raw) ? $cat_raw : (is_scalar($cat_raw) && trim((string)$cat_raw) !== '' ? preg_split('/[\r\n,\s]+/', trim((string)$cat_raw)) : []);
        foreach ((array)$cat_parts as $v) { if (is_numeric($v)) $rule_cat_ids[] = intval($v); } $rule_cat_ids = array_values(array_unique(array_filter($rule_cat_ids, function($v){return $v>0;})));
        $rule_tag_terms = []; $tag_raw = self::get_todo_variable('related_tags', $data);
        $tag_parts = is_array($tag_raw) ? $tag_raw : (is_scalar($tag_raw) && trim((string)$tag_raw) !== '' ? preg_split('/[\r\n,]+/', trim((string)$tag_raw)) : []);
        foreach ((array)$tag_parts as $v) { if (is_string($v) && trim($v) !== '') $rule_tag_terms[] = trim($v); } $rule_tag_terms = array_values(array_unique($rule_tag_terms));
        $rule_tag_ids = [];
        if (!empty($rule_tag_terms)) {
            $slugs = []; $names = [];
            foreach ($rule_tag_terms as $v) { $slug = function_exists('sanitize_title') ? sanitize_title($v) : $v; if ($slug !== '') $slugs[] = $slug; $names[] = $v; }
            $slugs = array_values(array_unique(array_filter($slugs, function($v){return $v!=='';})));
            $names = array_values(array_unique(array_filter($names, function($v){return $v!=='';})));
            $parts = []; $params = ['post_tag'];
            if (!empty($slugs)) { $parts[] = "t.slug IN (" . implode(',', array_fill(0, count($slugs), '?')) . ")"; foreach ($slugs as $s) $params[] = $s; }
            if (!empty($names)) { $parts[] = "t.name IN (" . implode(',', array_fill(0, count($names), '?')) . ")"; foreach ($names as $n) $params[] = $n; }
            if (!empty($parts)) {
                $sql = "SELECT tt.term_id FROM {$tododb->prefix}terms t JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = ? AND (" . implode(' OR ', $parts) . ")";
                $rows = $tododb->get_results($sql, $params);
                if (is_array($rows)) foreach ($rows as $r) { $tid = is_object($r) ? intval($r->term_id ?? 0) : intval($r['term_id'] ?? 0); if ($tid > 0) $rule_tag_ids[] = $tid; }
                $rule_tag_ids = array_values(array_unique($rule_tag_ids));
            }
        }
        $lang_filter = '';
        if (!defined('IS_ADMIN') || !IS_ADMIN) {
            $cl = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : 'zh-CN'); $cl = str_replace('_', '-', $cl);
            $dl = function_exists('get_default_language') ? get_default_language() : 'zh-CN'; $dl = str_replace('_', '-', $dl);
            if ($cl !== $dl) { $lang_escaped = $tododb->esc_like($cl); $lang_filter = " AND p.ID IN (SELECT post_id FROM {$tododb->prefix}postmeta WHERE meta_key = '_enabled_languages' AND meta_value LIKE '%\"{$lang_escaped}\"%')"; }
        }
        $site_filter = '';
        $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
        if ($current_site_id > 0 && $has_sp) {
            $site_posts_table = $tododb->prefix . 'site_posts';
            $site_filter = " AND (EXISTS (SELECT 1 FROM {$site_posts_table} sp WHERE sp.post_id = p.ID AND sp.site_id = {$current_site_id} AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_posts_table} spx WHERE spx.post_id = p.ID))";
        }

        $has_rule = (!empty($rule_cat_ids) || !empty($rule_tag_ids));
        $rule_ids = [];
        if ($has_rule) {
            $p = $tododb->prefix . 'posts'; $tr = $tododb->prefix . 'term_relationships'; $tt = $tododb->prefix . 'term_taxonomy';
            $where = ["p.post_status = 'publish'", "p.ID <> ?", "p.post_type <> 'special' {$lang_filter} {$site_filter}"]; $params = [$post_id];
            $tax_parts = [];
            if (!empty($rule_cat_ids)) { $tax_parts[] = "(tt.taxonomy = 'category' AND tt.term_id IN (" . implode(',', array_fill(0, count($rule_cat_ids), '?')) . "))"; foreach ($rule_cat_ids as $cid) $params[] = $cid; }
            if (!empty($rule_tag_ids)) { $tax_parts[] = "(tt.taxonomy = 'post_tag' AND tt.term_id IN (" . implode(',', array_fill(0, count($rule_tag_ids), '?')) . "))"; foreach ($rule_tag_ids as $tid) $params[] = $tid; }
            if (!empty($tax_parts)) $where[] = '(' . implode(' OR ', $tax_parts) . ')';
            if (!empty($manual_ids)) { $where[] = "p.ID NOT IN (" . implode(',', array_fill(0, count($manual_ids), '?')) . ")"; foreach ($manual_ids as $mid) $params[] = $mid; }
            $sql = "SELECT DISTINCT p.ID FROM {$p} p JOIN {$tr} tr ON tr.object_id = p.ID JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE " . implode(' AND ', $where) . " ORDER BY p.post_date DESC LIMIT ?";
            $params[] = max(1, (int) $need);
            $rows = $tododb->get_results($sql, $params);
            if (is_array($rows)) foreach ($rows as $r) { $rid = is_object($r) ? intval($r->ID ?? 0) : intval($r['ID'] ?? 0); if ($rid > 0) $rule_ids[] = $rid; } $rule_ids = array_values(array_unique($rule_ids));
        }
        if ($mode === 'manual') $combined = $manual_ids;
        elseif ($mode === 'rule') $combined = $rule_ids;
        else { $seen = []; $combined = []; foreach ($manual_ids as $idv) { if (!isset($seen[$idv])) { $seen[$idv] = true; $combined[] = $idv; } } foreach ($rule_ids as $idv) { if (!isset($seen[$idv])) { $seen[$idv] = true; $combined[] = $idv; } } }
        $slice = array_slice($combined, $offset, $per_page); if (empty($slice)) return [];
        $in = implode(',', array_fill(0, count($slice), '?'));
        $rows = $tododb->get_results("SELECT p.ID, p.post_title, p.post_excerpt, p.post_date FROM {$tododb->prefix}posts p WHERE p.post_status = 'publish' AND p.ID IN ({$in}) {$lang_filter} {$site_filter}", $slice);
        if (!is_array($rows)) return [];
        $map = []; foreach ($rows as $r) { $rid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0); if ($rid > 0) $map[$rid] = $r; }
        $items = [];
        foreach ($slice as $pid) { if (!isset($map[$pid])) continue; $r = $map[$pid]; $items[] = ['id' => $pid, 'title' => is_object($r) ? (string)$r->post_title : (string)($r['post_title']??''), 'description' => is_object($r) ? (string)$r->post_excerpt : (string)($r['post_excerpt']??''), 'add_time' => is_object($r) ? (string)$r->post_date : (string)($r['post_date']??''), 'url' => function_exists('todo_get_permalink') ? todo_get_permalink($pid) : '', 'thumb' => function_exists('get_the_post_thumbnail_url') ? (string)get_the_post_thumbnail_url($pid, 'full') : '']; }
        return $items;
    }

    private static function get_special_field_value($field_name, $data) {
        $val = self::get_todo_variable($field_name, $data);
        if (!empty($val)) {
            return $val;
        }
        $post_id = isset($data['post_id']) ? intval($data['post_id']) : (isset($data['ID']) ? intval($data['ID']) : (isset($data['id']) ? intval($data['id']) : 0));
        if ($post_id <= 0 && isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) {
            $post_id = intval($data['post']->ID);
        }
        if ($post_id <= 0) { global $post; if ($post && isset($post->ID)) $post_id = intval($post->ID); }

        if ($post_id > 0) {
            global $tododb;
            if (isset($tododb)) {
                $table = $tododb->prefix . 'model_fields_special';
                $exists = function_exists('table_exists')
                    ? table_exists($table)
                    : (preg_match('/^[a-zA-Z0-9_]+$/', $table) ? (bool) $tododb->get_var("SHOW TABLES LIKE '{$table}'") : false);
                if ($exists) {
                    $row = $tododb->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
                    if (is_array($row) && isset($row[$field_name]) && $row[$field_name] !== '' && $row[$field_name] !== null) {
                        return $row[$field_name];
                    }
                }
            }
        }
        return '';
    }

    private static function loop_special_featured_posts($data, $parsed_args) {
        $ids_raw = self::get_special_field_value('related_posts', $data);
        $ids = [];
        if (is_array($ids_raw)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids_raw), function($v){return $v>0;})));
        } else {
            $t = is_scalar($ids_raw) ? trim((string)$ids_raw) : '';
            if ($t !== '') {
                $d = json_decode($t, true);
                $arr = is_array($d) ? $d : preg_split('/[\r\n,\s]+/', $t);
                foreach ((array)$arr as $v) { if (is_numeric($v)) $ids[] = intval($v); }
                $ids = array_values(array_unique(array_filter($ids, function($v){return $v>0;})));
            }
        }
        if (empty($ids)) return [];

        global $tododb;
        if (!isset($tododb)) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$tododb->prefix}posts WHERE post_status = 'publish' AND ID IN ({$in})";
        $rows = $tododb->get_results($sql, $ids);
        if (!is_array($rows) || empty($rows)) return [];

        $map = [];
        foreach ($rows as $r) {
            $pid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0);
            if ($pid > 0) $map[$pid] = $r;
        }

        $items = [];
        foreach ($ids as $pid) {
            if (isset($map[$pid])) {
                $items[] = self::format_post_item($map[$pid]);
            }
        }
        return $items;
    }

    private static function loop_special_categories($data, $parsed_args) {
        $cat_raw = self::get_special_field_value('related_categories', $data);
        $cat_ids = [];
        if (is_array($cat_raw)) {
            $cat_ids = array_values(array_unique(array_filter(array_map('intval', $cat_raw), function($v){return $v>0;})));
        } else {
            $t = is_scalar($cat_raw) ? trim((string)$cat_raw) : '';
            if ($t !== '') {
                $d = json_decode($t, true);
                $arr = is_array($d) ? $d : preg_split('/[\r\n,\s]+/', $t);
                foreach ((array)$arr as $v) { if (is_numeric($v)) $cat_ids[] = intval($v); }
                $cat_ids = array_values(array_unique(array_filter($cat_ids, function($v){return $v>0;})));
            }
        }
        if (empty($cat_ids)) return [];

        global $tododb;
        if (!isset($tododb)) return [];

        $limit = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : 7;
        $items = [];

        foreach ($cat_ids as $cid) {
            $cat_name = '';
            $cat_url = '';
            $t_row = $tododb->get_row(
                "SELECT t.name, t.slug FROM {$tododb->prefix}terms t JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category' AND t.term_id = ? LIMIT 1",
                [$cid],
                'ARRAY_A'
            );
            if ($t_row) {
                $cat_name = $t_row['name'] ?? '';
                $cat_url = function_exists('todo_get_category_link') ? todo_get_category_link($cid) : (function_exists('get_category_link') ? get_category_link($cid) : (function_exists('todo_home_url') ? todo_home_url('?cat=' . $cid) : ''));
            }
            if ($cat_name === '') continue;

            $posts_table = $tododb->prefix . 'posts';
            $tr_table = $tododb->prefix . 'term_relationships';
            $tt_table = $tododb->prefix . 'term_taxonomy';
            $sql = "SELECT p.* FROM {$posts_table} p JOIN {$tr_table} tr ON tr.object_id = p.ID JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tt.term_id = ? AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT ?";
            $rows = $tododb->get_results($sql, [$cid, $limit]);

            $articles = [];
            if (is_array($rows) && !empty($rows)) {
                $c_pids = [];
                foreach ($rows as $r) {
                    $pid = is_object($r) ? intval($r->ID ?? 0) : intval($r['ID'] ?? 0);
                    if ($pid > 0) $c_pids[] = $pid;
                }
                if (!empty($c_pids)) {
                    self::prime_post_custom_fields_batch($c_pids, $rows);
                }
                foreach ($rows as $r) {
                    $articles[] = self::format_post_item($r, $cid);
                }
            }

            $items[] = [
                'category_id' => $cid,
                'category_name' => $cat_name,
                'category_url' => $cat_url,
                'articles' => $articles,
                'articles_count' => count($articles),
            ];
        }

        return $items;
    }

    private static function loop_special_tags($data, $parsed_args) {
        $tag_raw = self::get_special_field_value('related_tags', $data);
        $tags = [];
        if (is_array($tag_raw)) {
            $tags = array_values(array_unique(array_filter(array_map('trim', $tag_raw), function($v){return $v!=='';})));
        } else {
            $t = is_scalar($tag_raw) ? trim((string)$tag_raw) : '';
            if ($t !== '') {
                $d = json_decode($t, true);
                $arr = is_array($d) ? $d : preg_split('/[\r\n,\s]+/', $t);
                foreach ((array)$arr as $v) { $v = trim((string)$v); if ($v !== '') $tags[] = $v; }
                $tags = array_values(array_unique($tags));
            }
        }
        if (empty($tags)) return [];

        global $tododb;
        if (!isset($tododb)) return [];

        $limit = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : 7;
        $items = [];

        foreach ($tags as $tag_val) {
            $tid = 0;
            $tag_name = $tag_val;
            $tag_url = '';

            if (ctype_digit($tag_val)) {
                $tid = intval($tag_val);
                // 查询标签名称
                $t_row = $tododb->get_row("SELECT tag_title FROM {$tododb->prefix}tags WHERE tag_id = ? LIMIT 1", [$tid], 'ARRAY_A');
                if ($t_row && !empty($t_row['tag_title'])) {
                    $tag_name = $t_row['tag_title'];
                } else {
                    $t_row2 = $tododb->get_row("SELECT name FROM {$tododb->prefix}terms WHERE term_id = ? LIMIT 1", [$tid], 'ARRAY_A');
                    if ($t_row2 && !empty($t_row2['name'])) {
                        $tag_name = $t_row2['name'];
                    }
                }
            } else {
                $slug = function_exists('sanitize_title') ? sanitize_title($tag_val) : $tag_val;
                // 1. 优先在 tags 表查找
                $row = $tododb->get_row(
                    "SELECT tag_id, tag_title, tag_slug FROM {$tododb->prefix}tags WHERE tag_slug = ? OR tag_title = ? LIMIT 1",
                    [$slug, $tag_val],
                    'ARRAY_A'
                );
                if ($row) {
                    $tid = intval($row['tag_id']);
                    $tag_name = $row['tag_title'];
                } else {
                    // 2. 回退到 terms / term_taxonomy 表查找
                    $row = $tododb->get_row(
                        "SELECT t.term_id, t.name, t.slug FROM {$tododb->prefix}terms t JOIN {$tododb->prefix}term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'post_tag' AND (t.slug = ? OR t.name = ?) LIMIT 1",
                        [$slug, $tag_val],
                        'ARRAY_A'
                    );
                    if ($row) {
                        $tid = intval($row['term_id']);
                        $tag_name = $row['name'];
                    }
                }
            }

            $tag_url = function_exists('todo_get_tag_link') ? todo_get_tag_link($tid > 0 ? $tid : $tag_name) : (function_exists('get_tag_link') ? get_tag_link($tid) : '');

            $posts_table = $tododb->prefix . 'posts';
            $pt_table = $tododb->prefix . 'post_tags';
            $tr_table = $tododb->prefix . 'term_relationships';
            $tt_table = $tododb->prefix . 'term_taxonomy';

            $rows = [];
            if ($tid > 0) {
                // 1. 查询 post_tags 表
                $sql1 = "SELECT p.* FROM {$posts_table} p JOIN {$pt_table} pt ON pt.post_id = p.ID WHERE pt.tag_id = ? AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT ?";
                $rows = $tododb->get_results($sql1, [$tid, $limit]);

                // 2. 若无结果，查询 term_relationships 表
                if (empty($rows)) {
                    $sql2 = "SELECT p.* FROM {$posts_table} p JOIN {$tr_table} tr ON tr.object_id = p.ID JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'post_tag' AND tt.term_id = ? AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT ?";
                    $rows = $tododb->get_results($sql2, [$tid, $limit]);
                }
            }

            $articles = [];
            if (is_array($rows) && !empty($rows)) {
                $t_pids = [];
                foreach ($rows as $r) {
                    $pid = is_object($r) ? intval($r->ID ?? 0) : intval($r['ID'] ?? 0);
                    if ($pid > 0) $t_pids[] = $pid;
                }
                if (!empty($t_pids)) {
                    self::prime_post_custom_fields_batch($t_pids, $rows);
                }
                foreach ($rows as $r) {
                    $articles[] = self::format_post_item($r);
                }
            }

            $items[] = [
                'tag_id' => $tid,
                'tag_name' => $tag_name,
                'tag_url' => $tag_url,
                'articles' => $articles,
                'articles_count' => count($articles),
            ];
        }

        return $items;
    }

    private static function loop_timeline_events($data, $parsed_args) {
        $raw_events = self::loop_model_field_list('timeline_events', $data, $parsed_args, ['date', 'title', 'excerpt', 'content', 'url', 'image', 'time']);
        if (empty($raw_events)) return [];

        global $tododb;

        $items = [];
        foreach ($raw_events as $idx => $ev) {
            $date = trim((string)($ev['date'] ?? ''));
            $title = trim((string)($ev['title'] ?? ''));
            $excerpt = trim((string)($ev['excerpt'] ?? ''));
            $url = trim((string)($ev['url'] ?? ''));
            $image = trim((string)($ev['image'] ?? ''));
            $time = trim((string)($ev['time'] ?? ''));

            // 处理 content 字段：可能是数组、JSON字符串或文本
            $raw_content = $ev['content'] ?? '';
            $content_items = []; // 用于按顺序保存待处理的 ID (int) 或外链文章对象 (array)
            $content_text = '';

            if (is_string($raw_content)) {
                $trimmed = trim($raw_content);
                if (strpos($trimmed, '[') === 0 || strpos($trimmed, '{') === 0) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $raw_content = $decoded;
                    }
                }
            }

            if (is_array($raw_content)) {
                foreach ($raw_content as $item) {
                    if (is_array($item)) {
                        // 外链文章对象
                        $content_items[] = $item;
                    } elseif (is_string($item) || is_numeric($item)) {
                        $parts = preg_split('/[\s,]+/', trim((string)$item));
                        foreach ($parts as $p) {
                            if (is_numeric($p) && intval($p) > 0) {
                                $content_items[] = intval($p);
                            }
                        }
                    }
                }
            }

            // 收集所有需要查询的Post ID
            $post_ids = [];
            foreach ($content_items as $ci) {
                if (is_int($ci) && $ci > 0) {
                    $post_ids[] = $ci;
                }
            }
            $post_ids = array_values(array_unique($post_ids));

            $db_map = [];
            if (!empty($post_ids) && isset($tododb)) {
                $in = implode(',', array_fill(0, count($post_ids), '?'));
                $sql = "SELECT * FROM {$tododb->prefix}posts WHERE post_status = 'publish' AND ID IN ({$in})";
                $rows = $tododb->get_results($sql, $post_ids);

                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $pid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0);
                        if ($pid > 0) {
                            $db_map[$pid] = self::format_post_item($r);
                        }
                    }
                }
            }

            $articles = [];
            foreach ($content_items as $ci) {
                if (is_int($ci)) {
                    if (isset($db_map[$ci])) {
                        $articles[] = $db_map[$ci];
                    }
                } elseif (is_array($ci)) {
                    // 自定义外链文章对象
                    $ext_title   = trim((string)($ci['title'] ?? ''));
                    $ext_url     = trim((string)($ci['url'] ?? ''));
                    $ext_image   = trim((string)($ci['image'] ?? ''));
                    $ext_time    = trim((string)($ci['time'] ?? ''));

                    $ext_item = [
                        'title'          => $ext_title,
                        'url'            => $ext_url,
                        'post_thumbnail' => $ext_image,
                        'post_date'      => $ext_time,
                    ];
                    $articles[] = $ext_item;
                }
            }

            // 提取分类 Tab 识别值
            $year = $date !== '' ? $date : 'All';

            $items[] = [
                'index' => $idx + 1,
                'event_date' => $date,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content_text,
                'articles' => $articles,
                'url' => $url,
                'image' => $image,
                'time' => $time,
            ];
        }
        return $items;
    }

    private static function loop_related_jobs($data, $parsed_args) {
        $current_id = 0; global $post;
        if (isset($data['post_id'])) $current_id = intval($data['post_id']);
        elseif (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) $current_id = intval($data['post']->ID);
        elseif ($post && isset($post->ID)) $current_id = intval($post->ID);
        if ($current_id <= 0) return [];
        $limit = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : 6;
        $cat_id = 0;
        if (function_exists('todo_get_the_category')) { $cats = todo_get_the_category($current_id); if (is_array($cats) && !empty($cats)) $cat_id = isset($cats[0]->term_id) ? intval($cats[0]->term_id) : 0; }
        global $tododb; if (!isset($tododb)) return [];
        $posts = $tododb->prefix . 'posts';
        $site_filter = '';
        $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
        if ($current_site_id > 0 && $has_sp) {
            $site_posts_table = $tododb->prefix . 'site_posts';
            $site_filter = " AND (EXISTS (SELECT 1 FROM {$site_posts_table} sp WHERE sp.post_id = p.ID AND sp.site_id = {$current_site_id} AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_posts_table} spx WHERE spx.post_id = p.ID))";
        }
        $lang_filter = '';
        $lang_params = [];
        if (!defined('IS_ADMIN') || !IS_ADMIN) {
            $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : 'zh-CN'));
            $current_lang = str_replace('_', '-', $current_lang);
            $default_lang = function_exists('get_default_language') ? get_default_language() : (function_exists('todo_get_default_language') ? todo_get_default_language() : 'zh-CN');
            $default_lang = str_replace('_', '-', $default_lang);
            if ($current_lang !== $default_lang) {
                $pm_table = $tododb->prefix . 'postmeta';
                $lang_filter = " AND EXISTS (SELECT 1 FROM {$pm_table} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_enabled_languages' AND JSON_CONTAINS(pm.meta_value, ?))";
                $lang_params[] = '"' . $current_lang . '"';
            }
        }
        if ($cat_id > 0) { $rows = $tododb->get_results("SELECT p.ID, p.post_title FROM {$posts} p JOIN {$tododb->prefix}term_relationships tr ON tr.object_id = p.ID JOIN {$tododb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE p.post_status = 'publish' AND p.ID <> ? AND tt.taxonomy='category' AND tt.term_id = ? AND p.post_type = 'recruit' {$site_filter} {$lang_filter} ORDER BY p.post_date DESC LIMIT ?", array_merge([$current_id, $cat_id], $lang_params, [$limit])); }
        else { $rows = $tododb->get_results("SELECT p.ID, p.post_title FROM {$posts} p WHERE p.post_status = 'publish' AND p.ID <> ? AND p.post_type = 'recruit' {$site_filter} {$lang_filter} ORDER BY p.post_date DESC LIMIT ?", array_merge([$current_id], $lang_params, [$limit])); }
        if (!is_array($rows) || empty($rows)) return [];
        $ids = []; foreach ($rows as $r) { $ids[] = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0); } $ids = array_values(array_unique(array_filter($ids, function($v){return $v>0;})));
        if (empty($ids)) return [];
        $salary_map = []; $loc_map = [];
        $table = $tododb->prefix . 'model_fields_recruit';
        $exists = function_exists('table_exists') ? table_exists($table) : (preg_match('/^[a-zA-Z0-9_]+$/', $table) ? (bool)$tododb->get_var("SHOW TABLES LIKE '{$table}'") : false);
        if ($exists) {
            $in = implode(',', array_fill(0, count($ids), '?')); $frows = $tododb->get_results("SELECT post_id, salary, work_location, job_salary, job_location FROM `{$table}` WHERE post_id IN ({$in})", $ids);
            if (is_array($frows)) foreach ($frows as $fr) {
                $pid = is_object($fr) ? intval($fr->post_id) : intval($fr['post_id'] ?? 0); if ($pid <= 0) continue;
                $s = is_object($fr) ? (string)($fr->salary ?? '') : (string)($fr['salary'] ?? ''); if ($s === '') $s = is_object($fr) ? (string)($fr->job_salary ?? '') : (string)($fr['job_salary'] ?? '');
                $l = is_object($fr) ? (string)($fr->work_location ?? '') : (string)($fr['work_location'] ?? ''); if ($l === '') $l = is_object($fr) ? (string)($fr->job_location ?? '') : (string)($fr['job_location'] ?? '');
                $salary_map[$pid] = $s; $loc_map[$pid] = $l;
            }
        }
        $items = [];
        foreach ($rows as $r) { $pid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0); if ($pid <= 0) continue; $items[] = ['id' => $pid, 'title' => is_object($r) ? (string)$r->post_title : (string)($r['post_title'] ?? ''), 'url' => function_exists('todo_get_permalink') ? todo_get_permalink($pid) : '', 'salary' => $salary_map[$pid] ?? '', 'location' => $loc_map[$pid] ?? '']; }
        return $items;
    }

    /**
     * 相关文章循环：根据当前文章绑定的 tag，查找共享 tag 的其他文章，
     * 按共享 tag 数量降序排列（共享越多越靠前），相同共享数再按发布日期降序。
     *
     * 业务逻辑示例：
     *   当前文章 A 绑定了 tag 01、02、03
     *   文章 B 绑定了 01、02        → 共享 2 个 tag
     *   文章 C 绑定了 02            → 共享 1 个 tag
     *   排序结果：B → C
     */
    private static function loop_related_posts_by_tags($data, $parsed_args) {
        global $post, $tododb;
        if (!isset($tododb)) return [];

        // 获取当前文章 ID
        $current_id = 0;
        if (isset($data['post_id'])) $current_id = intval($data['post_id']);
        elseif (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) $current_id = intval($data['post']->ID);
        elseif ($post && isset($post->ID)) $current_id = intval($post->ID);
        if ($current_id <= 0) return [];

        // 获取当前文章的 tag ID 列表
        $my_tag_rows = $tododb->get_results(
            "SELECT tag_id FROM {$tododb->prefix}post_tags WHERE post_id = ?",
            [$current_id]
        );
        if (!is_array($my_tag_rows) || empty($my_tag_rows)) return [];

        $my_tag_ids = [];
        foreach ($my_tag_rows as $r) {
            $tid = is_object($r) ? intval($r->tag_id) : intval($r['tag_id'] ?? 0);
            if ($tid > 0) $my_tag_ids[] = $tid;
        }
        $my_tag_ids = array_values(array_unique($my_tag_ids));
        if (empty($my_tag_ids)) return [];

        // 解析参数
        if (is_array($parsed_args) && !isset($parsed_args['limit']) && isset($parsed_args['per_page'])) {
            $parsed_args['limit'] = $parsed_args['per_page'];
        }
        $limit = isset($parsed_args['limit']) ? max(1, intval($parsed_args['limit'])) : 6;

        $posts_table = $tododb->prefix . 'posts';
        $pt_table    = $tododb->prefix . 'post_tags';

        $site_filter = '';
        $current_site_id = function_exists('todo_current_site_id') ? (int) todo_current_site_id(true) : 0;
        $has_sp = function_exists('todo_site_posts_has_bindings') ? todo_site_posts_has_bindings() : (function_exists('todo_site_posts_table_exists') && todo_site_posts_table_exists());
        if ($current_site_id > 0 && $has_sp) {
            $site_posts_table = $tododb->prefix . 'site_posts';
            $site_filter = " AND (EXISTS (SELECT 1 FROM {$site_posts_table} sp WHERE sp.post_id = p.ID AND sp.site_id = {$current_site_id} AND sp.is_visible = 1) OR NOT EXISTS (SELECT 1 FROM {$site_posts_table} spx WHERE spx.post_id = p.ID))";
        }

        $lang_filter = '';
        $lang_params = [];
        if (!defined('IS_ADMIN') || !IS_ADMIN) {
            $current_lang = function_exists('current_locale') ? current_locale() : (function_exists('todo_get_current_language') ? todo_get_current_language() : (function_exists('get_current_language') ? get_current_language() : 'zh-CN'));
            $current_lang = str_replace('_', '-', $current_lang);
            $default_lang = function_exists('get_default_language') ? get_default_language() : (function_exists('todo_get_default_language') ? todo_get_default_language() : 'zh-CN');
            $default_lang = str_replace('_', '-', $default_lang);
            if ($current_lang !== $default_lang) {
                $pm_table = $tododb->prefix . 'postmeta';
                $lang_filter = " AND EXISTS (SELECT 1 FROM {$pm_table} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_enabled_languages' AND JSON_CONTAINS(pm.meta_value, ?))";
                $lang_params[] = '"' . $current_lang . '"';
            }
        }

        $in_placeholders = implode(',', array_fill(0, count($my_tag_ids), '?'));

        // 查找共享了当前文章 tag 的其他文章，计算共享 tag 数量，按共享数量降序、日期降序排列
        $sql = "SELECT p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_date,
                       p.featured_image,
                       COUNT(pt.tag_id) AS shared_count
                FROM {$posts_table} p
                INNER JOIN {$pt_table} pt ON pt.post_id = p.ID
                WHERE p.post_status = 'publish'
                  AND p.ID <> ?
                  AND pt.tag_id IN ({$in_placeholders})
                  {$site_filter}
                  {$lang_filter}
                GROUP BY p.ID
                ORDER BY shared_count DESC, p.post_date DESC
                LIMIT ?";

        $params = array_merge([$current_id], $my_tag_ids, $lang_params, [$limit]);
        $rows = $tododb->get_results($sql, $params);

        if (!is_array($rows) || empty($rows)) return [];

        // 批量匹配多语言版本 (包含标题、摘要及正文)
        $post_ids = [];
        foreach ($rows as $r) {
            $pid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0);
            if ($pid > 0) $post_ids[] = $pid;
        }

        $language_code = (function_exists('get_current_language') ? get_current_language() : (function_exists('todo_get_current_language') ? todo_get_current_language() : null));
        $apply_i18n = (!defined('IS_ADMIN') || !IS_ADMIN);
        if ($apply_i18n && $language_code && !empty($post_ids) && function_exists('todo_get_posts_multilang_batch')) {
            if (strpos($language_code, '_') !== false) {
                $language_code = str_replace('_', '-', $language_code);
            }
            $translations = todo_get_posts_multilang_batch($post_ids, $language_code, [
                'include_seo' => true,
                'fallback' => true,
                'status_allowed' => ['approved', 'draft']
            ]);
            foreach ($rows as $idx => $r) {
                $pid = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0);
                if ($pid > 0 && isset($translations[$pid])) {
                    $agg = $translations[$pid];
                    if (is_object($r)) {
                        if (!empty($agg['title'])) $r->post_title = $agg['title'];
                        if (!empty($agg['content'])) $r->post_content = $agg['content'];
                        if (!empty($agg['excerpt'])) $r->post_excerpt = $agg['excerpt'];
                    } else {
                        if (!empty($agg['title'])) $r['post_title'] = $agg['title'];
                        if (!empty($agg['content'])) $r['post_content'] = $agg['content'];
                        if (!empty($agg['excerpt'])) $r['post_excerpt'] = $agg['excerpt'];
                    }
                }
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $pid            = is_object($r) ? intval($r->ID) : intval($r['ID'] ?? 0);
            $title          = is_object($r) ? (string)($r->post_title ?? '') : (string)($r['post_title'] ?? '');
            $excerpt        = is_object($r) ? (string)($r->post_excerpt ?? '') : (string)($r['post_excerpt'] ?? '');
            $content        = is_object($r) ? (string)($r->post_content ?? '') : (string)($r['post_content'] ?? '');
            $post_date      = is_object($r) ? (string)($r->post_date ?? '') : (string)($r['post_date'] ?? '');
            $raw_featured   = is_object($r) ? ($r->featured_image ?? '') : ($r['featured_image'] ?? '');
            $featured_image = self::resolve_featured_image_to_url($raw_featured);
            if ($pid <= 0) continue;

            $item_obj = new \stdClass();
            $item_obj->ID             = $pid;
            $item_obj->post_title     = $title;
            $item_obj->post_excerpt   = $excerpt;
            $item_obj->post_content   = $content;
            $item_obj->post_date      = $post_date;
            $item_obj->featured_image = $raw_featured;
            $item_obj->post_thumbnail = $featured_image;
            $items[] = $item_obj;
        }
        return $items;
    }

    /**
     * 统一解析 posts.featured_image 原始值为图片数组
     * 支持 JSON 数组/对象、序列化数据、直接 URL 字符串
     * @param mixed $raw featured_image 原始值（来自 DB 或 data context）
     * @return array 每一项: ['url' => '', 'title' => '', 'alt' => '']
     */
    public static function parse_featured_images($raw) {
        if (empty($raw)) return [];

        if (function_exists('todo_filter_featured_images_by_lang')) {
            $raw = todo_filter_featured_images_by_lang($raw);
            if (empty($raw)) return [];
        }

        $list = [];

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '' || $trim === '[]') return [];

            // 直接 URL
            if (strpos($trim, 'http') === 0 || strpos($trim, '/') === 0) {
                $list = [['url' => $trim]];
            }
            // JSON 格式: [{"url":"...","title":"..."}] 或 {"url":"..."}
            elseif ($trim[0] === '{' || $trim[0] === '[') {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    if (isset($decoded['url'])) $list = [$decoded];
                    elseif (isset($decoded[0])) $list = $decoded;
                }
            }
            // 序列化格式
            elseif (strpos($trim, 'a:') === 0) {
                $unserialized = function_exists('maybe_unserialize') ? maybe_unserialize($trim) : @unserialize($trim);
                if (is_array($unserialized)) $list = $unserialized;
            }
            // 逗号/换行分隔的多 URL
            elseif (strpos($trim, ',') !== false || strpos($trim, "\n") !== false) {
                $parts = preg_split('/[\r\n,]+/', $trim);
                $list = array_map(function($u) { return ['url' => trim($u)]; }, array_filter(array_map('trim', $parts)));
                $list = array_values($list);
            }
            else {
                $list = [['url' => $trim]];
            }
        } elseif (is_array($raw)) {
            $list = $raw;
        }

        if (empty($list)) return [];

        $items = [];
        foreach ($list as $it) {
            if (is_string($it)) {
                $u = trim($it);
                if ($u === '') continue;
                $it = ['url' => $u];
            }
            if (!is_array($it)) continue;

            $url = isset($it['url']) ? (string)$it['url'] : '';
            if ($url === '') continue;

            $rel_url = function_exists('todo_media_url_to_relative') ? todo_media_url_to_relative($url) : $url;
            $title   = isset($it['title']) ? (string)$it['title'] : '';
            $alt     = isset($it['alt']) ? (string)$it['alt'] : ($title !== '' ? $title : pathinfo(parse_url($rel_url, PHP_URL_PATH) ?: '', PATHINFO_FILENAME));

            // 根据 URL 扩展名判断类型
            $url_path   = parse_url($rel_url, PHP_URL_PATH) ?: '';
            $ext        = strtolower(pathinfo($url_path, PATHINFO_EXTENSION));

            $video_exts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', 'ogv'];
            $audio_exts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
            $html_exts  = ['html', 'htm'];
            $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif', 'tiff'];

            if (in_array($ext, $video_exts, true)) {
                $type = 'video';
            } elseif (in_array($ext, $audio_exts, true)) {
                $type = 'audio';
            } elseif (in_array($ext, $html_exts, true)) {
                $type = 'html';
            } elseif (in_array($ext, $image_exts, true)) {
                $type = 'image';
            } else {
                $type = $ext !== '' ? 'file' : 'image';
            }

            $placeholder   = '';
            $use_thumbhash = false;

            if ($type === 'image' && $rel_url !== '') {
                $info = class_exists('CMS_HTML_Template_Tags') ? CMS_HTML_Template_Tags::get_media_info($rel_url) : null;
                if ($info && !empty($info->thumbhash) && isset($info->media_size) && (int)$info->media_size > 1048576) {
                    if (class_exists('ThumbHash')) {
                        $placeholder   = ThumbHash::toDataURL($info->thumbhash);
                        $use_thumbhash = true;
                    }
                }
            }

            $display_url = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($rel_url) : $rel_url;
            $thumb = isset($it['thumb']) ? (string)$it['thumb'] : $rel_url;
            $display_thumb = function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($thumb) : $thumb;

            $items[] = [
                'url'           => $display_url,
                'thumb'         => $display_thumb,
                'title'         => $title,
                'alt'           => $alt,
                'type'          => $type,
                'ext'           => $ext,
                'placeholder'   => $placeholder,
                'use_thumbhash' => $use_thumbhash,
            ];
        }
        return $items;
    }

    /**
     * 从上下文数据中获取 featured_image 原始值
     */
    private static function get_featured_image_raw($data, $parsed_args = []) {
        $override_id = isset($parsed_args['id']) ? intval($parsed_args['id']) : (isset($parsed_args['post_id']) ? intval($parsed_args['post_id']) : 0);
        if ($override_id > 0) {
            global $tododb;
            if (isset($tododb)) {
                $row = $tododb->get_row("SELECT featured_image FROM " . TABLE_PREFIX . "posts WHERE ID = ? LIMIT 1", [$override_id], 'ARRAY_A');
                if ($row && !empty($row['featured_image'])) {
                    return $row['featured_image'];
                }
            }
        }

        // 1. 优先从 data / post 中取特色图/多图字段
        $fi_val = $data['featured_image'] ?? ($data['images'] ?? ($data['featured_images'] ?? ($data['cat_image'] ?? ($data['category_image'] ?? ''))));
        if (!empty($fi_val)) {
            return $fi_val;
        }
        if (isset($data['post']) && is_object($data['post'])) {
            $post_fi = $data['post']->featured_image ?? ($data['post']->images ?? ($data['post']->featured_images ?? ''));
            if (!empty($post_fi)) return $post_fi;
        }
        if (isset($data['category'])) {
            $cat_obj = $data['category'];
            $cat_fi = is_array($cat_obj) ? ($cat_obj['category_image'] ?? ($cat_obj['cat_image'] ?? ($cat_obj['featured_image'] ?? '')))
                                       : (is_object($cat_obj) ? ($cat_obj->category_image ?? ($cat_obj->cat_image ?? ($cat_obj->featured_image ?? ''))) : '');
            if (!empty($cat_fi)) return $cat_fi;
        }

        // 2. 回退到文章数据库查询（详情页上下文）
        $post_id = isset($data['post_id']) ? intval($data['post_id']) : (isset($data['ID']) ? intval($data['ID']) : (isset($data['id']) ? intval($data['id']) : 0));
        if ($post_id <= 0) {
            if (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) $post_id = intval($data['post']->ID);
        }
        if ($post_id <= 0) { global $post; if ($post && isset($post->ID)) $post_id = intval($post->ID); }
        if ($post_id > 0) {
            global $tododb;
            if (isset($tododb)) {
                $row = $tododb->get_row("SELECT featured_image FROM " . TABLE_PREFIX . "posts WHERE ID = ? LIMIT 1", [$post_id], 'ARRAY_A');
                if ($row && !empty($row['featured_image'])) {
                    return $row['featured_image'];
                }
            }
        }

        // 3. 回退到分类/栏目数据库查询（列表页分类上下文）
        $cat_id = 0;
        $cid_val = $data['category_id'] ?? ($data['cat_id'] ?? ($data['term_id'] ?? ($data['cat'] ?? '')));
        if (!empty($cid_val) && is_numeric($cid_val)) { $cat_id = intval($cid_val); }
        if ($cat_id <= 0) {
            global $cat, $current_category_id;
            if (!empty($cat) && is_numeric($cat)) $cat_id = intval($cat);
            elseif (!empty($current_category_id) && is_numeric($current_category_id)) $cat_id = intval($current_category_id);
        }
        if ($cat_id > 0) {
            if (function_exists('get_term_meta')) {
                $meta_img = get_term_meta($cat_id, 'cat_image', true);
                if (!empty($meta_img)) return $meta_img;
                $meta_cat_img = get_term_meta($cat_id, 'category_image', true);
                if (!empty($meta_cat_img)) return $meta_cat_img;
            }
            global $tododb;
            if (isset($tododb)) {
                $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : (isset($tododb->prefix) ? $tododb->prefix : 'todo_');
                $table = $prefix . 'term_attributes';
                if ($tododb->table_exists($table)) {
                    $row = $tododb->get_row("SELECT category_image FROM {$table} WHERE term_id = ? LIMIT 1", [$cat_id], 'ARRAY_A');
                    if ($row && !empty($row['category_image'])) {
                        return $row['category_image'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * 从上下文获取单张图片 URL（取第一张）
     */
    private static function resolve_featured_image_url($data) {
        $raw = self::get_featured_image_raw($data);
        $items = self::parse_featured_images($raw);
        return !empty($items) ? $items[0]['url'] : '';
    }

    /**
     * 从上下文获取多图循环数组
     * 与小程序 buildHeroSlides 逻辑一致：有视频则排在最前，然后才是图像序列。
     * 每项携带 type 字段（'video' | 'image'）。
     */
    private static function resolve_featured_images_loop($data, $parsed_args) {
        // --- 1. 图像序列 ---
        $raw = self::get_featured_image_raw($data, $parsed_args);
        $image_items = self::parse_featured_images($raw);
        // 确保每张图片携带 type=image
        foreach ($image_items as &$img) {
            if (!isset($img['type'])) $img['type'] = 'image';
        }
        unset($img);

        // --- 2. 产品视频（来自 model_fields_product.product_video）---
        $video_raw = '';
        // 优先从已合并进 $data 的 product_video 字段取
        if (!empty($data['product_video'])) {
            $video_raw = $data['product_video'];
        }
        // 回退：从 model_fields_product 表读取
        if (empty($video_raw)) {
            $post_id = isset($data['post_id']) ? intval($data['post_id']) : (isset($data['ID']) ? intval($data['ID']) : (isset($data['id']) ? intval($data['id']) : 0));
            if ($post_id <= 0 && isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) {
                $post_id = intval($data['post']->ID);
            }
            if ($post_id <= 0) { global $post; if ($post && isset($post->ID)) $post_id = intval($post->ID); }
            if ($post_id > 0) {
                global $tododb;
                if (isset($tododb)) {
                    $table = $tododb->prefix . 'model_fields_product';
                    $exists = function_exists('table_exists')
                        ? table_exists($table)
                        : (preg_match('/^[a-zA-Z0-9_]+$/', $table)
                            ? (bool) $tododb->get_var("SHOW TABLES LIKE '{$table}'")
                            : false);
                    if ($exists) {
                        $row = $tododb->get_row(
                            "SELECT product_video FROM `{$table}` WHERE post_id = ? LIMIT 1",
                            [$post_id],
                            'ARRAY_A'
                        );
                        if ($row && !empty($row['product_video'])) {
                            $video_raw = $row['product_video'];
                        }
                    }
                }
            }
        }

        $actual_video_url = '';
        if (!empty($video_raw)) {
            $video_parsed = self::parse_featured_images($video_raw);
            if (!empty($video_parsed)) {
                $actual_video_url = $video_parsed[0]['url'];
            }
        }

        // --- 3. 合并：视频（若有）在前，图像序列在后 ---
        $items = [];
        if ($actual_video_url !== '') {
            $items[] = ['url' => $actual_video_url, 'type' => 'video', 'title' => '', 'alt' => ''];
        }
        foreach ($image_items as $img) {
            $items[] = $img;
        }

        $limit = isset($parsed_args['limit']) ? intval($parsed_args['limit']) : 0;
        if ($limit > 0 && count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }
        return $items;
    }

    /**
     * 将 featured_image 原始值解析为第一张图片 URL（兼容旧的直接传 raw 值的调用点）
     */
    private static function resolve_featured_image_to_url($raw) {
        if (!empty($raw) && function_exists('todo_match_featured_image_by_lang')) {
            $matched_url = todo_match_featured_image_by_lang($raw);
            if (!empty($matched_url)) {
                return $matched_url;
            }
        }
        $items = self::parse_featured_images($raw);
        return !empty($items) ? $items[0]['url'] : '';
    }

    /**
     * 将模型字段中的图片类原始值规范成单个 URL。
     * 仅对明确的图片字段名生效，避免误处理职位要求/福利等 JSON 文本字段。
     */
    private static function normalize_model_media_field_value($field_name, $raw) {
        $field_name = is_string($field_name) ? strtolower($field_name) : '';
        $media_fields = [
            'company_logo',
            'logo',
            'image',
            'images',
            'thumb',
            'thumbnail',
            'featured_image',
            'category_image',
        ];

        if (!in_array($field_name, $media_fields, true)) {
            return $raw;
        }

        if (is_string($raw)) {
            $resolved = self::resolve_featured_image_to_url($raw);
            if ($resolved !== '') {
                return function_exists('todo_media_url_to_runtime_absolute') ? todo_media_url_to_runtime_absolute($resolved) : $resolved;
            }
        }

        return $raw;
    }

    private static function loop_tags($data, $parsed_args) {
        $post_id = self::get_post_id_from_context($data);

        // 如果包含 limit, per_page, order, orderby 等列表/标签云参数，说明这是全局标签云，不能因为在文章页上下文中就误判为“获取当前文章的标签”
        $has_global_args = !empty($parsed_args['limit']) || !empty($parsed_args['per_page']) || !empty($parsed_args['order']) || !empty($parsed_args['orderby']) || !empty($parsed_args['hide_empty']) || !empty($parsed_args['status']);
        $is_post_tags_query = ($post_id > 0 && !$has_global_args);

        if ($is_post_tags_query) {
            $tags = function_exists('todo_get_the_tags') ? todo_get_the_tags($post_id) : (function_exists('todo_get_post_tags') ? todo_get_post_tags($post_id) : []);
            if (!empty($tags)) {
                $formatted = [];
                foreach ($tags as $tag) {
                    $tag_id = is_object($tag) ? intval($tag->term_id ?? ($tag->tag_id ?? $tag->id ?? 0)) : intval($tag['term_id'] ?? ($tag['tag_id'] ?? $tag['id'] ?? 0));
                    $tag_name = is_object($tag) ? (string)($tag->name ?? ($tag->tag_title ?? '')) : (string)($tag['name'] ?? ($tag['tag_title'] ?? ''));
                    if (function_exists('todo_parse_inline_translations')) {
                        $tag_name = todo_parse_inline_translations($tag_name);
                    }
                    if (function_exists('todo_translate')) {
                        $tag_name = todo_translate($tag_name);
                    }
                    $tag_url = $tag_id > 0 && function_exists('todo_get_tag_link') ? todo_get_tag_link($tag_id) : '';
                    $count = is_object($tag) ? intval($tag->count ?? ($tag->post_count ?? ($tag->articles_count ?? 0))) : intval($tag['count'] ?? ($tag['post_count'] ?? ($tag['articles_count'] ?? 0)));
                    $desc = is_object($tag) ? (string)($tag->description ?? ($tag->tag_desc ?? '')) : (string)($tag['description'] ?? ($tag['tag_desc'] ?? ''));
                    if (function_exists('todo_parse_inline_translations')) {
                        $desc = todo_parse_inline_translations($desc);
                    }
                    if (function_exists('todo_translate')) {
                        $desc = todo_translate($desc);
                    }
                    $color = is_object($tag) ? (string)($tag->tag_color ?? ($tag->color ?? '')) : (string)($tag['tag_color'] ?? ($tag['color'] ?? ''));
                    $formatted[] = [
                        'tag_id'         => $tag_id,
                        'id'             => $tag_id,
                        'tag_name'       => $tag_name,
                        'name'           => $tag_name,
                        'tag_title'      => $tag_name,
                        'tag_url'        => $tag_url,
                        'url'            => $tag_url,
                        'description'    => $desc,
                        'tag_desc'       => $desc,
                        'tag_color'      => $color,
                        'color'          => $color,
                        'count'          => $count,
                        'articles_count' => $count,
                        'post_count'     => $count,
                    ];
                }
                return $formatted;
            }
            return [];
        }

        // 参数适配
        if (!isset($parsed_args['limit']) && isset($parsed_args['per_page'])) {
            $parsed_args['limit'] = intval($parsed_args['per_page']);
        }
        if (isset($parsed_args['order']) && !isset($parsed_args['orderby'])) {
            $parsed_args['orderby'] = $parsed_args['order'];
        }
        if (isset($parsed_args['orderby'])) {
            if ($parsed_args['orderby'] === 'articles_count' || $parsed_args['orderby'] === 'articles_count desc' || $parsed_args['orderby'] === 'count') {
                $parsed_args['orderby'] = 'post_count';
                if (!isset($parsed_args['order'])) {
                    $parsed_args['order'] = 'DESC';
                }
            }
        }

        $tags = [];
        if (class_exists('CMS_Tags_Manager')) {
            $tm = new CMS_Tags_Manager();
            $tags = $tm->get_tags($parsed_args);
        } elseif (function_exists('todo_get_tags')) {
            $tags = todo_get_tags($parsed_args);
        } elseif (function_exists('get_tags')) {
            $tags = get_tags($parsed_args);
        } else {
            try {
                global $tododb;
                if ($tododb) {
                    $tt = $tododb->prefix . 'term_taxonomy';
                    $t = $tododb->prefix . 'terms';
                    $sql = "SELECT t.term_id, t.name, t.slug, tt.description, tt.count FROM {$t} t JOIN {$tt} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'post_tag' ORDER BY t.name ASC";
                    $tags = $tododb->get_results($sql);
                }
            } catch (Throwable $e) {}
        }

        if (is_array($tags)) {
            $formatted = [];
            foreach ($tags as $tag) {
                $tag_id = is_object($tag) ? intval($tag->term_id ?? ($tag->tag_id ?? $tag->id ?? 0)) : intval($tag['term_id'] ?? ($tag['tag_id'] ?? $tag['id'] ?? 0));
                $tag_name = is_object($tag) ? (string)($tag->name ?? ($tag->tag_title ?? '')) : (string)($tag['name'] ?? ($tag['tag_title'] ?? ''));
                if (function_exists('todo_parse_inline_translations')) {
                    $tag_name = todo_parse_inline_translations($tag_name);
                }
                if (function_exists('todo_translate')) {
                    $tag_name = todo_translate($tag_name);
                }
                $tag_url = $tag_id > 0 && function_exists('todo_get_tag_link') ? todo_get_tag_link($tag_id) : '';
                $count = is_object($tag) ? intval($tag->count ?? ($tag->post_count ?? ($tag->articles_count ?? 0))) : intval($tag['count'] ?? ($tag['post_count'] ?? ($tag['articles_count'] ?? 0)));
                $desc = is_object($tag) ? (string)($tag->description ?? ($tag->tag_desc ?? '')) : (string)($tag['description'] ?? ($tag['tag_desc'] ?? ''));
                if (function_exists('todo_parse_inline_translations')) {
                    $desc = todo_parse_inline_translations($desc);
                }
                if (function_exists('todo_translate')) {
                    $desc = todo_translate($desc);
                }
                $color = is_object($tag) ? (string)($tag->tag_color ?? ($tag->color ?? '')) : (string)($tag['tag_color'] ?? ($tag['color'] ?? ''));
                $formatted[] = [
                    'tag_id'         => $tag_id,
                    'id'             => $tag_id,
                    'tag_name'       => $tag_name,
                    'name'           => $tag_name,
                    'tag_title'      => $tag_name,
                    'tag_url'        => $tag_url,
                    'url'            => $tag_url,
                    'description'    => $desc,
                    'tag_desc'       => $desc,
                    'tag_color'      => $color,
                    'color'          => $color,
                    'count'          => $count,
                    'articles_count' => $count,
                    'post_count'     => $count,
                ];
            }
            return $formatted;
        }
        return [];
    }

    /**
     * 解析函数参数
     */
    public static function parse_function_params($params) {
        $params = trim($params, '()'); if ($params === '') return [];
        if (preg_match('/\w+\s*=\s*/', $params)) {
            $assoc = [];
            if (preg_match_all('/(\w+)\s*=\s*(?:(["\'])(.*?)\2|([^,|\s]+))/u', $params, $m, PREG_SET_ORDER)) {
                foreach ($m as $mm) { $key = $mm[1]; $val = isset($mm[3]) && $mm[3] !== '' ? $mm[3] : trim($mm[4]); if (is_numeric($val)) $assoc[$key] = ($val + 0); else { $lv = strtolower($val); if ($lv === 'true') $assoc[$key] = true; elseif ($lv === 'false') $assoc[$key] = false; else $assoc[$key] = $val; } }
            }
            return $assoc;
        }
        if (preg_match('/^\s*\{.*\}\s*$/', $params)) { $json_params = preg_replace('/\'([^\']*)\':/', '"$1":', $params); $json_params = preg_replace('/:\s*\'([^\']*)\'/', ': "$1"', $json_params); $decoded = json_decode($json_params, true); if ($decoded !== null) return [$decoded]; return []; }
        $args = [];
        if (preg_match_all('/["\']([^"\']*)["\']/', $params, $matches)) $args = $matches[1];
        return $args;
    }

    /**
     * 从上下文中获取文章 ID
     */
    private static function get_post_id_from_context($data) {
        if (is_array($data)) {
            $pid_val = $data['post_id'] ?? ($data['ID'] ?? ($data['id'] ?? null));
            if ($pid_val !== null) return intval($pid_val);
            if (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) return intval($data['post']->ID);
            if (isset($data['post']) && is_array($data['post']) && isset($data['post']['ID'])) return intval($data['post']['ID']);
            if (isset($data['item']) && is_object($data['item']) && isset($data['item']->ID)) return intval($data['item']->ID);
            if (isset($data['item']) && is_array($data['item']) && isset($data['item']['ID'])) return intval($data['item']['ID']);
        }
        // 使用 todocms 约定的 $GLOBALS['todo_post']
        $todo_post = isset($GLOBALS['todo_post']) && is_object($GLOBALS['todo_post']) ? $GLOBALS['todo_post'] : null;
        if ($todo_post && isset($todo_post->ID)) return intval($todo_post->ID);
        return 0;
    }

    /**
     * 获取模板变量值
     */
    private static function get_todo_variable($variable_name, $data) {
        if ($variable_name === 'post.content') { error_log('TodoCMS Deprecated: {{todo:post.content}} is no longer supported. Use {{todo:post_content}}.'); return ''; }
        
        $parsed_pipe_args = [];
        if (strpos($variable_name, '|') !== false) {
            list($raw_var, $pipe_params) = explode('|', $variable_name, 2);
            $raw_var = trim($raw_var);
            $pipe_params = trim($pipe_params);
            
            $suffix_part = '';
            if (preg_match('/^(.*?)(?:\.|\s+)(length|count|size|total|images_count|images_length|videos_count|videos_length)$/i', $pipe_params, $pm)) {
                $pipe_params = trim($pm[1]);
                $suffix_part = trim($pm[2]);
            }
            
            try {
                $parsed_pipe_args = self::parse_function_params('(' . $pipe_params . ')');
            } catch (Exception $e) {
                $parsed_pipe_args = [];
            }
            
            $variable_name = $raw_var . ($suffix_part !== '' ? '.' . $suffix_part : '');
        }

        $parts = explode('.', $variable_name);
        $value = $data;
        $column_aliases = [
            'likes' => 'like_count',
            'views' => 'view_count',
        ];

        foreach ($parts as $idx => $part) {
            if ($idx === 0 && ((is_array($value) && !array_key_exists($part, $value)) || (is_object($value) && !isset($value->$part)))) {
                if (in_array($part, ['featured_images', 'post_images', 'anywhere_post_images', 'anywhere_post', 'products_images', 'gallery_images', 'show_ads', 'posts', 'tags', 'post_tags', 'search_results', 'special_featured_posts', 'special_categories', 'special_tags', 'related_content'], true)) {
                    $loop_items = self::get_loop_data($part, is_array($data) ? $data : [], $parsed_pipe_args);
                    if (is_array($loop_items)) {
                        $value = $loop_items;
                        if (count($parts) > 1) {
                            continue;
                        }
                    }
                }
            }

            if (is_array($value)) {
                $alias = $column_aliases[$part] ?? null;
                if (array_key_exists($part, $value)) {
                    $value = $value[$part];
                } elseif ($alias && array_key_exists($alias, $value)) {
                    $value = $value[$alias];
                } elseif (count($parts) === 1 && isset($data['post'])) {
                    $postData = $data['post'];
                    $col = $alias ?? $part;
                    if (is_object($postData) && isset($postData->$col)) {
                        $value = $postData->$col;
                    } elseif (is_array($postData) && isset($postData[$col])) {
                        $value = $postData[$col];
                    } else {
                        return self::get_global_or_tag_fallback($variable_name, $data);
                    }
                } elseif (in_array($part, ['length', 'count', 'size', 'total'], true)) {
                    $value = count($value);
                } elseif ($part === 'images_count' || $part === 'images_length') {
                    $cnt = 0;
                    foreach ($value as $item) {
                        if (is_array($item) && isset($item['type']) && $item['type'] === 'image') $cnt++;
                    }
                    $value = $cnt;
                } elseif ($part === 'videos_count' || $part === 'videos_length') {
                    $cnt = 0;
                    foreach ($value as $item) {
                        if (is_array($item) && isset($item['type']) && $item['type'] === 'video') $cnt++;
                    }
                    $value = $cnt;
                } else {
                    return self::get_global_or_tag_fallback($variable_name, $data);
                }
            } elseif (is_object($value)) {
                $alias = $column_aliases[$part] ?? null;
                if (isset($value->$part)) {
                    $value = $value->$part;
                } elseif ($alias && isset($value->$alias)) {
                    $value = $value->$alias;
                } else {
                    return self::get_global_or_tag_fallback($variable_name, $data);
                }
            } else {
                return self::get_global_or_tag_fallback($variable_name, $data);
            }
        }

        if (is_object($value)) return method_exists($value, '__toString') ? (string)$value : '';
        if (is_array($value)) return implode('', $value);
        return (string) $value;
    }

    private static function get_global_or_tag_fallback($variable_name, $data) {
        $tagVal = self::call_taglib_tag($variable_name, '', is_array($data) ? $data : []);
        if ($tagVal !== null && $tagVal !== '') {
            return $tagVal;
        }
        return self::get_global_variable($variable_name);
    }

    /**
     * 将 HTML 内容中的相对路径 URL（src、href）转为绝对 URL
     * 支持已实体编码的内容（如 &quot; &lt; 等）
     */
    private static function make_content_urls_absolute($content) {
        if ($content === '') return $content;
        $has_entities = (strpos($content, '&') !== false);
        if (!$has_entities && strpos($content, 'src="/') === false && strpos($content, 'href="/') === false) {
            return $content;
        }
        $site_url = function_exists('todo_get_site_url') ? rtrim(todo_get_site_url(), '/') : '';
        if ($site_url === '') return $content;

        // 如果内容包含 HTML 实体编码，先解码
        if ($has_entities) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $content = preg_replace('/(src|href|poster)="\/([^"]*)"/', '$1="' . $site_url . '/$2"', $content);
        return $content;
    }

    /**
     * 获取全局变量值
     */
    private static function get_global_variable($variable_name) {
        global $post;
        $status_checks = [
            'user_logged_in' => 'is_user_logged_in', 'user_not_logged_in' => function() { return !is_user_logged_in(); },
            'is_home' => 'todo_is_home', 'is_single' => 'todo_is_single', 'is_page' => 'todo_is_page',
            'is_category' => 'todo_is_category', 'is_tag' => 'todo_is_tag', 'is_search' => 'todo_is_search', 'is_paged' => 'todo_is_paged', 'is_author' => 'todo_is_author',
            'is_archive' => 'todo_is_archive', 'is_404' => 'todo_is_404',
            'site_name' => function() { return todo_get_option('site_name'); },
            'site_description' => function() { return todo_get_option('site_description'); },
            'site_logo' => function() { return function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : todo_get_option('site_logo'); },
            'site_favicon' => function() { return function_exists('todo_get_site_favicon_url') ? todo_get_site_favicon_url() : todo_get_option('site_favicon'); },
            'page_title' => function() { return function_exists('get_full_page_title') ? get_full_page_title() : (function_exists('get_seo_title') ? get_seo_title() : get_option('site_title', '')); },
            'cart_count' => function() { if (class_exists('Todo_Cart_Manager') && is_callable(['Todo_Cart_Manager', 'get_cart'])) { $cart = Todo_Cart_Manager::get_cart(); return is_array($cart) && isset($cart['count']) ? (int)$cart['count'] : 0; } return 0; },
            'favorites_count' => function() { return function_exists('todo_get_favorites_count') ? todo_get_favorites_count() : 0; },
            'user_favorites_count' => function() { return function_exists('todo_get_favorites_count') ? todo_get_favorites_count() : 0; },
            'favorite_products_count' => function() { return function_exists('todo_get_favorites_count') ? todo_get_favorites_count(null, 'product') : 0; },
            'favorite_articles_count' => function() { return function_exists('todo_get_favorites_count') ? todo_get_favorites_count(null, 'post') : 0; },
            'og_type' => function() { return todo_is_single() ? 'article' : 'website'; },
            'og_url' => function() { return function_exists('todo_get_current_canonical') ? todo_get_current_canonical() : todo_get_current_url(); },
            'og_title' => function() { return function_exists('get_seo_title') ? (get_seo_title() ?: get_option('site_title', '')) : get_option('site_title', ''); },
            'og_description' => function() { return function_exists('get_seo_description') ? get_seo_description() : ''; },
            'og_image' => function() {
                if (todo_is_single()) {
                    global $post;
                    if ($post && function_exists('get_the_post_thumbnail_url')) {
                        $u = (string) get_the_post_thumbnail_url($post, 'full');
                        if ($u !== '') return $u;
                    }
                }
                $img = get_option('site_og_image', '');
                return $img ?: (function_exists('todo_get_site_logo_url') ? todo_get_site_logo_url() : get_option('site_logo', ''));
            },
        ];
        if (isset($status_checks[$variable_name])) {
            $check = $status_checks[$variable_name];
            $result = is_callable($check) ? call_user_func($check) : (is_string($check) && function_exists($check) ? call_user_func($check) : null);
            if (is_bool($result)) return $result ? '1' : '0';
            return is_scalar($result) || $result === null ? (string)$result : '';
        }
        if (strpos($variable_name, '.') !== false) {
            $parts = explode('.', $variable_name); $base = array_shift($parts);
            if (class_exists('CMS_HTML_Template_Tags')) {
                $handler = CMS_HTML_Template_Tags::get_instance();
                $base_method = 'parse_' . $base;
                if (method_exists($handler, $base_method)) {
                    $base_val = call_user_func([$handler, $base_method], [], '', []);
                    $current = $base_val;
                    foreach ($parts as $p) { if (is_array($current) && array_key_exists($p, $current)) $current = $current[$p]; elseif (is_object($current) && isset($current->$p)) $current = $current->$p; else return ''; }
                    if (is_array($current) || is_object($current)) return '';
                    return $current;
                }
            }
        }
        if (class_exists('CMS_HTML_Template_Tags')) {
            $handler = CMS_HTML_Template_Tags::get_instance();
            $method_name = 'parse_' . str_replace('.', '_', $variable_name);
            if (!preg_match('/^post\./', $variable_name) && method_exists($handler, $method_name)) {
                return call_user_func([$handler, $method_name], [], '', []);
            }
        }
        $cat_id_for_var = function_exists('get_current_category_id') ? get_current_category_id() : 0;
        if (!$cat_id_for_var && function_exists('todo_get_queried_object_id')) $cat_id_for_var = todo_get_queried_object_id();
        if (!$cat_id_for_var && isset($GLOBALS['cat_id'])) $cat_id_for_var = intval($GLOBALS['cat_id']);
        if (!$cat_id_for_var && isset($GLOBALS['category_id'])) $cat_id_for_var = intval($GLOBALS['category_id']);
        if (!$cat_id_for_var && isset($GLOBALS['current_category'])) {
            $cc = $GLOBALS['current_category'];
            $cat_id_for_var = is_object($cc) ? intval($cc->term_id ?? $cc->id ?? 0) : intval($cc['term_id'] ?? $cc['id'] ?? 0);
        }
        if ($cat_id_for_var > 0) {
            $term_attrs = function_exists('todo_get_term_attributes_multilang')
                ? todo_get_term_attributes_multilang($cat_id_for_var)
                : (function_exists('get_term_meta') ? get_term_meta($cat_id_for_var) : []);
            if (is_array($term_attrs)) {
                $clean_var_name = preg_replace('/^(cat_|category_)/', '', $variable_name);
                $term_meta_val = $term_attrs[$clean_var_name] ?? ($term_attrs[$variable_name] ?? null);
                if ($term_meta_val !== '' && $term_meta_val !== null && $term_meta_val !== false) {
                    if (is_string($term_meta_val) && function_exists('todo_parse_inline_translations')) {
                        $term_meta_val = todo_parse_inline_translations($term_meta_val);
                    }
                    if (is_scalar($term_meta_val)) {
                        return (string) $term_meta_val;
                    }
                }
            }
        }
        if ($post && isset($post->ID)) {
            $post_id = intval($post->ID); $model_key = isset($post->post_type) ? (string)$post->post_type : '';

            if (in_array($variable_name, ['views', 'content_views', 'real_views', 'views_boost', 'post_views', 'view_count', 'post_view_count'], true)) {
                $real_views = 0;
                if (function_exists('get_post_views')) {
                    $real_views = (int) get_post_views($post_id);
                } elseif (isset($post->view_count)) {
                    $real_views = (int) $post->view_count;
                }
                $views_boost = function_exists('get_post_meta') ? (int) get_post_meta($post_id, '_views_boost', true) : 0;

                if ($variable_name === 'real_views' || $variable_name === 'view_count' || $variable_name === 'post_view_count') return (string) max(0, $real_views);
                if ($variable_name === 'views_boost') return (string) max(0, $views_boost);
                return (string) max(0, $real_views + $views_boost);
            }

            // 已知别名映射：content_* → post 对象字段（与 merge_loop_context 保持一致）
            $alias_map = [
                'content_title'    => 'post_title',
                'content_date'     => 'post_date',
                'content_type'     => 'post_type',
                'content_summary'  => 'post_excerpt',
                'content_thumbnail' => 'featured_image',
                'highlighted_title'   => 'post_title',
                'highlighted_summary'  => 'post_excerpt',
                'post_featured_image'  => 'featured_image',
                // 页面上下文变量，不应查 model_fields
                'current_url'      => true,
            ];
            if (isset($alias_map[$variable_name])) {
                $real = $alias_map[$variable_name];
                // current_url 是页面级上下文变量，非 post 字段
                if ($real === true) {
                    if (isset($post->current_url) && $post->current_url !== '') return (string)$post->current_url;
                    if (function_exists('get_current_url')) { $u = get_current_url(); if ($u !== '') return $u; }
                    return '';
                }
                if (isset($post->$real) && $post->$real !== '') {
                    $raw = $post->$real;
                    // featured_image 可能是 JSON 数组/序列化数据，需要提取第一张图片 URL
                    if ($real === 'featured_image' && is_string($raw)) {
                        $raw = self::resolve_featured_image_to_url($raw);
                        if ($raw === '') return '';
                    }
                    return (string)$raw;
                }
                // 回退到 get_post_meta 再查一次原字段名
                if (function_exists('get_post_meta')) {
                    $meta = get_post_meta($post_id, $real, true);
                    if ($meta !== '' && $meta !== null) {
                        if ($real === 'featured_image' && is_string($meta)) {
                            $meta = self::resolve_featured_image_to_url($meta);
                            if ($meta === '') return '';
                        }
                        return $meta;
                    }
                }
                return '0';
            }

            if ($post_id > 0 && $model_key !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $model_key) && preg_match('/^[a-zA-Z0-9_]+$/', $variable_name)) {
                // 剥离模板层前缀（如 article_is_hot → is_hot），model_key 已承载上下文
                $field_name = $variable_name;
                $prefix = $model_key . '_';
                if (strpos($field_name, $prefix) === 0) {
                    $field_name = substr($field_name, strlen($prefix));
                    if ($field_name === '') $field_name = $variable_name;
                }
                if (isset($GLOBALS['tododb'])) {
                    $db = $GLOBALS['tododb']; $table = $db->prefix . 'model_fields_' . $model_key;
                    $exists = function_exists('table_exists') ? table_exists($table) : (preg_match('/^[a-zA-Z0-9_]+$/', $table) ? (bool)$db->get_var("SHOW TABLES LIKE '{$table}'") : false);
                    if ($exists) {
                        $row = $db->get_row("SELECT * FROM `{$table}` WHERE post_id = ? LIMIT 1", [$post_id], 'ARRAY_A');
                        if ($row !== null && is_array($row) && array_key_exists($field_name, $row)) {
                            $val = $row[$field_name];
                            if ($val !== null && $val !== '') {
                                $unserialized = maybe_unserialize($val);
                                if (function_exists('todo_map_inline_translations_deep')) {
                                    $unserialized = todo_map_inline_translations_deep($unserialized);
                                }
                                if (is_scalar($unserialized) || $unserialized === null) {
                                    return self::normalize_model_media_field_value($field_name, $unserialized);
                                }
                            }
                        }
                    }
                }
                if (function_exists('get_post_meta')) {
                    $meta = get_post_meta($post_id, $field_name, true);
                    if ($meta !== '' && $meta !== null) {
                        if (function_exists('todo_map_inline_translations_deep')) {
                            $meta = todo_map_inline_translations_deep($meta);
                        }
                        return self::normalize_model_media_field_value($field_name, $meta);
                    }
                }
            }
        }
        return '0';
    }

    // ======================================================================
    // 9. 缓存模板渲染（从 CMS_Template_Parser::parse_template 迁移）
    // ======================================================================

    /**
     * 带 HTML 结果缓存的模板渲染
     *
     * @param string $template_path
     * @param array $data
     * @return string
     */
    public static function parse_template_with_cache($template_path, $data = [])
    {
        $start = microtime(true);

        if (class_exists('Todo_Batch_Preloader')) Todo_Batch_Preloader::enable_collecting();

        $enable_result_cache = false;
        if (function_exists('todo_get_option')) {
            $enable_result_cache = (int) todo_get_option('enable_template_result_cache', 0) === 1;
        } elseif (function_exists('get_option')) {
            $enable_result_cache = (int) get_option('enable_template_result_cache', 0) === 1;
        }

        // 结果缓存检查
        if ($enable_result_cache && class_exists('Todo_Template_Result_Cache')) {
            $p = is_string($template_path) ? str_replace('\\', '/', $template_path) : '';
            $pt = function_exists('get_current_page_type') ? (string) get_current_page_type() : '';
            $skip_result_cache = ($p !== '' && strpos($p, '/users/') !== false) || $pt === 'single';
            if (!$skip_result_cache) {
                $cached_result = Todo_Template_Result_Cache::get($template_path, $data);
                if ($cached_result !== null && $cached_result !== '' && trim($cached_result) !== '') {
                    if (strpos($cached_result, '<?php') !== false || strpos($cached_result, '<?=') !== false
                        || strpos($cached_result, '{{todo:') !== false) {
                        Todo_Template_Result_Cache::clear($template_path);
                    } else {
                        return $cached_result;
                    }
                }
            }
        }

        // 用户会话数据注入（/users/ 模板）
        if (isset($p) && $p !== '' && strpos($p, '/users/') !== false) {
            $need_user = !isset($data['user']) || $data['user'] === null || $data['user'] === '' || $data['user'] === [];
            if ($need_user && class_exists('Todo_Session_Manager')) {
                $data['current_user'] = []; $data['user'] = []; $data['user_id'] = 0;
                Todo_Session_Manager::init();
                $uid = Todo_Session_Manager::get_frontend_user_id();
                if (!$uid) $uid = Todo_Session_Manager::get_admin_user_id();
                $uid = (int) $uid;
                if ($uid > 0) {
                    $row = self::get_user_by_id($uid);
                    if ($row) {
                        $user_arr = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);
                        if (!isset($user_arr['display_name']) || $user_arr['display_name'] === '' || $user_arr['display_name'] === null) {
                            $user_arr['display_name'] = '用户';
                        }
                        if ($uid > 0 && function_exists('get_user_avatar_url')) {
                            $user_arr['avatar'] = get_user_avatar_url($uid, 80);
                        }
                        if (!isset($user_arr['level_name']) || $user_arr['level_name'] === '' || $user_arr['level_name'] === null) {
                            $user_arr['level_name'] = '注册会员';
                        }
                        $data['current_user'] = $user_arr; $data['user'] = $user_arr; $data['user_id'] = $uid;
                    }
                }
            }
        }

        // 注册用户中心模板标签（解决 /users/ 模板中 cart_items 等标签未注册的问题）
        if (function_exists('todo_register_user_center_tags')) {
            todo_register_user_center_tags();
        }

        // 缓存配置
        $cache_enabled = true; $cache_expire = 3600; $page_mode = 'dynamic'; $effective_cache_dir = '';
        if (function_exists('get_option')) {
            $page_mode = get_option('page_mode', 'dynamic'); $page_mode = in_array($page_mode, ['static','dynamic']) ? $page_mode : 'dynamic';
            $micro_cache_time_raw = get_option('dynamic_template_cache_ttl', null);
            $micro_cache_time = $micro_cache_time_raw !== null ? intval($micro_cache_time_raw) : intval(get_option('html_cache_time', 3600));
        } else { $micro_cache_time = 3600; }
        if ($page_mode === 'dynamic') { $cache_enabled = $micro_cache_time > 0; $cache_expire = max(30, min(3600, $micro_cache_time)); }
        else { $cache_enabled = true; $cache_expire = 0; }

        // 所有内容时间变更（文章发布、广告修改、分类隐藏、优惠券到期等）
        // 已由 on_content_updated / on_ads_changed 等钩子做同步精准失效，
        // async-check 做异步时间窗口兜底。
        // 模板编译器不再做全局 latest_*_time > cache_time 比对，
        // 避免任意一条数据变更误伤所有不相关的缓存页面。
        // 动态令牌及受保护文章检测：含 CSRF 令牌或受保护文章时禁用结果缓存
        if ($cache_enabled) {
            // 用户中心禁用缓存，保障实时性
            $norm_tpl_path = str_replace('\\', '/', (string) $template_path);
            $req_uri = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (function_exists('todo_server_string') ? todo_server_string('REQUEST_URI', '') : ($_SERVER['REQUEST_URI'] ?? ''));
            if (
                strpos($norm_tpl_path, '/users/') !== false ||
                strpos($req_uri, '/users') !== false ||
                todo_get_query_context_value('user', (function_exists('todo_get_string') ? todo_get_string('user') : '')) !== ''
            ) {
                $cache_enabled = false;
            }

            if ($cache_enabled) {
                $dt = @file_get_contents($template_path);
                if ($dt !== false) {
                    // 仅当模板标签中包含动态 CSRF 令牌时才禁用缓存（如 {{todo:csrf_token}}、{{todo:csrf_field}}）
                    // 避免因 JS 代码中的 '_todononce'/'csrf_token' 字面量字符串而误禁用缓存
                    if (preg_match('/{{todo:csrf_/', $dt)) $cache_enabled = false;
                    $pt = function_exists('get_current_page_type') ? (string) get_current_page_type() : '';
                    if ($cache_enabled && $page_mode === 'dynamic' && $pt === 'single' && (
                        strpos($dt, '{{todo:views') !== false
                        || strpos($dt, '{{todo:content_views') !== false
                        || strpos($dt, '{{todo:post_views') !== false
                        || strpos($dt, '{{todo:real_views') !== false
                        || strpos($dt, '{{todo:views_boost') !== false
                    )) {
                        $cache_enabled = false;
                    }
                }
            }
        }

        // 强制刷新标志
        $is_preview_request = false;
        if (function_exists('todo_get_query_context_value')) {
            $is_preview_request = (string) todo_get_query_context_value('preview', '') === '1' && (string) todo_get_query_context_value('theme', '') !== '';
        }
        if (!$is_preview_request && function_exists('todo_get_string')) {
            $is_preview_request = todo_get_string('preview') === '1' && todo_get_string('theme') !== '';
        }
        $todo_cache_action = function_exists('todo_get_query_context_value')
            ? (string) todo_get_query_context_value('todocache', (function_exists('todo_get_string') ? todo_get_string('todocache') : ''))
            : (function_exists('todo_get_string') ? todo_get_string('todocache') : '');
        $force_refresh = (defined('TODO_CACHE_REFRESHED') && TODO_CACHE_REFRESHED)
            || (defined('TODO_VIEW_FUTURE') && TODO_VIEW_FUTURE)
            || $is_preview_request
            || $todo_cache_action === 'refresh';
        if ($force_refresh && !$cache_enabled) $cache_enabled = false;
        
        // 缓存预热引擎强制开启缓存写入
        if (!empty($GLOBALS['todo_doing_cache_warmup'])) {
            $cache_enabled = true;
            if ($cache_expire === 0) {
                $cache_expire = 3600;
            }
            $force_refresh = true; // 强制重新编译
        }

        if (!file_exists($template_path)) {
            file_put_contents(ABSPATH . 'logs/cache_debug.txt', "Template path not found: $template_path\n", FILE_APPEND);
            return '';
        }

        // 缓存键与目录
        // 站点维度：首页、分类页、单页分类需要区分不同站点的缓存
        $site_suffix = '';
        $pt = function_exists('get_current_page_type') ? (string) get_current_page_type() : '';
        $is_single_page_category = !empty($GLOBALS['_todo_is_single_page_category']);
        if (($pt !== 'single' || $is_single_page_category)
            && $pt !== 'tag_index' && $pt !== 'tag_lists' && $pt !== 'tag'
            && function_exists('todo_current_site_id')) {
            $site_id = (int) todo_current_site_id();
            if ($site_id > 0) {
                $site_suffix = '_site' . $site_id;
            }
        }
        $request_uri_for_key = function_exists('todo_get_request_uri') ? todo_get_request_uri() : (function_exists('todo_server_string') ? todo_server_string('REQUEST_URI', '') : ($_SERVER['REQUEST_URI'] ?? ''));
        if ($request_uri_for_key !== '' && strpos($request_uri_for_key, 'todocache=') !== false) {
            $request_uri_for_key = remove_query_arg('todocache', $request_uri_for_key);
        }
        $http_host_for_key = function_exists('todo_server_string') ? todo_server_string('HTTP_HOST', '') : ($_SERVER['HTTP_HOST'] ?? '');
        $cache_key = md5($template_path . $request_uri_for_key . $http_host_for_key . $site_suffix);
        $locale = function_exists('current_locale') ? current_locale() : (function_exists('get_option') ? get_option('locale', 'zh-CN') : 'zh-CN');
        if (function_exists('canonical_locale')) $locale = canonical_locale($locale);
        $locale_safe = preg_replace('/[^a-zA-Z0-9\-]/', '_', (string) $locale);
        $base_cache_dir = '';
        if (function_exists('get_cache_dir')) {
            $base_cache_dir = (string) get_cache_dir('templates');
        }
        if ($base_cache_dir === '') {
            $base_cache_dir = ABSPATH . 'content/cache/templates/';
        }
        $base_cache_dir = rtrim(str_replace('\\', '/', $base_cache_dir), '/') . '/';
        $effective_cache_dir = $base_cache_dir . ($locale_safe ?: 'default') . '/';
        if (!is_dir($effective_cache_dir)) @mkdir($effective_cache_dir, 0755, true);
        $cache_file = $effective_cache_dir . $cache_key . '.html';
        $cache_miss_reason = '';
        // 缓存命中
        if ($cache_enabled && !$force_refresh && file_exists($cache_file)) {
            $cache_time = filemtime($cache_file);
            $tpl_time = filemtime($template_path);
            $compiler_time = file_exists(__FILE__) ? filemtime(__FILE__) : 0;
            // 包含核心运行时文件修改时间，避免代码变更后缓存仍命中
            $_core_runtime = defined('ABSPATH') ? ABSPATH . 'core/functions/core.php' : '';
            $_core_runtime2 = __DIR__ . '/../classes/class-html-template-tags.php';
            $_rt_time = 0;
            if ($_core_runtime !== '' && file_exists($_core_runtime)) $_rt_time = max($_rt_time, filemtime($_core_runtime));
            if (file_exists($_core_runtime2)) $_rt_time = max($_rt_time, filemtime($_core_runtime2));
            if ($tpl_time <= $cache_time && $compiler_time <= $cache_time && $_rt_time <= $cache_time) {
                if ($cache_expire === 0 || (time() - $cache_time) <= $cache_expire) {
                    // 所有内容时间变更已由钩子同步精准失效 + async-check 异步兜底处理，
                    // 不再在此处做任何全局 latest_*_time > cache_time 比对

                    $content = file_get_contents($cache_file);
                    if (is_string($content) && $content !== '') {
                        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false || strpos($content, '{{todo:') !== false) {
                            $cache_miss_reason = 'invalid_cached_content_unlinked';
                            @unlink($cache_file);
                            goto __todo_template_cache_miss;
                        }

                        if (function_exists('todo_get_async_cache_check_script_html')
                            && strpos($content, 'data-todo-async-cache-check="1"') === false) {
                            $bootstrap = todo_get_async_cache_check_script_html(true);
                            if ($bootstrap !== '') {
                                $body_pos = strripos($content, '</body>');
                                if ($body_pos !== false) {
                                    $content = substr($content, 0, $body_pos) . $bootstrap . substr($content, $body_pos);
                                } else {
                                    $content .= $bootstrap;
                                }
                            }
                        }
                    }
                    if (!headers_sent() && php_sapi_name() !== 'cli') {
                        $dur_ms = round((microtime(true) - $start) * 1000, 1);
                        @header('X-Todo-Render-Time: ' . $dur_ms . 'ms', false);
                    }
                    return $content;
                }
            }
        }

        // 编译渲染
        __todo_template_cache_miss:
        if ($cache_miss_reason === '') {
            if (!$cache_enabled) {
                $cache_miss_reason = 'cache_disabled';
            } elseif ($force_refresh) {
                $cache_miss_reason = 'force_refresh';
            } elseif (!file_exists($cache_file)) {
                $cache_miss_reason = 'cache_file_not_found';
            } else {
                $cache_miss_reason = 'cache_stale_or_expired';
            }
        }
        // DB 查询预取
        if (class_exists('Todo_Query_Prefetcher') && file_exists($template_path)) {
            $tc = @file_get_contents($template_path);
            if ($tc !== false) Todo_Query_Prefetcher::prefetch_from_template($tc, $data);
        }
        if (class_exists('Todo_DB_Query_Interceptor')) Todo_DB_Query_Interceptor::flush();

        // 文章预加载
        if (isset($data['post']) && is_object($data['post']) && isset($data['post']->ID)) {
            $pid = (int) $data['post']->ID;
            if ($pid > 0 && class_exists('Todo_Post_ORM')) Todo_Post_ORM::eager_load_all([$pid], ['meta'=>true,'terms'=>true,'multilang'=>true,'tags'=>true,'categories'=>true]);
        } elseif (isset($data['post_id']) && $data['post_id'] > 0 && class_exists('Todo_Post_ORM')) {
            Todo_Post_ORM::eager_load_all([(int)$data['post_id']], ['meta'=>true,'terms'=>true,'multilang'=>true,'tags'=>true,'categories'=>true]);
        }
        
        $dependency_capture_started = false;
        if ($cache_enabled && class_exists('Todo_Unified_Cache_Manager')
            && method_exists('Todo_Unified_Cache_Manager', 'begin_template_cache_dependency_capture')) {
            Todo_Unified_Cache_Manager::begin_template_cache_dependency_capture($cache_file);
            $dependency_capture_started = true;
        }

        $parsed_content = self::render_template($template_path, $data);
        $persist_ad_dependencies = false;

        $full_page_content = $parsed_content;
        $response_content = $parsed_content;

        // 保存缓存（仅当结果非空且不含未解析标签时才缓存）
        if ($cache_enabled && is_writable($effective_cache_dir) && $full_page_content !== '' && trim($full_page_content) !== '') {
            $can_write_cache = (strpos($full_page_content, '{{todo:') === false);

            // 搜索防护：仅当确定搜索结果为 0 条或显式渲染无结果模块或频率锁定/敏感拦截时才禁止落盘缓存
            if ($can_write_cache && (strpos($template_path, 'lists_search') !== false || (function_exists('todo_get_search_context') && todo_get_search_context() !== ''))) {
                $is_zero_results = false;
                if ((defined('SEARCH_LOCKED') && SEARCH_LOCKED) || (defined('SEARCH_SENSITIVE_BLOCKED') && SEARCH_SENSITIVE_BLOCKED)) {
                    $is_zero_results = true;
                } elseif (isset($data['found_posts']) && (int)$data['found_posts'] === 0) {
                    $is_zero_results = true;
                } elseif (isset($data['search_results']) && is_array($data['search_results']) && count($data['search_results']) === 0) {
                    $is_zero_results = true;
                } elseif (strpos($full_page_content, 'class="no-results"') !== false || strpos($full_page_content, 'class="search-locked-notice"') !== false) {
                    $is_zero_results = true;
                }

                if ($is_zero_results) {
                    $can_write_cache = false;
                }
            }

            // 受保护文章（password / private）防护：
            // 如果渲染出来的全页 HTML 中不含“todo-password-form”也不含“todo-access-denied”（即包含已解锁的具体敏感正文），
            // 则拒绝把包含已解锁敏感正文的 HTML 写入静态缓存文件，确保磁盘上的 HTML 缓存永远只是无敏感正文的静态框架外壳。
            if ($can_write_cache) {
                $check_post = isset($data['post']) && is_object($data['post']) ? $data['post'] : (function_exists('get_current_post') ? get_current_post() : null);
                if (!$check_post && isset($data['post_id']) && (int)$data['post_id'] > 0 && function_exists('get_post')) {
                    $check_post = get_post($data['post_id']);
                }
                if ($check_post && isset($check_post->ID)) {
                    $vis = get_post_meta($check_post->ID, '_visibility', true);
                    if ($vis === 'password' || $vis === 'private') {
                        $has_pw_form = (strpos($full_page_content, 'todo-password-form') !== false);
                        $has_denied_block = (strpos($full_page_content, 'todo-access-denied') !== false);
                        // 若既没有密码框也没有无权访问提示框，说明输出的是解密后的敏感正文，禁止覆写静态缓存
                        if (!$has_pw_form && !$has_denied_block && empty($GLOBALS['todo_doing_cache_warmup'])) {
                            $can_write_cache = false;
                        }
                    }
                }
            }

            if ($can_write_cache) {
                $write_ok = (@file_put_contents($cache_file, $full_page_content) !== false);
                if ($write_ok && class_exists('Todo_Template_Result_Cache')) {
                    Todo_Template_Result_Cache::set($template_path, $data, $full_page_content, $cache_expire !== 0 ? $cache_expire : 31536000);
                }
                $persist_ad_dependencies = $write_ok;
            } else {
                error_log('[Template Compiler V2] 拒绝将敏感/未解析内容写入静态 HTML 缓存: ' . $template_path);
            }
        }

        if ($dependency_capture_started && class_exists('Todo_Unified_Cache_Manager')
            && method_exists('Todo_Unified_Cache_Manager', 'finish_template_cache_dependency_capture')) {
            Todo_Unified_Cache_Manager::finish_template_cache_dependency_capture($cache_file, $persist_ad_dependencies);
        }

        if (!headers_sent() && php_sapi_name() !== 'cli') {
            $dur_ms = round((microtime(true) - $start) * 1000, 1);
            @header('X-Todo-Render-Time: ' . $dur_ms . 'ms', false);
        }

        return $response_content;
    }

    /**
     * 智能分词候选分组提取（纯 PHP 实现，用于交叉搜索）
     * 支持：多词空格切分、中英文数字混合拆分、中文 4-7 字复合词多路径切分
     * @param string $keyword
     * @return array<int, string[]>
     */
    public static function get_search_token_groups($keyword)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return [];

        // 1. 空格与常见标点切分
        $raw_blocks = preg_split('/[\s,，;；。.\!\！\?\？、\-_+\/\\\]+/u', $keyword);
        $blocks = array_values(array_filter(array_map('trim', (array)$raw_blocks), function($b) { return $b !== ''; }));
        if (empty($blocks)) return [];

        // 用户显式用空格/标点隔开多个词，如 "高性能 缓存" 或 "分布式 Redis"
        if (count($blocks) > 1) {
            return [$blocks];
        }

        $str = $blocks[0];

        // 2. 中英文与数字边界提取，如 "Redis缓存" -> ["Redis", "缓存"]
        preg_match_all('/([a-zA-Z0-9_\-\.]+|[\x{4e00}-\x{9fa5}]+|[^\s])/u', $str, $parts);
        $elements = !empty($parts[0]) ? $parts[0] : [$str];

        if (count($elements) > 1) {
            $groups = [[]];
            foreach ($elements as $el) {
                if (preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $el) && mb_strlen($el, 'UTF-8') >= 4) {
                    $sub_groups = self::get_chinese_partitions($el);
                    $new_groups = [];
                    foreach ($groups as $g) {
                        foreach ($sub_groups as $sg) {
                            $new_groups[] = array_merge($g, $sg);
                        }
                    }
                    $groups = $new_groups;
                } else {
                    foreach ($groups as &$g) {
                        $g[] = $el;
                    }
                }
            }
            return $groups;
        }

        // 3. 纯中文连续短语切分
        return self::get_chinese_partitions($str);
    }

    /**
     * 中文连续短语多路径切分
     * @param string $str
     * @return array<int, string[]>
     */
    public static function get_chinese_partitions($str)
    {
        $len = mb_strlen($str, 'UTF-8');
        if ($len <= 3) {
            return [[$str]];
        }

        if ($len === 4) {
            // 4字词：如"网站搭建" -> ["网站", "搭建"]
            return [
                [mb_substr($str, 0, 2, 'UTF-8'), mb_substr($str, 2, 2, 'UTF-8')]
            ];
        } elseif ($len === 5) {
            // 5字词：如"高性能缓存" -> 优先 3+2 ("高性能", "缓存")，备选 2+3
            return [
                [mb_substr($str, 0, 3, 'UTF-8'), mb_substr($str, 3, 2, 'UTF-8')],
                [mb_substr($str, 0, 2, 'UTF-8'), mb_substr($str, 2, 3, 'UTF-8')]
            ];
        } elseif ($len === 6) {
            // 6字词：如"网站搭建教程" -> 2+2+2 或 3+3
            return [
                [mb_substr($str, 0, 2, 'UTF-8'), mb_substr($str, 2, 2, 'UTF-8'), mb_substr($str, 4, 2, 'UTF-8')],
                [mb_substr($str, 0, 3, 'UTF-8'), mb_substr($str, 3, 3, 'UTF-8')]
            ];
        } elseif ($len === 7) {
            // 7字词：如"微信小程序开发" -> 2+3+2 或 3+2+2
            return [
                [mb_substr($str, 0, 2, 'UTF-8'), mb_substr($str, 2, 3, 'UTF-8'), mb_substr($str, 5, 2, 'UTF-8')],
                [mb_substr($str, 0, 3, 'UTF-8'), mb_substr($str, 3, 2, 'UTF-8'), mb_substr($str, 5, 2, 'UTF-8')]
            ];
        } else {
            // 8字以上复合长句：二元滑动切分
            $bi = [];
            for ($i = 0; $i < $len; $i += 2) {
                $sub = mb_substr($str, $i, 2, 'UTF-8');
                if ($sub !== '') $bi[] = $sub;
            }
            return [$bi];
        }
    }

    /**
     * 简单中文分词（平铺返回所有分词词元，兼容历史调用）
     * @param string $keyword
     * @return string[]
     */
    public static function segment_keyword($keyword)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') return [];

        $groups = self::get_search_token_groups($keyword);
        $tokens = [$keyword];
        foreach ($groups as $g) {
            foreach ($g as $item) {
                if (mb_strlen($item, 'UTF-8') >= 2) {
                    $tokens[] = $item;
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * 搜索关键词高亮渲染器
     * @param string $text
     * @param string $keyword
     * @return string
     */
    public static function highlight_keywords($text, $keyword)
    {
        $text = (string)$text;
        $keyword = trim((string)$keyword);
        if ($keyword === '' || $text === '') return $text;

        $groups = self::get_search_token_groups($keyword);
        $words = [$keyword];
        foreach ($groups as $g) {
            foreach ($g as $item) {
                if (mb_strlen($item, 'UTF-8') >= 2) {
                    $words[] = $item;
                }
            }
        }
        $words = array_values(array_unique($words));
        usort($words, function($a, $b) {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        $safe_text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if (empty($words)) return $safe_text;

        $patterns = array_map(function($w) { return preg_quote($w, '/'); }, $words);
        $regex = '/(' . implode('|', $patterns) . ')/iu';
        return (string)preg_replace($regex, '<span class="highlight">$1</span>', $safe_text);
    }

    public static function build_paginated_url($page, $base_url, $page_param = 'paged')
    {
        $page = intval($page);
        $page_param = is_string($page_param) ? trim($page_param) : '';
        if ($page_param === '' || $page_param === 'main') $page_param = 'paged';

        $url = (string) $base_url;
        if ($url === '') {
            if (function_exists('todo_get_current_url')) $url = todo_get_current_url();
            elseif (function_exists('todo_home_url')) $url = todo_home_url('/');
            else $url = '/';
        }

        $parts = @parse_url($url);
        if (!is_array($parts)) $parts = [];
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path === '') $path = '/';

        $queryArgs = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryArgs);
            if (!is_array($queryArgs)) $queryArgs = [];
        }

        $prefix = '';
        if (isset($parts['scheme'])) $prefix .= $parts['scheme'] . '://';
        if (isset($parts['host'])) $prefix .= $parts['host'];
        if (isset($parts['port'])) $prefix .= ':' . $parts['port'];

        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';

        if ($page_param === 'paged') {
            unset($queryArgs['paged'], $queryArgs['page']);
            $path = preg_replace('~/(?:page)/[0-9]+/?$~', '/', $path);
            if ($path === '') $path = '/';

            $structure = function_exists('get_option') ? get_option('permalink_structure', '') : '';
            if (!empty($structure) && empty($queryArgs)) {
                if ($page <= 1) {
                    $final = rtrim($prefix . $path, '/');
                    if ($final === '') $final = '/';
                } else {
                    $final = rtrim($prefix . $path, '/') . '/page/' . $page . '/';
                }
                if (!empty($queryArgs)) $final .= '?' . http_build_query($queryArgs);
                if (function_exists('get_language_url') && function_exists('current_locale')) $final = get_language_url(current_locale(), $final);
                return $final . $fragment;
            }

            if ($page > 1) $queryArgs['paged'] = $page;
            $final = $prefix . $path;
            if (!empty($queryArgs)) $final .= '?' . http_build_query($queryArgs);
            if (function_exists('get_language_url') && function_exists('current_locale')) $final = get_language_url(current_locale(), $final);
            return $final . $fragment;
        }

        $k = 'paged_' . sanitize_key($page_param);
        unset($queryArgs[$k]);
        if ($page > 1) $queryArgs[$k] = $page;

        $final = $prefix . $path;
        if (!empty($queryArgs)) $final .= '?' . http_build_query($queryArgs);
        if (function_exists('get_language_url') && function_exists('current_locale')) $final = get_language_url(current_locale(), $final);
        return $final . $fragment;
    }

    public static function build_pagination_pages($current_page, $total_pages, $base_url, $page_param = 'paged', $mid_size = 2, $end_size = 1)
    {
        $total_pages = max(1, intval($total_pages));
        $current_page = max(1, intval($current_page));
        if ($current_page > $total_pages) $current_page = $total_pages;

        $mid_size = max(0, intval($mid_size));
        $end_size = max(0, intval($end_size));

        if ($total_pages <= 1) return [];

        $included = [];
        for ($i = 1; $i <= $total_pages; $i++) {
            $is_begin = ($end_size > 0) ? ($i <= $end_size) : false;
            $is_end = ($end_size > 0) ? ($i > ($total_pages - $end_size)) : false;
            $is_mid = ($i >= ($current_page - $mid_size) && $i <= ($current_page + $mid_size));
            if ($is_begin || $is_mid || $is_end) $included[$i] = true;
        }

        $pages = [];
        $last = 0;
        for ($i = 1; $i <= $total_pages; $i++) {
            if (!isset($included[$i])) continue;
            if ($last > 0 && $i > ($last + 1)) {
                $pages[] = [
                    'page_number'     => '',
                    'page_is_current' => 0,
                    'page_is_dots'    => 1,
                    'page_url'        => '',
                ];
            }
            $pages[] = [
                'page_number'     => $i,
                'page_is_current' => ($i === $current_page) ? 1 : 0,
                'page_is_dots'    => 0,
                'page_url'        => self::build_paginated_url($i, $base_url, $page_param),
            ];
            $last = $i;
        }

        return $pages;
    }
}

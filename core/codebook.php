<?php
/**
 * Myioncube Codebook Generator
 * Generates a unique 256-byte mapping for encryption.
 */

namespace Myioncube;

class Codebook
{
    private $map = [];
    private $reverse_map = [];

    public function __construct($seed = null)
    {
        if ($seed !== null) {
            mt_srand(crc32($seed));
        }
        
        $this->map = range(0, 255);
        shuffle($this->map);
        
        foreach ($this->map as $k => $v) {
            $this->reverse_map[$v] = $k;
        }
    }

    public function export()
    {
        return $this->map;
    }

    public function get_map_string()
    {
        return base64_encode(pack('C*', ...$this->map));
    }

    public static function from_string($string)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1]['class'] ?? '';
        if ($caller !== 'Myioncube\Loader' && $caller !== 'Myioncube\Encoder') {
            die("Unauthorized codebook access.");
        }

        $bytes = unpack('C*', base64_decode($string));
        $inst = new self();
        $inst->map = array_values($bytes);
        foreach ($inst->map as $k => $v) {
            $inst->reverse_map[$v] = $k;
        }
        return $inst;
    }

    public function translate($data, $forward = true)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1]['class'] ?? '';
        if ($caller !== 'Myioncube\Loader' && $caller !== 'Myioncube\Encoder') {
            die("Unauthorized translation access.");
        }

        $m = $forward ? $this->map : $this->reverse_map;
        $len = strlen($data);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr($m[ord($data[$i])]);
        }
        return $out;
    }
}

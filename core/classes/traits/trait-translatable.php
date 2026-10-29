<?php
/**
 * Translatable Trait
 *
 * 为可翻译实体提供统一的翻译读写接口。
 * 引入“翻译子表”范式：主表保存跨语言通用字段，子表保存各语言文本。
 *
 * 使用方式：在模型类中 use Todo_Translatable；并实现 get_translation_table_name()。
 *
 * @package TodoCMS
 * @version 1.0.0
 */

trait Todo_Translatable {
    /**
     * 翻译缓存（请求级）
     * @var array
     */
    protected $translations_cache = [];

    /**
     * 返回当前实体的翻译表名（含前缀）
     * 例如：todo_post_translations、todo_term_translations 等。
     *
     * 子类必须实现。
     * @return string
     */
    abstract protected function get_translation_table_name();

    /**
     * 字段映射：翻译子表字段 => 主表字段
     * @return array
     */
    protected function get_translation_field_map() {
        return [
            'title'          => 'post_title',
            'slug'           => 'post_name',
            'excerpt'        => 'post_excerpt',
            'content'        => 'post_content',
            'seo_title'      => 'seo_title',
            'seo_description'=> 'seo_description',
            'seo_keywords'   => 'seo_keywords',
        ];
    }

    /**
     * 读取指定语言的翻译（带回退）
     * @param string|null $locale
     * @param bool $fallback
     * @return array 关联数组（字段=>值），未找到返回空数组
     */
    public function translate($locale = null, $fallback = true) {
        if (!$this->get_id()) { return []; }
        $locale = $locale ?: current_locale();

        $cache_key = $this->get_id() . '|' . $locale;
        if (isset($this->translations_cache[$cache_key])) {
            return $this->translations_cache[$cache_key];
        }

        global $tododb;
        $table = $this->get_translation_table_name();
        $row = $tododb->get_row("SELECT * FROM {$table} WHERE post_id = ? AND language_code = ? LIMIT 1", [$this->get_id(), $locale], ARRAY_A);
        if ($row) {
            $this->translations_cache[$cache_key] = $row;
            return $row;
        }

        if ($fallback) {
            $default = get_default_language();
            $defaultLocale = is_array($default) ? ($default['locale'] ?? 'zh-CN') : (string)$default;
            $row2 = $tododb->get_row("SELECT * FROM {$table} WHERE post_id = ? AND language_code = ? LIMIT 1", [$this->get_id(), $defaultLocale], ARRAY_A);
            if ($row2) {
                $this->translations_cache[$cache_key] = $row2;
                return $row2;
            }
        }
        $this->translations_cache[$cache_key] = [];
        return [];
    }

    /**
     * 判断是否存在指定语言的翻译
     */
    public function has_translation($locale = null) {
        $locale = $locale ?: current_locale();
        $t = $this->translate($locale, false);
        return !empty($t);
    }

    /**
     * 读取翻译字段（带主表回退）
     * @param string $field 翻译表字段名（如 title、content）
     * @param string|null $locale
     * @param bool $fallback
     * @return string
     */
    public function get_translated($field, $locale = null, $fallback = true) {
        $map = $this->get_translation_field_map();
        $t = $this->translate($locale, $fallback);
        if (!empty($t) && array_key_exists($field, $t) && $t[$field] !== null && $t[$field] !== '') {
            return (string)$t[$field];
        }
        // 主表回退
        $baseField = $map[$field] ?? null;
        if ($baseField) {
            if (is_array($this->data) && array_key_exists($baseField, $this->data)) {
                return (string)$this->data[$baseField];
            }
            if (is_object($this->data) && isset($this->data->$baseField)) {
                return (string)$this->data->$baseField;
            }
        }
        return '';
    }

    /**
     * 保存或更新翻译（UPSERT）
     * @param string $locale
     * @param array $data 仅包含翻译列
     * @return bool
     */
    public function set_translation($locale, array $data) {
        if (!$this->get_id()) { return false; }
        $locale = canonical_locale($locale);
        if (!is_valid_language($locale)) { return false; }

        global $tododb;
        $table = $this->get_translation_table_name();

        // 仅允许写入白名单字段
        $allowed = array_keys($this->get_translation_field_map());
        $payload = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $payload[$k] = $data[$k];
            }
        }
        if (empty($payload)) { return false; }

        // 检查是否存在
        $exists = $tododb->get_row("SELECT id FROM {$table} WHERE post_id = ? AND language_code = ? LIMIT 1", [$this->get_id(), $locale]);
        if ($exists) {
            // 更新
            $set = [];
            $vals = [];
            foreach ($payload as $k => $v) { $set[] = "{$k} = ?"; $vals[] = $v; }
            $vals[] = $this->get_id();
            $vals[] = $locale;
            $sql = "UPDATE {$table} SET " . implode(',', $set) . ", updated_at = CURRENT_TIMESTAMP WHERE post_id = ? AND language_code = ?";
            return (bool) $tododb->query($sql, $vals);
        } else {
            // 插入
            $cols = array_merge(['post_id','language_code'], array_keys($payload));
            $vals = array_merge([$this->get_id(), $locale], array_values($payload));
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$placeholders})";
            return (bool) $tododb->query($sql, $vals);
        }
    }
}

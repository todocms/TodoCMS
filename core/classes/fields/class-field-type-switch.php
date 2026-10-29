<?php
/**
 * 开关字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 */

class Field_Type_Switch extends Field_Type_Base
{
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html()
    {
        $value = $this->value;
        if ($value === null && isset($this->field['field_default'])) {
            $value = $this->field['field_default'];
        }

        // 确保值为布尔值或可转换为布尔值的字符串
        $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        // 获取开关标签文本
        $on_text = $this->field['on_text'] ?? '是';
        $off_text = $this->field['off_text'] ?? '否';

        // 确保主要ID唯一性
        $uid = uniqid();
        $field_id = 'field-' . $this->field['field_key'] . '-' . $uid;
        // 保持name属性不变
        $field_name = $this->field['field_key'];
        
        $html = '<div class="switch-field" id="' . esc_attr($field_id) . '-wrapper">';

        // 隐藏的实际输入字段
        $html .= sprintf(
            '<input type="hidden" name="%s" value="%s">',
            esc_attr($field_name),
            $checked ? '1' : '0'
        );

        // 开关UI
        $html .= '<div class="switch-toggle">';
        $html .= sprintf(
            '<input type="checkbox" id="%s-toggle" class="switch-checkbox" %s>',
            esc_attr($field_id),
            $checked ? 'checked' : ''
        );
        $html .= sprintf(
            '<label for="%s-toggle" class="switch-label">',
            esc_attr($field_id)
        );
        $html .= '<span class="switch-inner" data-on="' . esc_attr($on_text) . '" data-off="' . esc_attr($off_text) . '"></span>';
        $html .= '<span class="switch-switch"></span>';
        $html .= '</label>';
        $html .= '</div>';

        // 添加JavaScript以更新隐藏字段的值 (原生JS，无依赖)
        $html .= '<script type="text/javascript">
            (function() {
                var wrapper = document.getElementById("' . esc_js($field_id) . '-wrapper");
                if (!wrapper) return;
                var toggle = wrapper.querySelector(".switch-checkbox");
                var hidden = wrapper.querySelector("input[type=hidden]");
                
                if (toggle && hidden) {
                    toggle.addEventListener("change", function() {
                        hidden.value = this.checked ? "1" : "0";
                    });
                }
            })();
        </script>';

        // 添加CSS样式
        $html .= '<style>
            .switch-toggle { position: relative; display: inline-block; margin-right: 10px; }
            .switch-checkbox { display: none; }
            .switch-label { display: block; width: 60px; height: 30px; margin-bottom: 0; text-indent: -150%; clip: rect(0 0 0 0); color: transparent; user-select: none; cursor: pointer; }
            .switch-label::before, .switch-label::after { content: ""; display: block; position: absolute; cursor: pointer; }
            .switch-label::before { width: 100%; height: 100%; background-color: #dedede; border-radius: 9999em; transition: background-color 0.25s ease; }
            .switch-label::after { top: 2px; left: 2px; width: 26px; height: 26px; border-radius: 50%; background-color: white; box-shadow: 0 0 2px rgba(0, 0, 0, 0.45); transition: left 0.25s ease; }
            .switch-checkbox:checked + .switch-label::before { background-color: transparent; border: 1px solid #4caf50; width: 58px; height: 28px; }
            .switch-checkbox:checked + .switch-label::after { left: 32px; }
            .switch-inner { display: block; position: absolute; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; text-indent: 0; font-size: 12px; line-height: 30px; color: white; text-align: center; z-index: 1; }
            .switch-inner::before, .switch-inner::after { content: attr(data-off); position: absolute; top: 0; left: 0; text-indent: 24px; width: 100%; text-align: center; transition: left 0.25s ease; }
            .switch-inner::after { content: attr(data-on); left: -100%; }
            .switch-checkbox:checked + .switch-label .switch-inner { color: #4caf50; }
            .switch-checkbox:checked + .switch-label .switch-inner::before { left: 100%; }
            .switch-checkbox:checked + .switch-label .switch-inner::after { left: 0; text-indent: -24px; }
        </style>';

        $html .= '</div>';

        return $this->wrap_field($html);
    }

    /**
     * 验证字段值
     *
     * @param mixed $value 字段值
     * @return bool|string 验证通过返回true，失败返回错误信息
     */
    public function validate($value)
    {
        // 首先进行基本验证
        $result = parent::validate($value);
        if ($result !== true) {
            return $result;
        }

        // 开关字段的值应该是0或1，或者可以转换为布尔值的字符串
        if ($value !== null && $value !== '' && !in_array($value, ['0', '1', 0, 1, true, false], true)) {
            return '开关字段的值必须是0或1';
        }

        return true;
    }

    /**
     * 格式化字段值用于保存
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value($value)
    {
        // 将值格式化为0或1
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    /**
     * 格式化字段值用于显示
     *
     * @param mixed $value 字段值
     * @return mixed 格式化后的值
     */
    public function format_value_for_display($value)
    {
        $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $on_text = $this->field['on_text'] ?? '开启';
        $off_text = $this->field['off_text'] ?? '关闭';

        return $checked ? $on_text : $off_text;
    }

    /**
     * 获取字段配置表单HTML
     *
     * @return string 配置表单HTML
     */
    public function get_config_html()
    {
        $html = parent::get_config_html();

        // 开启状态文本
        $html .= '<div class="form-group">';
        $html .= '<label>开启状态文本</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="on_text" value="%s" placeholder="开启">',
            esc_attr($this->field['on_text'] ?? '开启')
        );
        $html .= '</div>';

        // 关闭状态文本
        $html .= '<div class="form-group">';
        $html .= '<label>关闭状态文本</label>';
        $html .= sprintf(
            '<input type="text" class="form-control" name="off_text" value="%s" placeholder="关闭">',
            esc_attr($this->field['off_text'] ?? '关闭')
        );
        $html .= '</div>';

        // 默认状态
        $html .= '<div class="form-group">';
        $html .= '<label>默认状态</label>';
        $html .= '<select class="form-control" name="field_default">';
        $html .= sprintf(
            '<option value="0" %s>关闭</option>',
            selected($this->field['field_default'] ?? '0', '0', false)
        );
        $html .= sprintf(
            '<option value="1" %s>开启</option>',
            selected($this->field['field_default'] ?? '0', '1', false)
        );
        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 获取字段搜索SQL
     *
     * @param string $value 搜索值
     * @return array|false 成功返回[SQL语句, 参数数组]，失败返回false
     */
    public function get_search_sql($value)
    {
        if ($value === '') {
            return false;
        }

        // 将搜索值转换为0或1
        $search_value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';

        return [
            "meta_key = ? AND meta_value = ?",
            [$this->field['field_key'], $search_value]
        ];
    }

    /**
     * 获取字段排序SQL
     *
     * @param string $direction 排序方向，ASC或DESC
     * @return array|false 成功返回[SQL语句, 参数数组]，失败返回false
     */
    public function get_order_sql($direction = 'ASC')
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        return [
            "meta_key = ? ORDER BY CAST(meta_value AS UNSIGNED) $direction",
            [$this->field['field_key']]
        ];
    }

    /**
     * 获取字段类型名称
     *
     * @return string 字段类型名称
     */
    public static function get_type_name()
    {
        return '开关';
    }

    /**
     * 获取字段类型描述
     *
     * @return string 字段类型描述
     */
    public static function get_type_description()
    {
        return '适用于表示是/否、开/关等二元选择';
    }

    /**
     * 获取字段类型图标
     *
     * @return string 字段类型图标HTML
     */
    public static function get_type_icon()
    {
        return '<i class="custom-icon"></i>';
    }
}
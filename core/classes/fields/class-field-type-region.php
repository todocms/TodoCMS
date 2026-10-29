<?php
/**
 * 区域字段类型
 *
 * @package TodoCMS
 * @version 1.0.0
 *
 * 说明：
 *  - 级别 1-4 为下拉选择器（省/市/区县/乡镇街道）
 *  - 级别 5 在前4级基础上追加一个可自由输入的"详细地址"文本框
 *  - 原第5级下拉（居委会/村）已移除
 */

 
class Field_Type_Region extends Field_Type_Base {
    /**
     * 获取字段HTML
     *
     * @return string 字段HTML
     */
    protected function render_html() {
        $value = $this->value ?? $this->field['field_default'] ?? '';
        $level = intval($this->field['region_level'] ?? 5);
        $level = max(1, min(5, $level));

        // 解析已保存的值
        $selected = [];
        if (!empty($value)) {
            $selected = explode('|', $value);
        }
        if (function_exists('todo_regions_normalize_code') && is_array($selected)) {
            // 只对前4段（行政代码）做规范化，第5段是自由文本不处理
            for ($i = 0; $i < min(4, count($selected)); $i++) {
                $selected[$i] = todo_regions_normalize_code($selected[$i]);
            }
        }

        // 确保数组有足够的元素（最多5段）
        $max_slots = min($level, 5);
        while (count($selected) < $max_slots) {
            $selected[] = '';
        }

        $html = '<div class="region-selector flex gap-2 flex-wrap items-center" data-level="' . $level . '">';

        // 省级选择器
        $html .= '<select class="form-control region-province" name="' . $this->field['field_key'] . '_province" data-level="1" style="min-width: auto;">';
        $html .= '<option value="">省份</option>';
        $provinces = $this->get_provinces();
        foreach ($provinces as $code => $name) {
            $html .= sprintf(
                '<option value="%s" %s>%s</option>',
                todo_esc_attr($code),
                todo_selected($selected[0], $code, false),
                todo_esc_html($name)
            );
        }
        $html .= '</select>';

        // 市级选择器
        if ($level >= 2) {
            $html .= '<select class="form-control region-city" name="' . $this->field['field_key'] . '_city" data-level="2" style="min-width: auto;">';
            $html .= '<option value="">城市</option>';
            if (!empty($selected[0])) {
                $cities = $this->get_cities($selected[0]);
                foreach ($cities as $code => $name) {
                    $html .= sprintf(
                        '<option value="%s" %s>%s</option>',
                        todo_esc_attr($code),
                        todo_selected($selected[1], $code, false),
                        todo_esc_html($name)
                    );
                }
            }
            $html .= '</select>';
        }

        // 区/县级选择器
        if ($level >= 3) {
            $html .= '<select class="form-control region-district" name="' . $this->field['field_key'] . '_district" data-level="3" style="min-width: auto;">';
            $html .= '<option value="">区/县</option>';
            if (!empty($selected[0]) && !empty($selected[1])) {
                $districts = $this->get_districts($selected[1]);
                foreach ($districts as $code => $name) {
                    $html .= sprintf(
                        '<option value="%s" %s>%s</option>',
                        todo_esc_attr($code),
                        todo_selected($selected[2], $code, false),
                        todo_esc_html($name)
                    );
                }
            }
            $html .= '</select>';
        }

        // 乡镇/街道级选择器
        if ($level >= 4) {
            $html .= '<select class="form-control region-town" name="' . $this->field['field_key'] . '_town" data-level="4" style="min-width: auto;">';
            $html .= '<option value="">乡镇/街道</option>';
            if (!empty($selected[0]) && !empty($selected[1]) && !empty($selected[2])) {
                $towns = $this->get_towns($selected[2]);
                foreach ($towns as $code => $name) {
                    $html .= sprintf(
                        '<option value="%s" %s>%s</option>',
                        todo_esc_attr($code),
                        todo_selected($selected[3], $code, false),
                        todo_esc_html($name)
                    );
                }
            }
            $html .= '</select>';
        }

        // 第5级：自由输入的详细地址文本框（不再提供下拉）
        if ($level >= 5) {
            $detail_value = isset($selected[4]) ? $selected[4] : '';
            $html .= sprintf(
                '<input type="text" class="form-control region-detail" name="%s_detail" placeholder="详细地址" value="%s" style="min-width:120px;">',
                todo_esc_attr($this->field['field_key']),
                todo_esc_attr($detail_value)
            );
        }

        // 隐藏字段，用于存储完整的区域值（|分隔）
        $html .= sprintf(
            '<input type="hidden" name="%s" id="field-%s" value="%s">',
            todo_esc_attr($this->field['field_key']),
            todo_esc_attr($this->field['field_key']),
            todo_esc_attr($value)
        );

        // 添加联动脚本
        if (function_exists('todo_enqueue_script')) {
            todo_enqueue_script('jquery');
        }

        $html .= '<script type="text/javascript">
        jQuery(document).ready(function($) {
            var fieldKey = "' . $this->field['field_key'] . '";
            var $container = (function() {
                try {
                    var s = document.currentScript;
                    if (!s) {
                        var scripts = document.getElementsByTagName("script");
                        s = scripts[scripts.length - 1];
                    }
                    var $c = $(s).closest(".region-selector");
                    if ($c && $c.length) {
                        return $c;
                    }
                } catch (e) {}
                return $("#field-" + fieldKey).closest(".region-selector");
            })();
            if (!$container || !$container.length) {
                return;
            }
            if ($container.data("todoRegionInited")) {
                return;
            }
            $container.data("todoRegionInited", true);
            $container.off(".todoRegion");
            var level = parseInt($container.attr("data-level"), 10) || 4;
            var ajaxUrl = (typeof todoAjax !== "undefined" && todoAjax.ajaxurl ? todoAjax.ajaxurl : (typeof ajaxurl !== "undefined" ? ajaxurl : "' . todo_admin_url('admin-ajax.php') . '"));

            // 更新隐藏字段的值
            function updateHiddenField() {
                var provinceCode = $container.find(".region-province").val() || "";
                var cityCode     = $container.find(".region-city").val() || "";
                var districtCode = $container.find(".region-district").val() || "";
                var townCode     = $container.find(".region-town").val() || "";
                var detailText   = $container.find(".region-detail").val() || "";

                var parts = [provinceCode, cityCode, districtCode, townCode];
                var keep = [];
                var dropdownLevel = Math.min(level, 4);
                for (var i = 0; i < dropdownLevel && i < parts.length; i++) {
                    if (!parts[i]) {
                        break;
                    }
                    keep.push(parts[i]);
                }
                // 第5级为自由文本，不依赖前面是否完整
                if (level >= 5 && detailText !== "") {
                    // 补齐前四段中断的空位
                    while (keep.length < 4) {
                        keep.push("");
                    }
                    keep.push(detailText);
                }
                // 去掉末尾空串
                while (keep.length > 0 && keep[keep.length - 1] === "") {
                    keep.pop();
                }
                var value = keep.join("|");
                $("#field-" + fieldKey).val(value);
            }

            function loadChildren(parentCode, $select, placeholderText) {
                if (!$select || !$select.length) {
                    return $.Deferred().resolve().promise();
                }
                $select.html("<option value=\"\">" + placeholderText + "</option>");
                if (!parentCode) {
                    return $.Deferred().resolve().promise();
                }
                return $.ajax({
                    url: ajaxUrl,
                    type: "GET",
                    dataType: "json",
                    data: { action: "get_region_children", parent_code: parentCode }
                }).done(function(response) {
                    if (response && response.success && response.data) {
                        $.each(response.data, function(code, name) {
                            if ($select.find("option[value=\"" + code + "\"]").length === 0) {
                                $select.append("<option value=\"" + code + "\">" + name + "</option>");
                            }
                        });
                    }
                });
            }

            function fillCascade(values) {
                values = values || {};
                var province = values.province || "";
                var city = values.city || "";
                var district = values.district || "";
                var town = values.town || "";
                var detail = values.detail || "";

                var $provinceSelect = $container.find(".region-province");
                var $citySelect = $container.find(".region-city");
                var $districtSelect = $container.find(".region-district");
                var $townSelect = $container.find(".region-town");
                var $detailInput = $container.find(".region-detail");

                if ($provinceSelect.length) {
                    $provinceSelect.val(province);
                }

                var chain = $.Deferred().resolve().promise();
                if (level >= 2 && $citySelect.length) {
                    chain = chain.then(function() {
                        return loadChildren(province, $citySelect, "城市");
                    }).then(function() {
                        $citySelect.val(city);
                    });
                }
                if (level >= 3 && $districtSelect.length) {
                    chain = chain.then(function() {
                        return loadChildren(city, $districtSelect, "区/县");
                    }).then(function() {
                        $districtSelect.val(district);
                    });
                }
                if (level >= 4 && $townSelect.length) {
                    chain = chain.then(function() {
                        return loadChildren(district, $townSelect, "乡镇/街道");
                    }).then(function() {
                        $townSelect.val(town);
                    });
                }
                if (level >= 5 && $detailInput.length) {
                    chain = chain.then(function() {
                        $detailInput.val(detail);
                    });
                }
                chain.then(function() {
                    updateHiddenField();
                });
            }

            $container.on("todoRegion:fill.todoRegion", function(e, values) {
                fillCascade(values);
            });

            // 省份变化时加载城市
            $container.on("change.todoRegion", ".region-province", function() {
                var provinceCode = $(this).val();
                var $citySelect     = $container.find(".region-city");
                var $districtSelect = $container.find(".region-district");
                var $townSelect     = $container.find(".region-town");

                $citySelect.html("<option value=\"\">城市</option>");
                $districtSelect.html("<option value=\"\">区/县</option>");
                $townSelect.html("<option value=\"\">乡镇/街道</option>");

                if (provinceCode) {
                    $.ajax({
                        url: ajaxUrl,
                        type: "GET",
                        dataType: "json",
                        data: { action: "get_region_children", parent_code: provinceCode },
                        success: function(response) {
                            if (response.success && response.data) {
                                $.each(response.data, function(code, name) {
                                    if ($citySelect.find("option[value=\"" + code + "\"]").length === 0) {
                                        $citySelect.append("<option value=\"" + code + "\">" + name + "</option>");
                                    }
                                });
                            }
                            updateHiddenField();
                        }
                    });
                }
                updateHiddenField();
            });

            // 城市变化时加载区县
            $container.on("change.todoRegion", ".region-city", function() {
                var cityCode        = $(this).val();
                var $districtSelect = $container.find(".region-district");
                var $townSelect     = $container.find(".region-town");

                $districtSelect.html("<option value=\"\">区/县</option>");
                $townSelect.html("<option value=\"\">乡镇/街道</option>");

                if (cityCode) {
                    $.ajax({
                        url: ajaxUrl,
                        type: "GET",
                        dataType: "json",
                        data: { action: "get_region_children", parent_code: cityCode },
                        success: function(response) {
                            if (response.success && response.data) {
                                $.each(response.data, function(code, name) {
                                    if ($districtSelect.find("option[value=\"" + code + "\"]").length === 0) {
                                        $districtSelect.append("<option value=\"" + code + "\">" + name + "</option>");
                                    }
                                });
                            }
                            updateHiddenField();
                        }
                    });
                }
                updateHiddenField();
            });

            // 区县变化时加载乡镇
            $container.on("change.todoRegion", ".region-district", function() {
                var districtCode = $(this).val();
                var $townSelect  = $container.find(".region-town");

                $townSelect.html("<option value=\"\">乡镇/街道</option>");

                if (districtCode && level >= 4) {
                    $.ajax({
                        url: ajaxUrl,
                        type: "GET",
                        dataType: "json",
                        data: { action: "get_region_children", parent_code: districtCode },
                        success: function(response) {
                            if (response.success && response.data) {
                                $.each(response.data, function(code, name) {
                                    if ($townSelect.find("option[value=\"" + code + "\"]").length === 0) {
                                        $townSelect.append("<option value=\"" + code + "\">" + name + "</option>");
                                    }
                                });
                            }
                            updateHiddenField();
                        }
                    });
                }
                updateHiddenField();
            });

            // 乡镇变化时更新隐藏字段
            $container.on("change.todoRegion", ".region-town", function() {
                updateHiddenField();
            });

            // 详细地址输入时更新隐藏字段
            $container.on("input.todoRegion", ".region-detail", function() {
                updateHiddenField();
            });

            updateHiddenField();
        });
        </script>';

        $html .= '</div>';

        return $this->wrap_field($html);
    }

    /**
     * 获取省份列表
     */
    private function get_provinces() {
        if (function_exists('todo_regions_get_provinces')) {
            return todo_regions_get_provinces();
        }
        return [];
    }

    /**
     * 获取城市列表
     */
    private function get_cities($province_code) {
        if (function_exists('todo_regions_get_children')) {
            return todo_regions_get_children($province_code, 2);
        }
        return [];
    }

    /**
     * 获取区县列表
     */
    private function get_districts($city_code) {
        if (function_exists('todo_regions_get_children')) {
            return todo_regions_get_children($city_code, 3);
        }
        return [];
    }

    /**
     * 获取乡镇/街道列表
     */
    private function get_towns($district_code) {
        if (function_exists('todo_regions_get_children')) {
            return todo_regions_get_children($district_code, 4);
        }
        return [];
    }

    /**
     * 验证字段值
     */
    public function validate($value) {
        $result = parent::validate($value);
        if ($result !== true) {
            return $result;
        }

        if (($this->field['is_required'] ?? false) && $value !== '') {
            $parts = is_array($value) ? $value : explode('|', (string) $value);
            $level = max(1, min(5, intval($this->field['region_level'] ?? 5)));
            // 前4段为下拉代码，第5段为自由文本；只校验下拉部分
            $dropdown_level = min($level, 4);
            for ($i = 0; $i < $dropdown_level; $i++) {
                if (!isset($parts[$i]) || trim((string) $parts[$i]) === '') {
                    return '请完整选择区域信息';
                }
            }
        }

        return true;
    }

    /**
     * 获取字段配置表单HTML
     */
    public function get_config_html() {
        $html = parent::get_config_html();

        $html .= '<div class="form-group">';
        $html .= '<label>区域级别</label>';
        $html .= '<select class="form-control" name="region_level">';
        $html .= sprintf('<option value="1" %s>省级</option>',                     todo_selected($this->field['region_level'] ?? 5, 1, false));
        $html .= sprintf('<option value="2" %s>省市</option>',                     todo_selected($this->field['region_level'] ?? 5, 2, false));
        $html .= sprintf('<option value="3" %s>省市区</option>',                   todo_selected($this->field['region_level'] ?? 5, 3, false));
        $html .= sprintf('<option value="4" %s>省市区乡镇</option>',               todo_selected($this->field['region_level'] ?? 5, 4, false));
        $html .= sprintf('<option value="5" %s>省市区乡镇+详细地址</option>',     todo_selected($this->field['region_level'] ?? 5, 5, false));
        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 格式化字段值用于显示
     */
    public function format_value_for_display($value) {
        if (empty($value)) {
            return '';
        }

        $parts = explode('|', $value);
        $names = [];

        // 前4段为行政区划代码，最后一段（index 4）为自由文本
        $code_parts  = array_slice($parts, 0, 4);
        $detail_part = isset($parts[4]) ? trim($parts[4]) : '';

        if (function_exists('todo_regions_normalize_code')) {
            $code_parts = array_map('todo_regions_normalize_code', $code_parts);
        }

        if (function_exists('todo_regions_find_name')) {
            foreach ($code_parts as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $n = todo_regions_find_name($p);
                $names[] = $n !== '' ? $n : $p;
            }
        } else {
            if (!empty($code_parts[0])) {
                $provinces = $this->get_provinces();
                $names[] = $provinces[$code_parts[0]] ?? $code_parts[0];
            }
            if (!empty($code_parts[1])) {
                $cities = $this->get_cities($code_parts[0]);
                $names[] = $cities[$code_parts[1]] ?? $code_parts[1];
            }
            if (!empty($code_parts[2])) {
                $districts = $this->get_districts($code_parts[1]);
                $names[] = $districts[$code_parts[2]] ?? $code_parts[2];
            }
            if (!empty($code_parts[3])) {
                $towns = $this->get_towns($code_parts[2]);
                $names[] = $towns[$code_parts[3]] ?? $code_parts[3];
            }
        }

        if ($detail_part !== '') {
            $names[] = $detail_part;
        }

        return implode(' ', $names);
    }

    /**
     * 获取字段搜索SQL
     */
    public function get_search_sql($value) {
        if ($value === '') {
            return false;
        }
        return [
            "meta_key = ? AND meta_value LIKE ?",
            [$this->field['field_key'], '%' . $value . '%']
        ];
    }

    public static function get_type_name() {
        return '区域类型';
    }

    public static function get_type_description() {
        return '适用于选择省市区乡镇及自定义详细地址';
    }

    public static function get_type_icon() {
        return '<i class="custom-icon"></i>';
    }
}

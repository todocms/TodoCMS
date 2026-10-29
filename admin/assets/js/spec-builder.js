/**
 * TodoCMS 产品规格构建器
 * 支持最多3个规格组，所有规格组可附带图片映射
 */
(function(){
    'use strict';
    
    // 工具函数
    function slug(s){
        return (s||'').toString().trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function escAttr(s){
        return (s == null ? '' : String(s))
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/'/g, '&#39;');
    }
    
    // 使用稳定ASCII键生成器，避免中文/特殊字符导致的key碰撞
    function stableKey(vals){
        var SEP = '\u0002';
        // 拼接原始值（不做slug），用不可见分隔符降低碰撞概率
        var s = (vals || []).map(function(v){ return String(v).replace(/\u0001|\u0002/g, ''); }).join(SEP);
        // DJB2哈希，输出无符号32位，再转36进制
        var h = 5381;
        for (var i = 0; i < s.length; i++){
            h = ((h << 5) + h) + s.charCodeAt(i);
            h = h & 0xffffffff;
        }
        return 'k' + (h >>> 0).toString(36);
    }
    
    function qs(sel, ctx){
        return (ctx||document).querySelector(sel);
    }
    
    function qsa(sel, ctx){
        return Array.prototype.slice.call((ctx||document).querySelectorAll(sel));
    }
    
    // 主要变量
    var builder = qs('#todo-specs-builder');
    if(!builder) return;
    
    var groupsWrap = qs('.spec-groups', builder);
    var addBtn = qs('.add-spec-group-btn', groupsWrap);
    var defaultValues = [];
    
    function getGroups(){
        return qsa('.spec-group', builder);
    }
    
    function genUid(p){
        p = p || 'sg_';
        return p + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    
    // 初始化规格组
    function initGroup(g, idx){
        var list = qs('.value-list', g);
        
        // 创建规格芯片
        function createChip(label){
            var span = document.createElement('span');
            span.className = 'chip';
            var txt = document.createElement('span');
            txt.className = 'txt';
            txt.textContent = label;
            var rm = document.createElement('i');
            rm.className = 'rm';
            rm.title = '删除';
            rm.textContent = 'x';
            span.appendChild(txt);
            span.appendChild(rm);
            return span;
        }
        
        // 应用图片控件（勾选添加图片时）
        function applyImageControls(){
            return applyImageControlsFor(g);
        }
        
        // 初始化列表
        if(!list.dataset.inited){
            list.innerHTML = '';
            list.dataset.inited = '1';
        }
        
        // 点击事件处理
        list.addEventListener('click', function(e){
            if(e.target.classList.contains('rm')){
                // 删除规格值
                var chip = e.target.closest('.chip');
                if(chip){
                    chip.remove();
                    recompute();
                }
            } else if(e.target.classList.contains('img-btn')){
                // 选择第一张规格图片
                var chip = e.target.closest('.chip');
                if (!chip) return;
                if (window.selectMediaForField) {
                    window.selectMediaForField({
                        multiple: false,
                        type: 'image',
                        title: '选择规格图片1',
                        button_text: '选择',
                        modal_id: 'spec-media-modal',
                        callback: function(selected){
                            var f = Array.isArray(selected) ? selected[0] : selected;
                            if(!f) return;

                            chip.setAttribute('data-image-id', f.id || '');
                            chip.setAttribute('data-image-url', f.url || '');
                            var th = chip.querySelector('.img-thumb');
                            if(th){
                                th.src = f.url || '';
                                th.style.display = f.url ? '' : 'none';
                            }
                            var pv = chip.querySelector('img.previewimg');
                            if (pv) {
                                pv.remove();
                            }
                            applyImageControls();
                            syncHidden();
                        }
                    });
                } else {
                    console.error('媒体模态框未初始化');
                }
            } else if(e.target.classList.contains('img-rm')){
                // 清除第一张规格图片
                var chip = e.target.closest('.chip');
                if(chip){
                    chip.removeAttribute('data-image-id');
                    chip.removeAttribute('data-image-url');
                    var th = chip.querySelector('.img-thumb');
                    if(th){
                        th.removeAttribute('src');
                        th.style.display = 'none';
                    }
                    var pv2 = chip.querySelector('img.previewimg');
                    if (pv2) {
                        pv2.remove();
                    }
                    applyImageControls();
                    syncHidden();
                }
            } else if(e.target.classList.contains('img-btn-sm')){
                // 选择第二张规格图片（展示用小图 img-thumb-sm）
                var chip = e.target.closest('.chip');
                if (!chip) return;
                if (window.selectMediaForField) {
                    window.selectMediaForField({
                        multiple: false,
                        type: 'image',
                        title: '选择规格图片2(选项小图)',
                        button_text: '选择',
                        modal_id: 'spec-media-modal-sm',
                        callback: function(selected){
                            var f = Array.isArray(selected) ? selected[0] : selected;
                            if(!f) return;

                            chip.setAttribute('data-image-sm-id', f.id || '');
                            chip.setAttribute('data-image-sm-url', f.url || '');
                            var th = chip.querySelector('.img-thumb-sm');
                            if(th){
                                th.src = f.url || '';
                                th.style.display = f.url ? '' : 'none';
                            }
                            var pv = chip.querySelector('img.previewimg');
                            if (pv) {
                                pv.remove();
                            }
                            applyImageControls();
                            syncHidden();
                        }
                    });
                } else {
                    console.error('媒体模态框未初始化');
                }
            } else if(e.target.classList.contains('img-rm-sm')){
                // 清除第二张规格图片
                var chip = e.target.closest('.chip');
                if(chip){
                    chip.removeAttribute('data-image-sm-id');
                    chip.removeAttribute('data-image-sm-url');
                    var th = chip.querySelector('.img-thumb-sm');
                    if(th){
                        th.removeAttribute('src');
                        th.style.display = 'none';
                    }
                    var pv3 = chip.querySelector('img.previewimg');
                    if (pv3) {
                        pv3.remove();
                    }
                    applyImageControls();
                    syncHidden();
                }
            }
        });
        
        // 添加图片复选框事件（所有规格组）
        var imgChk = qs('input[name="add-spec-image"]', g);
        if(imgChk){
            imgChk.addEventListener('change', function(){
                applyImageControls();
                syncHidden();
            });
        }

        // 添加颜色复选框事件（所有规格组）
        var colorChk = qs('input[name="add-spec-color"]', g);
        if(colorChk){
            colorChk.addEventListener('change', function(){
                applyColorControlsFor(g);
                syncHidden();
            });
        }
        
        // 添加规格值
        var input = qs('.new-value-input', g);
        var btn = qs('.add-value-btn', g);
        
        function add(){
            var v = (input.value || '').trim();
            if(!v) return;
            
            // 统一使用 createChip，保证存在 .txt 元素，便于读取与图片绑定
            var span = createChip(v);
            list.appendChild(span);
            input.value = '';
            
            // 若首组已启用“添加图片”/“添加颜色”，为新芯片应用控件
            try { applyImageControls && applyImageControls(); } catch(e){}
            try { applyColorControlsFor(g); } catch(e){}
            
            // 更新规格表与隐藏字段
            recompute();
        }
        
        btn.addEventListener('click', add);
        input.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                add();
            }
        });
        
        // 规格标题变化事件
        g.addEventListener('input', function(e){
            if(e.target.classList.contains('spec-title')){
                recompute();
            }
        });
        
        // 删除规格组
        var del = qs('.del-spec-group', g);
        if(del){
            del.addEventListener('click', function(){
                if(g && g.parentNode){
                    g.parentNode.removeChild(g);
                    renumberGroups();
                    recompute();
                    updateAddBtnState();
                }
            });
        }
    }
    
    // 重新编号规格组
    function renumberGroups(){
        getGroups().forEach(function(g, i){
            g.setAttribute('data-group-index', i);
        });
        // 确保图片复选框逻辑正确
        try { ensureAllGroupsImageToggle(); } catch(e){}
    }

    function bindSpecChipImagePreview(list) {
        if (!list || list.dataset.previewimgBound) return;
        list.dataset.previewimgBound = '1';

        list.addEventListener('mouseover', function(e) {
            var chip = e.target && e.target.closest ? e.target.closest('.chip') : null;
            if (!chip || !list.contains(chip)) return;
            if (e.relatedTarget && chip.contains(e.relatedTarget)) return;

            var target = e.target;
            var thumb = (target && target.classList && target.classList.contains('img-thumb-sm')) 
                ? chip.querySelector('.img-thumb-sm') 
                : (chip.querySelector('.img-thumb') || chip.querySelector('.img-thumb-sm'));
            if (!thumb || !thumb.getAttribute('src')) {
                thumb = chip.querySelector('.img-thumb-sm') || chip.querySelector('.img-thumb');
            }
            if (!thumb || !thumb.getAttribute('src')) return;
            if (!window.TodoCMS || !window.TodoCMS.Utils || !window.TodoCMS.Utils.ImagePreview) return;

            if (!chip.style.position) {
                chip.style.position = 'relative';
            }

            var preview = window.TodoCMS.Utils.ImagePreview.ensureCloneInContainer(chip, {
                previewClass: 'previewimg',
                sourceSelector: '.img-thumb'
            });
            if (!preview) return;

            preview.setAttribute('src', thumb.getAttribute('src'));
            preview.style.position = 'absolute';
            preview.style.bottom = '40px';
            preview.style.left = '0px';
            preview.style.zIndex = '999';
            preview.style.maxWidth = '300px';
            preview.style.maxHeight = '300px';
            preview.style.minWidth = '100px';
            preview.style.minHeight = '100px';
            preview.style.backgroundColor = 'rgba(0, 0, 0, 0.3)';
            preview.style.padding = '10px';
            preview.style.borderRadius = '8px';
            preview.style.display = 'block';
            preview.style.pointerEvents = 'none';

            window.TodoCMS.Utils.ImagePreview.adjustPosition(chip, { previewSelector: 'img.previewimg', edgeCompensation: -30 });
        });

        list.addEventListener('mouseout', function(e) {
            var chip = e.target && e.target.closest ? e.target.closest('.chip') : null;
            if (!chip || !list.contains(chip)) return;
            if (e.relatedTarget && chip.contains(e.relatedTarget)) return;

            var pv = chip.querySelector('img.previewimg');
            if (pv) {
                pv.style.display = 'none';
            }
            if (window.TodoCMS && window.TodoCMS.Utils && window.TodoCMS.Utils.ImagePreview) {
                window.TodoCMS.Utils.ImagePreview.resetPosition(chip, { previewSelector: 'img.previewimg' });
            }
        });
    }
    
    // 为指定规格组应用/移除图片控件（根据是否已勾选"添加图片"）
    function applyImageControlsFor(g){
        if(!g) return;
        var list = qs('.value-list', g);
        if(!list) return;
        var chk = qs('input[name="add-spec-image"]', g);
        var allow = chk && chk.checked;
        if (allow) {
            bindSpecChipImagePreview(list);
        }
        qsa('.chip', list).forEach(function(span){
            var btn = span.querySelector('.img-btn');
            var rmBtn = span.querySelector('.img-rm');
            var thumb = span.querySelector('.img-thumb');
            var btnSm = span.querySelector('.img-btn-sm');
            var rmBtnSm = span.querySelector('.img-rm-sm');
            var thumbSm = span.querySelector('.img-thumb-sm');
            var anchor = span.querySelector('.txt');

            if(allow){
                // 第一张图 img-thumb
                if(!thumb){
                    thumb = document.createElement('img');
                    thumb.className = 'img-thumb';
                    thumb.style.maxWidth = '32px';
                    thumb.style.maxHeight = '32px';
                    thumb.style.verticalAlign = 'middle';
                    thumb.style.marginRight = '2px';
                    span.insertBefore(thumb, anchor);
                }
                if(!btn){
                    btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'img-btn btn button-link';
                    btn.textContent = '+图1';
                    span.insertBefore(btn, anchor);
                } else {
                    btn.textContent = '+图1';
                }
                if(!rmBtn){
                    rmBtn = document.createElement('button');
                    rmBtn.type = 'button';
                    rmBtn.className = 'img-rm btn button-link';
                    rmBtn.textContent = '-';
                    span.insertBefore(rmBtn, anchor);
                } else {
                    rmBtn.textContent = '-';
                }

                // 第二张图 img-thumb-sm
                if(!thumbSm){
                    thumbSm = document.createElement('img');
                    thumbSm.className = 'img-thumb-sm';
                    thumbSm.style.maxWidth = '32px';
                    thumbSm.style.maxHeight = '32px';
                    thumbSm.style.verticalAlign = 'middle';
                    thumbSm.style.marginRight = '2px';
                    span.insertBefore(thumbSm, anchor);
                }
                if(!btnSm){
                    btnSm = document.createElement('button');
                    btnSm.type = 'button';
                    btnSm.className = 'img-btn-sm btn button-link';
                    btnSm.textContent = '+图2';
                    span.insertBefore(btnSm, anchor);
                } else {
                    btnSm.textContent = '+图2';
                }
                if(!rmBtnSm){
                    rmBtnSm = document.createElement('button');
                    rmBtnSm.type = 'button';
                    rmBtnSm.className = 'img-rm-sm btn button-link';
                    rmBtnSm.textContent = '-';
                    span.insertBefore(rmBtnSm, anchor);
                } else {
                    rmBtnSm.textContent = '-';
                }

                // 恢复/更新第一张图片显示
                if(thumb){
                    var _url = span.getAttribute('data-image-url');
                    if(_url){ thumb.src = _url; thumb.style.display = ''; }
                    else if(span.getAttribute('data-image-id')){ thumb.style.display = ''; }
                    else { thumb.style.display = 'none'; }
                }

                // 恢复/更新第二张图片显示
                if(thumbSm){
                    var _smUrl = span.getAttribute('data-image-sm-url');
                    if(_smUrl){ thumbSm.src = _smUrl; thumbSm.style.display = ''; }
                    else if(span.getAttribute('data-image-sm-id')){ thumbSm.style.display = ''; }
                    else { thumbSm.style.display = 'none'; }
                }
            } else {
                if(btn) btn.remove();
                if(rmBtn) rmBtn.remove();
                if(thumb) thumb.remove();
                if(btnSm) btnSm.remove();
                if(rmBtnSm) rmBtnSm.remove();
                if(thumbSm) thumbSm.remove();
                var pv = span.querySelector('img.previewimg');
                if (pv) {
                    pv.remove();
                }
                span.removeAttribute('data-image-id');
                span.removeAttribute('data-image-url');
                span.removeAttribute('data-image-sm-id');
                span.removeAttribute('data-image-sm-url');
            }
        });
    }
    
    // 为指定规格组应用/移除颜色控件（根据是否已勾选"添加颜色"）
    function applyColorControlsFor(g){
        if(!g) return;
        var list = qs('.value-list', g);
        if(!list) return;
        var chk = qs('input[name="add-spec-color"]', g);
        var allow = chk && chk.checked;
        qsa('.chip', list).forEach(function(span){
            var picker = span.querySelector('.color-picker');
            var valInp = span.querySelector('.color-val');
            var anchor = span.querySelector('.txt');
            if(allow){
                if(!picker){
                    picker = document.createElement('input');
                    picker.type = 'color';
                    picker.className = 'color-picker';
                    picker.title = '选择颜色';
                    picker.style.cssText = 'width:26px;min-width: 26px;height:30px;min-height: 30px;padding:0;border:none;cursor:pointer;vertical-align:middle;margin:0;border-radius:100%;background:none;';
                    span.insertBefore(picker, anchor);
                }
                if(!valInp){
                    valInp = document.createElement('input');
                    valInp.type = 'text';
                    valInp.className = 'color-val';
                    valInp.placeholder = '#000000';
                    valInp.title = '色值';
                    valInp.style.cssText = 'width:62px;min-width: 62px;padding:0 3px;vertical-align:middle;margin:0;box-sizing:border-box;';
                    span.insertBefore(valInp, anchor);
                }

                var curColor = span.getAttribute('data-color-val') || '';
                valInp.value = curColor;
                if(/^#([0-9a-fA-F]{3}){1,2}$/.test(curColor)){
                    var pVal = curColor;
                    if(pVal.length === 4){
                        pVal = '#' + pVal[1] + pVal[1] + pVal[2] + pVal[2] + pVal[3] + pVal[3];
                    }
                    picker.value = pVal;
                } else if(!curColor) {
                    picker.value = '#000000';
                }

                var syncColor = function(colorVal){
                    if(colorVal){
                        span.setAttribute('data-color-val', colorVal);
                    } else {
                        span.removeAttribute('data-color-val');
                    }
                    syncHidden();
                };

                picker.oninput = function(){
                    valInp.value = picker.value;
                    syncColor(picker.value);
                };

                valInp.oninput = function(){
                    var v = valInp.value.trim();
                    if(/^#([0-9a-fA-F]{3}){1,2}$/.test(v)){
                        var pVal = v;
                        if(pVal.length === 4){
                            pVal = '#' + pVal[1] + pVal[1] + pVal[2] + pVal[2] + pVal[3] + pVal[3];
                        }
                        picker.value = pVal;
                    }
                    syncColor(v);
                };
            } else {
                if(picker) picker.remove();
                if(valInp) valInp.remove();
                span.removeAttribute('data-color-val');
            }
        });
    }

    // 确保所有规格组都有"添加图片"和"添加颜色"复选框
    function ensureAllGroupsImageToggle(){
        var gs = getGroups();
        if(!gs.length) return;
        gs.forEach(function(g, i){
            var header = qs('.spec-group-header', g);
            if(!header) return;
            var chk = qs('input[name="add-spec-image"]', g);
            if(!chk){
                var label = document.createElement('label');
                label.className = 'flex items-center gap-1 m-0';
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'add-spec-image';
                input.className = 'btn';
                input.title = '为该规格的规格值添加图片';
                label.appendChild(input);
                label.appendChild(document.createTextNode('图片'));
                header.appendChild(label);
                input.addEventListener('change', function(){
                    applyImageControlsFor(g);
                    syncHidden();
                });
            }
            var chkColor = qs('input[name="add-spec-color"]', g);
            if(!chkColor){
                var labelC = document.createElement('label');
                labelC.className = 'flex items-center gap-1 m-0';
                var inputC = document.createElement('input');
                inputC.type = 'checkbox';
                inputC.name = 'add-spec-color';
                inputC.className = 'btn';
                inputC.title = '为该规格的规格值添加颜色';
                labelC.appendChild(inputC);
                labelC.appendChild(document.createTextNode('颜色'));
                header.appendChild(labelC);
                inputC.addEventListener('change', function(){
                    applyColorControlsFor(g);
                    syncHidden();
                });
            }
            // 同步控件显隐
            applyImageControlsFor(g);
            applyColorControlsFor(g);
        });
    }
    
    // 更新添加按钮状态
    function updateAddBtnState(){
        var gs = getGroups();
        if(addBtn){
            if(gs.length >= 3){
                addBtn.disabled = true;
                addBtn.classList.add('disabled');
            } else {
                addBtn.disabled = false;
                addBtn.classList.remove('disabled');
            }
        }
    }
    
    // 添加新规格组
    function addGroup(){
        var gs = getGroups();
        if(gs.length >= 3){
            if (typeof TodoCMS !== 'undefined' && TodoCMS.Utils && TodoCMS.Utils.showMessage) {
                TodoCMS.Utils.showMessage('最多支持三个规格组', 'warning');
            } else {
                console.warn('TodoCMS.Utils not loaded. Message: 最多支持三个规格组');
            }
            return;
        }
        
        var idx = gs.length;
        var uid = genUid('sg_');
        
        var html = 
            '<div class="spec-group" data-group-index="' + idx + '" data-group-uid="' + uid + '">' +
            '<div class="spec-group-item">' +
            '<div class="spec-group-header flex gap-2 items-center flex-wrap">' +
            '<input type="text" class="spec-title" id="spec-title-' + uid + '" value="" placeholder="规格名称">' +
            '<span class="rm del-spec-group" title="删除规格组">x</span>' +
            '<label class="flex items-center gap-1 m-0"><input type="checkbox" name="add-spec-image" class="btn" title="为该规格的规格值添加图片">图片</label>' +
            '<label class="flex items-center gap-1 m-0"><input type="checkbox" name="add-spec-color" class="btn" title="为该规格的规格值添加颜色">颜色</label>' +
            '</div>' +
            '<div class="spec-values" style="margin: 10px 0 0 40px;">' +
            '<div class="value-list flex gap-x-8 gap-y-2 items-center flex-wrap"></div>' +
            '<div class="add-value flex gap-2 items-center flex-wrap">' +
            '<input type="text" class="new-value-input" id="new-value-input-' + uid + '">' +
            '<button type="button" class="btn add-value-btn">✚ 增加规格值</button>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>';
        
        var temp = document.createElement('div');
        temp.innerHTML = html;
        var g = temp.firstChild;
        
        groupsWrap.insertBefore(g, addBtn);
        renumberGroups();
        initGroup(g, idx);
        recompute();
        updateAddBtnState();
    }
    
    // 添加规格组按钮事件
    if(addBtn){
        addBtn.addEventListener('click', addGroup);
    }
    updateAddBtnState();
    
    // 删除规格组事件委托
    groupsWrap.addEventListener('click', function(e){
        if(e.target.classList.contains('del-spec-group')){
            var g = e.target.closest('.spec-group');
            if(g && g.parentNode){
                g.parentNode.removeChild(g);
                renumberGroups();
                recompute();
                updateAddBtnState();
            }
        }
    });
    
    // 初始化现有规格组（统一走 initGroup，保证图片控件等逻辑一致）
    getGroups().forEach(function(g, idx){
        initGroup(g, idx);
    });
    
    // 读取规格组数据
    function readGroup(idx){
        var g = getGroups()[idx];
        if(!g) return {title: '', values: []};
        
        var title = (qs('.spec-title', g).value || '').trim() || ('规格' + (idx + 1));
        var values = qsa('.value-list .chip', g).map(function(n){
            var t = n.querySelector('.txt');
            return t ? (t.textContent || '').trim() : ((n.childNodes[0] && n.childNodes[0].nodeValue) ? n.childNodes[0].nodeValue.trim() : '');
        });
        
        return {title: title, values: values};
    }

    function collectCurrentDetailsMap(){
        var map = {};
        qsa('#todo-spec-rows tr', builder).forEach(function(tr){
            var h0 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value]"]');
            var h1 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value2]"]');
            var h2 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value3]"]');
            var v0 = h0 ? (h0.value || '') : '';
            var v1 = h1 ? (h1.value || '') : '';
            var v2 = h2 ? (h2.value || '') : '';

            var vals = [v0, v1, v2];
            while (vals.length && (vals[vals.length - 1] === '' || vals[vals.length - 1] == null)) {
                vals.pop();
            }
            if (!vals.length) {
                return;
            }
            var key = stableKey(vals);

            var ip = function(sel){ var el = tr.querySelector(sel); return el ? el.value : ''; };
            map[key] = {
                users_price: ip('input.users_price'),
                wholesale_price: ip('input.wholesale_price'),
                wholesale_vip_price: ip('input.wholesale_vip_price'),
                crossed_price: ip('input.crossed_price'),
                goods_code: ip('input.goods_code'),
                stock_count: ip('input.stock_count'),
                spec_sales_num: ip('input.spec_sales_num')
            };
        });
        return map;
    }
    
    // 重新计算规格表格
    function recompute(){
        var prevDetails = collectCurrentDetailsMap();
        var gs = getGroups().map(function(_, i){
            return readGroup(i);
        });
        
        // 更新表头
        var head = qs('#todo-spec-head-row', builder);
        head.innerHTML = '';
        
        gs.forEach(function(g, i){
            var th = document.createElement('th');
            th.style.minWidth = '100px';
            th.textContent = g.title || ('规格' + (i + 1));
            head.appendChild(th);
        });
        
        // 固定列 + 批量设置按钮
        [
            {label: '现售价', field: 'users_price'},
            {label: '批发价', field: 'wholesale_price'},
            {label: '高级批发价', field: 'wholesale_vip_price'},
            {label: '原价', field: 'crossed_price'},
            {label: '商品编码', field: 'goods_code'},
            {label: '库存', field: 'stock_count'},
            {label: '虚拟销量', field: 'spec_sales_num'}
        ].forEach(function(col){
            var th = document.createElement('th');
            var span = document.createElement('span');
            span.textContent = col.label;
            th.appendChild(span);
            
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn button-link bulk-btn';
            btn.textContent = '✎';
            btn.setAttribute('data-field', col.field);
            btn.style.margin = '0 -5px 0 5px';
            btn.style.padding = '2px 5px';
            btn.style.transform = 'rotateY(180deg)';
            th.appendChild(btn);
            
            head.appendChild(th);
        });
        
        // 更新表格内容
        var tbody = qs('#todo-spec-rows', builder);
        tbody.innerHTML = '';
        
        var arrays = gs.map(function(g){
            return g.values || [];
        });
        
        if(arrays.length === 0 || arrays.some(function(a){
            return a.length === 0;
        })){
            syncHidden();
            return;
        }
        
        // 笛卡尔积计算（保证前置规格为慢变维度，生成的组合按规格1→规格2→...的块状顺序）
        function cartesian(arrs){
            return arrs.reduce(function(acc, curr){
                var res = [];
                // 先遍历已有组合，再遍历当前规格值，保证之前的规格值形成连续块
                acc.forEach(function(prev){
                    curr.forEach(function(v){
                        res.push(prev.concat([v]));
                    });
                });
                return res;
            }, [[]]);
        }
        
        var combos = cartesian(arrays);
        
        // 计算每个规格值的rowspan - 分组计算（按前置规格分组）
        var rowspans = [];
        for (var i = 0; i < arrays.length; i++) {
            rowspans[i] = {};
        }
        
        // 为每个组合计算规格值的显示信息（以“前置规格组”为作用域记录首次出现）
        combos.forEach(function(combo, rowIndex){
            combo.forEach(function(value, specIndex){
                // 前置规格的分组键（规格0..specIndex-1）
                var groupKey = '';
                if (specIndex > 0) {
                    groupKey = stableKey(combo.slice(0, specIndex));
                }
                if (!rowspans[specIndex][groupKey]) {
                    rowspans[specIndex][groupKey] = {};
                }
                if (!rowspans[specIndex][groupKey][value]) {
                    // 计算该规格值后续所有规格的组合数（跨行数）
                    var repeatCount = 1;
                    for (var j = specIndex + 1; j < arrays.length; j++){
                        repeatCount *= arrays[j].length;
                    }
                    rowspans[specIndex][groupKey][value] = {
                        firstRow: rowIndex,
                        span: repeatCount
                    };
                }
            });
        });
        
        combos.forEach(function(vals, rowIndex){
            // 使用稳定键避免中文跨行错位（不要对原值slug，直接由稳定哈希生成）
            var key = stableKey(vals);
            var prev = prevDetails && prevDetails[key] ? prevDetails[key] : null;
            
            var tr = document.createElement('tr');
            var cells = '';
            
            vals.forEach(function(v, idx){
                var hiddenName = idx === 0 ? 'spec_value' : ('spec_value' + (idx + 1));
                // 生成分组键：用当前行的前置规格值作为作用域
                var groupKey = '';
                if (idx > 0) {
                    groupKey = stableKey(vals.slice(0, idx));
                }
                var rowspanInfo = rowspans[idx][groupKey] ? rowspans[idx][groupKey][v] : null;
                
                // 每行都需要隐藏字段
                cells += '<input type="hidden" name="spec_value_id[' + key + '][' + hiddenName + ']" value="' + v + '">';
                
                // 显示策略：
                // - 最后一个规格：每行都显示
                // - 非最后一个规格：仅在其所属分组的首次出现行显示，并加上分组内的 rowspan
                if (idx === arrays.length - 1) {
                    cells += '<td><span>' + v + '</span></td>';
                } else if (rowspanInfo && rowspanInfo.firstRow === rowIndex) {
                    var rowspanAttr = rowspanInfo.span > 1 ? ' rowspan="' + rowspanInfo.span + '"' : '';
                    cells += '<td' + rowspanAttr + '><span>' + v + '</span></td>';
                }
            });
            
            var users_price = prev ? (prev.users_price || '') : '';
            var wholesale_price = prev ? (prev.wholesale_price || '') : '';
            var wholesale_vip_price = prev ? (prev.wholesale_vip_price || '') : '';
            var crossed_price = prev ? (prev.crossed_price || '') : '';
            var goods_code = prev ? (prev.goods_code || '') : '';
            var stock_count = prev ? (prev.stock_count || '0') : '0';
            var spec_sales_num = prev ? (prev.spec_sales_num || '0') : '0';

            cells += '<td><input type="text" class="users_price" name="spec_price[' + key + '][users_price]" value="' + escAttr(users_price) + '"></td>';
            cells += '<td><input type="text" class="wholesale_price" name="spec_wholesale_price[' + key + '][wholesale_price]" value="' + escAttr(wholesale_price) + '"></td>';
            cells += '<td><input type="text" class="wholesale_vip_price" name="spec_wholesale_vip_price[' + key + '][wholesale_vip_price]" value="' + escAttr(wholesale_vip_price) + '"></td>';
            cells += '<td><input type="text" class="crossed_price" name="spec_crossed_price[' + key + '][crossed_price]" value="' + escAttr(crossed_price) + '"></td>';
            cells += '<td><input type="text" class="goods_code" name="spec_goods_code[' + key + '][goods_code]" value="' + escAttr(goods_code) + '"></td>';
            cells += '<td><input type="text" class="stock_count" name="spec_stock[' + key + '][stock_count]" value="' + escAttr(stock_count) + '"></td>';
            cells += '<td><input type="text" class="spec_sales_num" name="spec_sales[' + key + '][spec_sales_num]" value="' + escAttr(spec_sales_num) + '"></td>';
            
            tr.innerHTML = cells;
            tbody.appendChild(tr);
        });
        
        syncHidden();
    }
    
    // 同步隐藏字段
    function syncHidden(){
        var sc = document.getElementById('spec_config');
        var sd = document.getElementById('spec_details');
        
        if(!sc){
            sc = document.createElement('textarea');
            sc.name = 'spec_config';
            sc.id = 'spec_config';
            sc.style.display = 'none';
            builder.appendChild(sc);
        }
        
        if(!sd){
            sd = document.createElement('textarea');
            sd.name = 'spec_details';
            sd.id = 'spec_details';
            sd.style.display = 'none';
            builder.appendChild(sd);
        }
        
        var gs = getGroups().map(function(_, i){
            return readGroup(i);
        });
        
        // 构建规格配置（支持最多3组），每组可附带图片映射
        var cfg = gs.map(function(g, i){
            var o = {title: g.title, values: g.values};
            
            var fg = getGroups()[i];
            if(fg){
                var chk = qs('input[name="add-spec-image"]', fg);
                if(chk && chk.checked){
                    var map = {};
                    var urlMap = {};
                    var smMap = {};
                    var smUrlMap = {};
                    qsa('.value-list .chip', fg).forEach(function(span){
                        var label = span.querySelector('.txt') ? 
                            span.querySelector('.txt').textContent.trim() : 
                            (span.childNodes[0] ? span.childNodes[0].nodeValue.trim() : '');
                        var id = span.getAttribute('data-image-id') || '';
                        var url = span.getAttribute('data-image-url') || '';
                        var smId = span.getAttribute('data-image-sm-id') || '';
                        var smUrl = span.getAttribute('data-image-sm-url') || '';
                        if(label){
                            var key = stableKey([label]);
                            if(id){ map[key] = id; }
                            if(url){ urlMap[key] = url; }
                            if(smId){ smMap[key] = smId; }
                            if(smUrl){ smUrlMap[key] = smUrl; }
                        }
                    });
                    
                    if(Object.keys(map).length || Object.keys(urlMap).length || Object.keys(smMap).length || Object.keys(smUrlMap).length){
                        if(Object.keys(map).length) o.images = map; // 兼容：保留原结构
                        if(Object.keys(urlMap).length) o.image_urls = urlMap;
                        if(Object.keys(smMap).length) o.images_sm = smMap;
                        if(Object.keys(smUrlMap).length) o.image_sm_urls = smUrlMap;
                        o.with_images = true;
                    }
                }
                
                var chkColor = qs('input[name="add-spec-color"]', fg);
                if(chkColor && chkColor.checked){
                    var colorMap = {};
                    qsa('.value-list .chip', fg).forEach(function(span){
                        var label = span.querySelector('.txt') ? 
                            span.querySelector('.txt').textContent.trim() : 
                            (span.childNodes[0] ? span.childNodes[0].nodeValue.trim() : '');
                        var colorVal = span.getAttribute('data-color-val') || '';
                        if(label && colorVal){
                            var key = stableKey([label]);
                            colorMap[key] = colorVal;
                        }
                    });
                    o.colors = colorMap;
                    o.with_colors = true;
                }
            }
            
            return o;
        });
        
        // 构建规格明细行（行合并安全）：从每行隐藏域与输入框读取，避免因 rowspan 导致的列偏移
        var rows = [];
        qsa('#todo-spec-rows tr', builder).forEach(function(tr){
            var r = {};
            // 读取规格值：优先从每行的隐藏域，保证不受 rowspan 影响
            var h0 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value]"]');
            var h1 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value2]"]');
            var h2 = tr.querySelector('input[name^="spec_value_id"][name$="[spec_value3]"]');
            r.v0 = h0 ? (h0.value || '') : '';
            r.v1 = h1 ? (h1.value || '') : '';
            r.v2 = h2 ? (h2.value || '') : '';

            // 读取明细字段：直接在当前行内按 class 查询
            var ip = function(sel){ var el = tr.querySelector(sel); return el ? el.value : ''; };
            r.users_price    = ip('input.users_price');
            r.wholesale_price = ip('input.wholesale_price');
            r.wholesale_vip_price = ip('input.wholesale_vip_price');
            r.crossed_price  = ip('input.crossed_price');
            r.goods_code     = ip('input.goods_code');
            r.stock_count    = ip('input.stock_count');
            r.spec_sales_num = ip('input.spec_sales_num');

            rows.push(r);
        });
        
        sc.value = JSON.stringify(cfg);
        sd.value = JSON.stringify(rows);
    }

    // 首次加载时，尝试从隐藏域回填规格UI与明细
    function prefillFromHidden(){
        var scEl = document.getElementById('spec_config');
        var sdEl = document.getElementById('spec_details');
        var cfg = null, details = null;
        try { cfg = scEl && scEl.value ? JSON.parse(scEl.value) : null; } catch(e){ cfg = null; }
        try { details = sdEl && sdEl.value ? JSON.parse(sdEl.value) : null; } catch(e){ details = null; }

        // 若仅有明细，尝试从明细反推规格配置
        if(!Array.isArray(cfg) && Array.isArray(details)){
            var maxCols = 0;
            details.forEach(function(r){
                for(var i=0;i<3;i++){
                    if (typeof r['v'+i] !== 'undefined' && r['v'+i] !== ''){
                        if (i+1 > maxCols) maxCols = i+1;
                    }
                }
            });
            cfg = [];
            for(var i=0;i<maxCols;i++){
                var set = {};
                details.forEach(function(r){ var v = r['v'+i]; if(v){ set[v] = 1; } });
                cfg.push({title: '规格' + (i+1), values: Object.keys(set)});
            }
        }

        if(Array.isArray(cfg) && cfg.length){
            // 清理现有规格组
            getGroups().forEach(function(g){ if(g && g.parentNode){ g.parentNode.removeChild(g); } });

            // 按配置重建规格组（最多3组）
            cfg.slice(0,3).forEach(function(grp, i){
                var uid = genUid('sg_');
                var html =
                    '<div class="spec-group" data-group-index="' + i + '" data-group-uid="' + uid + '">' +
                    '<div class="spec-group-item">' +
                    '<div class="spec-group-header flex gap-2 items-center flex-wrap">' +
                    '<input type="text" class="spec-title" id="spec-title-' + uid + '" value="" placeholder="规格名称">' +
                    '<span class="rm del-spec-group" title="删除规格组">x</span>' +
                    '</div>' +
                    '<div class="spec-values" style="margin: 10px 0 0 40px;">' +
                    '<div class="value-list flex gap-x-8 gap-y-2 items-center flex-wrap"></div>' +
                    '<div class="add-value flex gap-2 items-center flex-wrap">' +
                    '<input type="text" class="new-value-input" id="new-value-input-' + uid + '">' +
                    '<button type="button" class="btn add-value-btn">✚ 增加规格值</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var g = tmp.firstChild;
                groupsWrap.insertBefore(g, addBtn);
                // 绑定事件
                initGroup(g, i);

                // 设置标题
                var ti = qs('.spec-title', g);
                if(ti){ ti.value = (grp && grp.title) ? grp.title : ('规格' + (i+1)); }

                // 设置芯片
                var list = qs('.value-list', g);
                if(list){
                    list.innerHTML = '';
                    (grp && Array.isArray(grp.values) ? grp.values : []).forEach(function(v){
                        var span = document.createElement('span');
                        span.className = 'chip';
                        var txt = document.createElement('span'); txt.className = 'txt'; txt.textContent = v;
                        var rm = document.createElement('i'); rm.className = 'rm'; rm.title = '删除'; rm.textContent = 'x';
                        span.appendChild(txt); span.appendChild(rm);
                        list.appendChild(span);
                    });
                }
            });

            // 所有规格组图片回填
            renumberGroups();
            ensureAllGroupsImageToggle();
            getGroups().forEach(function(fg, idx){
                if(cfg[idx]){
                    var imgs = cfg[idx].images || {};
                    var urls = cfg[idx].image_urls || {};
                    var smImgs = cfg[idx].images_sm || {};
                    var smUrls = cfg[idx].image_sm_urls || {};
                    var withImg = !!(cfg[idx].with_images || (imgs && Object.keys(imgs).length) || (smUrls && Object.keys(smUrls).length));
                    var toggle = qs('input[name="add-spec-image"]', fg);
                    if(toggle){ toggle.checked = withImg; }
                    applyImageControlsFor(fg);
                    if(withImg){
                        qsa('.value-list .chip', fg).forEach(function(span){
                            var labelEl = span.querySelector('.txt');
                            var label = labelEl ? labelEl.textContent.trim() : '';
                            var k1 = stableKey([label]);
                            var k2 = slug(label);
                            var id = imgs[k1] || imgs[k2] || '';
                            var url = (urls && (urls[k1] || urls[k2])) || '';
                            var smId = (smImgs && (smImgs[k1] || smImgs[k2])) || '';
                            var smUrl = (smUrls && (smUrls[k1] || smUrls[k2])) || '';
                            if(id){ span.setAttribute('data-image-id', id); }
                            if(url){ span.setAttribute('data-image-url', url); }
                            if(smId){ span.setAttribute('data-image-sm-id', smId); }
                            if(smUrl){ span.setAttribute('data-image-sm-url', smUrl); }
                        });
                        applyImageControlsFor(fg);
                    }

                    var colors = cfg[idx].colors || {};
                    var withColor = !!(cfg[idx].with_colors || (colors && Object.keys(colors).length));
                    var toggleColor = qs('input[name="add-spec-color"]', fg);
                    if(toggleColor){ toggleColor.checked = withColor; }
                    applyColorControlsFor(fg);
                    if(withColor){
                        qsa('.value-list .chip', fg).forEach(function(span){
                            var labelEl = span.querySelector('.txt');
                            var label = labelEl ? labelEl.textContent.trim() : '';
                            var k1 = stableKey([label]);
                            var k2 = slug(label);
                            var colorVal = colors[k1] || colors[k2] || '';
                            if(colorVal){ span.setAttribute('data-color-val', colorVal); }
                        });
                        applyColorControlsFor(fg);
                    }
                }
            });

            // 重建表格并填充明细
            recompute();

            if(Array.isArray(details)){
                details.forEach(function(r){
                    var vals = [];
                    for(var i=0;i<3;i++){
                        if(typeof r['v'+i] !== 'undefined') vals.push(r['v'+i]);
                    }
                    while(vals.length && (vals[vals.length-1] === '' || vals[vals.length-1] == null)) vals.pop();
                    if(!vals.length) return;
                    var key = stableKey(vals);
                    function setInput(name, val){
                        var el = builder.querySelector('input[name="' + name + '"]');
                        if(el){ el.value = val; }
                    }
                    setInput('spec_price[' + key + '][users_price]', r.users_price || '');
                    setInput('spec_wholesale_price[' + key + '][wholesale_price]', r.wholesale_price || '');
                    setInput('spec_wholesale_vip_price[' + key + '][wholesale_vip_price]', r.wholesale_vip_price || '');
                    setInput('spec_crossed_price[' + key + '][crossed_price]', r.crossed_price || '');
                    setInput('spec_goods_code[' + key + '][goods_code]', r.goods_code || '');
                    setInput('spec_stock[' + key + '][stock_count]', r.stock_count || '0');
                    setInput('spec_sales[' + key + '][spec_sales_num]', r.spec_sales_num || '0');
                });
                // 同步隐藏域（保存时可直接提交）
                syncHidden();
            }

            updateAddBtnState();
            return; // 已完成预填
        }

        // 没有配置，保持默认并同步一次隐藏域
        recompute();
    }
    
    // 为现有规格组添加输入事件
    getGroups().forEach(function(g){
        g.addEventListener('input', function(e){
            if(e.target.classList.contains('spec-title')){
                recompute();
            }
        });
    });
    
    // 批量设置函数
    window.todoBulkSet = function(field){
        var labelMap = {
            users_price: '现售价',
            wholesale_price: '批发价',
            wholesale_vip_price: '高级批发价',
            crossed_price: '原价',
            goods_code: '商品编码',
            stock_count: '库存',
            spec_sales_num: '虚拟销量'
        };
        var fieldName = labelMap[field] || field;

        if (typeof TodoModal !== 'undefined') {
            var modal = new TodoModal({
                size: 'sm',
                title: '批量设置 - ' + fieldName,
                destroyOnClose: true
            });
            
            var content = '<div class="form-group">' +
                          '<label style="display:block;margin-bottom:5px;">请输入' + fieldName + '：</label>' +
                          '<input type="text" class="form-control" id="bulk-set-input" placeholder="输入新值" style="width:100%;box-sizing:border-box;">' +
                          '</div>';
            
            modal.setContent(content);
            
            modal.setFooter([
                {
                    text: '取消',
                    class: 'button button-secondary',
                    handler: function() {
                        return true; // Close modal
                    }
                },
                {
                    text: '确定',
                    class: 'button button-primary',
                    handler: function() {
                        var input = modal.element.querySelector('#bulk-set-input');
                        var v = input ? input.value : '';
                        
                        // Apply value
                        qsa('#todo-spec-rows .' + field, builder).forEach(function(i){
                            i.value = v;
                            try{ i.dispatchEvent(new Event('input', {bubbles: true})); }catch(e){}
                            try{ i.dispatchEvent(new Event('change', {bubbles: true})); }catch(e){}
                        });
                        
                        syncHidden();
                        return true; // Close modal
                    }
                }
            ]);
            
            modal.open();
            
            // Focus input and handle Enter key
            setTimeout(function() {
                var input = modal.element.querySelector('#bulk-set-input');
                if(input) {
                    input.focus();
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            // Trigger confirm button click
                            var confirmBtn = modal.element.querySelector('.todo-modal-footer .button-primary');
                            if (confirmBtn) confirmBtn.click();
                        }
                    });
                }
            }, 100);
            
        } else {
            console.warn('TodoModal not loaded. Bulk set feature unavailable.');
        }
    };

    // 绑定批量设置按钮（事件委托）
    builder.addEventListener('click', function(e){
        var btn = e.target.closest('.bulk-btn');
        if(btn && builder.contains(btn)){
            e.preventDefault();
            var field = btn.getAttribute('data-field');
            if(field){
                window.todoBulkSet(field);
            }
        }
    });

    // 手动输入变更 -> 同步隐藏域，确保保存（批量设置之外的单个输入也能保存）
    function isSpecFieldInput(el){
        if(!el) return false;
        if(!el.closest) return false;
        if(!el.closest('#todo-spec-rows')) return false;
        return (
            el.classList.contains('users_price') ||
            el.classList.contains('wholesale_price') ||
            el.classList.contains('wholesale_vip_price') ||
            el.classList.contains('crossed_price') ||
            el.classList.contains('goods_code') ||
            el.classList.contains('stock_count') ||
            el.classList.contains('spec_sales_num')
        );
    }
    builder.addEventListener('input', function(e){
        if(isSpecFieldInput(e.target)){
            // 直接同步隐藏域，避免PRG前后不一致
            syncHidden();
        }
    });
    builder.addEventListener('change', function(e){
        if(isSpecFieldInput(e.target)){
            syncHidden();
        }
    });
    
    // 初始化（优先从隐藏域回填已有数据）
    prefillFromHidden();
    
})();

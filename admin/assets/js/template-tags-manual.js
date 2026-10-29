(function () {
  var data = window.__TODO_TEMPLATE_TAGS_MANUAL__ || null;
  if (!data) return;

  var $tree = document.getElementById('todoTagTree');
  var $content = document.getElementById('todoTagContent');
  var $search = document.getElementById('todoTagSearch');
  var $prev = document.getElementById('todoTagPrev');
  var $next = document.getElementById('todoTagNext');
  var baseUrl = window.__TODO_TEMPLATE_TAGS_MANUAL_PAGE_URL__ || '';

  var tagMap = Object.create(null);
  (data.tags || []).forEach(function (t) {
    tagMap[t.name] = t;
  });
  var overrides = (data && data.overrides) ? data.overrides : {};

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function scrollToTop() {
    var $inner = document.querySelector('.todo-tag-manual-content-inner');
    if ($inner) {
      $inner.scrollTop = 0;
    }
    if (typeof window.scrollTo === 'function') {
      window.scrollTo(0, 0);
    }
    if (document && document.documentElement) {
      document.documentElement.scrollTop = 0;
    }
    if (document && document.body) {
      document.body.scrollTop = 0;
    }
    try {
      if (window.parent && window.parent !== window && window.parent.document) {
        var main = window.parent.document.getElementById('todo-admin-main');
        var docEl = window.parent.document.documentElement;
        var body = window.parent.document.body;
        if (main) main.scrollTop = 0;
        if (docEl) docEl.scrollTop = 0;
        if (body) body.scrollTop = 0;
        if (typeof window.parent.scrollTo === 'function') {
          window.parent.scrollTo(0, 0);
        }
        var iframe = window.parent.document.getElementById('todo-admin-iframe');
        if (iframe && iframe.scrollIntoView) iframe.scrollIntoView(true);

        if (typeof window.requestAnimationFrame === 'function') {
          window.requestAnimationFrame(function () {
            try {
              if (main) main.scrollTop = 0;
              if (docEl) docEl.scrollTop = 0;
              if (body) body.scrollTop = 0;
              if (typeof window.parent.scrollTo === 'function') window.parent.scrollTo(0, 0);
            } catch (e2) {
            }
          });
        }
      }
    } catch (e) {
    }
  }

  function buildParamRows(rows) {
    if (!rows || !rows.length) {
      return '<div class="todo-empty">暂无可用属性描述</div>';
    }
    var html = '<table class="todo-param-table"><thead><tr><th style="width:100px">属性</th><th style="width:100px">类型</th><th>说明</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      html += '<tr><td><code>' + escapeHtml(r.name) + '</code></td><td>' + escapeHtml(r.type) + '</td><td>' + escapeHtml(r.desc) + '</td></tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function usageFor(tag) {
    var name = tag.name;
    var bare = name.replace(/^todo:/, '');

    // 块标签：开始 + 结束
    if (tag.is_block) {
      return '{{todo:' + bare + '}}\n  ...\n{{/todo:' + bare + '}}';
    }

    // 所有其他标签统一双括号格式
    return '{{todo:' + bare + '}}';
  }

  function matchTag(name, q) {
    if (!q) return true;
    var t = tagMap[name];
    var ov = (overrides && overrides[name]) ? overrides[name] : null;

    // 1. 匹配标签英文名（如 post_title, loop）
    if (name.toLowerCase().indexOf(q) !== -1) return true;

    // 2. 匹配分组名（如 '文章 / 内容', '页面 / SEO'）
    var group = (t && t.group ? t.group : (ov && ov.group ? ov.group : '')).toLowerCase();
    if (group.indexOf(q) !== -1) return true;

    if (ov) {
      // 3. 匹配使用场景 tag（如 '详情页', '文章列表页', '首页'）
      var tagScene = '';
      if (Array.isArray(ov.tag)) {
        tagScene = ov.tag.join(' ').toLowerCase();
      } else if (typeof ov.tag === 'string') {
        tagScene = ov.tag.toLowerCase();
      }
      if (tagScene.indexOf(q) !== -1) return true;

      // 4. 匹配标题 title（如 '文章标题 post_title'）
      if (ov.title && ov.title.toLowerCase().indexOf(q) !== -1) return true;

      // 5. 匹配简介说明 intro
      if (ov.intro && ov.intro.toLowerCase().indexOf(q) !== -1) return true;

      // 6. 匹配属性 params 说明
      if (Array.isArray(ov.params)) {
        for (var i = 0; i < ov.params.length; i++) {
          var p = ov.params[i];
          if (p && ((p.name && p.name.toLowerCase().indexOf(q) !== -1) || (p.desc && p.desc.toLowerCase().indexOf(q) !== -1))) {
            return true;
          }
        }
      }

      // 7. 匹配使用示例与注意事项
      var notes = (ov.notes || []).join('\n').toLowerCase();
      if (notes.indexOf(q) !== -1) return true;
      var usage = (Array.isArray(ov.usage) ? ov.usage : [ov.usage || '']).join('\n').toLowerCase();
      if (usage.indexOf(q) !== -1) return true;
    }

    // 8. 匹配规范代码片段 snippets
    if (t && t.snippets) {
      var sn = t.snippets.join('\n').toLowerCase();
      if (sn.indexOf(q) !== -1) return true;
    }

    return false;
  }

  function computeFlatList(filterText) {
    var q = (filterText || '').trim().toLowerCase();
    var all = (data.tags || []).map(function (t) { return t.name; });
    if (!q) return all;
    return all.filter(function (name) {
      return matchTag(name, q);
    });
  }

  function renderTree(activeName, filterText) {
    var q = (filterText || '').trim().toLowerCase();
    var groups = data.groups || {};
    var html = '';
    Object.keys(groups).sort(function (a, b) {
      return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }).forEach(function (groupName) {
      var list = groups[groupName] || [];
      var filtered = list.filter(function (name) {
        return matchTag(name, q);
      });
      if (!filtered.length) return;
      html += '<div class="todo-tag-group" data-group="' + escapeHtml(groupName) + '">';
      html += '<div class="todo-tag-group-title" data-toggle="1"><span>' + escapeHtml(groupName) + '</span><span class="count">' + filtered.length + '</span></div>';
      html += '<ul class="todo-tag-list">';
      filtered.forEach(function (name) {
        var ov = overrides && overrides[name];
        var titleAttr = (ov && ov.title) ? escapeHtml(ov.title) : name;
        html += '<li class="todo-tag-item"><a href="javascript:void(0);" data-tag="' + escapeHtml(name) + '" title="' + titleAttr + '" class="' + (name === activeName ? 'active' : '') + '">' + escapeHtml(name) + '</a></li>';
      });
      html += '</ul></div>';
    });
    $tree.innerHTML = html || '<div class="todo-empty">未找到匹配的标签</div>';
  }

  function updateNav(activeName, filterText) {
    var flat = computeFlatList(filterText);
    var idx = flat.indexOf(activeName);
    var prevName = idx > 0 ? flat[idx - 1] : '';
    var nextName = (idx >= 0 && idx < flat.length - 1) ? flat[idx + 1] : '';

    function setLink($a, name) {
      if (!name) {
        $a.setAttribute('href', '#');
        $a.setAttribute('aria-disabled', 'true');
        $a.classList.add('disabled');
        $a.textContent = $a.id === 'todoTagPrev' ? '上一篇' : '下一篇';
        return;
      }
      $a.classList.remove('disabled');
      $a.removeAttribute('aria-disabled');
      $a.setAttribute('href', '#');
      $a.setAttribute('data-tag', name);
      $a.textContent = ($a.id === 'todoTagPrev' ? '上一篇：' : '下一篇：') + name;
    }

    setLink($prev, prevName);
    setLink($next, nextName);
  }

  function renderContent(name) {
    var tag = tagMap[name];
    if (!tag) {
      $content.innerHTML = '<div class="todo-empty">未找到标签：' + escapeHtml(name) + '</div>';
      return;
    }

    var ov = overrides && overrides[name] ? overrides[name] : null;
    var params = (data.params && data.params.by_tag && data.params.by_tag[name]) || [];
    var globalParams = (data.params && data.params.global) || [];
    var snippetHtml = '';
    if (tag.snippets && tag.snippets.length) {
      snippetHtml = '<div class="todo-tag-section"><h3>规范片段</h3>' +
        '<div class="todo-snippet">' + escapeHtml(tag.snippets.join('\n')) + '</div></div>';
    }

    var html = '';
    var title = ov && ov.title ? ov.title : name;
    html += '<h2 class="todo-tag-card-title">' + escapeHtml(title) + '</h2>';
    var metaParts = [];
    metaParts.push('分类：' + escapeHtml(tag.group || '-'));
    if (tag.source) metaParts.push('来源：' + escapeHtml(tag.source));
    if (tag.line) metaParts.push('规范：第 ' + escapeHtml(tag.line) + ' 行');
    html += '<div class="todo-tag-meta">' + metaParts.join(' · ') + '</div>';

    // 渲染使用场景 tag Badges
    if (ov && ov.tag) {
      var rawTag = ov.tag;
      var scenes = Array.isArray(rawTag) ? rawTag : String(rawTag).split(/[,，]/);
      var sceneBadges = [];
      scenes.forEach(function (s) {
        s = s.trim();
        if (s) {
          sceneBadges.push('<span class="todo-scene-badge" data-scene="' + escapeHtml(s) + '" title="点击按此场景筛选标签">' + escapeHtml(s) + '</span>');
        }
      });
      if (sceneBadges.length) {
        html += '<div class="todo-tag-scenes"><span class="scene-label">适用场景</span>' + sceneBadges.join('') + '</div>';
      }
    }

    if (ov && ov.intro) {
      html += '<div class="todo-tag-section"><h3>介绍</h3><div class="todo-tag-desc">' + escapeHtml(ov.intro).replace(/\n/g, '<br>') + '</div></div>';
    }

    var usages = null;
    if (ov && ov.usage) {
      usages = Array.isArray(ov.usage) ? ov.usage : [ov.usage];
    } else {
      usages = [usageFor(tag)];
    }
    html += '<div class="todo-tag-section"><h3>基础用法</h3>';
    usages.forEach(function (u, idx) {
      if (!u) return;
      html += '<pre class="todo-code">' + escapeHtml(String(u)) + '</pre>';
    });
    html += '</div>';

    var paramRows = null;
    if (ov && ov.params && ov.params.length) {
      paramRows = ov.params;
    } else {
      paramRows = params.length ? params : globalParams;
    }
    html += '<div class="todo-tag-section"><h3>属性</h3>' + buildParamRows(paramRows) + '</div>';

    var examples = null;
    if (ov && ov.examples) {
      examples = Array.isArray(ov.examples) ? ov.examples : [ov.examples];
    } else {
      examples = [usageFor(tag)];
    }
    html += '<div class="todo-tag-section"><h3>示例</h3>';
    examples.forEach(function (ex) {
      if (!ex) return;
      html += '<pre class="todo-code">' + escapeHtml(String(ex)) + '</pre>';
    });
    html += '</div>';

    if (ov && ov.notes && ov.notes.length) {
      html += '<div class="todo-tag-section"><h3>注意事项</h3><ul class="todo-tag-notes">';
      ov.notes.forEach(function (n) {
        if (!n) return;
        html += '<li>' + escapeHtml(String(n)) + '</li>';
      });
      html += '</ul></div>';
    }

    if (ov && ov.related && ov.related.length) {
      html += '<div class="todo-tag-section"><h3>相关标签</h3><div class="todo-tag-related">';
      ov.related.forEach(function (r) {
        if (!r) return;
        html += '<a href="javascript:void(0);" data-tag="' + escapeHtml(String(r)) + '" class="todo-related-link">' + escapeHtml(String(r)) + '</a> ';
      });
      html += '</div></div>';
    }

    html += snippetHtml;

    $content.innerHTML = html;
  }

  function setActive(name, opts) {
    opts = opts || {};
    if (!name || !tagMap[name]) {
      var first = (data.tags && data.tags[0] && data.tags[0].name) ? data.tags[0].name : '';
      name = first;
    }
    var q = $search ? ($search.value || '') : '';
    renderTree(name, q);
    renderContent(name);
    updateNav(name, q);

    if (opts.scrollTop !== false) {
      scrollToTop();
    }

    if (!opts.silent && baseUrl) {
      try {
        var url = new URL(baseUrl, window.location.origin);
        url.searchParams.set('tag', name);
        if (window.location.search.indexOf('format=iframe') !== -1) {
          url.searchParams.set('format', 'iframe');
        }
        window.history.replaceState({}, '', url.toString());
      } catch (e) {
      }
    }
  }

  function onTreeClick(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-tag]') : null;
    if (a && a.getAttribute) {
      e.preventDefault();
      var name = a.getAttribute('data-tag') || '';
      setActive(name);
      return;
    }
    var t = e.target && e.target.closest ? e.target.closest('.todo-tag-group-title') : null;
    if (t) {
      var group = t.parentElement;
      var ul = group ? group.querySelector('.todo-tag-list') : null;
      if (ul) {
        ul.style.display = (ul.style.display === 'none') ? '' : 'none';
      }
    }
  }

  function onNavClick(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-tag]') : null;
    if (!a) return;
    if (a.classList.contains('disabled')) return;
    e.preventDefault();
    var name = a.getAttribute('data-tag') || '';
    setActive(name);
  }

  if ($tree) {
    $tree.addEventListener('click', onTreeClick);
  }
  if ($prev) $prev.addEventListener('click', onNavClick);
  if ($next) $next.addEventListener('click', onNavClick);

  function syncQuickFilters(q) {
    var $filters = document.querySelectorAll('.todo-tag-quick-filters .quick-filter');
    if (!$filters || !$filters.length) return;
    var normQ = (q || '').trim();
    $filters.forEach(function ($btn) {
      var target = ($btn.getAttribute('data-q') || '').trim();
      if ((!normQ && !target) || (target && normQ === target)) {
        $btn.classList.add('active');
      } else {
        $btn.classList.remove('active');
      }
    });
  }

  function triggerSearch(q) {
    syncQuickFilters(q);
    var list = computeFlatList(q || '');
    var active = list.length ? list[0] : '';
    setActive(active, { silent: true, scrollTop: false });
  }

  // 点击场景徽章或相关标签链接
  if ($content) {
    $content.addEventListener('click', function (e) {
      var badge = e.target && e.target.closest ? e.target.closest('.todo-scene-badge[data-scene]') : null;
      if (badge) {
        var scene = badge.getAttribute('data-scene') || '';
        if (scene && $search) {
          $search.value = scene;
          triggerSearch(scene);
        }
        return;
      }
      var a = e.target && e.target.closest ? e.target.closest('a.todo-related-link[data-tag]') : null;
      if (!a) return;
      e.preventDefault();
      var name = a.getAttribute('data-tag') || '';
      if (name) setActive(name);
    });
  }

  // 快捷场景标签过滤按钮
  var $quickFilterContainer = document.querySelector('.todo-tag-quick-filters');
  if ($quickFilterContainer) {
    $quickFilterContainer.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.quick-filter[data-q]') : null;
      if (!btn) return;
      var q = btn.getAttribute('data-q') || '';
      if ($search) $search.value = q;
      triggerSearch(q);
    });
  }

  if ($search) {
    var timer = null;
    $search.addEventListener('input', function () {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        triggerSearch($search.value || '');
      }, 120);
    });
  }

  var initial = (window.__TODO_TEMPLATE_TAGS_MANUAL_ACTIVE__ || '').trim();
  if (!initial) {
    try {
      var u = new URL(window.location.href);
      initial = (u.searchParams.get('tag') || '').trim();
    } catch (e) {
    }
  }
  setActive(initial, { silent: true, scrollTop: false });
})();

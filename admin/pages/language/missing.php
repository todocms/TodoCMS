<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 缺失翻译报表
 * 
 * @package TodoCMS
 * @subpackage Language
 */

// 访问控制
if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
    if (function_exists('auth_redirect')) {
        auth_redirect();
    } else {
        $login_target = function_exists('todo_admin_login_proxy_url') ? todo_admin_login_proxy_url() : todo_admin_url('login.php');
        todo_redirect($login_target);
    }
    exit;
}

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!todo_current_user_can('manage_languages')) {
    todo_admin_notice('您没有权限执行该操作', 'error');
    todo_redirect(todo_admin_url('index.php?page=language'));
    exit;
}

$registry = new LanguageRegistry();
$manager  = todo_language_manager();

global $tododb;

// 处理提交（PRG）
$action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
if ($action) {
    if (!Todo_Security_Manager::verify_request_csrf('manage_missing')) {
        todo_admin_notice('CSRF校验失败，请重试', 'error');
        $redirect = $_SERVER['REQUEST_URI'];
        todo_redirect($redirect);
        exit;
    }
}
if ($action === 'bulk_status') {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $status = sanitize_text_field($_POST['status'] ?? 'pending');
    $count = 0;
    foreach ($ids as $id) {
        $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
        if (!$row) { continue; }
        $tododb->update($tododb->prefix . 'language_translations', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        if ($manager && method_exists($manager, 'clear_translation_cache')) {
            $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
        }
        $count++;
    }
    todo_admin_notice('已更新状态：' . $count . ' 条', 'success');
    $redirect = $_SERVER['REQUEST_URI'];
    todo_redirect($redirect);
    exit;
}

if ($action === 'bulk_delete') {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $count = 0;
    foreach ($ids as $id) {
        $row = $tododb->get_row("SELECT term_id, language_code FROM {$tododb->prefix}language_translations WHERE id = ?", [$id]);
        if (!$row) { continue; }
        $tododb->delete($tododb->prefix . 'language_translations', ['id' => $id]);
        $tododb->delete($tododb->prefix . 'language_cache', ['translation_id' => $id]);
        if ($manager && method_exists($manager, 'clear_translation_cache')) {
            $manager->clear_translation_cache(intval($row->term_id), sanitize_text_field($row->language_code));
        }
        $count++;
    }
    todo_admin_notice('已删除：' . $count . ' 条', 'success');
    $redirect = $_SERVER['REQUEST_URI'];
    todo_redirect($redirect);
    exit;
}
// 查询参数
$lang   = sanitize_text_field($_GET['language_code'] ?? '');
$status = sanitize_text_field($_GET['status'] ?? 'pending');
$search = sanitize_text_field($_GET['s'] ?? '');
$page   = max(1, intval($_GET['paged'] ?? 1));
$per    = 20;
$offset = ($page - 1) * $per;

$where  = "WHERE t.status = ?";
$params = [$status];
if ($lang !== '') { $where .= " AND t.language_code = ?"; $params[] = $lang; }
if ($search !== '') {
    $where .= " AND (terms.original_text LIKE ? OR terms.term_key LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
}

$total = intval($tododb->get_var(
    "SELECT COUNT(*) FROM {$tododb->prefix}language_translations t
     LEFT JOIN {$tododb->prefix}language_terms terms ON t.term_id = terms.id
     {$where}",
    $params
));

$rows = $tododb->get_results(
    "SELECT t.id, t.term_id, t.language_code, t.status, t.translated_text, terms.original_text, terms.term_key
     FROM {$tododb->prefix}language_translations t
     LEFT JOIN {$tododb->prefix}language_terms terms ON t.term_id = terms.id
     {$where}
     ORDER BY t.id DESC
     LIMIT {$per} OFFSET {$offset}",
    $params
);

$supported = array_keys(LanguageRegistry::get_instance()->get_languages(true));

// 后台头部已由主入口加载，此处通常不需要重复加载，或者使用 todo-functions 中的组件
// require_once ABSPATH . 'admin/includes/header.php';
?>
<div class="wrap">
  <h1>缺失翻译报表</h1>
  <p>统计所有待翻译的词条，支持按语言/关键字筛选，提供批量状态更新与删除。</p>

  <form method="get" action="" class="todo-request">
    <input type="hidden" name="page" value="language/missing"/>
    <label>语言：
      <select name="language_code">
        <option value="">全部</option>
        <?php foreach ($supported as $l): $code = $l['locale']; ?>
        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $lang===$code?'selected':''; ?>><?php echo htmlspecialchars($code); ?><?php if(!empty($l['title'])) echo ' - ' . htmlspecialchars($l['title']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>状态：
      <select name="status">
        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>待翻译</option>
        <option value="approved" <?php echo $status==='approved'?'selected':''; ?>>已完成</option>
      </select>
    </label>
    <label>搜索：<input type="text" name="s" value="<?php echo htmlspecialchars($search); ?>"/></label>
    <button type="submit" class="button">筛选</button>
  </form>

  <form method="post" action="" class="todo-request">
    <?php echo Todo_Security_Manager::csrf_field('manage_missing'); ?>
    <input type="hidden" name="page" value="language/missing"/>
    <div style="margin:10px 0;">
      <select name="action">
        <option value="bulk_status">批量设置状态</option>
        <option value="bulk_delete">批量删除</option>
      </select>
      <select name="status">
        <option value="pending">待翻译</option>
        <option value="approved">已完成</option>
      </select>
      <button type="submit" class="button">执行</button>
    </div>
    <table class="widefat fixed" cellspacing="0">
      <thead>
        <tr>
          <th class="check-column"><input type="checkbox" onclick="var c=this.checked;document.querySelectorAll('.chk-id').forEach(function(x){x.checked=c;});"/></th>
          <th>ID</th>
          <th>语言</th>
          <th>原文</th>
          <th>term_key</th>
          <th>译文</th>
          <th>状态</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><input class="chk-id" type="checkbox" name="ids[]" value="<?php echo intval($r->id); ?>"/></td>
          <td><?php echo intval($r->id); ?></td>
          <td><?php echo htmlspecialchars($r->language_code); ?></td>
          <td><?php echo htmlspecialchars($r->original_text); ?></td>
          <td><?php echo htmlspecialchars($r->term_key); ?></td>
          <td><?php echo htmlspecialchars($r->translated_text); ?></td>
          <td><?php echo htmlspecialchars($r->status); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>

  <script type="text/javascript">
    (function(){
      var form = document.querySelector('form[action=""][method="post"]');
      if (!form) return;
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var actionSel = form.querySelector('select[name="action"]');
        var statusSel = form.querySelector('select[name="status"]');
        var ids = Array.prototype.slice.call(form.querySelectorAll('.chk-id:checked')).map(function(x){return x.value;});
        if (!ids.length) { TodoModal.warning('请选择至少一条记录'); return; }
        var csrfInput = form.querySelector('input[name="_csrf_token"]');
        var payload = new FormData();
        payload.append('action', actionSel.value === 'bulk_status' ? 'mark_status' : 'delete');
        if (actionSel.value === 'bulk_status') { payload.append('status', statusSel.value); }
        ids.forEach(function(id){ payload.append('ids[]', id); });
        if (csrfInput) { payload.append('_csrf_token', csrfInput.value); }
        fetch('admin/ajax/language/missing.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: payload
        }).then(function(res){ return res.json(); }).then(function(json){
          if (json && json.success) {
            TodoModal.toast('操作成功', 'success');
            setTimeout(function() {
              // PRG: redirect to current page to avoid resubmission
              window.location.href = window.location.href.replace(/#.*$/, '');
            }, 500);
          } else {
            TodoModal.error('操作失败：' + (json && json.data ? json.data : '未知错误'));
          }
        }).catch(function(err){
          TodoModal.error('请求失败：' + err);
        });
      });
    })();
  </script>

  <?php
    $pages = max(1, ceil($total / $per));
    if ($pages > 1):
      $base = 'index.php?page=language/missing';
      $qs = [];
      if ($lang !== '') { $qs['language_code'] = $lang; }
      if ($status !== '') { $qs['status'] = $status; }
      if ($search !== '') { $qs['s'] = $search; }
  ?>
  <p>共 <?php echo $total; ?> 条，页码：
    <?php for ($i=1; $i<=$pages; $i++):
      $url = todo_admin_url($base . '&' . http_build_query(array_merge($qs, ['paged'=>$i])));
    ?>
      <a class="button <?php echo $i===$page?'button-primary':''; ?>" href="<?php echo htmlspecialchars($url); ?>">第 <?php echo $i; ?> 页</a>
    <?php endfor; ?>
  </p>
  <?php endif; ?>
</div>
<?php require_once ABSPATH . 'admin/includes/footer.php'; ?>

<?php
// 加载Composer autoloader
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

/**
 * 跨语言编辑视图
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

// 权限检查
if (!todo_current_user_can('manage_languages')) {
    todo_admin_notice('您没有权限执行该操作', 'error');
    todo_redirect(todo_admin_url('index.php?page=language'));
    exit;
}

// 防止页面缓存，便于PRG模式提示
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 权限检查
    todo_admin_notice('您没有权限执行该操作', 'error');
    todo_redirect(todo_admin_url('index.php?page=language'));
    exit;

// 加载语言相关类
$registry = LanguageRegistry::get_instance();
$manager  = todo_language_manager();

global $tododb;

// 处理提交（PRG）
$action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
if ($action === 'save_cross_translations') {
    if (!Todo_Security_Manager::verify_request_csrf('manage_translations')) {
        todo_admin_notice('CSRF校验失败，请重试', 'error');
        $redirect = $_SERVER['REQUEST_URI'];
        todo_redirect($redirect);
        exit;
    }

    $term_id = intval($_POST['term_id'] ?? 0);
    $items   = isset($_POST['translations']) && is_array($_POST['translations']) ? $_POST['translations'] : [];

    if ($term_id <= 0) {
        todo_admin_notice('缺少 term_id 参数', 'error');
        $redirect = $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect, true, 303);
        exit;
    }

    $updated = 0;
    foreach ($items as $lang => $payload) {
        $language_code = $registry->normalize_locale(sanitize_text_field($lang));
        $text   = isset($payload['translated_text']) ? (string)$payload['translated_text'] : '';
        $notes  = isset($payload['translator_notes']) ? (string)$payload['translator_notes'] : '';
        $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : 'pending';
        if ($text === '' && $status === 'approved') {
            // 没有译文不允许标记为已完成
            $status = 'pending';
        }
        // upsert
        $exists = $tododb->get_var(
            "SELECT id FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
            [$term_id, $language_code]
        );
        $data = [
            'term_id' => $term_id,
            'language_code' => $language_code,
            'translated_text' => $text,
            'translator_notes' => $notes,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($exists) {
            $tododb->update($tododb->prefix . 'language_translations', $data, ['id' => intval($exists)]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $tododb->insert($tododb->prefix . 'language_translations', $data);
        }
        $updated++;
        // 清理缓存
        if ($manager && method_exists($manager, 'clear_translation_cache')) {
            $manager->clear_translation_cache($term_id, $language_code);
        }
    }

    todo_admin_notice('已保存 ' . $updated . ' 条跨语言翻译', 'success');
    // PRG：重定向
    $redirect = $_SERVER['REQUEST_URI'];
    // 确保URL包含term_id参数
    if (strpos($redirect, 'term_id=') === false) {
        $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 'term_id=' . intval($term_id);
    }
    todo_redirect($redirect);
    exit;
}

// 查询数据
$term_id = intval($_GET['term_id'] ?? 0);
$search  = sanitize_text_field($_GET['search'] ?? '');

$current_term = null;
if ($term_id > 0) {
    $current_term = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}language_terms WHERE id = ?",
        [$term_id]
    );
}

// 若未指定 term_id，尝试通过搜索定位
if (!$current_term && $search !== '') {
    $current_term = $tododb->get_row(
        "SELECT * FROM {$tododb->prefix}language_terms WHERE original_text LIKE ? OR term_key = ? ORDER BY id DESC LIMIT 1",
        ['%' . $search . '%', $search]
    );
    if ($current_term) { $term_id = intval($current_term->id); }
}

$supported = $registry->get_supported_locales(true);

// 准备每个语言的翻译
$translations = [];
if ($current_term) {
    foreach ($supported as $lang) {
        $code = $lang['locale'];
        $row = $tododb->get_row(
            "SELECT translated_text, translator_notes, status FROM {$tododb->prefix}language_translations WHERE term_id = ? AND language_code = ?",
            [$term_id, $code]
        );
        $translations[$code] = [
            'translated_text' => $row->translated_text ?? '',
            'translator_notes' => $row->translator_notes ?? '',
            'status' => $row->status ?? 'pending'
        ];
    }
}

// 渲染页面（简洁表单）
require_once ABSPATH . 'admin/includes/header.php';
?>
<div class="wrap">
  <h1>跨语言编辑视图</h1>
  <a href="javascript:void(0);" class="page-title-action" onclick="try{if(document.referrer){window.location.href=document.referrer;}else{window.history.back();}}catch(e){window.history.back();} return false;">返回上一页</a>
  <p>在一个视图中编辑同一词条的多语言翻译，支持保存后PRG防重复提交。</p>

  <form method="get" action="">
    <input type="hidden" name="page" value="language/cross-edit">
    <label>Term ID：<input type="number" name="term_id" value="<?php echo intval($term_id); ?>"/></label>
    <label>搜索（original_text/term_key）：<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"/></label>
    <button type="submit" class="button">定位</button>
  </form>

  <?php if ($current_term): ?>
  <hr/>
  <h2>词条信息</h2>
  <p>ID：<?php echo intval($current_term->id); ?> | term_key：<?php echo htmlspecialchars($current_term->term_key); ?> | 原文：<?php echo htmlspecialchars($current_term->original_text); ?></p>

  <form method="post" action="" class="todo-request">
    <?php echo Todo_Security_Manager::csrf_field('manage_translations'); ?>
    <input type="hidden" name="page" value="language/cross-edit"/>
    <input type="hidden" name="action" value="save_cross_translations"/>
    <input type="hidden" name="term_id" value="<?php echo intval($term_id); ?>"/>

    <table class="widefat fixed" cellspacing="0">
      <thead>
        <tr>
          <th>语言代码</th>
          <th>翻译文本</th>
          <th>译者备注</th>
          <th>状态</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($supported as $lang): $code = $lang['locale']; $info = $translations[$code] ?? ['translated_text'=>'','translator_notes'=>'','status'=>'pending']; ?>
        <tr>
          <td><?php echo htmlspecialchars($code); ?> <?php if (!empty($lang['title'])) echo '(' . htmlspecialchars($lang['title']) . ')'; ?></td>
          <td>
            <textarea name="translations[<?php echo htmlspecialchars($code); ?>][translated_text]" rows="2" style="width:100%"><?php echo htmlspecialchars($info['translated_text']); ?></textarea>
          </td>
          <td>
            <input type="text" name="translations[<?php echo htmlspecialchars($code); ?>][translator_notes]" value="<?php echo htmlspecialchars($info['translator_notes']); ?>" style="width:100%"/>
          </td>
          <td>
            <select name="translations[<?php echo htmlspecialchars($code); ?>][status]">
              <option value="pending" <?php echo $info['status']==='pending'?'selected':''; ?>>待翻译</option>
              <option value="approved" <?php echo $info['status']==='approved'?'selected':''; ?>>已完成</option>
            </select>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><button type="submit" class="button button-primary">保存全部语言</button></p>
  </form>

  <script type="text/javascript">
    (function(){
      var form = document.querySelector('form[action=""][method="post"]');
      if (!form) return;
      form.addEventListener('submit', function(e){
        e.preventDefault();
        var termIdInput = form.querySelector('input[name="term_id"]');
        var csrfInput = form.querySelector('input[name="_csrf_token"]');
        if (!termIdInput || !termIdInput.value) { TodoModal.warning('Term ID缺失'); return; }
        var payload = new FormData(form);
        payload.set('action', 'save_batch');
        fetch('admin/ajax/language/translations.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: payload
        }).then(function(res){ return res.json(); }).then(function(json){
          if (json && json.success) {
            TodoModal.toast('保存成功', 'success');
            setTimeout(function() {
              // PRG: redirect to GET to avoid重复提交
              var url = window.location.href.replace(/#.*$/, '');
              window.location.href = url;
            }, 500);
          } else {
            TodoModal.error('保存失败：' + (json && json.data ? json.data : '未知错误'));
          }
        }).catch(function(err){
          TodoModal.error('请求失败：' + err);
        });
      });
    })();
  </script>

  <?php else: ?>
    <p>请先通过Term ID或搜索定位到具体词条。</p>
  <?php endif; ?>
</div>
<?php require_once ABSPATH . 'admin/includes/footer.php'; ?>

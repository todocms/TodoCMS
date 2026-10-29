<?php
/**
 * 通用弹窗组件
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 防止直接访问
if (!defined('IS_ADMIN') && !defined('DOING_AJAX') && !defined('DISABLE_DIRECT_ACCESS_PROTECTION')) {
    exit('Direct access not permitted');
}

class TodoModal {
    
    /**
     * 渲染通用确认弹窗
     *
     * @param array $options 弹窗选项
     * @return string HTML内容
     */
    public static function confirm($options = array()) {
        $defaults = array(
            'title' => '确认操作',
            'message' => '确定要执行此操作吗？',
            'confirm_text' => '确认',
            'cancel_text' => '取消',
            'type' => 'default', // default, danger
            'size' => 'sm', // sm, md, lg, xl
            'modal_id' => 'confirm-modal'
        );
        
        $options = array_merge($defaults, $options);
        
        $type_class = $options['type'] === 'danger' ? ' todo-modal-danger' : '';
        $size_class = 'todo-modal-' . $options['size'];
        ?>
        <div id="<?php echo esc_attr($options['modal_id']); ?>" class="todo-modal-overlay">
            <div class="todo-modal-container <?php echo esc_attr($size_class . $type_class); ?>">
                <div class="todo-modal-header">
                    <h3 class="todo-modal-title"><?php echo esc_html($options['title']); ?></h3>
                    <button type="button" class="todo-modal-close" aria-label="关闭">✕</button>
                </div>
                <div class="todo-modal-body">
                    <div class="todo-modal-message" style="text-align: center; padding: 20px 0;">
                        <?php echo todo_kses_post($options['message']); ?>
                    </div>
                </div>
                <div class="todo-modal-footer">
                    <button type="button" class="button button-secondary todo-modal-cancel"><?php echo esc_html($options['cancel_text']); ?></button>
                    <button type="button" class="button button-primary todo-modal-confirm"><?php echo esc_html($options['confirm_text']); ?></button>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const modalId = '<?php echo esc_js($options['modal_id']); ?>';
            const modal = (window.TodoDOM && typeof window.TodoDOM.get === 'function')
                ? window.TodoDOM.get(modalId)
                : (document.getElementById(modalId) || (window.parent && window.parent.document ? window.parent.document.getElementById(modalId) : null));

            if (!modal) return;

            const doc = modal.ownerDocument || document;
            const closeBtn = modal.querySelector('.todo-modal-close');
            const cancelBtn = modal.querySelector('.todo-modal-cancel');
            const confirmBtn = modal.querySelector('.todo-modal-confirm');
            
            // 关闭弹窗函数
            function closeModal() {
                modal.classList.remove('show');
                doc.body.style.overflow = '';
                doc.body.classList.remove('show-modal');
                doc.body.removeAttribute('data-overlay');
            }
            
            // 绑定关闭事件
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
            
            // 点击背景关闭
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC键关闭
            doc.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeModal();
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 渲染信息提示弹窗
     *
     * @param array $options 弹窗选项
     * @return string HTML内容
     */
    public static function alert($options = array()) {
        $defaults = array(
            'title' => '提示',
            'message' => '',
            'button_text' => '确定',
            'type' => 'default',
            'modal_id' => 'alert-modal'
        );
        
        $options = array_merge($defaults, $options);
        ?>
        <div id="<?php echo esc_attr($options['modal_id']); ?>" class="todo-modal-overlay">
            <div class="todo-modal-container todo-modal-sm">
                <div class="todo-modal-header">
                    <h3 class="todo-modal-title"><?php echo esc_html($options['title']); ?></h3>
                    <button type="button" class="todo-modal-close" aria-label="关闭">✕</button>
                </div>
                <div class="todo-modal-body">
                    <div class="todo-modal-message" style="text-align: center; padding: 20px 0;">
                        <?php echo todo_kses_post($options['message']); ?>
                    </div>
                </div>
                <div class="todo-modal-footer">
                    <button type="button" class="button button-primary todo-modal-confirm"><?php echo esc_html($options['button_text']); ?></button>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const modalId = '<?php echo esc_js($options['modal_id']); ?>';
            const modal = (window.TodoDOM && typeof window.TodoDOM.get === 'function')
                ? window.TodoDOM.get(modalId)
                : (document.getElementById(modalId) || (window.parent && window.parent.document ? window.parent.document.getElementById(modalId) : null));

            if (!modal) return;

            const doc = modal.ownerDocument || document;
            const closeBtn = modal.querySelector('.todo-modal-close');
            const confirmBtn = modal.querySelector('.todo-modal-confirm');
            
            // 关闭弹窗函数
            function closeModal() {
                modal.classList.remove('show');
                doc.body.style.overflow = '';
                doc.body.classList.remove('show-modal');
                doc.body.removeAttribute('data-overlay');
            }
            
            // 绑定关闭事件
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (confirmBtn) confirmBtn.addEventListener('click', closeModal);
            
            // 点击背景关闭
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            
            // ESC键关闭
            doc.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeModal();
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 显示弹窗的JavaScript代码
     *
     * @param string $modal_id 弹窗ID
     * @return string JavaScript代码
     */
    public static function show_script($modal_id) {
        return "<script type=\"text/javascript\">
            document.addEventListener('DOMContentLoaded', function() {
                const modalId = '{$modal_id}';
                const modal = (window.TodoDOM && typeof window.TodoDOM.get === 'function')
                    ? window.TodoDOM.get(modalId)
                    : (document.getElementById(modalId) || (window.parent && window.parent.document ? window.parent.document.getElementById(modalId) : null));
                if (modal) {
                    const doc = modal.ownerDocument || document;
                    modal.classList.add('show');
                    doc.body.style.overflow = 'hidden';
                    doc.body.classList.add('show-modal');
                    if(!doc.body.getAttribute('data-overlay')) {
                        doc.body.setAttribute('data-overlay', modalId);
                    }
                }
            });
        </script>";
    }
}
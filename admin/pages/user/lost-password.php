<?php
// 确保 REQUEST_METHOD 存在
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
/**
 * Admin Lost Password Form
 *
 * @package TodoCMS
 * @version 1.0.0
 */

// 加载核心文件，但不包含admin.php（它会强制要求登录）
require_once dirname(dirname(dirname(__DIR__))) . '/index.php';

// 直接包含header，而不是通过admin.php
require_once dirname(__DIR__) . '/../templates/header.php';

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $email = todo_sanitize_email($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // In a real implementation, you would:
        // 1. Check if email exists in user database
        // 2. Generate reset token
        // 3. Send password reset email
        // 4. Show success message

        $success = 'If an account exists with this email, you will receive a password reset link';
    }
}
?>

<div class="wrap">
    <h1>Reset Password</h1>

    <?php if (isset($error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($error); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($success); ?></p>
            <button type="button" class="notice-dismiss">✕</button>
        </div>
    <?php endif; ?>

    <form method="post">
        <table class="form-table">
            <tr>
                <th><label for="email">Email Address</label></th>
                <td>
                    <input type="email" name="email" id="email" class="regular-text" required>
                    <p class="description">Enter your account email address</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Reset Password">
        </p>
    </form>
</div>

<?php
require_once dirname(__DIR__) . '/../admin-footer.php';

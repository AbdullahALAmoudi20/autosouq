<a href="reset_password.php" class="btn btn-quiet" data-dialog="forgot-password">Forgot password?</a>
<dialog class="action-dialog" id="forgot-password" aria-labelledby="forgot-title">
    <h2 id="forgot-title" tabindex="-1">Reset your password</h2>
    <p>Enter your account email. Your reset link expires in 15 minutes.</p>
    <form method="post" action="reset_password.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_link">
        <div class="field">
            <label for="reset-email">Account email</label>
            <input id="reset-email" name="email" type="email" autocomplete="email"
                   value="<?= e((string) ($user['email'] ?? '')) ?>" required>
        </div>
        <div class="dialog-actions">
            <button type="submit" class="btn btn-primary">Email reset link</button>
            <button type="button" class="btn btn-quiet" data-close>Cancel</button>
        </div>
    </form>
    <?php if (is_page('account.php')): ?>
        <p><a href="account.php?edit_password=1#password-heading">I know my current password</a></p>
    <?php endif; ?>
</dialog>

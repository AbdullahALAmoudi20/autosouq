<section class="panel account-panel" id="test-drives">
<h2>Test drives</h2>
<?php if (!$drives): ?><p>No test-drive requests yet.</p><?php endif; ?>
<?php foreach ($drives as $drive): ?>
<article class="review-entry">
    <h3><a href="product_details.php?id=<?= (int) $drive['car_id'] ?>"><?= e($drive['car_name']) ?></a></h3>
    <p><?= e(substr($drive['starts_at'], 0, 16)) ?> (Riyadh) &middot; <?= e(ucfirst($drive['status'])) ?></p>
    <?php if ($manageDrives): ?><p><?= e($drive['full_name']) ?> &middot; <?= e($drive['email']) ?></p><?php endif; ?>
    <?php if ($drive['status'] !== 'cancelled' && $drive['starts_at'] > date('Y-m-d H:i:s')): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="drive_id" value="<?= (int) $drive['drive_id'] ?>">
            <?php if ($manageDrives && $drive['status'] === 'requested'): ?>
                <button class="btn btn-primary btn-sm" name="action" value="confirm_drive">Confirm appointment</button>
            <?php endif; ?>
            <button class="btn btn-quiet btn-sm" name="action" value="cancel_drive">Cancel appointment</button>
        </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

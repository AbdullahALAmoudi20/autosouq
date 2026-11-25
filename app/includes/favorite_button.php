<?php
require_once __DIR__ . '/customer_showroom.php';
$favoriteUrl = 'product_details.php?id=' . $carId;
$saved = isset(favorite_car_ids()[$carId]);
?>
<?php if (is_customer()): ?>
<form method="post" action="<?= e($favoriteUrl) ?>" class="favorite-action">
    <?= csrf_field() ?>
    <button class="btn btn-quiet favorite-heart" type="submit" name="action" value="<?= $saved ? 'remove_favorite' : 'save_favorite' ?>"
            title="<?= $saved ? 'Remove from favorites' : 'Save car' ?>"
            aria-pressed="<?= $saved ? 'true' : 'false' ?>" aria-label="Favorite <?= e($car['car_name']) ?>">
        <span aria-hidden="true"><?= $saved ? '&#9829;' : '&#9825;' ?></span>
    </button>
</form>
<?php else: ?>
<a class="btn btn-quiet favorite-action favorite-heart" href="login.php?next=<?= rawurlencode($favoriteUrl) ?>"
   title="Sign in to save car" aria-label="Sign in to save <?= e($car['car_name']) ?>"><span aria-hidden="true">&#9825;</span></a>
<?php endif; ?>

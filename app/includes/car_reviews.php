<section class="recalls reviews-panel" id="reviews" aria-labelledby="reviews-heading">
    <h2 id="reviews-heading">Customer reviews</h2>
    <?php if ($reviewCount): ?>
        <p><?= rating_stars((float) $reviewStats['average']) ?>
            &middot; <?= $reviewCount ?> <?= $reviewCount === 1 ? 'review' : 'reviews' ?></p>
    <?php else: ?>
        <p>No reviews yet. Be the first to review this car.</p>
    <?php endif; ?>
    <?php if ($reviewer === null): ?>
        <a href="login.php?next=<?= rawurlencode($reviewPageUrl) ?>">Sign in to write a review</a>
    <?php else: ?>
        <form method="post" action="<?= e($reviewPageUrl) ?>#reviews">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="review">
            <?php if ($reviewError !== ''): ?><p role="alert"><?= e($reviewError) ?></p><?php endif; ?>
            <fieldset class="review-rating">
                <legend>Your rating</legend>
                <div class="review-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label><input type="radio" name="rating" value="<?= $i ?>" aria-label="<?= $i ?> of 5 stars" required <?= $rating === $i ? 'checked' : '' ?>><span aria-hidden="true">★</span></label>
                    <?php endfor; ?>
                </div>
            </fieldset>
            <label for="review-comment">Your comment</label>
            <textarea id="review-comment" name="comment" rows="3" maxlength="2000" required><?= e($comment) ?></textarea>
            <button type="submit" class="btn btn-primary"><?= $ownReview ? 'Update review' : 'Post review' ?></button>
        </form>
    <?php endif; ?>
    <?php foreach ($reviews as $review): ?>
        <article class="review-entry">
            <strong><?= e($review['full_name']) ?></strong>
            <?= rating_stars((float) $review['rating']) ?>
            <time><?= e(substr($review['updated_at'], 0, 10)) ?></time>
            <p><?= e($review['comment']) ?></p>
        </article>
    <?php endforeach; ?>
    <?php if ($reviewPages > 1): ?>
        <nav aria-label="Review pages">
            <?php if ($reviewPage > 1): ?><a href="<?= e($reviewPageUrl) ?>&amp;reviews_page=<?= $reviewPage - 1 ?>#reviews">Newer reviews</a><?php endif; ?>
            <?php if ($reviewPage < $reviewPages): ?><a href="<?= e($reviewPageUrl) ?>&amp;reviews_page=<?= $reviewPage + 1 ?>#reviews">Older reviews</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

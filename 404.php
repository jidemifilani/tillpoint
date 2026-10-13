<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
http_response_code(404);
layout_head(['title' => 'Page not found']);
?>
<section class="card"><div class="card__body">
  <h2>That page does not exist</h2>
  <p class="muted">The address may have changed, or the record may have been removed.</p>
  <div class="btn-row">
    <a class="btn" href="<?= e(base_url('')) ?>">Back to the dashboard</a>
  </div>
</div></section>
<?php layout_foot();

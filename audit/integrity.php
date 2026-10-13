<?php
/**
 * Audit trail integrity.
 *
 * Re-walks the hash chain and proves whether the trail has been altered. This
 * is the page that makes every other audit figure worth reading: a log only
 * tells you what someone chose to leave in it, whereas a chained log tells you
 * whether anything was taken out.
 *
 * The explanation is on the page deliberately. A control nobody understands is a
 * control nobody trusts, and an auditor needs to be able to say what this
 * actually proves - and what it does not.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.integrity');

$result = null;

if (is_post()) {
    csrf_require();
    try {
        $result = audit_record_integrity_check((int) $_SESSION['uid'],
            trim((string) post('note')) !== '' ? (string) post('note') : '');
        if ($result['result'] === 'ok') {
            flash_ok(sprintf('Verified %s entries. The chain is unbroken.',
                number_format((int) $result['rows_checked'])));
        } elseif ($result['result'] === 'empty') {
            flash_info('The audit trail is empty.');
        } else {
            flash_err('Integrity check FAILED at entry #' . $result['broken_at_id'] . '.');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('audit/integrity');
}

$history = qa(
    'SELECT aic.*, u.full_name AS run_by_name FROM audit_integrity_checks aic
       LEFT JOIN users u ON u.id = aic.run_by
      ORDER BY aic.id DESC LIMIT 30'
);
$last = $history[0] ?? null;

$stats = q1(
    'SELECT COUNT(*) AS entries, MIN(id) AS first_id, MAX(id) AS last_id,
            MIN(created_at) AS earliest, MAX(created_at) AS latest,
            COUNT(DISTINCT user_id) AS actors
       FROM audit_log'
);
$head = q1('SELECT id, created_at, action, summary, prev_hash, row_hash
              FROM audit_log ORDER BY id DESC LIMIT 1');
$genesis = q1('SELECT id, created_at, action, summary, prev_hash, row_hash
                 FROM audit_log ORDER BY id ASC LIMIT 1');

$bySeverity = qpairs('SELECT severity, COUNT(*) FROM audit_log GROUP BY severity');

layout_head([
    'title'    => 'Audit trail integrity',
    'subtitle' => number_format((int) $stats['entries']) . ' entries in the chain',
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($last !== null && $last['result'] === 'broken'): ?>
  <div class="verdict verdict--broken">
    <h2>The chain is broken</h2>
    <p>
      Verification failed at entry #<?= (int) $last['broken_at_id'] ?> when checked on
      <?= e(date_fmt((string) $last['run_at'], true)) ?>.
      <?= e((string) ($last['note'] ?? '')) ?>
    </p>
    <p style="margin-top:.5rem">
      An entry was altered or deleted outside the application. Nothing in the audit module can be
      relied on until this is explained, because the records the other reports are built from may
      have been edited. Treat it as a security incident: find out who has direct database access
      and what changed.
    </p>
  </div>
<?php elseif ($last !== null && $last['result'] === 'ok'): ?>
  <div class="verdict verdict--ok">
    <h2>The chain verifies</h2>
    <p>
      <?= number_format((int) $last['rows_checked']) ?> entries re-hashed and checked against their
      predecessors on <?= e(date_fmt((string) $last['run_at'], true)) ?>
      <?php if ($last['run_by_name'] !== null): ?>by <?= e((string) $last['run_by_name']) ?><?php endif; ?>.
      Nothing in the trail has been altered or removed.
    </p>
  </div>
<?php else: ?>
  <div class="alert alert--warning">
    No integrity check has been recorded yet. Run one below.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Entries in the trail', number_format((int) $stats['entries']),
        'ids ' . (int) $stats['first_id'] . ' to ' . (int) $stats['last_id']) ?>
  <?= tile('Covering', $stats['earliest'] !== null
        ? date_fmt((string) $stats['earliest']) . ' to ' . date_fmt((string) $stats['latest']) : '-',
        number_format((int) $stats['actors']) . ' distinct actors', 'mute') ?>
  <?= tile('Critical entries', number_format((int) ($bySeverity['critical'] ?? 0)),
        'logged at critical severity',
        (int) ($bySeverity['critical'] ?? 0) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Checks recorded', number_format((int) qv('SELECT COUNT(*) FROM audit_integrity_checks', [], 0)),
        'every verification is itself evidenced', 'info') ?>
</div>

<?= card_open('Run a verification') ?>
  <form method="post">
    <?= csrf_field() ?>
    <label class="field">
      <span class="field__label">Note (optional)</span>
      <input type="text" name="note" maxlength="255"
             placeholder="e.g. Quarterly control testing, Q4 2026">
      <span class="field__hint">Recorded against the check, so the reason for it is on the record too.</span>
    </label>
    <button class="btn btn--lg" type="submit">Verify the whole chain</button>
  </form>
  <p class="small muted mt1">
    Re-reads every entry in order, recomputes its hash from its own contents plus the previous
    entry's hash, and compares. On <?= number_format((int) $stats['entries']) ?> entries this
    takes a moment. The result is stored, so the checks are themselves auditable.
  </p>
<?= card_close() ?>

<?= card_open('What this actually proves') ?>
  <div class="grid-2">
    <div>
      <h3>How it works</h3>
      <p class="small">
        Every entry stores a SHA-256 hash over its own significant fields &mdash; who, what, when,
        the before and after values &mdash; <em>plus the previous entry's hash</em>. That makes the
        table a chain rather than a list.
      </p>
      <p class="small">
        Change one historic entry and its own hash no longer matches its contents. Delete one and
        the next entry's recorded predecessor hash no longer matches what actually precedes it.
        Either way the break is detectable, and the verification names the exact entry where the
        chain stops adding up.
      </p>
      <p class="small">
        Nothing in the codebase issues an UPDATE or a DELETE against the trail. The only write
        path appends.
      </p>
    </div>
    <div>
      <h3>What it does not prove</h3>
      <p class="small">
        It is tamper-<em>evident</em>, not tamper-<em>proof</em>. Someone with database write access
        and the hashing function could in principle rewrite the whole chain from the point of
        change onwards and leave it internally consistent.
      </p>
      <p class="small">
        What defeats that is keeping evidence off the machine: export or print the current head
        hash periodically and file it. A rewritten chain cannot match a hash that was recorded
        elsewhere before the rewrite, so a single external copy turns "internally consistent"
        back into "demonstrably altered".
      </p>
      <p class="small">
        It also proves nothing about actions that were never logged. It guarantees the integrity of
        the record, not its completeness.
      </p>
    </div>
  </div>
<?= card_close() ?>

<?php if ($head !== null): ?>
<?= card_open('Current head of the chain',
      '<span class="tiny muted">Record this externally</span>') ?>
  <dl class="kv">
    <dt>Entry</dt><dd class="mono">#<?= (int) $head['id'] ?></dd>
    <dt>Written</dt><dd><?= e(date_fmt((string) $head['created_at'], true)) ?></dd>
    <dt>Action</dt><dd class="mono"><?= e($head['action']) ?></dd>
    <dt>Summary</dt><dd><?= e($head['summary']) ?></dd>
    <dt>Hash</dt><dd class="chain"><?= e($head['row_hash']) ?></dd>
  </dl>
  <p class="small muted mt1">
    This is the fingerprint of the entire trail up to entry #<?= (int) $head['id'] ?>. Copy it into
    a document outside this system, dated. If the trail is ever rewritten, the recomputed hash for
    that entry will not match the copy you kept &mdash; which is the one check a database
    administrator cannot defeat from inside.
  </p>

  <?php if ($genesis !== null && (int) $genesis['id'] !== (int) $head['id']): ?>
    <h3 class="mt1">First entry</h3>
    <dl class="kv">
      <dt>Entry</dt><dd class="mono">#<?= (int) $genesis['id'] ?></dd>
      <dt>Written</dt><dd><?= e(date_fmt((string) $genesis['created_at'], true)) ?></dd>
      <dt>Summary</dt><dd><?= e($genesis['summary']) ?></dd>
      <dt>Follows</dt><dd class="chain"><?= e($genesis['prev_hash']) ?>
        <?php if ($genesis['prev_hash'] === AUDIT_GENESIS_HASH): ?>
          <br><span class="tiny ok-text">all zeroes &mdash; correct for the first entry in a chain</span>
        <?php endif; ?>
      </dd>
    </dl>
  <?php endif; ?>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Verification history') ?>
  <?php if (!$history): ?>
    <?= empty_state('No checks recorded yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Run by</th><th>Result</th><th class="num">Entries checked</th>
        <th>Range</th><th>Broke at</th><th>Note</th>
      </tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr class="<?= $h['result'] === 'broken' ? 'row-bad' : '' ?>">
          <td class="nowrap tiny"><?= e(date_fmt((string) $h['run_at'], true)) ?></td>
          <td class="tiny"><?= e($h['run_by_name'] ?? 'automatic') ?></td>
          <td>
            <?= $h['result'] === 'ok' ? badge('Verified', 'ok')
                : ($h['result'] === 'broken' ? badge('FAILED', 'bad') : badge('Empty', 'neutral')) ?>
          </td>
          <td class="num"><?= number_format((int) $h['rows_checked']) ?></td>
          <td class="mono tiny">
            <?= e($h['first_id'] !== null ? '#' . $h['first_id'] . '-#' . $h['last_id'] : '-') ?>
          </td>
          <td class="mono tiny"><?= e($h['broken_at_id'] !== null ? '#' . $h['broken_at_id'] : '-') ?></td>
          <td class="tiny muted"><?= e($h['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<div class="sign-off">
  <div>Verified by: ________________________</div>
  <div>Date: ________________________</div>
</div>

<?php layout_foot(); ?>

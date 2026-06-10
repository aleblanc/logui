<?php
/** @var \Aleblanc\LogUi\Core\Model\Profile $profile */
/** @var string $uiPath */
$mem = null !== $profile->memStartMb && null !== $profile->memEndMb
    ? number_format($profile->memStartMb, 1).'→'.number_format($profile->memEndMb, 1).' MB (peak '.number_format($profile->memPeakMb, 1).')'
    : number_format($profile->memPeakMb, 1).' MB peak';
?>
<p><a href="<?= $this->escape($uiPath) ?>">← back</a></p>
<h2><?= $this->escape($profile->label) ?></h2>
<p class="ctx">
    <?= $this->escape($profile->type->value) ?>
    <?= null !== $profile->method ? '· '.$this->escape($profile->method).' ' : '' ?>·
    status <?= null === $profile->status ? '—' : (int) $profile->status ?> ·
    <?= $this->escape(number_format($profile->durationMs, 1)) ?> ms ·
    <?= $this->escape($mem) ?> ·
    <?= $this->escape($profile->at->format(\DateTimeInterface::ATOM)) ?>
</p>

<h3>SQL queries (<?= $profile->queries->count ?>)</h3>
<?php if (!$profile->queries->slow) { ?>
    <p class="ctx"><?= 0 === $profile->queries->count ? 'No queries recorded.' : 'No slow queries.' ?></p>
<?php } else { ?>
    <p class="ctx"><?= \count($profile->queries->slow) ?> slow:</p>
    <table>
        <thead><tr><th class="num">ms</th><th>SQL</th></tr></thead>
        <tbody>
        <?php foreach ($profile->queries->slow as $qy) { ?>
            <tr><td class="num"><?= $this->escape(number_format($qy['ms'], 1)) ?></td><td><?= $this->escape($qy['sql']) ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<?php if (null !== $profile->exception) { ?>
    <h3>Exception</h3>
    <pre><?= $this->escape($profile->exception['class']) ?>: <?= $this->escape($profile->exception['message']) ?>

<?= $this->escape($profile->exception['trace']) ?></pre>
<?php } ?>

<p class="ctx" style="margin-top:24px">
    Niveaux : <?php foreach ($profile->levels as $lvl => $n) { ?><span class="badge lvl-<?= $this->escape($lvl) ?>"><?= $this->escape($lvl) ?> <?= (int) $n ?></span> <?php } ?>
    <?= [] === $profile->levels ? '—' : '' ?>
</p>

<h3>Log records (<?= \count($profile->records) ?>)<?= $profile->truncated ? ' ⚠️ tronqué' : '' ?></h3>
<?php if (!$profile->records) { ?>
    <p class="ctx">Aucun enregistrement capturé pour cette requête.</p>
<?php } else {
    $recLevels = array_values(array_unique(array_map(static fn ($r) => $r->level, $profile->records)));
    $recChannels = array_values(array_unique(array_map(static fn ($r) => $r->channel, $profile->records)));
    sort($recLevels);
    sort($recChannels);
    ?>
<form class="filters">
    <select id="rec-level">
        <option value="">all levels</option>
        <?php foreach ($recLevels as $lvl) { ?><option value="<?= $this->escape($lvl) ?>"><?= $this->escape($lvl) ?></option><?php } ?>
    </select>
    <select id="rec-channel">
        <option value="">all channels</option>
        <?php foreach ($recChannels as $ch) { ?><option value="<?= $this->escape($ch) ?>"><?= $this->escape($ch) ?></option><?php } ?>
    </select>
    <span class="ctx" id="rec-count"></span>
</form>
<table id="logui-records">
    <thead><tr><th class="num">+ms</th><th>Level</th><th>Channel</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($profile->records as $r) { ?>
        <tr data-lvl="<?= $this->escape($r->level) ?>" data-ch="<?= $this->escape($r->channel) ?>">
            <td class="num"><?= $this->escape(number_format($r->t, 1)) ?></td>
            <td><span class="badge lvl-<?= $this->escape($r->level) ?>"><?= $this->escape($r->level) ?></span></td>
            <td class="ctx"><?= $this->escape($r->channel) ?></td>
            <td><?= $this->escape($r->message) ?><?php if ($r->context) { ?><br><span class="ctx"><?= $this->escape((string) json_encode($r->context, \JSON_UNESCAPED_SLASHES)) ?></span><?php } ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<script>
    (function () {
        var lvl = document.getElementById('rec-level'),
            ch = document.getElementById('rec-channel'),
            count = document.getElementById('rec-count'),
            rows = [].slice.call(document.querySelectorAll('#logui-records tbody tr'));
        function apply() {
            var L = lvl.value, C = ch.value, shown = 0;
            rows.forEach(function (r) {
                var ok = (L === '' || r.getAttribute('data-lvl') === L) && (C === '' || r.getAttribute('data-ch') === C);
                r.style.display = ok ? '' : 'none';
                if (ok) { shown++; }
            });
            count.textContent = shown + ' / ' + rows.length;
        }
        lvl.addEventListener('change', apply);
        ch.addEventListener('change', apply);
        apply();
    })();
</script>
<?php } ?>

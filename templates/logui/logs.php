<?php
/** @var list<array{label:string,path:string,ref:string}> $sources */
/** @var string $uiPath */
?>
<h2>Log files</h2>
<?php if (!$sources) { ?>
    <p class="ctx">No log files discovered. Configure <code>log_ui.external_logs</code> or enable <code>discover_monolog</code>.</p>
<?php } else { ?>
<table>
    <thead><tr><th>Source</th><th>Path</th></tr></thead>
    <tbody>
    <?php foreach ($sources as $s) { ?>
        <tr>
            <td><a href="<?= $this->escape($uiPath) ?>/logs/view?ref=<?= $this->escape(rawurlencode($s['ref'])) ?>"><?= $this->escape($s['label']) ?></a></td>
            <td class="ctx"><?= $this->escape($s['ref']) ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } ?>

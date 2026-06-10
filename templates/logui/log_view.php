<?php
/** @var string $path */
/** @var list<array{date:string,channel:string,level:string,message:string,context:?string,extra:?string}> $rows */
/** @var string $uiPath */
?>
<p><a href="<?= $this->escape($uiPath) ?>/logs">← all files</a></p>
<h2><?= $this->escape(basename($path)) ?></h2>
<p class="ctx"><?= $this->escape($path) ?> — newest first, last <?= \count($rows) ?> lines</p>

<?php if (!$rows) { ?>
    <p class="ctx">Empty or unreadable.</p>
<?php } else { ?>
<table>
    <thead><tr><th>Time</th><th>Channel</th><th>Level</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
        <tr>
            <td class="ctx"><?= $this->escape($r['date']) ?></td>
            <td class="ctx"><?= $this->escape($r['channel']) ?></td>
            <td><?php if ('' !== $r['level']) { ?><span class="badge lvl-<?= $this->escape(strtolower($r['level'])) ?>"><?= $this->escape($r['level']) ?></span><?php } ?></td>
            <td><?= $this->escape($r['message']) ?><?php if (null !== $r['context'] && '[]' !== $r['context']) { ?><br><span class="ctx"><?= $this->escape($r['context']) ?></span><?php } ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } ?>

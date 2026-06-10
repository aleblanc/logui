<?php
/** @var string $ref */
/** @var string $name */
/** @var list<array{date:string,channel:string,level:string,message:string,context:?string,extra:?string}> $rows */
/** @var list<string> $levels */
/** @var list<string> $channels */
/** @var array{level:string,channel:string,q:string} $filter */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var string $uiPath */
$base = $uiPath.'/logs/view';
$qs = function (int $p) use ($ref, $filter): string {
    return http_build_query(array_filter([
        'ref' => $ref,
        'level' => $filter['level'],
        'channel' => $filter['channel'],
        'q' => $filter['q'],
        'page' => $p,
    ], static fn ($v): bool => '' !== $v && null !== $v));
};
// Renders a possibly-huge value as a truncated cell with a "more" toggle.
$cell = function (string $text) {
    $full = $this->escape($text);
    $isLong = mb_strlen($text) > 240 || str_contains($text, "\n");
    if (!$isLong) {
        return $full;
    }
    $short = $this->escape(rtrim(mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 240)));

    return '<span class="msg"><span class="msg-short">'.$short.'…</span>'
        .'<span class="msg-full" hidden>'.$full.'</span> '
        .'<button type="button" class="msg-toggle">more ▸</button></span>';
};
?>
<p><a href="<?= $this->escape($uiPath) ?>/logs">← all files</a></p>
<h2><?= $this->escape($name) ?></h2>
<p class="ctx"><?= $this->escape($ref) ?> — newest first · <?= $total ?> lines in the read window (tail)</p>

<form class="filters" method="get" action="<?= $this->escape($base) ?>">
    <input type="hidden" name="ref" value="<?= $this->escape($ref) ?>">
    <select name="level">
        <option value="">all levels</option>
        <?php foreach ($levels as $lvl) { ?>
            <option value="<?= $this->escape($lvl) ?>"<?= $lvl === $filter['level'] ? ' selected' : '' ?>><?= $this->escape($lvl) ?></option>
        <?php } ?>
    </select>
    <select name="channel">
        <option value="">all channels</option>
        <?php foreach ($channels as $ch) { ?>
            <option value="<?= $this->escape($ch) ?>"<?= $ch === $filter['channel'] ? ' selected' : '' ?>><?= $this->escape($ch) ?></option>
        <?php } ?>
    </select>
    <input type="search" name="q" value="<?= $this->escape($filter['q']) ?>" placeholder="search message…">
    <button type="submit">Filter</button>
</form>

<?php if (!$rows) { ?>
    <p class="ctx">No matching lines.</p>
<?php } else { ?>
<table>
    <thead><tr><th>Time</th><th>Channel</th><th>Level</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
        <tr>
            <td class="ctx" style="white-space:nowrap"><?= $this->escape($r['date']) ?></td>
            <td class="ctx"><?= $this->escape($r['channel']) ?></td>
            <td><?php if ('' !== $r['level']) { ?><span class="badge lvl-<?= $this->escape(strtolower($r['level'])) ?>"><?= $this->escape($r['level']) ?></span><?php } ?></td>
            <td><?= $cell($r['message']) ?><?php if (null !== $r['context'] && '[]' !== $r['context']) { ?><br><span class="ctx"><?= $cell($r['context']) ?></span><?php } ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<div class="pager">
    <?php if ($page > 1) { ?><a href="<?= $this->escape($base.'?'.$qs($page - 1)) ?>">← prev</a><?php } else { ?><span class="ctx">← prev</span><?php } ?>
    <span class="ctx">page <?= $page ?> / <?= $pages ?> · <?= $total ?> lines</span>
    <?php if ($page < $pages) { ?><a href="<?= $this->escape($base.'?'.$qs($page + 1)) ?>">next →</a><?php } else { ?><span class="ctx">next →</span><?php } ?>
</div>
<?php } ?>

<script>
    document.addEventListener('click', function (e) {
        if (!e.target.classList || !e.target.classList.contains('msg-toggle')) { return; }
        var msg = e.target.closest('.msg'),
            full = msg.querySelector('.msg-full'),
            short = msg.querySelector('.msg-short'),
            expand = full.hidden;
        full.hidden = !expand;
        short.hidden = expand;
        e.target.textContent = expand ? 'less ▾' : 'more ▸';
    });
</script>

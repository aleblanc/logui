<?php
/** @var list<\Aleblanc\LogUi\Core\Model\Profile> $profiles */
/** @var \Aleblanc\LogUi\Core\Query\ProfileSummary $summary */
/** @var array{level:string,type:string,q:string,method:string} $filter */
/** @var string $uiPath */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
$qs = static function (int $p) use ($filter): string {
    return http_build_query(array_filter([
        'level' => $filter['level'],
        'type' => $filter['type'],
        'method' => $filter['method'],
        'q' => $filter['q'],
        'page' => $p,
    ], static fn ($v): bool => '' !== $v && null !== $v));
};
// A stat/cell links to the list with one filter applied (clearing the others).
$statLink = static function (array $extra) use ($uiPath): string {
    $query = http_build_query(array_filter($extra, static fn ($v): bool => '' !== $v && null !== $v));

    return $uiPath.('' !== $query ? '?'.$query : '');
};
$activeAll = '' === $filter['level'] && '' === $filter['type'] ? ' active' : '';
?>
<div class="stats">
    <a class="stat<?= $activeAll ?>" href="<?= $this->escape($statLink([])) ?>"><b><?= $summary->total ?></b> profils</a>
    <a class="stat<?= 'http' === $filter['type'] ? ' active' : '' ?>" href="<?= $this->escape($statLink(['type' => 'http'])) ?>">🌐 <b><?= $summary->http ?></b> requêtes</a>
    <a class="stat<?= 'cli' === $filter['type'] ? ' active' : '' ?>" href="<?= $this->escape($statLink(['type' => 'cli'])) ?>"><span class="glyph-cli">$_</span> <b><?= $summary->cli ?></b> commandes</a>
    <a class="stat sev-critical<?= 'critical' === $filter['level'] ? ' active' : '' ?>" href="<?= $this->escape($statLink(['level' => 'critical'])) ?>"><b><?= $summary->critical ?></b> critical</a>
    <a class="stat sev-error<?= 'error' === $filter['level'] ? ' active' : '' ?>" href="<?= $this->escape($statLink(['level' => 'error'])) ?>"><b><?= $summary->error ?></b> error</a>
    <a class="stat sev-warning<?= 'warning' === $filter['level'] ? ' active' : '' ?>" href="<?= $this->escape($statLink(['level' => 'warning'])) ?>"><b><?= $summary->warning ?></b> warning</a>
</div>

<form class="filters" method="get" action="<?= $this->escape($uiPath) ?>">
    <select name="level">
        <option value="">all levels</option>
        <?php foreach (['critical', 'error', 'warning', 'notice', 'info', 'debug'] as $lvl) { ?>
            <option value="<?= $lvl ?>"<?= $lvl === $filter['level'] ? ' selected' : '' ?>><?= $lvl ?></option>
        <?php } ?>
    </select>
    <select name="type">
        <option value="">all</option>
        <option value="http"<?= 'http' === $filter['type'] ? ' selected' : '' ?>>http</option>
        <option value="cli"<?= 'cli' === $filter['type'] ? ' selected' : '' ?>>cli</option>
    </select>
    <select name="method">
        <option value="">all methods</option>
        <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $mth) { ?>
            <option value="<?= $mth ?>"<?= $mth === $filter['method'] ? ' selected' : '' ?>><?= $mth ?></option>
        <?php } ?>
    </select>
    <input type="search" name="q" value="<?= $this->escape($filter['q']) ?>" placeholder="search…">
    <button type="submit">Filter</button>
</form>

<?php if (!$profiles) { ?>
    <p class="ctx">No profiles captured yet.</p>
<?php } else { ?>
<table>
    <thead><tr><th>Time</th><th>Type</th><th>Method</th><th>Label</th><th>Status</th><th class="num">ms</th><th class="num">MB</th><th class="num">SQL</th><th>Levels</th></tr></thead>
    <tbody>
    <?php foreach ($profiles as $p) { ?>
        <tr>
            <td class="ctx"><?= $this->escape($p->at->format('m-d H:i:s')) ?></td>
            <td><?= 'http' === $p->type->value ? '🌐' : '<span class="glyph-cli">$_</span>' ?></td>
            <td><?php if (null !== $p->method) { ?><a class="badge" href="<?= $this->escape($statLink(['method' => $p->method])) ?>"><?= $this->escape($p->method) ?></a><?php } else { ?><span class="ctx">—</span><?php } ?></td>
            <td><a href="<?= $this->escape($uiPath) ?>/<?= $this->escape($p->id) ?>"><?= $this->escape($p->label) ?></a></td>
            <td class="num"><?= null === $p->status ? '—' : (int) $p->status ?></td>
            <td class="num"><?= $this->escape(number_format($p->durationMs, 1)) ?></td>
            <td class="num"><?= $this->escape(number_format($p->memPeakMb, 1)) ?></td>
            <td class="num"><?= $p->queries->count ?><?= $p->queries->slow ? ' ⚠' : '' ?></td>
            <td><?php foreach ($p->levels as $lvl => $n) { ?><span class="badge lvl-<?= $this->escape($lvl) ?>"><?= $this->escape($lvl) ?> <?= (int) $n ?></span> <?php } ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<div class="pager">
    <?php if ($page > 1) { ?><a href="<?= $this->escape($uiPath.'?'.$qs($page - 1)) ?>">← prev</a><?php } else { ?><span class="ctx">← prev</span><?php } ?>
    <span class="ctx">page <?= $page ?> / <?= $pages ?> · <?= $total ?> profils</span>
    <?php if ($page < $pages) { ?><a href="<?= $this->escape($uiPath.'?'.$qs($page + 1)) ?>">next →</a><?php } else { ?><span class="ctx">next →</span><?php } ?>
</div>
<?php } ?>

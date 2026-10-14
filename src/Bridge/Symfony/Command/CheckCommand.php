<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Command;

use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Core\Query\Grep;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\LogQL\SyntaxError;
use Aleblanc\LogUi\Core\Query\Selection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Counts matching records over a recent window and exits non-zero when there are enough of them —
 * a one-liner for cron and CI: `logui:check '{level="error"}' --since=5m --min=10 || alert`.
 *
 * Reuses the explorer's own query engine, so the command and the UI agree to the record.
 */
#[AsCommand(name: 'logui:check', description: 'Exit non-zero when a query matches at least --min records in a recent window.')]
final class CheckCommand extends Command
{
    private const UNITS = ['s' => 1_000, 'm' => 60_000, 'h' => 3_600_000, 'd' => 86_400_000];

    public function __construct(
        private readonly RawLogSources $sources,
        private readonly LogExplorer $explorer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::OPTIONAL, 'LogQL query', '')
            ->addOption('grep', null, InputOption::VALUE_REQUIRED, 'Plain search term', '')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window length, e.g. 5m, 2h, 1d', '5m')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Minimum matches for a non-zero exit', '1')
            ->addOption('types', null, InputOption::VALUE_REQUIRED, 'Comma-separated row types (log,request,command)', '')
            ->addOption('levels', null, InputOption::VALUE_REQUIRED, 'Comma-separated levels', '')
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'Comma-separated source refs', '')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sinceMs = $this->parseSince((string) $input->getOption('since'));
        if (null === $sinceMs) {
            $output->writeln('<error>Invalid --since: use a number followed by s, m, h or d (e.g. 5m).</error>');

            return 2;
        }

        $min = max(0, (int) $input->getOption('min'));
        $now = (int) (microtime(true) * 1000);
        $selected = $this->list((string) $input->getOption('files'));

        $sources = [];
        foreach ($this->sources->all() as $entry) {
            if ([] === $selected || \in_array($entry['ref'], $selected, true)) {
                $sources[$entry['ref']] = $entry['path'];
            }
        }

        try {
            $selection = new Selection(
                $this->list((string) $input->getOption('levels')),
                [],
                Grep::of((string) $input->getOption('grep')),
                [],
                $this->list((string) $input->getOption('types')),
            );
            $result = $this->explorer->query($sources, (string) $input->getArgument('query'), $now - $sinceMs, $now, 1, 0, $selection);
        } catch (SyntaxError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return 2;
        }

        $total = $result['total'];

        if ('json' === $input->getOption('format')) {
            $output->writeln((string) json_encode(['total' => $total, 'min' => $min, 'since' => (string) $input->getOption('since')]));
        } else {
            $output->writeln((string) $total);
        }

        return $total >= $min ? Command::FAILURE : Command::SUCCESS;
    }

    private function parseSince(string $since): ?int
    {
        if (!preg_match('/^(\d+)([smhd])$/', $since, $m)) {
            return null;
        }

        return (int) $m[1] * self::UNITS[$m[2]];
    }

    /** @return list<string> */
    private function list(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $v): bool => '' !== $v));
    }
}

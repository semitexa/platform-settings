<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Platform\Settings\Application\Service\Feature\SettingsFeatureFlags;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Every declared flag: on or off for a site and user, and which scope decided it. */
#[AsCommand(name: 'feature:list', description: 'List feature flags, their value and where it comes from.')]
final class FeatureListCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected FeatureFlagRegistry $registry;

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    protected function configure(): void
    {
        $this->setName('feature:list')
            ->setDescription('List feature flags, their value and where it comes from.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'The site to answer for (default: global only)', '')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'The user of that site to answer for', '')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = (string) $input->getOption('tenant');
        $user = (string) $input->getOption('user');
        $flags = SettingsFeatureFlags::of($this->registry, $this->settings)->forSubject($tenant !== '' ? $tenant : null, $user !== '' ? $user : null);

        $rows = [];
        foreach ($this->registry->all() as $name => $flag) {
            $answer = $flags instanceof SettingsFeatureFlags ? $flags->explain($name) : ['enabled' => $flags->isEnabled($name), 'source' => '?', 'value' => null];
            $rows[] = [
                'flag' => $name,
                'enabled' => $answer['enabled'],
                'source' => $answer['source'],
                'value' => $answer['value'],
                'description' => $flag->description,
            ];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        if ($rows === []) {
            $output->writeln('No feature flags are declared (#[AsFeatureFlag]).');

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['flag', 'on', 'from', 'value', 'description'])
            ->setRows(array_map(static fn (array $r): array => [
                $r['flag'],
                $r['enabled'] ? 'yes' : 'no',
                $r['source'],
                json_encode($r['value']),
                $r['description'],
            ], $rows))
            ->render();

        return Command::SUCCESS;
    }
}

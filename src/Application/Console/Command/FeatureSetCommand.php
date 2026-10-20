<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Feature\FeatureFlagRegistry;
use Semitexa\Platform\Settings\Application\Service\Feature\FeatureFlagWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turn a flag on or off, roll it out to a share of users, or clear an
 * override — for every site, one site (--tenant) or one user (--tenant --user).
 * Running workers pick the change up within 2 seconds.
 */
#[AsCommand(name: 'feature:set', description: 'Set a feature flag: on, off, a percentage (25%), or clear.')]
final class FeatureSetCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected FeatureFlagRegistry $registry;

    #[InjectAsReadonly]
    protected FeatureFlagWriter $writer;

    protected function configure(): void
    {
        $this->setName('feature:set')
            ->setDescription('Set a feature flag: on, off, a percentage (25%), or clear.')
            ->addArgument('flag', InputArgument::REQUIRED, 'The flag, as declared with #[AsFeatureFlag]')
            ->addArgument('value', InputArgument::REQUIRED, 'on | off | N% | clear')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Only this site', '')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this user of the site (needs --tenant)', '')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set a flag no module declares');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flag = (string) $input->getArgument('flag');
        $value = strtolower(trim((string) $input->getArgument('value')));
        $tenant = (string) $input->getOption('tenant');
        $tenant = $tenant !== '' ? $tenant : null;
        $user = (string) $input->getOption('user');
        $user = $user !== '' ? $user : null;

        if ($this->registry->get($flag) === null && !(bool) $input->getOption('force')) {
            $output->writeln("<error>No module declares '{$flag}' (#[AsFeatureFlag]); it would read as off whatever is stored. Pass --force to store it anyway.</error>");

            return Command::FAILURE;
        }

        try {
            match (true) {
                in_array($value, ['on', 'true', '1', 'enable'], true) => $this->writer->enable($flag, $tenant, $user),
                in_array($value, ['off', 'false', '0', 'disable'], true) => $this->writer->disable($flag, $tenant, $user),
                $value === 'clear' => $this->writer->clear($flag, $tenant, $user),
                preg_match('/^(\d{1,3})%$/', $value, $m) === 1 && (int) $m[1] <= 100 => $this->writer->rollout($flag, (int) $m[1], $tenant, $user),
                default => throw new \InvalidArgumentException("'{$value}' is not on, off, N% or clear."),
            };
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $scope = $user !== null ? "user {$user} of {$tenant}" : ($tenant !== null ? "site {$tenant}" : 'every site');
        $output->writeln("<info>{$flag}: {$value} for {$scope}.</info> Running workers see it within 2 s.");

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Latte\PlainTextFileLoader;
use App\Vault\VaultClient;
use App\Vault\VaultException;
use Latte\Engine;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'compile:latte',
    description: 'Renders a Latte configuration template using secrets from Vault and generates a NEON file.',
)]
final class CompileLatteCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::REQUIRED, 'Environment (e.g. prod, staging) - substituted into {env} in the secret path.')
            ->addArgument('input', InputArgument::REQUIRED, 'Input Latte template (e.g. examples/config.latte).')
            ->addArgument('output', InputArgument::REQUIRED, 'Output NEON file (e.g. config/local.neon).')
            ->addOption('vault-addr', null, InputOption::VALUE_REQUIRED, 'Vault address.', getenv('VAULT_ADDR') ?: 'https://127.0.0.1:8200')
            ->addOption('mount', null, InputOption::VALUE_REQUIRED, 'KV mount in Vault.', getenv('VAULT_KV_MOUNT') ?: 'secret')
            ->addOption('kv-version', null, InputOption::VALUE_REQUIRED, 'KV engine version (1 or 2).', getenv('VAULT_KV_VERSION') ?: '2')
            ->addOption('secret-path', null, InputOption::VALUE_REQUIRED, 'Path template under the mount; {env} is replaced. Multiple comma-separated paths are merged (later ones override earlier).', getenv('VAULT_SECRET_PATH') ?: '{env}')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Render and validate, but print to stdout instead of writing to a file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Diagnostics go to stderr, the payload (dry-run) goes to stdout.
        $io = new SymfonyStyle($input, $output);
        $err = $io->getErrorStyle();

        $environment = self::asString($input->getArgument('environment'));
        $inputFile = self::asString($input->getArgument('input'));
        $outputFile = self::asString($input->getArgument('output'));

        $token = getenv('VAULT_TOKEN');
        if ($token === false || $token === '') {
            $err->error('Environment variable VAULT_TOKEN is not set.');

            return Command::FAILURE;
        }

        if (!is_file($inputFile)) {
            $err->error(sprintf('Input template does not exist: %s', $inputFile));

            return Command::FAILURE;
        }

        $address = self::asString($input->getOption('vault-addr'));
        $mount = self::asString($input->getOption('mount'));
        $kvVersion = self::asInt($input->getOption('kv-version'));
        if ($kvVersion !== 1 && $kvVersion !== 2) {
            $err->error(sprintf(
                'Invalid --kv-version "%s": only 1 or 2 are supported.',
                self::asString($input->getOption('kv-version')),
            ));

            return Command::FAILURE;
        }
        $namespace = getenv('VAULT_NAMESPACE') ?: null;

        $vault = new VaultClient($address, $token, $namespace, $kvVersion);

        // Paths: {env} substitution + optional merging of multiple paths.
        $pathTemplate = self::asString($input->getOption('secret-path'));
        $paths = array_values(array_filter(
            array_map('trim', explode(',', $pathTemplate)),
            static fn (string $p): bool => $p !== '',
        ));

        if ($paths === []) {
            $err->error('Could not build any path from --secret-path / VAULT_SECRET_PATH.');

            return Command::FAILURE;
        }

        $secrets = [];
        try {
            foreach ($paths as $path) {
                $resolved = str_replace('{env}', $environment, $path);
                $err->writeln(
                    sprintf('Reading %s/%s (KV v%d) from %s', $mount, $resolved, $kvVersion, $address),
                    OutputInterface::VERBOSITY_VERBOSE,
                );
                $secrets = array_merge($secrets, $vault->readKv($mount, $resolved));
            }
        } catch (VaultException $e) {
            $err->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($secrets === []) {
            $err->warning('Vault returned no keys for the given paths.');
        }

        // We log only the key NAMES, never the values.
        $err->writeln(
            sprintf('Keys loaded: %d [%s]', count($secrets), implode(', ', array_keys($secrets))),
            OutputInterface::VERBOSITY_VERBOSE,
        );

        // Parameters for Latte: each key as a variable (if it is a valid identifier),
        // always also the whole {$vault} array and {$environment}. Reserved names
        // (vault, environment) must not be clobbered by a secret of the same name -
        // such a key stays reachable only via {$vault['...']}.
        $reserved = ['vault' => true, 'environment' => true];
        $params = ['vault' => $secrets, 'environment' => $environment];
        foreach ($secrets as $key => $value) {
            if (isset($reserved[$key])) {
                $err->writeln(
                    sprintf('Secret key "%s" shadows a reserved variable; use {$vault[\'%s\']} to access it.', $key, $key),
                    OutputInterface::VERBOSITY_VERBOSE,
                );

                continue;
            }
            if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $key)) {
                $params[$key] = $value;
            }
        }

        // Per-user cache dir so two users on the same host do not collide on a
        // predictable shared path (permission failures / temp squatting).
        $uid = getmyuid();
        $cacheDir = sprintf('%s/vaulttoconfig-latte-%s', sys_get_temp_dir(), $uid !== false ? (string) $uid : 'shared');
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            $err->error(sprintf('Failed to create cache directory: %s', $cacheDir));

            return Command::FAILURE;
        }

        $latte = new Engine();
        $latte->setCacheDirectory($cacheDir);
        $latte->setLoader(new PlainTextFileLoader(dirname($inputFile)));

        // The |neon filter safely encodes the value as a NEON scalar (quotes, escaping).
        $latte->addFilter('neon', static fn ($value): string => Neon::encode($value));

        try {
            $rendered = $latte->renderToString(basename($inputFile), $params);
        } catch (\Throwable $e) {
            $err->error(sprintf('Latte rendering failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        // Before writing, verify that the output is syntactically valid NEON.
        try {
            Neon::decode($rendered);
        } catch (NeonException $e) {
            $err->error(sprintf('Rendered output is not valid NEON: %s', $e->getMessage()));
            $err->writeln($rendered, OutputInterface::VERBOSITY_VERBOSE);

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('dry-run')) {
            $err->note(sprintf('Dry-run for environment "%s" - the file is not written.', $environment));
            $output->write($rendered);

            return Command::SUCCESS;
        }

        $dir = dirname($outputFile);
        if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $err->error(sprintf('Failed to create output directory: %s', $dir));

            return Command::FAILURE;
        }

        // Restrict the umask before creating the file so the secrets are never
        // briefly world/group-readable between file_put_contents() and chmod().
        $oldUmask = umask(0177); // new files: 0600
        try {
            $written = @file_put_contents($outputFile, $rendered);
        } finally {
            umask($oldUmask);
        }

        if ($written === false) {
            $err->error(sprintf('Failed to write output file: %s', $outputFile));

            return Command::FAILURE;
        }

        @chmod($outputFile, 0640);

        $err->success(sprintf(
            'Written %s (%d B) for environment "%s".',
            $outputFile,
            strlen($rendered),
            $environment,
        ));

        return Command::SUCCESS;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

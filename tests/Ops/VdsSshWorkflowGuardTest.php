<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class VdsSshWorkflowGuardTest extends TestCase
{
    public function testWorkflowYamlParses(): void
    {
        foreach ($this->workflowFiles() as $path) {
            try {
                $parsed = Yaml::parseFile($path);
            } catch (ParseException $exception) {
                self::fail($path.': '.$exception->getMessage());
            }
            self::assertIsArray($parsed, $path);
            self::assertArrayHasKey('jobs', $parsed, $path);
        }
    }

    public function testPauseGuardSkipsSshJobs(): void
    {
        foreach ($this->sshWorkflowFiles() as $path) {
            $jobs = $this->jobs($path);
            $pauseJobs = 0;
            foreach ($jobs as $job) {
                if ($this->isPauseJob($job)) {
                    ++$pauseJobs;
                    $encoded = $this->encode($job);
                    self::assertStringNotContainsString('secrets.', $encoded, $path);
                    self::assertStringNotContainsString('webfactory/ssh-agent', $encoded, $path);
                    self::assertStringNotContainsString('gha-ssh-config.sh', $encoded, $path);
                    self::assertStringContainsString('VDS SSH operations are paused', $encoded, $path);
                }
                if ($this->isSshJob($job)) {
                    $condition = $job['if'] ?? '';
                    self::assertIsString($condition, $path);
                    self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $condition, $path);
                }
            }
            self::assertSame(1, $pauseJobs, $path);
        }
    }

    public function testAutoDeployRequiresPauseOffAndCiSuccess(): void
    {
        $deploy = $this->read('.github/workflows/deploy.yml');
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $deploy);
        self::assertStringContainsString("vars.VDS_AUTO_DEPLOY == 'true'", $deploy);
        self::assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $deploy);
        self::assertStringContainsString('Deploy skipped because SSH paused', $deploy);
        self::assertStringNotContainsString('Cleanup remote tarball', $deploy);
    }

    public function testSshJobsShareConcurrencyGroup(): void
    {
        $seen = 0;
        foreach ($this->sshWorkflowFiles() as $path) {
            foreach ($this->jobs($path) as $job) {
                if (!$this->isSshJob($job)) {
                    continue;
                }
                ++$seen;
                $concurrency = $job['concurrency'] ?? null;
                self::assertIsArray($concurrency, $path);
                self::assertSame('testlig-vds-ssh', $concurrency['group'] ?? null, $path);
                self::assertFalse($concurrency['cancel-in-progress'] ?? null, $path);
                self::assertSame('max', $concurrency['queue'] ?? null, $path);
            }
            $text = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/group:\s*(deploy-vds|ops-catalog-import|ops-catalog-publish-tree|ops-curriculum-pilot-import|ops-superadmin-bootstrap)\b/', $text, $path);
        }
        self::assertGreaterThanOrEqual(5, $seen);
    }

    public function testSshClientConfigIsSingleAttemptPublicKey(): void
    {
        $config = $this->read('deploy/vds/gha-ssh-config.sh');
        self::assertStringContainsString('Host testlig-vds', $config);
        self::assertStringContainsString('BatchMode yes', $config);
        self::assertStringContainsString('PasswordAuthentication no', $config);
        self::assertStringContainsString('KbdInteractiveAuthentication no', $config);
        self::assertStringContainsString('PreferredAuthentications publickey', $config);
        self::assertStringContainsString('PubkeyAuthentication yes', $config);
        self::assertStringContainsString('NumberOfPasswordPrompts 0', $config);
        self::assertStringContainsString('ConnectionAttempts 1', $config);
        self::assertStringContainsString('ConnectTimeout 15', $config);
        self::assertStringContainsString('ServerAliveInterval 15', $config);
        self::assertStringContainsString('ServerAliveCountMax 2', $config);
        self::assertStringContainsString('StrictHostKeyChecking yes', $config);
        self::assertStringContainsString('UserKnownHostsFile', $config);
        self::assertStringContainsString('LogLevel ERROR', $config);
        self::assertStringContainsString('ControlMaster auto', $config);
        self::assertStringContainsString('ControlPersist 120', $config);
        self::assertStringContainsString('ControlPath', $config);
        self::assertStringContainsString('chmod 600', $config);
        self::assertSame(0, $this->commandCount($config, 'ssh'));
        self::assertSame(0, $this->commandCount($config, 'scp'));
        self::assertStringNotContainsString('ssh-keyscan', $config);
        self::assertDoesNotMatchRegularExpression('/StrictHostKeyChecking\s+no/', $config);
        self::assertDoesNotMatchRegularExpression('/PasswordAuthentication\s+yes/', $config);
        self::assertDoesNotMatchRegularExpression('/ConnectionAttempts\s+(?!1\b)\d+/', $config);
        self::assertStringNotContainsString('IdentitiesOnly', $config);
        self::assertStringNotContainsString('IdentityFile', $config);
        self::assertStringContainsString('SSH_AUTH_SOCK', $config);
        self::assertStringContainsString('ssh-add -l', $config);
        self::assertStringContainsString('ssh_agent_identity_count=1', $config);
        self::assertLessThan(
            strpos($config, 'cat > "${HOME}/.ssh/config"') ?: \PHP_INT_MAX,
            strpos($config, 'require_one_agent_identity') ?: 0,
        );
        self::assertDoesNotMatchRegularExpression('/ssh-add\s+-L\b/', $config);
        self::assertDoesNotMatchRegularExpression('/printf[^\n]*identity_list/', $config);
        self::assertDoesNotMatchRegularExpression('/echo[^\n]*identity_list/', $config);

        foreach ($this->sshWorkflowFiles() as $path) {
            $text = (string) file_get_contents($path);
            self::assertStringContainsString('bash', $text, $path);
            self::assertStringContainsString('gha-ssh-config.sh', $text, $path);
            self::assertStringNotContainsString('ssh-keyscan', $text, $path);
            self::assertStringNotContainsString('IdentityFile', $text, $path);
            self::assertDoesNotMatchRegularExpression('/ssh-add\s+-L\b/', $text, $path);
            self::assertDoesNotMatchRegularExpression('/StrictHostKeyChecking\s*[:=]\s*no/', $text, $path);
        }
    }

    public function testDeployUsesOneBundleAndNoCleanupConnection(): void
    {
        $workflow = $this->read('.github/workflows/deploy.yml');
        $script = $this->read('deploy/vds/gha-deploy-once.sh');
        $close = $this->read('deploy/vds/gha-ssh-close-master.sh');

        self::assertSame(0, $this->commandCount($workflow, 'ssh'));
        self::assertSame(0, $this->commandCount($workflow, 'scp'));
        self::assertStringContainsString('gha-deploy-once.sh', $workflow);
        self::assertLessThan(
            strpos($workflow, 'gha-deploy-once.sh') ?: \PHP_INT_MAX,
            strpos($workflow, 'gha-ssh-config.sh') ?: 0,
        );

        self::assertSame(1, $this->commandCount($script, 'scp'));
        self::assertSame(1, $this->commandCount($script, 'ssh'));
        self::assertStringContainsString('release.tgz', $script);
        self::assertStringContainsString('release-deploy.sh', $script);
        self::assertStringContainsString('rollback.sh', $script);
        self::assertStringContainsString('testlig-deploy-bundle.tgz', $script);
        self::assertStringContainsString('trap cleanup EXIT', $script);
        self::assertStringContainsString('gha-ssh-close-master.sh', $script);
        self::assertDoesNotMatchRegularExpression('/\|\|\s*true/', $script);
        self::assertStringNotContainsString('testlig-release.tgz\' ||', $script);

        self::assertSame(1, $this->commandCount($close, 'ssh'));
        self::assertStringContainsString('-O exit', $close);
        self::assertStringContainsString('ControlMaster=no', $close);
        self::assertStringContainsString('no SSH control socket; not opening a connection', $close);
        self::assertSame(0, $this->commandCount($close, 'scp'));
    }

    public function testOpsWorkflowsUseOneSshSession(): void
    {
        $ops = [
            '.github/workflows/ops-assessment-access-policy-inventory.yml',
            '.github/workflows/ops-catalog-import.yml',
            '.github/workflows/ops-catalog-publish-tree.yml',
            '.github/workflows/ops-curriculum-pilot-import.yml',
            '.github/workflows/ops-curriculum-reconcile.yml',
            '.github/workflows/ops-learning-content-package-import.yml',
            '.github/workflows/ops-superadmin-bootstrap.yml',
        ];
        foreach ($ops as $relative) {
            $text = $this->read($relative);
            self::assertSame(1, $this->commandCount($text, 'ssh'), $relative);
            self::assertSame(0, $this->commandCount($text, 'scp'), $relative);
            self::assertStringContainsString('ssh testlig-vds', $text, $relative);
            self::assertStringContainsString('gha-ssh-close-master.sh', $text, $relative);
            self::assertLessThan(
                strpos($text, 'ssh testlig-vds') ?: \PHP_INT_MAX,
                strpos($text, 'gha-ssh-config.sh') ?: 0,
                $relative,
            );
        }

        $bootstrap = $this->read('.github/workflows/ops-superadmin-bootstrap.yml');
        self::assertStringContainsString('install-helper', $bootstrap);
        self::assertStringContainsString('HELPER_B64', $bootstrap);
        self::assertStringNotContainsString('scp ', $bootstrap);
    }

    public function testNoRetryLoopsOrPasswordFallback(): void
    {
        $paths = array_merge($this->sshWorkflowFiles(), $this->helperScripts());
        foreach ($paths as $path) {
            $text = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/\buntil\b/', $text, $path);
            self::assertDoesNotMatchRegularExpression('/\bfor\b[^\n]*\bssh\b/', $text, $path);
            self::assertDoesNotMatchRegularExpression('/\bssh\b[^\n]*\|\|\s*true/', $text, $path);
            self::assertDoesNotMatchRegularExpression('/\bscp\b[^\n]*\|\|\s*true/', $text, $path);
            self::assertDoesNotMatchRegularExpression('/uses:\s*\S*retry/i', $text, $path);
            self::assertStringNotContainsString('PasswordAuthentication yes', $text, $path);
            self::assertStringNotContainsString('KbdInteractiveAuthentication yes', $text, $path);
            self::assertDoesNotMatchRegularExpression('/StrictHostKeyChecking\s*[:=]\s*no/', $text, $path);
        }

        $ci = $this->read('.github/workflows/ci.yml');
        self::assertStringNotContainsString('webfactory/ssh-agent', $ci);
        self::assertStringNotContainsString('VDS_HOST', $ci);
        self::assertStringNotContainsString('testlig-vds-ssh', $ci);
    }

    public function testShellScriptsHaveValidBashSyntax(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash, 'bash is required to syntax-check SSH scripts');
        foreach ($this->helperScripts() as $path) {
            $script = file_get_contents($path);
            self::assertIsString($script, $path);
            $pipes = [];
            $process = proc_open([$bash, '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process, $path);
            fwrite($pipes[0], $script);
            fclose($pipes[0]);
            $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
            foreach ([1, 2] as $index) {
                if (\is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
            $exit = proc_close($process);
            self::assertSame(0, $exit, $path."\n".$stdout."\n".$stderr);
        }
    }

    /**
     * @return list<string>
     */
    private function workflowFiles(): array
    {
        $paths = glob($this->root().'/.github/workflows/*.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths);
        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function sshWorkflowFiles(): array
    {
        $paths = [];
        foreach ($this->workflowFiles() as $path) {
            $text = (string) file_get_contents($path);
            if (str_contains($text, 'webfactory/ssh-agent') || str_contains($text, 'VDS_HOST') || str_contains($text, 'gha-ssh-config.sh')) {
                $paths[] = $path;
            }
        }
        self::assertCount(9, $paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function helperScripts(): array
    {
        return [
            $this->root().'/deploy/vds/gha-ssh-config.sh',
            $this->root().'/deploy/vds/gha-ssh-close-master.sh',
            $this->root().'/deploy/vds/gha-deploy-once.sh',
            $this->root().'/deploy/vds/gha-learning-content-package-import-entry.sh',
            $this->root().'/deploy/vds/gha-learning-content-package-import-remote.sh',
            $this->root().'/deploy/vds/gha-question-package-import-entry.sh',
            $this->root().'/deploy/vds/gha-question-package-import-remote.sh',
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function jobs(string $path): array
    {
        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed);
        $jobs = $parsed['jobs'] ?? null;
        self::assertIsArray($jobs);
        $normalized = [];
        foreach ($jobs as $name => $job) {
            self::assertIsString($name);
            self::assertIsArray($job);
            $normalized[$name] = $job;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $job
     */
    private function isPauseJob(array $job): bool
    {
        $condition = $job['if'] ?? '';

        return \is_string($condition) && str_contains($condition, "vars.VDS_SSH_PAUSED == 'true'");
    }

    /**
     * @param array<mixed> $job
     */
    private function isSshJob(array $job): bool
    {
        $encoded = $this->encode($job);

        return str_contains($encoded, 'webfactory/ssh-agent')
            || str_contains($encoded, 'gha-ssh-config.sh')
            || str_contains($encoded, 'gha-deploy-once.sh');
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): string
    {
        $encoded = json_encode($value);
        self::assertIsString($encoded);

        return $encoded;
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.str_replace('\\', '/', $relative);
        $text = file_get_contents($path);
        self::assertIsString($text, $relative);

        return $text;
    }

    private function commandCount(string $script, string $command): int
    {
        $count = 0;
        $lines = preg_split("/\R/", $script);
        self::assertIsArray($lines);
        $pattern = '/^\s*(?:exec\s+)?'.preg_quote($command, '/').'\s+/';
        foreach ($lines as $line) {
            if (1 === preg_match($pattern, $line)) {
                ++$count;
            }
        }

        return $count;
    }

    private function bashBinary(): ?string
    {
        $candidates = ['bash', 'C:\\Program Files\\Git\\bin\\bash.exe'];
        foreach ($candidates as $candidate) {
            if ('bash' !== $candidate && !is_file($candidate)) {
                continue;
            }
            $process = @proc_open([$candidate, '-c', 'echo ok'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                continue;
            }
            $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            if (\is_string($stdout) && str_contains($stdout, 'ok')) {
                return $candidate;
            }
        }

        return null;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

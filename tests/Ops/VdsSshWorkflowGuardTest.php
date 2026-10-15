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
            '.github/workflows/ops-clamav-preflight.yml',
            '.github/workflows/ops-clamav-install.yml',
            '.github/workflows/ops-clamav-access-probe.yml',
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

    public function testClamavPreflightIsReadOnly(): void
    {
        $workflow = $this->read('.github/workflows/ops-clamav-preflight.yml');
        $remote = $this->read('deploy/vds/gha-clamav-preflight-remote.sh');

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $workflow);
        self::assertStringContainsString('VDS SSH operations are paused', $workflow);
        self::assertStringContainsString('ClamAV preflight skipped because SSH paused', $workflow);
        self::assertSame(1, $this->commandCount($workflow, 'ssh'));
        self::assertSame(0, $this->commandCount($workflow, 'scp'));
        self::assertStringContainsString('ssh testlig-vds bash -s < deploy/vds/gha-clamav-preflight-remote.sh', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertLessThan(
            strpos($workflow, 'ssh testlig-vds') ?: \PHP_INT_MAX,
            strpos($workflow, 'gha-ssh-config.sh') ?: 0,
        );

        self::assertStringContainsString('clamd freshclam sigtool', $remote);
        self::assertDoesNotMatchRegularExpression('/(^|[;&|]\s*)freshclam\b/m', $remote);
        self::assertStringNotContainsString('.env', $remote);
        self::assertStringNotContainsString('printenv', $remote);
        self::assertStringNotContainsString('bin/console', $remote);
        self::assertStringNotContainsString('environ', $remote);
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(apt-get|dnf|yum|pacman|usermod|groupadd|useradd|gpasswd|chmod|chown|clamscan|clamdscan|mkdir|rm|mv|cp|tee|install)\b/m',
            $remote,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bsystemctl\s+(start|stop|restart|reload|enable|disable|mask)\b/',
            $remote,
        );
        self::assertDoesNotMatchRegularExpression('/>>/', $remote);
        self::assertDoesNotMatchRegularExpression('/>(?!\/dev\/null\b|&1\b)/', $remote);
        self::assertStringContainsString('preflight_decides_install=no', $remote);
    }

    public function testClamavAccessProbeIsReadOnly(): void
    {
        $workflow = $this->read('.github/workflows/ops-clamav-access-probe.yml');
        $remote = $this->read('deploy/vds/gha-clamav-access-probe-remote.sh');

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $workflow);
        self::assertStringContainsString('VDS SSH operations are paused', $workflow);
        self::assertStringContainsString('ClamAV access probe skipped because SSH paused', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertSame(1, $this->commandCount($workflow, 'ssh'));
        self::assertSame(0, $this->commandCount($workflow, 'scp'));
        self::assertStringContainsString('ssh testlig-vds bash -s < deploy/vds/gha-clamav-access-probe-remote.sh', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertStringNotContainsString('TESTLIG_CLAMAV_ACCESS_ROOT', $workflow);
        self::assertStringNotContainsString('curl ', $workflow);
        self::assertLessThan(
            strpos($workflow, 'ssh testlig-vds') ?: \PHP_INT_MAX,
            strpos($workflow, 'gha-ssh-config.sh') ?: 0,
        );

        self::assertStringContainsString('dac_check_proves_selinux=no', $remote);
        self::assertStringContainsString('root_connection_counts_as_app=no', $remote);
        self::assertStringContainsString('app_user=not_app_user', $remote);
        self::assertStringContainsString('php_process=unknown', $remote);
        self::assertStringContainsString('timeout 2', $remote);
        self::assertStringContainsString('timeout 5', $remote);
        self::assertStringContainsString('nPING', $remote);
        self::assertStringContainsString('nVERSION', $remote);
        self::assertStringNotContainsString('INSTREAM', $remote);
        self::assertStringNotContainsString('printenv', $remote);
        self::assertStringNotContainsString('cmdline', $remote);
        self::assertStringNotContainsString('environ', $remote);
        self::assertStringNotContainsString('echo "$value"', $remote);
        self::assertStringNotContainsString('bin/console', $remote);
        self::assertStringNotContainsString('cache:warmup', $remote);
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(apt-get|dnf|yum|pacman|usermod|groupadd|useradd|gpasswd|chmod|chown|setfacl|setenforce|clamscan|clamdscan|mkdir|rm|mv|cp|tee|install)\b/m',
            $remote,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bsystemctl\s+(start|stop|restart|reload|enable|disable|mask)\b/',
            $remote,
        );
        self::assertDoesNotMatchRegularExpression('/>>/', $remote);
        self::assertDoesNotMatchRegularExpression('/>(?!\/dev\/null\b|&1\b)/', $remote);
        self::assertStringContainsString('env_values_logged=no', $remote);
        self::assertStringContainsString('loaded_main=unproven', $remote);
        self::assertStringContainsString('loaded_bytecode=unproven', $remote);

        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/clamav-access-probe-'.bin2hex(random_bytes(4));
        mkdir($root.'/proc/10', 0777, true);
        mkdir($root.'/proc/11', 0777, true);
        mkdir($root.'/etc/clamd.d', 0777, true);
        mkdir($root.'/var/lib/clamav', 0777, true);
        mkdir($root.'/home/testlig.net/shared', 0777, true);
        mkdir($root.'/home/testlig.net/app', 0777, true);
        file_put_contents($root.'/proc/10/comm', "lsphp\n");
        file_put_contents($root.'/proc/11/comm', "lsphp\n");
        file_put_contents($root.'/proc/10/status', "Name:\tlsphp\nUid:\t10\t10\t10\t10\nGid:\t10\t10\t10\t10\nGroups:\t10\n");
        file_put_contents($root.'/proc/11/status', "Name:\tlsphp\nUid:\t11\t11\t11\t11\nGid:\t11\t11\t11\t11\nGroups:\t11\n");
        file_put_contents($root.'/etc/clamd.d/scan.conf', "LocalSocket /run/clamd.scan/clamd.sock\nDatabaseDirectory /var/lib/clamav\n");
        file_put_contents($root.'/etc/freshclam.conf', "DatabaseDirectory /var/lib/clamav\n");
        $header = "ClamAV-VDB:UNIQUEHEADERTEXT:59:10:63:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:bbbb:clamav:1\n";
        file_put_contents($root.'/var/lib/clamav/daily.cvd', str_pad($header, 512, "\0"));
        file_put_contents(
            $root.'/home/testlig.net/shared/.env.local',
            "OTHER_SECRET=super-secret-value\nQUESTION_IMAGE_CLAMAV_SOCKET=\nQUESTION_IMAGE_CLAMAV_DATABASE=/not/the/measured/dir\n",
        );
        self::assertFileExists($root.'/proc/10/comm');
        $before = $this->fixtureFiles($root);
        $output = $this->runProbe($bash, $this->bashPath($bash, $root));
        self::assertSame($before, $this->fixtureFiles($root));
        self::assertStringContainsString('php_process uid=10 ', $output);
        self::assertStringContainsString('php_process uid=11 ', $output);
        self::assertStringContainsString('app_user=unresolved', $output);
        self::assertStringContainsString('app_ping=not_attempted', $output);
        self::assertStringContainsString('signature_daily_cvd_version=59', $output);
        self::assertStringContainsString('shared_socket_key=empty', $output);
        self::assertStringContainsString('shared_database_key=differ', $output);
        self::assertStringContainsString('database_directory_agreement=match', $output);
        self::assertStringNotContainsString('UNIQUEHEADERTEXT', $output);
        self::assertStringNotContainsString('super-secret-value', $output);
        self::assertStringNotContainsString('/not/the/measured/dir', $output);
        self::assertStringNotContainsString('OTHER_SECRET', $output);

        $empty = str_replace('\\', '/', sys_get_temp_dir()).'/clamav-access-probe-empty-'.bin2hex(random_bytes(4));
        mkdir($empty.'/proc', 0777, true);
        $emptyOutput = $this->runProbe($bash, $this->bashPath($bash, $empty));
        self::assertStringContainsString('php_process=unknown', $emptyOutput);
        self::assertStringNotContainsString('app_user_basis=single_live_lsphp_uid', $emptyOutput);
    }

    public function testClamavInstallStaysClosedWithoutConfirmation(): void
    {
        $workflow = $this->read('.github/workflows/ops-clamav-install.yml');
        $remote = $this->read('deploy/vds/gha-clamav-install-remote.sh');
        $accept = $this->read('deploy/vds/gha-clamav-accept.php');

        self::assertStringContainsString('default: ""', $workflow);
        self::assertStringContainsString("github.event.inputs.confirm == 'install-clamav-isolated-accept'", $workflow);
        self::assertStringContainsString("github.event.inputs.confirm != 'install-clamav-isolated-accept'", $workflow);
        self::assertStringContainsString('ClamAV install not confirmed', $workflow);
        self::assertStringContainsString('ClamAV install skipped because SSH paused', $workflow);
        self::assertSame(1, $this->commandCount($workflow, 'ssh'));
        self::assertSame(0, $this->commandCount($workflow, 'scp'));
        self::assertStringNotContainsString('TESTLIG_CLAMAV_TEST_ROOT', $workflow);
        self::assertStringNotContainsString('TESTLIG_PHP_BIN', $workflow);
        self::assertStringNotContainsString('.env.local', $workflow);
        self::assertStringNotContainsString('dnf update', $remote);
        self::assertStringNotContainsString('dnf upgrade', $remote);
        self::assertStringNotContainsString('usermod', $remote);
        self::assertStringNotContainsString('setenforce', $remote);
        self::assertStringNotContainsString('setsebool', $remote);
        self::assertStringNotContainsString('getenforce', $remote);
        self::assertStringNotContainsString('firewall-cmd', $remote);
        self::assertStringNotContainsString('dnf remove', $remote);
        self::assertStringNotContainsString('.env.local', $remote);
        self::assertStringContainsString('https://secure.eicar.org/eicar.com.txt', $accept);
        self::assertStringNotContainsString('file_put_contents($path, \'', $accept);
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
        self::assertCount(12, $paths);

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
            $this->root().'/deploy/vds/gha-clamav-preflight-remote.sh',
            $this->root().'/deploy/vds/gha-clamav-install-remote.sh',
            $this->root().'/deploy/vds/gha-clamav-access-probe-remote.sh',
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

    private function bashPath(string $bash, string $path): string
    {
        if (1 !== preg_match('/^([A-Za-z]):\\/(.*)$/', $path, $matches)) {
            return $path;
        }
        $suffix = strtolower($matches[1]).'/'.$matches[2];
        $pipes = [];
        $process = proc_open([$bash, '-c', 'if [ -d /mnt/c ]; then echo mnt; else echo unix; fi'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return str_contains($stdout, 'mnt') ? '/mnt/'.$suffix : '/'.$suffix;
    }

    private function runProbe(string $bash, string $root): string
    {
        $script = str_replace(
            'root="${TESTLIG_CLAMAV_ACCESS_ROOT:-}"',
            "root='".str_replace("'", '', $root)."'",
            $this->read('deploy/vds/gha-clamav-access-probe-remote.sh'),
        );
        $pipes = [];
        $process = proc_open(
            [$bash],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
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
        self::assertSame(0, $exit, $stdout."\n".$stderr);

        return $stdout;
    }

    /**
     * @return list<string>
     */
    private function fixtureFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
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

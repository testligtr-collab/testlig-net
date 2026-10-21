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
            '.github/workflows/ops-php-identity-exit-diagnostic.yml',
            '.github/workflows/ops-php-security-inventory.yml',
            '.github/workflows/ops-web-runtime-identity-window.yml',
            '.github/workflows/ops-clamav-socket-group.yml',
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
        self::assertStringContainsString('ssh testlig-vds "export ACCESS_PROBE_PHP_B64=$(printf %q "$probe_b64"); bash -s" < deploy/vds/gha-clamav-access-probe-remote.sh', $workflow);
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
        self::assertStringContainsString('web_identity_source=recorded_request', $remote);
        self::assertStringContainsString('access_context=cli_same_identity', $remote);
        self::assertStringContainsString('web_selinux_acceptance=no', $remote);
        self::assertStringContainsString('runuser -u testl3865 --', $remote);
        self::assertStringNotContainsString('runuser -u testl3865 -g', $remote);
        self::assertStringNotContainsString('runuser -u testl3865 -G', $remote);
        self::assertStringContainsString('php_proc_dirs=', $remote);
        self::assertStringContainsString('php_proc_unreadable=', $remote);
        self::assertStringContainsString('php_proc_vanished=', $remote);
        self::assertStringContainsString('php_proc_lsphp=', $remote);
        self::assertStringContainsString('php_proc_invalid_id=', $remote);
        self::assertStringContainsString('php_process=none', $remote);
        self::assertStringContainsString('php_process=unreadable', $remote);
        self::assertStringContainsString('php_process=incomplete', $remote);
        self::assertStringContainsString('php_process=timed_out', $remote);
        self::assertStringNotContainsString('php_process=unknown', $remote);
        self::assertStringNotContainsString('curl ', $remote);
        self::assertStringContainsString('timeout 2', $remote);
        self::assertStringContainsString('timeout 12', $remote);
        self::assertStringContainsString('site_account_uid_match=no', $remote);
        self::assertStringContainsString('site_account_name_match=no', $remote);
        self::assertStringContainsString('site_owner_match=no', $remote);
        self::assertStringContainsString('process_identity_match=no', $remote);
        self::assertStringContainsString('runuser_group_options=none', $remote);
        self::assertStringNotContainsString('single_live_lsphp_uid', $remote);
        self::assertStringNotContainsString('stream_socket_client', $remote);
        $probe = $this->read('deploy/vds/gha-clamav-access-probe.php');
        self::assertStringContainsString('nPING', $probe);
        self::assertStringContainsString('nVERSION', $probe);
        self::assertStringContainsString('TIMEOUT_SECONDS = 5.0', $probe);
        self::assertStringContainsString('stream_select', $probe);
        self::assertSame(2, substr_count($probe, 'self::command('));
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
        self::assertStringContainsString('php_process=multiple', $output);
        self::assertStringContainsString('site_account_uid_match=no', $output);
        self::assertStringContainsString('app_dac=not_attempted', $output);
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
        self::assertStringContainsString('php_proc_dirs=0', $emptyOutput);
        self::assertStringContainsString('php_proc_unreadable=0', $emptyOutput);
        self::assertStringContainsString('php_proc_vanished=0', $emptyOutput);
        self::assertStringContainsString('php_proc_lsphp=0', $emptyOutput);
        self::assertStringContainsString('php_proc_invalid_id=0', $emptyOutput);
        self::assertStringContainsString('php_process=none', $emptyOutput);
        $this->assertScanDoesNotImpersonate($emptyOutput);

        $other = str_replace('\\', '/', sys_get_temp_dir()).'/clamav-access-probe-other-'.bin2hex(random_bytes(4));
        mkdir($other.'/proc/10', 0777, true);
        mkdir($other.'/home/testlig.net', 0777, true);
        file_put_contents($other.'/proc/10/comm', "lsphp\n");
        file_put_contents($other.'/proc/10/status', "Name:\tlsphp\nUid:\t10\t10\t10\t10\nGid:\t10\t10\t10\t10\nGroups:\t10\n");
        $otherBefore = $this->fixtureFiles($other);
        $otherOutput = $this->runProbe($bash, $this->bashPath($bash, $other));
        self::assertSame($otherBefore, $this->fixtureFiles($other));
        self::assertStringContainsString('php_process uid=10 ', $otherOutput);
        self::assertStringContainsString('site_account_uid_match=no', $otherOutput);
        self::assertStringContainsString('process_identity_match=no', $otherOutput);
        self::assertStringContainsString('app_dac=not_attempted', $otherOutput);
        self::assertStringContainsString('app_ping=not_attempted', $otherOutput);
        self::assertStringNotContainsString('app_user_basis=site_account', $otherOutput);
        self::assertStringNotContainsString('app_user=testl3865', $otherOutput);
    }

    public function testClamavAccessProbeClassifiesProcessScan(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);

        $otherName = $this->probeRoot('other-name');
        $this->writeLsphp($otherName, '12', "php\n", $this->lsphpStatus('12'));
        $otherNameOutput = $this->runProbe($bash, $this->bashPath($bash, $otherName));
        self::assertStringContainsString('php_proc_dirs=1', $otherNameOutput);
        self::assertStringContainsString('php_proc_lsphp=0', $otherNameOutput);
        self::assertStringContainsString('php_process=none', $otherNameOutput);
        self::assertStringNotContainsString('php_process uid=', $otherNameOutput);
        $this->assertScanDoesNotImpersonate($otherNameOutput);

        $invalid = $this->probeRoot('invalid-id');
        $this->writeLsphp($invalid, '13', "lsphp\n", "Name:\tlsphp\nUid:\tbad\nGid:\t13\t13\t13\t13\n");
        $invalidOutput = $this->runProbe($bash, $this->bashPath($bash, $invalid));
        self::assertStringContainsString('php_proc_dirs=1', $invalidOutput);
        self::assertStringContainsString('php_proc_lsphp=1', $invalidOutput);
        self::assertStringContainsString('php_proc_invalid_id=1', $invalidOutput);
        self::assertStringContainsString('php_process=invalid_id', $invalidOutput);
        self::assertStringNotContainsString('php_process uid=', $invalidOutput);
        $this->assertScanDoesNotImpersonate($invalidOutput);

        $unreadable = $this->probeRoot('unreadable');
        $this->writeLsphp($unreadable, '14', "lsphp\n", $this->lsphpStatus('14'));
        $this->denyRead($bash, $unreadable.'/proc/14/comm');
        $this->denyRead($bash, $unreadable.'/proc/14/status');
        $unreadableOutput = $this->runProbe($bash, $this->bashPath($bash, $unreadable));
        self::assertStringContainsString('php_proc_dirs=1', $unreadableOutput);
        self::assertStringContainsString('php_proc_unreadable=1', $unreadableOutput);
        self::assertStringContainsString('php_proc_lsphp=0', $unreadableOutput);
        self::assertStringContainsString('php_process=unreadable', $unreadableOutput);
        $this->assertScanDoesNotImpersonate($unreadableOutput);

        $vanished = $this->probeRoot('vanished');
        mkdir($vanished.'/proc/15', 0777, true);
        $this->writeSiteFixtures($vanished);
        mkdir($vanished.'/home/testlig.net', 0777, true);
        $this->writeLsphp($vanished, '16', "lsphp\n", $this->lsphpStatus('4242'));
        $vanishedOutput = $this->runProbe($bash, $this->bashPath($bash, $vanished));
        self::assertStringContainsString('php_proc_dirs=2', $vanishedOutput);
        self::assertStringContainsString('php_proc_vanished=1', $vanishedOutput);
        self::assertStringContainsString('php_proc_unreadable=0', $vanishedOutput);
        self::assertStringContainsString('php_proc_lsphp=1', $vanishedOutput);
        self::assertStringContainsString('php_process=incomplete', $vanishedOutput);
        $this->assertScanDoesNotImpersonate($vanishedOutput);

        $blocked = $this->probeRoot('blocked-match');
        $this->writeSiteFixtures($blocked);
        mkdir($blocked.'/home/testlig.net', 0777, true);
        $this->writeLsphp($blocked, '17', "lsphp\n", $this->lsphpStatus('4242'));
        $this->writeLsphp($blocked, '18', "lsphp\n", $this->lsphpStatus('18'));
        $this->denyRead($bash, $blocked.'/proc/18/comm');
        $this->denyRead($bash, $blocked.'/proc/18/status');
        $blockedOutput = $this->runProbe($bash, $this->bashPath($bash, $blocked));
        self::assertStringContainsString('php_proc_unreadable=1', $blockedOutput);
        self::assertStringContainsString('php_proc_lsphp=1', $blockedOutput);
        self::assertStringContainsString('php_process=unreadable', $blockedOutput);
        $this->assertScanDoesNotImpersonate($blockedOutput);

        $matched = $this->probeRoot('site-user');
        $this->writeSiteFixtures($matched);
        mkdir($matched.'/home/testlig.net', 0777, true);
        $this->writeLsphp($matched, '19', "lsphp\n", $this->lsphpStatus('4242'));
        $matchedBefore = $this->fixtureFiles($matched);
        $matchedOutput = $this->runProbe($bash, $this->bashPath($bash, $matched));
        self::assertSame($matchedBefore, $this->fixtureFiles($matched));
        self::assertStringContainsString('php_proc_dirs=1', $matchedOutput);
        self::assertStringContainsString('php_proc_unreadable=0', $matchedOutput);
        self::assertStringContainsString('php_proc_vanished=0', $matchedOutput);
        self::assertStringContainsString('php_proc_lsphp=1', $matchedOutput);
        self::assertStringContainsString('php_proc_invalid_id=0', $matchedOutput);
        self::assertStringContainsString('php_process uid=4242 ', $matchedOutput);
        self::assertStringContainsString('user=testl3865 ', $matchedOutput);
        self::assertStringContainsString('php_process=observed', $matchedOutput);
        self::assertStringContainsString('site_account_uid_match=no', $matchedOutput);
        self::assertStringContainsString('app_dac=not_attempted', $matchedOutput);
        self::assertStringContainsString('app_ping=not_attempted', $matchedOutput);
        self::assertStringNotContainsString('app_user=root', $matchedOutput);

        $badUid = $this->probeRoot('bad-uid');
        $this->writeSiteFixtures($badUid);
        mkdir($badUid.'/home/testlig.net', 0777, true);
        $this->writeLsphp($badUid, '22', "lsphp\n", $this->lsphpStatus('4242'));
        $this->writeLsphp($badUid, '23', "lsphp\n", "Name:\tlsphp\nUid:\tbad\nGid:\t23\t23\t23\t23\n");
        $badUidOutput = $this->runProbe($bash, $this->bashPath($bash, $badUid));
        self::assertStringContainsString('php_proc_lsphp=2', $badUidOutput);
        self::assertStringContainsString('php_proc_invalid_id=1', $badUidOutput);
        self::assertStringContainsString('php_process=invalid_id', $badUidOutput);
        self::assertStringContainsString('php_process uid=4242 ', $badUidOutput);
        $this->assertScanDoesNotImpersonate($badUidOutput);
        self::assertStringContainsString('app_version_daily=not_attempted', $badUidOutput);

        $badGid = $this->probeRoot('bad-gid');
        $this->writeSiteFixtures($badGid);
        mkdir($badGid.'/home/testlig.net', 0777, true);
        $this->writeLsphp($badGid, '24', "lsphp\n", $this->lsphpStatus('4242'));
        $this->writeLsphp($badGid, '25', "lsphp\n", "Name:\tlsphp\nUid:\t25\t25\t25\t25\nGid:\tbad\n");
        $badGidOutput = $this->runProbe($bash, $this->bashPath($bash, $badGid));
        self::assertStringContainsString('php_proc_lsphp=2', $badGidOutput);
        self::assertStringContainsString('php_proc_invalid_id=1', $badGidOutput);
        self::assertStringContainsString('php_process=invalid_id', $badGidOutput);
        self::assertStringContainsString('php_process uid=4242 ', $badGidOutput);
        $this->assertScanDoesNotImpersonate($badGidOutput);
        self::assertStringContainsString('app_version_daily=not_attempted', $badGidOutput);

        $rootUser = $this->probeRoot('root-user');
        $this->writeSiteFixtures($rootUser);
        mkdir($rootUser.'/home/testlig.net', 0777, true);
        $this->writeLsphp($rootUser, '20', "lsphp\n", $this->lsphpStatus('0'));
        $rootOutput = $this->runProbe($bash, $this->bashPath($bash, $rootUser));
        self::assertStringContainsString('php_process uid=0 ', $rootOutput);
        self::assertStringContainsString('user=root ', $rootOutput);
        self::assertStringContainsString('site_account_uid_match=no', $rootOutput);
        self::assertStringContainsString('app_dac=not_attempted', $rootOutput);
        $this->assertScanDoesNotImpersonate($rootOutput);

        $timedOut = $this->probeRoot('timed-out');
        $this->writeSiteFixtures($timedOut);
        file_put_contents($timedOut.'/fixture-date', <<<'BASH'
#!/bin/bash
dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
file="$dir/date-calls"
n=0
if [ -f "$file" ]; then
  n=$(cat "$file" 2>/dev/null || printf '%s' 0)
fi
case "$n" in
  ''|*[!0-9]*) n=0 ;;
esac
n=$((n + 1))
printf '%s\n' "$n" > "$file"
if [ "$n" -le 1 ]; then
  printf '%s\n' 1000000000
else
  printf '%s\n' 1000000002
fi
BASH);
        mkdir($timedOut.'/home/testlig.net', 0777, true);
        $this->writeLsphp($timedOut, '21', "lsphp\n", $this->lsphpStatus('4242'));
        $timedOutOutput = $this->runProbe($bash, $this->bashPath($bash, $timedOut));
        self::assertStringContainsString('php_process=timed_out', $timedOutOutput);
        self::assertStringNotContainsString('php_process uid=', $timedOutOutput);
        $this->assertScanDoesNotImpersonate($timedOutOutput);
    }

    public function testRecordedWebIdentityGatesClamavAccess(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);

        $ownerDiffers = $this->recordedProbeRoot('owner-differs', '5002', '4999', 'testl3865', 'match', 'match');
        $ownerOutput = $this->runProbe($bash, $this->bashPath($bash, $ownerDiffers));
        self::assertStringContainsString('site_owner_match=no', $ownerOutput);
        self::assertStringContainsString('app_dac=not_attempted', $ownerOutput);
        self::assertStringContainsString('app_ping=not_attempted', $ownerOutput);
        self::assertFileDoesNotExist($ownerDiffers.'/dac-attempts');
        self::assertFileDoesNotExist($ownerDiffers.'/connect-attempts');

        $accountDiffers = $this->recordedProbeRoot('account-differs', '4999', '5002', 'testl3865', 'match', 'match');
        $accountOutput = $this->runProbe($bash, $this->bashPath($bash, $accountDiffers));
        self::assertStringContainsString('site_account_uid_match=no', $accountOutput);
        self::assertStringContainsString('app_dac=not_attempted', $accountOutput);
        self::assertFileDoesNotExist($accountDiffers.'/dac-attempts');

        $nameDiffers = $this->recordedProbeRoot('name-differs', '5002', '5002', 'other', 'match', 'match');
        $nameOutput = $this->runProbe($bash, $this->bashPath($bash, $nameDiffers));
        self::assertStringContainsString('site_account_name_match=no', $nameOutput);
        self::assertStringContainsString('app_dac=not_attempted', $nameOutput);
        self::assertFileDoesNotExist($nameDiffers.'/dac-attempts');

        $egidDiffers = $this->recordedProbeRoot('egid-differs', '5002', '5002', 'testl3865', 'egid', 'match');
        $egidOutput = $this->runProbe($bash, $this->bashPath($bash, $egidDiffers));
        self::assertStringContainsString('process_identity_match=no', $egidOutput);
        self::assertStringContainsString('app_dac=not_attempted', $egidOutput);
        self::assertFileDoesNotExist($egidDiffers.'/dac-attempts');

        $extraGroup = $this->recordedProbeRoot('extra-group', '5002', '5002', 'testl3865', 'extra', 'match');
        $extraOutput = $this->runProbe($bash, $this->bashPath($bash, $extraGroup));
        self::assertStringContainsString('process_identity_match=no', $extraOutput);
        self::assertStringContainsString('app_dac=not_attempted', $extraOutput);
        self::assertFileDoesNotExist($extraGroup.'/dac-attempts');

        $missingGroup = $this->recordedProbeRoot('missing-group', '5002', '5002', 'testl3865', 'missing', 'match');
        $missingOutput = $this->runProbe($bash, $this->bashPath($bash, $missingGroup));
        self::assertStringContainsString('process_identity_match=no', $missingOutput);
        self::assertFileDoesNotExist($missingGroup.'/dac-attempts');

        $noPosix = $this->recordedProbeRoot('no-posix', '5002', '5002', 'testl3865', 'unavailable', 'match');
        $noPosixOutput = $this->runProbe($bash, $this->bashPath($bash, $noPosix));
        self::assertStringContainsString('process_identity=unavailable', $noPosixOutput);
        self::assertStringContainsString('app_dac=not_attempted', $noPosixOutput);
        self::assertFileDoesNotExist($noPosix.'/dac-attempts');
        self::assertFileDoesNotExist($noPosix.'/connect-attempts');

        $connectDiffers = $this->recordedProbeRoot('connect-differs', '5002', '5002', 'testl3865', 'match', 'differ');
        $connectOutput = $this->runProbe($bash, $this->bashPath($bash, $connectDiffers));
        self::assertStringContainsString('process_identity_match=yes', $connectOutput);
        self::assertStringContainsString('connecting_process_identity_match=no', $connectOutput);
        self::assertStringContainsString('socket_dac_r=yes', $connectOutput);
        self::assertStringContainsString('socket_dac_w=yes', $connectOutput);
        self::assertStringContainsString('app_ping=not_attempted', $connectOutput);
        self::assertFileDoesNotExist($connectDiffers.'/connect-attempts');

        $matched = $this->recordedProbeRoot('matched', '5002', '5002', 'testl3865', 'match', 'match');
        $matchedOutput = $this->runProbe($bash, $this->bashPath($bash, $matched));
        self::assertStringContainsString('web_identity_source=recorded_request', $matchedOutput);
        self::assertStringContainsString('access_context=cli_same_identity', $matchedOutput);
        self::assertStringContainsString('web_selinux_acceptance=no', $matchedOutput);
        self::assertStringContainsString('site_account_uid_match=yes', $matchedOutput);
        self::assertStringContainsString('site_account_name_match=yes', $matchedOutput);
        self::assertStringContainsString('site_owner_match=yes', $matchedOutput);
        self::assertStringContainsString('process_identity_match=yes', $matchedOutput);
        self::assertStringContainsString('socket_dac_r=yes', $matchedOutput);
        self::assertStringContainsString('socket_dac_w=yes', $matchedOutput);
        self::assertStringNotContainsString('socket_dac_x=', $matchedOutput);
        self::assertStringContainsString('socket_ancestor_0_dac_x=yes', $matchedOutput);
        self::assertStringContainsString('signature_dir_dac_r=yes', $matchedOutput);
        self::assertStringContainsString('signature_dir_dac_w=yes', $matchedOutput);
        self::assertStringContainsString('signature_dir_dac_x=yes', $matchedOutput);
        self::assertStringContainsString('signature_daily_cvd_dac_r=yes', $matchedOutput);
        self::assertStringContainsString('signature_daily_cvd_dac_w=no', $matchedOutput);
        self::assertStringNotContainsString('signature_daily_cvd_dac_x=', $matchedOutput);
        self::assertStringContainsString('app_ping=pong', $matchedOutput);
        self::assertStringContainsString('app_version_daily=59', $matchedOutput);
        self::assertFileExists($matched.'/connect-attempts');
    }

    public function testRecordedIdentityRunsRealHelperBytesWithoutReadingBashStdin(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $remote = $this->read('deploy/vds/gha-clamav-access-probe-remote.sh');
        self::assertStringContainsString(
            'timeout 12 runuser -u testl3865 -- /usr/local/lsws/lsphp83/bin/php -r "$probe_php_code" -- "$@" </dev/null',
            $remote,
        );
        self::assertStringNotContainsString('base64 -d | timeout', $remote);
        self::assertStringNotContainsString('identity_decode_status', $remote);
        self::assertStringContainsString('ulimit -c 0', $remote);

        $helper = $this->read('deploy/vds/gha-clamav-access-probe.php');
        $expected = $this->directHelperIdentity($helper);
        self::assertMatchesRegularExpression('/^(process_identity=unavailable|process_euid=[0-9]+)$/m', $expected);

        $root = $this->identityPipelineRoot('real-helper', $bash);
        $output = $this->runProbe(
            $bash,
            $this->bashPath($bash, $root),
            "ACCESS_PROBE_PHP_B64='".base64_encode($helper)."'\n",
            "echo bash_continues=yes\n",
        );
        self::assertStringContainsString($expected, $output);
        self::assertStringContainsString('identity_helper_started=yes', $output);
        self::assertStringNotContainsString('identity_decode_status', $output);
        self::assertStringContainsString('identity_command_status=0', $output);
        self::assertStringContainsString('identity_failure=none', $output);
        self::assertStringContainsString('identity_caller_continues=yes', $output);
        self::assertStringContainsString('bash_continues=yes', $output);
        if ("process_euid=5002\nprocess_egid=5002\nprocess_group=5002" !== $expected) {
            self::assertStringNotContainsString('process_identity_match=yes', $output);
            self::assertStringContainsString('app_dac=not_attempted', $output);
            self::assertStringContainsString('app_ping=not_attempted', $output);
            self::assertFileDoesNotExist($root.'/dac-attempts');
            self::assertFileDoesNotExist($root.'/connect-attempts');
        }
    }

    public function testRecordedIdentityDashRClosesStdinAndKeepsDataArgsSeparate(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $helper = $this->read('deploy/vds/gha-clamav-access-probe.php');
        $root = $this->identityPipelineRoot('dash-r', $bash);
        file_put_contents($root.'/fixture-php-bin', <<<'BASH'
#!/bin/bash
dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
code=""
data=""
prev=""
for arg in "$@"; do
  if [ "$prev" = "-r" ]; then
    code="$arg"
  fi
  if [ "$prev" = "--" ]; then
    data="$arg"
  fi
  prev="$arg"
done
printf '%s\n' "$data" > "$dir/launcher-data"
if printf '%s' "$code" | grep -q 'clamav_access_probe_cli_main();'; then
  printf '%s\n' yes > "$dir/launcher-entry"
else
  printf '%s\n' no > "$dir/launcher-entry"
fi
if printf '%s' "$code" | grep -F -q -- '/probe-data-socket-not-in-source'; then
  printf '%s\n' embedded > "$dir/launcher-separation"
else
  printf '%s\n' separated > "$dir/launcher-separation"
fi
payload=$(cat)
if [ -z "$payload" ]; then
  printf '%s\n' EOF > "$dir/launcher-stdin"
else
  printf '%s\n' CONSUMED > "$dir/launcher-stdin"
fi
exit 0
BASH);
        $output = $this->runIdentityProbe($bash, $root, base64_encode($helper));
        self::assertSame("--identity\n", (string) file_get_contents($root.'/launcher-data'));
        self::assertSame("yes\n", (string) file_get_contents($root.'/launcher-entry'));
        self::assertSame("separated\n", (string) file_get_contents($root.'/launcher-separation'));
        self::assertSame("EOF\n", (string) file_get_contents($root.'/launcher-stdin'));
        self::assertStringContainsString('bash_continues=yes', $output);
        self::assertStringNotContainsString('<?php', $output);
        self::assertStringNotContainsString($root, $output);
    }

    public function testRecordedIdentityInvalidAndPartialBase64StayClosed(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $partial = base64_encode("<?php\necho \"identity_helper_started=yes\\nprocess_euid=5002\\nprocess_egid=5002\\nprocess_group=5002\\n\";\n").'***';
        foreach (['@@@not-base64@@@', $partial] as $payload) {
            $root = $this->identityPipelineRoot('decode', $bash);
            $output = $this->runIdentityProbe($bash, $root, $payload);
            self::assertStringContainsString('identity_failure=unknown', $output);
            self::assertStringContainsString('identity_command_status=not_attempted', $output);
            self::assertStringNotContainsString('identity_decode_status', $output);
            self::assertStringContainsString('identity_helper_started=no', $output);
            self::assertStringNotContainsString('identity_failure=php', $output);
            $this->assertIdentityGateStaysClosed($output, $root);
        }
    }

    public function testRecordedIdentityCommandCannotStart(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->identityPipelineRoot('command-start', $bash);
        file_put_contents($root.'/fixture-php-bin', "#!/bin/bash\nexit 127\n");
        $output = $this->runIdentityProbe($bash, $root, base64_encode($this->probeEntrySource(<<<'PHP'
function clamav_access_probe_cli_main(): void
{
    echo "identity_helper_started=yes\n";
}
PHP)));
        self::assertStringContainsString('identity_failure=command_start', $output);
        self::assertStringNotContainsString('identity_decode_status', $output);
        self::assertStringContainsString('identity_command_status=127', $output);
        self::assertStringContainsString('identity_helper_started=no', $output);
        self::assertStringNotContainsString('identity_failure=php', $output);
        $this->assertIdentityGateStaysClosed($output, $root);
    }

    public function testRecordedIdentityPhpErrorDiscardsPartialOutput(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->identityPipelineRoot('php-error', $bash);
        $payload = base64_encode($this->probeEntrySource(<<<'PHP'
function clamav_access_probe_cli_main(): void
{
    echo "identity_helper_started=yes\nprocess_euid=5002\nprocess_egid=5002\nprocess_group=5002\n";
    exit(1);
}
PHP));
        $output = $this->runIdentityProbe($bash, $root, $payload);
        self::assertStringContainsString('identity_failure=php', $output);
        self::assertStringNotContainsString('identity_decode_status', $output);
        self::assertStringContainsString('identity_command_status=1', $output);
        self::assertStringContainsString('identity_helper_started=yes', $output);
        self::assertStringNotContainsString('identity_failure=command_start', $output);
        $this->assertIdentityGateStaysClosed($output, $root);
    }

    public function testRecordedIdentityTimeoutDiscardsPartialOutput(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $payload = base64_encode($this->probeEntrySource(<<<'PHP'
function clamav_access_probe_cli_main(): void
{
    echo "identity_helper_started=yes\nprocess_euid=5002\nprocess_egid=5002\nprocess_group=5002\n";
    sleep(30);
}
PHP));
        $root = $this->identityPipelineRoot('timeout', $bash);
        $started = microtime(true);
        $output = $this->runProbe(
            $bash,
            $this->bashPath($bash, $root),
            "ACCESS_PROBE_PHP_B64='".$payload."'\n",
            "echo bash_continues=yes\n",
        );
        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(10.0, $elapsed);
        self::assertLessThan(20.0, $elapsed);
        self::assertStringContainsString('identity_failure=timeout', $output);
        self::assertStringContainsString('identity_command_status=124', $output);
        self::assertStringContainsString('identity_helper_started=yes', $output);
        self::assertStringNotContainsString('identity_failure=php', $output);
        $this->assertIdentityGateStaysClosed($output, $root);
    }

    public function testSocketProbeDiscardsPingVersionUnlessStatusIsZero(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);

        foreach (['139', '1'] as $status) {
            $output = $this->runSocketStatusProbe($bash, 'socket-'.$status, <<<BASH
printf '%s\n' 'LEAKED_SOCKET_STDOUT'
printf '%s\n' 'LEAKED_SOCKET_STDERR' >&2
printf '%s\n' app_ping=pong app_version_daily=59
exit {$status}
BASH);
            self::assertStringContainsString('identity_command_status=0', $output);
            self::assertStringContainsString('identity_failure=none', $output);
            self::assertStringContainsString('process_identity_match=yes', $output);
            self::assertStringContainsString('socket_command_status='.$status, $output);
            self::assertStringContainsString('app_ping=failed', $output);
            self::assertStringContainsString('app_version_daily=failed', $output);
            self::assertStringNotContainsString('app_ping=pong', $output);
            self::assertStringNotContainsString('app_version_daily=59', $output);
            self::assertStringNotContainsString('LEAKED_SOCKET_STDOUT', $output);
            self::assertStringNotContainsString('LEAKED_SOCKET_STDERR', $output);
        }

        $accepted = $this->runSocketStatusProbe($bash, 'socket-ok', <<<'BASH'
printf '%s\n' 'LEAKED_SOCKET_STDOUT'
printf '%s\n' app_ping=pong app_version_daily=59
exit 0
BASH);
        self::assertStringContainsString('process_identity_match=yes', $accepted);
        self::assertStringContainsString('app_ping=pong', $accepted);
        self::assertStringContainsString('app_version_daily=59', $accepted);
        self::assertStringNotContainsString('app_ping=failed', $accepted);
        self::assertStringNotContainsString('socket_command_status=', $accepted);
        self::assertStringNotContainsString('LEAKED_SOCKET_STDOUT', $accepted);

        $started = microtime(true);
        $timedOut = $this->runSocketStatusProbe($bash, 'socket-timeout', <<<'BASH'
printf '%s\n' app_ping=pong app_version_daily=59
sleep 30
exit 0
BASH);
        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(10.0, $elapsed);
        self::assertLessThan(20.0, $elapsed);
        self::assertStringContainsString('app_ping=timed_out', $timedOut);
        self::assertStringContainsString('app_version_daily=timed_out', $timedOut);
        self::assertStringNotContainsString('app_ping=pong', $timedOut);
        self::assertStringNotContainsString('app_ping=failed', $timedOut);
        self::assertStringNotContainsString('socket_command_status=', $timedOut);

        $mismatch = $this->runSocketStatusProbe($bash, 'socket-mismatch', <<<'BASH'
printf '%s\n' process_identity_match=no app_ping=pong app_version_daily=59
exit 0
BASH);
        self::assertStringContainsString('connecting_process_identity_match=no', $mismatch);
        self::assertStringContainsString('app_ping=not_attempted', $mismatch);
        self::assertStringContainsString('app_version_daily=not_attempted', $mismatch);
        self::assertStringNotContainsString('app_ping=pong', $mismatch);
        self::assertStringNotContainsString('app_ping=failed', $mismatch);

        $closed = $this->recordedProbeRoot('socket-status', '5002', '4999', 'testl3865', 'match', 'match');
        $classified = $this->runProbe($bash, $this->bashPath($bash, $closed), '', <<<'BASH'
emit_socket_probe '' "$(printf '%s\n' 'app_ping=pong' 'app_version_daily=59' 'LEAKED_SOCKET_STDOUT')"
printf '%s\n' "classify_missing_ping=${app_ping}" "classify_missing_version=${app_version}"
emit_socket_probe 'nope' "$(printf '%s\n' 'app_ping=pong' 'app_version_daily=59' 'LEAKED_SOCKET_STDOUT')"
printf '%s\n' "classify_corrupt_ping=${app_ping}" "classify_corrupt_version=${app_version}"
BASH);
        self::assertStringContainsString('socket_command_status=unknown', $classified);
        self::assertStringContainsString('classify_missing_ping=failed', $classified);
        self::assertStringContainsString('classify_missing_version=failed', $classified);
        self::assertStringContainsString('classify_corrupt_ping=failed', $classified);
        self::assertStringContainsString('classify_corrupt_version=failed', $classified);
        self::assertStringNotContainsString('app_ping=pong', $classified);
        self::assertStringNotContainsString('app_version_daily=59', $classified);
        self::assertStringNotContainsString('LEAKED_SOCKET_STDOUT', $classified);
    }

    public function testPhpIdentityExitDiagnosticStaysReadOnly(): void
    {
        $workflow = $this->read('.github/workflows/ops-php-identity-exit-diagnostic.yml');
        $remote = $this->read('deploy/vds/gha-php-identity-exit-remote.sh');

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString("github.event.inputs.confirm == 'diagnose-php-identity-exit'", $workflow);
        self::assertStringContainsString("github.event.inputs.confirm != 'diagnose-php-identity-exit'", $workflow);
        self::assertStringContainsString('PHP identity exit diagnostic not confirmed', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString('PHP identity exit diagnostic skipped because SSH paused', $workflow);
        self::assertStringContainsString('group: testlig-vds-ssh', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertSame(1, $this->commandCount($workflow, 'ssh'));
        self::assertSame(0, $this->commandCount($workflow, 'scp'));
        self::assertStringContainsString('ssh testlig-vds "bash -s" < deploy/vds/gha-php-identity-exit-remote.sh', $workflow);
        self::assertStringNotContainsString('gha-clamav-access-probe', $workflow);
        self::assertStringContainsString('ulimit -c 0', $remote);
        self::assertStringContainsString('runuser -u testl3865 --', $remote);
        self::assertStringNotContainsString(' -g ', $remote);
        self::assertStringNotContainsString(' -G ', $remote);
        self::assertStringNotContainsString('php -n', $remote);
        self::assertStringNotContainsString('phpinfo', $remote);
        self::assertStringNotContainsString('printenv', $remote);
        self::assertStringNotContainsString('cmdline', $remote);
        self::assertStringNotContainsString('environ', $remote);
        self::assertStringContainsString('timeout 5 runuser -u testl3865 -- "$php_bin" -v </dev/null', $remote);
        self::assertStringContainsString('bash "${root}/fixture-php" -v </dev/null', $remote);
        self::assertStringContainsString('timeout 5 runuser -u testl3865 -- "$php_bin" -r \'echo "php_diag_started=yes\n";\' </dev/null', $remote);
        self::assertStringContainsString('bash "${root}/fixture-php" -r \'echo "php_diag_started=yes\n";\' </dev/null', $remote);
        self::assertSame(4, substr_count($remote, '</dev/null'));
        self::assertStringContainsString('printf \'%s\n\' \'<?php echo "php_diag_started=yes\n";\' | timeout 5 runuser -u testl3865 -- "$php_bin"', $remote);
        self::assertDoesNotMatchRegularExpression('/>(?!\/dev\/null\b|&1\b)/', $remote);
    }

    public function testPhpIdentityExitDiagnosticRunsRealStdinScript(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->phpDiagRoot('real', $bash, 'real');
        $output = $this->runPhpDiag($bash, $root, "echo bash_continues=yes\n");
        self::assertStringContainsString('site_account_uid_match=yes', $output);
        self::assertStringContainsString('site_account_name_match=yes', $output);
        self::assertStringContainsString('site_owner_match=yes', $output);
        self::assertStringContainsString('php_diag_core=disabled', $output);
        self::assertStringContainsString("php_diag_test=version\nphp_diag_exit=0\nphp_diag_marker=no\nphp_diag_timeout=no", $output);
        self::assertStringContainsString("php_diag_test=inline\nphp_diag_exit=0\nphp_diag_marker=yes\nphp_diag_timeout=no", $output);
        self::assertStringContainsString("php_diag_test=stdin\nphp_diag_exit=0\nphp_diag_marker=yes\nphp_diag_timeout=no", $output);
        self::assertStringContainsString('php_diag_caller_continues=yes', $output);
        self::assertStringContainsString('bash_continues=yes', $output);
        self::assertStringNotContainsString('PHP ', $output);
        self::assertFileDoesNotExist($root.'/core');
    }

    public function testPhpIdentityExitDiagnosticClosesInheritedStdin(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->phpDiagRoot('stdin-closed', $bash, 'reader');
        $output = $this->runPhpDiag($bash, $root, "echo bash_continues=yes\n");
        self::assertStringContainsString("php_diag_test=version\nphp_diag_exit=0\nphp_diag_marker=no\nphp_diag_timeout=no", $output);
        self::assertStringContainsString("php_diag_test=inline\nphp_diag_exit=0\nphp_diag_marker=no\nphp_diag_timeout=no", $output);
        self::assertStringContainsString("php_diag_test=stdin\nphp_diag_exit=0\nphp_diag_marker=no\nphp_diag_timeout=no", $output);
        self::assertSame("EOF\n", (string) file_get_contents($root.'/launcher-version'));
        self::assertSame("EOF\n", (string) file_get_contents($root.'/launcher-inline'));
        self::assertSame('<?php echo "php_diag_started=yes\n";', (string) file_get_contents($root.'/launcher-stdin'));
        self::assertStringNotContainsString('bash_continues', (string) file_get_contents($root.'/launcher-stdin'));
        self::assertStringContainsString('php_diag_caller_continues=yes', $output);
        self::assertStringContainsString('bash_continues=yes', $output);
        self::assertStringNotContainsString('<?php', $output);
        self::assertStringNotContainsString($root, $output);
    }

    public function testPhpIdentityExitDiagnosticSkipsImpersonationWhenSiteDiffers(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->phpDiagRoot('site-differs', $bash, 'crash');
        file_put_contents($root.'/fixture-id', <<<'BASH'
#!/bin/bash
if [ "$1" = "-u" ] && [ "$2" = "testl3865" ]; then
  printf '%s\n' 4242
  exit 0
fi
exit 1
BASH);
        $output = $this->runPhpDiag($bash, $root);
        self::assertStringContainsString('site_account_uid_match=no', $output);
        self::assertSame(3, substr_count($output, 'php_diag_exit=not_attempted'));
        self::assertFileDoesNotExist($root.'/runuser-attempts');
    }

    public function testPhpIdentityExitDiagnosticPreservesLauncherStatusWithoutLeakingOutput(): void
    {
        $bash = $this->probeBash();
        self::assertNotNull($bash);

        $crash = $this->phpDiagRoot('crash', $bash, 'crash');
        $crashOutput = $this->runPhpDiag($bash, $crash);
        self::assertSame(3, substr_count($crashOutput, 'php_diag_exit=139'));
        self::assertStringContainsString('php_diag_marker=no', $crashOutput);
        self::assertStringContainsString('php_diag_timeout=no', $crashOutput);
        self::assertStringNotContainsString('LEAKED_STDOUT', $crashOutput);
        self::assertStringNotContainsString('LEAKED_STDERR', $crashOutput);
        self::assertStringContainsString('php_diag_caller_continues=yes', $crashOutput);

        $missing = $this->phpDiagRoot('missing', $bash, 'nostart');
        $missingOutput = $this->runPhpDiag($bash, $missing);
        self::assertSame(3, substr_count($missingOutput, 'php_diag_exit=127'));
        self::assertStringContainsString('php_diag_timeout=no', $missingOutput);
        self::assertStringContainsString('php_diag_marker=no', $missingOutput);

        $hang = $this->phpDiagRoot('hang', $bash, 'hang');
        $started = microtime(true);
        $hangOutput = $this->runPhpDiag($bash, $hang, "echo bash_continues=yes\n");
        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(12.0, $elapsed);
        self::assertLessThan(22.0, $elapsed);
        self::assertSame(3, substr_count($hangOutput, 'php_diag_exit=124'));
        self::assertSame(3, substr_count($hangOutput, 'php_diag_timeout=yes'));
        self::assertStringNotContainsString('LEAKED_PARTIAL', $hangOutput);
        self::assertStringContainsString('bash_continues=yes', $hangOutput);
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
        self::assertCount(16, $paths);

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
            $this->root().'/deploy/vds/gha-php-identity-exit-remote.sh',
            $this->root().'/deploy/vds/gha-php-security-inventory-remote.sh',
            $this->root().'/deploy/vds/gha-web-runtime-identity-window-remote.sh',
            $this->root().'/deploy/vds/gha-clamav-socket-group-remote.sh',
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

    private function assertScanDoesNotImpersonate(string $output): void
    {
        self::assertStringNotContainsString('app_user_basis=site_account', $output);
        self::assertStringNotContainsString('app_user=testl3865', $output);
        self::assertStringContainsString('app_ping=not_attempted', $output);
    }

    private function probeRoot(string $label): string
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/clamav-access-probe-'.$label.'-'.bin2hex(random_bytes(4));
        mkdir($root.'/proc', 0777, true);

        return $root;
    }

    private function lsphpStatus(string $uid): string
    {
        return "Name:\tlsphp\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\nGid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\nGroups:\t{$uid}\n";
    }

    private function writeLsphp(string $root, string $pid, string $comm, string $status): void
    {
        mkdir($root.'/proc/'.$pid, 0777, true);
        file_put_contents($root.'/proc/'.$pid.'/comm', $comm);
        file_put_contents($root.'/proc/'.$pid.'/status', $status);
    }

    private function runSocketStatusProbe(string $bash, string $label, string $socketCommands): string
    {
        $root = $this->recordedProbeRoot($label, '5002', '5002', 'testl3865', 'match', 'match');
        file_put_contents($root.'/fixture-php-bin', <<<BASH
#!/bin/bash
data=""
prev=""
for arg in "\$@"; do
  if [ "\$prev" = "--" ]; then
    data="\$arg"
  fi
  prev="\$arg"
done
if [ "\$data" = "--identity" ]; then
  printf '%s\n' identity_helper_started=yes process_euid=5002 process_egid=5002 process_group=5002
  exit 0
fi
{$socketCommands}
BASH);
        $payload = base64_encode($this->probeEntrySource(<<<'PHP'
function clamav_access_probe_cli_main(): void
{
    echo "unused\n";
}
PHP));

        return $this->runProbe(
            $bash,
            $this->bashPath($bash, $root),
            "ACCESS_PROBE_PHP_B64='".$payload."'\n",
        );
    }

    private function runIdentityProbe(string $bash, string $root, string $payload): string
    {
        return $this->runProbe(
            $bash,
            $this->bashPath($bash, $root),
            "ACCESS_PROBE_PHP_B64='".$payload."'\n",
            "echo bash_continues=yes\n",
        );
    }

    private function assertIdentityGateStaysClosed(string $output, string $root): void
    {
        self::assertStringContainsString('process_identity=unavailable', $output);
        self::assertStringNotContainsString('process_euid=', $output);
        self::assertStringNotContainsString('process_identity_match=yes', $output);
        self::assertStringContainsString('app_dac=not_attempted', $output);
        self::assertStringContainsString('app_ping=not_attempted', $output);
        self::assertStringContainsString('identity_caller_continues=yes', $output);
        self::assertStringContainsString('bash_continues=yes', $output);
        self::assertFileDoesNotExist($root.'/dac-attempts');
        self::assertFileDoesNotExist($root.'/connect-attempts');
    }

    private function phpDiagRoot(string $label, string $bash, string $mode): string
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/php-identity-exit-'.$label.'-'.bin2hex(random_bytes(4));
        mkdir($root.'/home/testlig.net', 0777, true);
        file_put_contents($root.'/fixture-id', <<<'BASH'
#!/bin/bash
if [ "$1" = "-u" ] && [ "$2" = "testl3865" ]; then
  printf '%s\n' 5002
  exit 0
fi
if [ "$1" = "-nu" ] && [ "$2" = "5002" ]; then
  printf '%s\n' testl3865
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-stat', <<<'BASH'
#!/bin/bash
if [ "$1" = "-c" ] && [ "$2" = "%u" ]; then
  printf '%s\n' 5002
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-runuser', <<<'BASH'
#!/bin/bash
if [ "$1" != "-u" ] || [ "$2" != "testl3865" ] || [ "$3" != "--" ]; then
  exit 1
fi
shift 3
dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
printf '%s\n' "$*" >> "$dir/runuser-attempts"
"$@"
BASH);
        $php = $this->bashPath($bash, str_replace('\\', '/', \PHP_BINARY));
        $quoted = "'".str_replace("'", "'\\''", $php)."'";
        $launcher = match ($mode) {
            'crash' => <<<'BASH'
#!/bin/bash
printf '%s\n' 'LEAKED_STDOUT_VERSION'
printf '%s\n' 'LEAKED_STDERR' >&2
exit 139
BASH,
            'nostart' => <<<'BASH'
#!/bin/bash
exit 127
BASH,
            'hang' => <<<'BASH'
#!/bin/bash
printf '%s\n' 'php_diag_started=yes'
printf '%s\n' 'LEAKED_PARTIAL'
sleep 30
BASH,
            'reader' => <<<'BASH'
#!/bin/bash
dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
kind=stdin
for arg in "$@"; do
  case "$arg" in
    -v) kind=version ;;
    -r) kind=inline ;;
  esac
done
payload=$(cat)
if [ "$kind" = "stdin" ]; then
  printf '%s' "$payload" > "$dir/launcher-stdin"
  exit 0
fi
if [ -z "$payload" ]; then
  printf '%s\n' EOF > "$dir/launcher-$kind"
else
  printf '%s\n' CONSUMED > "$dir/launcher-$kind"
fi
exit 0
BASH,
            default => "#!/bin/bash\nexec {$quoted} \"\$@\"\n",
        };
        file_put_contents($root.'/fixture-php', $launcher);

        return $root;
    }

    private function runPhpDiag(string $bash, string $root, string $suffix = ''): string
    {
        $script = str_replace(
            'root="${TESTLIG_PHP_DIAG_ROOT:-}"',
            "root='".str_replace("'", '', $root)."'",
            $this->read('deploy/vds/gha-php-identity-exit-remote.sh'),
        );
        $pipes = [];
        $process = proc_open([$bash], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $script.$suffix);
        fclose($pipes[0]);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ([1, 2] as $index) {
            if (\is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        self::assertSame(0, proc_close($process), $stdout."\n".$stderr);

        return $stdout;
    }

    private function probeBash(): ?string
    {
        // Git Bash passes the full helper to Windows PHP. The default bash here is WSL, and that pipe stops at 8192 bytes.
        $git = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (is_file($git)) {
            return $git;
        }

        return $this->bashBinary();
    }

    private function identityPipelineRoot(string $label, ?string $bash = null): string
    {
        $bash = $bash ?? $this->probeBash();
        self::assertNotNull($bash);
        $root = $this->recordedProbeRoot($label, '5002', '5002', 'testl3865', 'match', 'match');
        unlink($root.'/fixture-php');
        $php = $this->bashPath($bash, str_replace('\\', '/', \PHP_BINARY));
        $quoted = "'".str_replace("'", "'\\''", $php)."'";
        file_put_contents($root.'/fixture-php-bin', "#!/bin/bash\nexec {$quoted} \"\$@\"\n");

        return $root;
    }

    private function probeEntrySource(string $body): string
    {
        return "<?php\n".$body."\n// CLAMAV_ACCESS_PROBE_ENTRY\n";
    }

    private function probeEvalCode(string $helper): string
    {
        $marker = "// CLAMAV_ACCESS_PROBE_ENTRY\n";
        self::assertStringStartsWith("<?php\n", $helper);
        self::assertSame(1, substr_count($helper, $marker));
        self::assertStringEndsWith($marker, $helper);
        $code = substr($helper, \strlen("<?php\n"));
        self::assertStringNotContainsString('/probe-data-socket-not-in-source', $code);

        return substr($code, 0, -\strlen($marker))."clamav_access_probe_cli_main();\n";
    }

    private function directHelperIdentity(string $helper): string
    {
        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, '-r', $this->probeEvalCode($helper), '--', '--identity'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        foreach ([1, 2] as $index) {
            if (\is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        self::assertSame(0, proc_close($process));
        $stdout = trim($stdout);
        self::assertNotSame('', $stdout);

        return $stdout;
    }

    private function recordedProbeRoot(string $label, string $accountUid, string $ownerUid, string $recordedName, string $identityMode, string $connectMode): string
    {
        $root = $this->probeRoot($label);
        mkdir($root.'/etc/clamd.d', 0777, true);
        mkdir($root.'/var/lib/clamav', 0777, true);
        mkdir($root.'/home/testlig.net', 0777, true);
        file_put_contents($root.'/etc/clamd.d/scan.conf', "LocalSocket /run/clamd.scan/clamd.sock\nDatabaseDirectory /var/lib/clamav\n");
        file_put_contents($root.'/var/lib/clamav/daily.cvd', "ClamAV-VDB:UNIQUEHEADERTEXT:59:10:63:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:bbbb:clamav:1\n");
        file_put_contents($root.'/fixture-id', <<<BASH
#!/bin/bash
if [ "\$1" = "-u" ] && [ "\$2" = "testl3865" ]; then
  printf '%s\n' {$accountUid}
  exit 0
fi
if [ "\$1" = "-nu" ] && [ "\$2" = "5002" ]; then
  printf '%s\n' {$recordedName}
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-stat', <<<BASH
#!/bin/bash
if [ "\$1" = "-c" ] && [ "\$2" = "%u" ]; then
  printf '%s\n' {$ownerUid}
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-runuser', <<<'BASH'
#!/bin/bash
if [ "$1" != "-u" ] || [ "$2" != "testl3865" ] || [ "$3" != "--" ]; then
  exit 1
fi
shift 3
if [ "$1" = "test" ]; then
  flag="$2"
  path="$3"
  dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
  printf '%s %s\n' "$flag" "$path" >> "$dir/dac-attempts"
  case "$flag" in
    -w)
      case "$path" in
        *daily.cvd) exit 1 ;;
      esac
      exit 0
      ;;
    -r|-x) exit 0 ;;
  esac
  exit 1
fi
"$@"
BASH);
        file_put_contents($root.'/fixture-php', <<<BASH
#!/bin/bash
mode="{$identityMode}"
connect="{$connectMode}"
dir=\$(CDPATH= cd -- "\$(dirname "\$0")" && pwd)
if [ "\$1" = "--" ]; then
  shift
fi
if [ "\$1" = "--identity" ]; then
  if [ "\$mode" = "unavailable" ]; then
    printf '%s\n' process_identity=unavailable
    exit 0
  fi
  if [ "\$mode" = "egid" ]; then
    printf '%s\n' process_euid=5002 process_egid=5003 process_group=5002
    exit 0
  fi
  if [ "\$mode" = "extra" ]; then
    printf '%s\n' process_euid=5002 process_egid=5002 process_group=7 process_group=5002
    exit 0
  fi
  if [ "\$mode" = "missing" ]; then
    printf '%s\n' process_euid=5002 process_egid=5002
    exit 0
  fi
  printf '%s\n' process_euid=5002 process_egid=5002 process_group=5002
  exit 0
fi
if [ "\$connect" != "match" ]; then
  printf '%s\n' process_identity_match=no
  exit 0
fi
printf '%s\n' 1 >> "\$dir/connect-attempts"
printf '%s\n' app_ping=pong app_version_daily=59
BASH);

        return $root;
    }

    private function writeSiteFixtures(string $root): void
    {
        file_put_contents($root.'/fixture-id', <<<'BASH'
#!/bin/bash
if [ "$1" = "-u" ] && [ "$2" = "testl3865" ]; then
  printf '%s\n' 4242
  exit 0
fi
if [ "$1" = "-nu" ] && [ "$2" = "4242" ]; then
  printf '%s\n' testl3865
  exit 0
fi
if [ "$1" = "-nu" ] && [ "$2" = "0" ]; then
  printf '%s\n' root
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-stat', <<<'BASH'
#!/bin/bash
if [ "$1" = "-c" ] && [ "$2" = "%u" ]; then
  printf '%s\n' 4242
  exit 0
fi
exit 1
BASH);
    }

    private function denyRead(string $bash, string $path): void
    {
        $quoted = str_replace("'", "'\\''", $this->bashPath($bash, $path));
        $pipes = [];
        $process = proc_open([$bash, '-c', "chmod a-r -- '$quoted' && if [ -r '$quoted' ]; then echo readable; else echo unreadable; fi"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::assertSame(0, proc_close($process), $stdout."\n".$stderr);
        if (!str_contains($stdout, 'unreadable')) {
            unlink($path);
            mkdir($path, 0777, true);
        }
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

    private function runProbe(string $bash, string $root, string $prefix = '', string $suffix = ''): string
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
        fwrite($pipes[0], $prefix.$script.$suffix);
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

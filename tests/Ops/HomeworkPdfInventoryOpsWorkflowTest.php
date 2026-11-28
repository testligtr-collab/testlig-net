<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards for the manual, read-only homework PDF inventory operation (workflow + remote script).
 * Nothing here connects to a server: the SSH step is only inspected as text and the remote script
 * runs against a temporary fixture root with stub commands.
 */
final class HomeworkPdfInventoryOpsWorkflowTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ops-homework-pdf-inventory.yml';
    private const REMOTE = 'deploy/vds/gha-homework-pdf-inventory-remote.sh';
    private const SHA = '5eafc0496f928c1c9faf1c46796ea55ab51fdd95';
    private const RELEASE = '20261128T152947Z-5eafc0496f92';

    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> */
    private array $logs = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        $this->roots = [];
        $this->logs = [];
    }

    public function testWorkflowIsManualSingleSessionAndReadOnly(): void
    {
        $text = $this->read(self::WORKFLOW);
        $parsed = Yaml::parseFile($this->root().'/'.self::WORKFLOW);
        self::assertIsArray($parsed);

        $on = $parsed['on'] ?? $parsed[true] ?? null;
        self::assertIsArray($on);
        self::assertSame(['workflow_dispatch'], array_keys($on));
        $inputs = $on['workflow_dispatch']['inputs'] ?? null;
        self::assertIsArray($inputs);
        self::assertSame(['expected_sha'], array_keys($inputs), 'No free-form command, path, host or user input.');
        self::assertTrue($inputs['expected_sha']['required'] ?? false);
        self::assertSame(['contents' => 'read'], $parsed['permissions'] ?? null);
        self::assertDoesNotMatchRegularExpression('/\n\s*(push|pull_request|schedule|workflow_run):/', $text);

        $jobs = $parsed['jobs'] ?? null;
        self::assertIsArray($jobs);
        self::assertSame(['pause-notice', 'vds'], array_keys($jobs));

        $pause = $jobs['pause-notice'];
        self::assertIsArray($pause);
        self::assertSame("vars.VDS_SSH_PAUSED == 'true'", $pause['if']);
        $encodedPause = json_encode($pause);
        self::assertIsString($encodedPause);
        self::assertStringNotContainsString('secrets.', $encodedPause);
        self::assertStringNotContainsString('webfactory/ssh-agent', $encodedPause);
        self::assertStringContainsString('Homework PDF inventory skipped because SSH paused', $encodedPause);

        $vds = $jobs['vds'];
        self::assertIsArray($vds);
        self::assertSame("vars.VDS_SSH_PAUSED != 'true'", $vds['if']);
        self::assertSame(['group' => 'testlig-vds-ssh', 'cancel-in-progress' => false, 'queue' => 'max'], $vds['concurrency']);
        self::assertIsInt($vds['timeout-minutes']);
        self::assertLessThanOrEqual(20, $vds['timeout-minutes']);

        $steps = $vds['steps'];
        self::assertIsArray($steps);
        $names = array_map(static fn (array $step): string => (string) ($step['name'] ?? ''), $steps);
        $validate = array_search('Validate expected release SHA (before any SSH)', $names, true);
        $setup = array_search('Setup SSH', $names, true);
        $configure = array_search('Configure SSH client', $names, true);
        $run = array_search('Run remote read-only inventory and readiness preview', $names, true);
        self::assertIsInt($validate);
        self::assertIsInt($setup);
        self::assertIsInt($configure);
        self::assertIsInt($run);
        self::assertLessThan($setup, $validate);
        self::assertLessThan($run, $configure);
        self::assertLessThan($run, $setup);

        foreach ($steps as $step) {
            self::assertIsArray($step);
            if (isset($step['run'])) {
                self::assertIsString($step['run']);
                self::assertStringNotContainsString('${{', $step['run'], 'GitHub expressions must go through env, never into shell code.');
            }
        }

        $sshStep = $steps[$run];
        self::assertIsArray($sshStep);
        self::assertSame(['EXPECTED_SHA' => '${{ github.event.inputs.expected_sha }}'], $sshStep['env']);
        $command = (string) $sshStep['run'];
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $command));
        self::assertSame(0, preg_match_all('/^\s*(scp|sftp|rsync)\s+/m', $text));
        self::assertStringContainsString('ssh testlig-vds "export EXPECTED_SHA=$(printf %q "$EXPECTED_SHA"); bash -s" < deploy/vds/gha-homework-pdf-inventory-remote.sh', $command);
        self::assertStringContainsString('gha-ssh-close-master.sh', $command);
        self::assertStringContainsString('gha-homework-pdf-inventory-report.sh', $command);
        self::assertDoesNotMatchRegularExpression('/\b(until|while)\b|\bfor\b[^\n]*\bssh\b|\|\|\s*true|retry/i', $text);
        self::assertStringNotContainsString('TESTLIG_HW_INVENTORY_ROOT', $text);
        self::assertStringNotContainsString('--by-institution', $text);
        self::assertStringNotContainsString('--preview-limit-bytes', $text);
        self::assertStringNotContainsString('deploy.yml', $text);
        self::assertStringNotContainsString('release-deploy', $text);
        self::assertStringNotContainsString('rollback', $text);
        self::assertStringNotContainsString('gha-deploy-once', $text);
        self::assertSame(1, substr_count($text, 'webfactory/ssh-agent'));
        self::assertSame(1, substr_count($text, 'gha-ssh-config.sh'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badShaProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['5eafc049'];
        yield 'short sha form' => [substr(self::SHA, 0, 12)];
        yield 'uppercase' => [strtoupper(self::SHA)];
        yield '41 characters' => [self::SHA.'a'];
        yield '39 characters' => [substr(self::SHA, 0, 39)];
        yield 'not hex' => [str_repeat('g', 40)];
        yield 'trailing newline' => [self::SHA."\n"];
        yield 'embedded newline' => [substr(self::SHA, 0, 20)."\n".substr(self::SHA, 20)];
        yield 'second line' => [self::SHA."\nX=1"];
        yield 'control character' => [substr(self::SHA, 0, 39)."\x01"];
        yield 'space' => [self::SHA.' '];
        yield 'branch name' => ['main'];
        yield 'shell text' => ['$(id)'];
    }

    #[DataProvider('badShaProvider')]
    public function testBadShaStopsBeforeSsh(string $sha): void
    {
        [$exit, $output] = $this->runValidation($sha);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Stopping before SSH', $output);
        self::assertStringNotContainsString('expected_sha_format=ok', $output);
    }

    public function testFullShaPassesValidation(): void
    {
        [$exit, $output] = $this->runValidation(self::SHA);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('expected_sha_format=ok', $output);
    }

    public function testRemoteScriptHasNoWriteInstallOrWideningPaths(): void
    {
        $script = $this->read(self::REMOTE);

        self::assertSame(1, substr_count($script, 'bin/console'), 'One real console call.');
        self::assertSame(1, preg_match_all('/runuser -u "\$app_user" -- env HOME="\$site_root" APP_ENV=prod APP_DEBUG=0 "\$php_bin" bin\/console app:homework-pdf:inventory --no-interaction <\/dev\/null/', $script));
        self::assertStringContainsString('app_user="testl3865"', $script);
        foreach (['--by-institution', '--preview-limit-bytes', '--orphan-min-age', '--apply', '--delete', '--force', 'purge-quarantine', 'doctrine', 'cache:', 'dbal:', 'migrations', 'assets:', 'importmap'] as $needle) {
            self::assertStringNotContainsString($needle, $script, $needle);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*(apt-get|dnf|yum|pacman|usermod|groupadd|useradd|gpasswd|chmod|chown|chgrp|setfacl|setenforce|mkdir|rm|mv|cp|tee|install|ln|touch|truncate|dd|kill|pkill|sudo|curl|wget|scp|sftp|ssh|rsync|service|clamscan|clamdscan|freshclam)\b/m', $script);
        self::assertDoesNotMatchRegularExpression('/\bsystemctl\s+(start|stop|restart|reload|enable|disable|mask|daemon-reload)\b/', $script);
        self::assertDoesNotMatchRegularExpression('/\bsystemctl\b/', str_replace(['systemctl is-active', 'systemctl show -p ActiveEnterTimestamp --value', 'fixture-systemctl'], '', $script));
        self::assertDoesNotMatchRegularExpression('/>>/', $script);
        self::assertDoesNotMatchRegularExpression('/>\s*(?!\/dev\/null\b|&\d)["$\w.\/-]/', $script, 'Redirects only to /dev/null.');
        self::assertDoesNotMatchRegularExpression('/\b(printenv|phpinfo|\/environ|cmdline|nPING|nVERSION|INSTREAM|lswsctrl)\b/', $script);
        self::assertDoesNotMatchRegularExpression('/(echo|printf)[^\n]*READ_ENV_VALUE/', $script, 'An env value is classified, never printed.');
        self::assertDoesNotMatchRegularExpression('/\bcat\b[^\n]*\.env/', $script);
        self::assertStringContainsString('exec 2>/dev/null', $script);
        self::assertStringContainsString('env_values_logged=no', $script);
        self::assertStringContainsString('inventory_options=none', $script);
        self::assertStringContainsString('hw_pdf_ops_changes=no', $script);
        self::assertStringContainsString('scan_gate_changed=no', $script);
        self::assertStringContainsString('upload_gate_changed=no', $script);
        self::assertStringNotContainsString('runtime_verified=yes', $script);
        self::assertStringNotContainsString('web_acceptance=yes', $script);
        self::assertStringNotContainsString('proven=yes', $script);

        // The script is read from stdin by `bash -s`: every command that could read it must have it closed.
        foreach (preg_split('/\R/', $script) ?: [] as $line) {
            if (str_contains($line, 'runuser -u') || str_contains($line, 'fixture-console')) {
                self::assertStringContainsString('</dev/null', $line, $line);
            }
        }
    }

    public function testVerifiedReleaseRunsInventoryExactlyOnceAndNothingChanges(): void
    {
        $fixture = $this->fixture('happy');
        $before = $this->snapshot($fixture['root']);

        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame(0, $exit, $output);
        self::assertSame($before, $this->snapshot($fixture['root']), 'The fixture tree must not change.');
        self::assertStringContainsString('app_link=release_directory', $output);
        self::assertStringContainsString('release_name='.self::RELEASE, $output);
        self::assertStringContainsString('release_revision='.self::SHA, $output);
        self::assertStringContainsString('release_revision_match=yes', $output);
        self::assertStringContainsString('current_revision_match=yes', $output);
        self::assertStringContainsString('storage_dir_config=expected_shared', $output);
        self::assertStringContainsString('durable_dir_state=directory', $output);
        self::assertStringContainsString('inventory_result=ok', $output);
        self::assertStringContainsString('inventory_exit_code=0', $output);
        self::assertStringContainsString('inventory_status=ok', $output);
        self::assertStringContainsString('inventory_options=none', $output);
        self::assertStringContainsString('orphan_min_age_seconds=86400', $output);
        self::assertStringContainsString('db_declared_bytes=1000', $output);
        self::assertStringContainsString('db_physical_bytes=900', $output);
        self::assertStringContainsString('db_declared_minus_physical_bytes=100', $output);
        self::assertStringContainsString('orphan_candidate_files=1', $output);
        self::assertStringContainsString('ambiguous_owner_references=0', $output);
        self::assertStringContainsString('inventory_output_dropped_lines=6', $output);
        self::assertStringContainsString('hw_pdf_ops_complete=yes', $output);

        $calls = $this->calls($fixture);
        self::assertCount(1, $calls, 'The command runs exactly once.');
        self::assertSame($fixture['release'].' app:homework-pdf:inventory', $calls[0]);

        foreach (['institution=', 'file_name', 'secret-homework', 'storage_key', '0123456789abcdef0123456789abcdef', 'aaaaaaaa-bbbb', 'student@example.test', 'super-secret-value', 'SECRET_TOKEN', 'DB_PASSWORD', 'APP_SECRET', '/shared/var/homework-pdf', $fixture['root']] as $needle) {
            self::assertStringNotContainsString($needle, $output, $needle);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function blockedProvider(): iterable
    {
        yield 'revision differs' => ['revision_differs', 'not_run_revision_mismatch', 'release_revision_match=no'];
        yield 'expected sha is short' => ['short_expected', 'not_run_expected_sha_invalid', 'expected_sha=invalid'];
        yield 'expected sha is empty' => ['empty_expected', 'not_run_expected_sha_invalid', 'expected_sha=invalid'];
        yield 'app link absent' => ['no_app_link', 'not_run_app_link_absent', 'app_link=absent'];
        yield 'app link leaves releases' => ['link_outside', 'not_run_app_link_outside_releases', 'app_link=outside_releases'];
        yield 'revision file absent' => ['revision_absent', 'not_run_revision_absent', 'release_revision=absent'];
        yield 'revision file invalid' => ['revision_invalid', 'not_run_revision_invalid', 'release_revision=invalid'];
        yield 'storage root differs' => ['storage_differs', 'not_run_storage_root_unverified', 'storage_dir_config=differs'];
        yield 'storage root unset' => ['storage_absent', 'not_run_storage_root_unverified', 'storage_dir_config=absent'];
        yield 'storage root duplicated' => ['storage_contradictory', 'not_run_storage_root_unverified', 'storage_dir_config=contradictory'];
        yield 'durable dir is a symlink' => ['durable_symlink', 'not_run_storage_root_unsafe', 'durable_dir_state=symlink'];
        yield 'prod cache missing' => ['cache_missing', 'not_run_prod_cache_missing', 'prod_cache_present=no'];
        yield 'command missing in release' => ['command_missing', 'not_run_command_missing', 'inventory_command_in_release=no'];
    }

    #[DataProvider('blockedProvider')]
    public function testUnverifiedReleaseOrRootNeverRunsInventory(string $scenario, string $result, string $marker): void
    {
        $fixture = $this->fixture($scenario);
        $before = $this->snapshot($fixture['root']);

        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame(1, $exit, 'A blocked inventory is a failed run, not a pass.');
        self::assertSame($before, $this->snapshot($fixture['root']));
        self::assertStringContainsString('inventory_result='.$result, $output);
        self::assertStringContainsString('inventory_exit_code=not_run', $output);
        self::assertStringContainsString($marker, $output);
        self::assertSame([], $this->calls($fixture), 'The command must not be called.');
        self::assertStringNotContainsString('disk_files=', $output);
        self::assertStringNotContainsString('db_references=', $output);
        // Independent readiness reads still complete.
        self::assertStringContainsString('readiness_section=preview_only_measurements', $output);
        self::assertStringContainsString('hw_pdf_ops_complete=yes', $output);
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function inventoryStateProvider(): iterable
    {
        yield 'empty store' => ['empty', 0, 'ok_store_empty', 'store_empty=yes'];
        yield 'root missing' => ['root_missing', 0, 'root_missing', 'homework_pdf_inventory_status=root_missing'];
        yield 'unsafe root' => ['unsafe', 1, 'root_unsafe', 'homework_pdf_inventory_status=root_unsafe'];
        yield 'unreadable root' => ['unreadable', 1, 'root_unreadable', 'homework_pdf_inventory_status=root_unreadable'];
        yield 'database or internal error' => ['error', 1, 'inventory_error', 'homework_pdf_inventory_code=inventory_failed'];
        yield 'unknown failure' => ['crash', 1, 'failed', 'inventory_status=unknown'];
    }

    #[DataProvider('inventoryStateProvider')]
    public function testInventoryStatesStayDistinct(string $mode, int $expectedExit, string $result, string $marker): void
    {
        $fixture = $this->fixture('state-'.$mode, $mode);
        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame($expectedExit, $exit, $output);
        self::assertStringContainsString('inventory_result='.$result, $output);
        self::assertStringContainsString($marker, $output);
        self::assertCount(1, $this->calls($fixture));
        if ('empty' !== $mode) {
            self::assertStringNotContainsString('inventory_result=ok_store_empty', $output);
            self::assertStringNotContainsString('inventory_result=ok'."\n", $output);
        }
        foreach (['SECRET_ERROR_TEXT', 'Doctrine', 'SQLSTATE', $fixture['root']] as $needle) {
            self::assertStringNotContainsString($needle, $output, $needle);
        }
    }

    public function testMissingRootIsReportedWithoutCreatingIt(): void
    {
        $fixture = $this->fixture('root-missing', 'root_missing');
        $durable = $fixture['root'].'/home/testlig.net/shared/var/homework-pdf';
        rmdir($durable);
        $before = $this->snapshot($fixture['root']);
        $dirsBefore = $this->directories($fixture['root']);

        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('durable_dir_state=absent', $output);
        self::assertStringContainsString('inventory_result=root_missing', $output);
        self::assertDirectoryDoesNotExist($durable);
        self::assertSame($before, $this->snapshot($fixture['root']));
        self::assertSame($dirsBefore, $this->directories($fixture['root']));
    }

    public function testReadinessLinesNameTheirSourceAndNeverClaimWebOrRuntimeProof(): void
    {
        $fixture = $this->fixture('readiness');
        [$exit, $output] = $this->runRemote($fixture);
        self::assertSame(0, $exit, $output);

        self::assertStringContainsString('web_worker_identity=observed', $output);
        self::assertStringContainsString('web_worker_site_uid_count=1', $output);
        self::assertMatchesRegularExpression('/^web_worker uid=\d+ user=\S+ gid=\d+ supplementary_groups=\d+ serves_site_uid=yes exe=\S+ identity_source=proc web_acceptance=no$/m', $output);
        self::assertStringContainsString('serves_site_uid=no', $output);
        self::assertStringContainsString('clamd_conf=present', $output);
        self::assertStringContainsString('clamd_local_socket=/run/clamd.scan/clamd.sock', $output);
        self::assertStringContainsString('clamd_config key=StreamMaxLength bytes=104857600 vs_app_limit_25mib=ge_25mib source=config_file runtime_verified=no default_if_unset=not_assumed', $output);
        self::assertStringContainsString('clamd_config key=MaxFileSize bytes=10485760 vs_app_limit_25mib=lt_25mib source=config_file runtime_verified=no default_if_unset=not_assumed', $output);
        self::assertStringContainsString('clamd_running_stream_max_length=not_measured', $output);
        self::assertStringContainsString('clamd_running_max_file_size=not_measured', $output);
        self::assertStringContainsString('clamd_service_active=active', $output);
        self::assertStringContainsString('clamd_conf_newer_than_service_start=yes', $output);
        self::assertStringContainsString('web_worker_socket_connect=not_measured', $output);
        self::assertStringContainsString('connect_measured=no selinux_measured=no ancestors_checked=no web_acceptance=no', $output);
        self::assertStringContainsString('signature_daily file=daily.cld age_hours=192 vs_72h=exceeds_72h source=disk_header loaded_signature_verified=no', $output);
        self::assertStringContainsString('web_limit key=upload_max_filesize source=lsphp_ini_files bytes=2097152 vs_app_limit_25mib=lt_25mib runtime_verified=no web_acceptance=no', $output);
        self::assertStringContainsString('web_limit key=upload_max_filesize source=vhost_override bytes=67108864 vs_app_limit_25mib=ge_25mib', $output);
        self::assertStringContainsString('web_limit key=post_max_size source=lsphp_ini_files bytes=31457280 vs_app_limit_25mib=ge_25mib', $output);
        self::assertStringContainsString('ols_limit key=maxReqBodySize source=httpd_config bytes=2146435072', $output);
        self::assertStringContainsString('ols_limit key=maxReqBodySize source=vhost bytes=104857600', $output);
        self::assertStringContainsString('web_limits_note=user_ini_not_read multipart_overhead_not_measured effective_web_limit=not_measured cli_ini_not_used=yes', $output);
        self::assertStringContainsString('homework_pdf_upload_enabled declared=0 source=shared_env_local effective_web=not_measured code_default_is_live_evidence=no', $output);
        self::assertStringContainsString('homework_pdf_scan_ready declared=absent source=shared_env_local effective_web=not_measured code_default_is_live_evidence=no', $output);
        self::assertStringContainsString('homework_pdf_clamd_stream_max_declared=absent', $output);
        self::assertStringContainsString('scan_readiness_summary=preview_only gates_unchanged=yes proven=no', $output);
        self::assertStringContainsString('cli_or_root_is_web_acceptance=no', $output);
        self::assertStringContainsString('inventory_threshold_is_retention_policy=no', $output);
        self::assertStringNotContainsString('runtime_verified=yes', $output);
        self::assertStringNotContainsString('web_acceptance=yes', $output);
        self::assertStringNotContainsString('proven=yes', $output);
        self::assertStringNotContainsString('loaded_signature_verified=yes', $output);
    }

    public function testReadinessGapsStayNotMeasuredNotPass(): void
    {
        $fixture = $this->fixture('gaps');
        $root = $fixture['root'];
        $this->removeTree($root.'/proc');
        mkdir($root.'/proc', 0777, true);
        unlink($root.'/etc/clamd.d/scan.conf');
        unlink($root.'/var/lib/clamav/daily.cld');
        unlink($root.'/fixture-systemctl');
        unlink($root.'/usr/local/lsws/conf/vhosts/testlig.net/vhconf.conf');

        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('inventory_result=ok', $output, 'The inventory result is separate from the readiness result.');
        self::assertStringContainsString('web_worker_identity=none_running', $output);
        self::assertStringContainsString('clamd_conf=absent', $output);
        self::assertStringContainsString('clamd_service_active=unknown', $output);
        self::assertStringContainsString('clamd_conf_newer_than_service_start=unknown', $output);
        self::assertStringContainsString('clamd_socket=unknown', $output);
        self::assertStringContainsString('web_worker_socket_dac_inference=not_attempted reason=socket_not_stat', $output);
        self::assertStringContainsString('signature_daily=not_measured', $output);
        self::assertStringContainsString('testlig_php_handler=unknown', $output);
        self::assertStringContainsString('web_limit=not_measured reason=handler_unknown', $output);
        self::assertStringContainsString('scan_readiness_summary=preview_only gates_unchanged=yes proven=no', $output);
        self::assertStringNotContainsString('vs_72h=within_72h', $output);
    }

    public function testSocketModeDecidesDacInferenceButNeverConnection(): void
    {
        $fixture = $this->fixture('dac');
        $socket = $fixture['root'].'/run/clamd.scan/clamd.sock';

        foreach ([[0660, 'rw'], [0400, 'no_rw']] as [$mode, $expected]) {
            chmod($socket, $mode);
            if ($mode !== (fileperms($socket) & 0777)) {
                self::markTestSkipped('This file system cannot hold the fixture socket mode.');
            }
            [$exit, $output] = $this->runRemote($fixture);
            self::assertSame(0, $exit, $output);
            self::assertStringContainsString('web_worker_socket_dac_inference='.$expected.' reason=none basis=proc_identity_and_socket_mode connect_measured=no', $output);
            self::assertStringContainsString('web_worker_socket_connect=not_measured', $output);
            self::assertStringContainsString('web_acceptance=no', $output);
        }
    }

    public function testSignatureAgeWithinWindowAndEnvFlagsAreClassifiedNotPrinted(): void
    {
        $fixture = $this->fixture('fresh');
        $root = $fixture['root'];
        file_put_contents($root.'/var/lib/clamav/daily.cld', "ClamAV-VDB:27 Nov 2026 06-00 +0000:64:100:90:aaaa:bbbb:clamav:1\n");
        file_put_contents($root.'/home/testlig.net/shared/.env.local', implode("\n", [
            'APP_SECRET=super-secret-value',
            'HOMEWORK_PDF_STORAGE_DIR="/home/testlig.net/shared/var/homework-pdf"',
            'export HOMEWORK_PDF_UPLOAD_ENABLED=1',
            'HOMEWORK_PDF_SCAN_READY=weird-secret-looking-value',
            'HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES=26214400',
            '',
        ]));

        [$exit, $output] = $this->runRemote($fixture);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('signature_daily file=daily.cld age_hours=30 vs_72h=within_72h', $output);
        self::assertStringContainsString('homework_pdf_upload_enabled declared=1 ', $output);
        self::assertStringContainsString('homework_pdf_scan_ready declared=other ', $output);
        self::assertStringContainsString('homework_pdf_clamd_stream_max_declared vs_app_limit_25mib=ge_25mib source=shared_env_local runtime_verified=no', $output);
        self::assertStringNotContainsString('weird-secret-looking-value', $output);
        self::assertStringNotContainsString('super-secret-value', $output);
        self::assertStringNotContainsString('26214400'."\n", $output);
    }

    public function testEveryOutputLineIsAllowlisted(): void
    {
        $fixture = $this->fixture('allowlist');
        [$exit, $output] = $this->runRemote($fixture);
        self::assertSame(0, $exit, $output);

        $prefixes = [
            'hw_pdf_ops', 'upload_gate_changed=', 'scan_gate_changed=', 'inventory_threshold_is_retention_policy=',
            'cli_or_root_is_web_acceptance=', 'measured_utc=', 'expected_sha=', 'app_link=', 'release_name=',
            'release_revision=', 'release_revision_match=', 'current_revision_match=', 'inventory_command_in_release=',
            'prod_cache_present=', 'storage_dir_config=', 'durable_dir_state=', 'inventory_result=', 'inventory_exit_code=',
            'inventory_status=', 'inventory_options=', 'inventory_output_dropped_lines=', 'readiness_section=',
            'testlig_php_handler', 'web_worker', 'clamd_', 'signature_daily', 'web_limit', 'ols_limit', 'homework_pdf_',
            'env_values_logged=', 'scan_readiness_summary=',
            'preview_only=', 'writes=', 'point_in_time=', 'orphan_min_age_seconds=', 'store_empty=',
            'disk_', 'matched_', 'unreferenced_young_', 'timestamp_unreliable_files=', 'orphan_candidate_', 'unsafe_',
            'db_', 'duplicate_reference=', 'ambiguous_owner_references=', 'draft_', 'submitted_', 'evaluated_',
            'unknown_lifecycle_references=',
        ];
        $lines = preg_split('/\R/', trim($output));
        self::assertIsArray($lines);
        foreach ($lines as $line) {
            $matched = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            self::assertTrue($matched, 'Unexpected output line: '.$line);
            self::assertDoesNotMatchRegularExpression('/[@]|:\/\/|\.pdf|\.bin\b|\.env/i', $line, $line);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reporterScenarioProvider(): iterable
    {
        yield 'full report' => ['happy', 'ok'];
        yield 'readiness gaps' => ['gaps', 'ok'];
        yield 'blocked release' => ['revision_differs', 'ok'];
        yield 'storage differs' => ['storage_differs', 'ok'];
        yield 'empty store' => ['state-empty', 'empty'];
        yield 'root missing' => ['state-root_missing', 'root_missing'];
        yield 'unsafe root' => ['state-unsafe', 'unsafe'];
        yield 'inventory error' => ['state-error', 'error'];
    }

    /**
     * The local reporter shows only allowlisted lines; this keeps its allowlist in step with what the remote script
     * really prints, so a real report is never silently cut.
     */
    #[DataProvider('reporterScenarioProvider')]
    public function testReporterKeepsEveryRealRemoteLine(string $scenario, string $mode): void
    {
        $fixture = $this->fixture($scenario, $mode);
        if ('gaps' === $scenario) {
            $root = $fixture['root'];
            $this->removeTree($root.'/proc');
            mkdir($root.'/proc', 0777, true);
            unlink($root.'/etc/clamd.d/scan.conf');
            unlink($root.'/var/lib/clamav/daily.cld');
            unlink($root.'/fixture-systemctl');
        }
        [, $remote] = $this->runRemote($fixture);
        // The fixture console prints some junk on purpose; the remote script already drops it.
        $file = $fixture['root'].'.remote-out';
        $this->logs[] = $file;
        file_put_contents($file, $remote);

        [$exit, $report] = $this->runBash(
            'bash '.escapeshellarg($this->bashPath($this->root().'/deploy/vds/gha-homework-pdf-inventory-report.sh')).' '.escapeshellarg($this->bashPath($file)).' '.escapeshellarg('').' 0',
            [],
            [],
        );

        self::assertStringContainsString("remote_lines_dropped=0\n", $report);
        self::assertStringContainsString("remote_complete=yes\n", $report);
        self::assertSame(0, $exit, $report);
        $shown = (int) preg_replace('/^.*remote_lines_shown=(\d+).*$/s', '$1', $report);
        self::assertSame(\count(preg_split('/\R/', trim($remote)) ?: []), $shown);
    }

    public function testRemoteScriptHasValidBashSyntax(): void
    {
        $bash = $this->bash();
        $pipes = [];
        $process = proc_open([$bash, '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $this->read(self::REMOTE));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout."\n".$stderr);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runValidation(string $sha): array
    {
        $parsed = Yaml::parseFile($this->root().'/'.self::WORKFLOW);
        self::assertIsArray($parsed);
        $steps = $parsed['jobs']['vds']['steps'] ?? null;
        self::assertIsArray($steps);
        $script = null;
        foreach ($steps as $step) {
            if (\is_array($step) && 'Validate expected release SHA (before any SSH)' === ($step['name'] ?? null)) {
                $script = $step['run'] ?? null;
                self::assertSame(['EXPECTED_SHA' => '${{ github.event.inputs.expected_sha }}'], $step['env'] ?? null);
            }
        }
        self::assertIsString($script);

        // GitHub sets an empty input as an empty variable; an empty value cannot travel through proc_open's env.
        $prefix = '' === $sha ? "export EXPECTED_SHA=''\n" : '';

        return $this->runBash($prefix.$script, '' === $sha ? [] : ['EXPECTED_SHA' => $sha], ['-e']);
    }

    /**
     * @param array{root: string, release: string, log: string, env: array<string, string>} $fixture
     *
     * @return array{0: int, 1: string}
     */
    private function runRemote(array $fixture): array
    {
        $script = str_replace(
            'root="${TESTLIG_HW_INVENTORY_ROOT:-}"',
            "root='".str_replace("'", '', $this->bashPath($fixture['root']))."'",
            $this->read(self::REMOTE),
        );
        self::assertStringContainsString("root='", $script);

        return $this->runBash($script, array_merge(['HWINV_CALL_LOG' => $this->bashPath($fixture['log'])], $fixture['env']), []);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $flags
     *
     * @return array{0: int, 1: string}
     */
    private function runBash(string $script, array $env, array $flags): array
    {
        $environment = [];
        foreach (array_merge($_SERVER, $env) as $key => $value) {
            if (\is_string($key) && \is_string($value) && !str_contains($key, '=') && 'argv' !== $key) {
                $environment[$key] = $value;
            }
        }
        foreach (['EXPECTED_SHA', 'HWINV_MODE'] as $key) {
            if (!isset($env[$key])) {
                unset($environment[$key]);
            }
        }
        $pipes = [];
        $process = proc_open(
            array_merge([$this->bash()], $flags, ['-s']),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, str_replace("\r\n", "\n", (string) $stdout.(string) $stderr)];
    }

    /**
     * @return array{root: string, release: string, log: string, env: array<string, string>}
     */
    private function fixture(string $scenario, string $mode = 'ok'): array
    {
        $this->requireSymlinks();
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/hwinv-ops-'.bin2hex(random_bytes(4));
        $this->roots[] = $root;
        $site = $root.'/home/testlig.net';
        $releaseDir = $site.'/releases/'.self::RELEASE;
        $log = $root.'.calls';
        $this->logs[] = $log;
        foreach ([
            $releaseDir.'/src/Command', $releaseDir.'/var/cache/prod', $site.'/shared/var/homework-pdf',
            $root.'/proc', $root.'/etc/clamd.d', $root.'/var/lib/clamav', $root.'/run/clamd.scan',
            $root.'/usr/local/lsws/conf/vhosts/testlig.net', $root.'/usr/local/lsws/lsphp83/etc/php.d',
        ] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($releaseDir.'/REVISION', self::SHA."\n");
        file_put_contents($releaseDir.'/src/Command/HomeworkPdfInventoryCommand.php', "<?php\n");
        file_put_contents($releaseDir.'/var/cache/prod/App_KernelProdContainer.php', "<?php\n");
        symlink('releases/'.self::RELEASE, $site.'/app');
        file_put_contents($site.'/shared/.env.local', implode("\n", [
            'APP_SECRET=super-secret-value',
            'HOMEWORK_PDF_STORAGE_DIR=/home/testlig.net/shared/var/homework-pdf',
            'HOMEWORK_PDF_UPLOAD_ENABLED=0',
            '',
        ]));
        file_put_contents($site.'/shared/CURRENT_REVISION', self::SHA."\n");

        file_put_contents($root.'/fixture-console', <<<'BASH'
#!/bin/bash
printf '%s %s\n' "$PWD" "$*" >> "$HWINV_CALL_LOG"
cat >/dev/null
case "${HWINV_MODE:-ok}" in
  error) printf '%s\n' 'SECRET_ERROR_TEXT Doctrine SQLSTATE[HY000]' homework_pdf_inventory_status=error homework_pdf_inventory_code=inventory_failed; exit 1 ;;
  root_missing) printf '%s\n' homework_pdf_inventory_status=root_missing preview_only=yes writes=none db_references=7; exit 0 ;;
  unsafe) printf '%s\n' homework_pdf_inventory_status=root_unsafe preview_only=yes; exit 1 ;;
  unreadable) printf '%s\n' homework_pdf_inventory_status=root_unreadable preview_only=yes; exit 1 ;;
  empty) printf '%s\n' homework_pdf_inventory_status=ok store_empty=yes disk_files=0 db_references=0; exit 0 ;;
  crash) printf '%s\n' 'PHP Fatal error: SECRET_ERROR_TEXT'; exit 255 ;;
esac
printf '%s\n' homework_pdf_inventory_status=ok preview_only=yes writes=none point_in_time=yes orphan_min_age_seconds=86400 store_empty=no
printf '%s\n' disk_files=6 disk_bytes=1200 matched_files=4 db_references=4 db_declared_bytes=1000 db_physical_bytes=900 db_missing_file=0 db_size_mismatch=0 orphan_candidate_files=1 ambiguous_owner_references=0 db_declared_minus_physical_bytes=100
printf '%s\n' 'institution=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee references=3' 'file_name=secret-homework.pdf' 'storage_key=0123456789abcdef0123456789abcdef.bin' 'path=/home/testlig.net/shared/var/homework-pdf' 'student=student@example.test' 'disk_files=notanumber'
BASH);
        file_put_contents($root.'/etc/clamd.d/scan.conf', "LocalSocket /run/clamd.scan/clamd.sock\nStreamMaxLength 100M\nMaxFileSize 10M\nDatabaseDirectory /var/lib/clamav\nSECRET_TOKEN=abc\n");
        file_put_contents($root.'/run/clamd.scan/clamd.sock', '');
        chmod($root.'/run/clamd.scan/clamd.sock', 0660);
        file_put_contents($root.'/var/lib/clamav/daily.cld', "ClamAV-VDB:20 Nov 2026 11-54 +0000:63:100:90:aaaa:bbbb:clamav:1\n");
        file_put_contents($root.'/fixture-now', (string) gmmktime(12, 0, 0, 11, 28, 2026)."\n");
        file_put_contents($root.'/fixture-systemctl', <<<'BASH'
#!/bin/bash
case "$1" in
  is-active) echo active ;;
  show) echo 'Mon 2026-11-23 10:00:00 UTC' ;;
esac
BASH);
        file_put_contents($root.'/usr/local/lsws/conf/vhosts/testlig.net/vhconf.conf', implode("\n", [
            'extprocessor lsphp {',
            '  path /usr/local/lsws/lsphp83/bin/lsphp',
            '  env DB_PASSWORD super-secret',
            '}',
            'phpIniOverride {',
            '  php_value upload_max_filesize 64M',
            '}',
            'maxReqBodySize 100M',
            '',
        ]));
        file_put_contents($root.'/usr/local/lsws/conf/httpd_config.conf', "maxReqBodySize 2047M\n");
        file_put_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini', "upload_max_filesize = 2M\npost_max_size = 8M\nmemory_limit = 256M\n");
        file_put_contents($root.'/usr/local/lsws/lsphp83/etc/php.d/20-local.ini', "post_max_size = 30M\n");

        $siteUid = $this->bashValue('stat -c %u '.escapeshellarg($this->bashPath($site)));
        $sockGid = $this->bashValue('stat -c %g '.escapeshellarg($this->bashPath($root.'/run/clamd.scan/clamd.sock')));
        mkdir($root.'/proc/101', 0777, true);
        file_put_contents($root.'/proc/101/comm', "lsphp\n");
        file_put_contents($root.'/proc/101/status', "Name:\tlsphp\nUid:\t{$siteUid}\t{$siteUid}\t{$siteUid}\t{$siteUid}\nGid:\t{$sockGid}\t{$sockGid}\t{$sockGid}\t{$sockGid}\nGroups:\t{$sockGid}\n");
        mkdir($root.'/proc/102', 0777, true);
        file_put_contents($root.'/proc/102/comm', "lsphp\n");
        file_put_contents($root.'/proc/102/status', "Name:\tlsphp\nUid:\t4999\t4999\t4999\t4999\nGid:\t4999\t4999\t4999\t4999\nGroups:\t4999\n");
        mkdir($root.'/proc/103', 0777, true);
        file_put_contents($root.'/proc/103/comm', "bash\n");
        file_put_contents($root.'/proc/103/status', "Name:\tbash\nUid:\t{$siteUid}\t{$siteUid}\t{$siteUid}\t{$siteUid}\nGid:\t1\nGroups:\t1\n");

        $env = ['HWINV_MODE' => $mode, 'EXPECTED_SHA' => self::SHA];
        $this->applyScenario($scenario, $root, $releaseDir, $site, $env);

        return ['root' => $root, 'release' => $releaseDir, 'log' => $log, 'env' => $env];
    }

    /**
     * @param array<string, string> $env
     */
    private function applyScenario(string $scenario, string $root, string $releaseDir, string $site, array &$env): void
    {
        switch ($scenario) {
            case 'revision_differs':
                $env['EXPECTED_SHA'] = str_repeat('1', 40);
                break;
            case 'short_expected':
                $env['EXPECTED_SHA'] = substr(self::SHA, 0, 12);
                break;
            case 'empty_expected':
                $env['EXPECTED_SHA'] = '';
                break;
            case 'no_app_link':
                unlink($site.'/app');
                break;
            case 'link_outside':
                unlink($site.'/app');
                mkdir($root.'/elsewhere', 0777, true);
                symlink($root.'/elsewhere', $site.'/app');
                break;
            case 'revision_absent':
                unlink($releaseDir.'/REVISION');
                break;
            case 'revision_invalid':
                file_put_contents($releaseDir.'/REVISION', "not-a-sha\n");
                break;
            case 'storage_differs':
                file_put_contents($site.'/shared/.env.local', "HOMEWORK_PDF_STORAGE_DIR=/home/testlig.net/releases/x/var/homework-pdf\n");
                break;
            case 'storage_absent':
                file_put_contents($site.'/shared/.env.local', "APP_SECRET=super-secret-value\n");
                break;
            case 'storage_contradictory':
                file_put_contents($site.'/shared/.env.local', "HOMEWORK_PDF_STORAGE_DIR=/home/testlig.net/shared/var/homework-pdf\nHOMEWORK_PDF_STORAGE_DIR=/elsewhere\n");
                break;
            case 'durable_symlink':
                rmdir($site.'/shared/var/homework-pdf');
                mkdir($root.'/elsewhere', 0777, true);
                symlink($root.'/elsewhere', $site.'/shared/var/homework-pdf');
                break;
            case 'cache_missing':
                unlink($releaseDir.'/var/cache/prod/App_KernelProdContainer.php');
                break;
            case 'command_missing':
                unlink($releaseDir.'/src/Command/HomeworkPdfInventoryCommand.php');
                break;
        }
    }

    /**
     * @param array{root: string, release: string, log: string, env: array<string, string>} $fixture
     *
     * @return list<string>
     */
    private function calls(array $fixture): array
    {
        if (!is_file($fixture['log'])) {
            return [];
        }
        $lines = file($fixture['log'], \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_map(static fn (string $line): string => rtrim($line, "\r"), $lines);
    }

    private function requireSymlinks(): void
    {
        $probe = str_replace('\\', '/', sys_get_temp_dir()).'/hwinv-link-'.bin2hex(random_bytes(4));
        if (!@symlink(__FILE__, $probe)) {
            self::markTestSkipped('Symlinks are not available here; the release link checks run in CI.');
        }
        unlink($probe);
    }

    private function bashValue(string $command): string
    {
        $pipes = [];
        $process = proc_open([$this->bash(), '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $value = trim((string) $stdout);
        self::assertMatchesRegularExpression('/^\d+$/', $value);

        return $value;
    }

    private function bash(): string
    {
        $git = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (\DIRECTORY_SEPARATOR === '\\' && is_file($git)) {
            return $git;
        }

        return 'bash';
    }

    private function bashPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (\DIRECTORY_SEPARATOR === '\\' && 1 === preg_match('/^([A-Za-z]):\/(.*)$/', $path, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isLink()) {
                $files[$file->getPathname()] = 'link:'.$file->getLinkTarget();
            } elseif ($file->isFile()) {
                $hash = hash_file('sha256', $file->getPathname());
                self::assertIsString($hash);
                $files[$file->getPathname()] = $hash.':'.decoct($file->getPerms() & 0777);
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function directories(string $root): array
    {
        $dirs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir() && !$file->isLink()) {
                $dirs[] = $file->getPathname();
            }
        }
        sort($dirs);

        return $dirs;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $this->removeTree($path.'/'.$item);
        }
        rmdir($path);
    }

    private function read(string $relative): string
    {
        $text = file_get_contents($this->root().'/'.$relative);
        self::assertIsString($text, $relative);

        return $text;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

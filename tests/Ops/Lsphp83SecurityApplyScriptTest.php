<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class Lsphp83SecurityApplyScriptTest extends TestCase
{
    public function testPatchedPackageUpdatesOnlyLsphp83AndSetsIni(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\n;session.upload_progress.enabled = 1\nsession.upload_progress.enabled_extra = 1\nsession.upload_progress.enabled = 1\n";
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\nbaseurl=https://user:token@rpms.example/lsphp\n");
            $this->writeTree($root, $original);
            $this->writeProcesses($root);
            $output = $this->runApply($root);

            self::assertStringContainsString('lsphp83_previous=8.3.33-1.el9', $output);
            self::assertStringContainsString('lsphp83_candidate=8.3.36-1.el9', $output);
            self::assertStringContainsString('lsphp83_installed=8.3.36-1.el9', $output);
            self::assertStringContainsString('official_fix_version=candidate', $output);
            self::assertStringContainsString('cve_2026_109618=present', $output);
            self::assertStringContainsString('cve_2026_109619=present', $output);
            self::assertStringContainsString('package_update=updated', $output);
            self::assertStringContainsString('package_verified=yes', $output);
            self::assertStringContainsString('fix_evidence=candidate_changelog', $output);
            self::assertStringContainsString('package_downgrade=no', $output);
            self::assertStringContainsString('apply_result=patched', $output);
            self::assertStringNotContainsString('apply_result=temporary_mitigation', $output);
            self::assertStringContainsString('ini_change=set_0', $output);
            self::assertStringContainsString('ini_after=0', $output);
            self::assertStringContainsString('ini_backup=saved', $output);
            self::assertStringContainsString('ini_backup_web_exposed=no', $output);
            self::assertStringContainsString('worker_deleted_exe=4242', $output);
            self::assertStringContainsString('worker_recycle=ols_lsapi_term', $output);
            self::assertStringContainsString('worker_recycle_verified=exited', $output);
            self::assertStringContainsString('wide_restart=no', $output);
            self::assertLessThan(strpos($output, 'worker_recycle='), strpos($output, 'php_cli '));
            self::assertStringContainsString('shared_handler_vhost=example.net', $output);
            self::assertStringNotContainsString('shared_handler_vhost=other.net', $output);
            self::assertStringContainsString('php_cli binary=/usr/local/lsws/lsphp83/bin/php version=8.3.36 upload_progress_enabled=0 loaded_ini=/usr/local/lsws/lsphp83/etc/php.ini label=cli web_acceptance=no', $output);
            self::assertStringContainsString('web_runtime_measured=no', $output);
            self::assertStringContainsString('web_session_upload_progress_enabled=unknown', $output);
            self::assertStringContainsString('health_code=200', $output);
            self::assertStringContainsString('health_status=ok', $output);
            self::assertStringContainsString('homepage_code=200', $output);
            self::assertStringContainsString('login_code=200', $output);
            self::assertStringContainsString('other_php_handler=lsphp80', $output);
            self::assertStringContainsString('other_php_action=not_updated', $output);
            self::assertStringContainsString('php_apply_complete=yes', $output);
            self::assertStringNotContainsString('token@', $output);
            self::assertStringNotContainsString('super-secret-value', $output);

            $ini = (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini');
            self::assertStringContainsString("memory_limit = 128M\n", $ini);
            self::assertStringContainsString(";session.upload_progress.enabled = 1\n", $ini);
            self::assertStringContainsString("session.upload_progress.enabled_extra = 1\n", $ini);
            self::assertMatchesRegularExpression('/^session\\.upload_progress\\.enabled = 0$/m', $ini);
            self::assertDoesNotMatchRegularExpression('/^session\\.upload_progress\\.enabled = 1$/m', $ini);
            $backups = glob($root.'/home/testlig.net/shared/php-ini-backups/lsphp83-php.ini.*');
            self::assertIsArray($backups);
            self::assertCount(1, $backups);
            self::assertSame($original, (string) file_get_contents((string) $backups[0]));
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/php-ini-backups/health.json');
            self::assertSame("session.upload_progress.enabled = 0\n", (string) file_get_contents($root.'/usr/local/lsws/lsphp82/etc/php.ini'));
            $kill = (string) file_get_contents($root.'/state/kill.log');
            self::assertStringContainsString('4242', $kill);
            self::assertStringNotContainsString('77', $kill);
            self::assertStringNotContainsString('88', $kill);
            self::assertSame('', (string) file_get_contents($root.'/state/lswsctl.log'));
            $dnf = (string) file_get_contents($root.'/state/dnf.log');
            self::assertStringContainsString('makecache --disablerepo=* --enablerepo=litespeed*', $dnf);
            self::assertStringContainsString('changelog lsphp83-8.3.36-1.el9', $dnf);
            self::assertStringContainsString('--assumeno lsphp83-8.3.36-1.el9', $dnf);
            self::assertStringContainsString('-y update lsphp83-8.3.36-1.el9 lsphp83-common-8.3.36-1.el9', $dnf);
            self::assertStringNotContainsString('lsphp83*', $dnf);
            self::assertStringNotContainsString('lsphp80', $dnf);
            self::assertStringNotContainsString('lsphp82', $dnf);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testMissingPatchStillSetsIniAndWaits(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\n";
            $this->installStubs($root, '8.3.33', "Upgrading:\n lsphp83 x86_64 8.3.33-1.el9 litespeed\n");
            file_put_contents($root.'/state/changelog', "old cache only\nhttps://user:token@rpms.example/lsphp\n");
            file_put_contents($root.'/state/installed-changelog', "old\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root);

            self::assertStringContainsString('package_update=waiting', $output);
            self::assertStringContainsString('fix_evidence=not_confirmed', $output);
            self::assertStringContainsString('apply_result=temporary_mitigation', $output);
            self::assertStringNotContainsString('apply_result=patched', $output);
            self::assertStringContainsString('official_fix_version=not_candidate', $output);
            self::assertStringContainsString('lsphp83_installed=8.3.33-1.el9', $output);
            self::assertStringContainsString('cve_2026_109618=absent', $output);
            self::assertStringContainsString('cve_2026_109619=absent', $output);
            self::assertStringContainsString('ini_change=set_0', $output);
            self::assertStringContainsString('ini_after=0', $output);
            self::assertStringContainsString('php_apply_complete=yes', $output);
            self::assertStringContainsString('web_session_upload_progress_enabled=unknown', $output);
            self::assertStringNotContainsString('token@', $output);
            self::assertStringContainsString("memory_limit = 128M\n", (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertStringContainsString("session.upload_progress.enabled = 0\n", (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertFileDoesNotExist($root.'/state/updated');
            $dnf = (string) file_get_contents($root.'/state/dnf.log');
            self::assertStringNotContainsString('-y update', $dnf);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testUnexpectedRemovalStopsBeforeIniChange(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\nsession.upload_progress.enabled = 1\n";
            $this->installStubs($root, '8.3.36', "Removing:\n httpd x86_64 1-1.el9\nhttps://user:token@rpms.example/lsphp\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);

            self::assertStringContainsString('package_update=stopped', $output);
            self::assertStringContainsString('package_stop_reason=unexpected_removal', $output);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertStringContainsString('php_apply_complete=no', $output);
            self::assertStringNotContainsString('token@', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertFileDoesNotExist($root.'/state/updated');
            self::assertSame([], glob($root.'/home/testlig.net/shared/php-ini-backups/*') ?: []);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testConflictingUserIniStopsBeforeChanges(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\n";
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n");
            $this->writeTree($root, $original);
            $this->write($root.'/home/testlig.net/public_html/.user.ini', "session.upload_progress.enabled = 1\n");
            $output = $this->runApply($root, false);

            self::assertStringContainsString('site_override=conflict', $output);
            self::assertStringContainsString('package_update=not_attempted', $output);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertFileDoesNotExist($root.'/state/updated');
            $dnf = (string) file_get_contents($root.'/state/dnf.log');
            self::assertSame('', $dnf);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testHealthFailureRestoresIniWithoutDowngrade(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\nsession.upload_progress.enabled = 1\n";
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/health-fail', "1\n");
            $this->writeTree($root, $original);
            $this->writeProcesses($root);
            $output = $this->runApply($root, false);

            self::assertStringContainsString('package_update=updated', $output);
            self::assertStringContainsString('lsphp83_installed=8.3.36-1.el9', $output);
            self::assertStringContainsString('health_status=failed', $output);
            self::assertStringContainsString('ini_rollback=restored', $output);
            self::assertStringContainsString('rollback_worker_recycle_verified=exited', $output);
            self::assertStringContainsString('apply_result=failed', $output);
            self::assertStringContainsString('package_downgrade=no', $output);
            self::assertStringContainsString('php_apply_complete=no', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertSame('8.3.36|1.el9', trim((string) file_get_contents($root.'/state/version')));
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/php-ini-backups/health.json');
        } finally {
            $this->removeTree($root);
        }
    }

    public function testWorkflowStaysClosedUntilConfirmed(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/ops-lsphp83-security-apply.yml');
        $remote = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/gha-lsphp83-security-apply-remote.sh');
        self::assertStringContainsString('apply-lsphp83-php2shell-mitigation', $workflow);
        self::assertStringContainsString('VDS SSH operations are paused', $workflow);
        self::assertStringContainsString('group: testlig-vds-ssh', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString('queue: max', $workflow);
        self::assertStringNotContainsString('TESTLIG_PHP_APPLY_ROOT', $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
        self::assertStringContainsString('lswsctrl', $remote);
        self::assertStringNotContainsString('lswsctrl stop >/dev/null 2>&1 || true', $remote);
        self::assertStringNotContainsString('php-fpm', $remote);
        self::assertStringNotContainsString('phpinfo', $remote);
        self::assertStringNotContainsString('dnf downgrade', $remote);
        self::assertStringNotContainsString("dnf -y update 'lsphp83*'", $remote);
        self::assertStringContainsString('dnf -y update', $remote);
        self::assertStringNotContainsString('cat "$tmp" > "$ini_host"', $remote);
        self::assertStringContainsString('mv -f', $remote);
        self::assertSame(3, substr_count($remote, '</dev/null'));
        self::assertStringContainsString('--max-time 15', $remote);
        self::assertStringContainsString('timeout "$seconds"', $remote);
    }

    public function testBackupWriteAndRollbackFailures(): void
    {
        $original = "memory_limit = 128M\nsession.upload_progress.enabled = 1\n";
        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/fail-copy', "1\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('ini_backup=failed', $output);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertStringNotContainsString('ini_rollback=restored', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertFileDoesNotExist($root.'/state/updated');
        } finally {
            $this->removeTree($root);
        }

        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/fail-rename', "1\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertStringContainsString('ini_rollback=not_needed', $output);
            self::assertStringNotContainsString('ini_rollback=restored', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
        } finally {
            $this->removeTree($root);
        }

        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/health-fail', "1\n");
            $this->write($root.'/state/fail-rollback', "1\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('ini_rollback=failed', $output);
            self::assertStringNotContainsString('ini_rollback=restored', $output);
            self::assertStringContainsString('apply_result=failed', $output);
            self::assertNotSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertSame('8.3.36|1.el9', trim((string) file_get_contents($root.'/state/version')));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testSymlinkIniIsRejected(): void
    {
        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n");
            $this->writeTree($root, "memory_limit = 128M\n");
            $ini = $root.'/usr/local/lsws/lsphp83/etc/php.ini';
            $real = $root.'/usr/local/lsws/lsphp83/etc/php-real.ini';
            rename($ini, $real);
            $bash = $this->bashBinary();
            $dir = str_replace('\\', '/', \dirname($ini));
            $process = proc_open(
                [$bash, '-c', 'cd "$1" && ln -s php-real.ini php.ini && if [ -L php.ini ]; then echo link-ok; else echo link-missing; exit 1; fi', 'bash', $dir],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['MSYS' => 'winsymlinks:lnk'],
            );
            self::assertIsResource($process);
            $linkOut = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($process), $linkOut);
            self::assertStringContainsString('link-ok', $linkOut);
            $output = $this->runApply($root, false, ['MSYS' => 'winsymlinks:lnk']);
            self::assertStringContainsString('ini_change=rejected_symlink', $output);
            self::assertFileDoesNotExist($root.'/state/updated');
            self::assertStringNotContainsString('ini_rollback=restored', $output);
        } finally {
            $bash = $this->bashBinary();
            $dir = str_replace('\\', '/', $root.'/usr/local/lsws/lsphp83/etc');
            $cleanup = proc_open([$bash, '-c', 'cd "$1" && rm -f php.ini php.ini.lnk', 'bash', $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MSYS' => 'winsymlinks:lnk']);
            if (\is_resource($cleanup)) {
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($cleanup);
            }
            $this->removeTree($root);
        }
    }

    public function testFailedWorkerSignalAndStuckProcess(): void
    {
        $original = "memory_limit = 128M\nsession.upload_progress.enabled = 1\n";
        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/fail-kill', "1\n");
            $this->writeTree($root, $original);
            $this->writeProcesses($root);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('worker_recycle=failed', $output);
            self::assertStringContainsString('worker_recycle_verified=signal_failed', $output);
            self::assertStringContainsString('ini_rollback=restored', $output);
            self::assertStringContainsString('rollback_worker_recycle=failed', $output);
            self::assertStringNotContainsString('worker_recycle_verified=exited', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
        } finally {
            $this->removeTree($root);
        }

        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/kill-no-exit', "1\n");
            $this->writeTree($root, $original);
            $this->writeProcesses($root);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('worker_deleted_exe=4242', $output);
            self::assertStringContainsString('worker_recycle_verified=still_running', $output);
            self::assertStringContainsString('worker_recycle=failed', $output);
            self::assertStringNotContainsString('worker_recycle_verified=exited', $output);
            self::assertStringContainsString('ini_rollback=restored', $output);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testPreviewFailureAndCandidateMismatch(): void
    {
        $original = "memory_limit = 128M\n";
        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n");
            $this->write($root.'/state/preview-fail', "1\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('package_stop_reason=preview_failed', $output);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
            self::assertFileDoesNotExist($root.'/state/updated');
        } finally {
            $this->removeTree($root);
        }

        $root = $this->tempRoot();
        try {
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.37-1.el9 litespeed\n");
            $this->writeTree($root, $original);
            $output = $this->runApply($root, false);
            self::assertStringContainsString('package_stop_reason=candidate_mismatch', $output);
            self::assertStringContainsString('ini_change=not_applied', $output);
            self::assertFileDoesNotExist($root.'/state/updated');
            self::assertSame($original, (string) file_get_contents($root.'/usr/local/lsws/lsphp83/etc/php.ini'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testExclusiveHandlerUsesOlsRestart(): void
    {
        $root = $this->tempRoot();
        try {
            $original = "memory_limit = 128M\n";
            $this->installStubs($root, '8.3.36', "Upgrading:\n lsphp83 x86_64 8.3.36-1.el9 litespeed\n lsphp83-common x86_64 8.3.36-1.el9 litespeed\n");
            $this->writeTree($root, $original);
            unlink($root.'/usr/local/lsws/conf/vhosts/example.net/vhost.conf');
            $this->writeProcesses($root);
            $output = $this->runApply($root);
            self::assertStringContainsString('shared_handler_vhost=none', $output);
            self::assertStringContainsString('wide_restart=yes', $output);
            self::assertStringContainsString('worker_recycle=ols_restart', $output);
            self::assertStringContainsString('worker_recycle_verified=exited', $output);
            self::assertStringContainsString('apply_result=patched', $output);
            $control = (string) file_get_contents($root.'/state/lswsctl.log');
            self::assertStringContainsString('stop', $control);
            self::assertStringContainsString('start', $control);
            self::assertSame('', (string) file_get_contents($root.'/state/kill.log'));
            $processes = (string) file_get_contents($root.'/state/processes');
            self::assertStringContainsString('77|', $processes);
            self::assertStringNotContainsString('4242|', $processes);
        } finally {
            $this->removeTree($root);
        }
    }

    private function installStubs(string $root, string $candidate, string $preview): void
    {
        $this->write($root.'/state/version', "8.3.33|1.el9\n");
        $this->write($root.'/state/next-version', "8.3.36|1.el9\n");
        $this->write($root.'/state/repoquery', 'lsphp83|'.$candidate."|1.el9\n");
        $this->write($root.'/state/preview', $preview);
        $this->write($root.'/state/changelog', "8.3.36-1.el9\nCVE-2026-109618\nCVE-2026-109619\nhttps://user:token@rpms.example/lsphp\n");
        $this->write($root.'/state/installed-changelog', "8.3.33-1.el9\nold\n");
        $this->write($root.'/state/dnf.log', '');
        $this->write($root.'/state/kill.log', '');
        $this->write($root.'/state/lswsctl.log', '');
        $this->write($root.'/bin/dnf', <<<'BASH'
#!/bin/bash
root="$(cd "$(dirname "$0")/.." && pwd)"
printf '%s\n' "$*" >> "$root/state/dnf.log"
case "$*" in
  *makecache*)
    printf '%s\n' 'https://user:token@rpms.example/lsphp'
    exit 0
    ;;
  *repoquery*)
    cat "$root/state/repoquery"
    exit 0
    ;;
  *changelog*)
    cat "$root/state/changelog"
    exit 0
    ;;
  *--assumeno*)
    cat "$root/state/preview"
    if [ -f "$root/state/preview-fail" ]; then
      exit 2
    fi
    exit 1
    ;;
  *-y\ update*)
    cp "$root/state/next-version" "$root/state/version"
    cp "$root/state/changelog" "$root/state/installed-changelog"
    if [ -f "$root/state/processes" ]; then
      awk 'BEGIN { FS = OFS = "|" } $3 == "lshttpd" && $4 == "testlig.net" && $5 !~ / \(deleted\)$/ { $5 = $5 " (deleted)" } { print }' "$root/state/processes" > "$root/state/processes.tmp"
      mv "$root/state/processes.tmp" "$root/state/processes"
    fi
    printf '%s\n' 'updated' > "$root/state/updated"
    exit 0
    ;;
esac
exit 1
BASH);
        $this->write($root.'/bin/rpm', <<<'BASH'
#!/bin/bash
root="$(cd "$(dirname "$0")/.." && pwd)"
if [ "${1-}" = "-q" ] && [ "${2-}" = "--changelog" ]; then
  cat "$root/state/installed-changelog"
  exit 0
fi
if [ "${1-}" = "-q" ] && [ "${2-}" = "--qf" ] && [ "${4-}" = "lsphp83" ]; then
  tr -d '\n' < "$root/state/version"
  exit 0
fi
if [ "${1-}" = "-q" ] && [ "${2-}" = "lsphp80" ]; then
  exit 0
fi
exit 1
BASH);
        $this->write($root.'/bin/php', <<<'BASH'
#!/bin/bash
mode=""
ini=""
root="$(cd "$(dirname "$0")/.." && pwd)"
while [ $# -gt 0 ]; do
  case "$1" in
    -v) mode="v" ;;
    --ini) mode="ini" ;;
    -c) shift; ini="$1" ;;
    -r) mode="r" ;;
  esac
  shift
done
if [ "$mode" = "v" ]; then
  version="$(cut -d'|' -f1 < "$root/state/version")"
  printf 'PHP %s (cli)\n' "$version"
  exit 0
fi
if [ "$mode" = "ini" ]; then
  printf '%s\n' 'Configuration File (php.ini) Path: /usr/local/lsws/lsphp83/etc' 'Loaded Configuration File:         /usr/local/lsws/lsphp83/etc/php.ini'
  exit 0
fi
if [ "$mode" = "r" ]; then
  last="$(grep -E '^[[:space:]]*session\.upload_progress\.enabled[[:space:]]*=' "$ini" | tail -n 1 || true)"
  case "$last" in
    *"= 0"|*"=0") exit 0 ;;
  esac
  printf '%s' '1'
  exit 0
fi
exit 1
BASH);
        $this->write($root.'/bin/curl', <<<'BASH'
#!/bin/bash
root="$(cd "$(dirname "$0")/.." && pwd)"
out=""
url=""
while [ $# -gt 0 ]; do
  case "$1" in
    -o) shift; out="$1" ;;
    -w|-H|--resolve|--max-time|--connect-timeout) shift ;;
    -sk|-s|-k) ;;
    http*|https*) url="$1" ;;
  esac
  shift
done
code="200"
if [ -f "$root/state/health-fail" ] && printf '%s' "$url" | grep -q 'health'; then
  code="500"
fi
if [ -n "$out" ] && [ "$out" != "/dev/null" ]; then
  printf '%s' '{"status":"ok","secret":"super-secret-value"}' > "$out"
fi
printf '%s' "$code"
BASH);
        $this->write($root.'/bin/kill', <<<'BASH'
#!/bin/bash
root="$(cd "$(dirname "$0")/.." && pwd)"
printf '%s\n' "$*" >> "$root/state/kill.log"
if [ -f "$root/state/fail-kill" ]; then
  exit 1
fi
pid="${2-}"
if [ -f "$root/state/kill-no-exit" ]; then
  exit 0
fi
if [ -f "$root/state/processes" ]; then
  awk -v pid="$pid" 'BEGIN { FS = OFS = "|" } $1 != pid { print }' "$root/state/processes" > "$root/state/processes.tmp"
  mv "$root/state/processes.tmp" "$root/state/processes"
  if [ ! -f "$root/state/no-respawn" ]; then
    printf '%s\n' "$((pid + 5000))|2000|lshttpd|testlig.net|/usr/local/lsws/lsphp83/bin/lsphp" >> "$root/state/processes"
  fi
fi
exit 0
BASH);
        $this->write($root.'/bin/lswsctrl', <<<'BASH'
#!/bin/bash
root="$(cd "$(dirname "$0")/.." && pwd)"
printf '%s\n' "$*" >> "$root/state/lswsctl.log"
if [ -f "$root/state/lswsctrl-fail" ]; then
  exit 1
fi
if [ "$1" = "stop" ] && [ -f "$root/state/processes" ]; then
  awk 'BEGIN { FS = OFS = "|" } $3 != "lshttpd" { print }' "$root/state/processes" > "$root/state/processes.tmp"
  mv "$root/state/processes.tmp" "$root/state/processes"
fi
exit 0
BASH);
    }

    private function writeProcesses(string $root): void
    {
        $this->write($root.'/state/processes', <<<'TXT'
4242|1000|lshttpd|testlig.net|/usr/local/lsws/lsphp83/bin/lsphp
77|1001|bash|cli|/usr/local/lsws/lsphp83/bin/lsphp
88|1002|lshttpd|example.net|/usr/local/lsws/lsphp83/bin/lsphp
TXT);
    }

    private function writeTree(string $root, string $ini): void
    {
        $this->write($root.'/usr/local/lsws/lsphp83/etc/php.ini', $ini);
        $this->write($root.'/usr/local/lsws/lsphp82/etc/php.ini', "session.upload_progress.enabled = 0\n");
        $this->write($root.'/usr/local/lsws/lsphp80/bin/lsphp', "#!/bin/bash\nexit 0\n");
        $this->write($root.'/usr/local/lsws/conf/vhosts/testlig.net/vhost.conf', <<<'CONF'
docRoot /home/testlig.net/public_html
phpIniOverride /usr/local/lsws/lsphp83/etc/php.ini
# php_admin_value session.upload_progress.enabled 1
path /usr/local/lsws/lsphp83/bin/lsphp
env DB_PASSWORD super-secret-value
CONF);
        $this->write($root.'/usr/local/lsws/conf/vhosts/example.net/vhost.conf', "path /usr/local/lsws/lsphp83/bin/lsphp\n");
        $this->write($root.'/usr/local/lsws/conf/vhosts/other.net/vhost.conf', "path /usr/local/lsws/lsphp80/bin/lsphp\n");
        $this->write($root.'/home/testlig.net/public_html/index.html', "ok\n");
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', "3deca68635804136a53120605e9ff7caf3726170\n");
        $this->write($root.'/home/testlig.net/shared/CURRENT_RELEASE', "20261021T151953Z-3deca6863580\n");
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runApply(string $root, bool $expectSuccess = true, array $extraEnv = []): string
    {
        $script = \dirname(__DIR__, 2).'/deploy/vds/gha-lsphp83-security-apply-remote.sh';
        $bash = $this->bashBinary();
        $process = proc_open(
            [$bash, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['TESTLIG_PHP_APPLY_ROOT' => $root] + $extraEnv,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $stdout = \is_string($stdout) ? $stdout : '';
        $stderr = \is_string($stderr) ? $stderr : '';
        if ($expectSuccess) {
            self::assertSame(0, $status, $stdout."\n".$stderr);
        } else {
            self::assertNotSame(0, $status, $stdout."\n".$stderr);
        }

        return $stdout;
    }

    private function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Could not create '.$dir);
        }
        if (false === file_put_contents($path, $contents)) {
            self::fail('Could not write '.$path);
        }
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir().'/testlig-php-apply-'.bin2hex(random_bytes(4));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            self::fail('Could not create temp root');
        }

        return $root;
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                unlink($path);
            } elseif ($item->isDir()) {
                rmdir($path);
            }
        }
        rmdir($root);
    }

    private function bashBinary(): string
    {
        $candidates = ['C:\\Program Files\\Git\\bin\\bash.exe', 'bash'];
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

        self::fail('bash is required to run the apply fixture');
    }
}

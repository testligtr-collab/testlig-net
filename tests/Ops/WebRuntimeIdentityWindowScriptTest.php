<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use App\Security\WebRuntimeIdentityGate;
use PHPUnit\Framework\TestCase;

final class WebRuntimeIdentityWindowScriptTest extends TestCase
{
    private const SHA = 'd8264fc6b365b753c194f6f296a43da71fedc45b';

    private const SECRET = 'super-secret-value';

    private const PREVIOUS = 'previous-enabled-token';

    private ?bool $chmodApplies = null;

    public function testOpenChangesOnlyTheTwoKeysAndHidesFileContents(): void
    {
        $root = $this->fixture();
        $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\nWEB_RUNTIME_IDENTITY_ENABLED=".self::PREVIOUS."\n");

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringContainsString('expires_window_seconds=900', $result['output']);
        self::assertStringContainsString('record=kept', $result['output']);
        self::assertStringContainsString('http_sent=no', $result['output']);
        self::assertStringContainsString('process_env_read=no', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertStringNotContainsString(self::PREVIOUS, $result['output']);
        self::assertStringNotContainsString('1700000900', $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        $releaseEnv = $this->read($root, $release.'/.env.local');
        self::assertSame($shared, $releaseEnv);
        self::assertStringContainsString('OTHER_SECRET='.self::SECRET, $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_ENABLED=1', $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_EXPIRES_AT=1700000900', $shared);
        self::assertStringNotContainsString(self::PREVIOUS, $shared);
        self::assertFileExists($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testConflictAndPhpDumpStopWithoutChanges(): void
    {
        $cases = [
            'higher' => function (string $root, string $release): void {
                $this->write($root.'/'.$release.'/.env.prod', "WEB_RUNTIME_IDENTITY_ENABLED=1\n");
            },
            'dump' => function (string $root, string $release): void {
                $this->write($root.'/'.$release.'/.env.local.php', "<?php return ['APP_SECRET' => 'hidden'];\n");
            },
            'duplicate' => function (string $root, string $release): void {
                $this->write($root.'/home/testlig.net/shared/.env.local', "WEB_RUNTIME_IDENTITY_ENABLED=0\nWEB_RUNTIME_IDENTITY_ENABLED=1\n");
                $this->write($root.'/'.$release.'/.env.local', 'OTHER_SECRET='.self::SECRET."\n");
            },
            'ambiguous' => function (string $root, string $release): void {
                unset($release);
                $this->write($root.'/home/testlig.net/shared/.env.local', "export WEB_RUNTIME_IDENTITY_EXPIRES_AT=1\n");
            },
        ];
        foreach ($cases as $name => $arrange) {
            $root = $this->fixture();
            $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\n");
            $arrange($root, $release);
            $beforeShared = $this->read($root, 'home/testlig.net/shared/.env.local');
            $beforeRelease = $this->read($root, $release.'/.env.local');

            $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

            self::assertNotSame(0, $result['exit'], $name."\n".$result['output']);
            self::assertStringContainsString('result=stopped', $result['output'], $name);
            self::assertStringContainsString('files_changed=no', $result['output'], $name);
            self::assertStringNotContainsString(self::SECRET, $result['output'], $name);
            self::assertStringNotContainsString('hidden', $result['output'], $name);
            self::assertSame($beforeShared, $this->read($root, 'home/testlig.net/shared/.env.local'), $name);
            self::assertSame($beforeRelease, $this->read($root, $release.'/.env.local'), $name);
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
        }
    }

    public function testPartialFailureRestoresTheSharedFileAndKeepsTheRecordUntilVerified(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', self::SHA, [
            'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
            'TESTLIG_IDENTITY_WINDOW_FAIL' => 'after_shared',
        ]);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('rollback=verified', $result['output']);
        self::assertStringContainsString('record=removed', $result['output']);
        self::assertStringContainsString('files_changed=no', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testRestoreReturnsPreviousAbsenceWithoutClobberingLaterLines(): void
    {
        $root = $this->fixture();
        $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\nWEB_RUNTIME_IDENTITY_ENABLED=".self::PREVIOUS."\n");
        $opened = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);
        self::assertSame(0, $opened['exit'], $opened['output']);
        $this->write(
            $root.'/home/testlig.net/shared/.env.local',
            str_replace(self::SECRET, 'later-unrelated-value', $this->read($root, 'home/testlig.net/shared/.env.local')),
        );
        $this->write(
            $root.'/'.$release.'/.env.local',
            str_replace(self::SECRET, 'later-unrelated-value', $this->read($root, $release.'/.env.local')),
        );

        $restored = $this->runWindow($root, 'restore', self::SHA);

        self::assertSame(0, $restored['exit'], $restored['output']);
        self::assertStringContainsString('result=restored', $restored['output']);
        self::assertStringContainsString('record=removed', $restored['output']);
        self::assertStringNotContainsString(self::SECRET, $restored['output']);
        self::assertStringNotContainsString(self::PREVIOUS, $restored['output']);
        self::assertStringNotContainsString('later-unrelated-value', $restored['output']);
        self::assertStringNotContainsString('1700000900', $restored['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertStringContainsString('OTHER_SECRET=later-unrelated-value', $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_ENABLED='.self::PREVIOUS, $shared);
        self::assertStringNotContainsString('WEB_RUNTIME_IDENTITY_EXPIRES_AT=', $shared);
        self::assertSame($shared, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');

        $again = $this->runWindow($root, 'restore', self::SHA);
        self::assertNotSame(0, $again['exit'], $again['output']);
        self::assertStringContainsString('stop_reason=record_absent', $again['output']);
        self::assertStringContainsString('files_changed=no', $again['output']);
        self::assertSame($shared, $this->read($root, 'home/testlig.net/shared/.env.local'));
    }

    public function testReleaseMismatchStopsBeforeAnyEdit(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', str_repeat('a', 40), ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=release_mismatch', $result['output']);
        self::assertStringContainsString('files_changed=no', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testWorkflowStaysManualAndDoesNotCallTheSite(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/ops-web-runtime-identity-window.yml');
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/gha-web-runtime-identity-window-remote.sh');
        self::assertSame(900, WebRuntimeIdentityGate::MAX_WINDOW_SECONDS);
        self::assertStringContainsString('window_seconds=900', $script);
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString('open-web-runtime-identity', $workflow);
        self::assertStringContainsString('restore-web-runtime-identity', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString('testlig-vds-ssh', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $workflow));
        self::assertStringNotContainsString('curl ', $workflow);
        self::assertStringNotContainsString('curl ', $script);
        self::assertStringNotContainsString('environ', $script);
        self::assertStringNotContainsString('cmdline', $script);
        self::assertStringNotContainsString('lswsctrl', $script);
        self::assertStringNotContainsString('setfacl', $script);
        self::assertStringNotContainsString('/yonetim/', $script);
        self::assertStringNotContainsString('TESTLIG_IDENTITY_WINDOW_RECORD_UID', $workflow);
        self::assertStringNotContainsString('TESTLIG_IDENTITY_WINDOW_FAIL', $workflow);
        self::assertStringNotContainsString('TESTLIG_IDENTITY_WINDOW_MODE_LEDGER', $workflow);
        self::assertStringNotContainsString('cat "$tmp" >', $script);
        self::assertStringNotContainsString('/.identity-window.*', $script);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
    }

    public function testSourceReadErrorsKeepOriginalBytes(): void
    {
        foreach (['source_open', 'source_read', 'rewrite_open', 'rewrite_read'] as $fail) {
            $root = $this->fixture();
            $original = 'OTHER_SECRET='.self::SECRET."\nPLAIN_NOTE=keep-this-line\n";
            $release = $this->prepare($root, $original);
            $other = $root.'/home/testlig.net/shared/.identity-window.other-run';
            file_put_contents($other, "other-run-marker\n");

            $result = $this->runWindow($root, 'open', self::SHA, [
                'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
                'TESTLIG_IDENTITY_WINDOW_FAIL' => $fail,
            ]);

            self::assertNotSame(0, $result['exit'], $fail."\n".$result['output']);
            self::assertStringContainsString('result=stopped', $result['output'], $fail);
            self::assertStringNotContainsString('result=opened', $result['output'], $fail);
            self::assertStringNotContainsString('result=restored', $result['output'], $fail);
            self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'), $fail);
            self::assertSame($original, $this->read($root, $release.'/.env.local'), $fail);
            self::assertSame("other-run-marker\n", (string) file_get_contents($other), $fail);
            self::assertSame(['.identity-window.other-run'], $this->tempNames($root.'/home/testlig.net/shared'), $fail);
            self::assertSame([], $this->tempNames($root.'/'.$release), $fail);
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record', $fail);
            if ('rewrite_open' === $fail || 'rewrite_read' === $fail) {
                self::assertStringContainsString('rollback=verified', $result['output'], $fail);
                self::assertStringContainsString('record=removed', $result['output'], $fail);
            }
        }
    }

    public function testCleanupFailureStaysVisibleAndKeepsForeignTemps(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);
        $other = $root.'/home/testlig.net/shared/.identity-window.other-run';
        file_put_contents($other, "other-run-marker\n");

        $result = $this->runWindow($root, 'open', self::SHA, [
            'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
            'TESTLIG_IDENTITY_WINDOW_FAIL' => 'cleanup',
        ]);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('cleanup=failed', $result['output']);
        self::assertStringContainsString('result=stopped', $result['output']);
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertStringNotContainsString('result=restored', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertSame("other-run-marker\n", (string) file_get_contents($other));
        $names = $this->tempNames($root.'/home/testlig.net/shared');
        self::assertContains('.identity-window.other-run', $names);
        self::assertGreaterThan(1, \count($names));
        self::assertStringContainsString('rollback=not_needed', $result['output']);
        self::assertStringContainsString('record=absent', $result['output']);
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testRealReaderIoErrorKeepsOriginalBytes(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\nPLAIN_NOTE=keep-this-line\n";
        $release = $this->prepare($root, $original);
        $shared = $root.'/home/testlig.net/shared/.env.local';
        $lock = $this->blockReader($shared);
        try {
            $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);
        } finally {
            if (\is_resource($lock)) {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }
            $this->bash('chmod 644 '.$this->quote($shared).' '.$this->quote($root.'/'.$release.'/.env.local'));
        }

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertTrue(
            str_contains($result['output'], 'Permission denied') || str_contains($result['output'], 'Device or resource busy'),
            $result['output'],
        );
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertStringNotContainsString('result=restored', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
        $this->assertNoTemps($root, $release);
    }

    public function testFinalNewlineAndUnrelatedBytesStay(): void
    {
        $root = $this->fixture();
        $original = "WEB_RUNTIME_IDENTITY_ENABLED=old\nWEB_RUNTIME_IDENTITY_EXPIRES_AT=5\nPLAIN_NOTE=keep-this-line";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringNotContainsString('keep-this-line', $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertSame(
            "WEB_RUNTIME_IDENTITY_ENABLED=1\nWEB_RUNTIME_IDENTITY_EXPIRES_AT=1700000900\nPLAIN_NOTE=keep-this-line",
            $shared,
        );
        self::assertSame($shared, $this->read($root, $release.'/.env.local'));
    }

    public function testPrepareAndReplaceFailuresLeaveOriginalBytes(): void
    {
        foreach (['temp_prepare', 'atomic_replace'] as $fail) {
            $root = $this->fixture();
            $original = 'OTHER_SECRET='.self::SECRET."\n";
            $release = $this->prepare($root, $original);

            $result = $this->runWindow($root, 'open', self::SHA, [
                'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
                'TESTLIG_IDENTITY_WINDOW_FAIL' => $fail,
            ]);

            self::assertNotSame(0, $result['exit'], $fail."\n".$result['output']);
            self::assertStringContainsString('result=stopped', $result['output'], $fail);
            self::assertStringNotContainsString('result=opened', $result['output'], $fail);
            self::assertStringNotContainsString('result=restored', $result['output'], $fail);
            self::assertStringNotContainsString(self::SECRET, $result['output'], $fail);
            self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'), $fail);
            self::assertSame($original, $this->read($root, $release.'/.env.local'), $fail);
            $this->assertNoTemps($root, $release);
        }
    }

    public function testRecordCreateAndMoveFailuresLeaveEnvUntouched(): void
    {
        foreach (['record_write', 'record_move'] as $fail) {
            $root = $this->fixture();
            $original = 'OTHER_SECRET='.self::SECRET."\n";
            $release = $this->prepare($root, $original);

            $result = $this->runWindow($root, 'open', self::SHA, [
                'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
                'TESTLIG_IDENTITY_WINDOW_FAIL' => $fail,
            ]);

            self::assertNotSame(0, $result['exit'], $fail."\n".$result['output']);
            self::assertStringContainsString('result=stopped', $result['output'], $fail);
            self::assertStringContainsString('rollback=not_needed', $result['output'], $fail);
            self::assertStringContainsString('record=absent', $result['output'], $fail);
            self::assertStringNotContainsString('result=opened', $result['output'], $fail);
            self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'), $fail);
            self::assertSame($original, $this->read($root, $release.'/.env.local'), $fail);
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
            $this->assertNoTemps($root, $release);
        }
    }

    public function testRollbackContinuesWhenOneFileCannotBeRestored(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', self::SHA, [
            'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
            'TESTLIG_IDENTITY_WINDOW_FAIL' => 'rollback_shared_after_both',
        ]);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('rollback=failed', $result['output']);
        self::assertStringContainsString('record=kept', $result['output']);
        self::assertStringContainsString('result=stopped', $result['output']);
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertStringNotContainsString('result=restored', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_ENABLED=1', $shared);
        self::assertStringContainsString('OTHER_SECRET='.self::SECRET, $shared);
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileExists($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
        $this->assertNoTemps($root, $release);
    }

    public function testUnrelatedBytesAndMetadataStay(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\nPLAIN_NOTE=keep-this-line\n";
        $release = $this->prepare($root, $original);
        $sharedPath = $root.'/home/testlig.net/shared/.env.local';
        $releasePath = $root.'/'.$release.'/.env.local';
        $this->bash('chmod 640 '.$this->quote($sharedPath).' '.$this->quote($releasePath));
        $beforeShared = $this->statMeta($sharedPath);
        $beforeRelease = $this->statMeta($releasePath);

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringContainsString('record_mode=600', $result['output']);
        self::assertStringContainsString('record_directory=safe', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertStringNotContainsString('keep-this-line', $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertStringContainsString("PLAIN_NOTE=keep-this-line\n", $shared);
        self::assertStringContainsString('OTHER_SECRET='.self::SECRET, $shared);
        self::assertSame($beforeShared, $this->statMeta($sharedPath));
        self::assertSame($beforeRelease, $this->statMeta($releasePath));
        if ($this->chmodApplies()) {
            $recordMeta = $this->statMeta($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
            self::assertStringEndsWith(':600', $recordMeta);
        }
    }

    public function testWritableDirectoryRejectsTheRecord(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);
        if ($this->chmodApplies()) {
            $this->bash('chmod 777 '.$this->quote($root.'/home/testlig.net/shared'));
        } else {
            file_put_contents($root.'/mode-ledger', $this->bashPath($root)."/home/testlig.net/shared 777\n");
        }

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=record_directory', $result['output']);
        self::assertStringContainsString('result=stopped', $result['output']);
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testUnexpectedFileTypeStopsBeforeEditing(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);
        $shared = $root.'/home/testlig.net/shared/.env.local';
        $real = $shared.'.real';
        rename($shared, $real);
        $this->bash('ln -s .env.local.real '.$this->quote($shared).'; if [ ! -L '.$this->quote($shared).' ]; then rm -rf '.$this->quote($shared).'; mkdir '.$this->quote($shared).'; fi');

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=file_type', $result['output']);
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertSame($original, (string) file_get_contents($real));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testSecurityContextIsAppliedBeforeReplace(): void
    {
        $root = $this->fixture();
        $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\n");
        $bin = $root.'/bin';
        mkdir($bin);
        $log = $root.'/chcon.log';
        file_put_contents($bin.'/chcon', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> \"\$TESTLIG_IDENTITY_WINDOW_CONTEXT_LOG\"\nexit 0\n");
        $this->bash('chmod +x '.$this->quote($bin.'/chcon'));
        $inherited = getenv('PATH') ?: (getenv('Path') ?: '');
        $path = $bin.\PATH_SEPARATOR.'C:\\Program Files\\Git\\usr\\bin'.\PATH_SEPARATOR.$inherited;

        $result = $this->runWindow($root, 'open', self::SHA, [
            'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
            'TESTLIG_IDENTITY_WINDOW_CONTEXT' => 'fixture_u:object_r:fixture_t:s0',
            'TESTLIG_IDENTITY_WINDOW_CONTEXT_LOG' => $this->bashPath($log),
            'PATH' => $path,
            'Path' => $path,
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        $logged = (string) file_get_contents($log);
        self::assertStringContainsString('--reference=', $logged);
        self::assertStringContainsString('.env.local', $logged);
    }

    private function prepare(string $root, string $env): string
    {
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/shared/.env.local', $env);
        $this->write($root.'/home/testlig.net/releases/rel/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/releases/rel/.env.local', $env);
        $bash = $this->bashBinary();
        $site = $this->bashPath($root.'/home/testlig.net');
        $pipes = [];
        $process = proc_open([$bash, '-c', "ln -sfn releases/rel '$site/app'; if [ -L '$site/app' ]; then echo SYMLINK; else echo DIRECTORY; fi"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stdout.(string) $stderr);
        if (\is_string($stdout) && str_contains($stdout, 'SYMLINK')) {
            return 'home/testlig.net/releases/rel';
        }
        $this->write($root.'/home/testlig.net/app/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/app/.env.local', $env);

        return 'home/testlig.net/app';
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{exit: int, output: string}
     */
    private function runWindow(string $root, string $mode, string $sha, array $extra = []): array
    {
        $bash = $this->bashBinary();
        $script = $this->bashPath(\dirname(__DIR__, 2).'/deploy/vds/gha-web-runtime-identity-window-remote.sh');
        $env = getenv();
        $env['TESTLIG_IDENTITY_WINDOW_ROOT'] = $this->bashPath($root);
        $env['IDENTITY_WINDOW_MODE'] = $mode;
        $env['EXPECTED_SHA'] = $sha;
        unset($env['TESTLIG_IDENTITY_WINDOW_NOW'], $env['TESTLIG_IDENTITY_WINDOW_FAIL'], $env['TESTLIG_IDENTITY_WINDOW_CONTEXT'], $env['TESTLIG_IDENTITY_WINDOW_CONTEXT_LOG'], $env['TESTLIG_IDENTITY_WINDOW_MODE_LEDGER']);
        if (!$this->chmodApplies()) {
            $ledger = $root.'/mode-ledger';
            if (!is_file($ledger)) {
                file_put_contents($ledger, '');
            }
            $env['TESTLIG_IDENTITY_WINDOW_MODE_LEDGER'] = $this->bashPath($ledger);
        }
        foreach ($this->identityIds() as $name => $value) {
            $env[$name] = $value;
        }
        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }
        $pipes = [];
        $process = proc_open([$bash, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'output' => (string) $stdout.(string) $stderr,
        ];
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-identity-window-'.bin2hex(random_bytes(4));
        mkdir($root, 0700, true);

        return $root;
    }

    private function read(string $root, string $relative): string
    {
        $text = file_get_contents($root.'/'.$relative);
        self::assertIsString($text);

        return $text;
    }

    private function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function bashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^([A-Za-z]):\/(.*)$/', $normalized, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function identityIds(): array
    {
        $output = $this->bash('printf %s "$(id -u):$(id -g)"');
        $parts = explode(':', trim($output));
        self::assertCount(2, $parts);

        return [
            'TESTLIG_IDENTITY_WINDOW_RECORD_UID' => $parts[0],
            'TESTLIG_IDENTITY_WINDOW_RECORD_GID' => $parts[1],
        ];
    }

    private function chmodApplies(): bool
    {
        if (null !== $this->chmodApplies) {
            return $this->chmodApplies;
        }
        $path = sys_get_temp_dir().'/testlig-mode-'.bin2hex(random_bytes(3));
        file_put_contents($path, "x\n");
        $this->bash('chmod 600 '.$this->quote($path));
        $this->chmodApplies = str_ends_with($this->statMeta($path), ':600');
        unlink($path);

        return $this->chmodApplies;
    }

    private function statMeta(string $path): string
    {
        return trim($this->bash('stat -c %u:%g:%a '.$this->quote($path)));
    }

    /**
     * @return list<string>
     */
    private function tempNames(string $directory): array
    {
        $names = array_map('basename', glob($directory.'/.identity-window.*') ?: []);
        sort($names);

        return $names;
    }

    private function blockReader(string $path): mixed
    {
        $quoted = $this->quote($path);
        $this->bash('chmod 000 '.$quoted);
        $readable = trim($this->bash('if [ -r '.$quoted.' ]; then printf yes; else printf no; fi'));
        if ('no' === $readable) {
            return null;
        }
        $this->bash('chmod 644 '.$quoted);
        $handle = fopen($path, 'r');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));

        return $handle;
    }

    private function assertNoTemps(string $root, string $release): void
    {
        self::assertSame([], glob($root.'/home/testlig.net/shared/.identity-window.*') ?: []);
        self::assertSame([], glob($root.'/'.$release.'/.identity-window.*') ?: []);
    }

    private function quote(string $path): string
    {
        return "'".$this->bashPath($path)."'";
    }

    private function bash(string $command): string
    {
        $pipes = [];
        $process = proc_open([$this->bashBinary(), '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stdout.(string) $stderr);

        return (string) $stdout;
    }

    private function bashBinary(): string
    {
        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'bash'] as $candidate) {
            if ('bash' !== $candidate && !is_file($candidate)) {
                continue;
            }
            $pipes = [];
            $process = @proc_open([$candidate, '-c', 'echo ok'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                continue;
            }
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if (\is_string($stdout) && str_contains($stdout, 'ok')) {
                return $candidate;
            }
        }
        self::fail('bash is required');
    }
}

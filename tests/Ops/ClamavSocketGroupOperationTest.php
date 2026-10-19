<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/deploy/vds/gha-clamav-access-probe.php';

final class ClamavSocketGroupOperationTest extends TestCase
{
    private const SHA = 'd6fe38d394afb723a495869ab9462a190cad0e4a';

    public function testWorkflowStaysConfirmedAndSingleSession(): void
    {
        $workflow = $this->read('.github/workflows/ops-clamav-socket-group.yml');
        $remote = $this->read('deploy/vds/gha-clamav-socket-group-remote.sh');

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString('default: ""', $workflow);
        self::assertStringContainsString('default: restore', $workflow);
        self::assertStringContainsString("github.event.inputs.confirm == 'apply-clamav-socket-group'", $workflow);
        self::assertStringContainsString("github.event.inputs.confirm == 'restore-clamav-socket-group'", $workflow);
        self::assertStringContainsString('ClamAV socket group not confirmed', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString('ClamAV socket group skipped because SSH paused', $workflow);
        self::assertStringContainsString('group: testlig-vds-ssh', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $workflow));
        self::assertStringNotContainsString('scp ', $workflow);
        self::assertStringNotContainsString('TESTLIG_CLAMAV_GROUP_ROOT', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertStringContainsString('runuser -u "$account" --', $remote);
        self::assertStringNotContainsString(' -g ', $remote);
        self::assertStringNotContainsString(' -G ', $remote);
        self::assertStringNotContainsString('usermod', $remote);
        self::assertStringNotContainsString('setfacl', $remote);
        self::assertStringNotContainsString('setsebool', $remote);
        self::assertStringNotContainsString('systemctl', $remote);
        self::assertStringNotContainsString('chmod 666', $remote);
        self::assertStringContainsString('gpasswd -a "$account" virusgroup', $remote);
        self::assertStringContainsString('gpasswd -d "$account" virusgroup', $remote);
        self::assertStringContainsString('app_link="${site}/app"', $remote);
        self::assertStringNotContainsString('app_link="${site}/current"', $remote);
        self::assertStringNotContainsString('release_dir="${site}/releases/${expected}"', $remote);
        self::assertStringContainsString('clamav_socket_group_accept_main();', $remote);
        self::assertStringContainsString('recorded_group=5002', $this->read('deploy/vds/gha-clamav-access-probe-remote.sh'));
        self::assertTrue(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002, 4242],
        ]));
    }

    public function testRecordedGateStaysClosedAndSocketGroupEntryRejectsOtherSets(): void
    {
        self::assertTrue(clamav_socket_group_accept_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [4242, 5002],
        ], '4242'));
        self::assertFalse(clamav_socket_group_accept_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002],
        ], '4242'));
        self::assertFalse(clamav_socket_group_accept_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002, 4242, 9],
        ], '4242'));
        self::assertFalse(clamav_socket_group_accept_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002, 4242],
        ], '5002'));
        self::assertFalse(clamav_socket_group_accept_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002, 4242],
        ], '04242'));

        $code = $this->acceptSource();
        $result = $this->runPhp($code, ['/tmp/clamav-socket-group-not-opened', '4242']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('process_identity_match=no', $result['output']);
        self::assertStringNotContainsString('app_ping=', $result['output']);
    }

    public function testApplyRejectsUnexpectedSocketAndIdentityBeforeWriting(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $root = $this->operationRoot('bad-socket');
        file_put_contents($root.'/state/socket-mode', "640\n");
        $output = $this->runOperation($bash, $root, 'apply', 1);
        self::assertStringContainsString('stop_reason=socket', $output);
        self::assertStringContainsString('result=stopped', $output);
        self::assertFileDoesNotExist($root.'/state/gpasswd-calls');
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/clamav-socket-group.record');

        $identity = $this->operationRoot('bad-identity');
        file_put_contents($identity.'/state/bad-euid', "1\n");
        $identityOutput = $this->runOperation($bash, $identity, 'apply', 1);
        self::assertStringContainsString('stop_reason=posix_identity', $identityOutput);
        self::assertFileDoesNotExist($identity.'/state/gpasswd-calls');
    }

    public function testApplyAddsOnlyTheTargetMembershipAndAcceptsPing(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $root = $this->operationRoot('apply');
        $output = $this->runOperation($bash, $root, 'apply', 0);
        self::assertStringContainsString('result=applied', $output);
        self::assertStringContainsString('membership_added=yes', $output);
        self::assertStringContainsString('cli_socket_acceptance=yes', $output);
        self::assertStringContainsString('web_selinux_acceptance=no', $output);
        self::assertStringContainsString('process_identity_match=yes', $output);
        self::assertStringContainsString('app_ping=pong', $output);
        self::assertStringContainsString('app_version_daily=59', $output);
        self::assertStringContainsString('socket_dac_r=yes', $output);
        self::assertStringContainsString('socket_dac_w=yes', $output);
        self::assertStringContainsString('socket_parent_dac_r=no', $output);
        self::assertStringContainsString('socket_parent_dac_w=no', $output);
        self::assertStringContainsString('socket_parent_dac_x=yes', $output);
        self::assertStringContainsString('signature_main_cvd_dac_r=yes', $output);
        self::assertStringContainsString('signature_main_cvd_dac_w=no', $output);
        self::assertSame("-a testl3865 virusgroup\n", (string) file_get_contents($root.'/state/gpasswd-calls'));
        self::assertSame("testl3865\n", (string) file_get_contents($root.'/state/members'));
        $record = (string) file_get_contents($root.'/home/testlig.net/shared/clamav-socket-group.record');
        self::assertStringContainsString('membership_added=yes', $record);
        self::assertStringContainsString('virusgroup_gid=4242', $record);
        self::assertStringContainsString('previous_groups=5002', $record);
        self::assertStringContainsString('release_sha='.self::SHA, $record);
        $chmodLog = (string) file_get_contents($root.'/state/chmod-log');
        self::assertMatchesRegularExpression('/^600 /m', $chmodLog);
        $recordMode = $this->modeOf($bash, $root.'/home/testlig.net/shared/clamav-socket-group.record');
        if ('644' !== $recordMode) {
            self::assertSame('600', $recordMode);
        }
        $codes = (string) file_get_contents($root.'/state/codes');
        self::assertStringContainsString('clamav_socket_group_accept_main();', $codes);
        self::assertStringNotContainsString('4242', $codes);
        self::assertStringNotContainsString('clamd.sock', $codes);
        self::assertSame("EOF\n", (string) file_get_contents($root.'/state/last-stdin'));
        $args = (string) file_get_contents($root.'/state/last-args');
        self::assertStringContainsString('4242', $args);
        self::assertStringContainsString('clamd.sock', $args);
    }

    public function testNonZeroSocketStatusDiscardsPartialPingAndRollsBack(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        foreach (['139', '1'] as $status) {
            $root = $this->operationRoot('exit-'.$status);
            file_put_contents($root.'/state/exit-status', $status."\n");
            $output = $this->runOperation($bash, $root, 'apply', 1);
            self::assertStringContainsString('socket_command_status='.$status, $output);
            self::assertStringContainsString('app_ping=failed', $output);
            self::assertStringContainsString('result=rolled_back', $output);
            self::assertStringNotContainsString('app_ping=pong', $output);
            self::assertStringNotContainsString('LEAKED_SOCKET', $output);
            self::assertSame('', trim((string) file_get_contents($root.'/state/members')));
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/clamav-socket-group.record');
        }
    }

    public function testTimeoutDiscardsPartialPing(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $root = $this->operationRoot('timeout');
        file_put_contents($root.'/state/hang', "1\n");
        $started = microtime(true);
        $output = $this->runOperation($bash, $root, 'apply', 1);
        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(10.0, $elapsed);
        self::assertLessThan(28.0, $elapsed);
        self::assertStringContainsString('app_ping=timed_out', $output);
        self::assertStringNotContainsString('app_ping=pong', $output);
        self::assertStringContainsString('result=rolled_back', $output);
        self::assertSame('', trim((string) file_get_contents($root.'/state/members')));
    }

    public function testExtraGroupIsRejectedAndExistingMembershipIsKept(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $extra = $this->operationRoot('extra');
        file_put_contents($extra.'/state/extra-group', "9\n");
        $extraOutput = $this->runOperation($bash, $extra, 'apply', 1);
        self::assertStringContainsString('result=rolled_back', $extraOutput);
        self::assertStringNotContainsString('result=applied', $extraOutput);
        self::assertSame('', trim((string) file_get_contents($extra.'/state/members')));

        $existing = $this->operationRoot('existing');
        file_put_contents($existing.'/state/members', "testl3865\n");
        $existingOutput = $this->runOperation($bash, $existing, 'apply', 1);
        self::assertStringContainsString('stop_reason=posix_identity', $existingOutput);
        self::assertFileDoesNotExist($existing.'/state/gpasswd-calls');
        self::assertSame("testl3865\n", (string) file_get_contents($existing.'/state/members'));
    }

    public function testRecordRejectsUnsafeFilesAndKeepsRecordWhenDeleteFails(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $openDir = $this->operationRoot('open-dir');
        $this->bashExec($bash, 'chmod 777 .', $openDir.'/home/testlig.net/shared');
        file_put_contents($openDir.'/state/dir-mode', "777\n");
        $openOutput = $this->runOperation($bash, $openDir, 'apply', 1);
        self::assertStringContainsString('stop_reason=record_write', $openOutput);
        self::assertFileDoesNotExist($openDir.'/state/gpasswd-calls');

        $link = $this->operationRoot('symlink');
        $shared = $link.'/home/testlig.net/shared';
        file_put_contents($shared.'/target', "format=1\n");
        $this->bashExec($bash, 'ln -s target clamav-socket-group.record', $shared);
        $linkOutput = $this->runOperation($bash, $link, 'apply', 1);
        $linkKind = trim($this->bashExec($bash, 'if [ -L clamav-socket-group.record ]; then echo symlink; else echo file; fi', $shared));
        self::assertStringContainsString('symlink' === $linkKind ? 'stop_reason=record_type' : 'stop_reason=record_present', $linkOutput);
        self::assertStringContainsString('result=stopped', $linkOutput);
        self::assertFileDoesNotExist($link.'/state/gpasswd-calls');

        $restore = $this->operationRoot('restore-delete');
        file_put_contents($restore.'/state/members', "testl3865\n");
        file_put_contents($restore.'/state/fail-record-delete', "1\n");
        $this->writeRecord($restore, "virusgroup_gid=4242\n");
        $restoreOutput = $this->runOperation($bash, $restore, 'restore', 1);
        self::assertStringContainsString('result=rollback_failed', $restoreOutput);
        self::assertStringContainsString('record=kept', $restoreOutput);
        self::assertFileExists($restore.'/home/testlig.net/shared/clamav-socket-group.record');
        self::assertSame('', trim((string) file_get_contents($restore.'/state/members')));
        self::assertStringContainsString('running_processes_groups_unchanged=yes', $restoreOutput);

        $contradictory = $this->operationRoot('contradictory');
        file_put_contents($contradictory.'/state/members', "testl3865\n");
        $this->writeRecord($contradictory, "virusgroup_gid=4242\nvirusgroup_gid=9\n");
        $contradictoryOutput = $this->runOperation($bash, $contradictory, 'restore', 1);
        self::assertStringContainsString('stop_reason=record_fields', $contradictoryOutput);
        self::assertStringContainsString('record=kept', $contradictoryOutput);
        self::assertFileDoesNotExist($contradictory.'/state/gpasswd-calls');
        self::assertSame("testl3865\n", (string) file_get_contents($contradictory.'/state/members'));
    }

    public function testReleaseResolutionStopsBeforeMembershipOrRecordChanges(): void
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $other = str_repeat('a', 40);
        $cases = [
            ['missing', 'rm -rf app', 'app_link'],
            ['directory', 'rm -rf app && mkdir app', 'app_link'],
            ['broken', 'rm -rf app; ln -sfn releases/missing app; if [ -L app ] && [ ! -e app ]; then exit 0; fi; rm -rf app; mkdir -p releases/missing; cmd //c "mklink /J app releases\\missing" >/dev/null 2>&1 || true; rm -rf releases/missing; if [ -L app ] && [ ! -e app ]; then exit 0; fi; echo cannot-break-app-link; exit 1', 'app_link'],
            ['outside', 'mkdir -p ../outside; rm -rf app; ln -sfn ../outside app; if [ -L app ]; then exit 0; fi; rm -rf app; cmd //c "mklink /J app ..\\outside" >/dev/null 2>&1 || true; if [ -L app ]; then exit 0; fi; echo cannot-escape-app-link; exit 1', 'release_dir'],
        ];
        foreach (['apply', 'restore'] as $mode) {
            foreach ($cases as [$label, $command, $reason]) {
                $root = $this->operationRoot($mode.'-'.$label);
                $this->prepareRestore($root, 'restore' === $mode);
                $this->bashExec($bash, $command, $root.'/home/testlig.net');
                $this->assertReleaseUnchanged($bash, $root, $mode, $reason, 'restore' === $mode);
            }
            $current = $this->operationRoot($mode.'-current');
            $this->prepareRestore($current, 'restore' === $mode);
            file_put_contents($current.'/home/testlig.net/shared/CURRENT_REVISION', $other);
            $this->assertReleaseUnchanged($bash, $current, $mode, 'release_mismatch', 'restore' === $mode);

            $revision = $this->operationRoot($mode.'-revision');
            $this->prepareRestore($revision, 'restore' === $mode);
            file_put_contents($revision.'/home/testlig.net/releases/'.self::SHA.'/REVISION', $other);
            $this->assertReleaseUnchanged($bash, $revision, $mode, 'release_mismatch', 'restore' === $mode);
        }
    }

    private function acceptSource(): string
    {
        $helper = $this->read('deploy/vds/gha-clamav-access-probe.php');
        $marker = "// CLAMAV_ACCESS_PROBE_ENTRY\n";
        self::assertStringStartsWith("<?php\n", $helper);
        self::assertStringEndsWith($marker, $helper);
        $code = substr($helper, \strlen("<?php\n"));

        return substr($code, 0, -\strlen($marker))."clamav_socket_group_accept_main();\n";
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{exit: int, output: string}
     */
    private function runPhp(string $code, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            array_merge([\PHP_BINARY, '-r', $code, '--'], $arguments),
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

        return ['exit' => proc_close($process), 'output' => $stdout];
    }

    private function operationRoot(string $label): string
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/clamav-socket-group-'.$label.'-'.bin2hex(random_bytes(4));
        $shared = $root.'/home/testlig.net/shared';
        $release = $root.'/home/testlig.net/releases/'.self::SHA;
        mkdir($shared, 0755, true);
        mkdir($release, 0755, true);
        mkdir($root.'/run/clamd.scan', 0755, true);
        mkdir($root.'/var/lib/clamav', 0755, true);
        mkdir($root.'/state', 0755, true);
        file_put_contents($shared.'/CURRENT_REVISION', self::SHA);
        file_put_contents($release.'/REVISION', self::SHA);
        file_put_contents($root.'/run/clamd.scan/clamd.sock', '');
        foreach (['main.cvd', 'daily.cld', 'bytecode.cvd'] as $name) {
            file_put_contents($root.'/var/lib/clamav/'.$name, "signature\n");
        }
        file_put_contents($root.'/state/gid', "4242\n");
        file_put_contents($root.'/state/socket-mode', "660\n");
        file_put_contents($root.'/state/members', '');
        $this->writeFixtureCommands($root);
        $bash = $this->bashBinary();
        if (null === $bash) {
            self::markTestSkipped('Git Bash is required to create the production app symlink.');
        }
        $this->bashExec($bash, 'chmod 755 home/testlig.net/shared', $root);
        $this->linkApp($bash, $root);

        return $root;
    }

    /**
     * Linux CI uses ln -sfn, the same relative symlink as release-deploy.sh.
     * Windows ln copies a directory, and mklink /D is not permitted here. A directory
     * junction is only a local stand-in for Git Bash -L and readlink -f. The remote
     * script does not accept that stand-in as a plain directory and has no fixture bypass.
     */
    private function linkApp(string $bash, string $root): void
    {
        $site = $root.'/home/testlig.net';
        $kind = trim($this->bashExec($bash, 'rm -rf app; ln -sfn releases/'.self::SHA.' app; if [ -L app ] && [ "$(readlink -f app)" = "$(readlink -f releases/'.self::SHA.')" ]; then echo symlink; exit 0; fi; rm -rf app; cmd //c "mklink /J app releases\\'.self::SHA.'" >/dev/null 2>&1 || true; if [ -L app ] && [ "$(readlink -f app)" = "$(readlink -f releases/'.self::SHA.')" ]; then echo junction; exit 0; fi; echo unavailable', $site));
        if ('unavailable' === $kind) {
            self::markTestSkipped('Windows cannot create the production app symlink. The remote script still requires /home/testlig.net/app to be a symlink into site/releases, and this fixture does not bypass that check.');
        }
        self::assertContains($kind, ['symlink', 'junction']);
    }

    private function prepareRestore(string $root, bool $restore): void
    {
        if (!$restore) {
            return;
        }
        file_put_contents($root.'/state/members', "testl3865\n");
        $this->writeRecord($root, "virusgroup_gid=4242\n");
    }

    private function assertReleaseUnchanged(string $bash, string $root, string $mode, string $reason, bool $recordKept): void
    {
        $record = $root.'/home/testlig.net/shared/clamav-socket-group.record';
        $before = is_file($record) ? (string) file_get_contents($record) : null;
        $members = (string) file_get_contents($root.'/state/members');
        $output = $this->runOperation($bash, $root, $mode, 1);
        self::assertStringContainsString('stop_reason='.$reason, $output);
        self::assertStringContainsString('result=stopped', $output);
        self::assertStringContainsString($recordKept ? 'record=kept' : 'record=absent', $output);
        self::assertFileDoesNotExist($root.'/state/gpasswd-calls');
        self::assertSame($members, (string) file_get_contents($root.'/state/members'));
        if ($recordKept) {
            self::assertSame($before, (string) file_get_contents($record));
        } else {
            self::assertFileDoesNotExist($record);
        }
    }

    private function writeFixtureCommands(string $root): void
    {
        $dir = str_replace("'", "'\\''", $root);
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
        file_put_contents($root.'/fixture-stat', <<<BASH
#!/bin/bash
dir='{$dir}'
fmt="\$2"
path="\$3"
case "\$path" in
  */home/testlig.net)
    [ "\$fmt" = "%u" ] && printf '%s\n' 5002 && exit 0
    ;;
  */home/testlig.net/shared)
    if [ "\$fmt" = "%a" ] && [ -f "\$dir/state/dir-mode" ]; then
      tr -d '[:space:]' < "\$dir/state/dir-mode"
      printf '\n'
      exit 0
    fi
    exec /usr/bin/stat "\$@"
    ;;
  */clamd.sock)
    case "\$fmt" in
      %U) printf '%s\n' clamscan ;;
      %G) printf '%s\n' virusgroup ;;
      %a) tr -d '[:space:]' < "\$dir/state/socket-mode" ;;
    esac
    printf '\n'
    exit 0
    ;;
  */clamd.scan)
    case "\$fmt" in
      %U) printf '%s\n' clamscan ;;
      %G) printf '%s\n' virusgroup ;;
      %a) printf '%s\n' 710 ;;
    esac
    exit 0
    ;;
  *clamav-socket-group*)
    if [ "\$fmt" = "%a" ] && [ -f "\$dir/state/chmod-log" ]; then
      logged="\$(awk -v p="\$path" '\$2==p {m=\$1} END {print m}' "\$dir/state/chmod-log")"
      if [ -n "\$logged" ]; then
        printf '%s\n' "\$logged"
        exit 0
      fi
    fi
    exec /usr/bin/stat "\$@"
    ;;
esac
exit 1
BASH);
        file_put_contents($root.'/fixture-chmod', <<<BASH
#!/bin/bash
dir='{$dir}'
mkdir -p "\$dir/state"
printf '%s %s\n' "\$1" "\$2" >> "\$dir/state/chmod-log"
exec /usr/bin/chmod "\$@"
BASH);
        file_put_contents($root.'/fixture-getent', <<<BASH
#!/bin/bash
dir='{$dir}'
[ "\$1" = "group" ] && [ "\$2" = "virusgroup" ] || exit 1
gid="\$(tr -d '[:space:]' < "\$dir/state/gid")"
members="\$(tr '\n' ',' < "\$dir/state/members" | sed 's/,\$//')"
printf '%s\n' "virusgroup:x:\${gid}:\${members}"
BASH);
        file_put_contents($root.'/fixture-gpasswd', <<<BASH
#!/bin/bash
dir='{$dir}'
mkdir -p "\$dir/state"
printf '%s\n' "\$*" >> "\$dir/state/gpasswd-calls"
if [ "\$1" = "-a" ] && [ "\$2" = "testl3865" ] && [ "\$3" = "virusgroup" ]; then
  [ -f "\$dir/state/fail-add" ] && exit 1
  printf '%s\n' testl3865 >> "\$dir/state/members"
  exit 0
fi
if [ "\$1" = "-d" ] && [ "\$2" = "testl3865" ] && [ "\$3" = "virusgroup" ]; then
  [ -f "\$dir/state/fail-delete" ] && exit 1
  grep -vx testl3865 "\$dir/state/members" > "\$dir/state/members.tmp" || true
  mv "\$dir/state/members.tmp" "\$dir/state/members"
  exit 0
fi
exit 1
BASH);
        file_put_contents($root.'/fixture-runuser', <<<BASH
#!/bin/bash
dir='{$dir}'
if [ "\$1" != "-u" ] || [ "\$2" != "testl3865" ] || [ "\$3" != "--" ]; then
  exit 1
fi
shift 3
if [ "\$1" = "test" ]; then
  flag="\$2"
  path="\$3"
  member=no
  grep -qx testl3865 "\$dir/state/members" && member=yes
  case "\$path" in
    */main.cvd|*/daily.cld|*/bytecode.cvd)
      [ "\$flag" = "-r" ] && exit 0
      exit 1
      ;;
    */clamd.sock)
      [ "\$member" = "yes" ] && [ "\$flag" = "-r" -o "\$flag" = "-w" ] && exit 0
      exit 1
      ;;
    */clamd.scan)
      [ "\$flag" = "-r" ] || [ "\$flag" = "-w" ] && exit 1
      [ "\$flag" = "-x" ] && [ "\$member" = "yes" ] && exit 0
      exit 1
      ;;
  esac
  exit 1
fi
"\$@"
BASH);
        file_put_contents($root.'/fixture-php', <<<BASH
#!/bin/bash
dir='{$dir}'
code=""
prev=""
seen=0
args=""
for arg in "\$@"; do
  if [ "\$prev" = "-r" ]; then
    code="\$arg"
  fi
  if [ "\$seen" = "1" ]; then
    printf '%s\n' "\$arg" >> "\$dir/state/last-args"
    args="\$args \$arg"
  fi
  if [ "\$arg" = "--" ]; then
    seen=1
  fi
  prev="\$arg"
done
printf '%s\n---\n' "\$code" >> "\$dir/state/codes"
payload="\$(cat || true)"
if [ -z "\$payload" ]; then
  printf '%s\n' EOF > "\$dir/state/last-stdin"
else
  printf '%s' "\$payload" > "\$dir/state/last-stdin"
fi
first="\$(printf '%s\n' "\$args" | awk 'NF { print \$1; exit }')"
if [ "\$first" = "--identity" ]; then
  if [ -f "\$dir/state/bad-euid" ]; then
    printf '%s\n' identity_helper_started=yes process_euid=5003 process_egid=5002 process_group=5002
    exit 0
  fi
  if [ -f "\$dir/state/posix-only-5002" ] || ! grep -qx testl3865 "\$dir/state/members"; then
    printf '%s\n' identity_helper_started=yes process_euid=5002 process_egid=5002 process_group=5002
    exit 0
  fi
  gid="\$(tr -d '[:space:]' < "\$dir/state/gid")"
  printf '%s\n' identity_helper_started=yes process_euid=5002 process_egid=5002 process_group=5002 "process_group=\$gid"
  if [ -f "\$dir/state/extra-group" ]; then
    printf '%s\n' process_group=9
  fi
  exit 0
fi
if [ -f "\$dir/state/hang" ]; then
  printf '%s\n' identity_helper_started=yes process_identity_match=yes app_ping=pong app_version_daily=59
  sleep 30
  exit 0
fi
if [ -f "\$dir/state/exit-status" ]; then
  printf '%s\n' app_ping=pong app_version_daily=59 LEAKED_SOCKET
  exit "\$(tr -d '[:space:]' < "\$dir/state/exit-status")"
fi
printf '%s\n' identity_helper_started=yes process_identity_match=yes app_ping=pong app_version_daily=59
exit 0
BASH);
    }

    private function writeRecord(string $root, string $body): void
    {
        $path = $root.'/home/testlig.net/shared/clamav-socket-group.record';
        file_put_contents($path, "format=1\nrelease_sha=".self::SHA."\n".$body."previous_groups=5002\nmembership_added=yes\n");
        $bash = $this->bashBinary() ?? 'bash';
        $this->bashExec($bash, 'chmod 600 clamav-socket-group.record', \dirname($path));
        $bashRecord = trim($this->bashExec($bash, 'pwd', \dirname($path))).'/clamav-socket-group.record';
        file_put_contents($root.'/state/chmod-log', "600 {$bashRecord}\n", \FILE_APPEND);
    }

    private function runOperation(string $bash, string $root, string $mode, int $expectedExit): string
    {
        $bashRoot = trim($this->bashExec($bash, 'cd '.escapeshellarg($root).' && pwd'));
        $script = str_replace(
            'root="${TESTLIG_CLAMAV_GROUP_ROOT:-}"',
            "root='".str_replace("'", '', $bashRoot)."'",
            $this->read('deploy/vds/gha-clamav-socket-group-remote.sh'),
        );
        $prefix = "EXPECTED_SHA='".self::SHA."'\nCLAMAV_GROUP_MODE='".$mode."'\nACCESS_PROBE_PHP_B64='".base64_encode($this->read('deploy/vds/gha-clamav-access-probe.php'))."'\n";
        $pipes = [];
        $process = proc_open([$bash], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $prefix.$script);
        fclose($pipes[0]);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ([1, 2] as $index) {
            if (\is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        self::assertSame($expectedExit, proc_close($process), $stdout."\n".$stderr);

        return $stdout;
    }

    private function modeOf(string $bash, string $path): string
    {
        $output = $this->bashExec($bash, 'stat -c %a '.basename($path), \dirname($path));

        return trim($output);
    }

    private function bashExec(string $bash, string $command, string $cwd = ''): string
    {
        $pipes = [];
        $process = proc_open(
            [$bash, '-c', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            '' === $cwd ? null : $cwd,
        );
        self::assertIsResource($process);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::assertSame(0, proc_close($process), $stdout."\n".$stderr);

        return $stdout;
    }

    private function bashBinary(): ?string
    {
        $git = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (is_file($git)) {
            return $git;
        }
        $process = @proc_open(['bash', '-c', 'echo ok'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return str_contains($stdout, 'ok') ? 'bash' : null;
    }

    private function read(string $relative): string
    {
        $text = file_get_contents(\dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text);

        return $text;
    }
}

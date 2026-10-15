<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class ClamavInstallScriptTest extends TestCase
{
    public function testReleaseMismatchStopsBeforeInstall(): void
    {
        $root = $this->fixture();
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n");
        $bin = $this->fakeBin($root);

        $result = $this->runScript($root, $bin, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=release_mismatch', $result['output']);
        self::assertStringContainsString('summary_install=stopped', $result['output']);
        self::assertFileDoesNotExist($root.'/dnf.log');
    }

    public function testExistingConfigStopsBeforeInstall(): void
    {
        $root = $this->fixture();
        $sha = 'cccccccccccccccccccccccccccccccccccccccc';
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', $sha."\n");
        $this->write($root.'/etc/os-release', "ID=\"almalinux\"\nVERSION_ID=\"9.8\"\n");
        $this->write($root.'/proc/meminfo', "MemAvailable: 6319480 kB\n");
        $this->write($root.'/disk-avail', '53161009152');
        $this->write($root.'/etc/clamd.d/scan.conf', "#Example\n");
        $bin = $this->fakeBin($root);

        $result = $this->runScript($root, $bin, $sha);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=existing_config', $result['output']);
        self::assertFileDoesNotExist($root.'/dnf.log');
        self::assertStringContainsString('summary_cleanup=ok', $result['output']);
    }

    public function testFreshclamFailureRollsBackConfigAndRemovesTemp(): void
    {
        $root = $this->fixture();
        $sha = 'dddddddddddddddddddddddddddddddddddddddd';
        $this->prepareHost($root, $sha);
        $bin = $this->fakeBin($root);

        $result = $this->runScript($root, $bin, $sha);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=freshclam_failed', $result['output']);
        self::assertStringContainsString('summary_signature=failed', $result['output']);
        self::assertStringContainsString('summary_accept=not_run', $result['output']);
        self::assertStringContainsString('summary_updater=not_run', $result['output']);
        self::assertStringContainsString('summary_cleanup=ok', $result['output']);
        $config = (string) file_get_contents($root.'/etc/clamd.d/scan.conf');
        self::assertStringContainsString('#LocalSocket /run/clamd.scan/clamd.sock', $config);
        self::assertStringNotContainsString('LocalSocketMode 660', $config);
        $tempEntries = glob($root.'/tmp/testlig-clamav-accept.*');
        self::assertIsArray($tempEntries);
        self::assertSame([], $tempEntries);
        $systemctl = (string) file_get_contents($root.'/systemctl.log');
        self::assertStringContainsString('stop clamav-freshclam.service', $systemctl);
        self::assertStringContainsString('stop clamav-freshclam-once.timer', $systemctl);
        self::assertStringContainsString('disable clamav-freshclam-once.timer', $systemctl);
        self::assertStringContainsString('disable --now clamd@scan.service', $systemctl);
        self::assertStringContainsString('disable --now clamav-freshclam.service', $systemctl);
        self::assertFileDoesNotExist($root.'/setsebool.log');
    }

    public function testDatabaseDirectoryMismatchStopsWithoutFreshclam(): void
    {
        $root = $this->fixture();
        $sha = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $this->prepareHost($root, $sha);
        $bin = $this->fakeBin($root, '/var/lib/clamav', '/var/lib/other');

        $result = $this->runScript($root, $bin, $sha);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=database_directory_mismatch', $result['output']);
        self::assertFileDoesNotExist($root.'/freshclam.log');
        self::assertStringContainsString('cleanup_units=ok', $result['output']);
        self::assertStringContainsString('summary_cleanup=ok', $result['output']);
        $systemctl = (string) file_get_contents($root.'/systemctl.log');
        self::assertStringContainsString('disable --now clamd@scan.service', $systemctl);
    }

    public function testDeleteFailureKeepsRollbackAndRejectsSuccess(): void
    {
        $root = $this->fixture();
        $sha = 'ffffffffffffffffffffffffffffffffffffffff';
        $this->prepareHost($root, $sha);
        $bin = $this->fakeBin($root, '/var/lib/clamav', '/var/lib/clamav', true, true);

        $result = $this->runScript($root, $bin, $sha);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=freshclam_failed', $result['output']);
        self::assertStringContainsString('cleanup_work=failed', $result['output']);
        self::assertStringContainsString('cleanup_config=ok', $result['output']);
        self::assertStringContainsString('cleanup_units=ok', $result['output']);
        self::assertStringContainsString('summary_cleanup=failed', $result['output']);
        self::assertStringNotContainsString('summary_cleanup=ok', $result['output']);
        self::assertStringNotContainsString('summary_updater=enabled', $result['output']);
        $config = (string) file_get_contents($root.'/etc/clamd.d/scan.conf');
        self::assertMatchesRegularExpression('/^Example$/m', $config);
        self::assertStringNotContainsString('LocalSocketMode 660', $config);
        $systemctl = (string) file_get_contents($root.'/systemctl.log');
        self::assertStringContainsString('disable --now clamav-freshclam.service', $systemctl);
        self::assertStringContainsString('disable --now clamav-freshclam-once.timer', $systemctl);
        self::assertFileExists($root.'/rm.log');
    }

    public function testSuccessfulInstallEnablesOnlyTheFreshclamService(): void
    {
        $root = $this->fixture();
        $sha = '1111111111111111111111111111111111111111';
        $this->prepareHost($root, $sha);
        $bin = $this->fakeBin($root, '/var/lib/clamav', '/var/lib/clamav', false, false, 0);
        $this->write($root.'/home/testlig.net/app/vendor/autoload.php', "<?php\n");

        $result = $this->runScript($root, $bin, $sha, true);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('summary_install=installed', $result['output']);
        self::assertStringContainsString('summary_signature=current', $result['output']);
        self::assertStringContainsString('summary_socket=ok', $result['output']);
        self::assertStringContainsString('summary_accept=ok', $result['output']);
        self::assertStringContainsString('summary_updater=enabled', $result['output']);
        self::assertStringContainsString('summary_cleanup=ok', $result['output']);
        self::assertStringContainsString('cleanup_work=ok', $result['output']);
        $systemctl = (string) file_get_contents($root.'/systemctl.log');
        self::assertStringContainsString('enable --now clamav-freshclam.service', $systemctl);
        self::assertStringNotContainsString('enable --now clamav-freshclam-once.timer', $systemctl);
        $php = (string) file_get_contents($root.'/php.log');
        self::assertStringContainsString('dns '.$this->bashPath($root).'/var/lib/clamav', str_replace('\\', '/', $php));
        $config = (string) file_get_contents($root.'/etc/clamd.d/scan.conf');
        self::assertStringContainsString('#Example', $config);
        self::assertStringContainsString('DatabaseDirectory /var/lib/clamav', $config);
        self::assertStringContainsString('LocalSocketMode 660', $config);
        self::assertDoesNotMatchRegularExpression('/^Example[[:space:]]*$/m', $config);
        $freshclam = (string) file_get_contents($root.'/freshclam.log');
        self::assertSame(1, substr_count($freshclam, 'called'));
        self::assertFileDoesNotExist($root.'/setsebool.log');
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runScript(string $root, string $bin, string $expectedSha, bool $withAccept = false): array
    {
        $bash = $this->bashBinary();
        $script = $this->bashPath(\dirname(__DIR__, 2).'/deploy/vds/gha-clamav-install-remote.sh');
        $env = getenv();
        $inheritedPath = $env['PATH'] ?? $env['Path'] ?? '';
        $gitUsr = 'C:\\Program Files\\Git\\usr\\bin';
        $env['PATH'] = $bin.\PATH_SEPARATOR.$gitUsr.\PATH_SEPARATOR.$inheritedPath;
        $env['Path'] = $env['PATH'];
        $env['TESTLIG_CLAMAV_TEST_ROOT'] = $this->bashPath($root);
        $env['EXPECTED_SHA'] = $expectedSha;
        $env['TESTLIG_PHP_BIN'] = $this->bashPath($bin).'/php';
        if ($withAccept) {
            $env['ACCEPT_PHP_B64'] = base64_encode("<?php\n");
        } else {
            unset($env['ACCEPT_PHP_B64']);
        }
        $command = [$bash, $script];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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

    private function prepareHost(string $root, string $sha): void
    {
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', $sha."\n");
        $this->write($root.'/etc/os-release', "ID=\"almalinux\"\nVERSION_ID=\"9.8\"\n");
        $this->write($root.'/proc/meminfo', "MemAvailable: 6319480 kB\n");
        $this->write($root.'/disk-avail', '53161009152');
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-clamav-install-'.bin2hex(random_bytes(4));
        mkdir($root, 0700, true);

        return $root;
    }

    private function fakeBin(string $root, string $scanDatabase = '/var/lib/clamav', string $freshDatabase = '/var/lib/clamav', bool $activeExample = false, bool $refuseTempDelete = false, int $freshclamExit = 1): string
    {
        $bin = $root.'/bin';
        mkdir($bin, 0700, true);
        $example = $activeExample ? 'Example' : '#Example';
        $dnf = <<<SH
#!/bin/sh
printf '%s\\n' "\$*" >> "\$TESTLIG_CLAMAV_TEST_ROOT/dnf.log"
if printf '%s' "\$*" | grep -q 'clamav clamd'; then
  mkdir -p "\$TESTLIG_CLAMAV_TEST_ROOT/etc/clamd.d" "\$TESTLIG_CLAMAV_TEST_ROOT/etc" "\$TESTLIG_CLAMAV_TEST_ROOT/usr/lib/systemd/system"
  printf '%s\\n' '$example' '#LocalSocket /run/clamd.scan/clamd.sock' 'DatabaseDirectory $scanDatabase' > "\$TESTLIG_CLAMAV_TEST_ROOT/etc/clamd.d/scan.conf"
  printf '%s\\n' '#Example' 'DatabaseDirectory $freshDatabase' > "\$TESTLIG_CLAMAV_TEST_ROOT/etc/freshclam.conf"
  for unit in clamd@.service clamav-freshclam.service clamav-freshclam-once.service clamav-freshclam-once.timer; do
    printf '%s\\n' '[Unit]' > "\$TESTLIG_CLAMAV_TEST_ROOT/usr/lib/systemd/system/\$unit"
  done
fi
exit 0
SH;
        $systemctl = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$TESTLIG_CLAMAV_TEST_ROOT/systemctl.log"
case "$*" in
  *"enable --now clamd@scan.service"*)
    dir="$TESTLIG_CLAMAV_TEST_ROOT/run/clamd.scan"
    mkdir -p "$dir"
    perl -e 'use Socket; my $p = $ARGV[0]; unlink $p; socket(my $s, AF_UNIX, SOCK_STREAM, 0) or die $!; bind($s, pack_sockaddr_un($p)) or die $!; chmod 0660, $p;' "$dir/clamd.sock"
    ;;
  *"is-enabled clamav-freshclam.service"*)
    printf '%s\n' enabled
    exit 0
    ;;
  *"is-active clamav-freshclam.service"*)
    printf '%s\n' active
    exit 0
    ;;
  *"is-enabled clamav-freshclam-once.timer"*|*"is-active clamav-freshclam-once.timer"*)
    printf '%s\n' disabled
    exit 1
    ;;
esac
exit 0
SH;
        $rm = <<<'SH'
#!/bin/sh
refused=0
for arg in "$@"; do
  case "$arg" in
    *testlig-clamav-accept*)
      refused=1
      ;;
  esac
done
if [ "$refused" -eq 1 ]; then
  printf '%s\n' "$*" >> "$TESTLIG_CLAMAV_TEST_ROOT/rm.log"
  exit 1
fi
exec /usr/bin/rm "$@"
SH;
        $this->write($bin.'/dnf', $dnf);
        $this->write($bin.'/systemctl', $systemctl);
        $this->write($bin.'/freshclam', "#!/bin/sh\nprintf '%s\\n' called >> \"\$TESTLIG_CLAMAV_TEST_ROOT/freshclam.log\"\nexit {$freshclamExit}\n");
        $this->write($bin.'/php', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$TESTLIG_CLAMAV_TEST_ROOT/php.log\"\nexit 0\n");
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->write($bin.'/stat', "#!/bin/sh\nif [ \"\$1\" = -c ] && [ \"\$2\" = %a ] && [ -S \"\$3\" ]; then\n  printf '%s\\n' 660\n  exit 0\nfi\nexec /usr/bin/stat \"\$@\"\n");
        }
        if ($refuseTempDelete) {
            $this->write($bin.'/rm', $rm);
        }
        foreach (['dnf', 'systemctl', 'freshclam', 'php', 'rm', 'stat'] as $name) {
            if (is_file($bin.'/'.$name)) {
                chmod($bin.'/'.$name, 0700);
            }
        }

        return $bin;
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

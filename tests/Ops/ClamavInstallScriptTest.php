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
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', $sha."\n");
        $this->write($root.'/etc/os-release', "ID=\"almalinux\"\nVERSION_ID=\"9.8\"\n");
        $this->write($root.'/proc/meminfo', "MemAvailable: 6319480 kB\n");
        $this->write($root.'/disk-avail', '53161009152');
        $bin = $this->fakeBin($root);

        $result = $this->runScript($root, $bin, $sha);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=freshclam_failed', $result['output']);
        self::assertStringContainsString('summary_signature=failed', $result['output']);
        self::assertStringContainsString('summary_accept=not_run', $result['output']);
        self::assertStringContainsString('summary_cleanup=ok', $result['output']);
        $config = (string) file_get_contents($root.'/etc/clamd.d/scan.conf');
        self::assertStringContainsString('#LocalSocket /run/clamd.scan/clamd.sock', $config);
        self::assertStringNotContainsString('LocalSocketMode 660', $config);
        $tempEntries = glob($root.'/tmp/testlig-clamav-accept.*');
        self::assertIsArray($tempEntries);
        self::assertSame([], $tempEntries);
        $systemctl = (string) file_get_contents($root.'/systemctl.log');
        self::assertStringContainsString('disable --now clamd@scan.service', $systemctl);
        self::assertStringContainsString('disable --now clamav-freshclam.service', $systemctl);
        $bools = (string) file_get_contents($root.'/setsebool.log');
        self::assertStringContainsString('antivirus_can_scan_system 0', $bools);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runScript(string $root, string $bin, string $expectedSha): array
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
        unset($env['ACCEPT_PHP_B64'], $env['TESTLIG_PHP_BIN']);
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

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-clamav-install-'.bin2hex(random_bytes(4));
        mkdir($root, 0700, true);

        return $root;
    }

    private function fakeBin(string $root): string
    {
        $bin = $root.'/bin';
        mkdir($bin, 0700, true);
        $this->write($bin.'/dnf', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"\$TESTLIG_CLAMAV_TEST_ROOT/dnf.log\"\nif printf '%s' \"$*\" | grep -q 'clamav clamd'; then\n  mkdir -p \"\$TESTLIG_CLAMAV_TEST_ROOT/etc/clamd.d\" \"\$TESTLIG_CLAMAV_TEST_ROOT/etc\"\n  printf '%s\\n' '#Example' '#LocalSocket /run/clamd.scan/clamd.sock' > \"\$TESTLIG_CLAMAV_TEST_ROOT/etc/clamd.d/scan.conf\"\n  printf '%s\\n' '#Example' > \"\$TESTLIG_CLAMAV_TEST_ROOT/etc/freshclam.conf\"\nfi\nexit 0\n");
        $this->write($bin.'/systemctl', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"\$TESTLIG_CLAMAV_TEST_ROOT/systemctl.log\"\nexit 0\n");
        $this->write($bin.'/getenforce', "#!/bin/sh\nprintf '%s\\n' Enforcing\n");
        $this->write($bin.'/getsebool', "#!/bin/sh\nprintf '%s\\n' 'antivirus_can_scan_system --> off'\n");
        $this->write($bin.'/setsebool', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"\$TESTLIG_CLAMAV_TEST_ROOT/setsebool.log\"\nexit 0\n");
        $this->write($bin.'/freshclam', "#!/bin/sh\nexit 1\n");
        foreach (['dnf', 'systemctl', 'getenforce', 'getsebool', 'setsebool', 'freshclam'] as $name) {
            chmod($bin.'/'.$name, 0700);
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

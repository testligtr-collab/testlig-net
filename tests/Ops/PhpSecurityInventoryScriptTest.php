<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class PhpSecurityInventoryScriptTest extends TestCase
{
    public function testFullFixtureReportsCliAndUnknownWeb(): void
    {
        $root = $this->tempRoot();
        try {
            $this->write($root.'/bin/rpm', <<<'BASH'
#!/bin/bash
if [ "${1-}" = "-qc" ]; then
  if [ "${2-}" = "lsphp83" ]; then
    printf '%s\n' '/usr/local/lsws/lsphp83/etc/php.ini' '/etc/secret.env'
  fi
  if [ "${2-}" = "lsphp82" ]; then
    printf '%s\n' '/usr/local/lsws/lsphp82/etc/php.ini'
  fi
  exit 0
fi
printf '%s\n' 'lsphp83|8.3.33|1.el9|x86_64' 'lsphp82|8.2.34|1.el9|x86_64'
exit 0
BASH);
            $this->write($root.'/bin/dnf', <<<'BASH'
#!/bin/bash
printf '%s\n' 'ALSA-2026:9999 Important/Sec. lsphp83-8.3.36-1.el9.x86_64'
exit 0
BASH);
            $this->write($root.'/usr/local/lsws/lsphp83/bin/php', $this->phpStub());
            $this->write($root.'/usr/local/lsws/lsphp82/bin/php', $this->phpStub());
            $this->write($root.'/usr/local/lsws/lsphp83/bin/lsphp', "#!/bin/bash\nexit 0\n");
            $this->write($root.'/usr/local/lsws/lsphp82/bin/lsphp', "#!/bin/bash\nexit 0\n");
            $this->write($root.'/usr/local/lsws/lsphp83/etc/php.ini', "session.upload_progress.enabled = 1\nmemory_limit = 256M\nSECRET_DB_PASSWORD=do-not-print\n");
            $this->write($root.'/usr/local/lsws/lsphp82/etc/php.ini', "session.upload_progress.enabled = 0\n");
            $this->write($root.'/usr/local/lsws/conf/httpd_config.conf', "virtualhost testlig.net {\n  DB_PASSWORD=super-secret-value\n}\n");
            $this->write($root.'/usr/local/lsws/conf/vhosts/testlig.net/vhost.conf', <<<'CONF'
extprocessor lsphp {
  type lsapi
  path /usr/local/lsws/lsphp83/bin/lsphp
  env DB_PASSWORD super-secret-value
}
phpIniOverride {
  php_admin_value session.upload_progress.enabled 0
}
docRoot $VH_ROOT/public_html
rewrite {
  RewriteRule ^ SECRET_TOKEN=abc
}
CONF);
            $this->write($root.'/usr/local/lsws/conf/vhosts/example.net/vhost.conf', "path /usr/local/lsws/lsphp82/bin/lsphp\n");
            $this->write($root.'/etc/yum.repos.d/litespeed.repo', "[litespeed]\nenabled=1\nbaseurl=https://user:token@rpms.example/lsphp\n");
            $this->write($root.'/etc/secret.env', "SECRET_TOKEN=abc\n");
            $this->write($root.'/home/testlig.net/app/.env', "APP_SECRET=super-secret-value\n");
            $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', "67324b3d18fa541b164601e75e87d5e8ae57cfb6\n");
            $before = $this->snapshot($root);
            $output = $this->runInventory($root);
            self::assertSame($before, $this->snapshot($root));
            $this->assertContract($output);
            self::assertStringContainsString('php_inventory=read-only', $output);
            self::assertStringContainsString('php_inventory_changes=no', $output);
            self::assertStringContainsString('php_inventory_complete=yes', $output);
            self::assertStringContainsString('web_runtime_measured=no', $output);
            self::assertStringContainsString('web_session_upload_progress_enabled=unknown', $output);
            self::assertStringContainsString('php_cli_is_web_runtime=no', $output);
            self::assertStringContainsString('php_cli_loaded_ini_is_web_acceptance=no', $output);
            self::assertStringContainsString('configured_handler_is_internet_exposure=no', $output);
            self::assertStringContainsString('lsphp_package name=lsphp83 version=8.3.33 release=1.el9 arch=x86_64', $output);
            self::assertStringContainsString('lsphp_package name=lsphp82 version=8.2.34 release=1.el9 arch=x86_64', $output);
            self::assertStringContainsString('lsphp_config package=lsphp83 path=/usr/local/lsws/lsphp83/etc/php.ini source=package', $output);
            self::assertStringContainsString('lsphp_config package=lsphp82 path=/usr/local/lsws/lsphp82/etc/php.ini source=package', $output);
            self::assertStringContainsString('php_ini_directive path=/usr/local/lsws/lsphp83/etc/php.ini key=session.upload_progress.enabled value=1 web_acceptance=no', $output);
            self::assertStringContainsString('php_ini_directive path=/usr/local/lsws/lsphp82/etc/php.ini key=session.upload_progress.enabled value=0 web_acceptance=no', $output);
            self::assertStringContainsString('testlig_php_handler binary=/usr/local/lsws/lsphp83/bin/lsphp present=yes source=vhost_config exposure=not_measured', $output);
            self::assertStringContainsString('configured_php_handler binary=/usr/local/lsws/lsphp82/bin/lsphp present=yes exposure=not_measured', $output);
            self::assertStringContainsString('php_cli binary=/usr/local/lsws/lsphp83/bin/php version=8.3.33 upload_progress_enabled=1 loaded_ini=/usr/local/lsws/lsphp83/etc/php.ini label=cli web_acceptance=no', $output);
            self::assertStringContainsString('php_cli binary=/usr/local/lsws/lsphp82/bin/php version=8.2.34 upload_progress_enabled=1 loaded_ini=/usr/local/lsws/lsphp82/etc/php.ini label=cli web_acceptance=no', $output);
            self::assertStringContainsString('testlig_configured_override key=session.upload_progress.enabled value=0 source=vhconf web_acceptance=no', $output);
            self::assertStringContainsString('testlig_docroot_user_ini=unresolved', $output);
            self::assertStringContainsString('package_repo id=litespeed enabled=1', $output);
            self::assertStringContainsString('lsphp83_security_update=available', $output);
            self::assertStringContainsString('lsphp83_security_update_advisory=ALSA-2026:9999', $output);
            self::assertStringContainsString('lsphp83_security_update_package=lsphp83-8.3.36-1.el9.x86_64', $output);
            self::assertStringContainsString('release_sha=67324b3d18fa541b164601e75e87d5e8ae57cfb6', $output);
            self::assertStringContainsString('release_name=absent', $output);
            self::assertStringNotContainsString('super-secret-value', $output);
            self::assertStringNotContainsString('SECRET', $output);
            self::assertStringNotContainsString('do-not-print', $output);
            self::assertStringNotContainsString('token@', $output);
            self::assertStringNotContainsString('user:token', $output);
            self::assertStringNotContainsString('memory_limit', $output);
            self::assertStringNotContainsString('/etc/secret.env', $output);
            self::assertStringNotContainsString('baseurl', $output);
            self::assertStringNotContainsString('@', $output);
            self::assertStringNotContainsString('://', $output);
            self::assertStringNotContainsString('web_acceptance=yes', $output);
            self::assertStringNotContainsString('web_runtime_measured=yes', $output);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testMissingToolsStayUnknown(): void
    {
        $root = $this->tempRoot();
        try {
            $this->write($root.'/home/testlig.net/app/.env', "APP_SECRET=super-secret-value\n");
            $before = $this->snapshot($root);
            $output = $this->runInventory($root);
            self::assertSame($before, $this->snapshot($root));
            $this->assertContract($output);
            self::assertStringContainsString('web_session_upload_progress_enabled=unknown', $output);
            self::assertStringContainsString('lsphp_package_query=unknown', $output);
            self::assertStringContainsString('php_cli=absent', $output);
            self::assertStringContainsString('testlig_php_handler=absent', $output);
            self::assertStringContainsString('configured_php_handler=none', $output);
            self::assertStringContainsString('lsphp83_security_update=unknown', $output);
            self::assertStringContainsString('lsphp83_security_update_reason=repo_query_failed', $output);
            self::assertStringContainsString('php_inventory_complete=yes', $output);
            self::assertStringNotContainsString('super-secret-value', $output);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testEmptyCacheIsNotAnAvailableUpdate(): void
    {
        $root = $this->tempRoot();
        try {
            $this->write($root.'/bin/dnf', "#!/bin/bash\nexit 0\n");
            $output = $this->runInventory($root);
            $this->assertContract($output);
            self::assertStringContainsString('lsphp83_security_update=none_in_cache', $output);
            self::assertStringNotContainsString('lsphp83_security_update=available', $output);
            self::assertStringNotContainsString('lsphp83_security_update=unknown', $output);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testUnparsedRepoOutputIsNotPrinted(): void
    {
        $root = $this->tempRoot();
        try {
            $this->write($root.'/bin/dnf', "#!/bin/bash\nprintf '%s\n' 'https://user:token@rpms.example/lsphp83'\nexit 0\n");
            $output = $this->runInventory($root);
            $this->assertContract($output);
            self::assertStringContainsString('lsphp83_security_update=unknown', $output);
            self::assertStringContainsString('lsphp83_security_update_reason=unparsed_repo_output', $output);
            self::assertStringNotContainsString('token', $output);
            self::assertStringNotContainsString('https://', $output);
            self::assertStringNotContainsString('@', $output);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testScriptDoesNotMutateOrMeasureWeb(): void
    {
        $script = $this->read('deploy/vds/gha-php-security-inventory-remote.sh');
        self::assertStringContainsString('web_runtime_measured=no', $script);
        self::assertStringContainsString('web_session_upload_progress_enabled=unknown', $script);
        self::assertStringContainsString('label=cli', $script);
        self::assertStringContainsString('exposure=not_measured', $script);
        self::assertStringContainsString('--cacheonly', $script);
        self::assertStringNotContainsString('phpinfo', $script);
        self::assertStringNotContainsString('printenv', $script);
        self::assertStringNotContainsString('.env', $script);
        self::assertStringNotContainsString('systemctl', $script);
        self::assertStringNotContainsString('apt-get', $script);
        self::assertStringNotContainsString('dnf install', $script);
        self::assertStringNotContainsString('dnf update', $script);
        self::assertStringNotContainsString('dnf upgrade', $script);
        self::assertStringNotContainsString('yum install', $script);
        self::assertDoesNotMatchRegularExpression('/\buntil\b/', $script);
        self::assertDoesNotMatchRegularExpression('/>>/', $script);
        self::assertDoesNotMatchRegularExpression('/>(?!\/dev\/null\b|&1\b)/', $script);
        self::assertDoesNotMatchRegularExpression('/^\s*(apt-get|dnf|yum|pacman|usermod|groupadd|useradd|gpasswd|chmod|chown|mkdir|rm|mv|cp|tee|install|systemctl)\b/m', $script);
    }

    public function testWorkflowStaysManualAndSingleSession(): void
    {
        $workflow = $this->read('.github/workflows/ops-php-security-inventory.yml');
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
        self::assertStringNotContainsString('workflow_run:', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $workflow);
        self::assertStringContainsString('VDS SSH operations are paused', $workflow);
        self::assertStringContainsString('PHP security inventory skipped because SSH paused', $workflow);
        self::assertStringContainsString('group: testlig-vds-ssh', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString('queue: max', $workflow);
        self::assertStringContainsString('ssh testlig-vds bash -s < deploy/vds/gha-php-security-inventory-remote.sh', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertStringNotContainsString('TESTLIG_PHP_INVENTORY_ROOT', $workflow);
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $workflow));
        self::assertSame(0, preg_match_all('/^\s*scp\s+/m', $workflow));
        self::assertLessThan(
            strpos($workflow, 'ssh testlig-vds') ?: \PHP_INT_MAX,
            strpos($workflow, 'gha-ssh-config.sh') ?: 0,
        );
    }

    private function assertContract(string $output): void
    {
        $allowed = [
            'php_inventory=',
            'php_inventory_changes=',
            'php_inventory_complete=',
            'web_runtime_measured=',
            'web_session_upload_progress_enabled=',
            'php_cli_is_web_runtime=',
            'php_cli_loaded_ini_is_web_acceptance=',
            'configured_handler_is_internet_exposure=',
            'ols_config=',
            'lsphp_package ',
            'lsphp_package=',
            'lsphp_package_query=',
            'lsphp_config ',
            'testlig_php_handler ',
            'testlig_php_handler=',
            'configured_php_handler ',
            'configured_php_handler=',
            'php_cli ',
            'php_cli=',
            'php_ini_directive ',
            'testlig_configured_override ',
            'testlig_configured_override=',
            'testlig_docroot_user_ini=',
            'testlig_user_ini ',
            'testlig_user_ini=',
            'testlig_php_ini_override_path=',
            'package_repo ',
            'package_repo=',
            'lsphp83_security_update=',
            'lsphp83_security_update_reason=',
            'lsphp83_security_update_advisory=',
            'lsphp83_security_update_package=',
            'release_sha=',
            'release_name=',
        ];
        $lines = preg_split("/\R/", trim($output));
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            $matched = false;
            foreach ($allowed as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            self::assertTrue($matched, $line);
        }
    }

    private function runInventory(string $root): string
    {
        $bash = $this->bashBinary();
        $script = str_replace(
            'root="${TESTLIG_PHP_INVENTORY_ROOT:-}"',
            "root='".str_replace('\\', '/', $root)."'",
            $this->read('deploy/vds/gha-php-security-inventory-remote.sh'),
        );
        $pipes = [];
        $process = proc_open([$bash], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

    private function phpStub(): string
    {
        return <<<'BASH'
#!/bin/bash
version="PHP 8.3.33 (cli) (built: Jul 28 2026 17:56:10) (NTS)"
ini="/usr/local/lsws/lsphp83/etc/php.ini"
case "$0" in
  *lsphp82*)
    version="PHP 8.2.34 (cli) (built: Sep 24 2026 12:00:00) (NTS)"
    ini="/usr/local/lsws/lsphp82/etc/php.ini"
    ;;
esac
if [ "${1-}" = "-v" ]; then
  printf '%s\n' "$version"
  exit 0
fi
if [ "${1-}" = "--ini" ]; then
  printf '%s\n' "Loaded Configuration File: ${ini}"
  exit 0
fi
if [ "${1-}" = "-r" ]; then
  printf '%s' "1"
  exit 0
fi
exit 0
BASH;
    }

    private function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, str_replace("\r\n", "\n", $contents));
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir().'/php-inventory-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        return $root;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $hash = hash_file('sha256', $file->getPathname());
                if (!\is_string($hash)) {
                    throw new \RuntimeException('snapshot hash failed');
                }
                $files[$file->getPathname()] = $hash;
            }
        }
        ksort($files);

        return $files;
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }

    private function read(string $relative): string
    {
        $text = file_get_contents(\dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($text);

        return $text;
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

        self::fail('bash is required to run the inventory fixture');
    }
}

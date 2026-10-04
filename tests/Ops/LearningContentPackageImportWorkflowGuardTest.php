<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LearningContentPackageImportWorkflowGuardTest extends TestCase
{
    public function testWorkflowHasNoEmailDispatchInput(): void
    {
        $parsed = Yaml::parseFile($this->path());
        self::assertIsArray($parsed);
        $inputs = $parsed['on']['workflow_dispatch']['inputs'] ?? null;
        self::assertIsArray($inputs);
        self::assertArrayHasKey('mode', $inputs);
        self::assertArrayHasKey('package', $inputs);
        self::assertArrayHasKey('expected_plan_fingerprint', $inputs);
        self::assertArrayNotHasKey('actor_env', $inputs);
        self::assertArrayNotHasKey('actor_email', $inputs);
        self::assertArrayNotHasKey('email', $inputs);
        foreach (array_keys($inputs) as $name) {
            self::assertIsString($name);
            self::assertDoesNotMatchRegularExpression('/email/i', $name);
        }
    }

    public function testMissingActorSecretFailsBeforeSshJob(): void
    {
        $parsed = Yaml::parseFile($this->path());
        self::assertIsArray($parsed);
        $jobs = $parsed['jobs'];
        self::assertIsArray($jobs);
        $guard = $jobs['guard'] ?? null;
        $vds = $jobs['vds'] ?? null;
        self::assertIsArray($guard);
        self::assertIsArray($vds);

        $guardStep = $this->encode($guard);
        self::assertStringContainsString('secrets.TESTLIG_CONTENT_ACTOR_EMAIL', $guardStep);
        self::assertStringContainsString('actor secret is not configured', $guardStep);
        self::assertStringContainsString('*$\'\\r\'*|*$\'\\n\'*', $this->read());
        self::assertStringNotContainsString('webfactory/ssh-agent', $guardStep);
        self::assertStringNotContainsString('gha-ssh-config.sh', $guardStep);
        self::assertStringNotContainsString('ssh testlig-vds', $guardStep);

        $vdsNeeds = $vds['needs'] ?? null;
        self::assertIsArray($vdsNeeds);
        self::assertContains('guard', $vdsNeeds);
        $vdsIf = $vds['if'] ?? '';
        self::assertIsString($vdsIf);
        self::assertStringContainsString("needs.guard.result == 'success'", $vdsIf);
    }

    public function testActorSecretIsMaskedAndNotOnSshArgv(): void
    {
        $text = $this->read();
        $entry = $this->readRelative('/deploy/vds/gha-learning-content-package-import-entry.sh');
        $remote = $this->readRelative('/deploy/vds/gha-learning-content-package-import-remote.sh');
        self::assertStringContainsString('::add-mask::', $text);
        self::assertStringContainsString('TESTLIG_CONTENT_ACTOR_EMAIL', $text);
        self::assertStringContainsString('--actor-email-env="$ACTOR_ENV_NAME"', $remote);
        self::assertStringContainsString('ACTOR_ENV_NAME=TESTLIG_CONTENT_ACTOR_EMAIL', $remote);
        self::assertDoesNotMatchRegularExpression(
            '/ssh\s+testlig-vds[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL=\$\(printf/',
            $text,
        );
        self::assertDoesNotMatchRegularExpression(
            '/ssh\s+testlig-vds[^\n]*secrets\.TESTLIG_CONTENT_ACTOR_EMAIL/',
            $text,
        );
        self::assertStringContainsString('gha-learning-content-package-import-entry.sh', $text);
        self::assertStringContainsString('gha-learning-content-package-import-remote.sh', $text);
        self::assertStringContainsString('ENTRY="$(cat deploy/vds/gha-learning-content-package-import-entry.sh)"', $text);
        self::assertStringContainsString('$ENTRY', $text);
        self::assertStringContainsString('printf \'%s\\n\' "$TESTLIG_CONTENT_ACTOR_EMAIL"', $text);
        self::assertStringContainsString('IFS= read -r TESTLIG_CONTENT_ACTOR_EMAIL', $entry);
        self::assertLessThan(
            strpos($entry, 'exec bash --norc --noprofile -se') ?: \PHP_INT_MAX,
            strpos($entry, 'IFS= read -r TESTLIG_CONTENT_ACTOR_EMAIL') ?: 0,
        );
        self::assertLessThan(
            strpos($entry, 'exec bash --norc --noprofile -se') ?: \PHP_INT_MAX,
            strpos($entry, 'export TESTLIG_CONTENT_ACTOR_EMAIL') ?: 0,
        );
        self::assertStringNotContainsString('printf \'%s\\n\' "$MODE"', $text);
        self::assertStringNotContainsString('printf \'%s\\n\' "$PACKAGE"', $text);
        self::assertStringNotContainsString('printf \'%s\\n\' "$PLAN"', $text);
        self::assertStringContainsString('export MODE=$(printf %q "$MODE")', $text);
        self::assertStringContainsString('export PACKAGE=$(printf %q "$PACKAGE")', $text);
        self::assertStringNotContainsString('set -x', $text.$entry.$remote);
        self::assertDoesNotMatchRegularExpression('/^\s*eval\s/', $entry);
        self::assertDoesNotMatchRegularExpression('/^\s*eval\s/', $remote);
    }

    public function testWorkflowDoesNotPersistActorOnVds(): void
    {
        $text = $this->read();
        $entry = $this->readRelative('/deploy/vds/gha-learning-content-package-import-entry.sh');
        $remote = $this->readRelative('/deploy/vds/gha-learning-content-package-import-remote.sh');
        $combined = $text."\n".$entry."\n".$remote;
        self::assertStringNotContainsString('.env.local', $combined);
        self::assertStringNotContainsString('>> /home/testlig.net', $combined);
        self::assertDoesNotMatchRegularExpression('/\becho\b[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL=/', $combined);
        self::assertDoesNotMatchRegularExpression('/\btee\b/', $combined);
        self::assertStringNotContainsString('/etc/environment', $combined);
        self::assertStringNotContainsString('.bashrc', $combined);
        self::assertStringNotContainsString('systemctl', $combined);
        self::assertStringNotContainsString('shared/.env', $combined);
        self::assertStringContainsString('--preserve-environment', $remote);
        self::assertDoesNotMatchRegularExpression(
            '/runuser[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL="\$TESTLIG_CONTENT_ACTOR_EMAIL"/',
            $remote,
        );
    }

    public function testApplyStillRequiresFingerprintAndUsesOneSsh(): void
    {
        $text = $this->read();
        $remote = $this->readRelative('/deploy/vds/gha-learning-content-package-import-remote.sh');
        self::assertStringContainsString('refuse-apply-without-plan', $text);
        self::assertStringContainsString('apply requires the dry-run plan fingerprint', $text);
        self::assertStringContainsString('--expected-plan-fingerprint="$PLAN"', $remote);
        self::assertStringContainsString('verify|dry-run|apply', $remote);
        self::assertStringContainsString('data/content/tymm-2026/grade-1/matematik/mat-1-3-2', $remote);
        self::assertStringContainsString('data/content/tymm-2026/grade-1/matematik/mat-1-3-3', $remote);
        self::assertStringContainsString('--mode="$MODE"', $remote);
        self::assertStringContainsString('app:learning-content:import-package', $remote);
        self::assertSame(1, $this->commandCount($text, 'ssh'));
        self::assertSame(0, $this->commandCount($text, 'scp'));
        self::assertDoesNotMatchRegularExpression('/\buntil\b/', $text);
        self::assertDoesNotMatchRegularExpression('/\buntil\b/', $remote);
        self::assertStringContainsString('testlig-vds-ssh', $text);
        self::assertStringContainsString('cancel-in-progress: false', $text);
        self::assertStringContainsString('queue: max', $text);
        self::assertStringContainsString('set -Eeuo pipefail', $this->readRelative('/deploy/vds/gha-learning-content-package-import-entry.sh'));
        self::assertStringContainsString('set -Eeuo pipefail', $remote);
        $importAt = strpos($remote, 'app:learning-content:import-package');
        self::assertNotFalse($importAt);
        self::assertLessThan($importAt, strpos($remote, 'ERROR: unknown mode') ?: \PHP_INT_MAX);
        self::assertLessThan($importAt, strpos($remote, 'package is not allowlisted') ?: \PHP_INT_MAX);
        self::assertLessThan($importAt, strpos($remote, 'apply requires the dry-run plan fingerprint') ?: \PHP_INT_MAX);
    }

    private function path(): string
    {
        return $this->root().'/.github/workflows/ops-learning-content-package-import.yml';
    }

    private function read(): string
    {
        $text = file_get_contents($this->path());
        self::assertIsString($text);

        return $text;
    }

    private function readRelative(string $relative): string
    {
        $text = file_get_contents($this->root().$relative);
        self::assertIsString($text);

        return $text;
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

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

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
        self::assertStringContainsString('::add-mask::', $text);
        self::assertStringContainsString('TESTLIG_CONTENT_ACTOR_EMAIL', $text);
        self::assertStringContainsString('--actor-email-env="$ACTOR_ENV_NAME"', $text);
        self::assertStringContainsString('ACTOR_ENV_NAME=TESTLIG_CONTENT_ACTOR_EMAIL', $text);
        self::assertDoesNotMatchRegularExpression(
            '/ssh\s+testlig-vds[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL=\$\(printf/',
            $text,
        );
        self::assertDoesNotMatchRegularExpression(
            '/ssh\s+testlig-vds[^\n]*secrets\.TESTLIG_CONTENT_ACTOR_EMAIL/',
            $text,
        );
        self::assertStringContainsString('ssh testlig-vds "bash -s" < <(', $text);
        self::assertStringContainsString('printf \'%s\\n\' "$TESTLIG_CONTENT_ACTOR_EMAIL"', $text);
        self::assertStringContainsString('IFS= read -r TESTLIG_CONTENT_ACTOR_EMAIL', $text);
    }

    public function testWorkflowDoesNotPersistActorOnVds(): void
    {
        $text = $this->read();
        self::assertStringNotContainsString('.env.local', $text);
        self::assertStringNotContainsString('>> /home/testlig.net', $text);
        self::assertDoesNotMatchRegularExpression('/\becho\b[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL=/', $text);
        self::assertDoesNotMatchRegularExpression('/\btee\b/', $text);
        self::assertStringNotContainsString('/etc/environment', $text);
        self::assertStringNotContainsString('.bashrc', $text);
        self::assertStringNotContainsString('systemctl', $text);
        self::assertStringNotContainsString('shared/.env', $text);
    }

    public function testApplyStillRequiresFingerprintAndUsesOneSsh(): void
    {
        $text = $this->read();
        self::assertStringContainsString('refuse-apply-without-plan', $text);
        self::assertStringContainsString('apply requires the dry-run plan fingerprint', $text);
        self::assertStringContainsString('--expected-plan-fingerprint="$PLAN"', $text);
        self::assertSame(1, $this->commandCount($text, 'ssh'));
        self::assertSame(0, $this->commandCount($text, 'scp'));
        self::assertDoesNotMatchRegularExpression('/\buntil\b/', $text);
        self::assertStringContainsString('testlig-vds-ssh', $text);
        self::assertStringContainsString('cancel-in-progress: false', $text);
        self::assertStringContainsString('queue: max', $text);
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

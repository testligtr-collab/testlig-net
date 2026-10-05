<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class QuestionPackageImportWorkflowGuardTest extends TestCase
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
        self::assertSame(['data/content/tymm-2026/grade-1/matematik/mat-1-3-3'], $inputs['package']['options']);
        foreach (array_keys($inputs) as $name) {
            self::assertIsString($name);
            self::assertDoesNotMatchRegularExpression('/email/i', $name);
        }
    }

    public function testPausedSshSkipsAgentAndSecretJob(): void
    {
        $parsed = Yaml::parseFile($this->path());
        self::assertIsArray($parsed);
        $jobs = $parsed['jobs'];
        self::assertIsArray($jobs);
        self::assertSame("vars.VDS_SSH_PAUSED == 'true'", $jobs['pause-notice']['if'] ?? null);
        self::assertSame("vars.VDS_SSH_PAUSED != 'true'", $jobs['guard']['if'] ?? null);
        $vdsIf = $jobs['vds']['if'] ?? '';
        self::assertIsString($vdsIf);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $vdsIf);
        self::assertStringContainsString("needs.guard.result == 'success'", $vdsIf);
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
        self::assertStringNotContainsString('ssh testlig-vds', $guardStep);
        self::assertContains('guard', $vds['needs'] ?? []);
    }

    public function testActorSecretIsMaskedAndNotOnSshArgv(): void
    {
        $text = $this->read();
        $entry = $this->readRelative('/deploy/vds/gha-question-package-import-entry.sh');
        $remote = $this->readRelative('/deploy/vds/gha-question-package-import-remote.sh');
        self::assertStringContainsString('::add-mask::', $text);
        self::assertStringContainsString('TESTLIG_CONTENT_ACTOR_EMAIL', $text);
        self::assertStringContainsString('--actor-email-env="$ACTOR_ENV_NAME"', $remote);
        self::assertDoesNotMatchRegularExpression(
            '/ssh\s+testlig-vds[^\n]*TESTLIG_CONTENT_ACTOR_EMAIL=\$\(printf/',
            $text,
        );
        self::assertStringContainsString('gha-question-package-import-entry.sh', $text);
        self::assertStringContainsString('printf \'%s\\n\' "$TESTLIG_CONTENT_ACTOR_EMAIL"', $text);
        self::assertStringContainsString('IFS= read -r TESTLIG_CONTENT_ACTOR_EMAIL', $entry);
        self::assertStringNotContainsString('set -x', $text.$entry.$remote);
    }

    public function testApplyRequiresFingerprintAndUsesOneSsh(): void
    {
        $text = $this->read();
        $remote = $this->readRelative('/deploy/vds/gha-question-package-import-remote.sh');
        self::assertStringContainsString('refuse-apply-without-plan', $text);
        self::assertStringContainsString('apply requires the dry-run plan fingerprint', $text);
        self::assertStringContainsString('app:question:import-package', $remote);
        self::assertStringContainsString('data/content/tymm-2026/grade-1/matematik/mat-1-3-3', $remote);
        self::assertStringNotContainsString('mat-1-3-2', $remote);
        self::assertSame(1, $this->commandCount($text, 'ssh'));
        self::assertSame(0, $this->commandCount($text, 'scp'));
        self::assertDoesNotMatchRegularExpression('/\buntil\b/', $text);
        self::assertStringContainsString('testlig-vds-ssh', $text);
        self::assertStringContainsString('cancel-in-progress: false', $text);
        self::assertStringContainsString('queue: max', $text);
    }

    public function testExistingContentWorkflowStillUsesOneSsh(): void
    {
        $content = (string) file_get_contents($this->root().'/.github/workflows/ops-learning-content-package-import.yml');
        $remote = (string) file_get_contents($this->root().'/deploy/vds/gha-learning-content-package-import-remote.sh');
        self::assertSame(1, $this->commandCount($content, 'ssh'));
        self::assertStringContainsString('app:learning-content:import-package', $remote);
    }

    private function path(): string
    {
        return $this->root().'/.github/workflows/ops-question-package-import.yml';
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

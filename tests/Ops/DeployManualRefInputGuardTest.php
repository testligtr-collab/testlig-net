<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The manual Deploy VDS "ref" input and the other event fields must reach Bash as quoted
 * environment variables. A GitHub expression written into run: is pasted into the script text
 * before Bash starts, so a crafted input would run as shell code on the runner that holds the
 * VDS ssh-agent.
 */
final class DeployManualRefInputGuardTest extends TestCase
{
    private const RESOLVE_STEP = 'Resolve deploy SHA';

    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
        parent::tearDown();
    }

    public function testNoRunBlockInTheDeployWorkflowContainsAGithubExpression(): void
    {
        $jobs = $this->workflow()['jobs'];
        self::assertIsArray($jobs);
        $checked = 0;
        foreach ($jobs as $jobName => $job) {
            self::assertIsArray($job);
            $steps = $job['steps'] ?? [];
            self::assertIsArray($steps);
            foreach ($steps as $step) {
                self::assertIsArray($step);
                if (!isset($step['run'])) {
                    continue;
                }
                ++$checked;
                $label = $jobName.' / '.($step['name'] ?? '(unnamed)');
                self::assertIsString($step['run'], $label);
                self::assertStringNotContainsString('${{', $step['run'], $label.' must read expressions from env:');
                self::assertDoesNotMatchRegularExpression('/\b(?:github\.event|inputs)\./', $step['run'], $label);
            }
        }
        self::assertGreaterThan(3, $checked);
    }

    public function testResolveStepReceivesEveryExpressionThroughEnv(): void
    {
        $step = $this->resolveStep();

        self::assertSame('meta', $step['id'] ?? null);
        self::assertSame(
            [
                'EVENT_NAME' => '${{ github.event_name }}',
                'WORKFLOW_RUN_HEAD_SHA' => '${{ github.event.workflow_run.head_sha }}',
                'INPUT_REF' => '${{ github.event.inputs.ref }}',
                'WORKFLOW_SHA' => '${{ github.sha }}',
            ],
            $step['env'] ?? null,
        );

        $run = $step['run'] ?? null;
        self::assertIsString($run);
        foreach (['EVENT_NAME', 'WORKFLOW_RUN_HEAD_SHA', 'INPUT_REF', 'WORKFLOW_SHA'] as $variable) {
            self::assertStringContainsString('${'.$variable.'}', $run);
            // Every read must stay inside double quotes.
            self::assertSame(
                substr_count($run, '${'.$variable.'}'),
                preg_match_all('/"[^"\n]*\$\{'.$variable.'\}[^"\n]*"/', $run),
                $variable,
            );
        }
        self::assertStringContainsString('set -euo pipefail', $run);
        self::assertStringNotContainsString('eval', $run);
    }

    public function testCheckoutStillReceivesTheResolvedRefAsActionInput(): void
    {
        $refs = [];
        foreach ($this->deploySteps() as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@') && 'release-src' === ($step['with']['path'] ?? null)) {
                $refs[] = $step['with']['ref'] ?? null;
            }
        }
        self::assertSame(['${{ steps.meta.outputs.ref }}'], $refs);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function acceptedInputs(): iterable
    {
        $runSha = str_repeat('a', 40);
        $headSha = str_repeat('b', 40);

        // event, workflow_run head sha, input ref, expected ref, expected sha_hint, file that must not appear
        yield 'automatic workflow_run uses the CI head sha' => ['workflow_run', $runSha, '', $runSha, $runSha, ''];
        yield 'automatic workflow_run ignores any input' => ['workflow_run', $runSha, 'main', $runSha, $runSha, ''];
        yield 'manual with empty ref deploys this workflow revision' => ['workflow_dispatch', '', '', $headSha, $headSha, ''];
        yield 'manual branch' => ['workflow_dispatch', '', 'main', 'main', '', ''];
        yield 'manual nested branch' => ['workflow_dispatch', '', 'feature/x01-homework_2.1', 'feature/x01-homework_2.1', '', ''];
        yield 'manual tag' => ['workflow_dispatch', '', 'v1.2.3', 'v1.2.3', '', ''];
        yield 'manual full ref' => ['workflow_dispatch', '', 'refs/tags/v1.2.3', 'refs/tags/v1.2.3', '', ''];
        yield 'manual sha' => ['workflow_dispatch', '', $runSha, $runSha, '', ''];
        yield 'manual short sha' => ['workflow_dispatch', '', 'cd854ab', 'cd854ab', '', ''];
        // Valid git ref characters that are special to a shell must stay data.
        yield 'quote and semicolon' => ['workflow_dispatch', '', 'x"; touch INJECTED; echo "', 'x"; touch INJECTED; echo "', '', 'INJECTED'];
        yield 'command substitution' => ['workflow_dispatch', '', 'x$(touch INJECTED)', 'x$(touch INJECTED)', '', 'INJECTED'];
        yield 'backticks' => ['workflow_dispatch', '', 'x`touch INJECTED`', 'x`touch INJECTED`', '', 'INJECTED'];
        yield 'brace and dollar' => ['workflow_dispatch', '', '${HOME}/$HOME', '${HOME}/$HOME', '', ''];
        yield 'single quote' => ['workflow_dispatch', '', "x'; touch INJECTED; '", "x'; touch INJECTED; '", '', 'INJECTED'];
        yield 'dash prefix' => ['workflow_dispatch', '', '-n', '-n', '', ''];
    }

    #[DataProvider('acceptedInputs')]
    public function testResolveStepKeepsRefBehaviourAndTreatsInputAsData(
        string $event,
        string $runHeadSha,
        string $inputRef,
        string $expectedRef,
        string $expectedSha,
        string $forbiddenFile,
    ): void {
        $this->requirePosixBash();

        $dir = $this->tempDir('deploy-ref-ok');
        $result = $this->runResolveStep($dir, [
            'EVENT_NAME' => $event,
            'WORKFLOW_RUN_HEAD_SHA' => $runHeadSha,
            'INPUT_REF' => $inputRef,
            'WORKFLOW_SHA' => str_repeat('b', 40),
        ]);

        self::assertSame(0, $result['exit'], $result['console']);
        self::assertSame(['ref='.$expectedRef, 'sha_hint='.$expectedSha], $result['output']);
        if ('' !== $forbiddenFile) {
            self::assertFileDoesNotExist($dir.'/'.$forbiddenFile, 'input was executed as shell code');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refsWithControlCharacters(): iterable
    {
        yield 'newline adds a forged output line' => ["main\nsha_hint=0000000000000000000000000000000000000000"];
        yield 'carriage return' => ["main\rsha_hint=evil"];
        yield 'tab' => ["ma\tin"];
        yield 'escape' => ["ma\x1bin"];
        yield 'delete' => ["ma\x7fin"];
    }

    #[DataProvider('refsWithControlCharacters')]
    public function testResolveStepStopsBeforeWritingOutputForControlCharacters(string $inputRef): void
    {
        $this->requirePosixBash();

        $dir = $this->tempDir('deploy-ref-ctl');
        $result = $this->runResolveStep($dir, [
            'EVENT_NAME' => 'workflow_dispatch',
            'WORKFLOW_RUN_HEAD_SHA' => '',
            'INPUT_REF' => $inputRef,
            'WORKFLOW_SHA' => str_repeat('b', 40),
        ]);

        self::assertNotSame(0, $result['exit'], $result['console']);
        self::assertSame([], $result['output'], 'no output line may be written');
        self::assertStringContainsString('::error::Deploy ref contains a control character', $result['console']);
        self::assertStringNotContainsString('evil', $result['console']);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int|null, console: string, output: list<string>}
     */
    private function runResolveStep(string $dir, array $env): array
    {
        $run = $this->resolveStep()['run'] ?? null;
        self::assertIsString($run);
        $script = $dir.'/step.sh';
        $outputFile = $dir.'/github_output';
        file_put_contents($script, "#!/usr/bin/env bash\n".$run."\n");
        file_put_contents($outputFile, '');

        $basePath = getenv('PATH');
        $process = new Process(
            ['bash', '-e', $script],
            $dir,
            $env + [
                'GITHUB_OUTPUT' => $outputFile,
                'PATH' => \is_string($basePath) && '' !== $basePath ? $basePath : '/usr/local/bin:/usr/bin:/bin',
            ],
        );
        $process->run();

        $raw = (string) file_get_contents($outputFile);
        $lines = '' === $raw ? [] : explode("\n", rtrim($raw, "\n"));

        return [
            'exit' => $process->getExitCode(),
            'console' => $process->getOutput().$process->getErrorOutput(),
            'output' => $lines,
        ];
    }

    private function requirePosixBash(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX bash required to execute the workflow step');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        $parsed = Yaml::parseFile($this->root().'/.github/workflows/deploy.yml');
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['jobs'] ?? null);

        return $parsed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploySteps(): array
    {
        $jobs = $this->workflow()['jobs'];
        self::assertIsArray($jobs);
        $deploy = $jobs['deploy'] ?? null;
        self::assertIsArray($deploy);
        $steps = $deploy['steps'] ?? null;
        self::assertIsArray($steps);

        $normalized = [];
        foreach ($steps as $step) {
            self::assertIsArray($step);
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveStep(): array
    {
        foreach ($this->deploySteps() as $step) {
            if (self::RESOLVE_STEP === ($step['name'] ?? null)) {
                return $step;
            }
        }
        self::fail('resolve step missing');
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $this->cleanupDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

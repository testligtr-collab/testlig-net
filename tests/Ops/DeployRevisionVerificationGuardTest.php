<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The deploy workflow packages the SHA it deploys and, after the existing HTTP smoke, proves over external
 * HTTPS that this exact SHA is served. The check is read-only and must never open SSH or change the deployment.
 */
final class DeployRevisionVerificationGuardTest extends TestCase
{
    private const BUILD = 'Build release tarball';
    private const SMOKE = 'External smoke (apex + auth pages)';
    private const VERIFY = 'External revision (served release over HTTPS)';

    public function testStepOrderPutsTheRevisionCheckAfterTheHttpSmokeAndTheActivation(): void
    {
        $names = array_map(static fn (array $step): string => (string) ($step['name'] ?? ''), $this->steps());

        $build = array_search(self::BUILD, $names, true);
        $activate = array_search('Upload bundle and activate release', $names, true);
        $smoke = array_search(self::SMOKE, $names, true);
        $verify = array_search(self::VERIFY, $names, true);

        self::assertIsInt($build);
        self::assertIsInt($activate);
        self::assertIsInt($smoke);
        self::assertIsInt($verify);
        self::assertLessThan($activate, $build);
        self::assertLessThan($smoke, $activate);
        self::assertLessThan($verify, $smoke);
        self::assertSame($verify, array_key_last($names), 'The revision check is the last step.');
    }

    public function testReleaseTarballCarriesTheResolvedFullShaBeforeItIsPacked(): void
    {
        $step = $this->step(self::BUILD);
        $run = (string) $step['run'];

        self::assertSame('${{ steps.rev.outputs.sha }}', $step['env']['DEPLOY_SHA'] ?? null);
        self::assertStringContainsString('printf \'%s\n\' "${DEPLOY_SHA}" > release-src/REVISION', $run);
        self::assertLessThan(strpos($run, 'tar -czf'), strpos($run, 'release-src/REVISION'));
        self::assertStringNotContainsString("--exclude='./REVISION'", $run);

        $record = (string) $this->step('Record SHA')['run'];
        self::assertStringContainsString('[0-9a-f]{40}', $record);
        self::assertLessThan(strpos($record, 'GITHUB_OUTPUT'), strpos($record, '[0-9a-f]{40}'));
    }

    public function testRevisionCheckUsesEnvNotExpressionsAndTheExactDeployedSha(): void
    {
        $step = $this->step(self::VERIFY);
        $run = (string) $step['run'];

        self::assertSame('${{ steps.rev.outputs.sha }}', $step['env']['DEPLOY_SHA'] ?? null);
        self::assertStringNotContainsString('${{', $run);
        self::assertStringContainsString('verify-external-revision.php "${DEPLOY_SHA}"', $run);
        self::assertStringContainsString('https://testlig.net/', $run);
        self::assertStringContainsString('Beklenen sürüm dış HTTPS\'te doğrulandı', $run);
        self::assertStringContainsString('Dağıtım etkinleştirildi, sunulan sürüm doğrulanamadı', $run);
        self::assertStringContainsString('exit 1', $run);
        self::assertArrayNotHasKey('continue-on-error', $step, 'A failed check must fail the job.');
    }

    public function testRevisionCheckNeverOpensSshOrChangesTheDeployment(): void
    {
        $run = (string) $this->step(self::VERIFY)['run'];

        self::assertDoesNotMatchRegularExpression('/\b(ssh|scp|rsync|ssh-add)\b/', $run);
        foreach (['rollback', 'gha-deploy-once', 'release-deploy', 'lswsctrl', 'systemctl', 'gh workflow', 'gh run', 'workflow_dispatch'] as $needle) {
            self::assertStringNotContainsString($needle, $run, $needle);
        }
    }

    public function testExistingSmokeStillChecksStatusCodesAndWww(): void
    {
        $run = (string) $this->step(self::SMOKE)['run'];

        self::assertStringContainsString('test "$code" = "200"', $run);
        self::assertStringContainsString('https://www.testlig.net/', $run);
        self::assertStringContainsString('/giris /kayit', $run);
    }

    public function testRemoteActivationRejectsABundleThatNamesAnotherSha(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/release-deploy.sh');

        self::assertStringContainsString('PACKED_SHA="$(tr -d \'[:space:]\' <"$RELEASE_DIR/REVISION")"', $script);
        self::assertStringContainsString('packaged REVISION does not match the deploy sha', $script);
        self::assertLessThan(strpos($script, 'atomic symlink switch'), strpos($script, 'PACKED_SHA'));
        self::assertLessThan(strpos($script, 'composer install'), strpos($script, 'PACKED_SHA'));
        self::assertStringContainsString('printf \'%s\n\' "$SHA" >"$RELEASE_DIR/REVISION"', $script);
        self::assertLessThan(strpos($script, 'cache:warmup'), strpos($script, 'printf \'%s\n\' "$SHA" >"$RELEASE_DIR/REVISION"'));
    }

    public function testRevisionFileIsNeverCommitted(): void
    {
        $ignore = (string) file_get_contents(\dirname(__DIR__, 2).'/.gitignore');

        self::assertMatchesRegularExpression('/^\/REVISION$/m', $ignore);
        self::assertFileDoesNotExist(\dirname(__DIR__, 2).'/REVISION');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $workflow = Yaml::parseFile(\dirname(__DIR__, 2).'/.github/workflows/deploy.yml');
        self::assertIsArray($workflow);
        $steps = $workflow['jobs']['deploy']['steps'] ?? null;
        self::assertIsArray($steps);

        return array_values($steps);
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $name): array
    {
        foreach ($this->steps() as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        self::fail('Missing deploy step: '.$name);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class ClamavSignatureVersionTest extends TestCase
{
    public function testLongerVersionDoesNotMatchShorterField(): void
    {
        $result = $this->parse('123', '1', '1', '1.0.9:1234:1:1:0:1:1:1');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('signature_dns=mismatch', $result['output']);
    }

    public function testSameNumberInAnotherFieldIsRejected(): void
    {
        $result = $this->parse('123', '59', '7', '1.0.9:59:123:1:0:63:1:7');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('signature_dns=mismatch', $result['output']);
    }

    public function testOfficialFieldPositionsMatch(): void
    {
        $result = $this->parse('59', '26104', '333', '0.103.1:59:26104:1615408140:0:63:49191:333');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('signature_dns=match', $result['output']);
    }

    public function testContradictoryTxtRecordsStop(): void
    {
        $result = $this->parse(
            '59',
            '26104',
            '333',
            '0.103.1:59:26104:1615408140:0:63:49191:333',
            '1.0.9:60:26104:1615408140:0:63:49191:333',
        );

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('signature_dns=mismatch', $result['output']);
    }

    public function testMalformedTxtStops(): void
    {
        $result = $this->parse('59', '26104', '333', '59:26104:333');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('signature_dns=unreadable', $result['output']);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function parse(string ...$args): array
    {
        $script = \dirname(__DIR__, 2).'/deploy/vds/gha-clamav-accept.php';
        $command = [\PHP_BINARY, $script, 'parse'];
        foreach ($args as $arg) {
            $command[] = $arg;
        }
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Entity\HomeworkSubmission;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Service\Homework\HomeworkPdfAttachmentManager;
use App\Service\Homework\HomeworkSubmissionManager;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:homework-pdf:inventory` against real attachment rows (draft, submitted, evaluated, two institutions)
 * and a temporary durable root outside the project. Proves the database join, the lifecycle split, the
 * institution split and that the command changes neither rows nor files. Upload stays closed outside the
 * fixtures: the gate is opened only to create attachments through the real manager.
 */
final class HomeworkPdfInventoryDatabaseTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    private const NOW_UTC = '2026-11-10 09:00:00';

    private string $site = '';

    protected function setUp(): void
    {
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
        ControllableHomeworkPdfDurableStore::resetReadAttempts();
        self::ensureKernelShutdown();
        $this->site = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'hwinv-db-'.bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->site.'/shared/var', 0700, true));
        $this->setEnv('HOMEWORK_PDF_UPLOAD_ENABLED', '1');
        $this->setEnv('HOMEWORK_PDF_SCAN_READY', '1');
        $this->setEnv('HOMEWORK_PDF_STORAGE_DIR', $this->site.'/shared/var/homework-pdf');
        $this->setEnv('HOMEWORK_PDF_QUARANTINE_DIR', $this->site.'/shared/var/homework-pdf-quarantine');
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
        $this->fakeScanner()->setVerdict(HomeworkPdfScanVerdict::Clean);
    }

    protected function tearDown(): void
    {
        FakeHomeworkPdfScanAdapter::forceVerdict(null);
        ControllableHomeworkPdfDurableStore::resetFailNextCommit();
        ControllableHomeworkPdfDurableStore::resetReadAttempts();
        $this->resetClock();
        self::ensureKernelShutdown();
        $this->setEnv('HOMEWORK_PDF_UPLOAD_ENABLED', '0');
        $this->setEnv('HOMEWORK_PDF_SCAN_READY', '0');
        $this->setEnv('HOMEWORK_PDF_STORAGE_DIR', '');
        $this->setEnv('HOMEWORK_PDF_QUARANTINE_DIR', '');
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
        $this->removeTree($this->site);
        parent::tearDown();
    }

    public function testInventoryOfRealAttachmentsSplitsLifecycleAndInstitutionAndChangesNothing(): void
    {
        $this->freezeClock(self::NOW_UTC);

        // Institution A: one draft, one submitted, one evaluated attachment.
        $ctxA = $this->homeworkContext('hwinv-a');
        $studentsA = $this->enrollStudents($ctxA, 3);
        $definitionA = $this->homeworkManager()->createDraft($ctxA['teacher'], $this->draftInput($ctxA['classroom']));
        $assignmentA = $this->assignHomework($ctxA['teacher'], $definitionA->getId(), 1);
        $sizesA = [];
        $keys = [];
        foreach ([0, 1, 2] as $i) {
            $student = $studentsA[$i]->getUser();
            $this->submissions()->saveDraft($student, $assignmentA->getId(), 'Taslak '.$i, 0);
            $pdf = $this->minimalPdf('hwinv-a-'.$i.str_repeat('x', $i * 9));
            $attachment = $this->attachments()->attach($student, $assignmentA->getId(), $pdf, 'Odev'.$i.'.pdf', 1);
            $sizesA[$i] = \strlen($pdf);
            $keys[] = $attachment->getStorageKey();
        }
        $submitted = $this->submissions()->submit($studentsA[1]->getUser(), $assignmentA->getId(), 'Teslim 1', 2, true);
        $evaluated = $this->submissions()->submit($studentsA[2]->getUser(), $assignmentA->getId(), 'Teslim 2', 2, true);
        self::assertInstanceOf(HomeworkSubmission::class, $submitted);
        $this->homeworkEvaluationManager()->record($ctxA['teacher'], $evaluated->getId(), 'reviewed', 'Iyi is.', null);

        // Institution B: one draft attachment.
        $ctxB = $this->homeworkContext('hwinv-b');
        $studentsB = $this->enrollStudents($ctxB, 1);
        $definitionB = $this->homeworkManager()->createDraft($ctxB['teacher'], $this->draftInput($ctxB['classroom']));
        $assignmentB = $this->assignHomework($ctxB['teacher'], $definitionB->getId(), 1);
        $this->submissions()->saveDraft($studentsB[0]->getUser(), $assignmentB->getId(), 'Taslak B', 0);
        $pdfB = $this->minimalPdf('hwinv-b-0');
        $attachmentB = $this->attachments()->attach($studentsB[0]->getUser(), $assignmentB->getId(), $pdfB, 'OdevB.pdf', 1);
        $keys[] = $attachmentB->getStorageKey();
        $sizeB = \strlen($pdfB);

        $institutionA = $ctxA['institution']->getId()->toRfc4122();
        $institutionB = $ctxB['institution']->getId()->toRfc4122();
        $sizeA = array_sum($sizesA);

        // Unreferenced files in the durable root: one old, one young. Real clock from here on.
        $this->resetClock();
        $durable = $this->site.'/shared/var/homework-pdf';
        $old = $durable.'/'.str_repeat('e', 32).'.bin';
        $young = $durable.'/'.str_repeat('f', 32).'.bin';
        file_put_contents($old, 'old-orphan');
        touch($old, time() - 3 * 86400);
        file_put_contents($young, 'young-orphan');
        touch($young, time() - 30);

        $rowsBefore = $this->rowState();
        $filesBefore = $this->fileState($this->site);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:homework-pdf:inventory'));
        self::assertSame(0, $tester->execute(['--by-institution' => true, '--preview-limit-bytes' => '1000000', '--orphan-min-age' => '86400']));
        $display = $tester->getDisplay(true);

        foreach ([
            'homework_pdf_inventory_status=ok',
            'writes=none',
            'store_empty=no',
            'disk_files=6',
            'matched_files=4',
            'orphan_candidate_files=1',
            'unreferenced_young_files=1',
            'db_references=4',
            'db_missing_file=0',
            'db_size_mismatch=0',
            'db_unreadable_file=0',
            'duplicate_reference=0',
            'ambiguous_owner_references=0',
            'draft_references=2',
            'submitted_references=1',
            'evaluated_references=1',
            'db_declared_bytes='.($sizeA + $sizeB),
            'db_physical_bytes='.($sizeA + $sizeB),
            'db_declared_minus_physical_bytes=0',
            'preview_limit_applied=no',
            'institution_count=2',
            'institution='.$institutionA.' references=3 declared_bytes='.$sizeA.' physical_bytes='.$sizeA.' usage_percent=',
            'institution='.$institutionB.' references=1 declared_bytes='.$sizeB.' physical_bytes='.$sizeB.' usage_percent=',
        ] as $expected) {
            self::assertStringContainsString($expected, $display);
        }
        self::assertStringNotContainsString('institution=ambiguous', $display);

        // No key, file name, path, e-mail or content reaches the output.
        foreach ($keys as $key) {
            self::assertStringNotContainsString($key, $display);
        }
        self::assertStringNotContainsString('Odev', $display);
        self::assertStringNotContainsString($this->site, $display);
        self::assertStringNotContainsString('@example.com', $display);
        self::assertStringNotContainsString('%PDF', $display);

        // Rows and files are untouched, including the old orphan candidate.
        self::assertSame($rowsBefore, $this->rowState());
        self::assertSame($filesBefore, $this->fileState($this->site));
        self::assertFileExists($old);
        self::assertFileExists($young);
    }

    public function testMissingAndReplacedFilesAreReportedWithoutBeingRepaired(): void
    {
        $this->freezeClock(self::NOW_UTC);
        $ctx = $this->homeworkContext('hwinv-m');
        $students = $this->enrollStudents($ctx, 2);
        $definition = $this->homeworkManager()->createDraft($ctx['teacher'], $this->draftInput($ctx['classroom']));
        $assignment = $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
        $stored = [];
        foreach ([0, 1] as $i) {
            $student = $students[$i]->getUser();
            $this->submissions()->saveDraft($student, $assignment->getId(), 'Taslak '.$i, 0);
            $stored[] = $this->attachments()->attach($student, $assignment->getId(), $this->minimalPdf('hwinv-m-'.$i), 'Odev.pdf', 1)->getStorageKey();
        }
        $this->resetClock();
        $durable = $this->site.'/shared/var/homework-pdf';
        self::assertTrue(unlink($durable.'/'.$stored[0].'.bin'));           // the row stays, the file is gone
        file_put_contents($durable.'/'.$stored[1].'.bin', 'short');         // same key, different size
        $rowsBefore = $this->rowState();
        $filesBefore = $this->fileState($this->site);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:homework-pdf:inventory'));
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay(true);

        self::assertStringContainsString("db_references=2\n", $display);
        self::assertStringContainsString("db_missing_file=1\n", $display);
        self::assertStringContainsString("db_size_mismatch=1\n", $display);
        self::assertStringContainsString("db_physical_bytes=5\n", $display);
        self::assertSame($rowsBefore, $this->rowState());
        self::assertSame($filesBefore, $this->fileState($this->site));
    }

    /**
     * @return array<string, string>
     */
    private function rowState(): array
    {
        $db = $this->db();

        return [
            'attachments' => (string) $db->fetchOne('SELECT COUNT(*) FROM homework_submission_attachments'),
            'attachment_digest' => (string) $db->fetchOne("SELECT MD5(GROUP_CONCAT(CONCAT(HEX(id), storage_key, byte_size, sha256, created_at) ORDER BY storage_key SEPARATOR '|')) FROM homework_submission_attachments"),
            'submissions' => (string) $db->fetchOne('SELECT COUNT(*) FROM homework_submissions'),
            'submission_digest' => (string) $db->fetchOne("SELECT MD5(GROUP_CONCAT(CONCAT(HEX(id), status, revision_number, updated_at, IFNULL(HEX(current_evaluation_id), '-')) ORDER BY id SEPARATOR '|')) FROM homework_submissions"),
            'evaluations' => (string) $db->fetchOne('SELECT COUNT(*) FROM homework_evaluations'),
            'audit' => (string) $db->fetchOne('SELECT COUNT(*) FROM security_audit_events'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fileState(string $dir): array
    {
        $state = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $info) {
            if (!$info instanceof \SplFileInfo) {
                continue;
            }
            $path = $info->getPathname();
            $rel = substr($path, \strlen($dir));
            if ($info->isLink()) {
                $state[$rel] = 'link';
            } elseif ($info->isDir()) {
                $state[$rel] = 'dir';
            } else {
                clearstatcache(true, $path);
                $state[$rel] = 'file:'.filesize($path).':'.filemtime($path).':'.md5((string) file_get_contents($path));
            }
        }
        ksort($state);

        return $state;
    }

    private function db(): Connection
    {
        $this->rebindDeliveryFixtures();

        return $this->em->getConnection();
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function attachments(): HomeworkPdfAttachmentManager
    {
        $manager = static::getContainer()->get(HomeworkPdfAttachmentManager::class);
        self::assertInstanceOf(HomeworkPdfAttachmentManager::class, $manager);

        return $manager;
    }

    private function submissions(): HomeworkSubmissionManager
    {
        $manager = static::getContainer()->get(HomeworkSubmissionManager::class);
        self::assertInstanceOf(HomeworkSubmissionManager::class, $manager);

        return $manager;
    }

    private function fakeScanner(): FakeHomeworkPdfScanAdapter
    {
        $fake = static::getContainer()->get(FakeHomeworkPdfScanAdapter::class);
        self::assertInstanceOf(FakeHomeworkPdfScanAdapter::class, $fake);

        return $fake;
    }

    private function minimalPdf(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".$marker;
    }

    private function removeTree(string $path): void
    {
        if ('' === $path) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }
}

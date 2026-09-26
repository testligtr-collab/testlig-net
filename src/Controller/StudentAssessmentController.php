<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\GradeLevel;
use App\Exception\AssessmentAttemptException;
use App\Exception\StudentPracticeException;
use App\Service\StudentAssessmentPractice;
use App\Service\StudentProfileManager;
use App\Service\StudentTestHistoryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci/testler')]
#[IsGranted('ROLE_STUDENT')]
final class StudentAssessmentController extends AbstractController
{
    private const CSRF = 'student_test';

    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly StudentAssessmentPractice $practice,
        private readonly StudentTestHistoryQuery $historyQuery,
    ) {
    }

    #[Route('', name: 'app_student_tests', methods: ['GET'])]
    public function index(): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        $user = $this->student();

        return $this->render('student/tests/index.html.twig', [
            'gradeLevel' => $grade,
            'tests' => $this->practice->listFor($user, $grade),
        ]);
    }

    #[Route('/gecmisim', name: 'app_student_test_history', methods: ['GET'])]
    public function history(): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }

        return $this->render('student/tests/history.html.twig', [
            'tests' => $this->historyQuery->listFor($this->student()),
        ]);
    }

    #[Route('/{code}', name: 'app_student_test_show', methods: ['GET'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function show(string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }

        return $this->render('student/tests/show.html.twig', [
            'test' => $this->detail($code, $grade),
        ]);
    }

    #[Route('/{code}/baslat', name: 'app_student_test_start', methods: ['POST'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function start(Request $request, string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            $this->addFlash('error', 'İstek doğrulanamadı. Sayfayı yenileyip yeniden deneyin.');

            return $this->redirectToRoute('app_student_test_show', ['code' => strtolower($code)]);
        }

        $user = $this->student();
        try {
            $this->practice->start($user, $grade, $code);
        } catch (StudentPracticeException|AssessmentAttemptException $exception) {
            return $this->failure($exception, $code, 1);
        }

        if ('done' === $this->practice->detail($user, $grade, $code)['state']) {
            return $this->redirectToRoute('app_student_test_result', ['code' => strtolower($code)]);
        }

        return $this->redirectToRoute('app_student_test_solve', ['code' => strtolower($code)]);
    }

    #[Route('/{code}/coz', name: 'app_student_test_solve', methods: ['GET'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function solve(Request $request, string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        $user = $this->student();
        try {
            $view = $this->practice->solve($user, $grade, $code, $request->query->getInt('s', 1));
        } catch (StudentPracticeException $exception) {
            if ('finished' === $exception->getReason()) {
                return $this->redirectToRoute('app_student_test_result', ['code' => strtolower($code)]);
            }

            throw new NotFoundHttpException();
        } catch (AssessmentAttemptException $exception) {
            return $this->failure($exception, $code, $request->query->getInt('s', 1));
        }

        return $this->render('student/tests/solve.html.twig', [
            'test' => $view,
            'saved' => '1' === $request->query->getString('saved'),
        ]);
    }

    #[Route('/{code}/cevap', name: 'app_student_test_answer', methods: ['POST'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function answer(Request $request, string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        $position = $request->request->getInt('position');
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            $this->addFlash('error', 'İstek doğrulanamadı. Sayfayı yenileyip yeniden deneyin.');

            return $this->redirectToRoute('app_student_test_solve', ['code' => strtolower($code), 's' => max(1, $position)]);
        }

        $user = $this->student();
        try {
            $this->practice->saveChoice(
                $user,
                $grade,
                $code,
                $position,
                $request->request->getInt('choice'),
                $request->request->getInt('expected_version'),
            );
        } catch (StudentPracticeException|AssessmentAttemptException $exception) {
            return $this->failure($exception, $code, $position);
        }

        return $this->redirectToRoute('app_student_test_solve', [
            'code' => strtolower($code),
            's' => max(1, $position),
            'saved' => 1,
        ]);
    }

    #[Route('/{code}/bitir', name: 'app_student_test_finish', methods: ['POST'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function finish(Request $request, string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            $this->addFlash('error', 'İstek doğrulanamadı. Sayfayı yenileyip yeniden deneyin.');

            return $this->redirectToRoute('app_student_test_solve', ['code' => strtolower($code)]);
        }
        if ('1' !== $request->request->getString('confirm')) {
            $this->addFlash('error', 'Testi bitirmek için onay kutusunu işaretle.');

            return $this->redirectToRoute('app_student_test_solve', ['code' => strtolower($code)]);
        }

        $user = $this->student();
        try {
            $this->practice->finish($user, $grade, $code);
        } catch (StudentPracticeException|AssessmentAttemptException $exception) {
            return $this->failure($exception, $code, 1);
        }

        return $this->redirectToRoute('app_student_test_result', ['code' => strtolower($code)]);
    }

    #[Route('/{code}/sonuc', name: 'app_student_test_result', methods: ['GET'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function result(string $code): Response
    {
        $grade = $this->grade();
        if ($grade instanceof Response) {
            return $grade;
        }
        $user = $this->student();
        try {
            $view = $this->practice->result($user, $grade, $code);
        } catch (StudentPracticeException $exception) {
            if ('in_progress' === $exception->getReason()) {
                return $this->redirectToRoute('app_student_test_solve', ['code' => strtolower($code)]);
            }
            if ('not_found' === $exception->getReason()) {
                throw new NotFoundHttpException();
            }
            $this->addFlash('error', $this->practiceMessage($exception->getReason()));

            return $this->redirectToRoute('app_student_test_show', ['code' => strtolower($code)]);
        } catch (AssessmentAttemptException $exception) {
            return $this->failure($exception, $code, 1);
        }

        return $this->render('student/tests/result.html.twig', [
            'result' => $view,
            'code' => strtolower($code),
        ]);
    }

    private function grade(): GradeLevel|Response
    {
        $profile = $this->profiles->findForUser($this->student());
        if (null === $profile || !$profile->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_student_onboarding');
        }

        return $profile->getGradeLevel();
    }

    private function student(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $code, GradeLevel $grade): array
    {
        try {
            return $this->practice->detail($this->student(), $grade, $code);
        } catch (StudentPracticeException) {
            throw new NotFoundHttpException();
        } catch (AssessmentAttemptException $exception) {
            if (AssessmentAttemptFailureReason::Unauthorized === $exception->getReason()) {
                throw $this->createAccessDeniedException();
            }
            throw new NotFoundHttpException();
        }
    }

    private function failure(\Throwable $exception, string $code, int $position): Response
    {
        $code = strtolower($code);
        if ($exception instanceof StudentPracticeException) {
            if ('not_found' === $exception->getReason()) {
                throw new NotFoundHttpException();
            }
            if ('finished' === $exception->getReason()) {
                return $this->redirectToRoute('app_student_test_result', ['code' => $code]);
            }
            $this->addFlash('error', $this->practiceMessage($exception->getReason()));
            if ('choice' === $exception->getReason()) {
                return $this->redirectToRoute('app_student_test_solve', ['code' => $code, 's' => max(1, $position)]);
            }

            return $this->redirectToRoute('app_student_test_show', ['code' => $code]);
        }
        if (!$exception instanceof AssessmentAttemptException) {
            throw new NotFoundHttpException();
        }
        if (AssessmentAttemptFailureReason::Unauthorized === $exception->getReason()) {
            throw $this->createAccessDeniedException();
        }
        if (AssessmentAttemptFailureReason::NotFound === $exception->getReason()) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('error', $this->attemptMessage($exception->getReason()));
        if (AssessmentAttemptFailureReason::StaleAnswerVersion === $exception->getReason()
            || AssessmentAttemptFailureReason::AnswerInvalid === $exception->getReason()
            || AssessmentAttemptFailureReason::ItemNotFound === $exception->getReason()
        ) {
            return $this->redirectToRoute('app_student_test_solve', ['code' => $code, 's' => max(1, $position)]);
        }

        return $this->redirectToRoute('app_student_test_show', ['code' => $code]);
    }

    private function practiceMessage(string $reason): string
    {
        return match ($reason) {
            'choice' => 'Seçenek geçersiz.',
            'penalty', 'not_single_choice', 'points', 'empty' => 'Bu test şu an çözülemiyor.',
            'score' => 'Sonuç kaydedilemedi. Sayfayı yenileyip yeniden deneyin.',
            default => 'İşlem tamamlanamadı. Sayfayı yenileyip yeniden deneyin.',
        };
    }

    private function attemptMessage(AssessmentAttemptFailureReason $reason): string
    {
        return match ($reason) {
            AssessmentAttemptFailureReason::StaleAnswerVersion => 'Bu cevap başka bir kayıtla güncellendi. Sayfayı yenileyip yeniden deneyin.',
            AssessmentAttemptFailureReason::AnswerInvalid, AssessmentAttemptFailureReason::ItemNotFound => 'Seçenek bu soruya ait değil.',
            AssessmentAttemptFailureReason::Conflict => 'İşlem çakıştı. Sayfayı yenileyip yeniden deneyin.',
            AssessmentAttemptFailureReason::AttemptExpired, AssessmentAttemptFailureReason::AttemptTerminal => 'Test süresi doldu veya zaten gönderildi.',
            default => 'İşlem tamamlanamadı. Sayfayı yenileyip yeniden deneyin.',
        };
    }
}

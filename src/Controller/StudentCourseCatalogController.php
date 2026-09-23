<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Service\StudentCatalogQuery;
use App\Service\StudentProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci/dersler')]
#[IsGranted('ROLE_STUDENT')]
final class StudentCourseCatalogController extends AbstractController
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly StudentCatalogQuery $catalog,
    ) {
    }

    #[Route('', name: 'app_student_courses', methods: ['GET'])]
    public function index(): Response
    {
        $grade = $this->resolveGradeLevel();
        if ($grade instanceof Response) {
            return $grade;
        }
        $subjects = $this->catalog->listPublishedSubjectsForGrade($grade);

        return $this->render('student/courses/index.html.twig', [
            'gradeLevel' => $grade,
            'subjects' => $subjects,
        ]);
    }

    #[Route('/{subjectSlug}', name: 'app_student_course_subject', methods: ['GET'], requirements: ['subjectSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function subject(string $subjectSlug): Response
    {
        $grade = $this->resolveGradeLevel();
        if ($grade instanceof Response) {
            return $grade;
        }
        $subject = $this->catalog->findPublishedSubjectForGrade($grade, $subjectSlug);
        $units = $this->catalog->listPublishedUnitsForGradeSubject($grade, $subjectSlug);
        if (null === $subject || null === $units) {
            throw new NotFoundHttpException();
        }

        return $this->render('student/courses/subject.html.twig', [
            'gradeLevel' => $grade,
            'subject' => $subject,
            'units' => $units,
        ]);
    }

    #[Route('/{subjectSlug}/{unitSlug}', name: 'app_student_course_unit', methods: ['GET'], requirements: [
        'subjectSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'unitSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
    ])]
    public function unit(string $subjectSlug, string $unitSlug): Response
    {
        $grade = $this->resolveGradeLevel();
        if ($grade instanceof Response) {
            return $grade;
        }
        $detail = $this->catalog->getPublishedUnitDetail($grade, $subjectSlug, $unitSlug);
        if (null === $detail) {
            throw new NotFoundHttpException();
        }

        return $this->render('student/courses/unit.html.twig', [
            'gradeLevel' => $grade,
            'subject' => $detail['subject'],
            'unit' => $detail['unit'],
            'topics' => $detail['topics'],
        ]);
    }

    private function resolveGradeLevel(): GradeLevel|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $profile = $this->profiles->findForUser($user);
        if (null === $profile || !$profile->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_student_onboarding');
        }

        return $profile->getGradeLevel();
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\LearningContentException;
use App\LearningContent\Document\LearningDocumentResponse;
use App\Service\StudentProfileManager;
use App\Service\StudentTopicContentQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci/dersler')]
#[IsGranted('ROLE_STUDENT')]
final class StudentLearningDocumentController extends AbstractController
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly StudentTopicContentQuery $topicContent,
        private readonly LearningDocumentResponse $documents,
    ) {
    }

    #[Route('/{subjectSlug}/{unitSlug}/{topicSlug}/{lessonSlug}/pdf/{index}', name: 'app_student_learning_document', methods: ['GET'], requirements: [
        'subjectSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'unitSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'topicSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'lessonSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'index' => '\d+',
    ])]
    public function open(
        string $subjectSlug,
        string $unitSlug,
        string $topicSlug,
        string $lessonSlug,
        int $index,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $profile = $this->profiles->findForUser($user);
        if (null === $profile || !$profile->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_student_onboarding');
        }

        try {
            $asset = $this->topicContent->findServableDocument(
                $profile->getGradeLevel(),
                $subjectSlug,
                $unitSlug,
                $topicSlug,
                $lessonSlug,
                $index,
                $user,
            );
            if (null === $asset) {
                throw new NotFoundHttpException();
            }

            return $this->documents->inline($asset);
        } catch (LearningContentException) {
            throw new NotFoundHttpException();
        }
    }
}

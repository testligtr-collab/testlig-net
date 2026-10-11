<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TeacherProfessionalProfileRequest;
use App\Entity\TeacherProfessionalProfile;
use App\Entity\User;
use App\Exception\TeacherProfessionalProfileException;
use App\Form\TeacherProfessionalProfileFormType;
use App\Repository\LocationRepository;
use App\Repository\SubjectRepository;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\TeacherProfessionalProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/ogretmen')]
#[IsGranted('ROLE_TEACHER')]
final class TeacherProfessionalProfileController extends AbstractController
{
    public function __construct(
        private readonly TeacherProfessionalProfileManager $profiles,
        private readonly SubjectRepository $subjects,
        private readonly LocationRepository $locations,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUsers,
    ) {
    }

    #[Route('/profil', name: 'app_teacher_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->activeVerifiedUsers->isActiveAndVerified($user)) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }

        $activeSubjects = $this->subjects->findActiveOrdered();
        $profile = $this->profiles->findForUser($user);
        if ($request->isMethod('POST') && [] === $activeSubjects) {
            $this->addFlash('error', TeacherProfessionalProfileException::catalogClosed()->getMessage());

            return $this->redirectToRoute('app_teacher_profile');
        }

        $dto = $profile instanceof TeacherProfessionalProfile
            ? TeacherProfessionalProfileRequest::fromProfile($profile, $activeSubjects)
            : new TeacherProfessionalProfileRequest();
        $form = $this->createForm(TeacherProfessionalProfileFormType::class, $dto, [
            'subjects' => $activeSubjects,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profiles->save($user, $dto);
                $this->addFlash('success', 'Mesleki profil kaydedildi.');

                return $this->redirectToRoute('app_teacher_profile');
            } catch (TeacherProfessionalProfileException $exception) {
                if ($exception->getMessage() === TeacherProfessionalProfileException::unauthorized()->getMessage()) {
                    throw $this->createAccessDeniedException($exception->getMessage());
                }
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('app_teacher_profile');
            }
        }

        $response = $this->render('teacher/profile.html.twig', [
            'form' => $form,
            'profile' => $profile,
            'catalog_open' => [] !== $activeSubjects,
            'school_catalog_open' => $this->profiles->hasActiveSchoolChain(),
            'school_label' => $this->schoolLabel($profile),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/profil/konumlar', name: 'app_teacher_profile_locations', methods: ['GET'])]
    public function locations(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->activeVerifiedUsers->isActiveAndVerified($user)) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }

        $parent = $request->query->getString('parent');
        $parentId = null;
        if ('' !== $parent) {
            if (!Uuid::isValid($parent)) {
                throw $this->createNotFoundException();
            }
            $parentId = Uuid::fromString($parent);
        }
        $rows = [];
        foreach ($this->locations->findActiveChildren($parentId) as $location) {
            $rows[] = [
                'id' => $location->getId()->toRfc4122(),
                'name' => $location->getName(),
            ];
        }
        $response = $this->json(['locations' => $rows]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function schoolLabel(?TeacherProfessionalProfile $profile): ?string
    {
        $school = $profile?->getSchoolLocation();
        $district = $school?->getParent();
        $province = $district?->getParent();
        if (null === $school || null === $district || null === $province) {
            return null;
        }

        return $province->getName().' / '.$district->getName().' / '.$school->getName();
    }
}

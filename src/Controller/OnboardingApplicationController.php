<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InstitutionApplicationRequest;
use App\Dto\TeacherApplicationRequest;
use App\Entity\User;
use App\Exception\OnboardingApplicationException;
use App\Form\InstitutionApplicationFormType;
use App\Form\TeacherApplicationFormType;
use App\Repository\InstitutionApplicationRepository;
use App\Repository\TeacherApplicationRepository;
use App\Service\InstitutionApplicationManager;
use App\Service\TeacherApplicationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Authenticated pending application surfaces. Never exposes approve/reject.
 */
final class OnboardingApplicationController extends AbstractController
{
    public function __construct(
        private readonly TeacherApplicationManager $teacherApplications,
        private readonly InstitutionApplicationManager $institutionApplications,
        private readonly TeacherApplicationRepository $teacherApplicationRepository,
        private readonly InstitutionApplicationRepository $institutionApplicationRepository,
    ) {
    }

    #[Route('/basvuru/ogretmen', name: 'app_teacher_application', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function teacher(
        Request $request,
        #[Autowire(service: 'limiter.onboarding_application_submit')]
        RateLimiterFactory $submitLimiter,
    ): Response {
        $user = $this->requireUser();
        $open = $this->teacherApplicationRepository->findOpenForUser($user);

        $dto = new TeacherApplicationRequest();
        $form = $this->createForm(TeacherApplicationFormType::class, $dto);
        $form->handleRequest($request);

        if (null === $open && $form->isSubmitted() && $form->isValid()) {
            $this->consumeSubmitLimit($submitLimiter, $user->getId());

            try {
                $this->teacherApplications->submit($user);
                $this->addFlash(
                    'success',
                    'Öğretmen başvurunuz alındı. İnceleme tamamlanana kadar öğretmen erişiminiz açılmaz.'
                );

                return $this->redirectToRoute('app_teacher_application');
            } catch (OnboardingApplicationException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('security/teacher_application.html.twig', [
            'form' => $form,
            'openApplication' => $open,
        ]);
    }

    #[Route('/basvuru/kurum', name: 'app_institution_application', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function institution(
        Request $request,
        #[Autowire(service: 'limiter.onboarding_application_submit')]
        RateLimiterFactory $submitLimiter,
    ): Response {
        $user = $this->requireUser();
        $open = $this->institutionApplicationRepository->findOpenForUser($user);

        $dto = new InstitutionApplicationRequest();
        $form = $this->createForm(InstitutionApplicationFormType::class, $dto);
        $form->handleRequest($request);

        if (null === $open && $form->isSubmitted() && $form->isValid()) {
            $this->consumeSubmitLimit($submitLimiter, $user->getId());

            try {
                $type = $dto->proposedType;
                if (null === $type) {
                    throw OnboardingApplicationException::invalidInput();
                }
                $this->institutionApplications->submit($user, $dto->proposedName, $type);
                $this->addFlash(
                    'success',
                    'Kurum başvurunuz alındı. İnceleme tamamlanana kadar kurum erişiminiz açılmaz.'
                );

                return $this->redirectToRoute('app_institution_application');
            } catch (OnboardingApplicationException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('security/institution_application.html.twig', [
            'form' => $form,
            'openApplication' => $open,
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function consumeSubmitLimit(RateLimiterFactory $submitLimiter, Uuid $userId): void
    {
        $limiter = $submitLimiter->create('user:'.$userId->toRfc4122());
        if (!$limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }
}

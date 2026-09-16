<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminWebhookDeadLetterRequeueRequest;
use App\Enum\CommerceFailureReason;
use App\Exception\CommerceException;
use App\Form\AdminWebhookDeadLetterRequeueFormType;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminWebhookQueueQuery;
use App\Service\PaymentWebhookDeadLetterRequeueService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminWebhookController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminWebhookQueueQuery $webhookQuery,
        private readonly PaymentWebhookDeadLetterRequeueService $requeueService,
        #[Autowire(service: 'limiter.admin_dead_letter_requeue')]
        private readonly RateLimiterFactory $deadLetterRequeueLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/webhook', name: 'app_admin_webhooks', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function list(Request $request): Response
    {
        $tab = $request->query->getString('tab', AdminWebhookQueueQuery::TAB_DUE);
        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE);

        try {
            $result = $this->webhookQuery->listByTab($this->requireActorId(), $tab, $page, $pageSize);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz sekme.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/webhooks/list.html.twig', [
            'result' => $result,
            'tab' => $tab,
            'tabs' => AdminWebhookQueueQuery::allowedTabs(),
        ]);
    }

    #[Route('/yonetim/webhook/{eventId}', name: 'app_admin_webhook_detail', methods: ['GET'], requirements: ['eventId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function detail(Uuid $eventId): Response
    {
        try {
            $event = $this->webhookQuery->getDetail($this->requireActorId(), $eventId);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        $form = null;
        if ($event->canRequeue) {
            $form = $this->createForm(AdminWebhookDeadLetterRequeueFormType::class, new AdminWebhookDeadLetterRequeueRequest(), [
                'event_id' => $eventId,
                'action' => $this->generateUrl('app_admin_webhook_retry', ['eventId' => $eventId]),
                'method' => 'POST',
            ])->createView();
        }

        return $this->renderAdmin('admin/webhooks/detail.html.twig', [
            'event' => $event,
            'requeue_form' => $form,
        ]);
    }

    #[Route('/yonetim/webhook/{eventId}/yeniden-dene', name: 'app_admin_webhook_retry', methods: ['POST'], requirements: ['eventId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_DEAD_LETTER_REQUEUE)]
    public function retry(Request $request, Uuid $eventId): Response
    {
        $actorId = $this->requireActorId();
        $limiter = $this->deadLetterRequeueLimiter->create($actorId->toRfc4122().':'.$eventId->toRfc4122());
        $rateLimit = $limiter->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException(
                $seconds,
                'Çok fazla yeniden deneme isteği. Lütfen daha sonra tekrar deneyin.',
            );
        }

        $payload = $request->request->all()['admin_webhook_dead_letter_requeue'] ?? null;
        $token = \is_array($payload) ? ($payload['_token'] ?? null) : null;
        if (!\is_string($token) || '' === $token) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }

        $dto = new AdminWebhookDeadLetterRequeueRequest();
        $form = $this->createForm(AdminWebhookDeadLetterRequeueFormType::class, $dto, [
            'event_id' => $eventId,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            $this->addFlash('error', 'Yeniden deneme formu geçersiz. Onay ve geçerli gerekçe gereklidir.');

            return $this->redirectToRoute('app_admin_webhook_detail', ['eventId' => $eventId]);
        }

        try {
            $result = $this->requeueService->requeue(
                $eventId,
                $actorId,
                $dto->reasonCode,
                $dto->confirm,
            );
            $this->addFlash(
                'success',
                \sprintf(
                    'Webhook yeniden kuyruğa alındı (durum: %s, deneme: %d).',
                    $result->status->value,
                    $result->attemptCount,
                ),
            );
        } catch (CommerceException $e) {
            match ($e->getReason()) {
                CommerceFailureReason::Unauthorized => $this->mapCommerceException($e),
                CommerceFailureReason::NotFound => $this->mapCommerceException($e),
                CommerceFailureReason::InvalidTransition => $this->addFlash('error', 'Olay dead-letter durumunda değil; yeniden deneme uygulanmadı.'),
                CommerceFailureReason::InvalidInput => $this->addFlash('error', 'Yeniden deneme onayı veya gerekçe geçersiz.'),
                CommerceFailureReason::Conflict => $this->addFlash('warning', 'Eşzamanlı işlem çakışması; lütfen durumu yenileyin.'),
                default => $this->addFlash('error', 'Yeniden deneme tamamlanamadı.'),
            };
        }

        $response = $this->redirectToRoute('app_admin_webhook_detail', ['eventId' => $eventId]);
        $this->applyNoStore($response);

        return $response;
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<mixed> $form
     */
    private function formHasCsrfFailure(\Symfony\Component\Form\FormInterface $form): bool
    {
        foreach ($form->getErrors(true) as $error) {
            $haystack = strtolower($error->getMessage().' '.$error->getMessageTemplate());
            if (str_contains($haystack, 'csrf')) {
                return true;
            }
        }

        return false;
    }
}

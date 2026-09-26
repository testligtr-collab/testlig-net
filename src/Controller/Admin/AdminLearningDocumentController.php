<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LearningContent;
use App\Exception\CommerceException;
use App\Exception\LearningContentException;
use App\LearningContent\Document\LearningDocumentResponse;
use App\Security\AdminPermission;
use App\Service\Admin\AdminLearningContentQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\LearningDocumentManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminLearningDocumentController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminLearningContentQuery $query,
        private readonly LearningDocumentManager $documents,
        private readonly LearningDocumentResponse $responses,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/icerikler/{id}/dokuman/{index}', name: 'app_admin_learning_document', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'index' => '\d+'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_VIEW)]
    public function openBlock(string $id, int $index): Response
    {
        $content = $this->visibleContent($id);
        $revision = $content->getCurrentRevision();
        if (null === $revision) {
            throw new NotFoundHttpException();
        }
        $blocks = $revision->getStructuredContent()['blocks'] ?? [];
        if (!\is_array($blocks) || !isset($blocks[$index]) || !\is_array($blocks[$index])) {
            throw new NotFoundHttpException();
        }
        $block = $blocks[$index];
        $assetId = $block['assetId'] ?? null;
        if ('document' !== ($block['type'] ?? null) || !\is_string($assetId) || !Uuid::isValid($assetId)) {
            throw new NotFoundHttpException();
        }

        try {
            $asset = $this->documents->requireReadyAsset(Uuid::fromString($assetId));
        } catch (LearningContentException) {
            throw new NotFoundHttpException();
        }

        return $this->responses->inline($asset);
    }

    #[Route('/yonetim/icerikler/{id}/dokuman-onizleme/{handle}', name: 'app_admin_learning_document_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'handle' => '[a-f0-9]{64}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_VIEW)]
    public function preview(string $id, string $handle): Response
    {
        $this->visibleContent($id);

        try {
            return $this->responses->inline($this->documents->requireReadable($this->requireActorUser(), $handle));
        } catch (LearningContentException) {
            throw new NotFoundHttpException();
        }
    }

    private function visibleContent(string $id): LearningContent
    {
        try {
            return $this->query->requireVisibleContent($this->requireActorId(), Uuid::fromString($id));
        } catch (CommerceException|LearningContentException|\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
    }
}

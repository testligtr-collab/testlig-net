<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Exception\LearningContentException;
use App\Presentation\ContentWorkflowLabels;
use App\Presentation\ContentWorkflowProgress;
use App\Presentation\ContentWorkflowReason;
use App\Presentation\PlacementPosition;
use PHPUnit\Framework\TestCase;

final class ContentWorkflowUxTest extends TestCase
{
    public function testLabelsDoNotReplaceDomainValues(): void
    {
        $labels = new ContentWorkflowLabels();
        self::assertSame('Taslak', $labels->label('status', 'draft'));
        self::assertSame('İncelemede', $labels->label('status', 'in_review'));
        self::assertSame('Yayında', $labels->label('status', 'published'));
        self::assertSame('Arşivlenmiş', $labels->label('status', 'archived'));
        self::assertSame('Konu anlatımı', $labels->label('type', 'topic_explanation'));
        self::assertSame('Ücretsiz', $labels->label('access', 'free'));
        self::assertSame('Platform geneli', $labels->label('scope', 'platform'));
        self::assertSame('Bilgi', $labels->label('callout', 'info'));
        self::assertSame('custom_value', $labels->label('status', 'custom_value'));
    }

    public function testReasonDefaultsAndKeepsValidPostedCode(): void
    {
        $reason = new ContentWorkflowReason();
        $empty = $reason->resolve(ContentWorkflowReason::SUBMIT_REVIEW, '', '   ');
        self::assertSame('ready_for_review', $empty['code']);
        self::assertNull($empty['operator_note']);

        $posted = $reason->resolve(ContentWorkflowReason::PUBLISH, 'publish_approved', 'Pilot notu');
        self::assertSame('publish_approved', $posted['code']);
        self::assertSame('Pilot notu', $posted['operator_note']);

        $prose = $reason->resolve(ContentWorkflowReason::PLACEMENT_CREATE, 'hazır değil', null);
        self::assertSame('admin_placement_create', $prose['code']);
        self::assertSame('hazır değil', $prose['operator_note']);
    }

    public function testOperatorNoteRejectsControlCharacters(): void
    {
        $this->expectException(LearningContentException::class);
        (new ContentWorkflowReason())->resolve(ContentWorkflowReason::ARCHIVE, null, "not\x00");
    }

    public function testTeacherDraftShowsSubmitNotPublish(): void
    {
        $progress = (new ContentWorkflowProgress())->summarize($this->state([
            'status' => 'draft',
            'has_revision' => true,
            'can_submit_review' => true,
            'can_publish' => false,
        ]));
        self::assertSame('submit_review', $progress['next']['kind']);
        self::assertTrue($progress['steps'][0]['done']);
        self::assertTrue($progress['steps'][1]['done']);
        self::assertFalse($progress['steps'][3]['done']);
    }

    public function testPublisherInReviewIsTheOnlyPublishStep(): void
    {
        $progress = (new ContentWorkflowProgress())->summarize($this->state([
            'status' => 'in_review',
            'has_revision' => true,
            'revision_sealed' => true,
            'can_publish' => true,
            'can_submit_review' => false,
        ]));
        self::assertSame('publish', $progress['next']['kind']);
        self::assertTrue($progress['steps'][2]['done']);
        self::assertFalse($progress['steps'][3]['done']);
    }

    public function testFreePublishedPlacementIsVisibleToStudents(): void
    {
        $progress = (new ContentWorkflowProgress())->summarize($this->state([
            'status' => 'published',
            'has_revision' => true,
            'revision_sealed' => true,
            'access_class' => 'free',
            'has_published_placement' => true,
        ]));
        self::assertSame('complete', $progress['next']['kind']);
        self::assertTrue($progress['steps'][5]['done']);
    }

    public function testFailClosedPolicyDoesNotCountAsVisible(): void
    {
        $progress = (new ContentWorkflowProgress())->summarize($this->state([
            'status' => 'published',
            'has_revision' => true,
            'revision_sealed' => true,
            'access_class' => null,
            'has_published_placement' => true,
            'can_set_policy' => true,
        ]));
        self::assertSame('set_policy', $progress['next']['kind']);
        self::assertFalse($progress['steps'][5]['done']);
    }

    public function testDraftPlacementAsksForPublishNotASecondCreate(): void
    {
        $progress = (new ContentWorkflowProgress())->summarize($this->state([
            'status' => 'published',
            'has_revision' => true,
            'revision_sealed' => true,
            'access_class' => 'free',
            'has_draft_placement' => true,
            'can_publish_placement' => true,
        ]));
        self::assertSame('publish_placement', $progress['next']['kind']);
        self::assertFalse($progress['steps'][4]['done']);
    }

    public function testNextPositionSkipsHighestOccupied(): void
    {
        self::assertSame(0, PlacementPosition::next(null));
        self::assertSame(3, PlacementPosition::next(2));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{
     *     status: string,
     *     has_revision: bool,
     *     revision_sealed: bool,
     *     access_class: ?string,
     *     has_draft_placement: bool,
     *     has_published_placement: bool,
     *     can_manage: bool,
     *     can_submit_review: bool,
     *     can_publish: bool,
     *     can_set_policy: bool,
     *     can_create_placement: bool,
     *     can_publish_placement: bool
     * }
     */
    private function state(array $overrides): array
    {
        $state = [
            'status' => 'draft',
            'has_revision' => false,
            'revision_sealed' => false,
            'access_class' => null,
            'has_draft_placement' => false,
            'has_published_placement' => false,
            'can_manage' => false,
            'can_submit_review' => false,
            'can_publish' => false,
            'can_set_policy' => false,
            'can_create_placement' => false,
            'can_publish_placement' => false,
        ];
        foreach ($overrides as $key => $value) {
            if (\array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        return $state;
    }
}

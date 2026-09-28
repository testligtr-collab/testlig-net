<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentRevision;
use App\Entity\Classroom;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Institution classroom deliveries the student was snapshotted into.
 * Does not create the platform practice workspace.
 */
final class StudentAssignedTestCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{
     *     code: string,
     *     title: string,
     *     subject: string,
     *     question_count: int,
     *     duration_label: string,
     *     state: string,
     *     institution: string,
     *     classroom: string,
     *     window: string,
     *     status_label: string,
     *     can_start: bool
     * }>
     */
    public function listFor(User $student): array
    {
        $recipients = $this->recipients($student);
        if ([] === $recipients) {
            return [];
        }
        $attempts = $this->ownedAttempts($student, $recipients);
        $counts = $this->itemCounts($recipients);
        $cards = [];
        foreach ($recipients as $recipient) {
            $delivery = $recipient->getDelivery();
            $attempt = $attempts[$delivery->getId()->toRfc4122()] ?? null;
            if (AssessmentDeliveryStatus::Cancelled === $delivery->getStatus() && !$attempt instanceof AssessmentAttempt) {
                continue;
            }
            $revision = $delivery->getAssessmentPublication()->getAssessmentRevision();
            $cards[] = $this->card($recipient, $attempt, $counts[$revision->getId()->toRfc4122()] ?? 0);
        }

        return $cards;
    }

    public function deliveryFor(User $student, string $code): ?AssessmentDelivery
    {
        $code = strtolower($code);
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $code)) {
            return null;
        }
        foreach ($this->recipients($student) as $recipient) {
            $delivery = $recipient->getDelivery();
            if (hash_equals($this->hasher->studentAssignmentCode($delivery->getId()), $code)) {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * @return list<AssessmentDeliveryRecipient>
     */
    private function recipients(User $student): array
    {
        /** @var list<AssessmentDeliveryRecipient> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('recipient', 'delivery', 'assessment', 'publication', 'revision', 'institution', 'classroom', 'subject')
            ->from(AssessmentDeliveryRecipient::class, 'recipient')
            ->innerJoin('recipient.delivery', 'delivery')
            ->innerJoin('delivery.assessment', 'assessment')
            ->innerJoin('delivery.assessmentPublication', 'publication')
            ->innerJoin('publication.assessmentRevision', 'revision')
            ->innerJoin('delivery.institution', 'institution')
            ->leftJoin('delivery.classroom', 'classroom')
            ->leftJoin('assessment.subject', 'subject')
            ->innerJoin('recipient.studentMembership', 'membership')
            ->andWhere('membership.user = :student')
            ->andWhere('recipient.status = :eligible')
            ->andWhere('delivery.audienceType = :classroom')
            ->andWhere('assessment.scope = :scope')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('eligible', AssessmentDeliveryRecipientStatus::Eligible)
            ->setParameter('classroom', AssessmentDeliveryAudienceType::Classroom)
            ->setParameter('scope', AssessmentScope::Institution)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<AssessmentDeliveryRecipient> $recipients
     *
     * @return array<string, AssessmentAttempt>
     */
    private function ownedAttempts(User $student, array $recipients): array
    {
        $deliveries = [];
        foreach ($recipients as $recipient) {
            $deliveries[] = $recipient->getDelivery();
        }
        $builder = $this->entityManager->createQueryBuilder()
            ->select('attempt')
            ->from(AssessmentAttempt::class, 'attempt')
            ->andWhere('attempt.user = :student')
            ->setParameter('student', $student->getId(), 'uuid');
        $matches = [];
        foreach ($deliveries as $index => $delivery) {
            $name = 'delivery'.$index;
            $matches[] = 'attempt.delivery = :'.$name;
            $builder->setParameter($name, $delivery->getId(), 'uuid');
        }
        $builder->andWhere('('.implode(' OR ', $matches).')');

        /** @var list<AssessmentAttempt> $rows */
        $rows = $builder->getQuery()->getResult();
        $owned = [];
        foreach ($rows as $attempt) {
            $key = $attempt->getDelivery()->getId()->toRfc4122();
            $current = $owned[$key] ?? null;
            if (!$current instanceof AssessmentAttempt || $attempt->getAttemptNumber() > $current->getAttemptNumber()) {
                $owned[$key] = $attempt;
            }
        }

        return $owned;
    }

    /**
     * @param list<AssessmentDeliveryRecipient> $recipients
     *
     * @return array<string, int>
     */
    private function itemCounts(array $recipients): array
    {
        $revisions = [];
        foreach ($recipients as $recipient) {
            $revision = $recipient->getDelivery()->getAssessmentPublication()->getAssessmentRevision();
            $revisions[$revision->getId()->toRfc4122()] = $revision;
        }
        if ([] === $revisions) {
            return [];
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('revision.id AS revisionId', 'COUNT(item.id) AS itemCount')
            ->from(AssessmentItem::class, 'item')
            ->innerJoin('item.assessmentRevision', 'revision')
            ->groupBy('revision.id');
        $matches = [];
        foreach (array_values($revisions) as $index => $revision) {
            $name = 'revision'.$index;
            $matches[] = 'revision = :'.$name;
            $builder->setParameter($name, $revision->getId(), 'uuid');
        }
        $builder->andWhere('('.implode(' OR ', $matches).')');

        /** @var list<array{revisionId: mixed, itemCount: int|string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $id = $row['revisionId'];
            $key = $id instanceof \Symfony\Component\Uid\Uuid ? $id->toRfc4122() : (string) $id;
            $counts[$key] = (int) $row['itemCount'];
        }

        return $counts;
    }

    /**
     * @return array{
     *     code: string,
     *     title: string,
     *     subject: string,
     *     question_count: int,
     *     duration_label: string,
     *     state: string,
     *     institution: string,
     *     classroom: string,
     *     window: string,
     *     status_label: string,
     *     can_start: bool
     * }
     */
    private function card(AssessmentDeliveryRecipient $recipient, ?AssessmentAttempt $attempt, int $questionCount): array
    {
        $delivery = $recipient->getDelivery();
        $revision = $delivery->getAssessmentPublication()->getAssessmentRevision();
        $state = $this->state($delivery, $attempt);
        $classroom = $delivery->getClassroom();

        return [
            'code' => $this->hasher->studentAssignmentCode($delivery->getId()),
            'title' => $revision->getTitle(),
            'subject' => $delivery->getAssessment()->getSubject()?->getName() ?? '',
            'question_count' => $questionCount,
            'duration_label' => $this->duration($revision),
            'state' => $state,
            'institution' => $delivery->getInstitution()->getName(),
            'classroom' => $classroom instanceof Classroom ? $classroom->getName() : '',
            'window' => $this->window($delivery),
            'status_label' => match ($state) {
                'soon' => 'Yakında',
                'available' => 'Başlayabilir',
                'resume' => 'Devam ediyor',
                'done' => 'Tamamlandı',
                default => 'Süresi doldu',
            },
            'can_start' => 'available' === $state,
        ];
    }

    private function state(AssessmentDelivery $delivery, ?AssessmentAttempt $attempt): string
    {
        if ($attempt instanceof AssessmentAttempt) {
            return AssessmentAttemptStatus::InProgress === $attempt->getStatus() ? 'resume' : 'done';
        }
        $now = UtcInstant::ensure($this->clock->now());
        if (AssessmentDeliveryStatus::Active !== $delivery->getStatus() || $now >= $delivery->getClosesAt()) {
            return 'expired';
        }
        if ($now < $delivery->getOpensAt()) {
            return 'soon';
        }

        return 'available';
    }

    private function duration(AssessmentRevision $revision): string
    {
        $seconds = $revision->getDurationSeconds();
        if (null === $seconds || $seconds < 1) {
            return 'Süresiz';
        }
        $minutes = (int) ceil($seconds / 60);

        return $minutes.' dk';
    }

    private function window(AssessmentDelivery $delivery): string
    {
        $opens = UtcInstant::ensure($delivery->getOpensAt())->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i');
        if ((int) $delivery->getClosesAt()->format('Y') >= 9999) {
            return $opens.' — Bitiş yok';
        }
        $closes = UtcInstant::ensure($delivery->getClosesAt())->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i');

        return $opens.' — '.$closes;
    }
}

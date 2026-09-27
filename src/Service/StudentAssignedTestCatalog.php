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
use App\Repository\AssessmentAttemptRepository;
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
        private readonly AssessmentAttemptRepository $attempts,
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
        $cards = [];
        foreach ($this->recipients($student) as $recipient) {
            $delivery = $recipient->getDelivery();
            if (AssessmentDeliveryStatus::Cancelled === $delivery->getStatus()
                && !$this->attempts->findOwnedForDelivery($delivery->getId(), $student->getId()) instanceof AssessmentAttempt
            ) {
                continue;
            }
            $cards[] = $this->card($student, $recipient);
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
    private function card(User $student, AssessmentDeliveryRecipient $recipient): array
    {
        $delivery = $recipient->getDelivery();
        $revision = $delivery->getAssessmentPublication()->getAssessmentRevision();
        $attempt = $this->attempts->findOwnedForDelivery($delivery->getId(), $student->getId());
        $state = $this->state($delivery, $attempt);
        $classroom = $delivery->getClassroom();

        return [
            'code' => $this->hasher->studentAssignmentCode($delivery->getId()),
            'title' => $revision->getTitle(),
            'subject' => $delivery->getAssessment()->getSubject()?->getName() ?? '',
            'question_count' => $this->itemCount($revision),
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

    private function itemCount(AssessmentRevision $revision): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(item.id)')
            ->from(AssessmentItem::class, 'item')
            ->andWhere('item.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
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

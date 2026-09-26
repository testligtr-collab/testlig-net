<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentScore;
use App\Attempt\Answer\AttemptAnswerReader;
use App\Attempt\Answer\AttemptStudentAnswerValidator;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\QuestionType;
use App\Exception\AssessmentAttemptException;
use App\Exception\AssessmentException;
use App\Exception\AssessmentScoringException;
use App\Exception\StudentPracticeException;
use App\Question\Content\QuestionPlainText;
use App\Repository\AssessmentAttemptAnswerRepository;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentAttemptRepository;
use App\Repository\AssessmentDeliveryRecipientRepository;
use App\Repository\AssessmentDeliveryRepository;
use App\Repository\AssessmentItemRepository;
use App\Repository\AssessmentPlatformPracticeRepository;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\AssessmentRepository;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Student self-serve practice over published platform assessments.
 *
 * Access is the student's completed profile grade. Institution-scoped assessments stay on deliveries.
 * Attempts, encrypted answers, and scores stay on the existing assessment stack.
 */
final class StudentAssessmentPractice
{
    private const WORKSPACE_NAME = 'Bireysel deneme';

    private const WORKSPACE_SLUG = 'bireysel-deneme';

    public function __construct(
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentPlatformPracticeRepository $practices,
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptItemRepository $attemptItems,
        private readonly AssessmentAttemptAnswerRepository $answers,
        private readonly AssessmentItemRepository $items,
        private readonly AssessmentPublicationRepository $publications,
        private readonly AssessmentDeliveryRepository $deliveries,
        private readonly AssessmentDeliveryRecipientRepository $recipients,
        private readonly InstitutionRepository $institutions,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly InstitutionNameNormalizer $names,
        private readonly AssessmentAttemptManager $attemptManager,
        private readonly AssessmentScoringManager $scoring,
        private readonly AttemptAnswerReader $answerReader,
        private readonly StudentPracticeResultReader $results,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{code: string, title: string, subject: string, question_count: int, duration_label: string, state: string}>
     */
    public function listFor(User $student, GradeLevel $grade): array
    {
        $this->assertStudent($student);
        $rows = [];
        foreach ($this->assessments->findPublishedPlatformForGrade($grade) as $assessment) {
            $revision = $assessment->getPublishedRevision();
            if (!$revision instanceof AssessmentRevision) {
                continue;
            }
            $attempt = $this->attemptFor($student, $assessment);
            $rows[] = [
                'code' => $assessment->getCode(),
                'title' => $revision->getTitle(),
                'subject' => $assessment->getSubject()?->getName() ?? '',
                'question_count' => \count($this->revisionItems($revision)),
                'duration_label' => $this->durationLabel($revision),
                'state' => $this->state($attempt),
            ];
        }

        return $rows;
    }

    /**
     * @return array{code: string, title: string, instructions: ?string, question_count: int, duration_label: string, total_points: string, state: string}
     */
    public function detail(User $student, GradeLevel $grade, string $code): array
    {
        $assessment = $this->visible($student, $grade, $code);
        $revision = $this->publishedRevision($assessment);
        $attempt = $this->attemptFor($student, $assessment);

        return [
            'code' => $assessment->getCode(),
            'title' => $revision->getTitle(),
            'instructions' => $revision->getInstructions(),
            'question_count' => \count($this->revisionItems($revision)),
            'duration_label' => $this->durationLabel($revision),
            'total_points' => $this->totalPoints($revision),
            'state' => $this->state($attempt),
        ];
    }

    public function start(User $student, GradeLevel $grade, string $code): AssessmentAttempt
    {
        $assessment = $this->visible($student, $grade, $code);
        $existing = $this->attemptFor($student, $assessment);
        if ($existing instanceof AssessmentAttempt) {
            return $existing;
        }
        $revision = $this->publishedRevision($assessment);
        $this->assertPracticeItems($revision);
        $delivery = $this->provision($student, $assessment, $revision);
        try {
            return $this->attemptManager->startAttempt($delivery, $student, 'practice_start');
        } catch (AssessmentAttemptException $exception) {
            if (AssessmentAttemptFailureReason::ActiveAttemptExists === $exception->getReason()
                || AssessmentAttemptFailureReason::AttemptQuotaExceeded === $exception->getReason()
            ) {
                $attempt = $this->attemptFor($student, $assessment);
                if ($attempt instanceof AssessmentAttempt) {
                    return $attempt;
                }
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *     code: string,
     *     title: string,
     *     instructions: ?string,
     *     deadline: ?string,
     *     deadline_iso: ?string,
     *     position: int,
     *     count: int,
     *     progress: list<array{position: int, answered: bool, current: bool}>,
     *     stem: list<string>,
     *     options: list<array{index: int, text: string, selected: bool}>,
     *     answered: bool,
     *     expected_version: int
     * }
     */
    public function solve(User $student, GradeLevel $grade, string $code, int $requestedPosition): array
    {
        $assessment = $this->visible($student, $grade, $code);
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress !== $attempt->getStatus()) {
            throw StudentPracticeException::rejected('finished');
        }
        $this->finalizeIfDeadlinePassed($student, $attempt);
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress !== $attempt->getStatus()) {
            throw StudentPracticeException::rejected('finished');
        }

        $items = $this->attemptItems->findItemsForAttemptOrdered($attempt->getId());
        if ([] === $items) {
            throw StudentPracticeException::notFound();
        }
        $position = min(max(1, $requestedPosition), \count($items));
        $current = $items[$position - 1];
        $answer = $this->answers->findAnswer($attempt->getId(), $current->getId());
        $selectedKey = $this->selectedKey($student, $answer);
        $revision = $attempt->getAssessmentRevision();

        $progress = [];
        foreach ($items as $item) {
            $saved = $this->answers->findAnswer($attempt->getId(), $item->getId());
            $progress[] = [
                'position' => $item->getPresentationPosition(),
                'answered' => $saved instanceof AssessmentAttemptAnswer,
                'current' => $item->getPresentationPosition() === $position,
            ];
        }

        return [
            'code' => $assessment->getCode(),
            'title' => $revision->getTitle(),
            'instructions' => $revision->getInstructions(),
            'deadline' => null === $revision->getDurationSeconds() ? null : $attempt->getExpiresAt()->format('d.m.Y H:i').' UTC',
            'deadline_iso' => null === $revision->getDurationSeconds() ? null : $attempt->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'position' => $position,
            'count' => \count($items),
            'progress' => $progress,
            'stem' => QuestionPlainText::lines($current->getQuestionRevision()->getStemContent()),
            'options' => $this->choiceRows($current, $selectedKey),
            'answered' => $answer instanceof AssessmentAttemptAnswer,
            'expected_version' => $answer instanceof AssessmentAttemptAnswer ? $answer->getClientRevision() : 0,
        ];
    }

    public function saveChoice(User $student, GradeLevel $grade, string $code, int $position, int $choice, int $expectedVersion): void
    {
        $assessment = $this->visible($student, $grade, $code);
        $attempt = $this->requireInProgress($student, $assessment);
        $item = $this->itemAt($attempt, $position);
        $keys = $this->orderedKeys($item);
        $index = $choice - 1;
        if (!isset($keys[$index])) {
            throw StudentPracticeException::rejected('choice');
        }
        try {
            $this->attemptManager->saveAnswer(
                $attempt,
                $item,
                $student,
                [
                    'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
                    'answerType' => QuestionType::SingleChoice->value,
                    'selectedStableKey' => $keys[$index],
                ],
                $expectedVersion,
                'practice_save',
            );
        } catch (AssessmentAttemptException $exception) {
            if (AssessmentAttemptFailureReason::AttemptExpired === $exception->getReason()) {
                $this->score($attempt);
                throw StudentPracticeException::rejected('finished');
            }
            throw $exception;
        }
    }

    public function finish(User $student, GradeLevel $grade, string $code): void
    {
        $assessment = $this->visible($student, $grade, $code);
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress === $attempt->getStatus()) {
            try {
                $this->attemptManager->submit($attempt, $student, 'practice_submit');
            } catch (AssessmentAttemptException $exception) {
                if (!\in_array($exception->getReason(), [
                    AssessmentAttemptFailureReason::AttemptExpired,
                    AssessmentAttemptFailureReason::AttemptTerminal,
                ], true)) {
                    throw $exception;
                }
            }
        }
        $this->score($attempt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function result(User $student, GradeLevel $grade, string $code): ?array
    {
        $assessment = $this->visible($student, $grade, $code);
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress === $attempt->getStatus()) {
            throw StudentPracticeException::rejected('in_progress');
        }

        return $this->results->read($student, $attempt);
    }

    private function visible(User $student, GradeLevel $grade, string $code): Assessment
    {
        $this->assertStudent($student);
        $assessment = $this->assessments->findPublishedPlatformByCode(strtolower($code), $grade);
        if (!$assessment instanceof Assessment
            || AssessmentScope::Platform !== $assessment->getScope()
            || AssessmentStatus::Published !== $assessment->getStatus()
            || $assessment->getGradeLevel() !== $grade
        ) {
            throw StudentPracticeException::notFound();
        }

        return $assessment;
    }

    private function assertStudent(User $student): void
    {
        if (!\in_array('ROLE_STUDENT', $student->getRoles(), true) || !$this->activeUsers->isActiveAndVerified($student)) {
            throw AssessmentAttemptException::unauthorized();
        }
    }

    private function publishedRevision(Assessment $assessment): AssessmentRevision
    {
        $revision = $assessment->getPublishedRevision();
        if (!$revision instanceof AssessmentRevision) {
            throw StudentPracticeException::notFound();
        }

        return $revision;
    }

    /**
     * @return list<AssessmentItem>
     */
    private function revisionItems(AssessmentRevision $revision): array
    {
        /** @var list<AssessmentItem> $items */
        $items = $this->items->findBy(['assessmentRevision' => $revision], ['position' => 'ASC']);

        return $items;
    }

    private function assertPracticeItems(AssessmentRevision $revision): void
    {
        $items = $this->revisionItems($revision);
        if ([] === $items) {
            throw StudentPracticeException::rejected('empty');
        }
        $total = '0.00';
        foreach ($items as $item) {
            if (QuestionType::SingleChoice !== $item->getQuestionRevision()->getType()) {
                throw StudentPracticeException::rejected('not_single_choice');
            }
            try {
                $points = AssessmentScore::normalizePoints($item->getPoints());
                $penalty = AssessmentScore::normalizePenalty($item->getPenaltyPoints(), $points);
            } catch (AssessmentException) {
                throw StudentPracticeException::rejected('points');
            }
            if (0 !== bccomp($penalty, '0', 2)) {
                throw StudentPracticeException::rejected('penalty');
            }
            $total = bcadd($total, $points, 2);
        }
        if (1 !== bccomp($total, '0', 2)) {
            throw StudentPracticeException::rejected('points');
        }
    }

    private function provision(User $student, Assessment $assessment, AssessmentRevision $revision): AssessmentDelivery
    {
        $existing = $this->practices->findForUserAndAssessment($student, $assessment);
        if ($existing instanceof AssessmentPlatformPractice) {
            return $existing->getDelivery();
        }
        $now = UtcInstant::ensure($this->clock->now());
        $closes = null === $revision->getDurationSeconds()
            ? new \DateTimeImmutable('9999-01-01 00:00:00', new \DateTimeZone('UTC'))
            : $now->modify(\sprintf('+%d seconds', $revision->getDurationSeconds()));

        try {
            return $this->entityManager->wrapInTransaction(function () use ($student, $assessment, $now, $closes): AssessmentDelivery {
                $locked = $this->practices->findForUserAndAssessment($student, $assessment);
                if ($locked instanceof AssessmentPlatformPractice) {
                    return $locked->getDelivery();
                }
                $institution = $this->ensureWorkspace($now);
                $membership = $this->ensureMembership($institution, $student, $now);
                $publication = $this->latestPublication($assessment);
                $delivery = AssessmentDelivery::createDraft(
                    $institution,
                    $assessment,
                    $publication,
                    AssessmentDeliveryAudienceType::Student,
                    null,
                    $membership,
                    $now,
                    $closes,
                    1,
                    null,
                    null,
                    $student,
                    $now,
                );
                $this->deliveries->save($delivery, false);
                $this->entityManager->flush();
                $recipient = AssessmentDeliveryRecipient::createEligible($delivery, $membership, null, null, $now);
                $this->recipients->save($recipient, false);
                $this->entityManager->flush();
                $delivery->activate($student, $now);
                $practice = AssessmentPlatformPractice::create($student, $assessment, $delivery, $now);
                $this->practices->save($practice, false);
                $this->entityManager->flush();

                return $delivery;
            });
        } catch (UniqueConstraintViolationException) {
            throw AssessmentAttemptException::conflict();
        }
    }

    private function ensureWorkspace(\DateTimeImmutable $now): Institution
    {
        $names = $this->names->normalize(self::WORKSPACE_NAME);
        if (self::WORKSPACE_SLUG !== $names['slug']) {
            throw AssessmentAttemptException::invalidInput('workspace slug mismatch');
        }
        $institution = $this->institutions->findOneBySlug(self::WORKSPACE_SLUG);
        if (!$institution instanceof Institution) {
            $institution = Institution::create(
                $names['name'],
                $names['normalizedName'],
                $names['slug'],
                InstitutionType::Other,
                $now,
            );
            $institution->transitionTo(InstitutionStatus::Active, $now);
            $this->institutions->save($institution, false);
            $this->entityManager->flush();

            return $institution;
        }
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentAttemptException::institutionInactive();
        }

        return $institution;
    }

    private function ensureMembership(Institution $institution, User $student, \DateTimeImmutable $now): InstitutionMembership
    {
        $membership = $this->memberships->findMembership($student, $institution);
        if ($membership instanceof InstitutionMembership) {
            return $membership;
        }
        $membership = InstitutionMembership::createActive(
            $institution,
            $student,
            InstitutionMembershipRole::Student,
            $now,
        );
        $this->memberships->save($membership, false);
        $this->entityManager->flush();

        return $membership;
    }

    private function latestPublication(Assessment $assessment): AssessmentPublication
    {
        $publication = $this->publications->createQueryBuilder('p')
            ->andWhere('p.assessment = :assessment')
            ->setParameter('assessment', $assessment->getId(), 'uuid')
            ->orderBy('p.publicationNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$publication instanceof AssessmentPublication) {
            throw StudentPracticeException::notFound();
        }

        return $publication;
    }

    private function attemptFor(User $student, Assessment $assessment): ?AssessmentAttempt
    {
        $practice = $this->practices->findForUserAndAssessment($student, $assessment);
        if (!$practice instanceof AssessmentPlatformPractice) {
            return null;
        }

        return $this->attempts->findOwnedForDelivery($practice->getDelivery()->getId(), $student->getId());
    }

    private function requireAttempt(User $student, Assessment $assessment): AssessmentAttempt
    {
        $attempt = $this->attemptFor($student, $assessment);
        if (!$attempt instanceof AssessmentAttempt || !$attempt->getUser()->getId()->equals($student->getId())) {
            throw StudentPracticeException::notFound();
        }

        return $attempt;
    }

    private function requireInProgress(User $student, Assessment $assessment): AssessmentAttempt
    {
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress !== $attempt->getStatus()) {
            throw StudentPracticeException::rejected('finished');
        }
        $this->finalizeIfDeadlinePassed($student, $attempt);
        $attempt = $this->requireAttempt($student, $assessment);
        if (AssessmentAttemptStatus::InProgress !== $attempt->getStatus()) {
            throw StudentPracticeException::rejected('finished');
        }

        return $attempt;
    }

    private function finalizeIfDeadlinePassed(User $student, AssessmentAttempt $attempt): void
    {
        $now = UtcInstant::ensure($this->clock->now());
        if ($now < $attempt->getExpiresAt()) {
            return;
        }
        try {
            $this->attemptManager->submit($attempt, $student, 'practice_submit');
        } catch (AssessmentAttemptException $exception) {
            if (!\in_array($exception->getReason(), [
                AssessmentAttemptFailureReason::AttemptExpired,
                AssessmentAttemptFailureReason::AttemptTerminal,
            ], true)) {
                throw $exception;
            }
        }
        $this->score($attempt);
    }

    private function score(AssessmentAttempt $attempt): void
    {
        try {
            $this->scoring->scoreAttempt($attempt, null, 'practice_score');
        } catch (AssessmentScoringException) {
            throw StudentPracticeException::rejected('score');
        }
    }

    private function itemAt(AssessmentAttempt $attempt, int $position): AssessmentAttemptItem
    {
        foreach ($this->attemptItems->findItemsForAttemptOrdered($attempt->getId()) as $item) {
            if ($item->getPresentationPosition() === $position) {
                return $item;
            }
        }
        throw StudentPracticeException::rejected('choice');
    }

    /**
     * @return list<string>
     */
    private function orderedKeys(AssessmentAttemptItem $item): array
    {
        $order = $item->getOptionOrderJson();
        if (null !== $order && [] !== $order) {
            return $order;
        }

        return $this->options->listStableKeysForRevisionOrdered($item->getQuestionRevision()->getId());
    }

    /**
     * @return list<array{index: int, text: string, selected: bool}>
     */
    private function choiceRows(AssessmentAttemptItem $item, ?string $selectedKey): array
    {
        $byKey = [];
        foreach ($this->options->findByRevision($item->getQuestionRevision()) as $option) {
            $byKey[$option->getStableKey()] = implode(' ', QuestionPlainText::lines($option->getContent()));
        }
        $rows = [];
        $index = 1;
        foreach ($this->orderedKeys($item) as $key) {
            $rows[] = [
                'index' => $index,
                'text' => $byKey[$key] ?? '',
                'selected' => null !== $selectedKey && $selectedKey === $key,
            ];
            ++$index;
        }

        return $rows;
    }

    private function selectedKey(User $student, ?AssessmentAttemptAnswer $answer): ?string
    {
        if (!$answer instanceof AssessmentAttemptAnswer) {
            return null;
        }
        $payload = $this->answerReader->readForOwner($answer, $student);
        $key = $payload['selectedStableKey'] ?? null;

        return \is_string($key) ? $key : null;
    }

    private function state(?AssessmentAttempt $attempt): string
    {
        if (!$attempt instanceof AssessmentAttempt) {
            return 'start';
        }

        return AssessmentAttemptStatus::InProgress === $attempt->getStatus() ? 'resume' : 'done';
    }

    private function durationLabel(AssessmentRevision $revision): string
    {
        $seconds = $revision->getDurationSeconds();
        if (null === $seconds) {
            return 'Süresiz';
        }
        if (0 === $seconds % 60) {
            return intdiv($seconds, 60).' dk';
        }

        return $seconds.' sn';
    }

    private function totalPoints(AssessmentRevision $revision): string
    {
        $total = '0.00';
        foreach ($this->revisionItems($revision) as $item) {
            $total = bcadd($total, AssessmentScore::normalizePoints($item->getPoints()), 2);
        }

        return $total;
    }
}

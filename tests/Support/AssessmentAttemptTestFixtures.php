<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Attempt\Answer\AttemptAnswerReader;
use App\Attempt\Answer\AttemptStudentAnswerValidator;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\QuestionType;
use App\Repository\AssessmentAttemptActiveGuardRepository;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentAttemptRepository;
use App\Service\AssessmentAttemptManager;
use Symfony\Component\Uid\Uuid;

/**
 * Shared bootstrap helpers for Stage 2.11 assessment attempt tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait AssessmentAttemptTestFixtures
{
    use AssessmentDeliveryTestFixtures;

    private function attempts(): AssessmentAttemptManager
    {
        $s = static::getContainer()->get(AssessmentAttemptManager::class);
        self::assertInstanceOf(AssessmentAttemptManager::class, $s);

        return $s;
    }

    private function answerReader(): AttemptAnswerReader
    {
        $s = static::getContainer()->get(AttemptAnswerReader::class);
        self::assertInstanceOf(AttemptAnswerReader::class, $s);

        return $s;
    }

    private function answerValidator(): AttemptStudentAnswerValidator
    {
        $s = static::getContainer()->get(AttemptStudentAnswerValidator::class);
        self::assertInstanceOf(AttemptStudentAnswerValidator::class, $s);

        return $s;
    }

    private function attemptItems(): AssessmentAttemptItemRepository
    {
        $s = static::getContainer()->get(AssessmentAttemptItemRepository::class);
        self::assertInstanceOf(AssessmentAttemptItemRepository::class, $s);

        return $s;
    }

    private function attemptRepository(): AssessmentAttemptRepository
    {
        $s = static::getContainer()->get(AssessmentAttemptRepository::class);
        self::assertInstanceOf(AssessmentAttemptRepository::class, $s);

        return $s;
    }

    private function activeGuards(): AssessmentAttemptActiveGuardRepository
    {
        $s = static::getContainer()->get(AssessmentAttemptActiveGuardRepository::class);
        self::assertInstanceOf(AssessmentAttemptActiveGuardRepository::class, $s);

        return $s;
    }

    /**
     * @return array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: \App\Entity\Institution,
     *     classroom: \App\Entity\Classroom,
     *     teacher: User,
     *     teacherMembership: \App\Entity\InstitutionMembership,
     *     student: User,
     *     studentMembership: \App\Entity\InstitutionMembership,
     *     assessment: \App\Entity\Assessment,
     *     publication: \App\Entity\AssessmentPublication,
     *     delivery: AssessmentDelivery,
     *     recipient: AssessmentDeliveryRecipient
     * }
     */
    private function activatedClassroomDelivery(string $prefix, int $maxAttempts = 2): array
    {
        $ctx = $this->publishedDeliveryContext($prefix);
        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            $maxAttempts,
            null,
            null,
            'create_att_'.$prefix,
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_att_'.$prefix);
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        $recipient = $this->em->getRepository(AssessmentDeliveryRecipient::class)->findOneBy([
            'delivery' => $delivery,
            'user' => $ctx['student'],
        ]);
        self::assertInstanceOf(AssessmentDeliveryRecipient::class, $recipient);

        return $ctx + [
            'delivery' => $delivery,
            'recipient' => $recipient,
        ];
    }

    private function firstAttemptItem(AssessmentAttempt $attempt): AssessmentAttemptItem
    {
        $items = $this->attemptItems()->findItemsForAttemptOrdered($attempt->getId());
        self::assertNotEmpty($items);

        return $items[0];
    }

    /**
     * @return array{version: int, answerType: string, selectedStableKey: string}
     */
    private function singleChoicePayload(string $selectedStableKey = 'opt_b'): array
    {
        return [
            'version' => AttemptStudentAnswerValidator::PAYLOAD_VERSION,
            'answerType' => QuestionType::SingleChoice->value,
            'selectedStableKey' => $selectedStableKey,
        ];
    }

    private function reloadAttempt(Uuid $id): AssessmentAttempt
    {
        $attempt = $this->em->find(AssessmentAttempt::class, $id);
        self::assertInstanceOf(AssessmentAttempt::class, $attempt);

        return $attempt;
    }

    private function reloadUser(Uuid $id): User
    {
        $user = $this->users->find($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function reloadDelivery(Uuid $id): AssessmentDelivery
    {
        $delivery = $this->em->find(AssessmentDelivery::class, $id);
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        return $delivery;
    }
}

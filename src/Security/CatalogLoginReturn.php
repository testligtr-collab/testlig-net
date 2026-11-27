<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Service\PublicCatalogQuery;
use App\Service\StudentCatalogQuery;
use App\Service\StudentProfileManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Remembers the public catalog unit (or one of its topics) a visitor was browsing when they chose
 * "Giriş yap", and turns it into the matching student route after a successful login.
 *
 * Nothing the visitor sends is used as a redirect target. Only validated catalog coordinates
 * (grade number, subject/unit/topic slugs of currently published records) are stored in the session,
 * and the destination is rebuilt from the student route names. The entry is single use and short lived.
 */
final class CatalogLoginReturn
{
    public const SESSION_KEY = 'catalog_login_return';

    public const TTL_SECONDS = 1800;

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly PublicCatalogQuery $publicCatalog,
        private readonly StudentCatalogQuery $studentCatalog,
        private readonly StudentProfileManager $profiles,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Stores the coordinates when they name a published public unit (and topic, when given).
     * Anything else clears a previous entry and returns false.
     */
    public function remember(SessionInterface $session, mixed $grade, mixed $subject, mixed $unit, mixed $topic = null): bool
    {
        $this->discard($session);

        $level = $this->gradeLevel($grade);
        if (!$level instanceof GradeLevel || !$this->isSlug($subject) || !$this->isSlug($unit)) {
            return false;
        }
        if (null !== $topic && !$this->isSlug($topic)) {
            return false;
        }

        $page = $this->publicCatalog->unit($level, $subject, $unit);
        if (null === $page) {
            return false;
        }
        if (null !== $topic) {
            $known = false;
            foreach ($page->topics as $card) {
                if ($card->slug === $topic) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                return false;
            }
        }

        $session->set(self::SESSION_KEY, [
            'grade' => $level->value,
            'subject' => $subject,
            'unit' => $unit,
            'topic' => $topic,
            'at' => $this->clock->now()->getTimestamp(),
        ]);

        return true;
    }

    public function hasPending(SessionInterface $session): bool
    {
        return null !== $this->pending($session);
    }

    public function discard(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Consumes the remembered entry. Returns a same-site path only when this user is a student of the
     * remembered grade with a finished profile and the record is still published. Otherwise null, so the
     * caller falls back to the normal role destination.
     */
    public function consume(SessionInterface $session, User $user): ?string
    {
        $entry = $this->pending($session);
        $this->discard($session);
        if (null === $entry) {
            return null;
        }
        if (!\in_array(UserRole::Student->value, $user->getRoles(), true)) {
            return null;
        }
        $profile = $this->profiles->findForUser($user);
        if (null === $profile || !$profile->isOnboardingCompleted() || $profile->getGradeLevel()->value !== $entry['grade']) {
            return null;
        }
        $detail = $this->studentCatalog->getPublishedUnitDetail($profile->getGradeLevel(), $entry['subject'], $entry['unit']);
        if (null === $detail) {
            return null;
        }

        if (null !== $entry['topic']) {
            foreach ($detail['topics'] as $topic) {
                if ($topic->slug === $entry['topic']) {
                    return $this->urls->generate('app_student_course_topic', [
                        'subjectSlug' => $entry['subject'],
                        'unitSlug' => $entry['unit'],
                        'topicSlug' => $entry['topic'],
                    ]);
                }
            }
        }

        return $this->urls->generate('app_student_course_unit', [
            'subjectSlug' => $entry['subject'],
            'unitSlug' => $entry['unit'],
        ]);
    }

    /**
     * @return array{grade: int, subject: string, unit: string, topic: string|null, at: int}|null
     */
    private function pending(SessionInterface $session): ?array
    {
        $raw = $session->get(self::SESSION_KEY);
        if (!\is_array($raw)) {
            return null;
        }
        $grade = $raw['grade'] ?? null;
        $subject = $raw['subject'] ?? null;
        $unit = $raw['unit'] ?? null;
        $topic = $raw['topic'] ?? null;
        $at = $raw['at'] ?? null;
        if (!\is_int($grade) || !$this->isSlug($subject) || !$this->isSlug($unit) || (null !== $topic && !$this->isSlug($topic)) || !\is_int($at)) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - $at > self::TTL_SECONDS) {
            return null;
        }

        return ['grade' => $grade, 'subject' => $subject, 'unit' => $unit, 'topic' => $topic, 'at' => $at];
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isSlug(mixed $value): bool
    {
        return \is_string($value) && \strlen($value) <= 160 && 1 === preg_match(self::SLUG, $value);
    }

    private function gradeLevel(mixed $value): ?GradeLevel
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]?$/', (string) $value)) {
            return null;
        }

        return GradeLevel::tryFrom((int) $value);
    }
}

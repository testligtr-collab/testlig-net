<?php

declare(strict_types=1);

namespace App\Service\CatalogImport;

use App\Dto\CatalogSourceAttribution;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Service\CatalogSlugger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Idempotent MEB catalog import (draft-only; no publish/archive/delete).
 */
final class CatalogImportService
{
    private const LOCK_KEY = 'app.catalog.import';

    public function __construct(
        private readonly CatalogImportYamlLoader $loader,
        private readonly EntityManagerInterface $em,
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
        private readonly CatalogSlugger $slugger,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function import(string $absolutePath, bool $apply, bool $updateExisting): CatalogImportResult
    {
        $document = $this->loader->loadFile($absolutePath);
        $result = new CatalogImportResult();
        $result->dryRun = !$apply;
        $result->applied = false;
        $result->line(\sprintf(
            'Fixture: subject=%s grade=%d units=%d topics=%d version=%s',
            $document->subjectName,
            $document->gradeLevel->value,
            \count($document->units),
            $document->topicCount(),
            $document->sourceVersion,
        ));

        $lock = $this->lockFactory->createLock(self::LOCK_KEY, 300.0);
        if (!$lock->acquire()) {
            throw CatalogException::conflict('Katalog içe aktarma kilidi alınamadı; başka bir import çalışıyor olabilir.');
        }

        try {
            if (!$apply) {
                $this->plan($document, $updateExisting, $result);

                return $result;
            }

            $this->em->wrapInTransaction(function () use ($document, $updateExisting, $result): void {
                $this->applyDocument($document, $updateExisting, $result);
            });
            $result->applied = true;
            $result->dryRun = false;
        } finally {
            $lock->release();
        }

        return $result;
    }

    private function plan(CatalogImportDocument $document, bool $updateExisting, CatalogImportResult $result): void
    {
        $subjectAttr = $document->subjectAttribution();
        $existingSubject = $this->subjects->findOneBySourceIdentity($subjectAttr->version, $subjectAttr->code, $subjectAttr->occurrence);
        $this->planEntity('subject', $document->subjectName, $subjectAttr, $existingSubject, $updateExisting, $result, static function (CatalogSubject $s) use ($document): bool {
            return $s->getName() !== $document->subjectName
                || $s->getPosition() !== $document->subjectPosition
                || $s->getSourceUrl() !== $document->programUrl;
        });

        foreach ($document->units as $unitNode) {
            $unitAttr = $unitNode->attribution($document->sourceVersion, $document->programUrl);
            $existingUnit = $this->units->findOneBySourceIdentity($unitAttr->version, $unitAttr->code, $unitAttr->occurrence);
            $this->planEntity('unit', $unitNode->name, $unitAttr, $existingUnit, $updateExisting, $result, static function (CatalogUnit $u) use ($unitNode, $document): bool {
                return $u->getName() !== $unitNode->name
                    || $u->getPosition() !== $unitNode->position
                    || $u->getSourceUrl() !== $document->programUrl;
            });

            foreach ($unitNode->topics as $topicNode) {
                $topicAttr = $topicNode->attribution($document->sourceVersion, $document->programUrl);
                $existingTopic = $this->topics->findOneBySourceIdentity($topicAttr->version, $topicAttr->code, $topicAttr->occurrence);
                $this->planEntity('topic', $topicNode->name, $topicAttr, $existingTopic, $updateExisting, $result, static function (CatalogTopic $t) use ($topicNode, $document): bool {
                    return $t->getName() !== $topicNode->name
                        || $t->getPosition() !== $topicNode->position
                        || $t->getSourceUrl() !== $document->programUrl
                        || null !== $t->getEstimatedMinutes();
                });
            }
        }
    }

    /**
     * @param callable(mixed):bool $needsUpdate
     */
    private function planEntity(
        string $kind,
        string $name,
        CatalogSourceAttribution $attr,
        mixed $existing,
        bool $updateExisting,
        CatalogImportResult $result,
        callable $needsUpdate,
    ): void {
        $id = \sprintf('%s@%d', (string) $attr->code, $attr->occurrence);
        if (!$existing instanceof CatalogSubject && !$existing instanceof CatalogUnit && !$existing instanceof CatalogTopic) {
            ++$result->created;
            $result->line(\sprintf('[create] %s %s (%s)', $kind, $name, $id));

            return;
        }
        if ($needsUpdate($existing)) {
            if ($updateExisting) {
                ++$result->updated;
                $result->line(\sprintf('[update] %s %s (%s)', $kind, $name, $id));
            } else {
                ++$result->skipped;
                $result->line(\sprintf('[skip-changed] %s %s (%s) — pass --update-existing to apply name/position/source_url', $kind, $name, $id));
            }

            return;
        }
        ++$result->skipped;
        $result->line(\sprintf('[skip] %s %s (%s)', $kind, $name, $id));
    }

    private function applyDocument(CatalogImportDocument $document, bool $updateExisting, CatalogImportResult $result): void
    {
        $subjectAttr = $document->subjectAttribution();
        $subject = $this->subjects->findOneBySourceIdentity($subjectAttr->version, $subjectAttr->code, $subjectAttr->occurrence);
        if (!$subject instanceof CatalogSubject) {
            $slug = $this->slugger->slugify($document->subjectName);
            if ($this->subjects->existsSlugForGrade($document->gradeLevel, $slug)) {
                ++$result->conflicts;
                $result->line('[conflict] subject slug already used for grade: '.$slug);
                throw CatalogException::conflict('Ders slug çakışması: '.$slug);
            }
            $subject = CatalogSubject::createDraft(
                $document->gradeLevel,
                $document->subjectName,
                $slug,
                null,
                $document->subjectPosition,
                $this->clock->now(),
                null,
                $subjectAttr,
            );
            $this->subjects->save($subject, false);
            ++$result->created;
            $result->line('[create] subject '.$document->subjectName);
        } else {
            $this->maybeUpdateSubject($subject, $document, $subjectAttr, $updateExisting, $result);
        }

        foreach ($document->units as $unitNode) {
            $unitAttr = $unitNode->attribution($document->sourceVersion, $document->programUrl);
            $unit = $this->units->findOneBySourceIdentity($unitAttr->version, $unitAttr->code, $unitAttr->occurrence);
            if (!$unit instanceof CatalogUnit) {
                $slug = $this->slugger->slugify($unitNode->name);
                if ($this->units->existsSlugForSubject($subject, $slug)) {
                    ++$result->conflicts;
                    $result->line('[conflict] unit slug already used: '.$slug);
                    throw CatalogException::conflict('Ünite slug çakışması: '.$slug);
                }
                $unit = CatalogUnit::createDraft(
                    $subject,
                    $unitNode->name,
                    $slug,
                    null,
                    $unitNode->position,
                    $this->clock->now(),
                    null,
                    $unitAttr,
                );
                $this->units->save($unit, false);
                ++$result->created;
                $result->line('[create] unit '.$unitNode->name);
            } else {
                if ($unit->getSubject()->getId()->toRfc4122() !== $subject->getId()->toRfc4122()) {
                    ++$result->conflicts;
                    $result->line('[conflict] unit source identity bound to another subject: '.$unitNode->sourceCode.'@'.$unitNode->occurrence);
                    throw CatalogException::conflict('Ünite başka bir derse bağlı.');
                }
                $this->maybeUpdateUnit($unit, $unitNode, $unitAttr, $updateExisting, $result);
            }

            foreach ($unitNode->topics as $topicNode) {
                $topicAttr = $topicNode->attribution($document->sourceVersion, $document->programUrl);
                $topic = $this->topics->findOneBySourceIdentity($topicAttr->version, $topicAttr->code, $topicAttr->occurrence);
                if (!$topic instanceof CatalogTopic) {
                    $slug = $this->slugger->slugify($topicNode->name, CatalogTopic::SLUG_MAX);
                    if ($this->topics->existsSlugForUnit($unit, $slug)) {
                        ++$result->conflicts;
                        $result->line('[conflict] topic slug already used: '.$slug);
                        throw CatalogException::conflict('Konu slug çakışması: '.$slug);
                    }
                    $topic = CatalogTopic::createDraft(
                        $unit,
                        $topicNode->name,
                        $slug,
                        null,
                        $topicNode->position,
                        null,
                        $this->clock->now(),
                        null,
                        $topicAttr,
                    );
                    $this->topics->save($topic, false);
                    ++$result->created;
                    $result->line('[create] topic '.$topicNode->name);
                } else {
                    if ($topic->getUnit()->getId()->toRfc4122() !== $unit->getId()->toRfc4122()) {
                        ++$result->conflicts;
                        $result->line('[conflict] topic source identity bound to another unit: '.$topicNode->sourceCode);
                        throw CatalogException::conflict('Konu başka bir üniteye bağlı.');
                    }
                    $this->maybeUpdateTopic($topic, $topicNode, $topicAttr, $updateExisting, $result);
                }
            }
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            ++$result->errors;
            throw CatalogException::conflict('Benzersizlik kısıtı ihlali; işlem geri alındı.');
        }
    }

    private function maybeUpdateSubject(
        CatalogSubject $subject,
        CatalogImportDocument $document,
        CatalogSourceAttribution $attr,
        bool $updateExisting,
        CatalogImportResult $result,
    ): void {
        $changed = $subject->getName() !== $document->subjectName
            || $subject->getPosition() !== $document->subjectPosition
            || $subject->getSourceUrl() !== $document->programUrl;
        if (!$changed) {
            ++$result->skipped;
            $result->line('[skip] subject '.$document->subjectName);

            return;
        }
        if (!$updateExisting) {
            ++$result->skipped;
            $result->line('[skip-changed] subject '.$document->subjectName);

            return;
        }
        $slug = $this->slugger->slugify($document->subjectName);
        if ($this->subjects->existsSlugForGrade($document->gradeLevel, $slug, $subject->getId())) {
            ++$result->conflicts;
            throw CatalogException::conflict('Ders slug çakışması (update).');
        }
        $subject->updateDetails($document->subjectName, $slug, null, $document->subjectPosition, $this->clock->now());
        $subject->assignSourceAttribution($attr, $this->clock->now());
        ++$result->updated;
        $result->line('[update] subject '.$document->subjectName);
    }

    private function maybeUpdateUnit(
        CatalogUnit $unit,
        CatalogImportUnitNode $node,
        CatalogSourceAttribution $attr,
        bool $updateExisting,
        CatalogImportResult $result,
    ): void {
        $changed = $unit->getName() !== $node->name
            || $unit->getPosition() !== $node->position
            || $unit->getSourceUrl() !== $attr->url;
        if (!$changed) {
            ++$result->skipped;
            $result->line('[skip] unit '.$node->name);

            return;
        }
        if (!$updateExisting) {
            ++$result->skipped;
            $result->line('[skip-changed] unit '.$node->name);

            return;
        }
        $slug = $this->slugger->slugify($node->name);
        if ($this->units->existsSlugForSubject($unit->getSubject(), $slug, $unit->getId())) {
            ++$result->conflicts;
            throw CatalogException::conflict('Ünite slug çakışması (update).');
        }
        $unit->updateDetails($node->name, $slug, null, $node->position, $this->clock->now());
        $unit->assignSourceAttribution($attr, $this->clock->now());
        ++$result->updated;
        $result->line('[update] unit '.$node->name);
    }

    private function maybeUpdateTopic(
        CatalogTopic $topic,
        CatalogImportTopicNode $node,
        CatalogSourceAttribution $attr,
        bool $updateExisting,
        CatalogImportResult $result,
    ): void {
        $changed = $topic->getName() !== $node->name
            || $topic->getPosition() !== $node->position
            || $topic->getSourceUrl() !== $attr->url
            || null !== $topic->getEstimatedMinutes();
        if (!$changed) {
            ++$result->skipped;
            $result->line('[skip] topic '.$node->name);

            return;
        }
        if (!$updateExisting) {
            ++$result->skipped;
            $result->line('[skip-changed] topic '.$node->name);

            return;
        }
        $slug = $this->slugger->slugify($node->name, CatalogTopic::SLUG_MAX);
        if ($this->topics->existsSlugForUnit($topic->getUnit(), $slug, $topic->getId())) {
            ++$result->conflicts;
            throw CatalogException::conflict('Konu slug çakışması (update).');
        }
        $topic->updateDetails($node->name, $slug, null, $node->position, null, $this->clock->now());
        $topic->assignSourceAttribution($attr, $this->clock->now());
        ++$result->updated;
        $result->line('[update] topic '.$node->name);
    }
}

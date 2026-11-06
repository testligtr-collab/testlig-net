<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AdminAcademicYearCreateRequest
{
    public const REASON_ONBOARDING = 'institution_onboarding';
    public const REASON_OPERATOR_SETUP = 'operator_setup';
    public const REASON_MIGRATION_IMPORT = 'migration_import';

    #[Assert\NotBlank(message: 'Bağlı kurum gerekli.')]
    #[Assert\Uuid(message: 'Bağlı kurum geçersiz.')]
    public string $institutionId = '';

    #[Assert\NotBlank(message: 'Yıl adı gerekli.')]
    #[Assert\Length(min: 2, max: 180, minMessage: 'Yıl adı en az {{ limit }} karakter olmalı.', maxMessage: 'Yıl adı en fazla {{ limit }} karakter olabilir.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Başlangıç tarihi gerekli.')]
    #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'Başlangıç tarihi geçerli olmalıdır.')]
    public string $startsOn = '';

    #[Assert\NotBlank(message: 'Bitiş tarihi gerekli.')]
    #[Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/', message: 'Bitiş tarihi geçerli olmalıdır.')]
    public string $endsOn = '';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(choices: [
            self::REASON_ONBOARDING,
            self::REASON_OPERATOR_SETUP,
            self::REASON_MIGRATION_IMPORT,
        ], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Yıl oluşturmayı onaylamanız gerekir.')]
    public bool $confirm = false;

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->startsOn) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->endsOn)) {
            return;
        }
        $starts = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->startsOn);
        $ends = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->endsOn);
        if (!$starts instanceof \DateTimeImmutable || $starts->format('Y-m-d') !== $this->startsOn) {
            $context->buildViolation('Başlangıç tarihi geçerli olmalıdır.')->atPath('startsOn')->addViolation();

            return;
        }
        if (!$ends instanceof \DateTimeImmutable || $ends->format('Y-m-d') !== $this->endsOn) {
            $context->buildViolation('Bitiş tarihi geçerli olmalıdır.')->atPath('endsOn')->addViolation();

            return;
        }
        if ($starts > $ends) {
            $context->buildViolation('Bitiş tarihi başlangıçtan önce olamaz.')->atPath('endsOn')->addViolation();
        }
    }
}

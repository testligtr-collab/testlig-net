<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Classroom;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AdminClassroomReviseRequest
{
    #[Assert\NotBlank(message: 'Sınıf adı gerekli.')]
    #[Assert\Length(max: 180, maxMessage: 'Sınıf adı en fazla {{ limit }} karakter olabilir.')]
    public string $name = '';

    public string $capacity = '';

    #[Assert\NotBlank(message: 'Kayıt sürümü gerekli.')]
    #[Assert\Regex(pattern: '/^\d+$/', message: 'Kayıt sürümü geçersiz.')]
    public string $expectedUpdatedAt = '';

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'İşlem gerekçesi gerekli.'),
        new Assert\Choice(callback: [AdminClassroomCreateRequest::class, 'reasonChoices'], message: 'İşlem gerekçesi geçersiz.'),
    ])]
    public string $reasonCode = '';

    #[Assert\IsTrue(message: 'Değişikliği onaylamanız gerekir.')]
    public bool $confirm = false;

    #[Assert\Callback]
    public function validateCapacity(ExecutionContextInterface $context): void
    {
        $value = trim($this->capacity);
        if ('' === $value) {
            return;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $value)) {
            $context->buildViolation('Kontenjan 1 ile '.Classroom::CAPACITY_MAX.' arasında olmalı.')->atPath('capacity')->addViolation();

            return;
        }
        $capacity = (int) $value;
        if ($capacity < 1 || $capacity > Classroom::CAPACITY_MAX) {
            $context->buildViolation('Kontenjan 1 ile '.Classroom::CAPACITY_MAX.' arasında olmalı.')->atPath('capacity')->addViolation();
        }
    }
}

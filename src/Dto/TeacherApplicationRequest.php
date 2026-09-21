<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Teacher onboarding confirm step (no document fields in this slice).
 */
final class TeacherApplicationRequest
{
    #[Assert\IsTrue(message: 'Başvurunun inceleneceğini ve erişimin henüz açılmadığını onaylamanız gerekir.')]
    public bool $acknowledgePendingReview = false;
}

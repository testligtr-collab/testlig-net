<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\ParentStudentLink;
use App\Entity\PersonalInvitation;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * One-shot issuance payload. Plain code is never persisted; callers must deliver it
 * out-of-band. This DTO must not be logged or audited as a whole.
 */
final class ParentStudentLinkIssuanceResult
{
    public function __construct(
        public readonly ParentStudentLink $link,
        public readonly PersonalInvitation $invitation,
        #[\SensitiveParameter]
        #[Ignore]
        public readonly string $plainCode,
    ) {
    }
}

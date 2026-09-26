<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assessment;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\UserRole;

/**
 * Who may read the platform result report.
 *
 * Platform Admin and SuperAdmin may read platform assessments.
 * Teacher, HeadTeacher, and ExpertTeacher stay closed: platform practice
 * deliveries have no classroom or course assignment the attempt voter can prove.
 * Moderator, student, and parent are denied. Institution assessments are not listed here.
 */
final class AssessmentResultReportGate
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeUsers,
    ) {
    }

    public function canRead(User $actor, Assessment $assessment): bool
    {
        if (AssessmentScope::Platform !== $assessment->getScope()) {
            return false;
        }
        if (!$this->activeUsers->isActiveAndVerified($actor)) {
            return false;
        }

        $roles = $actor->getRoles();

        return \in_array(UserRole::SuperAdmin->value, $roles, true)
            || \in_array(UserRole::Admin->value, $roles, true);
    }
}

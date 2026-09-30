<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Route names for the post-login matrix. Paths stay on the controllers.
 */
final class PostLoginRoute
{
    public const STUDENT_HOME = 'app_student_dashboard';

    public const STUDENT_ONBOARDING = 'app_student_onboarding';

    public const PARENT_HOME = 'app_parent_dashboard';

    public const INSTITUTION_HOME = 'app_institution_overview';

    public const ADMIN_HOME = 'app_admin_dashboard';

    public const WORKSPACE_HOME = 'app_workspace_dashboard';

    public const ACCOUNT_HOME = 'app_account';
}

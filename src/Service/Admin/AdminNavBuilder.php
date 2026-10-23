<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Presentation\AdminGreetingName;
use App\Presentation\AdminIconCatalog;
use App\Presentation\WorkspaceRoleLabels;
use App\Security\AdminAuthorization;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Builds permission-filtered admin sidebar / mobile nav items.
 *
 * Group labels are presentation only. An item appears only when the existing
 * AdminAuthorization gate already allows that surface.
 */
final class AdminNavBuilder
{
    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly AdminIconCatalog $icons,
        private readonly ContentWorkspaceSummary $workspaceSummary,
        private readonly AdminGreetingName $greetingName,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @return array{
     *     nav_items: list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}>,
     *     nav_sections: list<array{id: string, label: string, open: bool, items: list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}>}>,
     *     mobile_nav: list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}>,
     *     panel_role_label: string,
     *     display_name: string,
     *     greeting_name: string,
     *     avatar_initials: string,
     *     workspace_shell: bool
     * }
     */
    public function build(User $actor): array
    {
        $currentPath = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
        $isSa = $this->adminAuthorization->canOperatePayments($actor);
        $canAudit = $this->adminAuthorization->canViewSecurityAudit($actor);
        $canShell = $this->adminAuthorization->canAccessAdminShell($actor);
        $canContent = $this->adminAuthorization->canViewLearningContentWorkspace($actor);
        $classroomCount = $this->workspaceSummary->activeClassroomCount($actor);
        $workspaceShell = !$canShell && ($canContent || $classroomCount > 0);

        $canUsers = $this->adminAuthorization->canViewUsers($actor);
        $canInstitutions = $this->adminAuthorization->canViewInstitutions($actor);

        $items = [];
        if ($canShell) {
            $items[] = $this->item('dashboard', 'Genel Bakış', 'app_admin_dashboard', $currentPath, null, true);
            $items[] = $this->item('system', 'Sistem', 'app_admin_system', $currentPath);
        } elseif ($workspaceShell) {
            $items[] = $this->item('dashboard', 'Çalışma alanı', 'app_workspace_dashboard', $currentPath);
        }

        if ($canUsers) {
            $items[] = $this->item('users', 'Kullanıcılar', 'app_admin_users', $currentPath, '/yonetim/kullanicilar');
            $items[] = $this->item('teachers', 'Öğretmenler', 'app_admin_teachers', $currentPath, '/yonetim/ogretmenler', false, 'users');
        }
        if ($this->adminAuthorization->canManageMemberships($actor)) {
            $items[] = $this->item('teacher_applications', 'Öğretmen başvuruları', 'app_admin_teacher_applications', $currentPath, '/yonetim/ogretmen-basvurulari');
            $items[] = $this->item('institution_applications', 'Kurum başvuruları', 'app_admin_institution_applications', $currentPath, '/yonetim/kurum-basvurulari');
        }
        if ($canInstitutions) {
            $items[] = $this->item('institutions', 'Kurumlar', 'app_admin_institutions', $currentPath, '/yonetim/kurumlar');
        }

        if ($this->adminAuthorization->canViewCatalog($actor)) {
            $items[] = $this->item('catalog', 'Müfredat', 'app_admin_catalog', $currentPath, '/yonetim/mufredat');
        }

        if ($this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            $items[] = $this->item('learning_contents', 'İçerikler', 'app_admin_learning_contents', $currentPath, '/yonetim/icerikler');
        }

        if ($this->adminAuthorization->canViewQuestionBank($actor)) {
            $items[] = $this->item('questions', 'Sorular', 'app_admin_questions', $currentPath, '/yonetim/sorular');
        }

        if ($this->adminAuthorization->canViewTestBank($actor)) {
            $items[] = $this->item('tests', 'Testler', 'app_admin_tests', $currentPath, '/yonetim/testler');
        }
        if ($this->adminAuthorization->canManageNews($actor)) {
            $items[] = $this->item('news', 'Haberler', 'app_admin_news', $currentPath, '/yonetim/haberler');
        }
        if ($classroomCount > 0) {
            $items[] = $this->item('classrooms', 'Sınıflarım', 'app_teacher_classrooms', $currentPath, '/ogretmen/siniflarim');
        }
        if ($this->holdsTeacherProfile($actor)) {
            $items[] = $this->item('teacher_profile', 'Mesleki profil', 'app_teacher_profile', $currentPath, '/ogretmen/profil');
        }

        if ($isSa) {
            $items[] = $this->item('payments', 'Ödemeler', 'app_admin_payments', $currentPath, '/yonetim/odemeler');
            $items[] = $this->item('webhooks', 'Webhook', 'app_admin_webhooks', $currentPath, '/yonetim/webhook');
            $items[] = $this->item('reconciliations', 'Uzlaştırma', 'app_admin_reconciliations', $currentPath, '/yonetim/uzlastirma');
        }
        if ($canAudit) {
            $items[] = $this->item('audit', 'Denetim', 'app_admin_audit', $currentPath);
        }
        if ($this->adminAuthorization->canViewPlannedAdminSections($actor)) {
            foreach (AdminSectionCatalog::all() as $section) {
                $items[] = $this->plannedItem($section['id'], $section['label'], $section['key'], $currentPath);
            }
        }

        $items[] = $this->item('account', 'Hesabım', 'app_account', $currentPath);
        $items = $this->singleCurrent($items);

        return [
            'nav_items' => $items,
            'nav_sections' => $this->sections($items, $workspaceShell),
            'mobile_nav' => $workspaceShell ? $items : [],
            'panel_role_label' => $isSa ? 'Süper Yönetici' : ($canShell ? 'Yönetici' : WorkspaceRoleLabels::content($actor->getRoles())),
            'display_name' => trim($actor->getFirstName().' '.$actor->getLastName()),
            'greeting_name' => $this->greetingName->friendlyFirstName($actor->getFirstName()) ?? '',
            'avatar_initials' => $this->initials($actor),
            'workspace_shell' => $workspaceShell,
        ];
    }

    /**
     * @param list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}> $items
     *
     * @return list<array{id: string, label: string, open: bool, items: list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}>}>
     */
    private function sections(array $items, bool $workspaceShell): array
    {
        $byId = [];
        foreach ($items as $item) {
            if ('account' === $item['id']) {
                continue;
            }
            $byId[$item['id']] = $item;
        }

        $groups = $workspaceShell
            ? [
                ['id' => 'general', 'label' => 'Genel', 'ids' => ['dashboard', 'system']],
                ['id' => 'management', 'label' => 'Yönetim', 'ids' => ['users', 'teacher_applications', 'institution_applications', 'institutions']],
                ['id' => 'education', 'label' => 'Eğitim', 'ids' => ['catalog', 'learning_contents', 'questions', 'tests', 'classrooms', 'teacher_profile']],
                ['id' => 'operations', 'label' => 'Operasyon', 'ids' => ['payments', 'webhooks', 'reconciliations', 'audit']],
            ]
            : [
                ['id' => 'overview', 'label' => 'Genel Bakış', 'ids' => ['dashboard']],
                ['id' => 'people', 'label' => 'Kullanıcılar ve Yetkiler', 'ids' => ['users', 'teacher_applications', 'institution_applications', 'teachers', 'role_permissions']],
                ['id' => 'campus', 'label' => 'Kurum ve Sınıf', 'ids' => ['institutions', 'branches', 'academic_years', 'classes', 'classrooms']],
                ['id' => 'learning', 'label' => 'Eğitim', 'ids' => ['catalog', 'learning_contents', 'questions', 'tests', 'assignments', 'reports', 'teacher_profile']],
                ['id' => 'media', 'label' => 'Medya', 'ids' => ['storage', 'documents', 'videos']],
                ['id' => 'interactive', 'label' => 'Etkileşimli Eğitim', 'ids' => ['simulations', 'games', 'live_lessons', 'qna', 'guidance', 'gamification']],
                ['id' => 'communication', 'label' => 'İletişim', 'ids' => ['messages', 'notifications', 'support']],
                ['id' => 'site', 'label' => 'Site Yönetimi', 'ids' => ['news', 'pages', 'home_sections', 'menu_footer', 'seo', 'traffic_ads']],
                ['id' => 'commerce', 'label' => 'Ticaret', 'ids' => ['packages', 'orders', 'coupons', 'payments', 'webhooks', 'reconciliations']],
                ['id' => 'integrations', 'label' => 'Entegrasyonlar', 'ids' => ['sms', 'ai', 'identity_providers', 'mobile_api']],
                ['id' => 'system', 'label' => 'Sistem', 'ids' => ['system', 'audit', 'operations']],
            ];

        $sections = [];
        foreach ($groups as $group) {
            $adminLabels = [
                'learning_contents' => 'Öğrenme içerikleri',
                'questions' => 'Soru havuzu',
                'tests' => 'Test ve sınavlar',
                'system' => 'Sistem durumu',
            ];
            $grouped = [];
            foreach ($group['ids'] as $id) {
                if (!isset($byId[$id])) {
                    continue;
                }
                $item = $byId[$id];
                if (!$workspaceShell && isset($adminLabels[$id])) {
                    $item['label'] = $adminLabels[$id];
                }
                $grouped[] = $item;
            }
            if ([] === $grouped) {
                continue;
            }
            $open = false;
            foreach ($grouped as $item) {
                if ($item['current']) {
                    $open = true;
                }
            }
            $sections[] = [
                'id' => $group['id'],
                'label' => $group['label'],
                'open' => $open,
                'items' => $grouped,
            ];
        }

        return $sections;
    }

    /**
     * @return array{id: string, label: string, href: string, current: bool, icon: string, stage: string}
     */
    private function item(string $id, string $label, string $route, string $currentPath, ?string $pathPrefix = null, bool $exact = false, ?string $icon = null): array
    {
        $href = $this->urlGenerator->generate($route);
        $prefix = rtrim($pathPrefix ?? $href, '/');
        $current = $exact
            ? $currentPath === $href
            : ($currentPath === $prefix || str_starts_with($currentPath, $prefix.'/'));

        return [
            'id' => $id,
            'label' => $label,
            'href' => $href,
            'current' => $current,
            'icon' => $this->icons->resolve($icon ?? $id),
            'stage' => 'ready',
        ];
    }

    /**
     * @return array{id: string, label: string, href: string, current: bool, icon: string, stage: string}
     */
    private function plannedItem(string $id, string $label, string $key, string $currentPath): array
    {
        $href = $this->urlGenerator->generate('app_admin_section', ['key' => $key]);

        return [
            'id' => $id,
            'label' => $label,
            'href' => $href,
            'current' => $currentPath === $href,
            'icon' => $this->icons->resolve('default'),
            'stage' => 'planned',
        ];
    }

    /**
     * @param list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}> $items
     *
     * @return list<array{id: string, label: string, href: string, current: bool, icon: string, stage: string}>
     */
    private function singleCurrent(array $items): array
    {
        $winner = null;
        $winnerLength = -1;
        foreach ($items as $index => $item) {
            if (!$item['current']) {
                continue;
            }
            $length = \strlen($item['href']);
            if ($length > $winnerLength) {
                $winner = $index;
                $winnerLength = $length;
            }
        }
        if (null === $winner) {
            return $items;
        }
        foreach ($items as $index => $item) {
            $items[$index]['current'] = $index === $winner;
        }

        return $items;
    }

    private function holdsTeacherProfile(User $actor): bool
    {
        return \in_array(
            UserRole::Teacher->value,
            $this->roleHierarchy->getReachableRoleNames($actor->getRoles()),
            true,
        );
    }

    private function initials(User $actor): string
    {
        $first = mb_substr($actor->getFirstName(), 0, 1);
        $last = mb_substr($actor->getLastName(), 0, 1);
        $pair = mb_strtoupper($first.$last);

        return '' !== $pair ? $pair : 'YN';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Presentation\AdminIconCatalog;
use App\Presentation\WorkspaceRoleLabels;
use App\Security\AdminAuthorization;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
    ) {
    }

    /**
     * @return array{
     *     nav_items: list<array{id: string, label: string, href: string, current: bool, icon: string}>,
     *     nav_sections: list<array{id: string, label: string, items: list<array{id: string, label: string, href: string, current: bool, icon: string}>}>,
     *     mobile_nav: list<array{id: string, label: string, href: string, current: bool, icon: string}>,
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
            $items[] = $this->item('dashboard', 'Genel Bakış', 'app_admin_dashboard', $currentPath);
            $items[] = $this->item('system', 'Sistem', 'app_admin_system', $currentPath);
        } elseif ($workspaceShell) {
            $items[] = $this->item('dashboard', 'Çalışma alanı', 'app_workspace_dashboard', $currentPath);
        }

        if ($canUsers) {
            $items[] = $this->item('users', 'Kullanıcılar', 'app_admin_users', $currentPath, '/yonetim/kullanicilar');
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
        if ($classroomCount > 0) {
            $items[] = $this->item('classrooms', 'Sınıflarım', 'app_teacher_classrooms', $currentPath, '/ogretmen/siniflarim');
        }

        if ($isSa) {
            $items[] = $this->item('payments', 'Ödemeler', 'app_admin_payments', $currentPath, '/yonetim/odemeler');
            $items[] = $this->item('webhooks', 'Webhook', 'app_admin_webhooks', $currentPath, '/yonetim/webhook');
            $items[] = $this->item('reconciliations', 'Uzlaştırma', 'app_admin_reconciliations', $currentPath, '/yonetim/uzlastirma');
        }
        if ($canAudit) {
            $items[] = $this->item('audit', 'Denetim', 'app_admin_audit', $currentPath);
        }

        $items[] = $this->item('account', 'Hesabım', 'app_account', $currentPath);

        return [
            'nav_items' => $items,
            'nav_sections' => $this->sections($items),
            'mobile_nav' => $items,
            'panel_role_label' => $isSa ? 'Süper Yönetici' : ($canShell ? 'Yönetici' : WorkspaceRoleLabels::content($actor->getRoles())),
            'display_name' => trim($actor->getFirstName().' '.$actor->getLastName()),
            'greeting_name' => $this->greetingName($actor),
            'avatar_initials' => $this->initials($actor),
            'workspace_shell' => $workspaceShell,
        ];
    }

    /**
     * @param list<array{id: string, label: string, href: string, current: bool, icon: string}> $items
     *
     * @return list<array{id: string, label: string, items: list<array{id: string, label: string, href: string, current: bool, icon: string}>}>
     */
    private function sections(array $items): array
    {
        $byId = [];
        foreach ($items as $item) {
            if ('account' === $item['id']) {
                continue;
            }
            $byId[$item['id']] = $item;
        }

        $groups = [
            ['id' => 'general', 'label' => 'Genel', 'ids' => ['dashboard', 'system']],
            ['id' => 'management', 'label' => 'Yönetim', 'ids' => ['users', 'institutions']],
            ['id' => 'education', 'label' => 'Eğitim', 'ids' => ['catalog', 'learning_contents', 'questions', 'tests', 'classrooms']],
            ['id' => 'operations', 'label' => 'Operasyon', 'ids' => ['payments', 'webhooks', 'reconciliations', 'audit']],
        ];

        $sections = [];
        foreach ($groups as $group) {
            $grouped = [];
            foreach ($group['ids'] as $id) {
                if (isset($byId[$id])) {
                    $grouped[] = $byId[$id];
                }
            }
            if ([] === $grouped) {
                continue;
            }
            $sections[] = [
                'id' => $group['id'],
                'label' => $group['label'],
                'items' => $grouped,
            ];
        }

        return $sections;
    }

    /**
     * @return array{id: string, label: string, href: string, current: bool, icon: string}
     */
    private function item(string $id, string $label, string $route, string $currentPath, ?string $pathPrefix = null): array
    {
        $href = $this->urlGenerator->generate($route);
        $prefix = $pathPrefix ?? $href;
        $current = $currentPath === $href || str_starts_with($currentPath, rtrim($prefix, '/').'/')
            || $currentPath === rtrim($prefix, '/');

        return [
            'id' => $id,
            'label' => $label,
            'href' => $href,
            'current' => $current,
            'icon' => $this->icons->resolve($id),
        ];
    }

    private function greetingName(User $actor): string
    {
        $first = trim($actor->getFirstName());

        return '' !== $first ? $first : 'yönetici';
    }

    private function initials(User $actor): string
    {
        $first = mb_substr($actor->getFirstName(), 0, 1);
        $last = mb_substr($actor->getLastName(), 0, 1);
        $pair = mb_strtoupper($first.$last);

        return '' !== $pair ? $pair : 'YN';
    }
}

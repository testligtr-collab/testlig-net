<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Security\AdminAuthorization;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds permission-filtered admin sidebar / mobile nav items.
 */
final class AdminNavBuilder
{
    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array{
     *     nav_items: list<array{id: string, label: string, href: string, current: bool}>,
     *     mobile_nav: list<array{id: string, label: string, href: string, current: bool}>,
     *     panel_role_label: string,
     *     display_name: string,
     *     avatar_initials: string
     * }
     */
    public function build(User $actor): array
    {
        $currentPath = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
        $isSa = $this->adminAuthorization->canOperatePayments($actor);
        $canAudit = $this->adminAuthorization->canViewSecurityAudit($actor);

        $canUsers = $this->adminAuthorization->canViewUsers($actor);
        $canInstitutions = $this->adminAuthorization->canViewInstitutions($actor);

        $items = [
            $this->item('dashboard', 'Özet', 'app_admin_dashboard', $currentPath),
            $this->item('system', 'Sistem', 'app_admin_system', $currentPath),
        ];

        if ($canUsers) {
            $items[] = $this->item('users', 'Kullanıcılar', 'app_admin_users', $currentPath, '/yonetim/kullanicilar');
        }
        if ($canInstitutions) {
            $items[] = $this->item('institutions', 'Kurumlar', 'app_admin_institutions', $currentPath, '/yonetim/kurumlar');
        }

        if ($isSa) {
            $items[] = $this->item('payments', 'Ödemeler', 'app_admin_payments', $currentPath, '/yonetim/odemeler');
            $items[] = $this->item('webhooks', 'Webhook', 'app_admin_webhooks', $currentPath, '/yonetim/webhook');
            $items[] = $this->item('reconciliations', 'Uzlaştırma', 'app_admin_reconciliations', $currentPath, '/yonetim/uzlastirma');
        }
        if ($canAudit) {
            $items[] = $this->item('audit', 'Denetim', 'app_admin_audit', $currentPath);
        }

        return [
            'nav_items' => $items,
            // Admin SA surfaces exceed the generic 4-slot panel budget; CSS uses auto-fit.
            'mobile_nav' => $items,
            'panel_role_label' => $isSa ? 'Süper Yönetici' : 'Yönetici',
            'display_name' => trim($actor->getFirstName().' '.$actor->getLastName()),
            'avatar_initials' => $this->initials($actor),
        ];
    }

    /**
     * @return array{id: string, label: string, href: string, current: bool}
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
        ];
    }

    private function initials(User $actor): string
    {
        $first = mb_substr($actor->getFirstName(), 0, 1);
        $last = mb_substr($actor->getLastName(), 0, 1);
        $pair = mb_strtoupper($first.$last);

        return '' !== $pair ? $pair : 'YN';
    }
}

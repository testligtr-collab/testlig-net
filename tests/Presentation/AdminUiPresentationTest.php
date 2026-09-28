<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\AdminFilterActivity;
use App\Presentation\AdminIconCatalog;
use App\Presentation\AdminStatusLabels;
use PHPUnit\Framework\TestCase;

final class AdminUiPresentationTest extends TestCase
{
    public function testIconCatalogFallsBackAndKeepsKnownIds(): void
    {
        $catalog = new AdminIconCatalog();

        self::assertSame('users', $catalog->resolve('users'));
        self::assertSame(AdminIconCatalog::DEFAULT, $catalog->resolve('not-an-icon'));
        self::assertSame(AdminIconCatalog::DEFAULT, $catalog->resolve('<svg>'));
        self::assertContains('payments', $catalog->allowed());
    }

    public function testStatusCodesBecomeTurkishLabelsWithSafeVariants(): void
    {
        $labels = new AdminStatusLabels();

        self::assertSame(['label' => 'Taslak', 'variant' => 'neutral'], $labels->present('draft'));
        self::assertSame(['label' => 'İncelemede', 'variant' => 'warning'], $labels->present('in_review'));
        self::assertSame(['label' => 'Yayında', 'variant' => 'success'], $labels->present('published'));
        self::assertSame(['label' => 'Arşivlenmiş', 'variant' => 'neutral'], $labels->present('archived'));
        self::assertSame(['label' => 'Aktif', 'variant' => 'success'], $labels->present('active'));
        self::assertSame(['label' => 'Askıda', 'variant' => 'warning'], $labels->present('suspended'));
        self::assertSame(['label' => 'Doğrulama bekliyor', 'variant' => 'warning'], $labels->present('pending_verification'));
        self::assertSame(['label' => 'Başarılı', 'variant' => 'success'], $labels->present('success'));
        self::assertSame(['label' => 'Uyarı', 'variant' => 'warning'], $labels->present('warning'));
        self::assertSame(['label' => 'Kullanılamıyor', 'variant' => 'danger'], $labels->present('unavailable'));
        self::assertSame(['label' => 'Sınırlı görünüm', 'variant' => 'limited'], $labels->present('limited'));
        self::assertSame(['label' => 'Bilinmiyor', 'variant' => 'neutral'], $labels->present('"><script>'));
    }

    public function testEmptyFiltersStayInactive(): void
    {
        $activity = new AdminFilterActivity();

        self::assertFalse($activity->isActive([]));
        self::assertFalse($activity->isActive(['q' => '', 'status' => null]));
        self::assertFalse($activity->isActive('q'));
        self::assertTrue($activity->isActive(['q' => 'deneme']));
        self::assertTrue($activity->isActive(['needs_reconciliation' => false]));
    }

    public function testAdminShellDoesNotTakeIconsOrRolesFromTemplates(): void
    {
        $root = \dirname(__DIR__, 2);
        $nav = (string) file_get_contents($root.'/src/Service/Admin/AdminNavBuilder.php');
        $sidebar = (string) file_get_contents($root.'/templates/admin/_sidebar.html.twig');
        $layout = (string) file_get_contents($root.'/templates/admin/layout.html.twig');
        $icon = (string) file_get_contents($root.'/templates/components/admin_icon.html.twig');

        self::assertStringContainsString('canOperatePayments', $nav);
        self::assertStringContainsString('icons->resolve', $nav);
        self::assertStringNotContainsString('is_granted', $sidebar.$layout);
        self::assertStringNotContainsString('ROLE_', $sidebar.$layout);
        self::assertStringContainsString('name not in allowed', $icon);
        self::assertStringContainsString("set name = 'default'", $icon);
    }

    public function testAdminPresentationAvoidsInlineMarkupAndSecretFields(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = [$root.'/templates/components/admin_icon.html.twig', $root.'/assets/styles/admin.css'];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates/admin'));
        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }
        $joined = '';
        foreach ($files as $file) {
            $joined .= (string) file_get_contents($file);
        }

        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=|onclick=|onerror=|onload=|javascript:|\|raw\b/i', $joined);
        self::assertStringNotContainsString('storageKey', $joined);
        self::assertStringNotContainsString('ciphertext', $joined);
        self::assertStringNotContainsString('DATABASE_URL', $joined);
        self::assertStringNotContainsString('password', strtolower($joined));

        $dashboard = (string) file_get_contents($root.'/templates/admin/dashboard.html.twig');
        $shell = (string) file_get_contents($root.'/src/Service/Admin/AdminShellMetrics.php');
        self::assertStringNotContainsString('email', strtolower($dashboard.$shell));
    }
}

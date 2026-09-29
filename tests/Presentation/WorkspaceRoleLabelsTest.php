<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Enum\UserRole;
use App\Presentation\WorkspaceRoleLabels;
use PHPUnit\Framework\TestCase;

final class WorkspaceRoleLabelsTest extends TestCase
{
    public function testContentLabelsStayOnTheFixedMap(): void
    {
        self::assertSame('Baş Öğretmen', WorkspaceRoleLabels::content([UserRole::HeadTeacher->value, UserRole::Teacher->value]));
        self::assertSame('Uzman Öğretmen', WorkspaceRoleLabels::content([UserRole::ExpertTeacher->value]));
        self::assertSame('Moderatör', WorkspaceRoleLabels::content([UserRole::Moderator->value]));
        self::assertSame('Öğretmen', WorkspaceRoleLabels::content([UserRole::Teacher->value]));
        self::assertSame('İçerik', WorkspaceRoleLabels::content(['<svg>']));
    }

    public function testWorkspaceLayoutsDoNotLoadTheAdminStylesheet(): void
    {
        $root = \dirname(__DIR__, 2);
        foreach ([
            '/templates/institution/layout.html.twig',
            '/templates/teacher/layout.html.twig',
        ] as $path) {
            self::assertStringNotContainsString('styles/admin.css', (string) file_get_contents($root.$path));
        }
        $admin = (string) file_get_contents($root.'/templates/admin/layout.html.twig');
        self::assertStringContainsString('styles/workspace.css', $admin);
        self::assertStringNotContainsString('ROLE_', $admin);
    }
}

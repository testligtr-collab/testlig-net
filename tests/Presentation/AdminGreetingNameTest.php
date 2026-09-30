<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\AdminGreetingName;
use PHPUnit\Framework\TestCase;

final class AdminGreetingNameTest extends TestCase
{
    private AdminGreetingName $names;

    protected function setUp(): void
    {
        $this->names = new AdminGreetingName();
    }

    public function testPersonalFirstNameIsKept(): void
    {
        self::assertSame('Deneme', $this->names->friendlyFirstName(' Deneme '));
        self::assertSame('Hüseyin', $this->names->friendlyFirstName('Hüseyin'));
    }

    public function testRolePlaceholdersAndTechnicalTokensAreOmitted(): void
    {
        self::assertNull($this->names->friendlyFirstName(''));
        self::assertNull($this->names->friendlyFirstName('   '));
        self::assertNull($this->names->friendlyFirstName('Super'));
        self::assertNull($this->names->friendlyFirstName('super admin'));
        self::assertNull($this->names->friendlyFirstName('Yönetici'));
        self::assertNull($this->names->friendlyFirstName('user@example.com'));
        self::assertNull($this->names->friendlyFirstName('ROLE_SUPER_ADMIN'));
        self::assertNull($this->names->friendlyFirstName('01932f5a-7c2e-7c2a-8c1a-6b0d2a5c9e11'));
    }
}

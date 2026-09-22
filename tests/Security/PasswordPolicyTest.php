<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Dto\ChangePasswordRequest;
use App\Dto\RegistrationRequest;
use App\Dto\ResetPasswordRequestData;
use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordPolicyTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $validator = static::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        $this->validator = $validator;
    }

    #[DataProvider('provideAcceptablePasswords')]
    public function testAcceptsPolicyCompliantPasswords(string $password): void
    {
        $violations = $this->validator->validate($password, PasswordPolicy::constraints());
        self::assertCount(0, $violations, (string) $violations);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideAcceptablePasswords(): iterable
    {
        yield 'exactly_8_letters' => ['abcdefgh'];
        yield 'letters_only_longer' => ['sadeceharfler'];
        yield 'turkish_chars' => ['şifreliğıl'];
        yield 'passphrase_with_spaces' => ['benim gizli cumlem'];
        yield 'exactly_128' => [str_repeat('a', 128)];
        yield 'mixed_without_rules' => ['testliga'];
    }

    #[DataProvider('provideRejectedPasswords')]
    public function testRejectsOutOfRangePasswords(string $password, string $expectedMessage): void
    {
        $violations = $this->validator->validate($password, PasswordPolicy::constraints());
        self::assertGreaterThan(0, \count($violations));
        self::assertSame($expectedMessage, $violations->get(0)->getMessage());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideRejectedPasswords(): iterable
    {
        yield 'too_short_7' => ['abcdefg', PasswordPolicy::TOO_SHORT_MESSAGE];
        yield 'too_long_129' => [str_repeat('a', 129), PasswordPolicy::TOO_LONG_MESSAGE];
        yield 'blank' => ['', 'Parola zorunludur.'];
    }

    public function testRegistrationAndResetDtosShareSamePolicy(): void
    {
        $registration = new RegistrationRequest();
        $registration->firstName = 'Ayşe';
        $registration->lastName = 'Yılmaz';
        $registration->email = 'policy-reg@example.com';
        $registration->plainPassword = 'abcdefgh';
        $registration->agreeTerms = true;

        $reset = new ResetPasswordRequestData();
        $reset->plainPassword = 'abcdefgh';

        $change = new ChangePasswordRequest();
        $change->currentPassword = 'eski-parola';
        $change->newPassword = 'abcdefgh';

        self::assertCount(0, $this->validator->validate($registration));
        self::assertCount(0, $this->validator->validate($reset));
        self::assertCount(0, $this->validator->validate($change));

        $registration->plainPassword = 'abcdefg';
        $reset->plainPassword = 'abcdefg';
        $change->newPassword = 'abcdefg';

        self::assertSame(
            PasswordPolicy::TOO_SHORT_MESSAGE,
            $this->validator->validate($registration)->get(0)->getMessage(),
        );
        self::assertSame(
            PasswordPolicy::TOO_SHORT_MESSAGE,
            $this->validator->validate($reset)->get(0)->getMessage(),
        );
        self::assertSame(
            PasswordPolicy::TOO_SHORT_MESSAGE,
            $this->validator->validate($change)->get(0)->getMessage(),
        );
    }

    public function testUserPasswordAttributeUsesCustomNotBlankMessage(): void
    {
        $change = new ChangePasswordRequest();
        $change->currentPassword = 'x';
        $change->newPassword = '';
        $violations = $this->validator->validate($change);
        self::assertGreaterThan(0, \count($violations));
        self::assertSame('Yeni parola zorunludur.', $violations->get(0)->getMessage());
    }

    public function testHelpTextIsSimplified(): void
    {
        self::assertSame('En az 8 karakter kullanın.', PasswordPolicy::HELP_TEXT);
    }
}

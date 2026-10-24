<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Direct platform grants a superadmin may give a moderator.
 *
 * The catalog is fixed. Publish, archive, payment, audit, and institution
 * membership are intentionally absent.
 */
enum ModeratorModuleGrant: string
{
    case LearningContentManage = 'learning_content.manage';
    case QuestionManage = 'question.manage';
    case AssessmentAuthor = 'assessment.author';

    public function moduleLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Öğrenme içeriği',
            self::QuestionManage => 'Soru',
            self::AssessmentAuthor => 'Test ve sınav',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Platform taslağı oluşturma ve düzenleme',
            self::QuestionManage => 'Platform sorusu oluşturma ve düzenleme',
            self::AssessmentAuthor => 'Platform testi oluşturma ve taslak düzenleme',
        };
    }

    public function roleAccessLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Rolden gelen erişim: görüntüleme, inceleme ve taslağa döndürme.',
            self::QuestionManage => 'Rolden gelen erişim: görüntüleme, inceleme ve cevap anahtarını görme.',
            self::AssessmentAuthor => 'Rolden gelen erişim: görüntüleme ve inceleme.',
        };
    }

    public function protectedLabel(): string
    {
        return 'Yayın ve arşiv bu ekrandan verilmez.';
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $grant): string => $grant->value, self::cases());
    }
}

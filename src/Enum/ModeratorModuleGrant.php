<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Direct platform grants a superadmin may give a moderator.
 *
 * Content publish, payment, audit, and institution membership stay absent.
 * Curriculum view, editing, publishing, and archive are separate grants.
 */
enum ModeratorModuleGrant: string
{
    case LearningContentManage = 'learning_content.manage';
    case QuestionManage = 'question.manage';
    case AssessmentAuthor = 'assessment.author';
    case CurriculumView = 'curriculum.view';
    case CurriculumManage = 'curriculum.manage';
    case CurriculumPublish = 'curriculum.publish';
    case CurriculumArchive = 'curriculum.archive';

    public function moduleLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Öğrenme içeriği',
            self::QuestionManage => 'Soru',
            self::AssessmentAuthor => 'Test ve sınav',
            self::CurriculumView => 'Müfredat',
            self::CurriculumManage => 'Müfredat',
            self::CurriculumPublish => 'Müfredat',
            self::CurriculumArchive => 'Müfredat',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Platform taslağı oluşturma ve düzenleme',
            self::QuestionManage => 'Platform sorusu oluşturma ve düzenleme',
            self::AssessmentAuthor => 'Platform testi oluşturma ve taslak düzenleme',
            self::CurriculumView => 'Program, ünite, konu ve kazanım görüntüleme',
            self::CurriculumManage => 'Taslak program ve alt kayıt oluşturma, düzenleme',
            self::CurriculumPublish => 'Program yayınlama',
            self::CurriculumArchive => 'Arşiv, emekliye ayırma ve kullanılmamış taslağı silme',
        };
    }

    public function roleAccessLabel(): string
    {
        return match ($this) {
            self::LearningContentManage => 'Rolden gelen erişim: görüntüleme, inceleme ve taslağa döndürme.',
            self::QuestionManage => 'Rolden gelen erişim: görüntüleme, inceleme ve cevap anahtarını görme.',
            self::AssessmentAuthor => 'Rolden gelen erişim: görüntüleme ve inceleme.',
            self::CurriculumView => 'Rolden gelen müfredat erişimi yoktur.',
            self::CurriculumManage => 'Oluşturma izni yayın veya arşiv vermez.',
            self::CurriculumPublish => 'Yayın izni oluşturma veya arşiv vermez.',
            self::CurriculumArchive => 'Arşiv izni oluşturma veya yayın vermez.',
        };
    }

    public function protectedLabel(): string
    {
        return match ($this) {
            self::LearningContentManage, self::QuestionManage, self::AssessmentAuthor => 'Yayın ve arşiv bu ekrandan verilmez.',
            self::CurriculumView => 'Görüntüleme, kayıt oluşturmaz.',
            self::CurriculumManage => 'Bu izin yayın, arşiv veya silme vermez.',
            self::CurriculumPublish => 'Bu izin yalnız program yayınını kapsar.',
            self::CurriculumArchive => 'Bu izin arşiv, emekliye ayırma ve bağımlılığı olmayan taslak silmeyi kapsar.',
        };
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $grant): string => $grant->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminCurriculumProgramCreateRequest;
use App\Entity\Subject;
use App\Enum\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminCurriculumProgramCreateRequest>
 */
final class AdminCurriculumProgramCreateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_curriculum_program_create';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Subject> $subjects */
        $subjects = $options['subjects'];
        $subjectChoices = [];
        foreach ($subjects as $subject) {
            $subjectChoices[$subject->getName()] = $subject->getId()->toRfc4122();
        }
        $grades = [];
        foreach (GradeLevel::cases() as $grade) {
            $grades[$grade->value.'. sınıf'] = (string) $grade->value;
        }

        $builder
            ->add('subjectId', ChoiceType::class, [
                'label' => 'Ders',
                'choices' => $subjectChoices,
                'placeholder' => 'Ders seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'Ders geçersiz.',
            ])
            ->add('gradeLevel', ChoiceType::class, [
                'label' => 'Sınıf düzeyi',
                'choices' => $grades,
                'placeholder' => 'Sınıf düzeyi seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'Sınıf düzeyi geçersiz.',
            ])
            ->add('code', TextType::class, [
                'label' => 'Kod',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('name', TextType::class, [
                'label' => 'Ad',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('version', TextType::class, [
                'label' => 'Sürüm',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Program kurulumu' => AdminCurriculumProgramCreateRequest::REASON_SETUP,
                    'Düzeltme' => AdminCurriculumProgramCreateRequest::REASON_CORRECTION,
                ],
                'placeholder' => 'Gerekçe seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'İşlem gerekçesi geçersiz.',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Taslak program oluşturmayı onaylıyorum',
                'required' => false,
            ]);
        foreach (['subjectId', 'gradeLevel', 'reasonCode'] as $field) {
            self::keepString($builder->get($field));
        }
    }

    /**
     * @param FormBuilderInterface<string> $field
     */
    private static function keepString(FormBuilderInterface $field): void
    {
        $field->resetViewTransformers();
        $field->addViewTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): string => \is_string($value) ? $value : '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminCurriculumProgramCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_curriculum_program_create',
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Bu form fazla alan içeremez.',
            'subjects' => [],
        ]);
        $resolver->setAllowedTypes('subjects', 'array');
    }
}

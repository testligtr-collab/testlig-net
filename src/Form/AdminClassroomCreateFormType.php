<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminClassroomCreateRequest;
use App\Dto\AdminClassroomInstitutionChoice;
use App\Dto\AdminClassroomYearChoice;
use App\Enum\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminClassroomCreateRequest>
 */
final class AdminClassroomCreateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_classroom_create';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AdminClassroomInstitutionChoice> $institutions */
        $institutions = $options['institutions'];
        /** @var list<AdminClassroomYearChoice> $years */
        $years = $options['years'];
        $institutionChoices = [];
        foreach ($institutions as $institution) {
            $institutionChoices[$institution->name] = $institution->id;
        }
        $yearChoices = [];
        foreach ($years as $year) {
            $yearChoices[$year->label] = $year->id;
        }
        $gradeChoices = [];
        foreach (GradeLevel::cases() as $grade) {
            $gradeChoices[$grade->value.'. sınıf'] = (string) $grade->value;
        }
        $builder
            ->add('institutionId', ChoiceType::class, [
                'label' => 'Kurum',
                'choices' => $institutionChoices,
                'placeholder' => 'Kurum seçin',
                'empty_data' => '',
                'invalid_message' => 'Bağlı kurum geçersiz.',
            ])
            ->add('academicYearId', ChoiceType::class, [
                'label' => 'Akademik yıl',
                'choices' => $yearChoices,
                'placeholder' => 'Akademik yıl seçin',
                'empty_data' => '',
                'invalid_message' => 'Akademik yıl geçersiz.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Sınıf adı',
                'empty_data' => '',
            ])
            ->add('gradeLevel', ChoiceType::class, [
                'label' => 'Müfredat sınıf düzeyi',
                'choices' => $gradeChoices,
                'placeholder' => 'Düzey seçin',
                'empty_data' => '',
                'invalid_message' => 'Müfredat sınıf düzeyi geçersiz.',
            ])
            ->add('sectionCode', TextType::class, [
                'label' => 'Şube kodu',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('capacity', TextType::class, [
                'label' => 'Kontenjan',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Operatör kurulumu' => AdminClassroomCreateRequest::REASON_OPERATOR_SETUP,
                    'Operatör düzeltmesi' => AdminClassroomCreateRequest::REASON_OPERATOR_CORRECTION,
                    'Yaşam döngüsü' => AdminClassroomCreateRequest::REASON_LIFECYCLE,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Sınıf oluşturmayı onaylıyorum',
                'required' => true,
            ]);
        self::keepString($builder->get('institutionId'));
        self::keepString($builder->get('academicYearId'));
        self::keepString($builder->get('gradeLevel'));
        self::keepString($builder->get('reasonCode'));
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
            'data_class' => AdminClassroomCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_classroom_create',
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Bu form fazla alan içeremez.',
            'institutions' => [],
            'years' => [],
        ]);
        $resolver->setAllowedTypes('institutions', 'array');
        $resolver->setAllowedTypes('years', 'array');
    }
}

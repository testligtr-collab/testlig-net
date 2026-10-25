<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminAcademicYearCreateRequest;
use App\Dto\AdminAcademicYearInstitutionChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminAcademicYearCreateRequest>
 */
final class AdminAcademicYearCreateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_academic_year_create';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AdminAcademicYearInstitutionChoice> $institutions */
        $institutions = $options['institutions'];
        $institutionChoices = [];
        foreach ($institutions as $institution) {
            $institutionChoices[$institution->name] = $institution->id;
        }
        $builder
            ->add('institutionId', ChoiceType::class, [
                'label' => 'Kurum',
                'choices' => $institutionChoices,
                'placeholder' => 'Kurum seçin',
                'empty_data' => '',
                'invalid_message' => 'Bağlı kurum geçersiz.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Yıl adı',
                'empty_data' => '',
            ])
            ->add('startsOn', TextType::class, [
                'label' => 'Başlangıç tarihi',
                'empty_data' => '',
            ])
            ->add('endsOn', TextType::class, [
                'label' => 'Bitiş tarihi',
                'empty_data' => '',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Kurum onboarding' => AdminAcademicYearCreateRequest::REASON_ONBOARDING,
                    'Operatör kurulumu' => AdminAcademicYearCreateRequest::REASON_OPERATOR_SETUP,
                    'Migrasyon' => AdminAcademicYearCreateRequest::REASON_MIGRATION_IMPORT,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Yıl oluşturmayı onaylıyorum',
                'required' => true,
            ]);
        self::keepString($builder->get('institutionId'));
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
            'data_class' => AdminAcademicYearCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_academic_year_create',
            'allow_extra_fields' => false,
            'institutions' => [],
        ]);
        $resolver->setAllowedTypes('institutions', 'array');
    }
}

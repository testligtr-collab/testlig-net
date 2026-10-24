<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminInstitutionCreateRequest;
use App\Enum\InstitutionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminInstitutionCreateRequest>
 */
final class AdminInstitutionCreateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_institution_create';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $typeChoices = [];
        foreach (InstitutionType::cases() as $type) {
            $typeChoices[$type->value] = $type;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Kurum adı',
                'empty_data' => '',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Kurum türü',
                'choices' => $typeChoices,
                'choice_label' => static fn (InstitutionType $type): string => match ($type) {
                    InstitutionType::School => 'Okul',
                    InstitutionType::CourseCenter => 'Kurs',
                    InstitutionType::TutoringCenter => 'Etüt merkezi',
                    InstitutionType::Other => 'Diğer',
                },
                'placeholder' => 'Tür seçin',
            ])
            ->add('ownerUserId', TextType::class, [
                'label' => 'İlk sahip hesabı',
                'empty_data' => '',
                'attr' => [
                    'autocomplete' => 'off',
                    'class' => 'admin-owner-picker__value',
                    'data-owner-value' => '1',
                    'aria-hidden' => 'true',
                    'tabindex' => '-1',
                ],
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Kurum onboarding' => AdminInstitutionCreateRequest::REASON_ONBOARDING,
                    'Operatör kurulumu' => AdminInstitutionCreateRequest::REASON_OPERATOR_SETUP,
                    'Migrasyon' => AdminInstitutionCreateRequest::REASON_MIGRATION_IMPORT,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Kurum oluşturmayı onaylıyorum',
                'required' => true,
            ]);
        $builder->get('reasonCode')->addModelTransformer(self::blankString());
    }

    private static function blankString(): CallbackTransformer
    {
        return new CallbackTransformer(
            static fn (mixed $value): mixed => $value,
            static fn (mixed $value): string => \is_string($value) ? $value : '',
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminInstitutionCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_institution_create',
            'allow_extra_fields' => false,
        ]);
    }
}

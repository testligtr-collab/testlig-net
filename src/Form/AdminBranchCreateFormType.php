<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminBranchCreateRequest;
use App\Dto\AdminBranchInstitutionChoice;
use App\Enum\InstitutionBranchKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminBranchCreateRequest>
 */
final class AdminBranchCreateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_branch_create';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AdminBranchInstitutionChoice> $institutions */
        $institutions = $options['institutions'];
        $institutionChoices = [];
        foreach ($institutions as $institution) {
            $institutionChoices[$institution->name] = $institution->id;
        }
        $kindChoices = [];
        foreach (InstitutionBranchKind::cases() as $kind) {
            $kindChoices[$kind->value] = $kind;
        }

        $builder
            ->add('institutionId', ChoiceType::class, [
                'label' => 'Bağlı kurum',
                'choices' => $institutionChoices,
                'placeholder' => 'Kurum seçin',
                'empty_data' => '',
            ])
            ->add('name', TextType::class, [
                'label' => 'Ad',
                'empty_data' => '',
            ])
            ->add('code', TextType::class, [
                'label' => 'Kurum içi kod',
                'empty_data' => '',
            ])
            ->add('kind', ChoiceType::class, [
                'label' => 'Tür',
                'choices' => $kindChoices,
                'choice_label' => static fn (InstitutionBranchKind $kind): string => match ($kind) {
                    InstitutionBranchKind::Branch => 'Şube',
                    InstitutionBranchKind::Campus => 'Kampüs',
                },
                'placeholder' => 'Tür seçin',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Kurum onboarding' => AdminBranchCreateRequest::REASON_ONBOARDING,
                    'Operatör kurulumu' => AdminBranchCreateRequest::REASON_OPERATOR_SETUP,
                    'Migrasyon' => AdminBranchCreateRequest::REASON_MIGRATION_IMPORT,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Kayıt oluşturmayı onaylıyorum',
                'required' => true,
            ]);
        self::keepString($builder->get('institutionId'));
        self::keepString($builder->get('reasonCode'));
    }

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
            'data_class' => AdminBranchCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_branch_create',
            'allow_extra_fields' => false,
            'institutions' => [],
        ]);
        $resolver->setAllowedTypes('institutions', 'array');
    }
}

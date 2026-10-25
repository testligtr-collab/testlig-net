<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminBranchUpdateRequest;
use App\Enum\InstitutionBranchKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminBranchUpdateRequest>
 */
final class AdminBranchUpdateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_branch_update';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $kindChoices = [];
        foreach (InstitutionBranchKind::cases() as $kind) {
            $kindChoices[$kind->value] = $kind;
        }

        $builder
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
                    'Kayıt düzeltmesi' => AdminBranchUpdateRequest::REASON_CORRECTION,
                    'Operatör' => AdminBranchUpdateRequest::REASON_OPERATOR,
                    'Migrasyon' => AdminBranchUpdateRequest::REASON_MIGRATION_IMPORT,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Değişikliği onaylıyorum',
                'required' => true,
            ]);
        $reason = $builder->get('reasonCode');
        $reason->resetViewTransformers();
        $reason->addViewTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): string => \is_string($value) ? $value : '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminBranchUpdateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_branch_update',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired(['branch_id']);
        $resolver->setAllowedTypes('branch_id', [Uuid::class, 'string']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $branchId = $options['branch_id'];
            $id = $branchId instanceof Uuid ? $branchId->toRfc4122() : (string) $branchId;

            return 'admin_branch_update_'.$id;
        });
    }
}

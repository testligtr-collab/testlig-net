<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminBranchStatusRequest;
use App\Enum\InstitutionBranchStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminBranchStatusRequest>
 */
final class AdminBranchStatusFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_branch_status';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<InstitutionBranchStatus> $targets */
        $targets = $options['allowed_targets'];
        $actions = [];
        foreach ($targets as $status) {
            $action = match ($status) {
                InstitutionBranchStatus::Active => AdminBranchStatusRequest::ACTION_ACTIVATE,
                InstitutionBranchStatus::Suspended => AdminBranchStatusRequest::ACTION_SUSPEND,
                InstitutionBranchStatus::Archived => AdminBranchStatusRequest::ACTION_ARCHIVE,
                default => null,
            };
            if (null !== $action) {
                $actions[$status->value] = $action;
            }
        }

        $builder
            ->add('action', ChoiceType::class, [
                'label' => 'Yeni durum',
                'choices' => $actions,
                'choice_label' => static fn (string $action): string => match ($action) {
                    AdminBranchStatusRequest::ACTION_ACTIVATE => 'Aktif',
                    AdminBranchStatusRequest::ACTION_SUSPEND => 'Askıda',
                    AdminBranchStatusRequest::ACTION_ARCHIVE => 'Arşiv',
                    default => $action,
                },
                'placeholder' => 'İşlem seçin',
                'empty_data' => '',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Yaşam döngüsü' => AdminBranchStatusRequest::REASON_LIFECYCLE,
                    'Politika' => AdminBranchStatusRequest::REASON_POLICY,
                    'Operatör' => AdminBranchStatusRequest::REASON_OPERATOR,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Durum değişikliğini onaylıyorum',
                'required' => true,
            ]);
        foreach (['action', 'reasonCode'] as $name) {
            $field = $builder->get($name);
            $field->resetViewTransformers();
            $field->addViewTransformer(new CallbackTransformer(
                static fn (mixed $value): string => \is_string($value) ? $value : '',
                static fn (mixed $value): string => \is_string($value) ? $value : '',
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminBranchStatusRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_branch_status',
            'allow_extra_fields' => true,
            'allowed_targets' => [],
        ]);
        $resolver->setRequired(['branch_id']);
        $resolver->setAllowedTypes('branch_id', [Uuid::class, 'string']);
        $resolver->setAllowedTypes('allowed_targets', 'array');
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $branchId = $options['branch_id'];
            $id = $branchId instanceof Uuid ? $branchId->toRfc4122() : (string) $branchId;

            return 'admin_branch_status_'.$id;
        });
    }
}

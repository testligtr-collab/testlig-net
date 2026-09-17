<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminMembershipRoleRequest;
use App\Enum\InstitutionMembershipRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminMembershipRoleRequest>
 */
final class AdminMembershipRoleFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_membership_role';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (InstitutionMembershipRole::assignableByOwner() as $role) {
            $choices[$role->value] = $role;
        }

        $builder
            ->add('role', ChoiceType::class, [
                'label' => 'Üyelik rolü',
                'choices' => $choices,
                'placeholder' => 'Rol seçin',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Rol ayarı' => AdminMembershipRoleRequest::REASON_ROLE_ADJUSTMENT,
                    'Politika' => AdminMembershipRoleRequest::REASON_POLICY_ENFORCEMENT,
                    'Operatör' => AdminMembershipRoleRequest::REASON_OPERATOR_CORRECTION,
                ],
                'placeholder' => 'Gerekçe seçin',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Rol değişikliğini onaylıyorum',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminMembershipRoleRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_membership_role',
            'allow_extra_fields' => false,
        ]);
        $resolver->setRequired(['membership_id']);
        $resolver->setAllowedTypes('membership_id', [Uuid::class, 'string']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $membershipId = $options['membership_id'];
            $id = $membershipId instanceof Uuid ? $membershipId->toRfc4122() : (string) $membershipId;

            return 'admin_membership_role_'.$id;
        });
    }
}

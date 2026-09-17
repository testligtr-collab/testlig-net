<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminUserRolesRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminUserRolesRequest>
 */
final class AdminUserRolesFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_user_roles';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $choices */
        $choices = $options['assignable_roles'];
        $choiceMap = [];
        foreach ($choices as $value) {
            $choiceMap[$value] = $value;
        }

        $builder
            ->add('roles', ChoiceType::class, [
                'label' => 'Global roller',
                'choices' => $choiceMap,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Yetki ayarı' => AdminUserRolesRequest::REASON_PRIVILEGE_ADJUSTMENT,
                    'Destek yükseltmesi' => AdminUserRolesRequest::REASON_SUPPORT_ESCALATION,
                    'Politika uygulaması' => AdminUserRolesRequest::REASON_POLICY_ENFORCEMENT,
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
            'data_class' => AdminUserRolesRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_user_roles',
            'allow_extra_fields' => false,
            'assignable_roles' => [],
        ]);
        $resolver->setRequired(['user_id']);
        $resolver->setAllowedTypes('user_id', [Uuid::class, 'string']);
        $resolver->setAllowedTypes('assignable_roles', ['array']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $userId = $options['user_id'];
            $id = $userId instanceof Uuid ? $userId->toRfc4122() : (string) $userId;

            return 'admin_user_roles_'.$id;
        });
    }
}

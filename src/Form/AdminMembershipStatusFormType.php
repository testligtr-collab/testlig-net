<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminMembershipStatusRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminMembershipStatusRequest>
 */
final class AdminMembershipStatusFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_membership_status';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $actions */
        $actions = $options['allowed_actions'];
        $choices = [];
        foreach ($actions as $action) {
            $choices[$action] = $action;
        }

        $builder
            ->add('action', ChoiceType::class, [
                'label' => 'İşlem',
                'choices' => $choices,
                'placeholder' => 'İşlem seçin',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Politika' => AdminMembershipStatusRequest::REASON_POLICY,
                    'Yaşam döngüsü' => AdminMembershipStatusRequest::REASON_LIFECYCLE,
                    'Operatör' => AdminMembershipStatusRequest::REASON_OPERATOR,
                ],
                'placeholder' => 'Gerekçe seçin',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Üyelik durum değişikliğini onaylıyorum',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminMembershipStatusRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_membership_status',
            'allow_extra_fields' => false,
            'allowed_actions' => [],
        ]);
        $resolver->setRequired(['membership_id']);
        $resolver->setAllowedTypes('membership_id', [Uuid::class, 'string']);
        $resolver->setAllowedTypes('allowed_actions', ['array']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $membershipId = $options['membership_id'];
            $id = $membershipId instanceof Uuid ? $membershipId->toRfc4122() : (string) $membershipId;

            return 'admin_membership_status_'.$id;
        });
    }
}

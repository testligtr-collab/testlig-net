<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminUserStatusRequest;
use App\Enum\UserStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminUserStatusRequest>
 */
final class AdminUserStatusFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_user_status';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<UserStatus> $targets */
        $targets = $options['allowed_targets'];
        $actions = [];
        foreach ($targets as $status) {
            $action = match ($status) {
                UserStatus::Suspended => AdminUserStatusRequest::ACTION_SUSPEND,
                UserStatus::Archived => AdminUserStatusRequest::ACTION_ARCHIVE,
                UserStatus::Active => AdminUserStatusRequest::ACTION_REACTIVATE,
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
                'placeholder' => 'İşlem seçin',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Politika ihlali' => AdminUserStatusRequest::REASON_POLICY_VIOLATION,
                    'Hesap incelemesi' => AdminUserStatusRequest::REASON_ACCOUNT_REVIEW,
                    'Operatör düzeltmesi' => AdminUserStatusRequest::REASON_OPERATOR_CORRECTION,
                ],
                'placeholder' => 'Gerekçe seçin',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Durum değişikliğini onaylıyorum',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminUserStatusRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_user_status',
            'allow_extra_fields' => false,
            'allowed_targets' => [],
        ]);
        $resolver->setRequired(['user_id']);
        $resolver->setAllowedTypes('user_id', [Uuid::class, 'string']);
        $resolver->setAllowedTypes('allowed_targets', ['array']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $userId = $options['user_id'];
            $id = $userId instanceof Uuid ? $userId->toRfc4122() : (string) $userId;

            return 'admin_user_status_'.$id;
        });
    }
}

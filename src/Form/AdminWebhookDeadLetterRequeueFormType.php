<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminWebhookDeadLetterRequeueRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminWebhookDeadLetterRequeueRequest>
 */
final class AdminWebhookDeadLetterRequeueFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_webhook_dead_letter_requeue';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Manuel inceleme sonrası kuyruğa alma' => AdminWebhookDeadLetterRequeueRequest::REASON_MANUAL_REQUEUE_REVIEW,
                    'Operatör düzeltmesi sonrası yeniden deneme' => AdminWebhookDeadLetterRequeueRequest::REASON_OPERATOR_RETRY_AFTER_FIX,
                    'Sağlayıcı tarafı onaylandı' => AdminWebhookDeadLetterRequeueRequest::REASON_PROVIDER_SIDE_CONFIRMED,
                ],
                'placeholder' => 'Gerekçe seçin',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Dead-letter olayını yeniden denemeyi onaylıyorum',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminWebhookDeadLetterRequeueRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_webhook_dead_letter_requeue',
            'allow_extra_fields' => false,
        ]);

        $resolver->setRequired(['event_id']);
        $resolver->setAllowedTypes('event_id', [Uuid::class, 'string']);

        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $eventId = $options['event_id'];
            $id = $eventId instanceof Uuid ? $eventId->toRfc4122() : (string) $eventId;

            return 'admin_webhook_dead_letter_requeue_'.$id;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminInstitutionStatusRequest;
use App\Enum\InstitutionStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminInstitutionStatusRequest>
 */
final class AdminInstitutionStatusFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_institution_status';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<InstitutionStatus> $targets */
        $targets = $options['allowed_targets'];
        $actions = [];
        foreach ($targets as $status) {
            $action = match ($status) {
                InstitutionStatus::Active => AdminInstitutionStatusRequest::ACTION_ACTIVATE,
                InstitutionStatus::Suspended => AdminInstitutionStatusRequest::ACTION_SUSPEND,
                InstitutionStatus::Archived => AdminInstitutionStatusRequest::ACTION_ARCHIVE,
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
                    'Yaşam döngüsü' => AdminInstitutionStatusRequest::REASON_LIFECYCLE,
                    'Politika' => AdminInstitutionStatusRequest::REASON_POLICY,
                    'Operatör' => AdminInstitutionStatusRequest::REASON_OPERATOR,
                ],
                'placeholder' => 'Gerekçe seçin',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Kurum durum değişikliğini onaylıyorum',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminInstitutionStatusRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_institution_status',
            'allow_extra_fields' => false,
            'allowed_targets' => [],
        ]);
        $resolver->setRequired(['institution_id']);
        $resolver->setAllowedTypes('institution_id', [Uuid::class, 'string']);
        $resolver->setAllowedTypes('allowed_targets', ['array']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $institutionId = $options['institution_id'];
            $id = $institutionId instanceof Uuid ? $institutionId->toRfc4122() : (string) $institutionId;

            return 'admin_institution_status_'.$id;
        });
    }
}

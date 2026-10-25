<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminAcademicYearActivateRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminAcademicYearActivateRequest>
 */
final class AdminAcademicYearActivateFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_academic_year_activate';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Dönem değişikliği' => AdminAcademicYearActivateRequest::REASON_LIFECYCLE,
                    'Operatör düzeltmesi' => AdminAcademicYearActivateRequest::REASON_OPERATOR,
                ],
                'placeholder' => 'Gerekçe seçin',
                'empty_data' => '',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Bu yılı aktifleştirmeyi ve mevcut aktif yılın kapanacağını onaylıyorum',
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
            'data_class' => AdminAcademicYearActivateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_academic_year_activate',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired(['year_id']);
        $resolver->setAllowedTypes('year_id', [Uuid::class, 'string']);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $yearId = $options['year_id'];
            $id = $yearId instanceof Uuid ? $yearId->toRfc4122() : (string) $yearId;

            return 'admin_academic_year_activate_'.$id;
        });
    }
}

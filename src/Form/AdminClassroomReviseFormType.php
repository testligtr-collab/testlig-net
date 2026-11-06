<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminClassroomCreateRequest;
use App\Dto\AdminClassroomReviseRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractType<AdminClassroomReviseRequest>
 */
final class AdminClassroomReviseFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_classroom_revise';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Sınıf adı',
                'empty_data' => '',
            ])
            ->add('capacity', TextType::class, [
                'label' => 'Kontenjan',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('expectedUpdatedAt', HiddenType::class, [
                'empty_data' => '',
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Operatör kurulumu' => AdminClassroomCreateRequest::REASON_OPERATOR_SETUP,
                    'Operatör düzeltmesi' => AdminClassroomCreateRequest::REASON_OPERATOR_CORRECTION,
                    'Yaşam döngüsü' => AdminClassroomCreateRequest::REASON_LIFECYCLE,
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
            'data_class' => AdminClassroomReviseRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_classroom_revise',
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Kurum veya akademik yıl bu formdan değiştirilemez.',
        ]);
        $resolver->setRequired('classroom_id');
        $resolver->setAllowedTypes('classroom_id', Uuid::class);
        $resolver->setNormalizer('csrf_token_id', static function (Options $options, mixed $value): string {
            $classroomId = $options['classroom_id'];
            $id = $classroomId instanceof Uuid ? $classroomId->toRfc4122() : (string) $classroomId;

            return 'admin_classroom_revise_'.$id;
        });
    }
}

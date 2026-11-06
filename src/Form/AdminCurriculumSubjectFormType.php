<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminCurriculumProgramCreateRequest;
use App\Dto\AdminCurriculumSubjectRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminCurriculumSubjectRequest>
 */
final class AdminCurriculumSubjectFormType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'admin_curriculum_subject';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Kod',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('name', TextType::class, [
                'label' => 'Ad',
                'empty_data' => '',
                'required' => false,
            ])
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => [
                    'Program kurulumu' => AdminCurriculumProgramCreateRequest::REASON_SETUP,
                    'Düzeltme' => AdminCurriculumProgramCreateRequest::REASON_CORRECTION,
                ],
                'placeholder' => 'Gerekçe seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'İşlem gerekçesi geçersiz.',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Ders oluşturmayı onaylıyorum',
                'required' => false,
            ]);
        $field = $builder->get('reasonCode');
        $field->resetViewTransformers();
        $field->addViewTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): string => \is_string($value) ? $value : '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminCurriculumSubjectRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'admin_curriculum_subject',
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Bu form fazla alan içeremez.',
        ]);
    }
}

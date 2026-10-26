<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminCurriculumActionRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminCurriculumActionRequest>
 */
final class AdminCurriculumActionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $reasons */
        $reasons = $options['reasons'];
        $builder
            ->add('reasonCode', ChoiceType::class, [
                'label' => 'Gerekçe',
                'choices' => $reasons,
                'placeholder' => 'Gerekçe seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'İşlem gerekçesi geçersiz.',
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => (string) $options['confirm_label'],
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
            'data_class' => AdminCurriculumActionRequest::class,
            'csrf_protection' => true,
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Bu form fazla alan içeremez.',
            'reasons' => [],
            'confirm_label' => 'İşlemi onaylıyorum',
        ]);
        $resolver->setAllowedTypes('reasons', 'array');
        $resolver->setAllowedTypes('confirm_label', 'string');
    }
}

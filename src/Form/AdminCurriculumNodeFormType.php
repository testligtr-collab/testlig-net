<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\AdminCurriculumNodeRequest;
use App\Dto\AdminCurriculumProgramCreateRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminCurriculumNodeRequest>
 */
final class AdminCurriculumNodeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $parents */
        $parents = $options['parents'];
        if ([] !== $parents) {
            $builder->add('parentId', ChoiceType::class, [
                'label' => (string) $options['parent_label'],
                'choices' => $parents,
                'placeholder' => 'Üst kayıt seçin',
                'required' => false,
                'empty_data' => '',
                'invalid_message' => 'Üst kayıt bu programda yok.',
            ]);
        }
        $builder
            ->add('code', TextType::class, [
                'label' => 'Kod',
                'empty_data' => '',
                'required' => false,
                'help' => 'Program içindeki teknik koddur. Resmî kazanım kodu değildir.',
            ])
            ->add('title', TextType::class, [
                'label' => (string) $options['title_label'],
                'empty_data' => '',
                'required' => false,
            ])
            ->add('position', TextType::class, [
                'label' => 'Sıra',
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
                'label' => 'Kaydı oluşturmayı onaylıyorum',
                'required' => false,
            ]);
        foreach (['parentId', 'reasonCode'] as $field) {
            if ($builder->has($field)) {
                self::keepString($builder->get($field));
            }
        }
    }

    /**
     * @param FormBuilderInterface<string> $field
     */
    private static function keepString(FormBuilderInterface $field): void
    {
        $field->resetViewTransformers();
        $field->addViewTransformer(new CallbackTransformer(
            static fn (mixed $value): string => \is_string($value) ? $value : '',
            static fn (mixed $value): string => \is_string($value) ? $value : '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminCurriculumNodeRequest::class,
            'csrf_protection' => true,
            'allow_extra_fields' => false,
            'extra_fields_message' => 'Bu form fazla alan içeremez.',
            'parents' => [],
            'parent_label' => 'Üst kayıt',
            'title_label' => 'Ad',
        ]);
        $resolver->setAllowedTypes('parents', 'array');
        $resolver->setAllowedTypes('parent_label', 'string');
        $resolver->setAllowedTypes('title_label', 'string');
    }
}

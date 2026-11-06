<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\SubjectCreateRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SubjectCreateRequest>
 */
final class SubjectCreateFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Konu alanı adı',
                'attr' => ['maxlength' => 180],
            ])
            ->add('code', TextType::class, [
                'label' => 'Teknik kod',
                'attr' => ['maxlength' => 64],
                'help' => 'Kalıcıdır. Kısa ve snake_case olmalıdır. Oluşturulduktan sonra değiştirilemez.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubjectCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'subject_create',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'subject_create';
    }
}

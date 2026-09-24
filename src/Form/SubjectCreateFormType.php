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
            ->add('code', TextType::class, [
                'label' => 'Kod',
                'attr' => ['maxlength' => 64],
                'help' => 'Kalıcı kimlik (snake_case). Oluşturulduktan sonra değiştirilemez.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Ad',
                'attr' => ['maxlength' => 180],
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

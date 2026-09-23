<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CatalogTopicRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CatalogTopicRequest>
 */
final class CatalogTopicFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Konu adı',
                'attr' => ['maxlength' => 180],
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Özet',
                'required' => false,
                'attr' => ['rows' => 4, 'maxlength' => 3000],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Sıra',
            ])
            ->add('estimatedMinutes', IntegerType::class, [
                'label' => 'Tahmini süre (dk)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CatalogTopicRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'catalog_topic',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'catalog_topic';
    }
}

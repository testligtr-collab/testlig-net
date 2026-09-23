<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\CatalogSubjectRequest;
use App\Enum\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CatalogSubjectRequest>
 */
final class CatalogSubjectFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('gradeLevel', EnumType::class, [
                'class' => GradeLevel::class,
                'label' => 'Sınıf seviyesi',
                'choice_label' => static fn (GradeLevel $g): string => \sprintf('%d. sınıf', $g->value),
                'disabled' => $options['lock_grade'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Ders adı',
                'attr' => ['maxlength' => 120],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Açıklama',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 1000],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Sıra',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CatalogSubjectRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'catalog_subject',
            'lock_grade' => false,
        ]);
        $resolver->setAllowedTypes('lock_grade', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'catalog_subject';
    }
}

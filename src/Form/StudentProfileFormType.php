<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\StudentProfileRequest;
use App\Enum\GradeLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<StudentProfileRequest>
 */
final class StudentProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('gradeLevel', EnumType::class, [
                'class' => GradeLevel::class,
                'label' => 'Sınıf seviyesi',
                'placeholder' => 'Sınıf seçin',
                'choice_label' => static fn (GradeLevel $grade): string => \sprintf('%d. sınıf', $grade->value),
            ])
            ->add('schoolName', TextType::class, [
                'label' => 'Okul adı',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'Şehir',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'address-level2',
                    'maxlength' => 100,
                ],
            ])
            ->add('learningGoal', TextareaType::class, [
                'label' => 'Öğrenme hedefi',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 500,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StudentProfileRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'student_profile',
        ]);
    }
}

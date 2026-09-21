<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\TeacherApplicationRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TeacherApplicationRequest>
 */
final class TeacherApplicationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('acknowledgePendingReview', CheckboxType::class, [
                'label' => 'Başvurumun inceleneceğini ve onaylanmadan öğretmen erişimimin açılmayacağını anlıyorum.',
                'mapped' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeacherApplicationRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'teacher_application',
            'allow_extra_fields' => false,
        ]);
    }
}

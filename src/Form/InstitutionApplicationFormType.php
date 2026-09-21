<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\InstitutionApplicationRequest;
use App\Enum\InstitutionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<InstitutionApplicationRequest>
 */
final class InstitutionApplicationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('proposedName', TextType::class, [
                'label' => 'Kurum adı',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('proposedType', EnumType::class, [
                'class' => InstitutionType::class,
                'label' => 'Kurum türü',
                'placeholder' => 'Seçiniz',
                'choice_label' => static fn (InstitutionType $type): string => match ($type) {
                    InstitutionType::School => 'Okul',
                    InstitutionType::CourseCenter => 'Kurs merkezi',
                    InstitutionType::TutoringCenter => 'Dershane / etüt',
                    InstitutionType::Other => 'Diğer',
                },
            ])
            ->add('acknowledgePendingReview', CheckboxType::class, [
                'label' => 'Başvurumun inceleneceğini ve onaylanmadan kurum erişimimin açılmayacağını anlıyorum.',
                'mapped' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InstitutionApplicationRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'institution_application',
            'allow_extra_fields' => false,
        ]);
    }
}

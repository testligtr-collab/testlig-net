<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\TeacherProfessionalProfileRequest;
use App\Entity\Subject;
use App\Enum\GradeLevel;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TeacherProfessionalProfileRequest>
 */
final class TeacherProfessionalProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', EntityType::class, [
                'class' => Subject::class,
                'choices' => $options['subjects'],
                'choice_label' => 'name',
                'choice_value' => static fn (?Subject $subject): string => $subject instanceof Subject ? $subject->getId()->toRfc4122() : '',
                'label' => 'Branş',
                'placeholder' => 'Branş seçin',
                'invalid_message' => 'Geçerli bir aktif branş seçin.',
            ])
            ->add('gradeLevels', EnumType::class, [
                'class' => GradeLevel::class,
                'choices' => GradeLevel::cases(),
                'choice_label' => static fn (GradeLevel $grade): string => \sprintf('%d. sınıf', $grade->value),
                'label' => 'Sınıflar',
                'multiple' => true,
                'expanded' => true,
                'invalid_message' => 'En az bir geçerli sınıf seçin.',
            ])
            ->add('provinceId', HiddenType::class, ['required' => false])
            ->add('districtId', HiddenType::class, ['required' => false])
            ->add('schoolId', HiddenType::class, ['required' => false]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }
            unset($data['user_id'], $data['userId']);
            if (isset($data['gradeLevels']) && \is_array($data['gradeLevels'])) {
                $data['gradeLevels'] = array_values(array_unique(array_map(
                    static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
                    $data['gradeLevels'],
                )));
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeacherProfessionalProfileRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'teacher_professional_profile',
            'allow_extra_fields' => true,
            'subjects' => [],
        ]);
        $resolver->setAllowedTypes('subjects', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'teacher_profile';
    }
}

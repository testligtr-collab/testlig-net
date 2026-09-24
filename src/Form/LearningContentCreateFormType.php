<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\LearningContentCreateRequest;
use App\Enum\GradeLevel;
use App\Enum\LearningContentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LearningContentCreateRequest>
 */
final class LearningContentCreateFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $subjectChoices */
        $subjectChoices = $options['subject_choices'];
        /** @var array<string, string> $outcomeChoices */
        $outcomeChoices = $options['outcome_choices'];

        $builder
            ->add('code', TextType::class, [
                'label' => 'Kod',
                'attr' => ['maxlength' => 64],
            ])
            ->add('title', TextType::class, [
                'label' => 'Başlık',
                'attr' => ['maxlength' => 200],
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Özet',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
            ])
            ->add('contentType', EnumType::class, [
                'class' => LearningContentType::class,
                'label' => 'İçerik türü',
                'choice_label' => static fn (LearningContentType $t): string => $t->value,
            ])
            ->add('gradeLevel', EnumType::class, [
                'class' => GradeLevel::class,
                'label' => 'Sınıf seviyesi',
                'choice_label' => static fn (GradeLevel $g): string => \sprintf('%d. sınıf', $g->value),
            ])
            ->add('subjectId', ChoiceType::class, [
                'label' => 'Canonical konu alanı',
                'choices' => $subjectChoices,
                'placeholder' => 'Seçin…',
            ])
            ->add('learningOutcomeId', ChoiceType::class, [
                'label' => 'Birincil öğrenme kazanımı',
                'choices' => $outcomeChoices,
                'placeholder' => 'Seçin…',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LearningContentCreateRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'learning_content_create',
            'subject_choices' => [],
            'outcome_choices' => [],
        ]);
        $resolver->setAllowedTypes('subject_choices', 'array');
        $resolver->setAllowedTypes('outcome_choices', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'learning_content_create';
    }
}

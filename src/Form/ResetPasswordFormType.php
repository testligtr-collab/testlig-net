<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ResetPasswordRequestData;
use App\Security\PasswordPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ResetPasswordRequestData>
 */
final class ResetPasswordFormType extends AbstractType
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [];
        if ('test' !== $this->environment) {
            $passwordConstraints[] = PasswordPolicy::notCompromisedConstraint();
        }

        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Parola alanları eşleşmiyor.',
            'first_options' => [
                'label' => 'Yeni parola',
                'attr' => ['autocomplete' => 'new-password'],
                'help' => PasswordPolicy::HELP_TEXT,
                'constraints' => $passwordConstraints,
                'trim' => false,
            ],
            'second_options' => [
                'label' => 'Yeni parola tekrarı',
                'attr' => ['autocomplete' => 'new-password'],
                'trim' => false,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResetPasswordRequestData::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'reset_password',
            'allow_extra_fields' => false,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ResetPasswordRequestData;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

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
            $passwordConstraints[] = new NotCompromisedPassword(
                message: 'Bu parola bilinen bir veri ihlalinde görülmüş. Lütfen başka bir parola seçin.',
            );
        }

        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Parola alanları eşleşmiyor.',
            'first_options' => [
                'label' => 'Yeni parola',
                'attr' => ['autocomplete' => 'new-password'],
                'help' => 'En az 12 karakter; büyük/küçük harf, rakam ve özel karakter karışımı önerilir. Bilinen sızıntılardaki parolalar kabul edilmez.',
                'constraints' => $passwordConstraints,
            ],
            'second_options' => [
                'label' => 'Yeni parola tekrarı',
                'attr' => ['autocomplete' => 'new-password'],
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

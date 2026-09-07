<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\RegistrationRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * @extends AbstractType<RegistrationRequest>
 */
final class RegistrationFormType extends AbstractType
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

        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Ad',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Soyad',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-posta',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Parola alanları eşleşmiyor.',
                'first_options' => [
                    'label' => 'Parola',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'En az 12 karakter; büyük/küçük harf, rakam ve özel karakter karışımı önerilir. Bilinen sızıntılardaki parolalar kabul edilmez.',
                    'constraints' => $passwordConstraints,
                ],
                'second_options' => [
                    'label' => 'Parola tekrarı',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'Kullanım koşullarını ve gizlilik metnini okudum, kabul ediyorum.',
                'mapped' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'registration',
            'allow_extra_fields' => true,
        ]);
    }
}

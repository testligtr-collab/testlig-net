<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request another verification email without revealing whether the address exists.
 *
 * @extends AbstractType<array{email?: string}>
 */
final class ResendVerificationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'E-posta',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'E-posta zorunludur.'),
                new Assert\Email(message: 'Geçerli bir e-posta adresi girin.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'resend_verification',
        ]);
    }
}

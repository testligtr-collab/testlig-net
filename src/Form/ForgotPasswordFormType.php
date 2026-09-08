<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ForgotPasswordRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ForgotPasswordRequest>
 */
final class ForgotPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'E-posta',
            'attr' => ['autocomplete' => 'email'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ForgotPasswordRequest::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'forgot_password',
            'allow_extra_fields' => false,
        ]);
    }
}

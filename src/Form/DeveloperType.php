<?php

namespace App\Form;

use App\Entity\Developer;
use App\Entity\Team;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeveloperType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['label' => 'Nom affiché'])
            ->add('jiraAccountId', TextType::class, [
                'label' => 'Identifiant Jira',
                'help' => 'Jira Cloud : accountId (visible dans l\'URL du profil, ex. 5b10ac8d82e05b22cc7d4ef5). Jira Server : nom d\'utilisateur. Le plus simple : "Importer depuis Jira".',
            ])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('role', ChoiceType::class, ['label' => 'Rôle', 'choices' => Developer::ROLES])
            ->add('team', EntityType::class, [
                'label' => 'Équipe',
                'class' => Team::class,
                'required' => false,
                'placeholder' => '— Aucune —',
            ])
            ->add('active', CheckboxType::class, ['label' => 'Suivi dans "Qui fait quoi"', 'required' => false])
            ->add('expectedOnTicket', CheckboxType::class, [
                'label' => 'Alerter si aucun ticket en cours',
                'help' => 'Décochez pour un PO, un manager ou une personne à temps partiel.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Developer::class]);
    }
}

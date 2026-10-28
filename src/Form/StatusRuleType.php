<?php

namespace App\Form;

use App\Entity\Project;
use App\Entity\StatusRule;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StatusRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statusName', TextType::class, [
                'label' => 'Statut Jira',
                'help' => 'Nom exact du statut (insensible à la casse), ex. : Code Review, En test, Bloqué.',
                'attr' => ['list' => 'jira-statuses'],
            ])
            ->add('warningDays', NumberType::class, ['label' => 'Alerte orange au-delà de (jours)', 'scale' => 1, 'html5' => true, 'attr' => ['step' => '0.5', 'min' => 0]])
            ->add('criticalDays', NumberType::class, ['label' => 'Alerte rouge au-delà de (jours)', 'scale' => 1, 'html5' => true, 'attr' => ['step' => '0.5', 'min' => 0]])
            ->add('project', EntityType::class, [
                'label' => 'Projet',
                'class' => Project::class,
                'required' => false,
                'placeholder' => 'Tous les projets',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StatusRule::class]);
    }
}

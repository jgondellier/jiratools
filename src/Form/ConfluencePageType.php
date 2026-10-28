<?php

namespace App\Form;

use App\Entity\ConfluencePage;
use App\Entity\Project;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfluencePageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('project', EntityType::class, ['label' => 'Projet', 'class' => Project::class])
            ->add('title', TextType::class, ['label' => 'Titre'])
            ->add('category', ChoiceType::class, ['label' => 'Catégorie', 'choices' => ConfluencePage::CATEGORIES])
            ->add('url', UrlType::class, [
                'label' => 'URL de la page',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'L\'identifiant de page est déduit automatiquement de l\'URL quand c\'est possible.',
            ])
            ->add('pageId', TextType::class, [
                'label' => 'Identifiant de page',
                'required' => false,
                'help' => 'Permet d\'afficher la date de dernière mise à jour.',
            ])
            ->add('maxAgeDays', IntegerType::class, [
                'label' => 'Alerter si non modifiée depuis (jours)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConfluencePage::class]);
    }
}

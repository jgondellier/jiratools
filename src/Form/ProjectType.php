<?php

namespace App\Form;

use App\Entity\Project;
use App\Entity\Team;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom du projet'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false])
            ->add('teams', EntityType::class, [
                'label' => 'Équipe(s)',
                'class' => Team::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('jiraProjectKey', TextType::class, [
                'label' => 'Clé du projet Jira',
                'help' => 'Ex. : APP. Plusieurs projets peuvent partager la même backlog Jira.',
            ])
            ->add('componentsText', TextareaType::class, [
                'label' => 'Composants Jira identifiant ce projet',
                'help' => 'Un par ligne (ou séparés par des virgules). Vide = tout le projet Jira.',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('jiraBoardId', IntegerType::class, [
                'label' => 'Identifiant du board Scrum',
                'help' => 'Nombre visible dans l\'URL du board (…/boards/42 ou rapidView=42). Nécessaire pour les sprints.',
                'required' => false,
            ])
            ->add('extraJql', TextType::class, [
                'label' => 'Filtre JQL additionnel',
                'help' => 'Optionnel, ex. : issuetype != Epic',
                'required' => false,
            ])
            ->add('confluenceSpaceKey', TextType::class, [
                'label' => 'Espace Confluence',
                'help' => 'Clé de l\'espace où publier la release note (ex. : APP).',
                'required' => false,
            ])
            ->add('releaseNoteParentPageId', TextType::class, [
                'label' => 'Page parente des release notes (id)',
                'help' => 'Identifiant de la page Confluence sous laquelle créer les release notes.',
                'required' => false,
            ])
            ->add('active', CheckboxType::class, ['label' => 'Projet actif', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Project::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\LocalApp;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LocalAppType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom de l\'application'])
            ->add('provider', ChoiceType::class, ['label' => 'Hébergement du dépôt Git', 'choices' => LocalApp::providers()])
            ->add('repository', TextType::class, [
                'label' => 'Dépôt',
                'help' => 'GitHub / Bitbucket : owner/depot · GitLab : groupe/sous-groupe/projet (ou identifiant numérique) · l\'URL complète du dépôt est acceptée. « URL du zip » : adresse de l\'archive, {ref} étant remplacé par la branche, ex. https://git.intranet/depot/archive/{ref}.zip',
                'attr' => ['placeholder' => 'mon-entreprise/mon-appli'],
            ])
            ->add('serverUrl', TextType::class, [
                'label' => 'Serveur (facultatif)',
                'required' => false,
                'help' => 'GitLab auto-hébergé : https://gitlab.mon-entreprise.fr · GitHub Enterprise : https://github.mon-entreprise.fr/api/v3. Vide : gitlab.com / github.com / bitbucket.org.',
            ])
            ->add('ref', TextType::class, ['label' => 'Branche, tag ou commit par défaut', 'help' => 'Modifiable à chaque lancement.'])
            ->add('token', TextType::class, [
                'label' => 'Jeton d\'accès (dépôt privé)',
                'required' => false,
                'help' => 'Conseillé : env:NOM_VARIABLE, la valeur étant mise dans .env.local (ex. env:GITHUB_TOKEN puis GITHUB_TOKEN=ghp_xxx). GitHub : jeton avec accès « Contents: read » · GitLab : « read_repository » / « read_api » · Bitbucket : utilisateur:mot-de-passe-d\'application.',
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'env:GITHUB_TOKEN'],
            ])
            ->add('subdirectory', TextType::class, ['label' => 'Sous-dossier de l\'archive à déployer (facultatif)', 'required' => false, 'help' => 'Si le dépôt contient plusieurs applications, ex. apps/catalogue.'])
            ->add('targetDirectory', TextType::class, [
                'label' => 'Répertoire du projet sur le poste',
                'help' => 'Chemin absolu, créé au premier déploiement s\'il n\'existe pas.',
                'attr' => ['placeholder' => 'C:/EasyPHP/eds-www/mon-appli'],
            ])
            ->add('preservedPaths', TextareaType::class, [
                'label' => 'Fichiers et dossiers protégés',
                'help' => 'Un par ligne, relatifs au projet : jamais écrasés ni supprimés. « var/ » = dossier entier ; « .env.*.local » = motif ; « *.log » = nom de fichier dans n\'importe quel dossier.',
                'attr' => ['rows' => 7, 'class' => 'font-monospace'],
            ])
            ->add('commands', TextareaType::class, [
                'label' => 'Commandes après la copie',
                'help' => 'Une par ligne, lancées dans le répertoire du projet, dans l\'ordre. « php » utilise LOCAL_UPDATE_PHP_BINARY, {composer} la commande LOCAL_UPDATE_COMPOSER. Une ligne commençant par # est ignorée.',
                'attr' => ['rows' => 6, 'class' => 'font-monospace'],
            ])
            ->add('environment', TextareaType::class, [
                'label' => 'Variables d\'environnement des commandes (facultatif)',
                'required' => false,
                'help' => 'NOM=valeur, une par ligne, ex. APP_ENV=dev. Sinon chaque application utilise son propre .env / .env.local.',
                'attr' => ['rows' => 2, 'class' => 'font-monospace'],
            ])
            ->add('deleteObsolete', CheckboxType::class, [
                'label' => 'Supprimer les fichiers retirés du dépôt',
                'required' => false,
                'help' => 'Seuls les fichiers déployés par la mise à jour précédente et absents de la nouvelle archive sont supprimés.',
            ])
            ->add('backup', CheckboxType::class, ['label' => 'Sauvegarder les fichiers remplacés ou supprimés', 'required' => false, 'help' => 'Dans var/local-updates/backups de Jira Tools (5 dernières mises à jour).'])
            ->add('stopOnError', CheckboxType::class, ['label' => 'Arrêter à la première commande en erreur', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LocalApp::class]);
    }
}

import type { I18nSchema } from '@/i18n'

const fr: I18nSchema = {
    common: {
        back: "Retour",
        cancel: "Annuler",
    },
    title: "Corrai",
    nav: {
        exams: "Examens",
        settings: "Paramètres",
        login: "Connexion",
        logout: "Déconnexion"
    },
    examList: {
        subtitle: "Gérez vos examens",
        empty: "Aucun examen pour le moment."
    },
    createExam: {
        title: "Créer un examen",
        subtitle: "Saisissez le nom, la matière et la date de votre examen",
        editTitle: "Modifier l'examen",
        editSubtitle: "Mettez à jour le nom, la matière et la date de votre examen"
    },
    exam: {
        loading: "Chargement de l'examen...",
        error: "Erreur",
        back: "← Retour",
        createNew: "Créer un nouvel examen",
        edit: "Modifier",
        name: "Nom",
        namePlaceholder: "Entrez le nom de l'examen",
        subject: "Matière",
        subjectPlaceholder: "Entrez la matière",
        date: "Date",
        create: "Créer l'examen",
        creating: "Création...",
        save: "Enregistrer",
        saving: "Enregistrement...",
        delete: "Supprimer l'examen",
        deleting: "Suppression...",
        deleteConfirm: "Êtes-vous sûr de vouloir supprimer cet examen ?",
        deleteError: "Échec de la suppression de l'examen",
        details: "Détails de l'examen",
        notFound: "Examen introuvable",
        notFoundMessage: "Impossible de trouver l'examen avec le hash \"{hash}\".",
        saveError: "Échec de l'enregistrement de l'examen",
        createError: "Échec de la création de l'examen",
        files: "Fichiers",
        filesEmpty: "Aucun fichier lié à cet examen pour le moment.",
        addFiles: "Ajouter des fichiers",
        addFilesTitle: "Ajouter des fichiers",
        dropzoneHint: "Glissez-déposez des fichiers ici, ou cliquez pour sélectionner",
        uploading: "Téléversement...",
        uploadDone: "Téléversé",
        uploadError: "Échec du téléversement du fichier"
    },
    language: "Langue",
    settings: {
        title: "Paramètres",
        subtitle: "Configurez vos préférences et options système",
        general: "Général",
        admin_console_link: "Lien de la console d'administration",
        copy_link: "Copier le lien dans le presse-papiers",
        admin_locale: "Langue de l'Interface d'Administration",
        notifications: "Notifications",
        notifications_permission_denied_warning: "Les notifications du navigateur sont actuellement désactivées. Activez les notifications pour recevoir des mises à jour importantes.",
        notifications_permission_denied: "La permission a été refusée. Veuillez activer les notifications dans les paramètres de votre navigateur.",
        notifications_enabled: "Les notifications ont été activées avec succès !",
        notifications_enabled_status: "Les notifications du navigateur sont activées.",
        notifications_not_requested: "Activez les notifications du navigateur pour recevoir des mises à jour importantes.",
        enable_notifications: "Activer les Notifications",
        create_webpush_subscription: "Créer un abonnement Web Push",
        notifications_error: "Une erreur s'est produite lors de la configuration des notifications.",
        reset_notifications: "Réinitialiser les Notifications",
        reset_notifications_confirm: "Êtes-vous sûr de vouloir réinitialiser et désactiver les notifications ?",
        notifications_reset_success: "Les notifications ont été réinitialisées avec succès !",
        notifications_reset_error: "Une erreur s'est produite lors de la réinitialisation des notifications."
    },
    notFound: {
        title: "Page non trouvée",
        message: "La page que vous recherchez n'existe pas ou a été déplacée.",
        goHome: "Aller à l'accueil",
        goBack: "Retour"
    },
    notAuthenticated: {
        title: "Non Authentifié",
        message: "Vous n'êtes pas authentifié pour accéder à cette page. Veuillez vous connecter pour continuer.",
        goToLogin: "Aller à la connexion"
    },
    initProfile: {
        title: "Initialiser le profil",
        subtitle: "Créez un nouveau profil ou récupérez un profil existant",
        createProfile: "Créer un nouveau profil",
        recoverProfile: "Récupérer un profil",
        userName: "Nom d'utilisateur",
        optional: "optionnel",
        userNamePlaceholder: "Entrez votre nom (optionnel)",
        create: "Créer le profil",
        creating: "Création...",
        createError: "Échec de la création du profil. Veuillez réessayer.",
        recoverDescription: "La récupération de profil sera bientôt disponible."
    }
} as I18nSchema

export default fr

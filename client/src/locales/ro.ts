import type { I18nSchema } from '@/i18n'

const ro: I18nSchema = {
    common: {
        back: "Înapoi",
        cancel: "Anulează",
    },
    title: "Corrai",
    nav: {
        exams: "Examene",
        settings: "Setări",
        login: "Autentificare",
        logout: "Deconectare"
    },
    examList: {
        subtitle: "Gestionați examenele",
        empty: "Niciun examen încă."
    },
    createExam: {
        title: "Creează examen",
        subtitle: "Introduceți numele, materia și data examenului",
        editTitle: "Editează examenul",
        editSubtitle: "Actualizați numele, materia și data examenului"
    },
    exam: {
        loading: "Se încarcă examenul...",
        error: "Eroare",
        back: "← Înapoi",
        createNew: "Creează examen nou",
        edit: "Editează",
        name: "Nume",
        namePlaceholder: "Introduceți numele examenului",
        subject: "Materie",
        subjectPlaceholder: "Introduceți materia",
        date: "Dată",
        create: "Creează examen",
        creating: "Se creează...",
        save: "Salvează",
        saving: "Se salvează...",
        delete: "Șterge examenul",
        deleting: "Se șterge...",
        deleteConfirm: "Sigur doriți să ștergeți acest examen?",
        deleteError: "Ștergerea examenului a eșuat",
        details: "Detalii examen",
        notFound: "Examen negăsit",
        notFoundMessage: "Nu s-a putut găsi examenul cu hash \"{hash}\".",
        saveError: "Salvarea examenului a eșuat",
        createError: "Crearea examenului a eșuat",
        files: "Fișiere",
        filesEmpty: "Nu există încă fișiere asociate acestui examen.",
        addFiles: "Adaugă fișiere",
        addFilesTitle: "Adaugă fișiere",
        dropzoneHint: "Trageți și plasați fișiere aici sau faceți clic pentru a selecta",
        uploading: "Se încarcă...",
        uploadDone: "Încărcat",
        uploadError: "Încărcarea fișierului a eșuat"
    },
    language: "Limbă",
    settings: {
        title: "Setări",
        subtitle: "Configurați preferințele și opțiunile sistemului",
        general: "General",
        admin_console_link: "Link consolă administrare",
        copy_link: "Copiați linkul în clipboard",
        admin_locale: "Limba interfeței de administrare",
        notifications: "Notificări",
        notifications_permission_denied_warning: "Notificările browserului sunt dezactivate. Activați-le pentru actualizări importante.",
        notifications_permission_denied: "Permisiunea a fost refuzată. Activați notificările în setările browserului.",
        notifications_enabled: "Notificările au fost activate cu succes!",
        notifications_enabled_status: "Notificările browserului sunt activate.",
        notifications_not_requested: "Activați notificările browserului pentru actualizări importante.",
        enable_notifications: "Activează notificările",
        create_webpush_subscription: "Creează abonament Web Push",
        notifications_error: "A apărut o eroare la configurarea notificărilor.",
        reset_notifications: "Resetează notificările",
        reset_notifications_confirm: "Sigur doriți să resetați și să dezactivați notificările?",
        notifications_reset_success: "Notificările au fost resetate cu succes!",
        notifications_reset_error: "A apărut o eroare la resetarea notificărilor."
    },
    notFound: {
        title: "Pagină negăsită",
        message: "Pagina pe care o căutați nu există sau a fost mutată.",
        goHome: "Mergi acasă",
        goBack: "Înapoi"
    },
    notAuthenticated: {
        title: "Neautentificat",
        message: "Nu sunteți autentificat pentru a accesa această pagină. Autentificați-vă pentru a continua.",
        goToLogin: "Mergi la autentificare"
    },
    initProfile: {
        title: "Inițializează profilul",
        subtitle: "Creați un profil nou sau recuperați unul existent",
        createProfile: "Creează profil nou",
        recoverProfile: "Recuperează profil",
        userName: "Nume utilizator",
        optional: "opțional",
        userNamePlaceholder: "Introduceți numele (opțional)",
        create: "Creează profil",
        creating: "Se creează...",
        createError: "Crearea profilului a eșuat. Încercați din nou.",
        recoverDescription: "Recuperarea profilului va fi disponibilă în curând."
    }
} as I18nSchema

export default ro

import { createI18n } from 'vue-i18n'

export const DEFAULT_LOCALE = 'en'
export const AVAILABLE_LOCALES = ['en', 'fr', 'ru', 'uk', 'es', 'pt', 'ro', 'de'] as const

export type AvailableLocale = typeof AVAILABLE_LOCALES[number]

export function isValidLocale(locale: string): locale is AvailableLocale {
    return AVAILABLE_LOCALES.includes(locale as AvailableLocale)
}

export function getBrowserLocale(): AvailableLocale {
    const browserLocales = navigator.languages || [navigator.language]

    for (const locale of browserLocales) {
        const baseLocale = locale.split('-')[0].toLowerCase()
        if (isValidLocale(baseLocale)) {
            return baseLocale
        }
    }

    return DEFAULT_LOCALE
}

export const i18n = createI18n({
    legacy: false,
    locale: getBrowserLocale(),
    fallbackLocale: DEFAULT_LOCALE,
    messages: {}
})

export interface I18nSchema {
    common: {
        back: string
        cancel: string
    }
    title: string
    nav: {
        exams: string
        settings: string
        login: string
        logout: string
    }
    examList: {
        subtitle: string
        empty: string
    }
    createExam: {
        title: string
        subtitle: string
        editTitle: string
        editSubtitle: string
    }
    exam: {
        loading: string
        error: string
        back: string
        createNew: string
        edit: string
        name: string
        namePlaceholder: string
        subject: string
        subjectPlaceholder: string
        date: string
        create: string
        creating: string
        save: string
        saving: string
        delete: string
        deleting: string
        deleteConfirm: string
        deleteError: string
        details: string
        notFound: string
        notFoundMessage: string
        saveError: string
        createError: string
        files: string
        filesEmpty: string
        addFiles: string
        addFilesTitle: string
        dropzoneHint: string
        uploading: string
        uploadDone: string
        uploadError: string
        viewByType: string
        viewByAuthor: string
        fileTypeSubject: string
        fileTypeSolution: string
        fileTypeSubmission: string
        fileTypeInstructions: string
        fileTypeUnknown: string
        fileChangeType: string
        fileSetAuthor: string
        fileAuthorPlaceholder: string
        fileAuthorSave: string
        fileAuthorUnknown: string
        fileZoneEmpty: string
        fileAuthorsEmpty: string
        fileAuthorBack: string
        fileUpdateError: string
        fileView: string
        fileRename: string
        fileDelete: string
        fileDeleteTitle: string
        fileDeleteConfirm: string
        fileDeleting: string
        fileDeleteError: string
        fileRenameTitle: string
        fileRenamePlaceholder: string
        fileRenameSave: string
        fileRenaming: string
        fileRenameError: string
    }
    language: string
    settings: {
        title: string
        subtitle: string
        general: string
        admin_console_link: string
        copy_link: string
        admin_locale: string
        notifications: string
        notifications_permission_denied_warning: string
        notifications_permission_denied: string
        notifications_enabled: string
        notifications_enabled_status: string
        notifications_not_requested: string
        enable_notifications: string
        create_webpush_subscription: string
        notifications_error: string
        reset_notifications: string
        reset_notifications_confirm: string
        notifications_reset_success: string
        notifications_reset_error: string
    }
    notFound: {
        title: string
        message: string
        goHome: string
        goBack: string
    }
    notAuthenticated: {
        title: string
        message: string
        goToLogin: string
    }
    initProfile: {
        title: string
        subtitle: string
        createProfile: string
        recoverProfile: string
        userName: string
        optional: string
        userNamePlaceholder: string
        create: string
        creating: string
        createError: string
        recoverDescription: string
    }
}

const loadedLanguages = new Set<string>()

export async function loadLanguage(lang: string) {
    if (loadedLanguages.has(lang)) {
        i18n.global.locale.value = lang
        return
    }

    const messages = await import(`@/locales/${lang}.ts`)

    i18n.global.setLocaleMessage(lang, messages.default)
    loadedLanguages.add(lang)
    i18n.global.locale.value = lang
}

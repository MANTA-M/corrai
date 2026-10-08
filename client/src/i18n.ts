import { createI18n } from 'vue-i18n'

export const DEFAULT_LOCALE = 'fr'
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
    locale: DEFAULT_LOCALE,
    fallbackLocale: DEFAULT_LOCALE,
    messages: {}
})

export interface I18nSchema {
    common: {
        back: string
        cancel: string
        delete: string
    }
    title: string
    nav: {
        assessments: string
        settings: string
        login: string
        logout: string
        myAccount: string
    }
    assessmentList: {
        subtitle: string
        empty: string
    }
    share: {
        title: string
        subtitle: string
        filesHeading: string
        noFiles: string
        chooseAssessment: string
        noAssessments: string
        uploading: string
        uploadError: string
        loadError: string
    }
    createAssessment: {
        title: string
        subtitle: string
        editTitle: string
        editSubtitle: string
        subjectSubtitle: string
        chooseSubject: string
        noSubject: string
        analyzing: string
        analyzeError: string
        continue: string
        reviewSubtitle: string
        finish: string
    }
    assessment: {
        loading: string
        error: string
        back: string
        createNew: string
        edit: string
        name: string
        namePlaceholder: string
        subject: string
        subjectPlaceholder: string
        country: string
        countryPlaceholder: string
        level: string
        levelPlaceholder: string
        autoDetect: string
        optional: string
        notSpecified: string
        subjects: {
            MathPipeline: string
            Physics: string
            Dictation: string
            English: string
            German: string
            Spanish: string
            Russian: string
            Law: string
            Other: string
        }
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
        dropzoneHint: string
        fileUploadType: string
        uploading: string
        uploadDone: string
        uploadError: string
        uploadProgress: string
        viewByType: string
        viewByStudent: string
        fileTypeSubject: string
        fileTypeSolution: string
        fileTypeSubmission: string
        fileTypeInstructions: string
        fileTypeCorrection: string
        fileTypeDebug: string
        fileTypeUnknown: string
        fileStored: string
        fileChangeType: string
        fileSetStudent: string
        studentNamePlaceholder: string
        fileZoneEmpty: string
        fileStudentBack: string
        fileUpdateError: string
        fileView: string
        fileEdit: string
        fileCorrect: string
        fileCorrecting: string
        fileCorrectError: string
        addInstruction: string
        instructionCreateTitle: string
        instructionEditTitle: string
        instructionTitle: string
        instructionBody: string
        instructionSave: string
        instructionSaving: string
        instructionTitlePrefix: string
        instructionSaveError: string
        instructionLoadError: string
        addSolution: string
        solutionCreateTitle: string
        solutionEditTitle: string
        solutionTitlePrefix: string
        solutionSaveError: string
        solutionLoadError: string
        rename: string
        renaming: string
        fileDeleteTitle: string
        fileDeleteConfirm: string
        fileDeleteError: string
        fileRenameTitle: string
        fileRenamePlaceholder: string
        fileRenameError: string
        fileHistory: string
        fileHistoryEmpty: string
        fileAnnexes: string
        fileAnnexesEmpty: string
        editSubject: string
        addCopies: string
        studentsHeading: string
        studentsEmpty: string
        assessedStudentsNumber: string
        markAverage: string
        markMin: string
        markMax: string
        unassignedFiles: string
        unassignedEmpty: string
        studentOpen: string
        studentDeleteTitle: string
        studentDeleteConfirm: string
        studentDeleteError: string
        studentRenameTitle: string
        studentRenameError: string
        studentNotFound: string
        status: string
        studentMark: string
        studentAppreciation: string
        subjectPageTitle: string
        subjectFiles: string
        subjectFilesEmpty: string
        addSubjectFile: string
        solutionFiles: string
        solutionFilesEmpty: string
        addSolutionFile: string
        studentFilesEmpty: string
        copies: string
        copiesEmpty: string
        results: string
        resultsEmpty: string
        fileAssignStudent: string
        fileReassign: string
        fileReassignTitle: string
        fileReassignNotFound: string
        fileReassignConfirm: string
        fileReassigning: string
        fileEventsTitle: string
        startCorrection: string
        correctionPrice: string
        startCorrectionLaunch: string
        startCorrectionError: string
        startCorrectionSuccess: string
        startCorrectionCancelled: string
        startCorrectionPaymentError: string
        testCorrection: string
    }
    language: string
    settings: {
        title: string
        subtitle: string
        credentials: string
        name: string
        email: string
        password: string
        password_confirm: string
        billing: string
        billing_empty: string
        preferences: string
        delete_account: string
        delete_account_confirm: string
        delete_account_error: string
        identity_error: string
        password_mismatch: string
        country: string
        country_error: string
        debug_mode: string
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
        document.documentElement.lang = lang
        return
    }

    const messages = await import(`@/locales/${lang}.ts`)

    i18n.global.setLocaleMessage(lang, messages.default)
    loadedLanguages.add(lang)
    i18n.global.locale.value = lang
    document.documentElement.lang = lang
}

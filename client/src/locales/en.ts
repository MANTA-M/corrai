import type { I18nSchema } from '@/i18n'

const en: I18nSchema = {
    common: {
        back: "Back",
        cancel: "Cancel",
    },
    title: "Corrai",
    nav: {
        exams: "Exams",
        settings: "Settings",
        login: "Login",
        logout: "Logout"
    },
    examList: {
        subtitle: "Manage your exams",
        empty: "No exams yet."
    },
    createExam: {
        title: "Create Exam",
        subtitle: "Enter the name, subject, and date of your exam",
        editTitle: "Edit Exam",
        editSubtitle: "Update the name, subject, and date of your exam"
    },
    exam: {
        loading: "Loading exam...",
        error: "Error",
        back: "← Back",
        createNew: "Create New Exam",
        edit: "Edit",
        name: "Name",
        namePlaceholder: "Enter exam name",
        subject: "Subject",
        subjectPlaceholder: "Enter subject",
        date: "Date",
        create: "Create Exam",
        creating: "Creating...",
        save: "Save",
        saving: "Saving...",
        delete: "Delete exam",
        deleting: "Deleting...",
        deleteConfirm: "Are you sure you want to delete this exam?",
        deleteError: "Failed to delete exam",
        details: "Exam Details",
        notFound: "Exam Not Found",
        notFoundMessage: "Could not find exam with hash \"{hash}\".",
        saveError: "Failed to save exam",
        createError: "Failed to create exam",
        files: "Files",
        filesEmpty: "No files linked to this exam yet.",
        addFiles: "Add files",
        addFilesTitle: "Add files",
        dropzoneHint: "Drag and drop files here, or click to select files",
        uploading: "Uploading...",
        uploadDone: "Uploaded",
        uploadError: "Failed to upload file",
        viewByType: "By type",
        viewByAuthor: "By author",
        fileTypeSubject: "Subject",
        fileTypeSolution: "Solution",
        fileTypeSubmission: "Submission",
        fileTypeInstructions: "Instructions",
        fileTypeUnknown: "Unknown",
        fileChangeType: "Change type",
        fileSetAuthor: "Set author",
        fileAuthorPlaceholder: "Author name",
        fileAuthorSave: "Save",
        fileAuthorUnknown: "Unknown",
        fileZoneEmpty: "No files",
        fileAuthorsEmpty: "No authors yet.",
        fileAuthorBack: "← Authors",
        fileUpdateError: "Failed to update file"
    },
    language: "Language",
    settings: {
        title: "Settings",
        subtitle: "Configure your preferences and system options",
        general: "General",
        admin_console_link: "Admin Console Link",
        copy_link: "Copy link to clipboard",
        admin_locale: "Admin Interface Language",
        notifications: "Notifications",
        notifications_permission_denied_warning: "Browser notifications are currently disabled. Enable notifications to receive important updates.",
        notifications_permission_denied: "Permission was denied. Please enable notifications in your browser settings.",
        notifications_enabled: "Notifications have been enabled successfully!",
        notifications_enabled_status: "Browser notifications are enabled.",
        notifications_not_requested: "Enable browser notifications to receive important updates.",
        enable_notifications: "Enable Notifications",
        create_webpush_subscription: "Create Web Push Subscription",
        notifications_error: "An error occurred while setting up notifications.",
        reset_notifications: "Reset Notifications",
        reset_notifications_confirm: "Are you sure you want to reset and disable notifications?",
        notifications_reset_success: "Notifications have been reset successfully!",
        notifications_reset_error: "An error occurred while resetting notifications."
    },
    notFound: {
        title: "Page Not Found",
        message: "The page you're looking for doesn't exist or has been moved.",
        goHome: "Go Home",
        goBack: "Go Back"
    },
    notAuthenticated: {
        title: "Not Authenticated",
        message: "You are not authenticated to access this page. Please log in to continue.",
        goToLogin: "Go to Login"
    },
    initProfile: {
        title: "Initialize Profile",
        subtitle: "Create a new profile or recover an existing one",
        createProfile: "Create New Profile",
        recoverProfile: "Recover Profile",
        userName: "User Name",
        optional: "optional",
        userNamePlaceholder: "Enter your name (optional)",
        create: "Create Profile",
        creating: "Creating...",
        createError: "Failed to create profile. Please try again.",
        recoverDescription: "Profile recovery functionality will be available soon."
    }
} as I18nSchema

export default en

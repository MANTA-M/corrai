import type { I18nSchema } from '@/i18n'

const uk: I18nSchema = {
    common: {
        back: "Назад",
        cancel: "Скасувати",
    },
    title: "Corrai",
    nav: {
        exams: "Іспити",
        settings: "Налаштування",
        login: "Вхід",
        logout: "Вихід"
    },
    examList: {
        subtitle: "Керуйте своїми іспитами",
        empty: "Ще немає іспитів."
    },
    createExam: {
        title: "Створити іспит",
        subtitle: "Введіть назву, предмет і дату іспиту",
        editTitle: "Редагувати іспит",
        editSubtitle: "Оновіть назву, предмет і дату іспиту"
    },
    exam: {
        loading: "Завантаження іспиту...",
        error: "Помилка",
        back: "← Назад",
        createNew: "Створити новий іспит",
        edit: "Редагувати",
        name: "Назва",
        namePlaceholder: "Введіть назву іспиту",
        subject: "Предмет",
        subjectPlaceholder: "Введіть предмет",
        date: "Дата",
        create: "Створити іспит",
        creating: "Створення...",
        save: "Зберегти",
        saving: "Збереження...",
        delete: "Видалити іспит",
        deleting: "Видалення...",
        deleteConfirm: "Ви впевнені, що хочете видалити цей іспит?",
        deleteError: "Не вдалося видалити іспит",
        details: "Деталі іспиту",
        notFound: "Іспит не знайдено",
        notFoundMessage: "Не вдалося знайти іспит з хешем \"{hash}\".",
        saveError: "Не вдалося зберегти іспит",
        createError: "Не вдалося створити іспит",
        files: "Файли",
        filesEmpty: "До цього іспиту ще не прив’язано файлів.",
        addFiles: "Додати файли",
        addFilesTitle: "Додати файли",
        dropzoneHint: "Перетягніть файли сюди або натисніть, щоб вибрати",
        uploading: "Завантаження...",
        uploadDone: "Завантажено",
        uploadError: "Не вдалося завантажити файл",
        viewByType: "За типом",
        viewByAuthor: "За автором",
        fileTypeSubject: "Завдання",
        fileTypeSolution: "Розв'язок",
        fileTypeSubmission: "Робота",
        fileTypeInstructions: "Інструкції",
        fileTypeUnknown: "Невідомо",
        fileChangeType: "Змінити тип",
        fileSetAuthor: "Вказати автора",
        fileAuthorPlaceholder: "Ім'я автора",
        fileAuthorSave: "Зберегти",
        fileAuthorUnknown: "Невідомо",
        fileZoneEmpty: "Немає файлів",
        fileAuthorsEmpty: "Авторів ще немає.",
        fileAuthorBack: "← Автори",
        fileUpdateError: "Не вдалося оновити файл"
    },
    language: "Мова",
    settings: {
        title: "Налаштування",
        subtitle: "Налаштуйте ваші вподобання та параметри системи",
        general: "Загальні",
        admin_console_link: "Посилання на консоль адміністратора",
        copy_link: "Скопіювати посилання в буфер обміну",
        admin_locale: "Мова Адміністративного Інтерфейсу",
        notifications: "Сповіщення",
        notifications_permission_denied_warning: "Сповіщення браузера зараз вимкнені. Увімкніть сповіщення, щоб отримувати важливі оновлення.",
        notifications_permission_denied: "Дозвіл був відхилений. Будь ласка, увімкніть сповіщення в налаштуваннях браузера.",
        notifications_enabled: "Сповіщення успішно увімкнено!",
        notifications_enabled_status: "Сповіщення браузера увімкнено.",
        notifications_not_requested: "Увімкніть сповіщення браузера, щоб отримувати важливі оновлення.",
        enable_notifications: "Увімкнути Сповіщення",
        create_webpush_subscription: "Створити підписку Web Push",
        notifications_error: "Сталася помилка під час налаштування сповіщень.",
        reset_notifications: "Скинути Сповіщення",
        reset_notifications_confirm: "Ви впевнені, що хочете скинути та вимкнути сповіщення?",
        notifications_reset_success: "Сповіщення успішно скинуті!",
        notifications_reset_error: "Сталася помилка під час скидання сповіщень."
    },
    notFound: {
        title: "Сторінку не знайдено",
        message: "Сторінка, яку ви шукаєте, не існує або була переміщена.",
        goHome: "На головну",
        goBack: "Назад"
    },
    notAuthenticated: {
        title: "Не авторизовано",
        message: "Ви не авторизовані для доступу до цієї сторінки. Будь ласка, увійдіть, щоб продовжити.",
        goToLogin: "Перейти до входу"
    },
    initProfile: {
        title: "Ініціалізація профілю",
        subtitle: "Створіть новий профіль або відновіть існуючий",
        createProfile: "Створити новий профіль",
        recoverProfile: "Відновити профіль",
        userName: "Ім'я користувача",
        optional: "необов'язково",
        userNamePlaceholder: "Введіть ваше ім'я (необов'язково)",
        create: "Створити профіль",
        creating: "Створення...",
        createError: "Не вдалося створити профіль. Спробуйте знову.",
        recoverDescription: "Відновлення профілю буде доступне найближчим часом."
    }
} as I18nSchema

export default uk

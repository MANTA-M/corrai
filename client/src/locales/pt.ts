import type { I18nSchema } from '@/i18n'

const pt: I18nSchema = {
    common: {
        back: "Voltar",
        cancel: "Cancelar",
    },
    title: "Corrai",
    nav: {
        exams: "Exames",
        settings: "Configurações",
        login: "Entrar",
        logout: "Sair"
    },
    examList: {
        subtitle: "Gerencie seus exames",
        empty: "Nenhum exame ainda."
    },
    createExam: {
        title: "Criar exame",
        subtitle: "Introduza o nome, a disciplina e a data do seu exame",
        editTitle: "Editar exame",
        editSubtitle: "Atualize o nome, a disciplina e a data do seu exame"
    },
    exam: {
        loading: "A carregar exame...",
        error: "Erro",
        back: "← Voltar",
        createNew: "Criar novo exame",
        edit: "Editar",
        name: "Nome",
        namePlaceholder: "Introduza o nome do exame",
        subject: "Disciplina",
        subjectPlaceholder: "Introduza a disciplina",
        date: "Data",
        create: "Criar exame",
        creating: "A criar...",
        save: "Guardar",
        saving: "A guardar...",
        delete: "Eliminar exame",
        deleting: "A eliminar...",
        deleteConfirm: "Tem a certeza de que pretende eliminar este exame?",
        deleteError: "Falha ao eliminar o exame",
        details: "Detalhes do exame",
        notFound: "Exame não encontrado",
        notFoundMessage: "Não foi possível encontrar o exame com hash \"{hash}\".",
        saveError: "Falha ao guardar o exame",
        createError: "Falha ao criar o exame",
        files: "Ficheiros",
        filesEmpty: "Ainda não há ficheiros associados a este exame.",
        addFiles: "Adicionar ficheiros",
        addFilesTitle: "Adicionar ficheiros",
        dropzoneHint: "Arraste e largue ficheiros aqui, ou clique para selecionar",
        uploading: "A carregar...",
        uploadDone: "Carregado",
        uploadError: "Falha ao carregar o ficheiro"
    },
    language: "Idioma",
    settings: {
        title: "Configurações",
        subtitle: "Configure as suas preferências e opções do sistema",
        general: "Geral",
        admin_console_link: "Link da consola de administração",
        copy_link: "Copiar link para a área de transferência",
        admin_locale: "Idioma da interface de administração",
        notifications: "Notificações",
        notifications_permission_denied_warning: "As notificações do navegador estão desativadas. Ative-as para receber atualizações importantes.",
        notifications_permission_denied: "A permissão foi negada. Ative as notificações nas definições do navegador.",
        notifications_enabled: "As notificações foram ativadas com sucesso!",
        notifications_enabled_status: "As notificações do navegador estão ativadas.",
        notifications_not_requested: "Ative as notificações do navegador para receber atualizações importantes.",
        enable_notifications: "Ativar notificações",
        create_webpush_subscription: "Criar subscrição Web Push",
        notifications_error: "Ocorreu um erro ao configurar as notificações.",
        reset_notifications: "Repor notificações",
        reset_notifications_confirm: "Tem a certeza de que pretende repor e desativar as notificações?",
        notifications_reset_success: "As notificações foram repostas com sucesso!",
        notifications_reset_error: "Ocorreu um erro ao repor as notificações."
    },
    notFound: {
        title: "Página não encontrada",
        message: "A página que procura não existe ou foi movida.",
        goHome: "Ir para o início",
        goBack: "Voltar"
    },
    notAuthenticated: {
        title: "Não autenticado",
        message: "Não está autenticado para aceder a esta página. Inicie sessão para continuar.",
        goToLogin: "Ir para o login"
    },
    initProfile: {
        title: "Inicializar perfil",
        subtitle: "Crie um novo perfil ou recupere um existente",
        createProfile: "Criar novo perfil",
        recoverProfile: "Recuperar perfil",
        userName: "Nome de utilizador",
        optional: "opcional",
        userNamePlaceholder: "Introduza o seu nome (opcional)",
        create: "Criar perfil",
        creating: "A criar...",
        createError: "Falha ao criar o perfil. Tente novamente.",
        recoverDescription: "A recuperação de perfil estará disponível em breve."
    }
} as I18nSchema

export default pt

import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: '/exam-list'
    },
    {
      path: '/settings_page',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { title: 'Settings', requiresAuth: true }
    },
    {
      path: '/exam-list',
      name: 'exam-list',
      component: () => import('@/views/ExamList.vue'),
      meta: { title: 'Exams', requiresAuth: true }
    },
    {
      path: '/create_exam',
      name: 'create-exam',
      component: () => import('@/views/CreateExamView.vue'),
      meta: { title: 'Create Exam', requiresAuth: true }
    },
    {
      path: '/exam/:id/edit',
      name: 'exam-edit',
      component: () => import('@/views/CreateExamView.vue'),
      meta: { title: 'Edit Exam', requiresAuth: true }
    },
    {
      path: '/exam/:id',
      name: 'exam',
      component: () => import('@/views/ExamView.vue'),
      meta: { title: 'Exam', requiresAuth: true }
    },
    {
      path: '/not-authenticated',
      name: 'not-authenticated',
      component: () => import('@/views/NotAuthenticatedView.vue'),
      meta: { title: 'Not Authenticated', requiresAuth: false }
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { title: 'Page Not Found' }
    }
  ]
})

export default router

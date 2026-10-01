import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      redirect: '/assessment-list'
    },
    {
      path: '/settings_page',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { title: 'Settings', requiresAuth: true }
    },
    {
      path: '/assessment-list',
      name: 'assessment-list',
      component: () => import('@/views/AssessmentList.vue'),
      meta: { title: 'Assessments', requiresAuth: true }
    },
    {
      path: '/create_assessment',
      name: 'create-assessment',
      component: () => import('@/views/CreateAssessmentView.vue'),
      meta: { title: 'Create Assessment', requiresAuth: true }
    },
    {
      path: '/assessment/:id/edit',
      name: 'assessment-edit',
      component: () => import('@/views/CreateAssessmentView.vue'),
      meta: { title: 'Edit Assessment', requiresAuth: true }
    },
    {
      path: '/assessment/:id/sujet',
      name: 'assessment-subject',
      component: () => import('@/views/SubjectView.vue'),
      meta: { title: 'Sujet', requiresAuth: true }
    },
    {
      path: '/assessment/:id/student/:studentId',
      name: 'assessment-student',
      component: () => import('@/views/StudentView.vue'),
      meta: { title: 'Élève', requiresAuth: true }
    },
    {
      path: '/assessment/:id',
      name: 'assessment',
      component: () => import('@/views/AssessmentView.vue'),
      meta: { title: 'Assessment', requiresAuth: true }
    },
    {
      path: '/share',
      name: 'share',
      component: () => import('@/views/ShareView.vue'),
      meta: { title: 'Share', requiresAuth: true }
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

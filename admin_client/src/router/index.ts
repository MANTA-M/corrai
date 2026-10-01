import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'schools',
      component: () => import('@/views/SchoolList.vue'),
      meta: { title: 'Schools' },
    },
    {
      path: '/monitoring',
      name: 'monitoring',
      component: () => import('@/views/Monitoring.vue'),
      meta: { title: 'Monitoring' },
    },
    {
      path: '/schools/:id',
      name: 'school',
      component: () => import('@/views/SchoolPage.vue'),
      meta: { title: 'School' },
    },
    {
      path: '/schools/:schoolId/teachers/:id',
      name: 'teacher',
      component: () => import('@/views/TeacherPage.vue'),
      meta: { title: 'Teacher' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

export default router

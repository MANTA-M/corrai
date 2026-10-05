/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_CORRAI_ROOT?: string
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

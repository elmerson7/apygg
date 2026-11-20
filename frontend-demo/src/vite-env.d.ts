/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base de la API vista desde el navegador (por defecto `/api`, que el proxy de Vite reenvía a VITE_API_URL). */
  readonly VITE_API_BASE?: string
}
interface ImportMeta {
  readonly env: ImportMetaEnv
}

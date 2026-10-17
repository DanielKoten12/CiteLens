/// <reference types="vite/client" />

/**
 * Environment variables exposed to the SPA bundle.
 *
 * `VITE_API_BASE_URL` is the base for every Laravel API call
 * (`docs/API_SPEC.md` §1). It stays relative so the dev-server proxy and the
 * production nginx reverse proxy keep requests same-origin.
 */
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

const BASE = import.meta.env.VITE_API_BASE_URL ?? "/api/v1";
const TOKEN_KEY = "citelens_token";

export const tokenStorage = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public details?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Gabungkan semua pesan validasi (422) jadi satu kalimat. */
  get detailMessages(): string {
    if (!this.details) return this.message;
    const all = Object.values(this.details).flat();
    return all.length ? all.join(" ") : this.message;
  }
}

let onUnauthorized: (() => void) | null = null;

/** Dipanggil saat API membalas 401 padahal token ada (token kedaluwarsa/dicabut). */
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn;
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  const token = tokenStorage.get();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  // FormData: biarkan browser mengisi Content-Type + boundary sendiri.
  if (init.body && !(init.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }

  let res: Response;
  try {
    res = await fetch(`${BASE}${path}`, { ...init, headers });
  } catch {
    throw new ApiError(0, "NETWORK_ERROR", "Tidak dapat terhubung ke server.");
  }

  // 204 No Content / body bukan JSON -> null
  const body = await res.json().catch(() => null);

  if (!res.ok) {
    const e = body?.error;
    if (res.status === 401 && token) onUnauthorized?.();
    throw new ApiError(
      res.status,
      e?.code ?? "SERVER_ERROR",
      e?.message ?? "Terjadi kesalahan pada server.",
      e?.details,
    );
  }

  return body as T;
}
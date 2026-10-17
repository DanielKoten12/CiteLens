import { onUnmounted } from "vue";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import type { DocumentStatusData } from "@/types/api";

/**
 * Polling status analisis sesuai API_SPEC:
 * tiap 2 detik, melambat jadi 5 detik setelah 30 detik,
 * berhenti saat status completed / failed.
 *
 * Error 404/403 (dokumen tidak ada / bukan milik pengguna) menghentikan
 * polling dan memanggil onFatal. Error lain (jaringan, 429) dicoba lagi.
 */
export function useDocumentPolling(
  id: string,
  onUpdate: (status: DocumentStatusData) => void,
  onFatal?: (error: ApiError) => void,
) {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let stopped = true;
  let startedAt = 0;

  async function tick() {
    if (stopped) return;
    try {
      const { data } = await documentsApi.status(id);
      if (stopped) return;
      onUpdate(data);
      if (data.status === "completed" || data.status === "failed") {
        stopped = true;
        return;
      }
    } catch (e) {
      if (e instanceof ApiError && (e.status === 404 || e.status === 403)) {
        stopped = true;
        onFatal?.(e);
        return;
      }
      // Gagal sesaat (jaringan / 429): coba lagi di putaran berikutnya.
    }
    const delay = Date.now() - startedAt > 30_000 ? 5000 : 2000;
    timer = setTimeout(tick, delay);
  }

  function start() {
    stop();
    stopped = false;
    startedAt = Date.now();
    tick();
  }

  function stop() {
    stopped = true;
    if (timer) clearTimeout(timer);
  }

  onUnmounted(stop);

  return { start, stop };
}
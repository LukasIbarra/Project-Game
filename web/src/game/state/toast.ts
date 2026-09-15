// Fase 14: store mínimo para el sistema de Toast Notifications. Mismo
// mecanismo que ya usa el resto del proyecto para sincronizar UI entre
// componentes sin librería de estado (CustomEvent en window, ver
// playerState.ts/PLAYER_STATE_CHANGED_EVENT de Fase 11) — ya estaba
// previsto así en ROADMAP.md (Fase 14: "Dispatcher:
// window.dispatchEvent(new CustomEvent('notify', ...))").
//
// `ToastHost.astro` (montado una única vez en AppShell.astro) es el único
// que escucha este evento y pinta los toasts; este módulo es la única
// forma de dispararlos -cualquier página/componente hace
// `import { toast } from ".../state/toast"` y listo, sin acoplarse al DOM
// de ToastHost-.
export type ToastType = "success" | "error" | "info" | "warning";

export const NOTIFY_EVENT = "notify";

export interface ToastDetail {
  type: ToastType;
  message: string;
  // Opcional -ToastHost aplica un default razonable si no se manda-.
  durationMs?: number;
}

function notify(type: ToastType, message: string, durationMs?: number): void {
  window.dispatchEvent(new CustomEvent<ToastDetail>(NOTIFY_EVENT, { detail: { type, message, durationMs } }));
}

// API ergonómica pedida por la fase (`toast.success(...)`, `toast.error(...)`),
// implementada como wrapper fino sobre el CustomEvent de arriba -nunca una
// segunda fuente de verdad-.
export const toast = {
  success: (message: string, durationMs?: number) => notify("success", message, durationMs),
  error: (message: string, durationMs?: number) => notify("error", message, durationMs),
  info: (message: string, durationMs?: number) => notify("info", message, durationMs),
  warning: (message: string, durationMs?: number) => notify("warning", message, durationMs),
};

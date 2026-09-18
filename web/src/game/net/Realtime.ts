// Fase Reverb: única capa de la app que sabe crear/configurar Laravel Echo
// -mismo criterio que ApiClient.ts para HTTP: cualquier componente que
// necesite tiempo real importa getEcho() de acá, nunca instancia Echo ni
// pusher-js por su cuenta (GlobalChat.astro no debe conocer host/puerto/
// TLS/app key/authorizer, ver docs/REALTIME_CHAT_AUDIT.md).
import Echo from "laravel-echo";
import Pusher from "pusher-js";
import type { ChannelAuthorizationCallback } from "pusher-js";
import { authorizeBroadcastChannel } from "./ApiClient";

// pusher-js espera encontrar el constructor en window.Pusher (mismo patrón
// que documenta Laravel) -el paquete no trae una declaración de tipos para
// window, así que se amplía acá una sola vez.
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

// pusher-js no re-exporta `ChannelAuthorizationData` desde su punto de
// entrada público (node_modules/pusher-js/index.d.ts solo expone
// `ChannelAuthorizationCallback`, no el tipo de su segundo parámetro) — se
// deriva el mismo tipo desde ahí en vez de importar una ruta interna no
// pública o usar `any`.
type ChannelAuthorizationData = Parameters<ChannelAuthorizationCallback>[1];

let echo: Echo<"reverb"> | null = null;

// Singleton: nunca crear una segunda instancia de Echo en la misma página
// -evitaría conexiones WebSocket y listeners duplicados sobre el mismo
// canal. Todo lo que toca `window`/variables de entorno vive DENTRO de
// esta función, nunca en el nivel superior del módulo (mismo criterio que
// ApiClient.ts con localStorage): este archivo solo se importa desde
// <script> de cliente, nunca desde el frontmatter de Astro.
export function getEcho(): Echo<"reverb"> {
  if (echo) return echo;

  window.Pusher = Pusher;

  echo = new Echo<"reverb">({
    broadcaster: "reverb",
    key: import.meta.env.PUBLIC_REVERB_APP_KEY,
    wsHost: import.meta.env.PUBLIC_REVERB_HOST,
    wsPort: Number(import.meta.env.PUBLIC_REVERB_PORT ?? 443),
    wssPort: Number(import.meta.env.PUBLIC_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.PUBLIC_REVERB_SCHEME ?? "https") === "https",
    enabledTransports: ["ws", "wss"],
    // Canal privado autenticado por Bearer, nunca por cookie -adapta el
    // ejemplo oficial de Sanctum (pensado para una SPA con sesión + axios)
    // al ApiClient.ts real de este proyecto, que ya adjunta Authorization:
    // Bearer solo. Firma verificada contra los tipos reales instalados de
    // pusher-js@8.6.0 (types/src/core/auth/options.d.ts): el callback es
    // (error: Error | null, authData: ChannelAuthorizationData | null),
    // no el (boolean, data) que muestran ejemplos escritos para otra
    // versión.
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        authorizeBroadcastChannel(socketId, channel.name)
          .then((authData) => {
            // authorizeBroadcastChannel() devuelve `unknown` a propósito
            // (ver ApiClient.ts: la forma exacta de la respuesta de
            // Laravel nunca se verificó en runtime) — se tipa acá recién,
            // en el único lugar que la consume, en vez de imponerle una
            // forma no confirmada a ApiClient.ts.
            callback(null, authData as ChannelAuthorizationData);
          })
          .catch((error: unknown) => {
            callback(error instanceof Error ? error : new Error(String(error)), null);
          });
      },
    }),
  });

  return echo;
}

// Fase 19.5: segunda suscripción sobre el MISMO singleton de arriba -nunca
// una segunda conexión Echo/Pusher-, esta vez sobre el canal PÚBLICO
// "world" (ver backend/app/Events/PlayerMoved.php: new Channel('world'),
// nunca Private/Presence -por eso getEcho().channel(), no .private()-,
// sin ningún round-trip de autorización contra /broadcasting/auth).
//
// PlayerMoved::broadcastAs() devuelve el nombre customizado 'PlayerMoved'
// -mismo motivo que ChatMessageCreated ya documentaba acá: un broadcast
// name customizado exige el punto inicial en .listen(), si no Echo arma el
// nombre completo por defecto (namespace + clase) y nunca matchea contra
// lo que realmente emite Laravel.
export interface PlayerMovedEvent {
  character_id: number;
  name: string;
  level: number;
  x: number;
  y: number;
  direction: "up" | "down" | "left" | "right";
}

// Devuelve una función de limpieza que abandona el canal "world" por
// completo (getEcho().leaveChannel) -mismo patrón que ya usa
// GlobalChat.astro para "chat"-. Nunca lanza: si Echo o la suscripción
// fallan (ej. variables PUBLIC_REVERB_* no configuradas en este entorno),
// Mundo debe poder seguir funcionando sin tiempo real, igual que el chat
// sigue funcionando por polling si esto falla.
export function subscribeWorld(onPlayerMoved: (event: PlayerMovedEvent) => void): () => void {
  try {
    const channel = getEcho().channel("world");
    channel.listen(".PlayerMoved", onPlayerMoved);

    return () => {
      try {
        getEcho().leaveChannel("world");
      } catch (err) {
        console.warn("No se pudo abandonar el canal world.", err);
      }
    };
  } catch (err) {
    console.warn("No se pudo suscribir al canal world -Mundo sigue funcionando sin tiempo real.", err);
    return () => {};
  }
}

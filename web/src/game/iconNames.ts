// Fase 18: IconName vivía declarado inline en Icon.astro. Un archivo .ts
// plano no puede importar un tipo DESDE un .astro bajo `tsc --noEmit` (el
// chequeo de tipos que usa este proyecto) -el propio compilador de Astro sí
// resuelve imports de tipos entre archivos .astro, pero tsc no tiene ese
// shim para módulos .astro. Se extrae acá sin tocar nada del resto de
// Icon.astro (PIXEL_ICONS/paths/render siguen exactamente igual); ese
// archivo ahora re-exporta este mismo tipo, así que game/navigation.ts
// puede importarlo directo y el resto de los consumidores (.astro) siguen
// importándolo desde Icon.astro sin ningún cambio.
export type IconName =
  | "home"
  | "house"
  | "pet"
  | "arena"
  | "world"
  | "social"
  | "inventory"
  | "ranking"
  | "chat"
  | "bell"
  | "logout"
  | "coin"
  | "craft"
  | "shop"
  | "attack"
  | "shield"
  | "victory"
  | "defeat"
  | "history"
  | "chevron-down"
  | "chevron-right"
  | "toast-success"
  | "toast-error"
  | "toast-info"
  | "toast-warning";

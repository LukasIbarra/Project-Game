// Fase 11: fuente única de verdad del estado del jugador (nivel/XP/monedas)
// en el cliente. Backend -> ApiClient (getCharacter) -> este módulo -> HUD/
// Inicio (y cualquier página futura) vía CustomEvent, mismo mecanismo que ya
// usaba el evento puntual "coins:changed" (F8) generalizado acá para
// cualquier cambio de estado del jugador, no solo monedas.
import { getCharacter, type CharacterDto } from "../net/ApiClient";

export const PLAYER_STATE_CHANGED_EVENT = "player:changed";

let cached: CharacterDto | null = null;

export function getCachedPlayerState(): CharacterDto | null {
  return cached;
}

// Vuelve a pedir el personaje al backend (nunca se calcula nada acá,
// CLAUDE.md #1) y avisa a quien esté escuchando. Cualquier acción que pueda
// cambiar nivel/exp/monedas (combate, venta, crafting, futuras compras)
// debe llamar a esto para que el HUD se actualice sin recargar la página.
export async function refreshPlayerState(): Promise<CharacterDto> {
  const character = await getCharacter();
  cached = character;
  window.dispatchEvent(new CustomEvent<CharacterDto>(PLAYER_STATE_CHANGED_EVENT, { detail: character }));
  return character;
}

export function expPercent(character: Pick<CharacterDto, "exp" | "exp_to_next_level">): number {
  if (character.exp_to_next_level <= 0) return 0;
  return Math.max(0, Math.min(100, Math.round((character.exp / character.exp_to_next_level) * 100)));
}

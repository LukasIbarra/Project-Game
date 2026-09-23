// Fase 1: cliente HTTP con autenticación por token Sanctum
// (Authorization: Bearer ...). El token se guarda en localStorage
// y se adjunta automáticamente a cada request autenticado.

const API_BASE_URL = import.meta.env.PUBLIC_API_URL ?? "http://localhost:8000";
const TOKEN_STORAGE_KEY = "auth_token";

export interface AuthUser {
  id: number;
  name: string;
  email: string;
}

export interface AuthResponse {
  user: AuthUser;
  token: string;
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY);
}

function setToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY);
}

export function isAuthenticated(): boolean {
  return getToken() !== null;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(options.body ? { "Content-Type": "application/json" } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers as Record<string, string>),
  };

  // no-store: el backend es la única fuente de verdad (CLAUDE.md #1) -en
  // particular Fase 7 depende de releer el estado real después de cada
  // acción (start/claim), nunca de una respuesta GET cacheada por el
  // navegador.
  const response = await fetch(`${API_BASE_URL}${path}`, { ...options, headers, cache: "no-store" });

  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new ApiError(response.status, body);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export class ApiError extends Error {
  status: number;
  body: unknown;

  constructor(status: number, body: unknown) {
    super(`HTTP ${status}`);
    this.status = status;
    this.body = body;
  }
}

// F8: los endpoints de venta/crafting devuelven errores de negocio
// lanzando ValidationException del lado de Laravel -forma automática
// `{message, errors: {campo: [..]}}`-, distinto del `{message}` plano
// que devuelven a mano el resto de los controllers (Equipment/Pet/etc.).
// Esta función entiende ambas formas para no duplicar el parseo en cada
// página nueva.
export function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError && err.body && typeof err.body === "object") {
    const body = err.body as { message?: unknown; errors?: Record<string, string[]> };
    if (body.errors) {
      const firstField = Object.values(body.errors)[0];
      if (Array.isArray(firstField) && firstField.length > 0) {
        return String(firstField[0]);
      }
    }
    if (body.message) {
      return String(body.message);
    }
  }
  return fallback;
}

export async function pingApi(): Promise<unknown> {
  return request("/api/v1/ping");
}

export async function register(
  name: string,
  email: string,
  password: string,
  passwordConfirmation: string
): Promise<AuthResponse> {
  const data = await request<AuthResponse>("/api/v1/auth/register", {
    method: "POST",
    body: JSON.stringify({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    }),
  });
  setToken(data.token);
  return data;
}

export async function login(email: string, password: string): Promise<AuthResponse> {
  const data = await request<AuthResponse>("/api/v1/auth/login", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
  setToken(data.token);
  return data;
}

export async function logout(): Promise<void> {
  try {
    await request("/api/v1/auth/logout", { method: "POST" });
  } finally {
    clearToken();
  }
}

export async function me(): Promise<AuthUser> {
  return request("/api/v1/auth/me");
}

// Fase 6: inventario/equipamiento. El personaje siempre se deriva del
// usuario autenticado en el backend -estas funciones nunca mandan un
// character_id, el servidor no lo aceptaría de todos modos.
export interface CharacterDto {
  id: number;
  user_id: number;
  name: string;
  level: number;
  exp: number;
  // Fase 11: reusa la misma fórmula que ya expone GET /v1/arena
  // (CombatStatsService::xpToNextLevel) -nunca se recalcula en el cliente-.
  exp_to_next_level: number;
  strength: number;
  agility: number;
  vitality: number;
  coins: number;
  appearance_json: Record<string, string | null>;
}

export interface ItemDto {
  id: number;
  key: string;
  name: string;
  description: string | null;
  type: string;
  subtype: string | null;
  stackable: boolean;
  max_stack: number;
  sell_value: number;
  icon: string | null;
  rarity: "common" | "uncommon" | "rare" | "very_rare";
  // Bolsa libre por item (F8/Room): para furniture colocable trae
  // {tile_width, tile_height, collision, placeable, rotatable} -ver
  // FurnitureMetadata en game/room/RoomFurniture.ts-, para otros items
  // puede venir null o con otra forma. Tipado suelto a propósito: este
  // DTO es compartido por inventario/crafting/equipamiento/room, no solo
  // por furniture.
  metadata_json: Record<string, unknown> | null;
}

export interface InventoryItemDto {
  id: number;
  character_id: number;
  item_id: number;
  quantity: number;
  item: ItemDto;
}

export interface CharacterEquipmentDto {
  id: number;
  character_id: number;
  inventory_item_id: number;
  slot: string;
  inventory_item: InventoryItemDto;
}

export interface EquipmentStateDto {
  equipment: CharacterEquipmentDto[];
  appearance: Record<string, string | null>;
}

export async function getCharacter(): Promise<CharacterDto> {
  return request("/api/v1/character");
}

export async function getItemCatalog(): Promise<ItemDto[]> {
  return request("/api/v1/items");
}

export async function getInventory(): Promise<InventoryItemDto[]> {
  return request("/api/v1/inventory");
}

export async function grantItem(itemKey: string, quantity = 1): Promise<InventoryItemDto[]> {
  return request("/api/v1/inventory/grant", {
    method: "POST",
    body: JSON.stringify({ item_key: itemKey, quantity }),
  });
}

export async function getEquipment(): Promise<EquipmentStateDto> {
  return request("/api/v1/equipment");
}

export async function equipItem(inventoryItemId: number): Promise<EquipmentStateDto> {
  return request("/api/v1/equipment/equip", {
    method: "POST",
    body: JSON.stringify({ inventory_item_id: inventoryItemId }),
  });
}

export async function unequipSlot(slot: string): Promise<EquipmentStateDto> {
  return request(`/api/v1/equipment/${encodeURIComponent(slot)}`, {
    method: "DELETE",
  });
}

// Fase 7/F21: mascota / expediciones. Igual que arriba, la mascota
// siempre se deriva del usuario autenticado -estas funciones nunca mandan
// un pet_id ni un character_id-. F21 reemplaza el contrato completo (ver
// docs/PETS_EXPEDITIONS_SYSTEM.md §13): el resultado ya no se precalcula
// al iniciar -cada checkpoint se resuelve en su momento real, server-side,
// la primera vez que algo pide el estado después de que correspondía
// resolverse (resolución perezosa de verdad, no solo de la aplicación de
// un resultado ya fijado).
export interface PetReward {
  item_key: string;
  quantity: number;
}

// F21/F22: un checkpoint sin resolver nunca trae payload -ni pistas de qué
// va a pasar-, solo su scheduled_at (para poder mostrar "próximo evento en
// X min" sin revelar nada). Cuando status=awaiting_decision, `event` trae
// lo que el jugador necesita para decidir (título/texto/opciones, leído
// del catálogo server-side) -nunca en `payload`, que sigue siendo
// estrictamente "el resultado ya calculado" (ver PetPresenter::checkpoint).
export interface PetExpeditionCheckpointEvent {
  title: string | null;
  text: string;
  options: string[];
}

export interface PetExpeditionCheckpoint {
  id: number;
  sequence: number;
  scheduled_at: string;
  kind: "narrative" | "event";
  status: "pending" | "awaiting_decision" | "resolved";
  payload: { text?: string; decision?: string; outcome?: string; damage?: number; loot?: PetReward[] } | null;
  event: PetExpeditionCheckpointEvent | null;
}

export interface PetExpedition {
  id: number;
  expedition: string;
  expedition_name: string;
  status: "active" | "completed" | "claimed";
  started_at: string;
  finishes_at: string;
  resolved_at: string | null;
  checkpoints: PetExpeditionCheckpoint[];
  // F22: `loot` es el total combinado, listo para mostrar tal cual (mismo
  // contrato que ya consumía el frontend). expedition_loot/event_loot
  // quedan aparte para no perder la procedencia de cada recompensa -ver
  // ExpeditionService::completeExpedition/appendEventLoot-, hoy no se
  // muestran por separado en la UI (alcance mínimo de F22), pero ya están
  // disponibles para cuando haga falta.
  loot: PetReward[];
  expedition_loot: PetReward[];
  event_loot: (PetReward & { checkpoint_id: number })[];
}

// F23: metadata del spritesheet real de cada especie (grid uniforme de
// frames cuadrados, sin padding entre celdas -ver PetSpeciesAdoptionSeeder).
// `idle_frames` es lo único que consume la animación mínima de esta fase;
// `expression_frames` queda documentado/disponible para cuando se
// implementen las expresiones (happy/fed/expedition/hurt/interact), no se
// usa todavía.
export interface PetSpriteMetaDto {
  file: string;
  frame_width: number;
  frame_height: number;
  columns: number;
  rows: number;
  idle_frames: number[];
  expression_frames: Record<string, number[]>;
}

export interface Pet {
  id: number;
  name: string;
  species: string;
  // Fase 20: nombre amigable de la especie (antes solo existía `species`,
  // la key técnica) -ver PetPresenter::pet().
  species_name: string;
  level: number;
  experience: number;
  health: number;
  max_health: number;
  energy: number;
  max_energy: number;
  status: "idle" | "exploring" | "injured";
  expedition: PetExpedition | null;
  // F23: null solo puede pasar en teoría (especies legacy sin metadata
  // sembrada) -el frontend debe tolerarlo y caer a un render sin animar.
  sprite: PetSpriteMetaDto | null;
}

// F23: GET /pet ahora devuelve 404 cuando el personaje todavía no tiene
// mascota activa (ya no se auto-crea en el registro) -este helper expone
// esa distinción sin que cada caller tenga que andar mirando err.status.
export async function getPet(): Promise<Pet | null> {
  try {
    return await request<Pet>("/api/v1/pet");
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      return null;
    }
    throw err;
  }
}

// F23: catálogo de especies ahora incluye si son elegibles como starter,
// su precio de adopción y si el usuario autenticado ya la posee -sigue sin
// exponer modifiers_json/level_modifiers_json crudo (detalles de balance
// server-side, ver PetPresenter::species).
export interface PetSpeciesDto {
  key: string;
  name: string;
  description: string | null;
  rarity: "common" | "uncommon" | "rare" | "very_rare";
  sprite: PetSpriteMetaDto | null;
  is_starter_option: boolean;
  adoption_price: number;
  owned: boolean;
}

export async function getPetSpecies(): Promise<PetSpeciesDto[]> {
  return request("/api/v1/pet/species");
}

// F23: resumen liviano de una mascota poseída -usado por la colección
// (GET /pet/mine), no trae expedición/status de combate, solo lo necesario
// para listar y distinguir cuál está activa.
export interface PetSummaryDto {
  id: number;
  name: string;
  species: string;
  species_name: string;
  level: number;
  status: "idle" | "exploring" | "injured";
  sprite: PetSpriteMetaDto | null;
}

export interface MyPetsDto {
  active_pet_id: number | null;
  pets: PetSummaryDto[];
}

export async function getMyPets(): Promise<MyPetsDto> {
  return request("/api/v1/pet/mine");
}

// F23: primer starter, siempre gratis -el servidor rechaza (409) si el
// personaje ya tiene una mascota activa o no retirada.
export async function adoptPet(speciesKey: string): Promise<Pet> {
  return request("/api/v1/pet/adopt", {
    method: "POST",
    body: JSON.stringify({ species_key: speciesKey }),
  });
}

// F23: compra de una especie adicional -el precio SIEMPRE sale del
// servidor (pet_species.adoption_price), este cliente nunca lo manda.
export async function purchasePetSpecies(speciesKey: string): Promise<PetSummaryDto> {
  return request(`/api/v1/pet/species/${encodeURIComponent(speciesKey)}/purchase`, {
    method: "POST",
  });
}

// F23: cambia cuál mascota poseída está activa -nunca reasigna expediciones
// históricas, ver PetAdoptionService::setActive.
export async function setActivePet(petId: number): Promise<void> {
  await request("/api/v1/pet/active", {
    method: "POST",
    body: JSON.stringify({ pet_id: petId }),
  });
}

// F21: reward_preview ya viene con % real calculado server-side
// (weight / SUM(weight) * 100) -nunca se expone weight/rareza crudos de
// expedition_rewards tal cual.
export interface ExpeditionRewardPreviewDto {
  item_name: string;
  percent: number;
  rarity_tier: "common" | "uncommon" | "rare" | "very_rare" | null;
}

export interface ExpeditionDefinitionDto {
  key: string;
  name: string;
  description: string | null;
  difficulty: number;
  duration_seconds: number;
  min_pet_level: number;
  reward_preview: ExpeditionRewardPreviewDto[];
}

export async function getExpeditionDefinitions(): Promise<ExpeditionDefinitionDto[]> {
  return request("/api/v1/pet/expeditions/definitions");
}

export async function getCurrentExpedition(): Promise<PetExpedition> {
  return request("/api/v1/pet/expeditions/current");
}

export async function startExpedition(expeditionKey: string): Promise<PetExpedition> {
  return request("/api/v1/pet/expeditions/start", {
    method: "POST",
    body: JSON.stringify({ expedition_key: expeditionKey }),
  });
}

export async function claimExpedition(expeditionId: number): Promise<{ expedition: PetExpedition; loot: PetReward[] }> {
  return request(`/api/v1/pet/expeditions/${expeditionId}/claim`, { method: "POST" });
}

// F21: preparado para F22 (checkpoints kind=event con decisión real) -no
// hay ningún checkpoint en awaiting_decision todavía en la práctica, pero
// el endpoint ya existe con locking/idempotencia reales del lado backend.
export interface DecideCheckpointResultDto {
  id: number;
  status: string;
  decision: string | null;
  payload: { outcome?: string; decision?: string; damage?: number; loot?: PetReward[] } | null;
}

export async function decideCheckpoint(checkpointId: number, decision: string): Promise<DecideCheckpointResultDto> {
  return request(`/api/v1/pet/expeditions/checkpoints/${checkpointId}/decide`, {
    method: "POST",
    body: JSON.stringify({ decision }),
  });
}

export async function getExpeditionHistory(): Promise<PetExpedition[]> {
  return request("/api/v1/pet/expeditions/history");
}

// Fase 20: alimentación -catálogo de comida + acción de alimentar. El
// personaje/mascota siempre se derivan del usuario autenticado del lado
// del backend, estas funciones nunca mandan character_id/pet_id.
export interface PetFoodDto {
  item_key: string;
  item_name: string;
  exp_value: number;
}

export async function getPetFood(): Promise<PetFoodDto[]> {
  return request("/api/v1/pet/food");
}

export interface FeedPetResultDto {
  pet: Pet;
  leveled_up: boolean;
}

export async function feedPet(foodKey: string): Promise<FeedPetResultDto> {
  return request("/api/v1/pet/feed", {
    method: "POST",
    body: JSON.stringify({ food_key: foodKey }),
  });
}

// F8: economía/crafting/venta. Igual que arriba: el cliente solo pide
// "vendé item_key x quantity" o "fabricá recipe_key" — el precio/
// ingredientes/resultado los resuelve siempre el backend (sell_value e
// ingredientes viven en DB, nunca en este archivo ni en el request).
export interface SellResultDto {
  earned: number;
  coins: number;
  inventory: InventoryItemDto[];
}

export async function sellItem(itemKey: string, quantity: number): Promise<SellResultDto> {
  return request("/api/v1/inventory/sell", {
    method: "POST",
    body: JSON.stringify({ item_key: itemKey, quantity }),
  });
}

// Fase 16: Tienda (primera versión, catálogo fijo sin rotación). Mismo
// principio de siempre: el cliente solo pide "comprá item_key x quantity"
// -el precio real vive en `shop_products` (DB), nunca en este archivo ni
// en el request-.
export interface ShopProductDto {
  item_key: string;
  name: string;
  icon: string | null;
  price: number;
}

export interface ShopStateDto {
  products: ShopProductDto[];
  coins: number;
}

export interface PurchaseResultDto {
  spent: number;
  coins: number;
  inventory: InventoryItemDto[];
}

export async function getShop(): Promise<ShopStateDto> {
  return request("/api/v1/shop");
}

export async function buyItem(itemKey: string, quantity: number): Promise<PurchaseResultDto> {
  return request("/api/v1/shop/purchase", {
    method: "POST",
    body: JSON.stringify({ item_key: itemKey, quantity }),
  });
}

export interface RecipeIngredientDto {
  id: number;
  item_id: number;
  quantity: number;
  item: ItemDto;
}

export interface RecipeDto {
  id: number;
  key: string;
  name: string;
  description: string | null;
  category: "materials" | "consumables" | "house" | "equipment";
  rarity: "common" | "uncommon" | "rare" | "very_rare";
  result_item_id: number;
  result_quantity: number;
  required_level: number;
  unlock_condition: string | null;
  is_active: boolean;
  result: ItemDto;
  ingredients: RecipeIngredientDto[];
}

export async function getRecipes(): Promise<RecipeDto[]> {
  return request("/api/v1/recipes");
}

export async function craftRecipe(recipeKey: string): Promise<InventoryItemDto[]> {
  return request("/api/v1/crafting/craft", {
    method: "POST",
    body: JSON.stringify({ recipe_key: recipeKey }),
  });
}

// Room: habitación personal + muebles colocables. Igual que arriba, el
// personaje/habitación siempre se derivan del usuario autenticado -estas
// funciones nunca mandan un character_id ni un room_id-.
export interface RoomObjectDto {
  id: number;
  room_id: number;
  item_id: number;
  x: number;
  y: number;
  rotation: number;
  item: ItemDto;
}

export interface RoomStateDto {
  id: number;
  map_key: string;
  objects: RoomObjectDto[];
}

export async function getRoom(): Promise<RoomStateDto> {
  return request("/api/v1/room");
}

export async function placeRoomObject(
  itemKey: string,
  tileX: number,
  tileY: number
): Promise<{ objects: RoomObjectDto[]; inventory: InventoryItemDto[] }> {
  return request("/api/v1/room/objects", {
    method: "POST",
    body: JSON.stringify({ item_key: itemKey, tile_x: tileX, tile_y: tileY }),
  });
}

export async function moveRoomObject(
  roomObjectId: number,
  tileX: number,
  tileY: number
): Promise<{ objects: RoomObjectDto[] }> {
  return request(`/api/v1/room/objects/${roomObjectId}`, {
    method: "PATCH",
    body: JSON.stringify({ tile_x: tileX, tile_y: tileY }),
  });
}

export async function removeRoomObject(
  roomObjectId: number
): Promise<{ objects: RoomObjectDto[]; inventory: InventoryItemDto[] }> {
  return request(`/api/v1/room/objects/${roomObjectId}`, { method: "DELETE" });
}

// Fase 10: combate asíncrono. El cliente solo elige A QUIÉN atacar -todo
// lo demás (daño/crítico/esquive/ganador/xp/monedas/cooldown) lo calcula
// el backend una sola vez; estos tipos reflejan tal cual la forma de
// `events_json` que arma CombatService, para que BattleScene solo
// reproduzca sin recalcular nada (CLAUDE.md #6).
export interface CombatStatsDto {
  max_hp: number;
  attack: number;
  defense: number;
  crit_chance: number;
  dodge_chance: number;
}

export interface ArenaCharacterDto {
  id: number;
  name: string;
  level: number;
  exp: number;
  exp_to_next_level: number;
  coins: number;
  appearance_json: Record<string, string | null>;
  stats: CombatStatsDto;
  wins: number;
  losses: number;
  rank: number;
}

export interface ArenaOpponentDto {
  character_id: number;
  name: string;
  level: number;
  appearance_json: Record<string, string | null>;
  stats: CombatStatsDto;
  wins: number;
  losses: number;
  cooldown_seconds: number;
}

export interface ArenaRankingEntryDto {
  character_id: number;
  name: string;
  level: number;
  wins: number;
  losses: number;
}

export interface ArenaStateDto {
  character: ArenaCharacterDto;
  opponents: ArenaOpponentDto[];
  ranking: ArenaRankingEntryDto[];
}

export interface CombatEventDto {
  type: "attack" | "critical" | "dodge";
  actor: "attacker" | "defender";
  target: "attacker" | "defender" | null;
  damage: number;
  critical: boolean;
  target_hp_after: number;
}

export interface CombatFighterSnapshotDto {
  character_id: number;
  name: string;
  level: number;
  stats: CombatStatsDto;
}

export interface CombatRewardDto {
  xp: number;
  coins: number;
  leveled_up: boolean;
  new_level: number;
}

export interface CombatResultDto {
  version: number;
  attacker: CombatFighterSnapshotDto;
  defender: CombatFighterSnapshotDto;
  events: CombatEventDto[];
  winner: "attacker" | "defender";
  attacker_hp_remaining: number;
  defender_hp_remaining: number;
  rewards: { attacker: CombatRewardDto; defender: CombatRewardDto };
}

export interface CombatLogDto {
  id: number;
  attacker_character_id: number;
  defender_character_id: number;
  winner_character_id: number;
  seed: string;
  status: string;
  started_at: string;
  finished_at: string | null;
  events_json: CombatResultDto;
  // Fase 17 ("Repetir"): apariencia ACTUAL de cada personaje -no la que
  // tenían en el momento del combate, ese dato nunca se guardó (ver
  // ArenaController::show). null si el personaje ya no existe -en ese
  // caso el frontend no debe montar BattleScene, solo el log de texto.
  attacker_appearance_json: Record<string, string | null> | null;
  defender_appearance_json: Record<string, string | null> | null;
}

// Fase 17: fila de "Historial de Combates" -ya resuelta server-side
// (rol/oponente/resultado relativos a MÍ, ver ArenaController::combats)
// para no duplicar esa lógica acá.
export interface CombatHistoryEntryDto {
  id: number;
  role: "attacker" | "defender";
  opponent_character_id: number | null;
  opponent_name: string;
  result: "victory" | "defeat";
  xp: number;
  coins: number;
  leveled_up: boolean;
  new_level: number | null;
  created_at: string;
}

export interface CombatHistoryPageDto {
  combats: CombatHistoryEntryDto[];
  has_more: boolean;
}

export async function getCombatHistory(beforeId?: number): Promise<CombatHistoryPageDto> {
  const query = beforeId ? `?before_id=${beforeId}` : "";
  return request(`/api/v1/arena/combats${query}`);
}

export async function getArena(): Promise<ArenaStateDto> {
  return request("/api/v1/arena");
}

// Fase 13: /ranking tiene su propio endpoint liviano -reusa
// ArenaRankingService, mismo tipo de fila que ArenaStateDto.ranking
// (ArenaRankingEntryDto), pero sin acoplarse a la respuesta completa de
// Arena (oponentes/cooldowns/mis stats de combate no le sirven).
export interface RankingMeDto {
  character_id: number;
  rank: number;
  wins: number;
  losses: number;
}

export interface RankingStateDto {
  ranking: ArenaRankingEntryDto[];
  me: RankingMeDto | null;
}

export async function getRanking(): Promise<RankingStateDto> {
  return request("/api/v1/ranking");
}

export async function attackCharacter(defenderCharacterId: number): Promise<CombatLogDto> {
  return request("/api/v1/arena/attack", {
    method: "POST",
    body: JSON.stringify({ defender_character_id: defenderCharacterId }),
  });
}

export async function getCombatLog(combatId: number): Promise<CombatLogDto> {
  return request(`/api/v1/arena/combats/${combatId}`);
}

// Chat global (demo, polling HTTP -ver GlobalChat.astro-). El backend
// SIEMPRE deriva el autor del token Sanctum -estas funciones nunca mandan
// un nombre de usuario, el servidor no lo aceptaría de todos modos.
export interface ChatMessageDto {
  id: number;
  user_name: string;
  message: string;
  created_at: string;
}

export async function getChatMessages(afterId?: number): Promise<ChatMessageDto[]> {
  const query = afterId ? `?after_id=${afterId}` : "";
  return request(`/api/v1/chat/messages${query}`);
}

export async function sendChatMessage(message: string): Promise<ChatMessageDto> {
  return request("/api/v1/chat/messages", {
    method: "POST",
    body: JSON.stringify({ message }),
  });
}

// Fase Reverb: autoriza la suscripción a un canal privado de broadcasting
// -mismo request() que todo lo demás acá, así el header Authorization:
// Bearer sale automático (nunca cookies/sesión, ver docs/REALTIME_CHAT_AUDIT.md).
// La consume el authorizer custom de Laravel Echo en Realtime.ts.
export async function authorizeBroadcastChannel(socketId: string, channelName: string): Promise<unknown> {
  return request("/api/v1/broadcasting/auth", {
    method: "POST",
    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
  });
}

// Fase 12: feed de actividad reciente -mismo patrón exacto que el chat
// (after_id, sin WebSockets). El backend ya resuelve el `type` a texto en
// ningún lado -esa traducción vive en el cliente (home.astro)-, `payload`
// llega con forma libre según `type`, nunca tipada campo por campo acá
// porque crecerá con cada fase que agregue un tipo nuevo (Fase 16/17/21/22).
export interface ActivityEventDto {
  id: number;
  type: string;
  payload: Record<string, unknown>;
  created_at: string;
}

export async function getActivityEvents(afterId?: number): Promise<ActivityEventDto[]> {
  const query = afterId ? `?after_id=${afterId}` : "";
  return request(`/api/v1/activity${query}`);
}

// Fase 18: presencia de jugadores (HTTP + polling, sin Reverb -ver
// docs/ROADMAP.md-). Mismo principio de siempre: el personaje SIEMPRE se
// deriva del usuario autenticado del lado del backend, este cliente nunca
// manda un character_id. `current_map` es el único dato que sale de acá,
// ya resuelto a uno de los identificadores válidos de App\Enums\GameMap
// (ver game/navigation.ts) antes de llamar a esta función.
export interface PresenceEntryDto {
  character_id: number;
  name: string;
  level: number;
  current_map: string;
  // Fase 19.5: el backend ya devolvía estos 3 campos desde F19.2
  // (PresenceController::present()) -este tipo se había quedado
  // desactualizado, nunca los declaró. null hasta que el personaje mande
  // una posición real vía POST /presence/position (F19.6).
  position_x: number | null;
  position_y: number | null;
  direction: string | null;
  status: string;
}

export async function sendPresenceHeartbeat(currentMap: string): Promise<void> {
  return request("/api/v1/presence/heartbeat", {
    method: "POST",
    body: JSON.stringify({ current_map: currentMap }),
  });
}

// Fase 19.5: `map` opcional -sin él, comportamiento idéntico a F18 (todos
// los jugadores online). Con él, filtra por current_map server-side (ver
// PresenceController::resolveMapFilter) -ej. Mundo pide únicamente
// getPresence("play").
export async function getPresence(map?: string): Promise<PresenceEntryDto[]> {
  const query = map ? `?map=${encodeURIComponent(map)}` : "";
  return request(`/api/v1/presence${query}`);
}

// Fase 19.6: guarda x/y/direction del personaje autenticado en Mundo (ver
// SendPresencePositionRequest: 0<=x<=1280, 0<=y<=800, direction solo
// cardinal). La respuesta se tipa para que quien la llame pueda loguearla/
// inspeccionarla, pero WorldScene nunca debe usarla para mover al jugador
// local -el movimiento local es 100% inmediato, ver WorldPlayer.update().
export interface PresencePositionResultDto {
  x: number;
  y: number;
  direction: string;
}

export async function sendPresencePosition(
  x: number,
  y: number,
  direction: "up" | "down" | "left" | "right"
): Promise<PresencePositionResultDto> {
  return request("/api/v1/presence/position", {
    method: "POST",
    body: JSON.stringify({ x, y, direction }),
  });
}

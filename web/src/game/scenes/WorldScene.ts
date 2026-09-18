import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { isTypingInFormField } from "../input/keyboardGuard";
import { getCharacter, getPresence, sendPresencePosition } from "../net/ApiClient";
import { subscribeWorld, type PlayerMovedEvent } from "../net/Realtime";
import { getCachedPlayerState } from "../state/playerState";
import { InteractionUI } from "../world/InteractionUI";
import { Npc } from "../world/Npc";
import { RemotePlayerEntity, type RemotePlayerData, type WorldDirection } from "../world/RemotePlayerEntity";
import { WorldMap, type StructureInfo } from "../world/WorldMap";
import { WorldPlayer } from "../world/WorldPlayer";
import { TILED_MAP_KEY } from "../world/worldAssets";

// Fase 19.5: alineada con PRESENCE_POLL_INTERVAL_MS de PlayersOnline.astro
// -misma frecuencia de reconciliación que el resto de Presence, sin
// inventar un ritmo distinto para Mundo.
const PRESENCE_RECONCILE_INTERVAL_MS = 11_000;

// Fase 19.6: ~300ms pedido explícitamente (≈3.3 req/s, ≈200/min en
// movimiento continuo -bien por debajo del limiter presence.position,
// 240/min-). Nunca por frame: Phaser corre update() ~60 veces por
// segundo, un POST por frame saturaría el limiter y el servidor sin
// aportar nada (la interpolación remota ya suaviza visualmente).
const POSITION_SYNC_INTERVAL_MS = 300;

interface WorldSceneData {
  manifest: Manifest;
}

const INTERACTION_RADIUS = 28;

// Punto de spawn del jugador y del NPC. El mapa de Tiled no trae objetos
// de tipo "spawn"/"npc" -solo objetos de colisión-, así que se eligieron
// a mano dos coordenadas abiertas (verificadas jugando: no caen dentro de
// ningún objeto de colisión).
const PLAYER_SPAWN = { x: 470, y: 560 };
const NPC_SPAWN = { x: 420, y: 420, dialogue: ["Bienvenido al dojo.", "Aquí comienzan los desafíos."] };

// Fase 5 (integración Tiled): el Mundo usa ahora el mapa real diseñado en
// Tiled (mapa_base.tmx/.json) en vez del prototipo hardcodeado de la
// iteración anterior. El `Player`/`CharacterRenderer` de Fase 3 sigue
// existiendo tal cual para Arena/combate/personalización — esta escena
// usa `WorldPlayer`, que ahora reutiliza el mismo asset
// `characters/player/base` (no el pack Anokolisa).
export class WorldScene extends Phaser.Scene {
  private manifest!: Manifest;
  private map!: WorldMap;
  private player!: WorldPlayer;
  private npcs: Npc[] = [];
  private ui!: InteractionUI;

  // Fase 19.4: Map por character_id, ver upsertRemotePlayer/removeRemotePlayer
  // más abajo. Fase 19.5 es quien finalmente lo llena/vacía de verdad.
  private readonly remotePlayers = new Map<number, RemotePlayerEntity>();

  // Fase 19.5
  private localCharacterId: number | null = null;
  private worldUnsubscribe: (() => void) | null = null;
  private reconcileTimer: ReturnType<typeof setInterval> | null = null;
  private destroyed = false;

  // Fase 19.6: sincronización de la posición LOCAL -ver syncLocalPosition().
  // Nunca un setInterval propio: se apoya en el mismo loop de update() que
  // ya corre Phaser, así que no hay un timer extra que limpiar (ver
  // objetivo #27) más allá de lo que ya cubre teardownRemotePlayers().
  private positionSyncAccumulatorMs = 0;
  private wasMoving = false;
  private lastSyncedPosition: { x: number; y: number; direction: WorldDirection } | null = null;
  // Objetivo #28: nunca dos POST /presence/position en vuelo a la vez.
  private positionSendInFlight = false;
  private pendingPositionSend: { x: number; y: number; direction: WorldDirection } | null = null;

  private cursors!: Phaser.Types.Input.Keyboard.CursorKeys;
  private wasd!: { W: Phaser.Input.Keyboard.Key; A: Phaser.Input.Keyboard.Key; S: Phaser.Input.Keyboard.Key; D: Phaser.Input.Keyboard.Key };
  private interactKey!: Phaser.Input.Keyboard.Key;

  private activeStructure: StructureInfo | null = null;
  private activeNpc: Npc | null = null;

  constructor() {
    super("WorldScene");
  }

  init(data: WorldSceneData) {
    this.manifest = data.manifest;
  }

  create() {
    this.cameras.main.setBackgroundColor("#2b2d3a");

    this.map = new WorldMap(this, TILED_MAP_KEY);
    this.cameras.main.setBounds(0, 0, this.map.pixelWidth, this.map.pixelHeight);

    this.npcs = [
      new Npc(this, NPC_SPAWN.x, NPC_SPAWN.y, "peasant", "Maestro", NPC_SPAWN.dialogue, this.manifest),
    ];

    this.player = new WorldPlayer(this, PLAYER_SPAWN.x, PLAYER_SPAWN.y, "world-player", this.manifest.worldPlayer, (box) =>
      this.map.canMoveTo(box)
    );

    this.cameras.main.startFollow(this.player.sprite, true, 0.12, 0.12);

    this.ui = new InteractionUI(this);

    const keyboard = this.input.keyboard!;
    this.cursors = keyboard.createCursorKeys();
    this.wasd = keyboard.addKeys("W,A,S,D") as typeof this.wasd;
    this.interactKey = keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.E);

    this.initRemotePlayers();
  }

  update(_time: number, delta: number) {
    const deltaSeconds = delta / 1000;
    const dialogueOpen = this.ui.isDialogueOpen;
    // Corrección de UX: mientras el foco está en el input del chat (u otro
    // campo de texto de la página), WASD/E no deben moverlo/interactuar —
    // ver keyboardGuard.ts. `disableGlobalCapture()` es la parte
    // importante: por default Phaser captura (preventDefault) las teclas
    // registradas con addKeys() a nivel del KeyboardManager compartido de
    // todo el juego -eso pasa ANTES y APARTE de cualquier `enabled` de
    // escena, así que sin esto la letra ni siquiera llega a escribirse en
    // el input, sin importar si el movimiento está guardado o no más
    // abajo-. Es exactamente el caso de uso documentado por Phaser para
    // esta API ("swap to a DOM element").
    const typing = isTypingInFormField();
    if (typing) {
      this.input.keyboard!.disableGlobalCapture();
    } else {
      this.input.keyboard!.enableGlobalCapture();
    }

    if (!dialogueOpen && !typing) {
      this.player.update(
        {
          up: this.cursors.up.isDown || this.wasd.W.isDown,
          down: this.cursors.down.isDown || this.wasd.S.isDown,
          left: this.cursors.left.isDown || this.wasd.A.isDown,
          right: this.cursors.right.isDown || this.wasd.D.isDown,
        },
        deltaSeconds
      );

      // Fase 19.6: capa ADICIONAL sobre el movimiento ya aplicado arriba
      // -nunca antes-. El jugador local ya se movió de verdad; esto solo
      // decide si corresponde avisarle al backend, nunca condiciona el
      // movimiento en sí (ver objetivo #2/#13).
      this.syncLocalPosition(delta);
    }

    // Interpolación de jugadores remotos: corre siempre, incluso con un
    // diálogo abierto -que otro jugador se mueva no depende de qué esté
    // haciendo el jugador local en este momento.
    for (const remote of this.remotePlayers.values()) {
      remote.update(delta);
    }

    this.updateInteractions();

    if (!typing && Phaser.Input.Keyboard.JustDown(this.interactKey)) {
      this.handleInteractKey();
    }
  }

  private updateInteractions(): void {
    if (this.ui.isDialogueOpen) return;

    const nearNpc = this.npcs.find((npc) => npc.distanceTo(this.player.x, this.player.y) <= INTERACTION_RADIUS);
    const nearStructure = this.map.structures.find(
      (structure) => Phaser.Math.Distance.Between(structure.x, structure.y, this.player.x, this.player.y) <= structure.radius
    );

    this.activeNpc = nearNpc ?? null;
    this.activeStructure = nearNpc ? null : nearStructure ?? null;

    if (this.activeNpc) {
      this.ui.showPrompt(`E — Hablar con ${this.activeNpc.displayName}`);
    } else if (this.activeStructure) {
      this.ui.showPrompt(`E — Entrar a ${this.activeStructure.label}`);
    } else {
      this.ui.hidePrompt();
    }
  }

  private handleInteractKey(): void {
    if (this.ui.isDialogueOpen) {
      this.ui.closeDialogue();
      return;
    }

    if (this.activeNpc) {
      this.ui.showDialogue(this.activeNpc.displayName, this.activeNpc.dialogueLines);
      return;
    }

    if (this.activeStructure) {
      // Fase 15: la navegación depende únicamente de `targetPage`, ya
      // resuelto y validado en WorldMap (metadata formal de Tiled, nunca
      // el nombre del objeto). Sin `targetPage` reconocido, mismo
      // fallback de antes -nunca un crash ni una navegación inventada-.
      // Navegación completa (no hay router client-side en este proyecto),
      // mismo patrón que el resto de la app (ej. logout en Hud.astro).
      const targetPage = this.activeStructure.targetPage;
      if (targetPage) {
        window.location.href = targetPage;
        return;
      }
      this.ui.showDialogue(this.activeStructure.label, ["Próximamente."]);
    }
  }

  // ============================================================
  // Fase 19.5: snapshot inicial + reconciliación HTTP + Realtime.
  // ============================================================

  // Sync a propósito -create() debe seguir siendo síncrono, contrato de
  // Phaser.Scene-. Dispara el bootstrap async sin esperarlo (fire-and-
  // forget, mismo criterio que GlobalChat.astro/PlayersOnline.astro
  // arrancando su propio ciclo de vida desde un <script> de cliente) y
  // engancha la limpieza a los eventos de ciclo de vida reales de Phaser.
  private initRemotePlayers(): void {
    void this.bootstrapRemotePlayers();

    this.events.once(Phaser.Scenes.Events.SHUTDOWN, this.teardownRemotePlayers, this);
    this.events.once(Phaser.Scenes.Events.DESTROY, this.teardownRemotePlayers, this);
  }

  private async bootstrapRemotePlayers(): Promise<void> {
    // Objetivo #8: identificar al jugador local reusando la fuente que ya
    // existe (game/state/playerState.ts, que Hud.astro ya popula al
    // cargar la página) en vez de inventar una nueva. Si todavía no se
    // resolvió (carrera con el propio fetch de Hud), getCharacter() es la
    // misma función que ese caché usa por debajo -nunca se inventa un
    // segundo mecanismo de identidad, solo se evita esperar una carrera-.
    const cachedCharacter = getCachedPlayerState();
    if (cachedCharacter) {
      this.localCharacterId = cachedCharacter.id;
    } else {
      try {
        const character = await getCharacter();
        this.localCharacterId = character.id;
      } catch (err) {
        console.warn(
          "No se pudo identificar al personaje local -los jugadores remotos podrían no filtrarse correctamente.",
          err
        );
      }
    }

    // Snapshot inicial (objetivo #1) — reusa la misma reconciliación que
    // corre después periódicamente, ver más abajo.
    await this.reconcilePresence();

    // Objetivo #12: el jugador local puede entrar a Mundo y no moverse
    // nunca -igual debe terminar con una posición válida en Presence, no
    // solo cuando camina-. Fire-and-forget: no bloquea la suscripción a
    // Realtime ni el resto del bootstrap.
    void this.sendInitialPosition();

    // Objetivo #4/#5: un único listener para todo el ciclo de vida de la
    // escena, nunca una segunda conexión Echo (subscribeWorld reusa el
    // singleton de Realtime.ts).
    this.worldUnsubscribe = subscribeWorld((event) => this.handlePlayerMoved(event));

    // Objetivo #11: reconciliación periódica -nunca se confía en Reverb
    // para saber quién sigue online (objetivo #10), solo HTTP decide
    // quién se agrega o se quita de remotePlayers.
    this.reconcileTimer = setInterval(() => {
      void this.reconcilePresence();
    }, PRESENCE_RECONCILE_INTERVAL_MS);
  }

  // Fuente de verdad de "quién está en Mundo" (objetivo #6). Crea/
  // actualiza cada personaje remoto con posición válida y quita de
  // remotePlayers a quien el snapshot ya no incluya -nunca al revés-.
  private async reconcilePresence(): Promise<void> {
    let snapshot: Awaited<ReturnType<typeof getPresence>>;
    try {
      snapshot = await getPresence("play");
    } catch (err) {
      console.warn("No se pudo reconciliar Presence de Mundo -se reintenta en el próximo ciclo.", err);
      return;
    }

    const presentIds = new Set<number>();

    for (const entry of snapshot) {
      if (entry.character_id === this.localCharacterId) continue; // objetivo #3

      presentIds.add(entry.character_id);

      // Objetivo #12: sin posición/dirección válidas todavía, no se crea
      // -puede ser un personaje con presencia en Mundo que nunca mandó
      // POST /presence/position (F19.6 todavía no lo hace).
      if (entry.position_x === null || entry.position_y === null || entry.direction === null) {
        continue;
      }

      this.upsertRemotePlayer({
        characterId: entry.character_id,
        name: entry.name,
        level: entry.level,
        x: entry.position_x,
        y: entry.position_y,
        direction: entry.direction as WorldDirection,
      });
    }

    // Objetivo #6/#10: se elimina SOLO por lo que confirma el snapshot
    // HTTP, nunca por falta de PlayerMoved -un jugador quieto sigue
    // presente aunque no emita nada-.
    for (const characterId of this.remotePlayers.keys()) {
      if (!presentIds.has(characterId)) {
        this.removeRemotePlayer(characterId);
      }
    }
  }

  // Objetivo #9: distribución en tiempo real sobre lo que la reconciliación
  // HTTP ya estableció como fuente de verdad -nunca decide por sí sola
  // quién está online, solo actualiza posición/dirección de quien ya
  // corresponde, o lo crea si el payload trae todo lo necesario.
  private handlePlayerMoved(event: PlayerMovedEvent): void {
    if (event.character_id === this.localCharacterId) return; // ignora el eco del propio jugador

    this.upsertRemotePlayer({
      characterId: event.character_id,
      name: event.name,
      level: event.level,
      x: event.x,
      y: event.y,
      direction: event.direction,
    });
  }

  // Objetivo #15: se engancha a SHUTDOWN/DESTROY de Phaser (ver
  // initRemotePlayers) -nunca deja un timer o una suscripción de Echo
  // colgada si la escena termina. Idempotente a propósito: si Phaser
  // llegara a disparar ambos eventos, correr esto dos veces es inofensivo.
  private teardownRemotePlayers(): void {
    this.destroyed = true;

    if (this.reconcileTimer !== null) {
      clearInterval(this.reconcileTimer);
      this.reconcileTimer = null;
    }

    if (this.worldUnsubscribe) {
      this.worldUnsubscribe();
      this.worldUnsubscribe = null;
    }

    for (const entity of this.remotePlayers.values()) {
      entity.destroy();
    }
    this.remotePlayers.clear();
  }

  // ============================================================
  // Fase 19.6: sincronización de la posición del jugador LOCAL.
  // ============================================================

  // Objetivo #12: intento acotado (2 llamadas, no un retry general) para
  // que el jugador termine con una posición válida en Presence aunque
  // nunca camine. El primer intento puede fallar por una carrera real con
  // el heartbeat de PlayersOnline.astro (que recién establece
  // current_map="play" en player_presence; POST /position devuelve 409
  // hasta que eso exista) -un único reintento corto alcanza para cerrar
  // esa ventana sin inventar un mecanismo de reintento complejo.
  private async sendInitialPosition(): Promise<void> {
    const attempt = async (): Promise<boolean> => {
      if (this.destroyed) return true; // no seguir intentando si la escena ya terminó
      try {
        const direction = this.player.direction as WorldDirection;
        await sendPresencePosition(this.player.x, this.player.y, direction);
        this.lastSyncedPosition = { x: this.player.x, y: this.player.y, direction };
        return true;
      } catch {
        return false;
      }
    };

    if (await attempt()) return;

    await new Promise((resolve) => setTimeout(resolve, 800));
    if (!(await attempt())) {
      console.warn(
        "No se pudo sincronizar la posición inicial en Mundo -se sincronizará en cuanto el jugador se mueva."
      );
    }
  }

  // Objetivo #2/#6/#7/#8/#9: capa de sincronización sobre el movimiento ya
  // aplicado por WorldPlayer -nunca lo condiciona ni espera su respuesta.
  // Llamado una vez por frame (con el jugador local activo, ver update()),
  // decide si corresponde avisar al backend según 3 transiciones:
  //   - empieza a moverse / cambia de dirección en movimiento → inmediato
  //   - se detiene → actualización final inmediata
  //   - sigue moviéndose en la misma dirección → cada ~300ms
  private syncLocalPosition(deltaMs: number): void {
    const moving = this.player.moving;
    const direction = this.player.direction as WorldDirection;
    const x = this.player.x;
    const y = this.player.y;

    const justStarted = moving && !this.wasMoving;
    const justStopped = !moving && this.wasMoving;
    this.wasMoving = moving;

    if (!moving) {
      if (justStopped) {
        this.positionSyncAccumulatorMs = 0;
        this.trySendPosition(x, y, direction); // objetivo #8
      }
      return; // quieto: sin requests continuos (objetivo #7)
    }

    const directionChanged = this.lastSyncedPosition !== null && this.lastSyncedPosition.direction !== direction;

    if (justStarted || directionChanged) {
      this.positionSyncAccumulatorMs = 0;
      this.trySendPosition(x, y, direction); // objetivo #7/#9
      return;
    }

    this.positionSyncAccumulatorMs += deltaMs;
    if (this.positionSyncAccumulatorMs >= POSITION_SYNC_INTERVAL_MS) {
      this.positionSyncAccumulatorMs = 0;
      this.trySendPosition(x, y, direction);
    }
  }

  private trySendPosition(x: number, y: number, direction: WorldDirection): void {
    // Objetivo #8: no duplicar si ya se acaba de mandar exactamente esto.
    if (
      this.lastSyncedPosition &&
      this.lastSyncedPosition.x === x &&
      this.lastSyncedPosition.y === y &&
      this.lastSyncedPosition.direction === direction
    ) {
      return;
    }
    this.lastSyncedPosition = { x, y, direction };

    if (this.positionSendInFlight) {
      // Objetivo #28: nunca dos POST simultáneos -se guarda únicamente el
      // último estado deseado, nunca se acumula una cola.
      this.pendingPositionSend = { x, y, direction };
      return;
    }

    void this.sendPositionNow(x, y, direction);
  }

  private async sendPositionNow(x: number, y: number, direction: WorldDirection): Promise<void> {
    this.positionSendInFlight = true;
    try {
      // Objetivo #13: la respuesta NUNCA controla al jugador local -acá
      // ni siquiera se usa su valor, solo importa si la promesa resolvió
      // o rechazó.
      await sendPresencePosition(x, y, direction);
    } catch (err) {
      // Objetivo #14/#15: nunca congela ni corrige al jugador local -el
      // movimiento local ya ocurrió, esto es best-effort. Cubre tanto
      // errores de red como un 422 de plausibilidad del backend; la
      // próxima posición real (o el próximo tick de 300ms) reintenta con
      // datos frescos, nunca con esta misma posición rechazada.
      console.warn("No se pudo sincronizar la posición del jugador local.", err);
    } finally {
      this.positionSendInFlight = false;

      if (!this.destroyed && this.pendingPositionSend) {
        const next = this.pendingPositionSend;
        this.pendingPositionSend = null;
        void this.sendPositionNow(next.x, next.y, next.direction);
      }
    }
  }

  // Crea o actualiza una entidad remota por character_id -mismo camino
  // para el snapshot inicial, la reconciliación periódica y los eventos
  // PlayerMoved en tiempo real, ver los 3 métodos de arriba.
  private upsertRemotePlayer(data: RemotePlayerData): void {
    const existing = this.remotePlayers.get(data.characterId);
    if (existing) {
      existing.updateFromRemote(data);
      existing.updateBasicInfo(data);
      return;
    }

    this.remotePlayers.set(data.characterId, new RemotePlayerEntity(this, this.manifest, data));
  }

  private removeRemotePlayer(characterId: number): void {
    const existing = this.remotePlayers.get(characterId);
    if (!existing) return;

    existing.destroy();
    this.remotePlayers.delete(characterId);
  }
}

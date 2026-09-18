import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { isTypingInFormField } from "../input/keyboardGuard";
import { getCharacter, getPresence } from "../net/ApiClient";
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

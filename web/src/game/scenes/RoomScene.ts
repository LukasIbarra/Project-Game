import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import type { RoomObjectDto } from "../net/ApiClient";
import type { AABB } from "../world/WorldPlayer";
import { WorldMap } from "../world/WorldMap";
import { WorldPlayer } from "../world/WorldPlayer";
import { RoomFurniture, type FurnitureMetadata } from "../room/RoomFurniture";
import { FURNITURE_TEXTURE_KEY, furnitureFrameKey, ROOM_MAP_KEY, ROOM_PLAYER_NAMESPACE } from "../room/roomAssets";

interface RoomSceneData {
  manifest: Manifest;
}

const TILE_SIZE = 16;
const PLAYER_SPAWN = { x: 256, y: 300 };

// Room 9.1: la habitación (32x24 @16px = 512x384) es mucho más chica que
// el canvas compartido de 900x700 que usa /play (Mundo, con cámara que
// sí necesita scrollear sobre un mapa grande) -por eso se veía como un
// mapa pequeño pegado en la esquina superior izquierda, con el resto del
// canvas vacío-. La resolución LÓGICA de la habitación sigue siendo
// 512x384 (16x16 real, nada de tiles de otro tamaño ni geometría
// deformada) -lo que cambia es que el Scale Manager de Phaser redimensiona
// el canvas a ese tamaño exacto y lo escala con un zoom ENTERO (2x) vía
// CSS -mismo mecanismo que ya usa `pixelArt:true`/`image-rendering:
// pixelated`, sin introducir blur ni sub-pixeles-. Un zoom entero evita el
// shimmer/jitter que produciría un factor fraccionario en movimiento.
// Phaser's ScaleManager.transformX/Y (que el InputManager ya usa para
// convertir eventos de mouse a coordenadas de juego) tiene en cuenta este
// zoom automáticamente, así que ninguna lógica de puntero/grid/colisión
// de esta escena necesita cambiar.
const ROOM_ZOOM = 2;

// Room, sección 6/8/9: reutiliza WorldMap (colisión estática) y
// WorldPlayer (movimiento top-down) generalizados -no un sistema de mapa
// ni de movimiento paralelo, ver CLAUDE.md-. Lo único nuevo acá es el
// furniture dinámico (RoomFurniture) y el modo edición.
//
// El modo edición NO tiene su propia UI de Phaser: emite eventos
// (`this.events.emit`) que la página Astro escucha para dibujar sus
// propios paneles pixel-art (mismo lenguaje visual que el resto de la
// app) y hacer las llamadas reales a la API -Phaser solo dibuja el
// mundo/ghost y decide si una posición se ve válida, nunca decide si
// "de verdad" lo es: eso lo vuelve a validar el backend siempre-.
export class RoomScene extends Phaser.Scene {
  private manifest!: Manifest;
  private map!: WorldMap;
  private player!: WorldPlayer;
  private furniture: RoomFurniture[] = [];

  private cursors!: Phaser.Types.Input.Keyboard.CursorKeys;
  private wasd!: { W: Phaser.Input.Keyboard.Key; A: Phaser.Input.Keyboard.Key; S: Phaser.Input.Keyboard.Key; D: Phaser.Input.Keyboard.Key };

  private editMode = false;
  private cursorHighlight!: Phaser.GameObjects.Rectangle;

  // Ghost activo -placement nuevo O move de uno existente-, nunca ambos.
  private ghost: {
    kind: "place" | "move";
    itemKey: string;
    metadata: FurnitureMetadata;
    sprite: Phaser.GameObjects.Sprite;
    movingRoomObjectId?: number;
  } | null = null;

  constructor() {
    super("RoomScene");
  }

  init(data: RoomSceneData) {
    this.manifest = data.manifest;
  }

  create() {
    this.cameras.main.setBackgroundColor("#2b2d3a");

    this.map = new WorldMap(this, ROOM_MAP_KEY);

    // El juego arranca con el tamaño de canvas de /play (900x700, ver
    // main.ts) porque Boot/Preload todavía no saben qué escena final se
    // va a mostrar. Acá, ya con el mapa cargado, se ajusta el Scale
    // Manager al tamaño real de ESTA habitación -CameraManager escucha el
    // evento RESIZE y reajusta la cámara principal sola (ver
    // Phaser.Cameras.Scene2D.CameraManager#onResize), así que no hace
    // falta tocar `this.cameras.main` a mano-.
    this.scale.resize(this.map.pixelWidth, this.map.pixelHeight);
    this.scale.setZoom(ROOM_ZOOM);

    this.cameras.main.setBounds(0, 0, this.map.pixelWidth, this.map.pixelHeight);

    this.player = new WorldPlayer(
      this,
      PLAYER_SPAWN.x,
      PLAYER_SPAWN.y,
      ROOM_PLAYER_NAMESPACE,
      this.manifest.housePlayer,
      (box) => this.canMoveTo(box),
      { width: 8, height: 5, offsetY: 8 }
    );

    this.cameras.main.startFollow(this.player.sprite, true, 0.12, 0.12);

    this.cursorHighlight = this.add.rectangle(0, 0, TILE_SIZE, TILE_SIZE, 0x00ff00, 0.35);
    this.cursorHighlight.setOrigin(0, 0);
    this.cursorHighlight.setVisible(false);
    this.cursorHighlight.setDepth(999999);

    const keyboard = this.input.keyboard!;
    this.cursors = keyboard.createCursorKeys();
    this.wasd = keyboard.addKeys("W,A,S,D") as typeof this.wasd;
    keyboard.on("keydown-ESC", () => this.cancelGhost());

    this.input.on("pointermove", (pointer: Phaser.Input.Pointer) => this.onPointerMove(pointer));
    this.input.on("pointerdown", (pointer: Phaser.Input.Pointer) => this.onPointerDown(pointer));
  }

  update(_time: number, delta: number) {
    if (!this.editMode) {
      this.player.update(
        {
          up: this.cursors.up.isDown || this.wasd.W.isDown,
          down: this.cursors.down.isDown || this.wasd.S.isDown,
          left: this.cursors.left.isDown || this.wasd.A.isDown,
          right: this.cursors.right.isDown || this.wasd.D.isDown,
        },
        delta / 1000
      );
      this.player.sprite.setDepth(this.player.y);
    }
  }

  // ---- API pública para la página Astro (edición) ----

  setEditMode(enabled: boolean): void {
    this.editMode = enabled;
    this.cursorHighlight.setVisible(false);
    this.cancelGhost();
    this.furniture.forEach((f) => this.setFurnitureInteractive(f, enabled));
  }

  startPlacing(itemKey: string, metadata: FurnitureMetadata): void {
    this.cancelGhost();
    const sprite = this.add.sprite(0, 0, FURNITURE_TEXTURE_KEY, furnitureFrameKey(itemKey));
    sprite.setOrigin(0, 0);
    sprite.setAlpha(0.6);
    this.ghost = { kind: "place", itemKey, metadata, sprite };
  }

  startMoving(roomObjectId: number): void {
    const target = this.furniture.find((f) => f.roomObjectId === roomObjectId);
    if (!target) return;

    this.cancelGhost();
    const sprite = this.add.sprite(0, 0, FURNITURE_TEXTURE_KEY, furnitureFrameKey(target.itemKey));
    sprite.setOrigin(0, 0);
    sprite.setAlpha(0.6);
    target.sprite.setVisible(false);

    this.ghost = {
      kind: "move",
      itemKey: target.itemKey,
      metadata: {
        tile_width: target.tileWidth,
        tile_height: target.tileHeight,
        collision: { x: 0, y: 0, width: 0, height: 0 }, // no se usa acá, canMoveTo real lo valida el backend
        placeable: true,
        rotatable: false,
      },
      sprite,
      movingRoomObjectId: roomObjectId,
    };
  }

  cancelGhost(): void {
    if (this.ghost?.kind === "move" && this.ghost.movingRoomObjectId !== undefined) {
      const original = this.furniture.find((f) => f.roomObjectId === this.ghost!.movingRoomObjectId);
      original?.sprite.setVisible(true);
    }
    this.ghost?.sprite.destroy();
    this.ghost = null;
  }

  // Reemplaza el estado completo de furniture renderizado -se llama
  // después de cada acción exitosa (place/move/remove), la respuesta del
  // backend siempre es la fuente de verdad, nunca se asume optimista.
  refreshRoomObjects(objects: RoomObjectDto[]): void {
    this.furniture.forEach((f) => f.destroy());
    this.furniture = [];

    for (const obj of objects) {
      if (!obj.item.metadata_json) continue;
      const metadata = obj.item.metadata_json as unknown as FurnitureMetadata;
      const piece = new RoomFurniture(this, obj.id, obj.item.key, metadata, obj.x, obj.y);
      this.furniture.push(piece);
    }

    // El listener se registra siempre (no solo si editMode ya está activo
    // en este momento) porque refreshRoomObjects corre primero al cargar
    // la escena, ANTES de que el usuario entre a modo edición -si solo se
    // registraba cuando editMode ya era true, el furniture cargado al
    // inicio/recargar la página quedaba sin listener para siempre, aunque
    // después se activara "Editar habitación" (bug real, encontrado por
    // Playwright). El propio handler chequea editMode/ghost en el momento
    // del click, así que registrarlo siempre es seguro.
    this.furniture.forEach((f) => {
      this.setFurnitureInteractive(f, this.editMode);
      f.sprite.on("pointerdown", () => {
        if (this.editMode && !this.ghost) {
          this.events.emit("furniture-selected", { roomObjectId: f.roomObjectId, itemKey: f.itemKey });
        }
      });
    });
  }

  // `sprite.setInteractive(false)` NO desactiva la interactividad -Phaser
  // interpreta ese `false` como un hitArea inválido y después revienta con
  // "input.hitAreaCallback is not a function" en cualquier evento de
  // puntero posterior, apagando en silencio los clics-. Encontrado por
  // Playwright: el furniture cargado al abrir/recargar la página quedaba
  // imposible de seleccionar incluso después de corregir el registro del
  // listener. La forma correcta es `disableInteractive()`.
  private setFurnitureInteractive(f: RoomFurniture, enabled: boolean): void {
    if (enabled) {
      f.sprite.setInteractive({ useHandCursor: true });
    } else {
      f.sprite.disableInteractive();
    }
  }

  // ---- internals ----

  private onPointerMove(pointer: Phaser.Input.Pointer): void {
    if (!this.editMode) return;

    const world = this.cameras.main.getWorldPoint(pointer.x, pointer.y);
    const tileX = Math.floor(world.x / TILE_SIZE);
    const tileY = Math.floor(world.y / TILE_SIZE);

    if (this.ghost) {
      this.ghost.sprite.setPosition(tileX * TILE_SIZE, tileY * TILE_SIZE);
      const valid = this.isValidGhostPosition(tileX, tileY);
      this.ghost.sprite.setTint(valid ? 0x66ff66 : 0xff6666);
      this.cursorHighlight.setVisible(false);
    } else {
      this.cursorHighlight.setPosition(tileX * TILE_SIZE, tileY * TILE_SIZE);
      this.cursorHighlight.setVisible(true);
    }
  }

  private onPointerDown(_pointer: Phaser.Input.Pointer): void {
    if (!this.editMode || !this.ghost) return;

    const tileX = Math.round(this.ghost.sprite.x / TILE_SIZE);
    const tileY = Math.round(this.ghost.sprite.y / TILE_SIZE);

    if (!this.isValidGhostPosition(tileX, tileY)) return;

    if (this.ghost.kind === "place") {
      this.events.emit("place-requested", { itemKey: this.ghost.itemKey, tileX, tileY });
    } else {
      this.events.emit("move-requested", { roomObjectId: this.ghost.movingRoomObjectId, tileX, tileY });
    }
  }

  // Validación puramente visual (sección 11: "la validación visual del
  // cliente es solo UX"). El backend vuelve a validar todo desde cero.
  private isValidGhostPosition(tileX: number, tileY: number): boolean {
    if (!this.ghost) return false;
    const { metadata } = this.ghost;

    const footprint: AABB = {
      x: tileX * TILE_SIZE,
      y: tileY * TILE_SIZE,
      width: metadata.tile_width * TILE_SIZE,
      height: metadata.tile_height * TILE_SIZE,
    };

    if (footprint.x < 0 || footprint.y < 0 || footprint.x + footprint.width > this.map.pixelWidth || footprint.y + footprint.height > this.map.pixelHeight) {
      return false;
    }

    if (!this.map.canMoveTo(footprint)) return false;

    return !this.furniture.some((f) => {
      if (this.ghost?.kind === "move" && f.roomObjectId === this.ghost.movingRoomObjectId) return false;
      return aabbOverlap(footprint, f.footprint());
    });
  }

  private canMoveTo(box: AABB): boolean {
    if (!this.map.canMoveTo(box)) return false;
    return !this.furniture.some((f) => aabbOverlap(box, f.footprint()));
  }
}

function aabbOverlap(a: AABB, b: AABB): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

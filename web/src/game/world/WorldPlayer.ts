import Phaser from "phaser";
import type { DirectionalAnimationAsset } from "../assets/manifest";
import { playerTextureKey } from "./worldAssets";

// Fase 5 (corrección jugador 16x16): 8 direcciones posibles a nivel
// visual. El movimiento actual solo usa las 4 cardinales (ver
// `WorldPlayer.update`), pero el sistema de animación soporta las 8 sin
// cambios -las diagonales quedan listas para cuando se habiliten-.
export type Direction =
  | "down"
  | "down-right"
  | "right"
  | "up-right"
  | "up"
  | "up-left"
  | "left"
  | "down-left";

export type AnimationName = "idle" | "walk" | "run";

export interface AABB {
  x: number;
  y: number;
  width: number;
  height: number;
}

// El asset solo dibuja 5 direcciones (down, down-right, right, up-right,
// up, en ese orden de fila) — verificado con detección de componentes
// conectados + inspección visual fila por fila (no a ojo). Las 3
// direcciones "de la izquierda" no tienen fila propia: se obtienen
// espejando (flipX) la fila de su contraparte derecha. Esto no es una
// solución de reemplazo: el propio Rotate-Sheet del pack trae las 8
// direcciones y sus columnas izquierdas son, píxel a píxel (0% de
// diferencia medida), el espejo horizontal de las derechas — el asset ya
// está construido así.
// Fase 19.4: exportado (antes privado de este archivo) -RemotePlayerEntity
// necesita crear las mismas animaciones y aplicar el mismo criterio de
// dirección/flip que el jugador local, sin duplicar esta tabla ni el
// espejado. Nada de esto cambió de comportamiento, solo de visibilidad.
export type BaseDirection = "down" | "down-right" | "right" | "up-right" | "up";

const BASE_DIRECTION_ROW: Record<BaseDirection, number> = {
  down: 0,
  "down-right": 1,
  right: 2,
  "up-right": 3,
  up: 4,
};

const MIRRORED_DIRECTION: Record<Exclude<Direction, BaseDirection>, BaseDirection> = {
  "down-left": "down-right",
  left: "right",
  "up-left": "up-right",
};

export function resolveDirection(direction: Direction): { base: BaseDirection; flip: boolean } {
  if (direction in BASE_DIRECTION_ROW) {
    return { base: direction as BaseDirection, flip: false };
  }
  return { base: MIRRORED_DIRECTION[direction as Exclude<Direction, BaseDirection>], flip: true };
}

export function animationKey(namespace: string, animation: AnimationName, base: BaseDirection): string {
  return `${namespace}-${animation}-${base}`;
}

export function ensureAnimations(
  scene: Phaser.Scene,
  namespace: string,
  animation: AnimationName,
  asset: DirectionalAnimationAsset
): void {
  const textureKey = playerTextureKey(namespace, animation);

  for (const base of Object.keys(BASE_DIRECTION_ROW) as BaseDirection[]) {
    const key = animationKey(namespace, animation, base);
    if (scene.anims.exists(key)) continue;

    const row = BASE_DIRECTION_ROW[base];
    const start = row * asset.columns;
    const end = start + asset.columns - 1;

    scene.anims.create({
      key,
      frames: scene.anims.generateFrameNumbers(textureKey, { start, end }),
      frameRate: asset.frameRate,
      repeat: -1,
    });
  }
}

// Fase 19.4: extraído de WorldPlayer.play() (que ahora delega acá, ver
// abajo) para que RemotePlayerEntity pueda aplicar exactamente el mismo
// criterio de animación/flip sin reimplementarlo -única fuente de verdad
// de "cómo se ve un sprite world-player mirando hacia X".
export function applyDirectionalAnimation(
  sprite: Phaser.GameObjects.Sprite,
  namespace: string,
  animation: AnimationName,
  direction: Direction
): void {
  const { base, flip } = resolveDirection(direction);
  sprite.setFlipX(flip);

  const key = animationKey(namespace, animation, base);
  if (sprite.anims.currentAnim?.key !== key) {
    sprite.play(key);
  }
}

const SPEED = 70;

// F(Room): valores por defecto = los que Fase 5 ya afinó para el 16x32
// del Mundo. El 16x16 de la Habitación pasa los suyos propios -sprite
// más chico, hitbox de pies proporcionalmente más chica- sin tocar esta
// clase ni al Mundo.
const DEFAULT_FOOT = { width: 10, height: 6, offsetY: 9 };

export interface FootBoxConfig {
  width: number;
  height: number;
  offsetY: number;
}

export class WorldPlayer {
  readonly sprite: Phaser.GameObjects.Sprite;
  private _direction: Direction = "down";
  private _moving = false;
  private readonly foot: FootBoxConfig;

  constructor(
    scene: Phaser.Scene,
    x: number,
    y: number,
    private readonly namespace: string,
    animations: Record<AnimationName, DirectionalAnimationAsset>,
    private readonly canMoveTo: (box: AABB) => boolean,
    foot: FootBoxConfig = DEFAULT_FOOT
  ) {
    this.foot = foot;

    (["idle", "walk", "run"] as AnimationName[]).forEach((animation) =>
      ensureAnimations(scene, namespace, animation, animations[animation])
    );

    this.sprite = scene.add.sprite(x, y, playerTextureKey(namespace, "idle"), 0);
    this.play("idle", "down");
  }

  get x(): number {
    return this.sprite.x;
  }

  get y(): number {
    return this.sprite.y;
  }

  // Fase 19.6: WorldScene necesita leer esto cada frame para decidir
  // cuándo sincronizar posición al backend -mismo criterio que x/y de
  // arriba, getters de solo lectura sobre el estado real, nunca una copia
  // que se pueda desincronizar.
  get direction(): Direction {
    return this._direction;
  }

  get moving(): boolean {
    return this._moving;
  }

  private play(animation: AnimationName, direction: Direction): void {
    applyDirectionalAnimation(this.sprite, this.namespace, animation, direction);
  }

  private footBox(x: number, y: number): AABB {
    return {
      x: x - this.foot.width / 2,
      y: y + this.foot.offsetY - this.foot.height / 2,
      width: this.foot.width,
      height: this.foot.height,
    };
  }

  update(input: { up: boolean; down: boolean; left: boolean; right: boolean }, deltaSeconds: number): void {
    // Movimiento en 4 direcciones, sin diagonal (decisión de diseño
    // vigente): un solo eje activo por vez, horizontal con prioridad.
    // El sistema de animación de abajo sí soporta las 8 direcciones -acá
    // solo se elige entre las 4 cardinales porque es lo único que el
    // movimiento actual produce.
    let dx = 0;
    let dy = 0;
    if (input.left) dx = -1;
    else if (input.right) dx = 1;
    else if (input.up) dy = -1;
    else if (input.down) dy = 1;

    this._moving = dx !== 0 || dy !== 0;

    if (this._moving) {
      const stepX = dx * SPEED * deltaSeconds;
      const stepY = dy * SPEED * deltaSeconds;

      const afterX = this.footBox(this.sprite.x + stepX, this.sprite.y);
      if (this.canMoveTo(afterX)) {
        this.sprite.x += stepX;
      }

      const afterY = this.footBox(this.sprite.x, this.sprite.y + stepY);
      if (this.canMoveTo(afterY)) {
        this.sprite.y += stepY;
      }

      if (dx < 0) this._direction = "left";
      else if (dx > 0) this._direction = "right";
      else if (dy < 0) this._direction = "up";
      else if (dy > 0) this._direction = "down";
    }

    this.play(this._moving ? "walk" : "idle", this._direction);
  }
}

import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { playerTextureKey } from "./worldAssets";
import { applyDirectionalAnimation, ensureAnimations, type AnimationName } from "./WorldPlayer";

// Fase 19.4: pieza PURAMENTE visual -sin red, sin fetch, sin Echo/Pusher,
// sin saber qué es player_presence o Laravel. Representa a un jugador
// remoto en Mundo reusando exactamente el mismo sprite/sistema de
// animación que ya usa el jugador local (WorldPlayer: mismo namespace
// "world-player", mismo asset genérico) -F19.4 no toca appearance_json/
// CharacterRenderer, esa personalización queda para más adelante, igual
// que el jugador local tampoco la usa en Mundo hoy-.
//
// Quién la alimenta con datos reales (Reverb, canal "world") es
// responsabilidad de F19.5 -esta clase no sabe ni le importa de dónde
// vienen sus datos, solo sabe dibujarlos-.
export type WorldDirection = "up" | "down" | "left" | "right";

export interface RemotePlayerData {
  characterId: number;
  name: string;
  level: number;
  x: number;
  y: number;
  direction: WorldDirection;
}

// Mismo espaciado vertical que ya usa Npc.ts para su nameLabel (-26); el
// nivel va un poco más abajo, más cerca del sprite, como dato secundario.
const NAME_LABEL_OFFSET_Y = 26;
const LEVEL_LABEL_OFFSET_Y = 15;

// Único namespace de sprite "de jugador" que existe hoy en Mundo (ver
// manifest.worldPlayer, cargado por preloadWorldAssets) -no hay todavía
// un asset "genérico remoto" separado del que usa WorldPlayer.
const NAMESPACE = "world-player";

export class RemotePlayerEntity {
  readonly characterId: number;
  readonly sprite: Phaser.GameObjects.Sprite;
  readonly nameLabel: Phaser.GameObjects.Text;
  readonly levelLabel: Phaser.GameObjects.Text;

  private name: string;
  private level: number;
  private direction: WorldDirection;

  // `scene` es un parámetro simple, no un campo -solo hace falta durante
  // la construcción (crear sprite/textos), ningún método de abajo lo
  // necesita después.
  constructor(scene: Phaser.Scene, manifest: Manifest, data: RemotePlayerData) {
    this.characterId = data.characterId;
    this.name = data.name;
    this.level = data.level;
    this.direction = data.direction;

    // Idempotente (ensureAnimations ya se salta lo que exists()) -en la
    // práctica WorldPlayer ya las creó primero (siempre se instancia antes
    // en WorldScene.create()), pero esta clase no debe depender de ese
    // orden para ser correcta por sí sola.
    (["idle", "walk", "run"] as AnimationName[]).forEach((animation) =>
      ensureAnimations(scene, NAMESPACE, animation, manifest.worldPlayer[animation])
    );

    this.sprite = scene.add.sprite(data.x, data.y, playerTextureKey(NAMESPACE, "idle"), 0);
    applyDirectionalAnimation(this.sprite, NAMESPACE, "idle", this.direction);

    this.nameLabel = scene.add
      .text(data.x, data.y - NAME_LABEL_OFFSET_Y, this.name, {
        fontFamily: "monospace",
        fontSize: "10px",
        color: "#ece8e3",
        backgroundColor: "#0b0b0d",
        padding: { x: 3, y: 1 },
      })
      .setOrigin(0.5, 1);

    // Discreto a propósito: sin fondo, fuente más chica, color muted
    // (mismo token que el resto de la UI, ver theme.css --color-muted) —
    // el nombre es el dato principal, el nivel es secundario.
    this.levelLabel = scene.add
      .text(data.x, data.y - LEVEL_LABEL_OFFSET_Y, `Nv. ${this.level}`, {
        fontFamily: "monospace",
        fontSize: "8px",
        color: "#8f8d96",
      })
      .setOrigin(0.5, 1);
  }

  get x(): number {
    return this.sprite.x;
  }

  get y(): number {
    return this.sprite.y;
  }

  getDirection(): WorldDirection {
    return this.direction;
  }

  // F19.4: reposiciona directo, sin interpolación/tween -eso es F19.5/F19.6
  // si hace falta. Sprite y labels se mueven juntos.
  setPosition(x: number, y: number): void {
    this.sprite.setPosition(x, y);
    this.nameLabel.setPosition(x, y - NAME_LABEL_OFFSET_Y);
    this.levelLabel.setPosition(x, y - LEVEL_LABEL_OFFSET_Y);
  }

  setDirection(direction: WorldDirection): void {
    if (this.direction === direction) return;
    this.direction = direction;
    // "walk": un update remoto de dirección representa movimiento real -el
    // backend (PresenceController::position) solo emite PlayerMoved cuando
    // x/y/direction efectivamente cambiaron (ver F19.3), así que para esta
    // entidad nunca hay una razón real para pintar "idle" después de la
    // creación inicial.
    applyDirectionalAnimation(this.sprite, NAMESPACE, "walk", this.direction);
  }

  // Caso real de un PlayerMoved: posición y dirección llegan juntas -evita
  // que quien la use tenga que llamar a los dos setters por separado.
  updateFromRemote(data: Pick<RemotePlayerData, "x" | "y" | "direction">): void {
    this.setPosition(data.x, data.y);
    this.setDirection(data.direction);
  }

  updateBasicInfo(data: Partial<Pick<RemotePlayerData, "name" | "level">>): void {
    if (data.name !== undefined && data.name !== this.name) {
      this.name = data.name;
      this.nameLabel.setText(this.name);
    }
    if (data.level !== undefined && data.level !== this.level) {
      this.level = data.level;
      this.levelLabel.setText(`Nv. ${this.level}`);
    }
  }

  destroy(): void {
    this.sprite.destroy();
    this.nameLabel.destroy();
    this.levelLabel.destroy();
  }
}

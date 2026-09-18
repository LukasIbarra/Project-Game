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

// Fase 19.6: interpolación simple (Phaser.Math.Linear aplicado cada frame
// hacia un único target mutable, ver update()/setPosition() más abajo) -
// nunca Tweens de Phaser, para no tener que cancelar/reusar uno viejo cada
// vez que llega un PlayerMoved a mitad de camino (objetivo #19).
//
// INTERP_DURATION_MS: WorldScene manda posición cada ~300ms (F19.6) -150ms
// converge visiblemente rápido sin sentirse un salto, dejando margen antes
// de que llegue la próxima actualización real.
const INTERP_DURATION_MS = 150;
// Debajo de esto se considera "ya llegó" -evita perseguir un target a una
// fracción de píxel para siempre por el decaimiento asintótico del lerp.
const SNAP_EPSILON_PX = 0.5;
// Distancia normal por actualización a SPEED=70px/s cada ~300ms ronda los
// 20-30px. Un salto de más de 150px (5x eso) no es movimiento continuo
// real -es un spawn nuevo o una corrección- y se corrige directo en vez
// de animarlo (objetivo #20).
const TELEPORT_DISTANCE_PX = 150;
// F19.3 solo emite PlayerMoved cuando algo cambió de verdad -si no llega
// nada en este período, lo más probable es que el jugador remoto esté
// quieto, no que el evento se haya perdido. Ventana chica a propósito
// (objetivo #22: "no usar varios segundos"), con margen sobre los ~300ms
// de envío para no parpadear a idle por un tick perdido puntual.
const IDLE_TIMEOUT_MS = 600;

export class RemotePlayerEntity {
  readonly characterId: number;
  readonly sprite: Phaser.GameObjects.Sprite;
  readonly nameLabel: Phaser.GameObjects.Text;
  readonly levelLabel: Phaser.GameObjects.Text;

  private name: string;
  private level: number;
  private direction: WorldDirection;

  // Objetivo #18/#19: único target mutable -nunca una cola/lista de
  // posiciones pendientes. setPosition() solo cambia esto; el sprite se
  // mueve de verdad en update(), interpolando hacia acá.
  private targetX: number;
  private targetY: number;

  // Objetivo #21/#22: separado de `direction` a propósito -direction es
  // "hacia dónde mira", walking es "¿reproduzco walk o idle ahora?". Se
  // decide por tiempo desde la última actualización real, no por un
  // evento explícito de stop que no existe (F19.3/F19.5 no lo emiten).
  private walking = false;
  private msSinceLastUpdate = 0;

  // `scene` es un parámetro simple, no un campo -solo hace falta durante
  // la construcción (crear sprite/textos), ningún método de abajo lo
  // necesita después.
  constructor(scene: Phaser.Scene, manifest: Manifest, data: RemotePlayerData) {
    this.characterId = data.characterId;
    this.name = data.name;
    this.level = data.level;
    this.direction = data.direction;
    this.targetX = data.x;
    this.targetY = data.y;

    // Idempotente (ensureAnimations ya se salta lo que exists()) -en la
    // práctica WorldPlayer ya las creó primero (siempre se instancia antes
    // en WorldScene.create()), pero esta clase no debe depender de ese
    // orden para ser correcta por sí sola.
    (["idle", "walk", "run"] as AnimationName[]).forEach((animation) =>
      ensureAnimations(scene, NAMESPACE, animation, manifest.worldPlayer[animation])
    );

    this.sprite = scene.add.sprite(data.x, data.y, playerTextureKey(NAMESPACE, "idle"), 0);
    this.renderAnimation();

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

  // Fase 19.6: llamado una vez por frame desde WorldScene (mismo criterio
  // que WorldPlayer.update(): recibe delta en ms, no hace su propio
  // timer). Dos responsabilidades independientes:
  //  1. decidir si ya pasó suficiente tiempo sin una actualización real
  //     como para volver a "idle" (objetivo #22);
  //  2. interpolar el sprite hacia targetX/targetY (objetivos #16-#20).
  update(deltaMs: number): void {
    if (this.walking && this.msSinceLastUpdate >= IDLE_TIMEOUT_MS) {
      this.walking = false;
      this.renderAnimation();
    }
    this.msSinceLastUpdate += deltaMs;

    const distance = Phaser.Math.Distance.Between(this.sprite.x, this.sprite.y, this.targetX, this.targetY);
    if (distance < SNAP_EPSILON_PX) return;

    if (distance > TELEPORT_DISTANCE_PX) {
      this.applyVisualPosition(this.targetX, this.targetY);
      return;
    }

    const t = Phaser.Math.Clamp(deltaMs / INTERP_DURATION_MS, 0, 1);
    this.applyVisualPosition(
      Phaser.Math.Linear(this.sprite.x, this.targetX, t),
      Phaser.Math.Linear(this.sprite.y, this.targetY, t)
    );
  }

  // Objetivo #18/#19: solo mueve el target -nunca el sprite directo, y
  // nunca crea/cancela un tween-. Si llega una posición nueva antes de
  // llegar a la anterior, update() simplemente empieza a interpolar hacia
  // esta desde donde esté parado ese frame; no hay nada que acumular.
  setPosition(x: number, y: number): void {
    this.targetX = x;
    this.targetY = y;
  }

  setDirection(direction: WorldDirection): void {
    this.direction = direction;
    this.renderAnimation();
  }

  // Caso real de un PlayerMoved (o una fila del snapshot con posición
  // válida): posición y dirección llegan juntas, y esto SIEMPRE
  // representa actividad real -F19.3 solo emite PlayerMoved cuando algo
  // cambió de verdad- así que reinicia el conteo de inactividad acá,
  // antes de aplicar los setters de abajo.
  updateFromRemote(data: Pick<RemotePlayerData, "x" | "y" | "direction">): void {
    this.walking = true;
    this.msSinceLastUpdate = 0;

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

  private renderAnimation(): void {
    applyDirectionalAnimation(this.sprite, NAMESPACE, this.walking ? "walk" : "idle", this.direction);
  }

  private applyVisualPosition(x: number, y: number): void {
    this.sprite.setPosition(x, y);
    this.nameLabel.setPosition(x, y - NAME_LABEL_OFFSET_Y);
    this.levelLabel.setPosition(x, y - LEVEL_LABEL_OFFSET_Y);
  }

  destroy(): void {
    this.sprite.destroy();
    this.nameLabel.destroy();
    this.levelLabel.destroy();
  }
}

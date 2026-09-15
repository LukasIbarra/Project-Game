import Phaser from "phaser";
import { publicUrl, type Manifest } from "../assets/manifest";
import { CharacterRenderer, type CharacterAppearance } from "../entities/CharacterRenderer";
import type { CombatEventDto } from "../net/ApiClient";

export interface BattleFighterData {
  name: string;
  level: number;
  maxHp: number;
  appearance: CharacterAppearance;
}

export interface BattleSceneData {
  manifest: Manifest;
  attacker: BattleFighterData;
  defender: BattleFighterData;
  events: CombatEventDto[];
}

const SCALE = 3;
const MS_PER_EVENT = 1400;

// Mejora visual Arena: fondo del dojo. Vive en public/backgrounds/ (no en
// public/assets/), mismo lugar y mismo criterio que los fondos ambiente
// que ya usa Astro directamente (AppShell/GameView, ver fondo_05.png/
// fondo_1.png) — el manifest.json/assetUrl() es específicamente para el
// pipeline de personajes/tiles de Phaser (CLAUDE.md #5), no para un fondo
// de escena estático y único como este. `publicUrl()` (mismo cache-busting
// que assetUrl(), ver manifest.ts) sí aplica igual -es el mecanismo
// general para cualquier archivo público versionado, no solo los del
// pipeline de personajes-.
const BATTLE_BACKGROUND_KEY = "battle-bg-dojo";
const BATTLE_BACKGROUND_PATH = "backgrounds/dojo-arena.jpg";

// Fase 10, sección 11-13: el backend ya calculó y guardó TODO el combate
// (CLAUDE.md #6) -esta escena solo lo REPRODUCE al ritmo que ella misma
// decide (sección 10: "Laravel no usa delays, Phaser controla el ritmo
// visual"), nunca vuelve a tirar un dado. Reutiliza CharacterRenderer
// (Fase 3, pensado desde entonces para "Arena/personalización futura",
// ver WorldScene.ts) en vez de crear un sistema de sprites nuevo.
//
// Mejora visual (post-Fase 10): el golpe usa el spritesheet real
// bodyAttack.base (Attack-Sheet.png, ver CharacterRenderer.playAttack())
// en vez del tween posicional simple original -no hay frame dedicado a
// "recibir daño" en ese sheet (inspeccionado a mano, ver manifest.json),
// así que el defensor sigue reaccionando con el shake() de siempre-.
//
// El log de texto (sección 11, "centro: log de combate") vive en HTML
// (`arena.astro`, mismo patrón que el resto de la UI del juego) — esta
// escena solo emite un evento por cada paso reproducido
// (`this.events.emit("combat-event", ...)`), igual que RoomScene emite
// eventos para que Astro dibuje sus propios paneles.
export class BattleScene extends Phaser.Scene {
  private manifest!: Manifest;
  private attackerData!: BattleFighterData;
  private defenderData!: BattleFighterData;
  private events_: CombatEventDto[] = [];

  private attackerSprite!: CharacterRenderer;
  private defenderSprite!: CharacterRenderer;
  private attackerHp = 0;
  private defenderHp = 0;

  private attackerBarFill!: Phaser.GameObjects.Rectangle;
  private defenderBarFill!: Phaser.GameObjects.Rectangle;
  private readonly barWidth = 160;

  constructor() {
    super("BattleScene");
  }

  init(data: BattleSceneData) {
    this.manifest = data.manifest;
    this.attackerData = data.attacker;
    this.defenderData = data.defender;
    this.events_ = data.events;
    this.attackerHp = data.attacker.maxHp;
    this.defenderHp = data.defender.maxHp;
  }

  preload() {
    this.load.image(BATTLE_BACKGROUND_KEY, publicUrl(BATTLE_BACKGROUND_PATH));
  }

  create() {
    this.cameras.main.setBackgroundColor("#20212b");

    const width = this.scale.width;
    const height = this.scale.height;
    const groundY = height * 0.72;

    this.addBattleBackground(width, height);

    // Suelo simple -sin tileset ni assets nuevos, sección 15/25-. Se deja
    // igual con el fondo real: es sutil (6px, gris oscuro) y sigue
    // sirviendo de referencia clara de "acá paran los personajes" sin
    // competir con el arte del piso del dojo.
    this.add.rectangle(width / 2, groundY + 30, width, 6, 0x3a3d4d);

    const attackerX = width * 0.28;
    const defenderX = width * 0.72;

    this.attackerSprite = new CharacterRenderer(this, attackerX, groundY, this.manifest, this.attackerData.appearance);
    this.attackerSprite.setScale(SCALE);

    this.defenderSprite = new CharacterRenderer(this, defenderX, groundY, this.manifest, this.defenderData.appearance);
    this.defenderSprite.setScale(-SCALE, SCALE); // espejado: mira hacia la izquierda, hacia el atacante.

    this.createNamePlate(attackerX, groundY - 110, this.attackerData.name, this.attackerData.level, "cyan");
    this.createNamePlate(defenderX, groundY - 110, this.defenderData.name, this.defenderData.level, "red");

    this.attackerBarFill = this.createHpBar(attackerX, groundY - 90);
    this.defenderBarFill = this.createHpBar(defenderX, groundY - 90);

    this.time.delayedCall(600, () => this.playNext(0));
  }

  // Mejora visual Arena: fondo real (dojo-arena.jpg, 1920x800) detrás de
  // los personajes. Analizado antes de ajustarlo: es una ilustración
  // detallada -NO pixel art-, así que se fuerza filtro LINEAR en esta
  // textura puntual (el `pixelArt:true` global del juego queda intacto
  // para personajes/tiles, esto es una excepción per-texture, no un
  // cambio de configuración del juego). "Cover" -escala por altura,
  // recorte simétrico en los costados por el origin centrado- en vez de
  // "contain": la imagen es mucho más ancha que el canvas (2.4:1 vs
  // ~1.29:1 de 900x700) y "contain" hubiera dejado franjas vacías arriba/
  // abajo; con "cover" además la línea de piso/tatami que la propia
  // imagen ya trae queda casi exactamente sobre `groundY` (ambas ~72% del
  // alto) sin necesidad de recortar ni desplazar nada a mano -coincidencia
  // real de la composición, verificada, no ajustada a ojo-. No es
  // escalado entero (0.875, la imagen es más grande que el canvas) porque
  // no es pixel art: acá "entero" no aplica, lo que importa es que no se
  // deforme (misma escala en X e Y) y no se recorte con nearest-neighbor.
  // Alpha reducido para que quede como telón de fondo -personajes, barras
  // de HP y el log de combate (HTML, por encima del canvas) siguen leyéndose
  // claro-.
  private addBattleBackground(width: number, height: number): void {
    const texture = this.textures.get(BATTLE_BACKGROUND_KEY);
    if (!texture || texture.key === "__MISSING") return;

    texture.setFilter(Phaser.Textures.FilterMode.LINEAR);

    const bg = this.add.image(width / 2, height / 2, BATTLE_BACKGROUND_KEY);
    const scale = height / bg.height;
    bg.setScale(scale);
    bg.setAlpha(0.4);
    bg.setDepth(-10);
  }

  private createNamePlate(x: number, y: number, name: string, level: number, color: string): void {
    this.add
      .text(x, y, `${name}  Nv.${level}`, {
        fontFamily: "monospace",
        fontSize: "13px",
        color: color === "cyan" ? "#7CE8E8" : "#E87C7C",
      })
      .setOrigin(0.5);
  }

  private createHpBar(x: number, y: number): Phaser.GameObjects.Rectangle {
    this.add.rectangle(x, y, this.barWidth + 4, 14, 0x000000).setOrigin(0.5);
    this.add.rectangle(x, y, this.barWidth, 10, 0x442222).setOrigin(0.5);
    const fill = this.add.rectangle(x - this.barWidth / 2, y, this.barWidth, 10, 0x4ade80).setOrigin(0, 0.5);
    fill.setData("baseX", x - this.barWidth / 2);
    return fill;
  }

  private updateHpBar(fill: Phaser.GameObjects.Rectangle, current: number, max: number): void {
    const pct = Math.max(0, Math.min(1, current / max));
    this.tweens.add({ targets: fill, width: this.barWidth * pct, duration: 300, ease: "Cubic.Out" });
    fill.fillColor = pct > 0.5 ? 0x4ade80 : pct > 0.2 ? 0xf5c542 : 0xe8544a;
  }

  private floatingDamage(x: number, y: number, text: string, color: string): void {
    const label = this.add
      .text(x, y, text, { fontFamily: "monospace", fontSize: "16px", color })
      .setOrigin(0.5);
    this.tweens.add({
      targets: label,
      y: y - 40,
      alpha: 0,
      duration: 900,
      ease: "Cubic.Out",
      onComplete: () => label.destroy(),
    });
  }

  private shake(sprite: CharacterRenderer): void {
    const baseX = sprite.x;
    this.tweens.add({
      targets: sprite,
      x: baseX + 6,
      duration: 60,
      yoyo: true,
      repeat: 3,
      onComplete: () => {
        sprite.x = baseX;
      },
    });
  }

  private playNext(index: number): void {
    if (index >= this.events_.length) {
      this.time.delayedCall(300, () => this.events.emit("combat-finished"));
      return;
    }

    const event = this.events_[index];
    this.events.emit("combat-event", { index, event });

    if (event.type === "dodge") {
      const dodger = event.actor === "attacker" ? this.attackerSprite : this.defenderSprite;
      this.floatingDamage(dodger.x, dodger.y - 80, "¡ESQUIVA!", "#9CA3AF");
    } else {
      const actorSprite = event.actor === "attacker" ? this.attackerSprite : this.defenderSprite;
      const targetSprite = event.target === "attacker" ? this.attackerSprite : this.defenderSprite;

      // Mejora visual Arena: la preparación/golpe/recuperación de
      // Attack-Sheet.png reemplaza el lunge posicional que había antes -el
      // cambio de pose ya transmite el ataque, sumarle además un tween de
      // posición se veía recargado-. El daño/shake/HP recién se aplican en
      // onImpact, cuando la animación llega al frame de golpe real (no al
      // arrancar el evento) — así el "impacto" visual y el daño numérico
      // quedan sincronizados de verdad, no solo por tiempo fijo.
      actorSprite.playAttack(this.manifest, () => {
        this.shake(targetSprite);

        const color = event.critical ? "#facc15" : "#ffffff";
        const label = event.critical ? `¡CRÍTICO! -${event.damage}` : `-${event.damage}`;
        this.floatingDamage(targetSprite.x, targetSprite.y - 90, label, color);

        if (event.target === "attacker") {
          this.attackerHp = event.target_hp_after;
          this.updateHpBar(this.attackerBarFill, this.attackerHp, this.attackerData.maxHp);
        } else {
          this.defenderHp = event.target_hp_after;
          this.updateHpBar(this.defenderBarFill, this.defenderHp, this.defenderData.maxHp);
        }
      });
    }

    this.time.delayedCall(MS_PER_EVENT, () => this.playNext(index + 1));
  }
}

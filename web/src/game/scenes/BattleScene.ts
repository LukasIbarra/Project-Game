import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
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

// Fase 10, sección 11-13: el backend ya calculó y guardó TODO el combate
// (CLAUDE.md #6) -esta escena solo lo REPRODUCE al ritmo que ella misma
// decide (sección 10: "Laravel no usa delays, Phaser controla el ritmo
// visual"), nunca vuelve a tirar un dado. Reutiliza CharacterRenderer
// (Fase 3, pensado desde entonces para "Arena/personalización futura",
// ver WorldScene.ts) en vez de crear un sistema de sprites nuevo -sección
// 25: nada de spritesheets de ataque, se anima con tweens simples sobre
// el idle existente-.
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

  create() {
    this.cameras.main.setBackgroundColor("#20212b");

    const width = this.scale.width;
    const height = this.scale.height;
    const groundY = height * 0.72;

    // Suelo simple -sin tileset ni assets nuevos, sección 15/25-.
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

  private lunge(sprite: CharacterRenderer, towards: number): void {
    const baseX = sprite.x;
    this.tweens.add({
      targets: sprite,
      x: baseX + (towards > baseX ? 20 : -20),
      duration: 150,
      yoyo: true,
      ease: "Quad.Out",
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
      this.lunge(actorSprite, targetSprite.x);
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
    }

    this.time.delayedCall(MS_PER_EVENT, () => this.playNext(index + 1));
  }
}

import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { npcTextureKey } from "./worldAssets";

// Fase 5: NPC quieto (no implementamos movimiento/IA todavía, según lo
// planeado). Conceptualmente es una entidad separada del jugador y de un
// futuro RemotePlayerEntity -PlayerEntity / NpcEntity / (a futuro)
// RemotePlayerEntity-, sin que eso implique un sistema complejo hoy.
export class Npc {
  readonly sprite: Phaser.GameObjects.Sprite;
  readonly nameLabel: Phaser.GameObjects.Text;

  constructor(
    scene: Phaser.Scene,
    x: number,
    y: number,
    private readonly npcId: string,
    readonly displayName: string,
    readonly dialogueLines: string[],
    manifest: Manifest
  ) {
    const idleAnimKey = `world-npc-${npcId}-idle`;
    if (!scene.anims.exists(idleAnimKey)) {
      const asset = manifest.npcs[npcId].idle;
      scene.anims.create({
        key: idleAnimKey,
        frames: scene.anims.generateFrameNumbers(npcTextureKey(npcId, "idle"), {
          frames: asset.frames,
        }),
        frameRate: asset.frameRate,
        repeat: -1,
      });
    }

    this.sprite = scene.add.sprite(x, y, npcTextureKey(npcId, "idle"), 0);
    this.sprite.play(idleAnimKey);

    this.nameLabel = scene.add
      .text(x, y - 26, displayName, {
        fontFamily: "monospace",
        fontSize: "10px",
        color: "#ece8e3",
        backgroundColor: "#0b0b0d",
        padding: { x: 3, y: 1 },
      })
      .setOrigin(0.5, 1);
  }

  distanceTo(x: number, y: number): number {
    return Phaser.Math.Distance.Between(this.sprite.x, this.sprite.y, x, y);
  }
}

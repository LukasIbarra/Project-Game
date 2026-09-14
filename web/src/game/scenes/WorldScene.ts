import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { InteractionUI } from "../world/InteractionUI";
import { Npc } from "../world/Npc";
import { WorldMap, type StructureInfo } from "../world/WorldMap";
import { WorldPlayer } from "../world/WorldPlayer";
import { TILED_MAP_KEY } from "../world/worldAssets";

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
  }

  update(_time: number, delta: number) {
    const deltaSeconds = delta / 1000;
    const dialogueOpen = this.ui.isDialogueOpen;

    if (!dialogueOpen) {
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

    if (Phaser.Input.Keyboard.JustDown(this.interactKey)) {
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
      // Fase 5: sin sistemas reales todavía detrás de estas entradas -solo
      // se deja registrado el punto de entrada, como pide esta fase.
      console.log(`[Mundo] Interacción con estructura: ${this.activeStructure.id}`);
      this.ui.showDialogue(this.activeStructure.label, ["Próximamente."]);
    }
  }
}

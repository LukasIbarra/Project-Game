import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import {
  preloadAttackAnimations,
  preloadCharacterLayers,
  preloadCharacterLayersForMany,
} from "../entities/CharacterRenderer";
import { DEFAULT_APPEARANCE } from "../entities/Player";
import { preloadRoomAssets, registerFurnitureFrames } from "../room/roomAssets";
import { preloadWorldAssets } from "../world/worldAssets";
import type { BattleFighterData } from "./BattleScene";

const MANIFEST_CACHE_KEY = "manifest";

interface PreloadSceneData {
  targetScene?: string;
}

// Fase 3: precarga en dos pasadas, como exige el Loader de Phaser cuando
// no se conoce de antemano qué archivos pedir. Pasada 1: baja
// manifest.json. Pasada 2 (en create(), ya con el manifest resuelto):
// encola los spritesheets reales de la apariencia por defecto y vuelve a
// correr el Loader antes de pasar a la escena final. Separada de
// BootScene para que cargar el personaje no dependa de si el backend
// responde o no.
// Fase 5: la misma pasada 2 ahora también encola los assets del Mundo
// (tileset, jugador 16x16, NPCs, estructuras, props) — un solo Loader,
// no un sistema de carga paralelo.
// Room: `targetScene` (recibido de BootScene, que a su vez lo lee del
// registry que dejó main.ts) decide si esta pasada carga los assets del
// Mundo o los de la Habitación -nunca ambos, cada página solo necesita
// los suyos-.
export class PreloadScene extends Phaser.Scene {
  private targetScene = "WorldScene";

  constructor() {
    super("PreloadScene");
  }

  init(data: PreloadSceneData) {
    this.targetScene = data.targetScene ?? "WorldScene";
  }

  preload() {
    this.load.json(MANIFEST_CACHE_KEY, "/assets/manifest.json");
  }

  create() {
    const manifest = this.cache.json.get(MANIFEST_CACHE_KEY) as Manifest;
    const sceneData = (this.registry.get("sceneData") as Record<string, unknown> | undefined) ?? {};

    if (this.targetScene === "BattleScene") {
      const attacker = sceneData.attacker as BattleFighterData | undefined;
      const defender = sceneData.defender as BattleFighterData | undefined;
      const appearances = [attacker?.appearance ?? DEFAULT_APPEARANCE, defender?.appearance ?? DEFAULT_APPEARANCE];
      preloadCharacterLayersForMany(this, manifest, appearances);
      // Mejora visual Arena: solo BattleScene necesita el sheet de ataque
      // -World/Room nunca llegan a este branch-.
      preloadAttackAnimations(this, manifest, appearances);
    } else {
      preloadCharacterLayers(this, manifest, DEFAULT_APPEARANCE);

      if (this.targetScene === "RoomScene") {
        preloadRoomAssets(this, manifest);
      } else {
        preloadWorldAssets(this, manifest);
      }
    }

    const finish = () => {
      if (this.targetScene === "RoomScene") {
        registerFurnitureFrames(this, manifest);
      }
      this.scene.start(this.targetScene, { manifest, ...sceneData });
    };

    if (this.load.list.size === 0) {
      finish();
      return;
    }

    this.load.once(Phaser.Loader.Events.COMPLETE, finish);
    this.load.start();
  }
}

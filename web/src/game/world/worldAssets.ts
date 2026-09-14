import Phaser from "phaser";
import { assetUrl, type Manifest } from "../assets/manifest";

// Fase 5 (integración Tiled): nombres de textura/mapa centralizados para
// que WorldMap/WorldPlayer/Npc no reconstruyan el string a mano.
export const TILED_MAP_KEY = "tiled-worldmap";

// F(Room): namespace parametrizable para que WorldPlayer sirva tanto para
// el jugador 16x32 del Mundo ("world-player") como para el 16x16
// top-down de la Habitación ("house-player") sin duplicar la clase -ver
// room/roomAssets.ts-.
export function playerTextureKey(namespace: string, clip: string): string {
  return `${namespace}-${clip}`;
}

export function npcTextureKey(npc: string, clip: string): string {
  return `world-npc-${npc}-${clip}`;
}

export function tiledTilesetTextureKey(name: string): string {
  return `tiled-tileset-${name}`;
}

// Encola en el Loader todo lo que el Mundo necesita: el mapa real de
// Tiled (convertido a JSON -Phaser no tiene parser de .tmx/XML nativo,
// ver CLAUDE.md-), la imagen de cada uno de sus tilesets, el jugador
// (characters/player/base, el mismo asset de Fase 2/3) y los NPCs. Se
// llama junto a preloadCharacterLayers en PreloadScene -mismo Loader, no
// un sistema de carga paralelo.
export function preloadWorldAssets(scene: Phaser.Scene, manifest: Manifest): void {
  scene.load.tilemapTiledJSON(TILED_MAP_KEY, assetUrl(manifest.tiledMap.sheet));

  for (const [name, asset] of Object.entries(manifest.tiledTilesets)) {
    scene.load.image(tiledTilesetTextureKey(name), assetUrl(asset.sheet));
  }

  for (const [clip, asset] of Object.entries(manifest.worldPlayer)) {
    scene.load.spritesheet(playerTextureKey("world-player", clip), assetUrl(asset.sheet), {
      frameWidth: asset.frameWidth,
      frameHeight: asset.frameHeight,
    });
  }

  for (const [npcName, npcAsset] of Object.entries(manifest.npcs)) {
    scene.load.spritesheet(npcTextureKey(npcName, "idle"), assetUrl(npcAsset.idle.sheet), {
      frameWidth: npcAsset.idle.frameWidth,
      frameHeight: npcAsset.idle.frameHeight,
    });
    if (npcAsset.walk) {
      scene.load.spritesheet(npcTextureKey(npcName, "walk"), assetUrl(npcAsset.walk.sheet), {
        frameWidth: npcAsset.walk.frameWidth,
        frameHeight: npcAsset.walk.frameHeight,
      });
    }
  }
}

import Phaser from "phaser";
import { assetUrl, type Manifest } from "../assets/manifest";
import { playerTextureKey, tiledTilesetTextureKey } from "../world/worldAssets";

// F(Room): mismo patrón que world/worldAssets.ts -nombres de textura/mapa
// centralizados-, para la habitación personal. Reutiliza
// tiledTilesetTextureKey/playerTextureKey (ya genéricos desde la
// generalización de WorldMap/WorldPlayer), no crea un sistema de carga
// paralelo.
export const ROOM_MAP_KEY = "tiled-room";
export const ROOM_PLAYER_NAMESPACE = "house-player";
export const FURNITURE_TEXTURE_KEY = "room-furniture-atlas";

export function furnitureFrameKey(itemKey: string): string {
  return `furniture-${itemKey}`;
}

export function preloadRoomAssets(scene: Phaser.Scene, manifest: Manifest): void {
  scene.load.tilemapTiledJSON(ROOM_MAP_KEY, assetUrl(manifest.roomMap.sheet));

  for (const [name, asset] of Object.entries(manifest.tiledTilesets)) {
    const key = tiledTilesetTextureKey(name);
    if (!scene.textures.exists(key)) {
      scene.load.image(key, assetUrl(asset.sheet));
    }
  }

  for (const [clip, asset] of Object.entries(manifest.housePlayer)) {
    const key = playerTextureKey(ROOM_PLAYER_NAMESPACE, clip);
    if (!scene.textures.exists(key)) {
      scene.load.spritesheet(key, assetUrl(asset.sheet), {
        frameWidth: asset.frameWidth,
        frameHeight: asset.frameHeight,
      });
    }
  }

  // Un solo atlas de furniture colocable (sección 2 de la fase: no
  // separar en decenas de PNG si el original ya sirve como atlas). Se
  // carga como imagen simple -no spritesheet uniforme, porque cada mueble
  // mide distinto (32x32 el cofre, 32x64 la cama, 16x32 velador/caja)- y
  // se registran los frames irregulares a mano en registerFurnitureFrames.
  if (!scene.textures.exists(FURNITURE_TEXTURE_KEY)) {
    scene.load.image(FURNITURE_TEXTURE_KEY, assetUrl(manifest.furniture.sheet));
  }
}

// Se llama una vez, después de que el Loader terminó (la textura ya
// existe) — recorta del atlas un frame nombrado por cada item_key
// colocable, usando las regiones de manifest.furniture.frames (dónde
// cortar, puramente visual) sin duplicar los tamaños/collision reales
// (esos viven en items.metadata_json, ver RoomFurniture.ts).
export function registerFurnitureFrames(scene: Phaser.Scene, manifest: Manifest): void {
  const texture = scene.textures.get(FURNITURE_TEXTURE_KEY);
  for (const [itemKey, frame] of Object.entries(manifest.furniture.frames)) {
    const frameName = furnitureFrameKey(itemKey);
    if (texture.has(frameName)) continue;
    texture.add(frameName, 0, frame.x, frame.y, frame.width, frame.height);
  }
}

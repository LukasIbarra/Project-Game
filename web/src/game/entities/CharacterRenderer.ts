import Phaser from "phaser";
import {
  assetUrl,
  layerTextureKey,
  resolveLayerAsset,
  type LayerCategory,
  type Manifest,
  type SpriteLayerAsset,
} from "../assets/manifest";

// Fase 3: Character Renderer. Compone un personaje a partir de capas
// independientes (body, hair, shirt, pants, shoes, weapon, accessory)
// apiladas en un Container, cada una resuelta por clave lógica vía el
// manifest de assets. Por ahora solo existe contenido real para "body"
// (el pack de personalización llega en una fase posterior) — las demás
// capas simplemente se omiten si la apariencia no las especifica o el
// manifest no tiene esa clave, sin romper el render.
//
// Orden de apilado (de abajo hacia arriba): cuerpo, pantalón, zapatos,
// camisa, pelo, arma, accesorio. Es el orden convencional para este tipo
// de personaje; se puede ajustar cuando haya assets reales para validarlo.
const LAYER_ORDER: LayerCategory[] = [
  "body",
  "pants",
  "shoes",
  "shirt",
  "hair",
  "weapon",
  "accessory",
];

export type CharacterAppearance = Partial<Record<LayerCategory, string>>;

// Encola en el Loader de la escena el spritesheet de cada layer que la
// apariencia solicita y que el manifest sabe resolver. Capas pedidas pero
// no encontradas en el manifest se ignoran (no rompen la carga) — el
// renderer las omite después por la misma razón.
export function preloadCharacterLayers(
  scene: Phaser.Scene,
  manifest: Manifest,
  appearance: CharacterAppearance
): void {
  for (const category of LAYER_ORDER) {
    const key = appearance[category];
    if (!key) continue;

    const asset = resolveLayerAsset(manifest, category, key);
    if (!asset) continue;

    scene.load.spritesheet(layerTextureKey(category, key), assetUrl(asset.sheet), {
      frameWidth: asset.frameWidth,
      frameHeight: asset.frameHeight,
    });
  }
}

// Fase 10 (BattleScene): precarga las apariencias de VARIOS personajes a
// la vez (atacante + defensor) sin encolar la misma textura dos veces -si
// ambos comparten, por ejemplo, `body:"base"` (el caso normal hoy, nadie
// tiene todavía cosméticos con sprite propio), el Loader de Phaser no
// debe recibir la misma key repetida en el mismo batch-.
export function preloadCharacterLayersForMany(
  scene: Phaser.Scene,
  manifest: Manifest,
  appearances: CharacterAppearance[]
): void {
  const seen = new Set<string>();

  for (const appearance of appearances) {
    const unique: CharacterAppearance = {};
    for (const category of LAYER_ORDER) {
      const key = appearance[category];
      if (!key || seen.has(`${category}:${key}`)) continue;
      seen.add(`${category}:${key}`);
      unique[category] = key;
    }
    preloadCharacterLayers(scene, manifest, unique);
  }
}

function ensureLayerAnimation(
  scene: Phaser.Scene,
  textureKey: string,
  asset: SpriteLayerAsset
): string {
  const animKey = `${textureKey}-idle`;
  if (!scene.anims.exists(animKey)) {
    scene.anims.create({
      key: animKey,
      frames: scene.anims.generateFrameNumbers(textureKey, {
        frames: asset.frames,
      }),
      frameRate: asset.frameRate,
      repeat: -1,
    });
  }
  return animKey;
}

export class CharacterRenderer extends Phaser.GameObjects.Container {
  constructor(
    scene: Phaser.Scene,
    x: number,
    y: number,
    manifest: Manifest,
    appearance: CharacterAppearance
  ) {
    super(scene, x, y);
    scene.add.existing(this);

    for (const category of LAYER_ORDER) {
      const key = appearance[category];
      if (!key) continue;

      const asset = resolveLayerAsset(manifest, category, key);
      if (!asset) continue;

      const textureKey = layerTextureKey(category, key);
      if (!scene.textures.exists(textureKey)) continue;

      const animKey = ensureLayerAnimation(scene, textureKey, asset);
      const layerSprite = scene.add.sprite(0, 0, textureKey, asset.frames[0]);
      layerSprite.play(animKey);
      this.add(layerSprite);
    }
  }
}

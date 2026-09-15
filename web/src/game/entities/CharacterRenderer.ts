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

const BODY_ATTACK_TEXTURE_PREFIX = "bodyAttack";

function bodyAttackTextureKey(key: string): string {
  return `${BODY_ATTACK_TEXTURE_PREFIX}:${key}`;
}

// Mejora visual Arena: solo BattleScene llama esto -World/Room nunca
// necesitan el sheet de ataque, así que mantenerlo fuera de
// preloadCharacterLayers(s) evita bajarlo donde no hace falta. Mismo
// criterio "no cargar la misma key dos veces" que preloadCharacterLayersForMany
// (hoy solo existe la variante "base", pero esto no asume eso).
export function preloadAttackAnimations(
  scene: Phaser.Scene,
  manifest: Manifest,
  appearances: CharacterAppearance[]
): void {
  const seen = new Set<string>();

  for (const appearance of appearances) {
    const key = appearance.body;
    if (!key || seen.has(key)) continue;

    const asset = manifest.bodyAttack?.[key];
    if (!asset) continue;

    seen.add(key);
    scene.load.spritesheet(bodyAttackTextureKey(key), assetUrl(asset.sheet), {
      frameWidth: asset.frameWidth,
      frameHeight: asset.frameHeight,
    });
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

// Mejora visual Arena: mismo mecanismo que ensureLayerAnimation (cachear
// por key, no recrear si ya existe), pero `repeat: 0` -es un golpe, se
// reproduce una vez y CharacterRenderer.playAttack() vuelve al idle solo
// al terminar (ANIMATION_COMPLETE), nunca queda en loop-.
function ensureBodyAttackAnimation(
  scene: Phaser.Scene,
  textureKey: string,
  asset: SpriteLayerAsset
): string {
  const animKey = `${textureKey}-attack`;
  if (!scene.anims.exists(animKey)) {
    scene.anims.create({
      key: animKey,
      frames: scene.anims.generateFrameNumbers(textureKey, {
        frames: asset.frames,
      }),
      frameRate: asset.frameRate,
      repeat: 0,
    });
  }
  return animKey;
}

export class CharacterRenderer extends Phaser.GameObjects.Container {
  // Mejora visual Arena: referencias guardadas SOLO para el layer "body"
  // -es el único que tiene un clip de ataque hoy (bodyAttack.base)-. Nunca
  // se "reemplaza" el sprite ni se crea uno nuevo para atacar: es el MISMO
  // GameObject cambiando de animación (idle <-> attack) y volviendo solo,
  // así que el resto del Container (hair/shirt/etc., cuando existan) sigue
  // apilado igual sin que BattleScene tenga que saber nada de eso.
  private bodySprite: Phaser.GameObjects.Sprite | null = null;
  private bodyIdleAnimKey: string | null = null;
  private bodyAppearanceKey: string | null = null;

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

      if (category === "body") {
        this.bodySprite = layerSprite;
        this.bodyIdleAnimKey = animKey;
        this.bodyAppearanceKey = key;
      }
    }
  }

  // Mejora visual Arena (BattleScene): reproduce el clip de
  // preparación->golpe->recuperación de bodyAttack.base UNA vez sobre el
  // mismo sprite de body, y vuelve solo al idle al terminar -nunca queda
  // "trabado" en la pose de golpe-. `onImpact` se dispara cuando la
  // animación llega al frame de golpe real (el del medio, ej. índice 3 de
  // 7: 0-2 preparación, 3 impacto, 4-6 recuperación) -así BattleScene
  // puede sincronizar el daño/shake del defensor con el golpe VISUAL en
  // vez de con el inicio del evento-. Si no hay sheet de ataque cargado
  // para esta apariencia (asset no resuelto, textura no precargada), no
  // rompe nada: solo llama onImpact enseguida y onComplete, sin animar.
  playAttack(manifest: Manifest, onImpact: () => void, onComplete?: () => void): void {
    const key = this.bodyAppearanceKey;
    const sprite = this.bodySprite;

    if (!key || !sprite) {
      onImpact();
      onComplete?.();
      return;
    }

    const asset = manifest.bodyAttack?.[key];
    const textureKey = bodyAttackTextureKey(key);

    if (!asset || !this.scene.textures.exists(textureKey)) {
      onImpact();
      onComplete?.();
      return;
    }

    const animKey = ensureBodyAttackAnimation(this.scene, textureKey, asset);
    const impactFrameIndex = Math.floor(asset.frames.length / 2);
    const impactDelayMs = (impactFrameIndex / asset.frameRate) * 1000;

    sprite.play(animKey);
    this.scene.time.delayedCall(impactDelayMs, onImpact);

    sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      if (this.bodyIdleAnimKey) {
        sprite.play(this.bodyIdleAnimKey);
      }
      onComplete?.();
    });
  }
}

// Fase 3: capa de resolución del sistema de assets desacoplado
// (ver CLAUDE.md, principio de arquitectura #5). El código del juego
// nunca debe escribir una ruta de archivo a mano: siempre pide un layer
// por categoría + clave lógica ("body" + "base") y este módulo resuelve
// dónde vive el asset real. Cambiar un asset provisional por uno
// definitivo = editar manifest.json, sin tocar código.

export type LayerCategory =
  | "body"
  | "hair"
  | "shirt"
  | "pants"
  | "shoes"
  | "weapon"
  | "accessory";

export interface SpriteLayerAsset {
  sheet: string;
  frameWidth: number;
  frameHeight: number;
  frames: number[];
  frameRate: number;
}

export interface NpcAsset {
  idle: SpriteLayerAsset;
  walk?: SpriteLayerAsset;
}

// Fase 5 (integración Tiled): una imagen simple sin frames -cada tileset
// del mapa real es solo una hoja de tiles que Phaser corta él mismo a
// partir de columns/tilewidth/tileheight, ya declarados en el propio
// mapa_base.json-. Solo hace falta la ruta.
export interface ImageAsset {
  sheet: string;
}

// Fase 5 (corrección jugador 16x16): una animación con variantes de
// dirección. Verificado con detección de componentes conectados +
// comparación de píxeles (no a ojo, ver CLAUDE.md): `characters/player/
// base` organiza cada sheet en `rows` filas (dirección: down, down-right,
// right, up-right, up, en ese orden fijo) x `columns` frames por fila. No
// hay filas para las diagonales/lado izquierdos -se obtienen espejando
// las filas 1/2/3 con flipX, técnica que el propio asset ya usa (el
// Rotate-Sheet trae las 8 direcciones y sus columnas espejadas son
// idénticas en píxeles a las columnas "derechas", 0% de diferencia)-.
export interface DirectionalAnimationAsset {
  sheet: string;
  frameWidth: number;
  frameHeight: number;
  columns: number;
  rows: number;
  frameRate: number;
}

type ManifestCategory = Record<string, SpriteLayerAsset>;

export interface Manifest {
  version: number;
  body: ManifestCategory;
  // Fase 10 (mejora visual Arena): animación de ataque de `body.base` -no
  // es un layer nuevo compuesto en el Container (nunca se "equipa"), es un
  // clip alternativo para el MISMO sprite de body que BattleScene activa
  // temporalmente durante un evento de golpe/crítico y revierte a idle al
  // terminar. Ver CharacterRenderer.playAttack().
  bodyAttack: ManifestCategory;
  hair: ManifestCategory;
  shirt: ManifestCategory;
  pants: ManifestCategory;
  shoes: ManifestCategory;
  weapon: ManifestCategory;
  accessory: ManifestCategory;
  // Fase 6: el Mundo/Hub vuelve a usar el sprite grande (16x32, frame real
  // 32x32) como personaje principal -mismo layout de 5 filas direccionales
  // + mirror que 16x16, solo escalado, ver public/assets/manifest.json-.
  // `housePlayer` reserva el 16x16 (frame real 24x24) para una futura
  // vista específica de Casa; ambos comparten el mismo tipo
  // `DirectionalAnimationAsset` y el mismo loader/animador genérico en
  // WorldPlayer.ts -no hay lógica de apariencia duplicada entre formatos,
  // solo cambia qué entrada del manifest se usa en cada escena-.
  worldPlayer: Record<string, DirectionalAnimationAsset>;
  housePlayer: Record<string, DirectionalAnimationAsset>;
  npcs: Record<string, NpcAsset>;
  tiledMap: ImageAsset;
  tiledTilesets: Record<string, ImageAsset>;
  // F(Room): mapa de la habitación personal — misma mecánica que
  // tiledMap/tiledTilesets (Tiled -> JSON convertido, ver
  // public/assets/world/room.tmx), un mapa Tiled más entre varios, no un
  // sistema paralelo. Sus tilesets viven en el mismo `tiledTilesets` de
  // arriba (las claves no colisionan, WorldMap solo usa las que su propio
  // mapa declara).
  roomMap: ImageAsset;
  // Atlas de furniture colocable (sección 19 de la fase): una sola imagen
  // + este mapa de qué región de píxeles usa cada item_key. La cantidad
  // de tiles/collision de cada uno vive en items.metadata_json (backend,
  // autoritativo) — este objeto es solo "dónde recortar", puramente
  // visual, para no duplicar los mismos números en dos lugares.
  furniture: {
    sheet: string;
    frames: Record<string, { x: number; y: number; width: number; height: number }>;
  };
}

const MANIFEST_URL = "/assets/manifest.json";

export async function fetchManifest(): Promise<Manifest> {
  const response = await fetch(MANIFEST_URL);
  if (!response.ok) {
    throw new Error(`No se pudo cargar el manifest de assets (HTTP ${response.status})`);
  }
  return response.json();
}

export function resolveLayerAsset(
  manifest: Manifest,
  category: LayerCategory,
  key: string
): SpriteLayerAsset | null {
  return manifest[category]?.[key] ?? null;
}

// Convierte una ruta relativa del manifest (puede tener espacios) en una
// URL válida bajo /assets/, codificando cada segmento por separado.
export function assetUrl(relativePath: string): string {
  const encodedSegments = relativePath.split("/").map(encodeURIComponent);
  return `/assets/${encodedSegments.join("/")}`;
}

// Clave lógica que identifica un layer cargado en Phaser, con el mismo
// formato "categoria:clave" que usa el manifest como ejemplo (hair:5).
export function layerTextureKey(category: LayerCategory, key: string): string {
  return `${category}:${key}`;
}

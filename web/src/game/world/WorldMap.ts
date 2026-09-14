import Phaser from "phaser";
import { tiledTilesetTextureKey } from "./worldAssets";
import type { AABB } from "./WorldPlayer";

// Fase 5 (integración Tiled): el mapa real diseñado en Tiled
// (`public/assets/world/mapa_base.tmx`, convertido a JSON porque Phaser
// no parsea .tmx/XML nativo) es ahora la única fuente de verdad del
// layout del mundo. Nada de tiles/árboles/estructuras hardcodeados en
// código -eso era el prototipo de la iteración anterior de esta fase,
// reemplazado por completo acá.
export interface StructureInfo {
  id: string;
  label: string;
  x: number;
  y: number;
  radius: number;
}

// Los 5 objetos con `type="collision"` del mapa tienen nombre propio.
// Los que además son puntos de entrada reales (Dojo/Casa/Arena/Mascota,
// pedidos por esta fase) se listan acá; "rio" queda como obstáculo sin
// interacción (agua, no se puede entrar).
const STRUCTURE_LABELS: Record<string, string> = {
  edificio: "Dojo",
  casa: "Casa",
  hotel: "Arena",
  pet: "Mascota",
};

interface TiledObject {
  id: number;
  name?: string;
  type?: string;
  x: number;
  y: number;
  width?: number;
  height?: number;
  polygon?: { x: number; y: number }[];
  point?: boolean;
}

function objectBoundingBox(obj: TiledObject): AABB {
  if (obj.polygon) {
    const xs = obj.polygon.map((p) => obj.x + p.x);
    const ys = obj.polygon.map((p) => obj.y + p.y);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
  }
  return { x: obj.x, y: obj.y, width: obj.width ?? 0, height: obj.height ?? 0 };
}

export class WorldMap {
  readonly pixelWidth: number;
  readonly pixelHeight: number;
  readonly structures: StructureInfo[] = [];
  private readonly collidables: AABB[] = [];

  constructor(private readonly scene: Phaser.Scene, mapKey: string) {
    const map = scene.make.tilemap({ key: mapKey });
    this.pixelWidth = map.widthInPixels;
    this.pixelHeight = map.heightInPixels;

    // Un tileset por imagen real (ver worldAssets.ts / manifest.json);
    // los nombres vienen del propio mapa convertido, no se repiten a mano.
    const tilesets = map.tilesets
      .map((ts) => map.addTilesetImage(ts.name, tiledTilesetTextureKey(ts.name)))
      .filter((ts): ts is Phaser.Tilemaps.Tileset => ts !== null);

    for (const layerData of map.layers) {
      map.createLayer(layerData.name, tilesets, 0, 0);
    }

    const objectLayer = map.getObjectLayer("Capa de Objetos 1");
    for (const obj of (objectLayer?.objects ?? []) as unknown as TiledObject[]) {
      if (obj.point) continue; // marcadores puntuales, sin área -no colisionan-

      const box = objectBoundingBox(obj);
      if (box.width <= 0 || box.height <= 0) continue;
      this.collidables.push(box);

      const label = obj.name ? STRUCTURE_LABELS[obj.name] : undefined;
      if (label) {
        this.structures.push({
          id: obj.name!,
          label,
          x: box.x + box.width / 2,
          y: box.y + box.height / 2,
          radius: Math.max(box.width, box.height) / 2 + 40,
        });
      }
    }
  }

  canMoveTo(box: AABB): boolean {
    if (box.x < 0 || box.y < 0 || box.x + box.width > this.pixelWidth || box.y + box.height > this.pixelHeight) {
      return false;
    }

    return !this.collidables.some((solid) => aabbOverlap(box, solid));
  }
}

function aabbOverlap(a: AABB, b: AABB): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

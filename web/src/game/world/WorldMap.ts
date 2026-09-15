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
  // Fase 15: ruta real ya validada (o null si el objeto no navega a
  // ningún lado -sin `targetPage`, o con un valor no reconocido-). Quien
  // consuma esto (WorldScene) nunca vuelve a mirar el nombre del objeto.
  targetPage: string | null;
}

// Solo para el texto del prompt ("E — Entrar a X") -cosmético, no decide
// destino-. Si un objeto no tiene nombre reconocido acá, se muestra su
// `name` crudo o un genérico; la navegación en sí nunca depende de esto.
const STRUCTURE_LABELS: Record<string, string> = {
  edificio: "Dojo",
  casa: "Casa",
  hotel: "Arena",
  pet: "Mascota",
};

// Fase 15: única lista blanca de destinos reales. La metadata de Tiled
// (`properties.targetPage`) manda una CLAVE lógica, nunca una ruta a
// mano; agregar un destino nuevo es una entrada acá, nada en WorldScene.
// El Dojo todavía no tiene página propia (pedido explícito de la fase) —
// "ranking" es su destino real por ahora, no se creó /dojo.
const TARGET_PAGES: Record<string, string> = {
  house: "/house",
  arena: "/arena",
  pet: "/pet",
  ranking: "/ranking",
};

interface TiledObjectProperty {
  name: string;
  type?: string;
  value: string;
}

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
  properties?: TiledObjectProperty[];
}

function stringProperty(obj: TiledObject, propertyName: string): string | undefined {
  return obj.properties?.find((p) => p.name === propertyName)?.value;
}

// Único lugar donde `targetPage` (string suelto de Tiled) se convierte en
// una ruta real -o en null, nunca en una navegación adivinada-.
function resolveTargetPage(obj: TiledObject): string | null {
  const key = stringProperty(obj, "targetPage");
  if (!key) return null;
  return TARGET_PAGES[key] ?? null;
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

      // Fase 15: la inclusión como punto de entrada interactuable depende
      // de `type="navigation"` (metadata formal de Tiled) — nunca de que
      // el nombre matchee algo a mano. El nombre solo aporta el texto
      // cosmético del prompt, con fallback si no hay uno mapeado.
      if (obj.type === "navigation") {
        this.structures.push({
          id: obj.name ?? `structure-${obj.id}`,
          label: (obj.name && STRUCTURE_LABELS[obj.name]) || obj.name || "Entrada",
          x: box.x + box.width / 2,
          y: box.y + box.height / 2,
          radius: Math.max(box.width, box.height) / 2 + 40,
          targetPage: resolveTargetPage(obj),
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

import Phaser from "phaser";
import { furnitureFrameKey, FURNITURE_TEXTURE_KEY } from "./roomAssets";

export interface FurnitureMetadata {
  tile_width: number;
  tile_height: number;
  collision: { x: number; y: number; width: number; height: number };
  placeable: boolean;
  rotatable: boolean;
}

export interface AABB {
  x: number;
  y: number;
  width: number;
  height: number;
}

const TILE_SIZE = 16;

// Room, sección 8/18: un furniture colocado = un sprite + un AABB de
// collision derivados de la MISMA metadata (items.metadata_json,
// backend) — cuando se mueve, ambos se recalculan juntos, nunca por
// separado (la sección 8 pide explícitamente que la collision nunca se
// quede en la posición anterior).
export class RoomFurniture {
  readonly roomObjectId: number;
  readonly itemKey: string;
  readonly sprite: Phaser.GameObjects.Sprite;
  private tileX: number;
  private tileY: number;

  constructor(
    scene: Phaser.Scene,
    roomObjectId: number,
    itemKey: string,
    private readonly metadata: FurnitureMetadata,
    tileX: number,
    tileY: number
  ) {
    this.roomObjectId = roomObjectId;
    this.itemKey = itemKey;
    this.tileX = tileX;
    this.tileY = tileY;

    this.sprite = scene.add.sprite(0, 0, FURNITURE_TEXTURE_KEY, furnitureFrameKey(itemKey));
    this.sprite.setOrigin(0, 0);
    this.applyPosition();
  }

  get tilePosition(): { x: number; y: number } {
    return { x: this.tileX, y: this.tileY };
  }

  get tileWidth(): number {
    return this.metadata.tile_width;
  }

  get tileHeight(): number {
    return this.metadata.tile_height;
  }

  setTilePosition(tileX: number, tileY: number): void {
    this.tileX = tileX;
    this.tileY = tileY;
    this.applyPosition();
  }

  // AABB en píxeles, misma fórmula que RoomPlacementService::footprint en
  // el backend (offset de collision relativo al sprite, no todo el PNG).
  footprint(): AABB {
    return {
      x: this.tileX * TILE_SIZE + this.metadata.collision.x,
      y: this.tileY * TILE_SIZE + this.metadata.collision.y,
      width: this.metadata.collision.width,
      height: this.metadata.collision.height,
    };
  }

  setTint(color: number | null): void {
    if (color === null) {
      this.sprite.clearTint();
    } else {
      this.sprite.setTint(color);
    }
  }

  setInteractiveSelectable(onClick: () => void): void {
    this.sprite.setInteractive({ useHandCursor: true });
    this.sprite.on("pointerdown", onClick);
  }

  destroy(): void {
    this.sprite.destroy();
  }

  private applyPosition(): void {
    this.sprite.setPosition(this.tileX * TILE_SIZE, this.tileY * TILE_SIZE);
    // Y-sort simple (sección 18): la profundidad es el borde inferior del
    // sprite -así el jugador se ve delante cuando está "más abajo" que el
    // mueble y detrás cuando está "más arriba", sin partir ningún sprite
    // en partes para los muebles simples de esta fase-.
    this.sprite.setDepth(this.sprite.y + this.metadata.tile_height * TILE_SIZE);
  }
}

<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Room;
use App\Models\RoomItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Room, sección 8/11/17: toda la validación de colocación/movimiento es
// server-authoritative (CLAUDE.md #1) — el cliente solo sugiere una
// posición, esto decide si es válida. La colisión visual del cliente
// (ghost verde/rojo) es solo UX, nunca la autoridad.
class RoomPlacementService
{
    // Tamaño real de la habitación (world/room.tmx: 32x24 tiles de
    // 16px). Constante acá porque el backend valida límites sin depender
    // de Phaser/Tiled en absoluto.
    private const ROOM_WIDTH_TILES = 32;

    private const ROOM_HEIGHT_TILES = 24;

    private const TILE_SIZE = 16;

    // Rectángulos de colisión ESTÁTICA de la habitación (paredes +
    // separador + rincón de banco de trabajo/yunque) — sacados tal cual
    // de los objetos `type="collision"` que ya quedaron en
    // world/room.tmx después de sacar el furniture dinámico de ahí (ver
    // CLAUDE.md). No son valores inventados: es la misma geometría que
    // Tiled ya tenía.
    private const STATIC_COLLISIONS = [
        ['x' => 26.56, 'y' => 7.74668, 'width' => 457.054, 'height' => 52.0134],   // pared trasera
        ['x' => 0, 'y' => 14.94, 'width' => 31.54, 'height' => 367.967],           // pared izquierda
        ['x' => 480.847, 'y' => 1.10667, 'width' => 30.4334, 'height' => 381.247], // pared derecha
        ['x' => 32.6467, 'y' => 352.474, 'width' => 449.307, 'height' => 30.4334], // pared delantera
        ['x' => 272.24, 'y' => 177.067, 'width' => 213.034, 'height' => 87.4268],  // separador
        ['x' => 311.527, 'y' => 266.707, 'width' => 50.9067, 'height' => 27.1134], // mesa de trabajo1
        ['x' => 384.567, 'y' => 252.874, 'width' => 46.4801, 'height' => 35.4134], // mesa de trabajo0
    ];

    public function __construct(private readonly InventoryGrantService $inventory)
    {
    }

    public function place(Room $room, Item $item, int $tileX, int $tileY): RoomItem
    {
        $character = $room->character;
        $metadata = $this->furnitureMetadata($item);

        if ($this->inventory->totalOwned($character, $item) < 1) {
            throw ValidationException::withMessages([
                'item_key' => ['No tenés ninguna unidad de este mueble en tu inventario.'],
            ]);
        }

        $footprint = $this->footprint($tileX, $tileY, $metadata);
        $this->assertValidPosition($room, $footprint, excludeRoomItemId: null);

        return DB::transaction(function () use ($room, $character, $item, $tileX, $tileY) {
            $this->inventory->consume($character, $item, 1);

            return RoomItem::create([
                'room_id' => $room->id,
                'item_id' => $item->id,
                'x' => $tileX,
                'y' => $tileY,
                'rotation' => 0,
                'layer' => 'floor',
            ]);
        });
    }

    public function move(RoomItem $roomItem, int $tileX, int $tileY): void
    {
        $room = $roomItem->room;
        $metadata = $this->furnitureMetadata($roomItem->item);

        $footprint = $this->footprint($tileX, $tileY, $metadata);
        $this->assertValidPosition($room, $footprint, excludeRoomItemId: $roomItem->id);

        $roomItem->update(['x' => $tileX, 'y' => $tileY]);
    }

    public function remove(RoomItem $roomItem): void
    {
        DB::transaction(function () use ($roomItem) {
            $character = $roomItem->room->character;
            $item = $roomItem->item;
            $roomItem->delete();
            $this->inventory->grant($character, $item, 1);
        });
    }

    /**
     * @return array{tile_width:int,tile_height:int,collision:array{x:int,y:int,width:int,height:int}}
     */
    private function furnitureMetadata(Item $item): array
    {
        $metadata = $item->metadata_json;

        if (! $metadata || empty($metadata['placeable']) || ! isset($metadata['tile_width'], $metadata['tile_height'], $metadata['collision'])) {
            throw ValidationException::withMessages([
                'item_key' => ['Este item no es furniture colocable.'],
            ]);
        }

        return $metadata;
    }

    private function footprint(int $tileX, int $tileY, array $metadata): array
    {
        $collision = $metadata['collision'];

        return [
            'x' => $tileX * self::TILE_SIZE + $collision['x'],
            'y' => $tileY * self::TILE_SIZE + $collision['y'],
            'width' => $collision['width'],
            'height' => $collision['height'],
            'tile_width' => $metadata['tile_width'],
            'tile_height' => $metadata['tile_height'],
        ];
    }

    private function assertValidPosition(Room $room, array $footprint, ?int $excludeRoomItemId): void
    {
        $roomWidthPx = self::ROOM_WIDTH_TILES * self::TILE_SIZE;
        $roomHeightPx = self::ROOM_HEIGHT_TILES * self::TILE_SIZE;

        if (
            $footprint['x'] < 0 || $footprint['y'] < 0
            || $footprint['x'] + $footprint['width'] > $roomWidthPx
            || $footprint['y'] + $footprint['height'] > $roomHeightPx
        ) {
            throw ValidationException::withMessages([
                'position' => ['Esa posición queda fuera de los límites de la habitación.'],
            ]);
        }

        foreach (self::STATIC_COLLISIONS as $solid) {
            if ($this->overlaps($footprint, $solid)) {
                throw ValidationException::withMessages([
                    'position' => ['Esa posición choca con una pared o estructura fija.'],
                ]);
            }
        }

        $others = $room->items()->with('item')->get();
        foreach ($others as $other) {
            if ($excludeRoomItemId !== null && $other->id === $excludeRoomItemId) {
                continue;
            }

            $otherMetadata = $other->item->metadata_json;
            if (! $otherMetadata || ! isset($otherMetadata['collision'])) {
                continue;
            }

            $otherFootprint = [
                'x' => $other->x * self::TILE_SIZE + $otherMetadata['collision']['x'],
                'y' => $other->y * self::TILE_SIZE + $otherMetadata['collision']['y'],
                'width' => $otherMetadata['collision']['width'],
                'height' => $otherMetadata['collision']['height'],
            ];

            if ($this->overlaps($footprint, $otherFootprint)) {
                throw ValidationException::withMessages([
                    'position' => ['Ya hay otro mueble en esa posición.'],
                ]);
            }
        }
    }

    private function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['width']
            && $a['x'] + $a['width'] > $b['x']
            && $a['y'] < $b['y'] + $b['height']
            && $a['y'] + $a['height'] > $b['y'];
    }
}

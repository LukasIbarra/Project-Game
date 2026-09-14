<?php

namespace Database\Seeders;

use App\Enums\ItemRarity;
use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Seeder;

// Fase Room: furniture colocable en la habitación personal. `small_chest`
// ya existía (F8, categoría "Casa") — acá solo se le agrega
// metadata_json de colocación, no se duplica el item. `bed_frame`/
// `nightstand`/`wooden_crate` son nuevos, mismo patrón que
// EconomyItemSeeder (sección 3-8 de F8).
//
// metadata_json.collision NO es un valor inventado: sale de las collision
// boxes que el propio autor del mapa ya dibujó a mano en Tiled
// (world/room.tmx original, antes de que estos 4 muebles pasaran a ser
// dinámicos), traducidas a coordenadas relativas al sprite -ver reporte
// de la fase para el detalle pixel a pixel-.
class RoomFurnitureSeeder extends Seeder
{
    public function run(): void
    {
        $furniture = [
            [
                'key' => 'small_chest',
                'name' => 'Cofre pequeño',
                'icon' => '📦',
                'type' => ItemType::Room,
                'rarity' => ItemRarity::Uncommon,
                'sell_value' => 60,
                'tile_width' => 2,
                'tile_height' => 2,
                'collision' => ['x' => 3, 'y' => 10, 'width' => 26, 'height' => 21],
            ],
            [
                'key' => 'bed_frame',
                'name' => 'Cama',
                'icon' => '🛏️',
                'type' => ItemType::Room,
                'rarity' => ItemRarity::Common,
                'sell_value' => 80,
                'tile_width' => 2,
                'tile_height' => 4,
                'collision' => ['x' => 1, 'y' => 7, 'width' => 31, 'height' => 50],
            ],
            [
                'key' => 'nightstand',
                'name' => 'Velador',
                'icon' => '🗄️',
                'type' => ItemType::Room,
                'rarity' => ItemRarity::Common,
                'sell_value' => 35,
                'tile_width' => 1,
                'tile_height' => 2,
                'collision' => ['x' => 0, 'y' => 3, 'width' => 16, 'height' => 28],
            ],
            [
                'key' => 'wooden_crate',
                'name' => 'Caja de madera',
                'icon' => '🗃️',
                'type' => ItemType::Room,
                'rarity' => ItemRarity::Common,
                'sell_value' => 20,
                'tile_width' => 1,
                'tile_height' => 2,
                'collision' => ['x' => 0, 'y' => 11, 'width' => 16, 'height' => 19],
            ],
        ];

        foreach ($furniture as $entry) {
            $metadata = [
                'tile_width' => $entry['tile_width'],
                'tile_height' => $entry['tile_height'],
                'collision' => $entry['collision'],
                'placeable' => true,
                'rotatable' => false,
            ];

            Item::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'name' => $entry['name'],
                    'icon' => $entry['icon'],
                    'type' => $entry['type'],
                    'rarity' => $entry['rarity'],
                    'sell_value' => $entry['sell_value'],
                    'stackable' => true,
                    'max_stack' => 99,
                    'metadata_json' => $metadata,
                ]
            );
        }

        $this->attachMetadataToExistingFurniture();
    }

    // Fase 9.1: `wooden_chair`/`simple_table`/`decorative_pot` ya existían
    // como Items desde F8 (EconomyItemSeeder) con recetas activas, pero
    // fueron sembrados ANTES de analizar el atlas real de furniture -no
    // tenían `metadata_json`, así que no eran colocables (violaba la
    // regla "no mostrar en crafting un furniture que no pueda ser
    // colocado realmente")-. Tras inspeccionar `Interior_Props_01.png`
    // recorte a recorte, estos 3 SÍ tienen un sprite real que les
    // corresponde con claridad (ver manifest.json → furniture.frames y el
    // reporte de la fase para las coordenadas exactas). Acá solo se les
    // agrega la metadata de colocación -no se toca name/icon/type/rarity/
    // sell_value, que pertenecen a EconomyItemSeeder y ya son correctos-.
    //
    // `lantern` y `ancient_totem` NO tienen un sprite real que los
    // represente en este atlas (se revisó todo el spritesheet: lo más
    // cercano a "linterna" es un candelabro de techo, y a "tótem" son
    // cabezas de trofeo de lobo/oso -ninguno de los dos es honestamente
    // el objeto que el nombre/ícono describen-), así que se dejan
    // deliberadamente SIN metadata (siguen sin ser colocables) y sus
    // recetas se desactivan en RecipeSeeder para que no aparezcan en
    // /crafting prometiendo un mueble que no se puede colocar de verdad.
    private function attachMetadataToExistingFurniture(): void
    {
        $existing = [
            // Silla con respaldo, sprite de 16x32 (1x2 tiles) -la
            // colisión solo cubre el asiento+patas (mitad inferior), el
            // respaldo es visual y no bloquea el paso por detrás.
            'wooden_chair' => [
                'tile_width' => 1,
                'tile_height' => 2,
                'collision' => ['x' => 2, 'y' => 16, 'width' => 12, 'height' => 16],
            ],
            // Mesa rectangular de madera con refuerzos metálicos, sprite
            // de 48x32 (3x2 tiles) -colisión casi todo el tablero, con un
            // margen pequeño para no bloquear las esquinas visualmente
            // libres.
            'simple_table' => [
                'tile_width' => 3,
                'tile_height' => 2,
                'collision' => ['x' => 2, 'y' => 2, 'width' => 44, 'height' => 28],
            ],
            // Maceta con flores, sprite de 16x32 (1x2 tiles) -la planta
            // en sí es solo visual, la colisión es nada más la base de
            // la maceta (franja inferior).
            'decorative_pot' => [
                'tile_width' => 1,
                'tile_height' => 2,
                'collision' => ['x' => 2, 'y' => 24, 'width' => 12, 'height' => 8],
            ],
        ];

        foreach ($existing as $key => $dims) {
            Item::where('key', $key)->update([
                'metadata_json' => [
                    'tile_width' => $dims['tile_width'],
                    'tile_height' => $dims['tile_height'],
                    'collision' => $dims['collision'],
                    'placeable' => true,
                    'rotatable' => false,
                ],
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ShopProduct;
use Illuminate\Database\Seeder;

// Fase 16 (primera versión): catálogo mínimo y mantenible -4 materiales de
// crafting comunes, 1 decorativo de Casa, 1 consumible-, tal como preveía
// el roadmap ("vender principalmente: materiales, decorativos,
// eventualmente consumibles"). Precios con un margen simple sobre
// `sell_value` (evita que comprar y vender de vuelta sea neutro) — no es
// una fórmula, son valores fijos a mano, coherentes con los de F8.
// `updateOrCreate` -mismo criterio que RecipeSeeder-: correr esto de nuevo
// (entrypoint.sh en cada boot) nunca duplica filas.
class ShopProductSeeder extends Seeder
{
    public function run(): void
    {
        $itemIds = Item::pluck('id', 'key');

        $products = [
            'wood' => 8,
            'stone' => 8,
            'wild_herb' => 10,
            'wood_plank' => 12,
            'wooden_chair' => 60,
            'small_potion' => 40,
        ];

        foreach ($products as $itemKey => $price) {
            if (! isset($itemIds[$itemKey])) {
                continue; // catálogo cambiado -no debería pasar, no rompe el resto del seed-.
            }

            ShopProduct::updateOrCreate(
                ['item_id' => $itemIds[$itemKey]],
                ['price' => $price, 'is_active' => true]
            );
        }
    }
}

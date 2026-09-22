<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\PetFoodItem;
use Illuminate\Database\Seeder;

// Fase 20: catálogo de comida -reusa items YA existentes del catálogo
// (F6/F8), no crea items nuevos. 'pet_treat' ("Premio para mascota") ya
// existía en EconomyItemSeeder sin ningún consumidor real hasta ahora;
// wild_herb/wild_mushroom son materiales de expedición reales (Bosque),
// coherentes temáticamente como comida "de la naturaleza".
class PetFoodItemSeeder extends Seeder
{
    public function run(): void
    {
        $food = [
            ['item_key' => 'pet_treat', 'exp_value' => 15],
            ['item_key' => 'wild_herb', 'exp_value' => 5],
            ['item_key' => 'wild_mushroom', 'exp_value' => 8],
        ];

        foreach ($food as $entry) {
            $item = Item::where('key', $entry['item_key'])->first();
            if (! $item) {
                continue; // catálogo incompleto en este entorno -no rompe el resto del seed-.
            }

            PetFoodItem::updateOrCreate(
                ['item_id' => $item->id],
                ['exp_value' => $entry['exp_value']]
            );
        }
    }
}

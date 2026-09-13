<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// CLAUDE.md, "Metodología de trabajo": los comandos Artisan de desarrollo
// (item:grant, character:set-level, expedition:complete, dev:reset) se
// crean recién cuando exista contenido que manipular -a partir de esta
// fase-. Este es el primero: da un item del catálogo a un personaje sin
// pasar por HTTP, para pruebas manuales locales.
class GrantItemCommand extends Command
{
    protected $signature = 'item:grant {character_id} {item_key} {quantity=1}';

    protected $description = 'Otorga un item del catálogo a un personaje (herramienta de desarrollo).';

    public function handle(): int
    {
        $character = Character::find($this->argument('character_id'));
        if (! $character) {
            $this->error('No existe un character con ese id.');

            return self::FAILURE;
        }

        $item = Item::where('key', $this->argument('item_key'))->first();
        if (! $item) {
            $this->error('No existe un item con esa key en el catálogo.');

            return self::FAILURE;
        }

        $quantity = max(1, (int) $this->argument('quantity'));

        DB::transaction(function () use ($character, $item, $quantity) {
            if ($item->stackable) {
                $stack = InventoryItem::firstOrNew([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                ]);
                $stack->quantity = min($item->max_stack, ($stack->quantity ?? 0) + $quantity);
                $stack->save();
            } else {
                for ($i = 0; $i < $quantity; $i++) {
                    InventoryItem::create([
                        'character_id' => $character->id,
                        'item_id' => $item->id,
                        'quantity' => 1,
                    ]);
                }
            }
        });

        $this->info("Otorgado: {$item->key} x{$quantity} -> personaje #{$character->id} ({$character->name}).");

        return self::SUCCESS;
    }
}

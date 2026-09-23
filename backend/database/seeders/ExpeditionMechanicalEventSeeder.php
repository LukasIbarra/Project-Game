<?php

namespace Database\Seeders;

use App\Models\ExpeditionDefinition;
use App\Models\ExpeditionEventDefinition;
use Illuminate\Database\Seeder;

// F22 (docs/PETS_EXPEDITIONS_SYSTEM.md §5.7/§7): eventos con consecuencia
// mecánica real -chest/enemy/help-, conviven con los 108 narrativos de F21
// (ExpeditionEventDefinitionSeeder) sin reemplazarlos. `requires_decision`
// vive en config_json (no se infiere del `type`): solo `enemy` pausa en
// esta siembra (fight/flee, tal como ejemplifica el documento de diseño),
// chest/help se auto-resuelven como narrative pero con consecuencia real
// -el motor (ExpeditionService) soporta cualquier combinación, esto es
// solo la decisión de CONTENIDO para esta siembra inicial-.
//
// `expedition_definition_id` null = universal (cualquier expedición); las
// entradas con key de expedición son flavor temático adicional (mismo
// criterio que los narrativos de F21: dato, no código -ninguna de estas
// filas requiere ni una línea nueva en ExpeditionService-).
class ExpeditionMechanicalEventSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = ExpeditionDefinition::pluck('id', 'key');

        foreach ($this->events() as $entry) {
            [$destinationKey, $type, $rarity, $title, $text, $config] = $entry;

            ExpeditionEventDefinition::updateOrCreate(
                ['text' => $text],
                [
                    'expedition_definition_id' => $destinationKey ? ($definitions[$destinationKey] ?? null) : null,
                    'type' => $type,
                    'rarity' => $rarity,
                    'weight' => 1,
                    'is_active' => true,
                    'title' => $title,
                    'config_json' => $config,
                ]
            );
        }
    }

    private function events(): array
    {
        return [
            // ===================== ENEMY universales (requiere decisión) =====================
            [
                null, 'enemy', 'common', 'Encuentro hostil',
                'Un lobo salvaje bloquea el camino de tu mascota, gruñendo con las orejas hacia atrás.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 60, 'loot_on_win_multiplier' => 1.2, 'damage_on_loss' => [8, 18]],
            ],
            [
                null, 'enemy', 'uncommon', 'Algo se acerca',
                'Una criatura que no llegás a reconocer emerge de entre las sombras, observando en silencio.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 45, 'loot_on_win_multiplier' => 1.5, 'damage_on_loss' => [12, 25]],
            ],

            // ===================== CHEST universales (auto-resuelve) =====================
            [
                null, 'chest', 'common', 'Cofre olvidado',
                'Un cofre medio enterrado entre la maleza llama la atención de tu mascota.',
                ['requires_decision' => false, 'open_odds' => ['loot' => 70, 'trap' => 15, 'nothing' => 15], 'loot_multiplier' => 1.3, 'trap_damage' => [5, 12]],
            ],
            [
                null, 'chest', 'common', 'Algo entre la maleza',
                'Hay un bulto sospechoso entre la maleza, medio cubierto de tierra.',
                ['requires_decision' => false, 'open_odds' => ['loot' => 60, 'trap' => 20, 'nothing' => 20], 'loot_multiplier' => 1.1, 'trap_damage' => [5, 15]],
            ],

            // ===================== HELP universales (auto-resuelve) =====================
            [
                null, 'help', 'common', 'Alguien necesita ayuda',
                'Una criatura pequeña quedó atrapada y parece necesitar una mano.',
                ['requires_decision' => false, 'success_chance' => 65, 'reward_on_success' => 'special', 'damage_on_failure' => [3, 10]],
            ],
            [
                null, 'help', 'uncommon', 'Un viajero perdido',
                'Un viajero perdido le pide indicaciones a tu mascota, señalando un mapa arrugado.',
                ['requires_decision' => false, 'success_chance' => 75, 'reward_on_success' => 'special', 'damage_on_failure' => [2, 8]],
            ],

            // ===================== ENEMY temáticos por expedición =====================
            [
                'forest', 'enemy', 'common', 'Guardián del bosque',
                'Un espíritu del bosque se interpone en el camino, con los ojos brillando entre las ramas.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 60, 'loot_on_win_multiplier' => 1.2, 'damage_on_loss' => [6, 15]],
            ],
            [
                'windy_hills', 'enemy', 'common', 'Bandido de las colinas',
                'Un bandido solitario aparece entre las rocas, cortando el paso con una honda en la mano.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 55, 'loot_on_win_multiplier' => 1.2, 'damage_on_loss' => [8, 16]],
            ],
            [
                'mountains', 'enemy', 'common', 'Lobo de las nieves',
                'Un lobo blanco, casi invisible contra la nieve, gruñe desde una saliente rocosa.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 50, 'loot_on_win_multiplier' => 1.3, 'damage_on_loss' => [10, 20]],
            ],
            [
                'ancient_ruins', 'enemy', 'uncommon', 'Guardián de piedra',
                'Una estatua que debería estar inmóvil gira lentamente la cabeza hacia tu mascota.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 45, 'loot_on_win_multiplier' => 1.4, 'damage_on_loss' => [12, 22]],
            ],
            [
                'cursed_swamp', 'enemy', 'uncommon', 'Criatura corrupta',
                'Algo que alguna vez fue un animal normal se arrastra fuera del barro, deforme y hostil.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 45, 'loot_on_win_multiplier' => 1.4, 'damage_on_loss' => [14, 24]],
            ],
            [
                'blood_castle', 'enemy', 'rare', 'Siervo del castillo',
                'Un siervo pálido del castillo bloquea el pasillo, con una mirada que no parpadea.',
                ['requires_decision' => true, 'options' => ['fight', 'flee'], 'win_chance_base' => 40, 'loot_on_win_multiplier' => 1.6, 'damage_on_loss' => [15, 28]],
            ],
        ];
    }
}

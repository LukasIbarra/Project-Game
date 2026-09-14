<?php

namespace Database\Seeders;

use App\Enums\PetNarrativeCategory as Cat;
use App\Enums\PetNarrativeRarity as Rar;
use App\Models\PetDestination;
use App\Models\PetNarrativeEvent;
use Illuminate\Database\Seeder;

// F7.1: catálogo de contenido narrativo -sin efecto mecánico (ver
// migración). `destination_key` null = universal (cualquier destino).
// Distribución objetivo por destino: ~20 common / ~6 uncommon / ~3 rare /
// ~1 very_rare de 30; universal ~12/4/1/1 de 18. Total ~108.
//
// Tono: absurdo, seco, ocasionalmente oscuro, nunca explícito. Ver
// CLAUDE.md / instrucciones de F7.1 para el criterio completo -no repetir
// acá, solo el contenido resultante-.
class PetNarrativeEventSeeder extends Seeder
{
    public function run(): void
    {
        $destinations = PetDestination::pluck('id', 'key');

        foreach ($this->events() as $entry) {
            [$destinationKey, $category, $rarity, $text] = $entry;

            PetNarrativeEvent::updateOrCreate(
                ['text' => $text],
                [
                    'destination_id' => $destinationKey ? ($destinations[$destinationKey] ?? null) : null,
                    'category' => $category,
                    'rarity' => $rarity,
                    'weight' => 1,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{0: ?string, 1: Cat, 2: Rar, 3: string}>
     */
    private function events(): array
    {
        return [
            // ============================= UNIVERSALES (18) =============================
            [null, Cat::Thought, Rar::Common, 'Compañero está emocionado por su aventura. Todavía no sabe muy bien por qué.'],
            [null, Cat::Thought, Rar::Common, 'Compañero se detuvo a pensar en la vida. Después siguió caminando, sin conclusiones.'],
            [null, Cat::Memory, Rar::Common, 'Compañero recordó que alguna vez tuvo trabajo. Inmediatamente dejó de pensar en eso.'],
            [null, Cat::Memory, Rar::Common, 'Compañero encontró una botella vacía. Por alguna razón, le resultó familiar.'],
            [null, Cat::Thought, Rar::Common, 'Compañero está pensando en sus decisiones de vida. No llegó a ninguna conclusión importante.'],
            [null, Cat::Funny, Rar::Common, 'Compañero se rascó una oreja durante un buen rato. Fue el punto más alto de la última hora.'],
            [null, Cat::Funny, Rar::Common, 'Compañero encontró una moneda en el suelo. No vale nada donde está. Se la quedó igual.'],
            [null, Cat::Funny, Rar::Common, 'Compañero bostezó. No hay ninguna razón particular para mencionarlo, pero ocurrió.'],
            [null, Cat::Thought, Rar::Common, 'Compañero se detuvo un momento a escuchar el silencio. Le pareció razonablemente agradable.'],
            [null, Cat::Memory, Rar::Common, 'Compañero recordó una canción. No recuerda cómo sigue. Siguió caminando de todas formas.'],
            [null, Cat::Funny, Rar::Common, 'Compañero encontró su propio reflejo en un charco. Se saludó a sí mismo, por si acaso.'],
            [null, Cat::Thought, Rar::Common, 'Compañero decidió tomarse un descanso. Nadie se lo impidió.'],
            [null, Cat::Memory, Rar::Uncommon, 'Compañero recordó sus años como humano. Recordó una noche de fiesta. No recordó cómo llegó a casa.'],
            [null, Cat::Memory, Rar::Uncommon, 'Compañero comenzó a cuestionar algunas decisiones que tomó antes de convertirse en mascota.'],
            [null, Cat::Strange, Rar::Uncommon, 'Compañero sintió que algo lo estaba observando. No está seguro. Decidió no darle más vueltas.'],
            [null, Cat::Memory, Rar::Uncommon, 'Compañero intentó recordar su nombre de antes. No pudo. Tampoco insistió demasiado.'],
            [null, Cat::Rare, Rar::Rare, 'Compañero escuchó, con total claridad, que alguien decía su nombre. No había nadie cerca.'],
            [null, Cat::Rare, Rar::VeryRare, 'Compañero vio, por un instante, una versión ligeramente distinta de sí mismo a la distancia. Decidió no investigar.'],

            // ============================= BOSQUE (30) =============================
            ['forest', Cat::Funny, Rar::Common, 'Compañero encontró un palo. Ahora es su palo.'],
            ['forest', Cat::Environment, Rar::Common, 'Compañero escuchó algo entre los árboles. Decidió que probablemente no era importante.'],
            ['forest', Cat::Funny, Rar::Common, 'Compañero fue a orinar detrás de un árbol. No preguntes cuál.'],
            ['forest', Cat::Animal, Rar::Common, 'Compañero vio un conejo. El conejo lo miró. Compañero siguió caminando.'],
            ['forest', Cat::Environment, Rar::Common, 'Compañero pisó un hongo. No sabe si eso cuenta como una victoria.'],
            ['forest', Cat::Environment, Rar::Common, 'Compañero se detuvo a oler una flor. No le encontró ningún propósito, pero lo hizo de todas formas.'],
            ['forest', Cat::Environment, Rar::Common, 'Compañero encontró un camino que no reconoce. Decidió seguirlo de todas formas.'],
            ['forest', Cat::Animal, Rar::Common, 'Compañero lleva diez minutos mirando una ardilla. La ardilla parece estar ganando.'],
            ['forest', Cat::Environment, Rar::Common, 'Empezó a lloviznar. Compañero fingió que no le importaba.'],
            ['forest', Cat::Funny, Rar::Common, 'Compañero encontró una piedra con forma de pollo. No sabe qué significa. La guardó mentalmente.'],
            ['forest', Cat::Thought, Rar::Common, 'Compañero se sentó a descansar. Después recordó que no tenía ninguna razón para estar cansado. Se quedó sentado de todas formas.'],
            ['forest', Cat::Animal, Rar::Common, 'Un pájaro cantó cerca. Compañero intentó imitarlo. No le salió bien.'],
            ['forest', Cat::Thought, Rar::Common, 'Compañero encontró un tronco hueco. Se planteó vivir ahí. Solo por un segundo.'],
            ['forest', Cat::Funny, Rar::Common, 'Compañero se enredó con unas raíces. Miró alrededor para comprobar que nadie lo hubiera visto.'],
            ['forest', Cat::Funny, Rar::Common, 'Compañero encontró un grupo de hormigas trabajando. Se sintió ligeramente improductivo en comparación.'],
            ['forest', Cat::Strange, Rar::Common, 'El bosque estaba en silencio. A Compañero le pareció sospechoso. Siguió caminando igual.'],
            ['forest', Cat::Funny, Rar::Common, 'Compañero mordisqueó una hoja. No estaba buena. Lo volvería a hacer.'],
            ['forest', Cat::Environment, Rar::Common, 'Compañero encontró una telaraña enorme. Decidió que ese no era su problema.'],
            ['forest', Cat::Animal, Rar::Common, 'Compañero siguió una mariposa durante un rato. No tenía ningún plan más allá de eso.'],
            ['forest', Cat::Thought, Rar::Common, 'Compañero se detuvo a mirar cómo la luz atravesaba las hojas. Fue un buen momento.'],
            ['forest', Cat::Funny, Rar::Uncommon, "Compañero encontró un cartel que decía \"NO ENTRE\".\nCompañero siguió caminando."],
            ['forest', Cat::Thought, Rar::Uncommon, 'Compañero se perdió durante un rato. Decidió llamarlo "exploración".'],
            ['forest', Cat::Strange, Rar::Uncommon, 'Compañero encontró un nido abandonado. Se preguntó qué le habría pasado a sus dueños. Prefirió no pensarlo más.'],
            ['forest', Cat::OtherPlayer, Rar::Uncommon, 'Compañero vio a lo lejos a la mascota de otro aventurero. Se saludaron.'],
            ['forest', Cat::Strange, Rar::Uncommon, 'Compañero encontró un círculo de setas perfecto. Algo en su instinto le dijo que no pisara dentro.'],
            ['forest', Cat::Dark, Rar::Uncommon, 'Compañero escuchó pasos detrás de él. Cuando se dio vuelta, no había nada. Siguió caminando, un poco más rápido.'],
            ['forest', Cat::Dark, Rar::Rare, 'Compañero encontró un esqueleto pequeño entre las raíces de un árbol. Decidió no molestarlo.'],
            ['forest', Cat::Dark, Rar::Rare, 'Compañero encontró una muñeca vieja colgada de una rama. Prefirió no acercarse.'],
            ['forest', Cat::Strange, Rar::Rare, 'Compañero vio una sombra entre los árboles. La sombra también lo vio. Ambos decidieron seguir con sus vidas.'],
            ['forest', Cat::Rare, Rar::VeryRare, 'Compañero encontró una puerta de madera en medio del bosque, sin ninguna pared alrededor. No recuerda haberla visto antes. Decidió no abrirla.'],

            // ============================= MONTAÑAS (30) =============================
            ['mountains', Cat::Environment, Rar::Common, 'El viento soplaba fuerte. Compañero fingió que no lo afectaba.'],
            ['mountains', Cat::Thought, Rar::Common, 'Compañero encontró una roca con una vista increíble. Se quedó un rato mirando la nada.'],
            ['mountains', Cat::Funny, Rar::Common, 'Compañero resbaló un poco en el camino. Miró alrededor para comprobar que nadie lo hubiera visto.'],
            ['mountains', Cat::Animal, Rar::Common, 'Un águila pasó volando cerca. Compañero se sintió pequeño en comparación.'],
            ['mountains', Cat::Funny, Rar::Common, 'Compañero encontró nieve donde no esperaba encontrar nieve. La probó. Fue un error.'],
            ['mountains', Cat::Thought, Rar::Common, 'El aire se sintió más liviano ahí arriba. Compañero respiró hondo, más que nada por hacer algo.'],
            ['mountains', Cat::Funny, Rar::Common, 'Compañero gritó hacia el valle solo para escuchar el eco. Valió la pena.'],
            ['mountains', Cat::Environment, Rar::Common, 'Compañero encontró una cueva pequeña. Decidió no entrar. Todavía.'],
            ['mountains', Cat::Thought, Rar::Common, 'El camino se puso empinado. Compañero consideró seriamente sus decisiones de vida.'],
            ['mountains', Cat::Animal, Rar::Common, 'Compañero vio una cabra en un lugar donde ninguna cabra debería poder pararse. La respetó profundamente.'],
            ['mountains', Cat::Environment, Rar::Common, 'Una nube pasó tan cerca que Compañero pudo tocarla. No sintió nada especial. Un poco decepcionante.'],
            ['mountains', Cat::Strange, Rar::Common, 'Compañero encontró huellas en la nieve. No eran suyas. Decidió seguir su propio camino.'],
            ['mountains', Cat::Thought, Rar::Common, 'El frío se sintió distinto acá arriba. Compañero se lo tomó como algo personal.'],
            ['mountains', Cat::Funny, Rar::Common, 'Compañero se detuvo a descansar sobre una roca plana. La roca no pidió permiso para estar tan fría.'],
            ['mountains', Cat::Funny, Rar::Common, 'Compañero encontró un pequeño charco congelado. Lo rompió con la pata. Se sintió poderoso.'],
            ['mountains', Cat::Dark, Rar::Common, 'El viento aulló entre las rocas. Compañero decidió que era mejor no quedarse a escuchar por qué.'],
            ['mountains', Cat::Thought, Rar::Common, 'Compañero vio su propio aliento en el aire por primera vez. Le pareció fascinante durante unos tres segundos.'],
            ['mountains', Cat::Animal, Rar::Common, 'Un cuervo lo observó desde una roca. Compañero le sostuvo la mirada. Ganó, probablemente.'],
            ['mountains', Cat::Thought, Rar::Common, 'Compañero encontró un mirador natural. Se sentó a pensar en nada en particular.'],
            ['mountains', Cat::Environment, Rar::Common, 'El sendero se bifurcaba. Compañero eligió el camino de la izquierda sin ninguna razón en especial.'],
            ['mountains', Cat::Strange, Rar::Uncommon, 'Compañero encontró una bandera vieja clavada en una roca. No reconoce el símbolo. Decidió dejarla ahí.'],
            ['mountains', Cat::OtherPlayer, Rar::Uncommon, 'Compañero vio a lo lejos la mascota de otro aventurero escalando la misma ladera. Ninguno de los dos se acercó.'],
            ['mountains', Cat::Strange, Rar::Uncommon, 'Compañero encontró restos de una fogata apagada. Nadie a la vista. Se preguntó cuánto tiempo llevaba ahí.'],
            ['mountains', Cat::Funny, Rar::Uncommon, 'Compañero se acercó demasiado al borde de un precipicio. Retrocedió con dignidad.'],
            ['mountains', Cat::Dark, Rar::Uncommon, 'Un eco le devolvió su propio grito, pero un segundo más tarde de lo que debería.'],
            ['mountains', Cat::Strange, Rar::Uncommon, 'Compañero encontró un guante solitario a mitad de camino. Se preguntó qué le habría pasado al otro.'],
            ['mountains', Cat::Strange, Rar::Rare, 'Compañero encontró unos esquíes viejos, muy por encima de la línea de nieve. No hay ninguna huella que lleve hasta ahí.'],
            ['mountains', Cat::Dark, Rar::Rare, 'Compañero encontró una tienda de campaña vacía, todavía armada. Adentro, todo estaba ordenado. Demasiado ordenado.'],
            ['mountains', Cat::Strange, Rar::Rare, 'Compañero vio una figura inmóvil en la cima de la montaña. Cuando volvió a mirar, ya no estaba.'],
            ['mountains', Cat::Rare, Rar::VeryRare, 'Compañero llegó a un punto del camino que juraría haber recorrido antes, en esta misma expedición. No dijo nada al respecto.'],

            // ============================= CASTILLO SANGRIENTO (30) =============================
            ['blood_castle', Cat::Funny, Rar::Common, 'Compañero entró al castillo. Las bisagras de la puerta chirriaron de forma innecesariamente dramática.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Compañero encontró una armadura vacía. Le pareció que lo miraba. Decidió no comprobarlo.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Un cuadro en la pared parecía seguirlo con la mirada. Compañero optó por caminar más rápido.'],
            ['blood_castle', Cat::Environment, Rar::Common, 'Compañero encontró una telaraña del tamaño de una puerta. Prefirió tomar otro pasillo.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Las velas del castillo estaban encendidas. No hay nadie que las haya encendido. Compañero no hizo preguntas.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Compañero escuchó pasos en el piso de arriba. El castillo no tiene piso de arriba.'],
            ['blood_castle', Cat::Environment, Rar::Common, 'Compañero encontró una biblioteca polvorienta. Ningún libro tenía título. Decidió no leer ninguno.'],
            ['blood_castle', Cat::Environment, Rar::Common, 'Una corriente de aire frío recorrió el pasillo. No había ninguna ventana abierta.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Compañero encontró un salón de banquetes vacío, con la mesa todavía servida. La comida se veía... vieja.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Compañero pasó junto a una armadura y juraría que esta le devolvió el saludo. No se detuvo a confirmarlo.'],
            ['blood_castle', Cat::Dark, Rar::Common, 'El eco de sus propios pasos sonaba un poco más lento de lo que caminaba.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Compañero encontró un espejo cubierto con una sábana. Decidió que estaba bien así.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Una gárgola del techo cambió de posición cuando Compañero no estaba mirando. Probablemente.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Compañero encontró un trono vacío. Se sentó un segundo. Solo para probar.'],
            ['blood_castle', Cat::Thought, Rar::Common, 'El castillo olía a humedad y a decisiones antiguas.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'Compañero encontró un candelabro que se balanceaba solo. No había viento.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Una puerta se cerró sola detrás de él. Compañero decidió que el castillo simplemente tenía corrientes de aire raras.'],
            ['blood_castle', Cat::Dark, Rar::Common, 'Compañero encontró manchas oscuras en el piso de piedra. Decidió no investigar de qué eran.'],
            ['blood_castle', Cat::Strange, Rar::Common, 'En una de las habitaciones, todos los relojes marcaban una hora distinta. Ninguno la correcta.'],
            ['blood_castle', Cat::Funny, Rar::Common, 'Compañero encontró una capa vieja colgada en una percha. Se la probó mentalmente. Le quedaba bien.'],
            ['blood_castle', Cat::Dark, Rar::Uncommon, 'Compañero encontró un esqueleto sentado a una mesa, como si estuviera esperando a alguien. Decidió no ser esa persona.'],
            ['blood_castle', Cat::Dark, Rar::Uncommon, 'Compañero escuchó una risa lejana en algún pasillo del castillo. No sonaba particularmente amistosa.'],
            ['blood_castle', Cat::OtherPlayer, Rar::Uncommon, 'Compañero vio a lo lejos la mascota de otro aventurero, parada muy quieta frente a un retrato. No se movió en todo el tiempo que Compañero pudo verla.'],
            ['blood_castle', Cat::Strange, Rar::Uncommon, 'Compañero encontró una habitación completamente vacía, salvo por una silla mirando a la pared.'],
            ['blood_castle', Cat::Animal, Rar::Uncommon, 'Un murciélago pasó volando cerca. Compañero decidió que probablemente era solo un murciélago.'],
            ['blood_castle', Cat::Dark, Rar::Uncommon, 'Compañero encontró una carta sin terminar sobre un escritorio. La última palabra estaba a la mitad.'],
            ['blood_castle', Cat::Dark, Rar::Rare, 'Compañero encontró un ataúd abierto y vacío en el sótano. Decidió no quedarse a averiguar por qué estaba abierto.'],
            ['blood_castle', Cat::Dark, Rar::Rare, 'Compañero encontró una espada oxidada junto a un cadáver muy viejo. La espada parecía en mejor estado que su antiguo dueño.'],
            ['blood_castle', Cat::Strange, Rar::Rare, 'Un retrato en la pared tenía los ojos mirando hacia una dirección distinta a como Compañero lo recordaba de antes.'],
            ['blood_castle', Cat::Rare, Rar::VeryRare, 'Compañero encontró una fotografía vieja tirada en un pasillo del castillo. En ella aparece alguien que se parece sospechosamente a él.'],
        ];
    }
}

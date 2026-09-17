# Auditoría técnica: Sistema de Combate / Arena

**Tipo de documento:** auditoría + propuesta de arquitectura. **No implementa nada.**
Ningún archivo de gameplay fue modificado para producir este documento (cero
migraciones, cero cambios de fórmula, cero cambios de cooldown, cero clases,
cero atributos nuevos, `BattleScene.ts` y el sistema de Replay de la Fase 17
intactos).

**Fecha:** 2026-09-16. **Alcance:** todo lo que hoy determina el resultado de
un combate de Arena, de dónde salen las estadísticas de un personaje, y qué
tan invasivo sería evolucionar ese sistema hacia builds/arquetipos/puntos de
atributo/equipamiento diversificado, según lo pedido antes de abrir una Fase
18 de implementación.

---

## 0. Resumen ejecutivo

| Pregunta | Respuesta corta |
|---|---|
| ¿El nivel determina el resultado hoy? | **Sí, casi por completo.** Ver §1.7 y §2.3: subir de nivel sube `strength`/`agility`/`vitality` EN LA MISMA CANTIDAD Y PROPORCIÓN para cualquier personaje. Dos personajes del mismo nivel tienen exactamente las mismas stats base, siempre, sin excepción. |
| ¿Existe diversidad de builds hoy? | **No, prácticamente ninguna.** La única fuente de diferencia entre dos personajes del mismo nivel es el equipamiento (7 slots, 4 items con `stat_bonus` en todo el catálogo actual). No hay puntos de atributo, no hay clases, no hay forma de invertir en una stat más que en otra. |
| ¿Qué tan invasivo es tocar el sistema de stats? | **Medio-bajo en el frontend, medio en el backend.** El frontend NO lee `strength`/`agility`/`vitality` en ningún lado (§3.2) — solo consume el objeto ya calculado (`max_hp`/`attack`/`defense`/`crit_chance`/`dodge_chance`). El backend concentra el 100% de la fórmula en una sola clase (`CombatStatsService`), lo cual es una buena noticia estructural, pero varios sistemas (Ranking, Activity, Arena, Replay) leen campos puntuales del personaje o de `combat_logs.events_json` que habría que revisar caso por caso. |
| ¿Se puede introducir el nuevo sistema sin romper personajes existentes? | **Sí**, con una estrategia de migración explícita — ver §4. No es automático: `strength`/`agility`/`vitality` no tienen hoy ningún significado "de build" que preservar (son 100% derivadas del nivel), así que cualquier estrategia de conversión es defendible, pero hay que elegir una a propósito. |
| ¿Qué systems ya están preparados para crecer sin romperse? | Equipamiento (bonus genérico por `metadata_json.stat_bonus`, sin `if item === "x"`), Activity Feed (tipos abiertos por string), Ranking (deriva todo de `combat_logs`, no duplica contadores). |
| ¿Qué sistemas quedarían más expuestos a un cambio de stats? | `combat_logs.events_json` (congela `stats` con las 5 claves actuales — cualquier cambio de forma del objeto de stats finales convive con combates viejos que ya tienen la forma vieja), y el "Repetir" de Fase 17 (reproduce el snapshot tal cual quedó guardado, nunca reformatea). |

---

## 1. Combate actual — flujo y fórmulas exactas

### 1.1 Dónde comienza un ataque

- **Frontend:** [`arena.astro`](../web/src/pages/arena.astro) — botón "ATACAR" en cada card de `#opponents-list` → `startLiveBattle(opponent)` → `attackCharacter(opponent.character_id)` (en [`ApiClient.ts`](../web/src/game/net/ApiClient.ts)) → `POST /api/v1/arena/attack`.
- **Backend:** [`routes/api.php`](../backend/routes/api.php) → `ArenaAttackController::attack()` ([`ArenaAttackController.php`](../backend/app/Http/Controllers/Api/ArenaAttackController.php)).
  - El **atacante** SIEMPRE es `$request->user()->character` (nunca un id del payload — CLAUDE.md #1).
  - El **defensor** es el único dato que manda el cliente: `defender_character_id`, validado por [`AttackRequest.php`](../backend/app/Http/Requests/AttackRequest.php) (`required|integer|exists:characters,id`). Cualquier otro campo del payload (`damage`, `winner`, `xp`, …) no está declarado en el FormRequest → se ignora.
  - Delega el 100% de la lógica a `CombatService::attack($attacker, $defender)`.

### 1.2 Qué servicio ejecuta el combate

[`CombatService.php`](../backend/app/Services/CombatService.php) es el ÚNICO lugar donde se decide un combate. Dos responsabilidades separadas dentro del mismo archivo:

1. **`simulate(array $attackerStats, array $defenderStats): array`** — función **pura** (no toca DB, no conoce `Character`), directamente testeable con stats fabricados a mano. Es la que efectivamente "juega" el combate.
2. **`attack(Character $attacker, Character $defender): CombatLog`** — orquesta: valida self-attack/cooldown → pide stats finales a `CombatStatsService::finalStats()` → llama a `simulate()` → dentro de una `DB::transaction()`, aplica recompensas, persiste el `CombatLog`, y loguea actividad (`combat_attacked`/`combat_defended`, Fase 17).

### 1.3 Cómo se calculan los turnos

`simulate()`: hasta `MAX_ROUNDS = 6` rondas. Cada ronda son **2 turnos fijos**: primero ataca `attacker`, después `defender` (si sigue con vida). No existe stat de velocidad/iniciativa — el atacante SIEMPRE actúa primero en cada ronda, sin excepción, sin roll de quién empieza.

```php
for ($round = 0; $round < 6; $round++) {
    $defenderHp = resolveTurn('attacker', ...);
    if ($defenderHp <= 0) break;
    $attackerHp = resolveTurn('defender', ...);
    if ($attackerHp <= 0) break;
}
```

Esto da un máximo de 12 eventos de tipo `attack`/`critical`/`dodge` por combate (6 rondas × 2 turnos), tal como documenta el propio código.

### 1.4 Cómo se calcula el daño (fórmula exacta)

Dentro de `resolveTurn()`, si no hubo esquiva:

```
variance   = random_int(85, 115) / 100        // 0.85 .. 1.15 uniforme
rawDamage  = (atacante.attack * variance) - (objetivo.defense * 0.5)
damage     = max(1, round(rawDamage * (esCritico ? 1.5 : 1)))
```

- El daño mínimo garantizado es **1** (`max(1, ...)`), sin importar cuánta defensa tenga el objetivo.
- La defensa solo resta, nunca reduce por porcentaje (no hay mitigación multiplicativa) — a defensas altas, el daño puede volverse casi lineal en `attack` con un descuento fijo pequeño (`defense * 0.5`).

### 1.5 Cómo se calcula el crítico

```
critRoll    = random_int(0, 10000) / 100      // 0.00 .. 100.00 uniforme
esCritico   = critRoll <= atacante.crit_chance
multiplicador_critico = 1.5x
```

`crit_chance` sale de `CombatStatsService::finalStats()` (ver §1.9). Tope duro `MAX_CRIT_CHANCE = 50.0`.

### 1.6 Cómo se calcula la evasión (esquiva)

Se evalúa **antes** que el crítico, y si esquiva, el turno termina ahí (no hay daño ni roll de crítico):

```
dodgeRoll = random_int(0, 10000) / 100
esquiva   = dodgeRoll <= objetivo.dodge_chance
```

`dodge_chance` también sale de `finalStats()`. Tope duro `MAX_DODGE_CHANCE = 40.0`.

### 1.7 Cómo interviene el nivel — el hallazgo central de esta auditoría

**El nivel no interviene directamente en ninguna fórmula de combate.** No hay ningún `+ $character->level * X` en `CombatStatsService` ni en `CombatService`. Lo que pasa es indirecto pero determinante:

- `CombatStatsService::addExperience()` (se ejecuta como parte de CADA combate, para ambos participantes) sube `strength`, `agility` y `vitality` **en +2 cada una, por cada nivel ganado**, de forma **idéntica para cualquier personaje**:

```php
while ($character->exp >= xpToNextLevel($character->level)) {
    $character->exp -= xpToNextLevel($character->level);
    $character->level++;
    $character->strength += 2;
    $character->agility  += 2;
    $character->vitality += 2;
}
```

- Como TODO personaje nace con `strength = agility = vitality = 1` (default de columna, ver §2.1) y la ÚNICA forma de subirlas es este loop, **dos personajes del mismo nivel tienen, siempre, exactamente `1 + 2*(nivel-1)` en las 3 stats** — no existe ningún camino en el código actual para que diverjan. Confirmado por búsqueda exhaustiva: solo 2 archivos en todo `backend/app/` mencionan `strength`/`agility`/`vitality` (`CombatStatsService.php` y `Character.php`, este último solo en `$fillable`) — ningún crafting, poción, evento ni admin las toca.
- Por lo tanto, en la práctica, **el nivel SÍ determina casi por completo el resultado**, no porque la fórmula de combate lo use directamente, sino porque el nivel es hoy el único predictor real de `attack`/`defense`/`max_hp`/`crit`/`dodge`. La única palanca de variación entre iguales es el equipamiento (§1.10), acotado a 4 items en todo el catálogo actual.
- `xpToNextLevel(level) = level * 100` (lineal, `XP_PER_LEVEL = 100`).

### 1.8 Qué estadísticas intervienen actualmente

**Base (columnas de `characters`, enteros, ilimitados hacia arriba):** `strength`, `agility`, `vitality`.

**Derivadas (calculadas en cada request por `CombatStatsService::finalStats()`, nunca persistidas):**

| Stat final | Fórmula | Tope |
|---|---|---|
| `max_hp` | `60 + vitality * 6` | sin tope superior |
| `attack` | `10 + strength * 4` | sin tope superior |
| `defense` | `3 + agility * 1 + vitality * 0.5` | sin tope superior |
| `crit_chance` | `5.0 + agility * 0.4` | **tope duro 50.0** |
| `dodge_chance` | `5.0 + agility * 0.4` | **tope duro 40.0** |

Nótese que `agility` alimenta **tres** derivadas a la vez (`defense`, `crit_chance`, `dodge_chance`) mientras que `strength` solo alimenta `attack` y `vitality` solo alimenta `max_hp` (+ una fracción de `defense`) — ya hoy hay una asimetría de "cuánto rinde" cada punto según en qué stat se invierta, aunque el jugador no puede elegir dónde invertir.

### 1.9 ¿Existen precisión u otras estadísticas no listadas?

No. Solo existen las 5 derivadas de la tabla anterior. No hay "precisión" separada de crítico, no hay velocidad/iniciativa, no hay resistencias elementales, no hay stats secundarias (ej. "lifesteal", "penetración").

### 1.10 Cómo interviene el equipamiento hoy

`CombatStatsService::finalStats()` recorre `$character->equipment()->with('inventoryItem.item')->get()` y suma genéricamente lo que encuentre en `item.metadata_json.stat_bonus` (claves `hp`/`attack`/`defense`/`crit`/`dodge`) — **no hay ningún `if item->key === "x"`**, así que un item nuevo con `stat_bonus` funciona sin tocar esta clase. Esto es una buena base para "el equipamiento módula builds" (pedido del roadmap).

Estado real del catálogo (`ArenaEquipmentSeeder.php`): **solo 4 items de TODO el juego tienen `stat_bonus` hoy**:

| Item | Slot (`EquipmentSlot`) | Bonus |
|---|---|---|
| `simple_sword` | weapon | `attack +5` |
| `adventurer_axe` | weapon | `attack +6` |
| `crimson_sword` | weapon | `attack +12, crit +3` |
| `reinforced_wooden_shield` | shield | `defense +6, hp +15` |

Hay 7 slots posibles (`shirt`, `pants`, `shoes`, `hair`, `weapon`, `accessory`, `shield`) pero solo `weapon` y `shield` tienen algún item con bonus real — el resto son 100% cosméticos hoy. Un personaje solo puede tener **1 item por slot** (`CharacterEquipment::updateOrCreate(['character_id', 'slot'], ...)` — clave única lógica).

### 1.11 Cómo interviene el RNG

Tres únicos puntos de aleatoriedad, todos con `random_int()` de PHP (CSPRNG del sistema, **sin seed manual, sin generador propio**):

1. Esquiva del objetivo (`random_int(0, 10000) / 100` vs `dodge_chance`).
2. Crítico del atacante (`random_int(0, 10000) / 100` vs `crit_chance`).
3. Varianza de daño (`random_int(85, 115) / 100`).

**Nota importante sobre el campo `seed`:** `combat_logs.seed` se genera con `random_int(100000, PHP_INT_MAX)` pero **nunca se usa para volver a sembrar nada** — es puramente informativo/de auditoría (el comentario del propio código lo aclara: "no hace falta poder re-tirar los mismos dados... el resultado completo ya queda persistido verbatim"). La reproducibilidad del combate viene de **persistir el resultado completo**, no de poder re-ejecutar la simulación con el mismo seed. Esto es relevante si en el futuro alguien asume que `seed` sirve para "reproducir" — no es así, y conviene documentarlo o renombrarlo en una fase de limpieza (no se toca en esta auditoría).

### 1.12 Cómo se determina el ganador

Dentro de `simulate()`: apenas el HP de alguno llega a 0 se corta el loop (`break`). Ganador = quien sigue con HP > 0. Si se agotan las 6 rondas sin KO (o ambos llegan a 0 en el mismo intercambio, caso límite), gana quien conserve **mayor porcentaje de vida** (`hp_actual / max_hp`); un empate exacto de porcentaje favorece arbitrariamente al atacante (decisión documentada en el propio código, sección "empate exacto favorece al atacante").

### 1.13 Cómo se generan los eventos de `events_json`

Cada `resolveTurn()` hace `push` de un evento con esta forma exacta:

```json
{ "type": "attack" | "critical" | "dodge", "actor": "attacker" | "defender", "target": "attacker" | "defender" | null, "damage": int, "critical": bool, "target_hp_after": int }
```

El objeto completo persistido en `combat_logs.events_json` (ver `CombatService::attack()`) tiene esta forma (congelada, `version: 1`):

```json
{
  "version": 1,
  "attacker": { "character_id", "name", "level", "stats": {max_hp, attack, defense, crit_chance, dodge_chance} },
  "defender": { "character_id", "name", "level", "stats": {...} },
  "events": [ ... ],
  "winner": "attacker" | "defender",
  "attacker_hp_remaining": int,
  "defender_hp_remaining": int,
  "rewards": { "attacker": {xp, coins, leveled_up, new_level}, "defender": {...} }
}
```

**Este `stats` snapshot es la forma exacta que hoy exponen las 5 derivadas (§1.8).** Cualquier cambio futuro a esa forma (agregar una 6ª stat, renombrar una clave) convive para siempre con combates viejos que tienen la forma vieja — el "Repetir" de Fase 17 lee este JSON tal cual, sin migrarlo.

### 1.14 Qué parte simula y qué parte solo reproduce

- **Simula (única fuente de verdad, cliente NUNCA participa):** `CombatService::simulate()` + `resolveTurn()`, en el backend, dentro de `attack()`. Todo el resultado (daño, crítico, esquiva, HP, ganador, recompensas) se decide una sola vez, server-side, en el POST.
- **Reproduce (nunca recalcula):** [`BattleScene.ts`](../web/src/game/scenes/BattleScene.ts) — `playNext()` recorre `events_json.events` y solo anima/renderiza lo que ya fue decidido (`this.time.delayedCall(MS_PER_EVENT, ...)`, `MS_PER_EVENT = 1400ms`). El botón "Repetir" de Fase 17 (`arena.astro`, `mountBattle()`) reutiliza exactamente esta misma escena con un `CombatLog` ya existente, sin volver a llamar `CombatService::attack()`.

---

## 2. Estadísticas y progresión — dónde viven

### 2.1 Tabla / columnas (`characters`)

Migraciones: [`2026_09_13_000001_create_characters_table.php`](../backend/database/migrations/2026_09_13_000001_create_characters_table.php) + [`2026_09_16_000001_add_coins_to_characters_table.php`](../backend/database/migrations/2026_09_16_000001_add_coins_to_characters_table.php).

| Columna | Tipo | Default | Comentario |
|---|---|---|---|
| `level` | `unsignedInteger` | `1` | sin tope superior |
| `exp` | `unsignedInteger` | `0` | se resetea al cruzar de nivel (resta el costo, no acumula) |
| `strength` | `unsignedInteger` | `1` | sin tope superior |
| `agility` | `unsignedInteger` | `1` | sin tope superior |
| `vitality` | `unsignedInteger` | `1` | sin tope superior |
| `coins` | `unsignedInteger` | `0` | añadida en F8, no relacionada a combate |
| `appearance_json` | `json` | `{"body":"base"}` | visual, NO es una fuente de stats |

Todas son **columnas normales, no JSON** — decisión explícita documentada en el propio código ("CLAUDE.md principio #3: se necesitan para rankings, matchmaking y balance"), lo cual es una buena base para lo que se pide (queries/soft caps/agregados son triviales con columnas).

### 2.2 Modelo / Servicio / Controlador / Frontend por cada dato

| Dato | Modelo | Se calcula/escribe en | Se expone vía | Frontend que lo consume |
|---|---|---|---|---|
| `level`, `exp` | [`Character.php`](../backend/app/Models/Character.php) (`$fillable`) | `CombatStatsService::addExperience()` | `GET /character` (`CharacterController`), `GET /arena` (`ArenaController`) | HUD ([`Hud.astro`](../web/src/components/hud/Hud.astro), barra de XP), `home.astro`, `arena.astro` ("Mi personaje") |
| `strength`/`agility`/`vitality` | `Character.php` (`$fillable`) | `CombatStatsService::addExperience()` (level-up) | `GET /character` (`CharacterDto` los incluye) | **Ninguno.** Búsqueda exhaustiva (`grep -r "\.strength\|\.agility\|\.vitality" web/src`) → 0 resultados. Estas 3 columnas viajan al cliente pero **ningún componente las lee ni las muestra hoy**. |
| `max_hp`/`attack`/`defense`/`crit_chance`/`dodge_chance` (derivadas) | *no persisten* | `CombatStatsService::finalStats()` | `GET /arena` (`character.stats`, `opponents[].stats`), `combat_logs.events_json.attacker/defender.stats` | `arena.astro` ("Mi personaje": HP/ATK/DEF/Crit/Esquiva; cards de "Oponentes": HP/ATK/DEF) |
| `coins` | `Character.php` | `EconomyService`, `CombatService::attack()` | `GET /character`, `GET /arena` | HUD, `home.astro`, `arena.astro`, `shop.astro` |
| `wins`/`losses` (NO son columna) | *derivado* | `ArenaRankingService::statsFor()` (agrega `combat_logs`) | `GET /arena`, `GET /ranking` | `arena.astro` (panel Ranking + "Mi personaje"), `ranking.astro` |

### 2.3 Qué pasa exactamente al subir de nivel (hoy)

Único lugar: `CombatStatsService::addExperience()`, invocado desde `CombatService::attack()` para **ambos** participantes en cada combate. Verificado que ningún otro flujo toca `Character.exp`/`Character.level`: `PetExpeditionService` (expediciones AFK) otorga XP a la **mascota** y recompensas de items/coins al personaje, pero nunca llama `addExperience()` ni escribe `Character.exp` directamente — confirmado por búsqueda en el archivo. Crafting, Shop y Room tampoco lo tocan.

1. `exp += xp_ganada`.
2. Mientras `exp >= xpToNextLevel(level)` (`= level * 100`): resta el costo, `level++`, y las 3 stats base suben **+2 cada una, sin excepción, sin elección del jugador**.
3. Un combate puede, en teoría, subir más de un nivel de una sola vez (el `while`, no un `if`).
4. Si hubo al menos un level-up, dispara `ActivityLogger::log($character, 'level_up', ...)` — para CUALQUIER personaje que suba (atacante o defensor), a diferencia de `combat_attacked`/`combat_defended` que si distinguen rol (Fase 17).

**No existe ningún "árbol de puntos", ningún endpoint de asignación, ninguna UI de distribución de atributos.** El crecimiento es 100% automático y 100% uniforme.

---

## 3. Dependencias — qué tan invasivo sería cambiar el sistema de stats

### 3.1 Backend — mapa de dependencias directas

```
characters.{strength,agility,vitality}
        │
        ▼
CombatStatsService::finalStats()  ←── ÚNICO punto de lectura de las 3 columnas base
        │
        ├──→ CombatService::attack()          (simula el combate)
        │         │
        │         ├──→ combat_logs.events_json.{attacker,defender}.stats   (snapshot congelado)
        │         └──→ ActivityLogger::log('combat_attacked'/'combat_defended')
        │
        ├──→ ArenaController::index()          (GET /arena: character.stats, opponents[].stats)
        │
        └──→ ArenaController::show()/combats() (Fase 17: NO recalcula, solo lee events_json ya guardado)

characters.{level, exp}
        │
        ├──→ CombatStatsService::xpToNextLevel()/addExperience()
        ├──→ ArenaRankingService::topRanking()/rankOf()   (desempate por nivel, wins es lo primario)
        ├──→ CharacterController::show()                  (expone exp_to_next_level)
        └──→ ArenaController::index()                     (character.level, opponents[].level)

combat_logs (wins/losses derivados, nunca columnas)
        │
        └──→ ArenaRankingService::statsFor()/topRanking()/rankOf()
                  │
                  ├──→ ArenaController::index()  (character.wins/losses, opponents[].wins/losses)
                  └──→ RankingController::index() (GET /ranking)
```

**Nada fuera de Arena/Combat/Ranking toca `strength`/`agility`/`vitality`/`finalStats()`.** Inventario, Crafting, Mascota, Shop, Room, Chat: cero acoplamiento con stats de combate (confirmado por grep). Esto acota mucho el "blast radius" de un cambio de fórmula: es prácticamente un sistema aislado.

### 3.2 Frontend — mapa de dependencias directas

| Archivo | Qué consume | Impacto si cambia la FORMA de las stats derivadas |
|---|---|---|
| [`arena.astro`](../web/src/pages/arena.astro) | `ArenaStateDto.character.stats` (5 claves fijas), `opponents[].stats` (`max_hp`/`attack`/`defense` solamente, sin crit/dodge en las cards) | **Alto** — hoy asume exactamente esas 5 claves con esos nombres (`statsLine()`), hardcodeadas en el HTML/JS. |
| [`ApiClient.ts`](../web/src/game/net/ApiClient.ts) | `CombatStatsDto` (interfaz TS con las 5 claves) | Cambiar la forma implica tocar esta interfaz y todo lo que la referencia (`ArenaCharacterDto`, `ArenaOpponentDto`, `CombatFighterSnapshotDto`). |
| [`BattleScene.ts`](../web/src/game/scenes/BattleScene.ts) | Solo `maxHp` (de `BattleFighterData`) y los eventos ya resueltos (`damage`, `critical`, `target_hp_after`) — **NO lee `attack`/`defense`/`crit_chance`/`dodge_chance` directamente**, esos ya vienen "cocinados" en los eventos. | **Bajo** — Phaser no conoce la fórmula, solo anima HP y números de daño ya calculados. Cambiar la fórmula de combate no debería tocar este archivo (consistente con el pedido explícito de no modificarlo). |
| `CharacterDto` (`ApiClient.ts`) | Expone `strength`/`agility`/`vitality` crudos, pero **ningún componente los renderiza** | **Nulo hoy** — es deuda "silenciosa": si se elimina/renombra la columna, hay que actualizar el tipo TS aunque nada lo use visualmente, para no romper el build. |
| Historial de Combates (Fase 17) | Lee `events_json.rewards`/`winner`/`events` — **no lee el objeto `stats` para nada visual**, solo XP/coins/resultado | **Nulo** — el panel de Historial es agnóstico a la forma de las stats. |

### 3.3 Conclusión de invasividad

- **Frontend:** cambiar de qué compone `attack`/`defense`/etc. (ej. agregar arquetipos) es **transparente** para el frontend mientras el objeto final siga teniendo las mismas 5 claves con los mismos nombres y tipos. Cambiar el **número o nombre de las claves** (ej. agregar `speed`, o `crit_chance` → `critical_chance`) sí requiere tocar `arena.astro` + `ApiClient.ts` (bajo-medio esfuerzo, ~2 archivos, sin Phaser).
- **Backend:** la fórmula está concentrada en una sola clase (`CombatStatsService`), lo cual es la mejor noticia posible para refactorizar — pero esa clase mezcla hoy "cálculo puro" con "lectura de equipamiento" y "persistencia de level-up + logging". Antes de agregar arquetipos/puntos de atributo conviene separar esas 3 responsabilidades (ver §5).
- **`combat_logs` histórico:** es el punto más delicado. Cualquier cambio de forma del objeto `stats` (nuevas claves, tipos distintos) **no debe romper la lectura de combates viejos** (Ranking los usa para wins/losses agregados —no lee `stats`—, pero el "Repetir" de Fase 17 sí muestra ese snapshot). Config recomendada: versionar (`events_json.version`, ya existe) y que el frontend/replay sepa tolerar snapshots viejos sin la clave nueva.

---

## 4. Personajes existentes — alternativas de migración (sin implementar)

Punto de partida importante: como se demostró en §1.7, **hoy `strength = agility = vitality = 1 + 2*(nivel-1)` para el 100% de los personajes existentes, sin excepción**. No hay "builds reales" que preservar — cualquier personaje del mismo nivel es, stat por stat, un clon de cualquier otro (más equipamiento). Esto simplifica mucho la decisión: no hay que "adivinar" qué build tenía cada jugador, porque todos tienen la misma.

### Opción A — Congelar las columnas actuales como "puntos ya gastados" (recomendada)
Tratar `strength`/`agility`/`vitality` actuales como el resultado de puntos YA invertidos automáticamente, y a partir de la migración, futuros level-ups empiezan a repartirse manualmente (o con un preset por defecto configurable).
- **Pros:** cero pérdida de poder para nadie, cero recálculo retroactivo, mínimo riesgo. Un personaje nivel 10 sigue teniendo exactamente las mismas stats que tenía ayer.
- **Contras:** no resuelve la falta de diversidad RETROACTIVA — dos personajes viejos del mismo nivel siguen siendo idénticos hasta que vuelvan a subir de nivel bajo el sistema nuevo. Es una migración "hacia adelante", no repara el pasado.

### Opción B — Recalcular desde cero con la fórmula nueva
Aplicar la fórmula nueva (ej. base + arquetipo + puntos por defecto) a partir del nivel actual, ignorando las stats actuales.
- **Pros:** consistencia total — todos los personajes, viejos y nuevos, quedan bajo las mismas reglas desde el día uno.
- **Contras:** requiere decidir un arquetipo/tendencia para personajes que nunca eligieron uno (asignar uno por defecto, o el más "neutro"). Puede subir o bajar el poder de personajes existentes de forma perceptible — mal recibido si un jugador siente que "le sacaron poder".

### Opción C — Otorgar puntos de atributo retroactivos "para redistribuir"
Convertir las stats actuales en un pool de puntos acumulados (ej. `(strength - 1) + (agility - 1) + (vitality - 1)` puntos totales) y dejar que cada jugador los redistribuya una vez, con una pantalla de "reasignación" post-migración.
- **Pros:** se siente como un regalo, no como una pérdida; le da agencia real al jugador exactamente en el momento de introducir el sistema nuevo.
- **Contras:** más trabajo de producto (pantalla de reasignación única), y hay que decidir qué pasa con nuevos personajes creados DESPUÉS de la migración (¿empiezan con 0 puntos para repartir, o con un preset base?).

### Opción D — Introducir columnas nuevas con default neutro, dejar las viejas intactas
Agregar `archetype`, `attribute_points_available`, etc. como columnas nuevas con default (`null`/`0`), sin tocar `strength`/`agility`/`vitality` existentes.
- **Pros:** migración más simple técnicamente (solo `ALTER TABLE ADD COLUMN`, sin `UPDATE` masivo).
- **Contras:** dos generaciones de personajes conviven (los que tienen arquetipo elegido y los que no) hasta que cada uno pase por un flujo de "elegí tu arquetipo" — hay que diseñar ese flujo igual, no se puede posponer indefinidamente si el arquetipo afecta la fórmula de combate.

**Recomendación de esta auditoría:** A como base técnica (no perder poder de nadie) + C como capa de producto (una pantalla de "reasignación" única impulsada por un evento de migración), evitando B por el riesgo de percepción de "nerfeo" sin aviso.

---

## 5. Diseño técnico recomendado (arquitectura, NO implementación)

Objetivo: que la fórmula de stats no termine repartida por controllers/páginas, y que cada capa del pipeline pedido sea un punto de extensión aislado y testeable por separado, igual que ya se logró con el equipamiento (`stat_bonus` genérico, sin `if`).

```
Base Stats (columna, invariante por personaje)
        ↓
Archetype/Class modifiers   (multiplicador o flat por stat, según arquetipo elegido)
        ↓
Level progression           (crecimiento automático, MENOR que hoy — parte automática)
        ↓
Allocated attribute points  (parte manual — el jugador elige dónde invertir)
        ↓
Equipment modifiers         (igual que hoy: bolsa genérica sumada al final)
        ↓
Final combat stats          (max_hp/attack/defense/crit_chance/dodge_chance — LA MISMA FORMA que hoy)
```

### Propuesta de responsabilidades (nombres tentativos, a validar en Fase A/B)

- **`CombatStatsService` se divide en 2+ colaboradores**, no una clase monolítica:
  - Un **resolver de stats por capas** (ej. `CharacterStatsResolver`), que aplica el pipeline de arriba en orden y devuelve el mismo array final de 5 claves que hoy (`max_hp`/`attack`/`defense`/`crit_chance`/`dodge_chance`) — **cero cambio de forma hacia afuera**, así ni el frontend ni `combat_logs` necesitan saber que por dentro cambió.
  - Un **servicio de progresión** (ej. `CharacterProgressionService`), responsable solo de XP/level-up/puntos disponibles — sin conocer fórmulas de combate.
  - `CombatService` sigue siendo el único que simula combates, pero PIDE el array final ya resuelto al resolver de stats — no cambia su contrato interno.
- **Arquetipos como datos, no como código:** una tabla (futura, NO se crea acá) tipo `archetypes` (`key`, `name`, modificadores por stat como JSON o columnas) en vez de un `switch(archetype)` en PHP — mismo criterio ya usado para `stat_bonus` de equipamiento (bolsa genérica sumada, no `if`).
- **Puntos de atributo como estado explícito del personaje:** una columna `attribute_points_available` (o similar) + un endpoint dedicado de asignación (`POST /character/allocate-points` o similar) que sea el ÚNICO lugar donde `strength`/`agility`/`vitality` cambian por elección del jugador — separado de `addExperience()`, que seguiría siendo el único lugar donde cambian automáticamente (en menor medida que hoy).
- **Soft caps:** implementarlos en el resolver de stats final (ej. rendimientos decrecientes por encima de X puntos en una stat), NUNCA truncando la columna en DB — así un soft cap se puede ajustar/eliminar sin tocar datos ya guardados, mismo criterio que hoy usan `MAX_CRIT_CHANCE`/`MAX_DODGE_CHANCE` (se aplican al calcular, no se guardan truncados).
- **`events_json.stats` versionado:** cuando la forma del snapshot cambie (ej. una 6ª stat), subir `events_json.version` y que cualquier lector (Replay, futuro Combat Power histórico) sepa interpretar versiones viejas sin la clave nueva, en vez de asumir que siempre existe.

---

## 6. Combat Power — propuesta inicial (NO implementar)

Estadísticas disponibles hoy para construirlo: las 5 derivadas ya calculadas por `finalStats()` (`max_hp`, `attack`, `defense`, `crit_chance`, `dodge_chance`), más `level` como señal indirecta.

### Fórmula inicial propuesta (punto de partida, a validar con datos reales)

```
combat_power = (attack * 1.0)
             + (defense * 1.0)
             + (max_hp * 0.15)
             + (crit_chance * 2.0)
             + (dodge_chance * 2.0)
```

Los pesos son arbitrarios a propósito — la idea es normalizar approximadamente el "aporte" de cada stat a la probabilidad de ganar, ya que `max_hp` crece en unidades mucho más grandes (60+) que `crit_chance`/`dodge_chance` (0–50).

### Problemas conocidos de este enfoque (a documentar, no resolver ahora)

1. **Los pesos son una suposición, no están calibrados contra resultados reales de combate.** Sin datos de win-rate por rango de `combat_power`, cualquier constante es un placeholder.
2. **No captura sinergias ni "counters".** Un personaje con `crit_chance` alto y HP bajo puede tener el mismo `combat_power` que uno balanceado, pero rendir muy distinto contra oponentes con `dodge_chance` alto — la fórmula es lineal, el combate real no lo es (crítico y esquiva interactúan de forma no lineal en `resolveTurn()`).
3. **No incluye equipamiento con efectos NO numéricos** (si en el futuro un item da, por ejemplo, "ignora 10% de defensa" en vez de un flat bonus, `combat_power` no lo vería).
4. **Cuando exista el pipeline de §5, hay que decidir en qué punto se calcula** (¿sobre stats base, o sobre stats finales post-equipo? — para matchmaking probablemente conviene post-equipo, ya que es lo que efectivamente pelea).
5. **Riesgo de uso indebido:** si se expone `combat_power` al frontend antes de tener buen matchmaking, puede fomentar "sniping" de oponentes débiles en vez de resolver el problema que se busca evitar.

**Recomendación:** tratar `combat_power` como un valor **interno** primero (solo para ordenar/filtrar oponentes en el backend), y no exponerlo como stat pública hasta validar que correlaciona razonablemente con win-rate real (requiere datos de varias fases jugadas).

---

## 7. Arena Energy — estado actual del límite de ataques

**Hoy NO existe un sistema de energía/cargas.** Lo que existe es un **cooldown por par ordenado (atacante, defensor)**, sin límite global de combates.

### 7.1 Dónde se guarda

**En ningún lado aparte.** No hay tabla ni columna de cooldown. Se **deriva** de `combat_logs` en tiempo real:

```php
// CombatService::cooldownRemainingSeconds()
$last = CombatLog::where('attacker_character_id', $attacker->id)
    ->where('defender_character_id', $defender->id)
    ->orderByDesc('created_at')
    ->first();

$unlocksAt  = $last->created_at->addMinutes(30);   // COOLDOWN_MINUTES = 30
$remaining  = now()->diffInSeconds($unlocksAt, false);
```

### 7.2 Cómo se valida

- **Al listar oponentes:** `ArenaController::index()` calcula `cooldown_seconds` para cada oponente y lo manda al frontend (para pintar el botón "Cooldown mm:ss" deshabilitado).
- **Al atacar (autoridad real):** `CombatService::attack()` vuelve a llamar `cooldownRemainingSeconds()` y **rechaza con 422** (`ValidationException`) si es `> 0` — esta es la validación que realmente importa, ocurre server-side dentro del mismo método que ejecuta el combate.

### 7.3 Cómo se evita que el frontend lo manipule

El frontend (`arena.astro`) solo usa el `cooldown_seconds` recibido para **UX** (deshabilitar el botón, mostrar cuenta regresiva local con `setInterval`) — nunca decide si el ataque procede. Aunque alguien fuerce el click (`{force:true}` o DevTools), `POST /arena/attack` re-valida contra `combat_logs` real en el servidor y devuelve 422. **No hay ningún dato de cooldown que el cliente pueda mandar ni que el servidor confíe** — el único input del cliente es `defender_character_id`.

### 7.4 Qué tablas/columnas intervienen

Ninguna dedicada — solo `combat_logs.attacker_character_id`, `combat_logs.defender_character_id` y `combat_logs.created_at` (ya existentes, usados también para Ranking e Historial).

### 7.5 Qué habría que cambiar para pasar a "cargas regenerables" (ej. 5 combates que se regeneran con el tiempo)

Esto es un cambio de modelo, no un ajuste de constante — el cooldown actual es **por objetivo** (puedo atacar a B aunque esté en cooldown contra A), mientras que "Arena Energy" sería **global por jugador** (un pool compartido entre todos los objetivos). Piezas nuevas necesarias (a diseñar en Fase F, no ahora):

- Un estado persistente por personaje: cargas actuales + timestamp de la última regeneración (ej. 2 columnas nuevas en `characters`, o una tabla aparte si se quiere desacoplar de la tabla principal).
- Una función de regeneración **derivada en el momento de consultar/consumir** (mismo espíritu que el cooldown actual: nunca un scheduler/cron — CLAUDE.md #2, "resolución perezosa") — ej. `cargas_actuales = min(max, cargas_guardadas + floor(segundos_transcurridos / segundos_por_carga))`, recalculado al leer, sin jobs en segundo plano.
- `CombatService::attack()` pasaría a validar Y CONSUMIR una carga (además de, o en vez de, el cooldown por objetivo — hay que decidir si conviven ambos o el cooldown por objetivo desaparece).
- El cooldown por-objetivo actual **puede convivir** con Arena Energy (ej. "no podés volver a pegarle al mismo rival en 30 min" + "solo tenés 5 combates totales") o ser reemplazado — es una decisión de diseño de Fase F, no técnica.
- Frontend: la Arena necesitaría mostrar "cargas restantes" + "próxima carga en mm:ss" en vez de (o además de) el cooldown por card de oponente — cambio de UI, no de Phaser.

---

## 8. Roadmap técnico recomendado para la reestructuración

```
FASE A — Auditoría/diseño            [ESTE DOCUMENTO — ya completa]
        │
        ▼
FASE B — Sistema de estadísticas base
        (separar CombatStatsService en resolver de stats + progresión;
         MISMO output final de 5 claves; sin arquetipos ni puntos todavía;
         reducir STAT_GAIN_PER_LEVEL; introducir soft caps en el resolver)
        │
        ├──────────────┐
        ▼              ▼
FASE C — Arquetipos   FASE D — Progresión y puntos de atributo
   (tabla de datos,      (columna de puntos disponibles + endpoint de
    modificadores         asignación; requiere que B ya exista para tener
    por stat, sin         dónde "engancharse")
    UI de combate
    nueva todavía)
        │              │
        └──────┬───────┘
               ▼
FASE E — Nuevo balance/fórmulas
        (ajustar constantes de daño/crít/esquiva con arquetipos + puntos
         ya en juego; requiere datos de B/C/D jugándose para calibrar)
               │
   ┌───────────┼───────────────┐
   ▼           ▼               ▼
FASE F      FASE G          FASE H
Arena       Matchmaking     Equipamiento
Energy      (usa Combat     (diversificar bonus más allá de
(indepen-    Power §6,      weapon/shield; requiere B/C/D/E
diente de    requiere E     para que "modificar la build" tenga
B-E, se      calibrado)     sentido real)
puede
adelantar
o atrasar)
               │
               ▼
        FASE I — Tipos de armas/animaciones
        (depende de H — necesita que el equipamiento ya
         module stats/comportamiento antes de diferenciar
         armas visualmente/mecánicamente)
```

### Notas de dependencia

- **B es el bloqueante real de C y D.** No tiene sentido diseñar arquetipos o puntos de atributo sobre la clase monolítica actual — primero separar responsabilidades (§5) sin cambiar el resultado final, para poder iterar C/D en paralelo sin pisarse.
- **C y D no dependen entre sí** y pueden ir en paralelo o en cualquier orden después de B.
- **E depende de C+D** porque recién ahí existen builds distintas para calibrar contra (hoy no se puede "balancear" nada porque no hay variación que balancear).
- **F (Arena Energy) es la más independiente de todas** — no depende de las stats en absoluto, es un sistema de recursos aparte. Se puede adelantar antes de B si el equipo prefiere resolver primero "cuántos combates por día" y dejar el rebalanceo de stats para después.
- **G (Matchmaking/Combat Power) depende de E** — no tiene sentido ordenar rivales por un `combat_power` calculado sobre una fórmula que todavía va a cambiar.
- **H (Equipamiento) depende de B/C/D/E** — hoy el equipamiento YA es genérico (§1.10), pero "diversificar builds vía equipo" solo tiene impacto real si ya existen builds diferenciables por arquetipo/puntos; si no, el equipo seguiría siendo la única fuente de diferencia (como hoy).
- **I depende de H** — variar animaciones/tipos de arma requiere que el arma ya tenga una identidad mecánica (no sería solo un `attack +N` genérico).

### Riesgos generales a vigilar en toda la reestructuración

1. **Compatibilidad con `combat_logs` históricos** (ver §3.3) — versionar `events_json` desde el día 1 de la Fase B.
2. **Percepción de balance** al migrar personajes existentes (ver §4) — comunicar la migración, no solo ejecutarla.
3. **Ranking/wins-losses no deben romperse** — `ArenaRankingService` deriva todo de `combat_logs`, agnóstico a la fórmula de stats; mientras `winner_character_id` se siga escribiendo igual, Ranking sobrevive intacto a cualquier cambio de B-E.
4. **Replay de Fase 17 es de solo lectura del pasado** — cualquier fase nueva debe evitar la tentación de "recalcular" un combate viejo con la fórmula nueva; el contrato de Fase 17 (nunca re-ejecutar `CombatService::attack()`) debe mantenerse.
5. **No mezclar Arena Energy con cooldown por objetivo sin decidir explícitamente si conviven** (§7.5) — ambigüedad de diseño, no técnica, pero puede generar confusión de UX si no se resuelve antes de F.

---

## Archivos identificados como relevantes para las fases B–I (referencia rápida)

**Backend:**
- `backend/app/Services/CombatStatsService.php` — a dividir (§5).
- `backend/app/Services/CombatService.php` — consumidor del resolver de stats, no debería necesitar tocar su lógica de turnos/daño/crítico/esquiva en B/C/D (solo en E).
- `backend/app/Models/Character.php` — nuevas columnas futuras (`archetype`, puntos disponibles) se agregan acá.
- `backend/database/migrations/2026_09_13_000001_create_characters_table.php` — referencia del esquema actual (no se toca, las columnas nuevas van en migraciones nuevas).
- `backend/app/Http/Controllers/Api/ArenaController.php`, `ArenaAttackController.php`, `RankingController.php` — consumidores de `finalStats()`/`statsFor()`, revisar en B por si acceden a stats base directamente (hoy no lo hacen, ver §3.1).
- `backend/database/seeders/ArenaEquipmentSeeder.php` — punto de partida natural para H (agregar más items con `stat_bonus`, o evolucionar la forma del bonus).
- `backend/app/Enums/EquipmentSlot.php` — candidato a revisar en H/I si se agregan slots nuevos.
- Futuras migraciones (NO creadas en esta auditoría): tabla `archetypes` (Fase C), columnas de puntos de atributo en `characters` (Fase D), columnas/tabla de Arena Energy (Fase F).

**Frontend:**
- `web/src/pages/arena.astro` — único lugar que renderiza stats derivadas; a revisar en E si cambia la forma del objeto `stats`, y en F/G para UI de energía/emparejamiento.
- `web/src/game/net/ApiClient.ts` — `CombatStatsDto`, `CharacterDto` (contiene `strength`/`agility`/`vitality` sin uso hoy), `ArenaStateDto`.
- `web/src/game/scenes/BattleScene.ts` — **no debería necesitar cambios** en B–H (solo anima eventos ya resueltos); I sí podría tocarlo si se agregan animaciones por tipo de arma (fuera del alcance de este documento).
- `web/src/pages/home.astro` — `describeActivity()` tendría un caso nuevo si se loguea algo de progresión distinto (ej. "asignaste puntos de atributo").

**No afectados (confirmado por auditoría, útil para acotar el miedo a romper cosas):** Inventario, Crafting, Mascota/Expediciones, Shop, Room, Chat, Toast — cero acoplamiento actual con `CombatStatsService`/stats de combate.

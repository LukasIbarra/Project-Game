# Sistema de Mascotas + Expediciones — Fuente de verdad

> Documento de diseño, no de implementación. Nada de lo descrito acá está
> construido todavía salvo lo explícitamente marcado como "Estado actual".
> Cuando se diga "Implementa F20 siguiendo este documento", este es el
> contrato a seguir.

## 0. Estado de implementación (actualizado tras F23/Adopción — sección viva, no de diseño)

- **F20 (base de especies/alimentación) — ✅ implementado.** `pet_species`,
  migración de `pets.key`→`pets.species_id`, `pet_food_items`,
  `PetModifierResolver`. Ver ROADMAP.md Fase 20, Parte 1.
- **F21 (motor temporal + checkpoints + loot normalizado) — ✅
  implementado.** `expedition_definitions` (6 expediciones reales),
  `expedition_rewards`, `expedition_event_definitions`,
  `pet_expedition_checkpoints`, `ExpeditionService` con
  `start()`/`resolveDueCheckpoints()`/`decide()`/`claim()`, fix del bug de
  doble-claim de §3.4. Checkpoints eran 100% `kind=narrative` al cerrar
  esta fase. Ver ROADMAP.md Fase 20, Parte 2.
- **F22 (eventos interactivos: chest/enemy/help) — ✅ implementado.**
  Checkpoints `kind=event` con consecuencia mecánica real, `requires_decision`
  gobierna pausa en `awaiting_decision` (vive en `config_json`, no se infiere
  del `type`), resolvers por tipo (nunca por destino), loot de evento
  reutiliza `expedition_rewards` con procedencia preservada
  (`expedition_loot` vs `event_loot` en `result_data_json`, `checkpoint_id`
  por entrada). Ver ROADMAP.md Fase 21 para el detalle completo del cierre.
  **Ajuste posterior, ya parte del comportamiento definitivo (§8/§11):**
  `checkpoint_event_chance_pct` por sí solo permitía completar una
  expedición corta sin ningún checkpoint mecánico (~49% de chance en el
  piso de 3 checkpoints). `config('expeditions.min_event_checkpoints')`
  (default `1`) agrega una GARANTÍA separada de la PROBABILIDAD: después de
  rifar el % como siempre, si faltan checkpoints `event` para llegar al
  mínimo, `planCheckpoints()` sube de `narrative` a `event` los que falten
  entre los elegibles (`sequence>0`) -determinista, sin tirar dados de
  nuevo, sin tocar el primer checkpoint, solo si hay contenido mecánico
  disponible-. Sigue sin elegir el `EventDefinition` concreto -eso sigue
  siendo trabajo exclusivo de `resolveDueCheckpoints()`, intacto-. Ver
  ROADMAP.md Fase 21 ("Ajuste posterior") para el detalle completo.
- **§3.9 (más abajo) queda desactualizado**: en su momento no se encontró
  `CLAUDE.md` en el repo; existe y está en `project/CLAUDE.md` (confirmado
  en la auditoría de F21) — se deja la sección original sin reescribir para
  no perder el rastro del hallazgo, pero la afirmación de que no existe es
  falsa.
- **F23 (Adopción, Colección y Mascota Activa) — ✅ implementado — colisión
  de numeración con §18, documentada explícitamente en vez de renombrada en
  silencio:** el roadmap original de este documento (§17/§18) reservaba la
  etiqueta "F23" para el BALANCE (fórmula de §10) y ponía "colección/
  adquisición" recién en **F25**. La fase que efectivamente se implementó a
  continuación de F22 -y que tanto esta sesión como `ROADMAP.md` (Fase 22)
  llaman **"F23"**- es en realidad el contenido de colección de esa F25
  original (adoptar/coleccionar varias mascotas, una activa a la vez),
  NO la fórmula de balance de §10 -que sigue sin implementarse, ver más
  abajo-. Se prefirió no renombrar el trabajo ya hecho ni reescribir §18
  para no perder el rastro de la planificación original; quien lea "F23" de
  acá en adelante en código/tests/ROADMAP.md debe entenderlo como esta
  fase de adopción/colección, no como la de balance.
  **Cambios reales al modelo de datos** (ver §6.1, que queda desactualizado
  en su forma original — no reescrita, nota aparte): `Character 1—1 Pet` ya
  NO es cierto — pasa a `Character 1—N Pet` (colección) +
  `characters.active_pet_id` (FK nullable a `pets.id`) como única fuente de
  verdad de cuál está activa. `pets.character_id` deja de ser UNIQUE simple
  y pasa a UNIQUE compuesto `(character_id, species_id)` -máximo 1 pet por
  especie por usuario, sin duplicados todavía (eso sigue siendo F25/gacha
  real)-. La especie legacy `pet_species.key='starter'` (la única que
  existía antes de esta fase, auto-creada en el registro) queda retirada
  -`is_active=false`, `is_starter_option=false`, sus `pets` con
  `retired_at` seteado- pero NUNCA borrada, para no romper
  `pet_expeditions` históricas.
  **Decisión formalizada sobre expediciones (afecta §7/§8):** una
  `PetExpedition` queda ligada para siempre a la `Pet` concreta que la
  inició (`pet_expeditions.pet_id`, columna histórica) — cambiar cuál
  mascota está ACTIVA (`characters.active_pet_id`) nunca reasigna
  expediciones existentes ni en curso; una expedición activa iniciada por
  una mascota sigue resolviéndose/reclamándose con normalidad aunque el
  jugador cambie su mascota activa mientras tanto. El sistema de
  expediciones sigue operando sobre "la mascota activa del personaje" para
  decidir CON QUIÉN iniciar una nueva expedición (`PetProvisioningService::activePetFor()`),
  nunca sobre "la única mascota del personaje" como asumía el diseño
  original de §7/§8.
  Ver `ROADMAP.md` Fase 22 para el detalle completo del cierre (migraciones,
  servicios, API, seeds de las 5 especies nuevas con sprites reales,
  precios, tests, verificación end-to-end contra backend real).
- **Pendiente real (no confundir con "no implementado a propósito"):**
  la fórmula de balance de §10 (`BASE_BUDGET`/`DURATION_EXPONENT`/etc.
  siguen siendo propuesta, no implementadas — esto es lo que este
  documento originalmente llamaba "F23"), UX rica de timeline/alertas
  (§16, lo que originalmente era "F24"), y duplicados/rarezas
  individuales/huevos/gachapon reales (lo que seguía siendo "F25" incluso
  después de que su contenido de colección se adelantara a esta fase). El
  resto de este documento (§1-20) sigue siendo el diseño original tal como
  se escribió — léelo como intención/arquitectura, no como "todavía no
  existe nada de esto".

## 1. Objetivo del sistema

Mascotas + Expediciones dejan de ser un sistema AFK secundario ("elegí un
destino, esperá, reclamá") para convertirse en un sistema de progresión
central: mascotas coleccionables/progresables cuyos atributos afectan
expediciones individuales de cada jugador, con duración, dificultad,
recompensas, eventos propios y decisiones simples, resueltas
server-side y capaces de avanzar offline.

## 2. Principios de diseño

Estos son los mismos principios que ya gobiernan el resto de Chibikko
(visibles en los comentarios del código existente, aunque no encontré un
`CLAUDE.md` real en el repo — ver hallazgo en §3.9), reafirmados
explícitamente por el pedido de esta fase:

1. **El servidor es la única autoridad.** El cliente nunca calcula daño,
   loot, ni resultados — solo los pide y los muestra.
2. **Resolución perezosa (lazy resolution).** Nada se resuelve por un job,
   cron ni worker. Se resuelve la primera vez que alguien pide el recurso
   después de que correspondía resolverse.
3. **Un personaje/mascota nunca se deriva del cliente.** Siempre sale del
   usuario autenticado (Sanctum).
4. **Columnas para lo que se consulta/ordena/filtra; JSON para
   composiciones atómicas que se leen enteras.** (Precedente real: `items`
   usa columnas para stats consultables y `metadata_json` para datos de
   furniture que se consumen como bloque — ver §3.7).
5. **Tablas de catálogo para contenido que crece con el tiempo**
   (`items`, `recipes`, `pet_narrative_events` ya lo hacen así) — nunca
   hardcodeado en un service.
6. **Sin Redis, sin colas, sin microservicios para este sistema.** El MVP
   ya demostró (con `PetExpeditionService::resolveIfDue`) que
   `DB::transaction()` + `lockForUpdate()` alcanza.
7. **`ApiClient.ts` es la única entrada HTTP del frontend; `Realtime.ts`
   la única capa de Echo/WebSocket.** Expeditions/Pets es HTTP puro en
   F20-F24 — no necesita Reverb (no hay nada "en tiempo real compartido"
   que mostrar; cada expedición es privada de su jugador).
8. **No arquitectura enterprise.** Cuando JSON alcanza, JSON. Cuando una
   tabla relacional aporta algo real (integridad referencial, queries de
   balance, edición granular), tabla.

## 3. Estado actual encontrado (auditoría real del código)

### 3.1 Tablas que existen hoy

| Tabla | Columnas reales | Notas |
|---|---|---|
| `pets` | `id, character_id (UNIQUE), key, name, level, exp, health, max_health, energy, max_energy, status, timestamps` | **1 mascota por personaje, a nivel de constraint de DB.** `key` es un string libre ("starter" es el único valor real hoy) — NO hay tabla de especies. |
| `pet_destinations` | `id, key (UNIQUE), name, difficulty (1-5, CHECK), duration_minutes, loot_min_tier, loot_max_tier, loot_pool_json, timestamps` | 3 filas reales: `forest` (Bosque, dif.2, **120 min**), `mountains` (Montañas, dif.3, **300 min**), `blood_castle` (Castillo Sangriento, dif.5, **720 min = 12h**, confirma la queja del punto 8). `loot_min_tier`/`max_tier` son puramente cosméticos — nada filtra loot por tier todavía. |
| `pet_expeditions` | `id, pet_id, destination_id (FK), status, started_at, ends_at, resolved_at, result_data_json, timestamps` | `status`: `active\|completed\|claimed` (enum PHP sobre columna string). `result_data_json` contiene **eventos + loot + health_delta + narrative_log completos, calculados una sola vez en `start()`**. |
| `pet_narrative_events` | `id, destination_id (nullable FK), category, rarity, text, weight, is_active, timestamps` | 108 filas seedeadas. Puramente cosmético — nunca toca loot/vida/tiempo. |

**`pet_expedition_checkpoints` NO EXISTE.** Ni la tabla, ni el modelo, ni
ninguna referencia en `app/`, `database/`, config o rutas — confirmado
con grep de "checkpoint" (case-insensitive) en todo `backend/`, cero
resultados. Lo que el punto 17 describe como "actualmente tenemos" es en
realidad **el diseño objetivo, no el estado real** — lo trato como una
tabla nueva a diseñar desde cero (§9), no a auditar como legado.

### 3.2 El "sistema de eventos" real hoy son DOS sistemas separados, ninguno interactivo

- **`pet_narrative_events`** (tabla): texto de ambientación puro. Elegido
  por rareza ponderada + anti-repetición de las últimas 3, con
  degradación hacia "common" si la rareza elegida no tiene candidatos.
  Se seleccionan N eventos (`MIN 3, MAX 20, 1 cada 12 min de duración
  final`) **todos de una vez en `start()`**, con timestamps `occurred_at`
  pre-calculados con jitter, y se "revelan" filtrando `occurred_at <=
  now()` en `PetPresenter` — nunca se vuelve a tirar un dado.
- **`config/pet_events.php`** (archivo de config, NO tabla): eventos
  MECÁNICOS, solo 3 destinos, cada uno con 5-6 entradas hardcodeadas
  `{key, label, type: positive|negative|delay, ...}`. Se sortean 0-2 por
  expedición, **también en `start()`**, y contribuyen a
  `health_delta`/`delay_minutes`/bonus de loot ya fijados de entrada.

**Ninguno de los dos tiene decisión del jugador.** No existe hoy
`[ENFRENTAR]/[HUIR]` ni nada similar — es una invención completa de F22,
no una extensión de algo existente.

### 3.3 El diseño central actual es "todo se calcula en `start()`" — lo opuesto a lo pedido

El comentario que encabeza `PetExpeditionService` lo dice explícitamente:

> "TODO el resultado de una expedición (eventos, retraso, loot, delta de
> vida) se decide UNA VEZ, en start()... Por eso resolveIfDue() nunca
> tira dados: solo compara timestamps."

Esto es **resolución perezosa de la APLICACIÓN de un resultado ya
calculado**, no resolución perezosa del CÁLCULO en sí (que es lo que pide
§15/§16). `resolveIfDue()` es el único lugar que hace algo "en su
momento": aplicar `health_delta` y pasar a `Completed` cuando `ends_at`
ya pasó. Es un patrón sólido y **reutilizable** (ver §10), pero acota
"resultado" a un único blob fijado al inicio, no a una secuencia de
checkpoints resueltos en su momento real.

### 3.4 Bug de concurrencia real encontrado: `claim()` no está bloqueado

`PetExpeditionController::claim()` llama `resolveIfDue()` (que SÍ usa
`lockForUpdate()` correctamente) y después, si el status ya es
`Completed`, llama a `PetExpeditionService::claim()` — pero esa función
**no vuelve a lockear ni a re-verificar el status dentro de su propia
transacción** antes de conceder loot:

```php
public function claim(PetExpedition $expedition): array
{
    $loot = $expedition->result_data_json['loot'] ?? [];
    DB::transaction(function () use ($expedition, $character, $loot) {
        foreach ($loot as $entry) { $this->grantService->grant(...); }
        $expedition->status = PetExpeditionStatus::Claimed;
        $expedition->save();
        ...
    });
    return $loot;
}
```

Dos requests simultáneos (doble click, dos pestañas) que ambos pasan el
chequeo `status !== Active` en el controller **antes** de que cualquiera
de los dos marque `Claimed`, ejecutan ambos el `foreach` de loot →
**loot duplicado real, hoy, en producción.** Esto es exactamente el
escenario que pide auditar el punto 24, y ya existe sin que F20 lo haya
tocado todavía. Lo marco como hallazgo, no lo arreglo en esta tarea (sin
código), pero **recomiendo que F21 lo corrija como parte de generalizar
el patrón de locking**, no como un parche aislado.

### 3.5 Frontend (`pet.astro` + `ApiClient.ts`)

Página única (`web/src/pages/pet.astro`, patrón Astro+HTML+`<script>`,
NO Phaser): panel de mascota (barras salud/energía), panel de estado
(idle/exploring/completed con countdown cosmético), grid de destinos,
historial, bitácora narrativa con render incremental y auto-scroll
condicional. Polling cada 20s con guard contra refrescos superpuestos
(`refreshInFlight`/`refreshQueued`) — mismo patrón defensivo que ya usa
el resto del proyecto (Presence, Chat).

`ApiClient.ts` ya expone: `getPet()`, `getPetDestinations()`,
`getPetExpedition()`, `startPetExpedition(key)`, `claimPetExpedition()`,
`getPetEvents()`, con tipos `Pet`, `PetExpedition`, `PetDestination`,
`PetEvent`, `PetReward`, `PetNarrativeLogEntry`.

### 3.6 Reutilizable tal cual: `ActivityLogger` (F12)

`ActivityEvent{character_id, type, payload_json, created_at}` (append-only,
sin `updated_at`), escrito exclusivamente vía `ActivityLogger::log()`.
`PetExpeditionService::claim()` YA lo usa para `'expedition_claimed'`. Es
el mecanismo correcto y suficiente para toda la bitácora que pide §20 —
agregar `expedition_started`/`expedition_event`/etc. es solo más
llamadas a `log()` en los puntos correctos, cero infraestructura nueva.

### 3.7 Precedente directo para "tipo + JSON de parámetros": `items.type` + `metadata_json`

El catálogo de items ya usa exactamente el patrón que este documento
recomienda para eventos (§9): una columna discriminadora (`type`) más una
bolsa JSON libre (`metadata_json`) cuya forma depende del tipo (furniture
trae `{tile_width, tile_height, collision, placeable, rotatable}`, otros
tipos no). No es una idea nueva para este proyecto, es coherencia con
algo que ya funciona.

### 3.8 `InventoryGrantService::consume()` ya usa `lockForUpdate()` bajo concurrencia real

Precedente adicional (más allá de `resolveIfDue()`) de que el patrón
lock+transacción ya está probado en este codebase, no es una técnica
nueva a introducir.

### 3.9 Hallazgo aparte: no existe `CLAUDE.md` en el repo

El código cita repetidamente "CLAUDE.md principio #1/#2/#3/#6" en
comentarios, pero no hay ningún archivo `CLAUDE.md` en ninguna parte del
repositorio (confirmado por búsqueda exhaustiva). Los principios citados
sí son reales y consistentes (los infiero del código, listados en §2),
pero vale la pena que lo sepas: o el archivo se perdió/nunca se
commiteó, o es una convención que se sigue sin estar escrita. No es
bloqueante para esta tarea, lo dejo registrado porque afecta qué tan
"fuente de verdad" puedo citar sin verla directamente.

### 3.10 Riesgo de infraestructura: doble backend documentado (VPS vs Render+Neon)

Todo el trabajo de F16-F19 de esta conversación (Reverb, Presence, Mundo)
se hizo contra un **VPS propio con MariaDB**. Pero `docs/RENDER_DEPLOYMENT_ANALYSIS.md`
documenta una migración **ya implementada en el repo** (Dockerfile,
`render.yaml`, entrypoint) a **Render + PostgreSQL vía Neon**, con un
`SQLSTATE[25P02]` real ya diagnosticado y resuelto (ver §12). No verifiqué
cuál de los dos es el backend de producción real hoy — son arquitecturas
de despliegue distintas y no debería asumir cuál manda. Como el punto 22
de tu propio pedido declara PostgreSQL como la base actual, este
documento diseña asumiendo **PostgreSQL (Neon) como destino real**, pero
recomiendo confirmar explícitamente cuál backend está sirviendo tráfico
real antes de implementar F21 (el primero con locking bajo concurrencia
genuina).

## 4. Problemas actuales (resumen)

1. Duraciones desproporcionadas (Castillo = 12h reales, confirmado).
2. Todo el resultado se decide en `start()` — cero verdadero "tiempo
   real"; el jugador podría, en teoría, inspeccionar la respuesta del
   `start()` y saber el final de antemano (no hay UI que lo exponga hoy,
   pero el dato ya existe completo en `result_data_json` desde el
   minuto cero).
3. Sin decisiones del jugador — ambos sistemas de eventos son 100%
   automáticos.
4. Sin loot visible/probabilidades expuestas antes de iniciar — el
   jugador no sabe qué puede ganar hasta que ya empezó.
5. Sin nivel mínimo de mascota por expedición.
6. `pets.key` como string libre en vez de una relación real a especies —
   no hay 20 especies, hay 1 ("starter"), y no hay dónde colgar
   modificadores por especie.
7. Bug de concurrencia real en `claim()` (§3.4) — loot duplicado posible
   hoy mismo con un doble click.
8. `loot_pool_json` no tiene pesos/probabilidades — "elegí 2 al azar,
   uniforme" no es una loot table real, no hay forma de mostrar "40%
   Hierro" porque esa cifra no existe en ningún lado.
9. Sin arquitectura de "quién soy yo, especie" que permita 20 mascotas
   sin 20 métodos/branches en el service.

## 5-7. Arquitectura propuesta, alternativas y decisiones tomadas

Organizado por decisión, cada una con ≥2 alternativas evaluadas.

### 5.1 Representación de modificadores de mascota (Punto 2 del pedido)

**Opción A — Columnas fijas** (`loot_bonus_castle_pct`, `rare_loot_bonus_pct`, ...):
descartada de entrada. No escala a 20 especies × N bonos distintos;
cada modificador nuevo es una migración.

**Opción B — JSON en `pet_species.modifiers_json`:**
```json
[
  {"type": "loot_bonus_pct", "scope": "expedition", "target": "blood_castle", "value": 10},
  {"type": "rare_loot_chance_pct", "scope": "global", "value": 5},
  {"type": "damage_reduction_pct", "scope": "event_type", "target": "enemy", "value": 10}
]
```
Ventajas: cero migración para agregar/ajustar un modificador a una
especie existente; coherente con `loot_pool_json`/`metadata_json` ya
usados en el proyecto; se lee entero para UNA mascota a la vez, nunca se
necesita filtrar/join a través de especies. Desventajas: no se puede
hacer `WHERE modifier_value > X` en SQL directo (no hace falta hoy — no
hay ninguna pantalla que ordene especies por poder).

**Opción C — Tabla normalizada `pet_species_modifiers`:**
(`species_id, type, scope, target, value`). Ventajas: integridad de tipo
a nivel de FK/enum de DB, queries de balance ("qué especies dan +loot en
Castillo") triviales en SQL. Desventajas: para ~20 especies × 2-4
modificadores (40-80 filas) es maquinaria relacional para algo que
siempre se consume completo y de a una especie por vez — la ventaja de
"queryable" no tiene un consumidor real hoy.

**Decisión: Opción B (JSON).** El "vocabulario" de `type` válidos se fija
como un PHP enum (`ModifierType`) para que la validación de escritura
rechace tipos inventados — el JSON guarda **datos**, la **lógica** de qué
significa cada `type` vive en un `PetModifierResolver` (servicio PHP,
testeable), nunca solo en el JSON (cumple la restricción del punto 19).

### 5.2 Progresión por nivel sin hardcodear 20×20

**Opción A — Fórmula continua** (`valor_efectivo = base + nivel * factor`):
simple, pero fuerza que TODO modificador escale linealmente con el
nivel — no representa "se desbloquea recién en nivel 5" ni "no mejora
en niveles intermedios" sin trucos.

**Opción B — Lista dispersa de milestones**, embebida en la misma especie:
```json
"level_modifiers": [
  {"level": 1, "modifiers": [{"type":"loot_bonus_pct","scope":"expedition","target":"blood_castle","value":10}]},
  {"level": 5, "modifiers": [{"type":"loot_bonus_pct","scope":"expedition","target":"blood_castle","value":12}]},
  {"level": 10, "modifiers": [{"type":"loot_bonus_pct","scope":"expedition","target":"blood_castle","value":15}]}
]
```
El resolver toma el milestone de mayor `level` que sea `<= pet.level`
— cada milestone es un **override completo**, no un delta acumulable
(evita bugs de acumulación). Solo se escriben las filas donde algo
realmente cambia, tal como pide el punto 4.

**Decisión: Opción B, embebida en `pet_species` como columna JSON
separada** (`level_modifiers_json`, distinta de `modifiers_json` base —
"base" son los modificadores de nivel 1 implícitos, `level_modifiers_json`
son los overrides en niveles posteriores). Alternativa honesta: si el
diseño de contenido crece mucho (curación por muchas personas a la vez,
necesidad de historial de cambios por nivel), migrar esto a una tabla
`pet_species_levels(species_id, level, modifiers_json)` es un cambio
aislado y no rompe el resolver (misma interfaz de salida). No lo hago
ahora porque no hay evidencia de esa necesidad.

### 5.3 Especie vs. mascota individual (Punto 3)

Sin alternativas reales acá — es la separación correcta y la única
sensata: **`pet_species` (catálogo) + `pets.species_id` (FK)**. Hoy
`pets.key` es un string suelto; pasa a ser una relación real.
`pet_species` guarda: `key, name, description, rarity (para F25),
sprite_key, modifiers_json, level_modifiers_json, is_active`. `pets`
conserva `level, exp, health, max_health, energy, max_energy, status,
name` (el apodo del jugador) — esos son atributos de la INSTANCIA, no de
la especie.

### 5.4 Alimentación (Punto 5)

**Opción A — Flag en `items`** (`items.pet_exp_value` nullable): acopla
el catálogo general de items (usado por inventario/crafting/shop) a un
concepto específico de mascotas.

**Opción B — Tabla de catálogo dedicada `pet_food_items`**
(`item_id FK, exp_value, favorite_species_ids_json nullable`): sigue el
mismo patrón ya establecido por `items`/`recipes`/`shop_products` (tabla
de catálogo, seedeable, sin acoplar `items` a un dominio ajeno).

**Decisión: Opción B.** "Comida favorita" (futuro) es agregar
`favorite_species_ids_json` (ya diseñado, columna nullable desde el
día uno aunque F20 no la use) o, si se vuelve más rico, una tabla
`pet_species_favorite_foods` — de cualquier forma, aditivo, sin
rediseño.

### 5.5 Expediciones: definición vs. instancia (Punto 7)

`pet_destinations` se convierte en **`expedition_definitions`**
(rename conceptual — ver migración en §8), agregando lo que hoy falta:
`min_pet_level`, `duration_seconds` (reemplaza `duration_minutes`, ver
§9), `description`. `pet_expeditions` sigue siendo la instancia (ya
está bien separada hoy, se mantiene).

### 5.6 Loot visible / reward pools (Punto 10)

**Opción A — JSON enriquecido** (`reward_pool_json` con
`[{item_key, weight, min_qty, max_qty}]` en la propia
`expedition_definitions`): simple, mismo patrón que `loot_pool_json`
actual, sin migración para nuevos items.

**Opción B — Tabla normalizada `expedition_rewards`**
(`expedition_definition_id, item_id FK, weight, min_qty, max_qty,
rarity_tier`): integridad referencial real contra `items` (el `claim()`
actual hace `if (!$item) continue;` — un item borrado/renombrado
silenciosamente reduce el loot sin avisar a nadie; una FK real
convertiría eso en un error visible al momento de editar contenido, no
en producción). Permite consultas de balance ("qué expediciones dan el
item X, con qué probabilidad") y que la UI pida directamente "dame la
loot table de esta expedición con % ya calculado" (`weight / SUM(weight)
* 100`) sin tener que decodificar JSON en el frontend.

**Decisión: Opción B**, a diferencia de los modificadores de mascota. La
razón de la asimetría: esto es contenido **player-facing** (el punto 10
pide mostrarlo con porcentajes reales en UI) donde la integridad y la
posibilidad de auditar/balancear pesan más que el costo de una tabla
extra — mientras que los modificadores de mascota nunca se muestran como
tabla cruda ni se auditan por fila.

### 5.7 Eventos: unificar narrativo + mecánico + decisión (Punto 13)

Única opción seria (mismo criterio que §5.6 al revés — acá SÍ conviene
tabla+JSON híbrido, no JSON puro ni tabla 100% normalizada):
**`expedition_event_definitions`**, discriminada por `type`
(`narrative|chest|enemy|help|...`), con una columna `config_json`
específica de cada `type` (mismo patrón que `items.type` +
`metadata_json`, §3.7):

```php
// type=narrative
config_json = null  // solo texto, sin mecánica

// type=chest
config_json = {"open_odds": {"loot": 70, "trap": 15, "nothing": 15}, "loot_multiplier": 1.5}

// type=enemy
config_json = {"win_chance_base": 60, "loot_on_win_multiplier": 1.2, "damage_on_loss": [10, 25]}

// type=help
config_json = {"success_chance": 70, "reward_on_success": "special", "damage_on_failure": [5, 15]}
```

Agregar un `type` nuevo en el futuro (F22+) es una fila nueva con su
propio `config_json` — **cero cambio de esquema**, tal como exige
explícitamente el punto 13. `pet_narrative_events` (108 filas ya
seedeadas) se migra 1:1 a `expedition_event_definitions` con
`type=narrative`; `config/pet_events.php` se migra a filas con
`type=chest`/`enemy` reales — ambos catálogos actuales quedan absorbidos,
no duplicados.

## 6. Modelo de datos (propuesto)

```text
pet_species
  id, key (unique), name, description, rarity, sprite_key,
  modifiers_json, level_modifiers_json, is_active, timestamps

pets
  id, character_id (unique, FK), species_id (FK), name,
  level, exp, health, max_health, energy, max_energy, status,
  timestamps

pet_food_items
  id, item_id (FK -> items), exp_value, favorite_species_ids_json (nullable),
  timestamps

expedition_definitions               [antes: pet_destinations]
  id, key (unique), name, description, difficulty (1-5, CHECK),
  duration_seconds, min_pet_level, is_active, timestamps

expedition_rewards                   [nuevo, reemplaza loot_pool_json]
  id, expedition_definition_id (FK), item_id (FK -> items),
  weight, min_qty, max_qty, rarity_tier (nullable), timestamps

expedition_event_definitions         [nuevo, unifica pet_narrative_events + config/pet_events.php]
  id, expedition_definition_id (nullable FK, null=universal),
  type, rarity, weight, is_active, title (nullable), text,
  config_json (nullable), timestamps

pet_expeditions                      [existe, se mantiene + rename FK]
  id, pet_id (FK), expedition_definition_id (FK),
  status (active|completed|claimed), started_at, ends_at,
  resolved_at, result_data_json (se acota su rol, ver §11), timestamps

pet_expedition_checkpoints           [nuevo — NO existe hoy, ver §3.1]
  id, pet_expedition_id (FK), sequence,
  scheduled_at, kind (narrative|event),
  event_definition_id (nullable FK -> expedition_event_definitions),
  status (pending|awaiting_decision|resolved),
  decision (nullable string),
  resolved_at (nullable), payload (nullable json),
  timestamps
```

### 6.1 Relaciones

```text
Character 1—1 Pet
PetSpecies 1—N Pet
Pet 1—N PetExpedition
ExpeditionDefinition 1—N PetExpedition
ExpeditionDefinition 1—N ExpeditionReward
ExpeditionDefinition 1—N ExpeditionEventDefinition (nullable = universal)
Item 1—N ExpeditionReward
Item 1—1 PetFoodItem
PetExpedition 1—N PetExpeditionCheckpoint
ExpeditionEventDefinition 1—N PetExpeditionCheckpoint (el evento QUE resultó elegido)
```

### 6.2 Qué es tabla / JSON / relación / config — resumen de §5

| Concepto | Forma | Por qué |
|---|---|---|
| Modificadores de especie | JSON (`pet_species.modifiers_json`) | Nunca se query/filtra entre especies, siempre se lee 1 completa |
| Progresión por nivel | JSON (`pet_species.level_modifiers_json`) | Mismo motivo, disperso por diseño |
| Especies | Tabla (`pet_species`) | Es una relación real (`pets.species_id`), contenido que crece |
| Comida | Tabla (`pet_food_items`) | Catálogo, mismo patrón que `items`/`recipes` |
| Definiciones de expedición | Tabla (`expedition_definitions`) | Ya es tabla hoy, se mantiene y enriquece |
| Loot/recompensas | Tabla (`expedition_rewards`) | Player-facing, necesita integridad FK y % reales |
| Definiciones de eventos | Tabla + JSON híbrida (`expedition_event_definitions.config_json`) | Tipo discriminado (tabla) + parámetros variables por tipo (JSON), mismo patrón que `items` |
| Checkpoints (instancia) | Tabla (`pet_expedition_checkpoints`) | Es estado mutable por expedición, con status propio — no compone bien como JSON si necesita resolverse individualmente con locking |
| Resultado de expedición completa | JSON acotado (`pet_expeditions.result_data_json`) | Ver §11 — pasa a ser un resumen/caché, no la fuente de verdad de cada evento |

## 7. Flujo de una expedición (end-to-end)

```text
1. POST /v1/pet/expeditions/start {expedition_key}
   → valida: mascota libre (status=idle), nivel mínimo cumplido
   → planifica N checkpoints: [{sequence, scheduled_at, kind}], SIN
     resolver ni elegir evento todavía
   → crea PetExpedition (status=active, ends_at = now + duration_seconds)
   → responde: expedición + lista de checkpoints con scheduled_at
     (para que la UI pueda mostrar una línea de tiempo), sin payload

2. Mientras status=active:
   GET /v1/pet  (o /v1/pet/expedition)
   → SIEMPRE, antes de responder, corre resolveDueCheckpoints():
       - lockea la fila de PetExpedition (mismo patrón que resolveIfDue hoy)
       - por cada checkpoint pending con scheduled_at <= now(), EN ORDEN:
           - si kind=narrative: elige texto (weighted random, ya resuelto),
             marca resolved, payload={text}
           - si kind=event: elige expedition_event_definition (weighted
             random según el pool del destino), evalúa su `type`:
               - narrative-like (chest sin decisión / enemy con decisión
                 obligatoria) → si config_json.requires_decision: marca
                 status=awaiting_decision, NO sigue resolviendo los
                 checkpoints siguientes todavía (bloquea el avance hasta
                 que el jugador decida)
               - si no requiere decisión: resuelve completo ahí mismo
                 (rollea resultado, aplica consecuencias, guarda payload)
   → si ends_at ya pasó Y no queda ningún checkpoint pending/awaiting_decision:
     status=completed

3. Si algún checkpoint quedó awaiting_decision:
   POST /v1/pet/expeditions/checkpoints/{id}/decide {decision: "fight"|"flee"}
   → lockea expedition, re-verifica que el checkpoint siga
     awaiting_decision (si ya se resolvió, responde el resultado
     existente sin volver a tirar dados — idempotente)
   → aplica la decisión: rollea resultado según config_json del evento,
     aplica consecuencias (daño/loot), guarda payload, status=resolved
   → continúa resolviendo los checkpoints siguientes que ya estén vencidos

4. Cuando status=completed:
   POST /v1/pet/expeditions/{id}/claim
   → lockea expedition (fix del bug de §3.4)
   → re-verifica status=completed dentro de la transacción
   → concede TODO el loot acumulado de los checkpoints resueltos
   → status=claimed, pet.status=idle
   → ActivityLogger: expedition_claimed
```

## 8. Sistema temporal (planificación vs. resolución vs. aplicación vs. historial)

Separación estricta pedida en el punto 16, mapeada a métodos concretos
de un futuro `ExpeditionService`:

| Responsabilidad | Qué hace | Qué NO hace |
|---|---|---|
| **Planificación** (`start()`) | Decide cuántos checkpoints, su `scheduled_at`, su `kind`. Fija `ends_at`. | Nunca elige QUÉ evento específico ocurre, nunca rollea daño/loot. |
| **Resolución** (`resolveDueCheckpoints()`) | Para cada checkpoint vencido: elige el evento concreto (si aplica), tira los dados de su resultado. | Nunca aplica la consecuencia directamente al modelo del jugador — solo calcula y guarda en `payload`. |
| **Aplicación** (`applyCheckpointConsequences()`) | Toma un `payload` ya resuelto y modifica `pet.health`, acumula loot pendiente, etc. | Nunca vuelve a tirar dados — es puramente determinística sobre un payload ya fijado. |
| **Historial** (`ActivityLogger`) | Registra qué pasó, cuándo, para el feed. | Nunca decide ni aplica nada — solo observa y persiste. |

Esto es una extensión directa y NO conflictiva del patrón que
`resolveIfDue()` ya prueba hoy (lock → verificar si corresponde algo →
hacerlo una sola vez → guardar) — la diferencia es que ahora hay **N
checkpoints en secuencia** en vez de un único evento binario
(activo→completado).

## 9. Duración (actualización — reemplaza el planteamiento de 5/10/15 min)

**Corrección explícita del usuario incorporada:** no se implementan
duraciones de prueba cortas. Las duraciones base reales, desde el
principio:

```text
30 min  =   1,800s
1 h     =   3,600s
2 h     =   7,200s
4 h     =  14,400s
8 h     =  28,800s
12 h    =  43,200s
(24 h   =  86,400s — reservado, no obligatorio para F20/F21)
```

`expedition_definitions.duration_seconds` es una columna simple de
enteros — el motor temporal (`start()`, `resolveDueCheckpoints()`) nunca
conoce estos valores hardcodeados, solo lee la columna. Agregar 24h o
cualquier otro valor intermedio (6h, 16h) es una fila de seed nueva, cero
cambio de código.

### 9.1 Propuesta de distribución inicial (contenido, no arquitectura)

| Expedición | Dificultad | Duración | Nivel mín. mascota |
|---|---|---|---|
| Bosque Encantado | 1 | 30 min | 1 |
| Colinas del Viento | 2 | 1 h | 1 |
| Montañas Heladas | 3 | 2 h | 3 |
| Ruinas Antiguas | 3 | 4 h | 5 |
| Pantano Maldito | 4 | 8 h | 7 |
| Castillo Sangriento | 5 | 12 h | 10 |

No es prescriptivo — es un punto de partida razonable que ya resuelve la
queja del punto 8 (nada por debajo de 30 min, nada "de prueba") sin
perder la duración de 12h como el contenido de mayor compromiso/riesgo
del juego.

## 10. Loot y balance duración+dificultad→recompensa (fórmula propuesta, NO implementada)

Problema a resolver: evitar tanto (a) linealidad pura (una de 12h no
puede dar solo "4x lo de una de 3h", se siente igual de arbitrario en la
otra dirección) como (b) que una expedición larga y difícil dé poco loot
(la queja original).

**Modelo propuesto: "presupuesto de recompensa" con retornos
decrecientes sobre duración, multiplicativo sobre el resto.**

```text
duration_hours = duration_seconds / 3600

base_budget = BASE_BUDGET
              × duration_hours^DURATION_EXPONENT      (DURATION_EXPONENT ≈ 0.65)
              × DIFFICULTY_MULTIPLIER[difficulty]      (tabla, no fórmula lineal)

pet_factor = 1 + (pet.level × LEVEL_FACTOR)             (LEVEL_FACTOR ≈ 0.015 → nivel 20 = +30%)

modifier_factor = producto de todos los modifiers_json aplicables
                   (loot_bonus_pct, etc., resueltos por PetModifierResolver)

event_factor = producto de los outcomes de checkpoints tipo "event"
               ya resueltos (cofres/enemigos ganados suman, derrotas restan)

rng_factor = random entre 0.85 y 1.15                    (variación orgánica, nunca 0)

final_budget = base_budget × pet_factor × modifier_factor × event_factor × rng_factor
```

`final_budget` se traduce a resultado real de dos formas combinables
(a decidir en F23, no ahora):
1. **Número de tiradas** contra `expedition_rewards` (más presupuesto =
   más ítems, no ítems más caros individualmente), y/o
2. **Umbral de rareza alcanzable** (presupuesto alto habilita tirar
   contra el pool de rareza alta de esa misma tabla, no una tabla
   distinta).

**Por qué exponente <1 en duración, no lineal:** con `DURATION_EXPONENT
= 0.65`, una expedición de 12h da ≈`12^0.65 ≈ 5.3x` el presupuesto base
de una de 1h — significativamente más, pero lejos de las 12x lineales
que devaluarían visualmente el resto del contenido corto. Con
`DIFFICULTY_MULTIPLIER` como tabla editable (ej. `[1: 1.0, 2: 1.3, 3:
1.7, 4: 2.3, 5: 3.0]`, no una fórmula), dificultad y duración se pueden
balancear de forma independiente sin que una ecuación única los acople
artificialmente.

**Alternativa considerada y descartada:** presupuesto puramente aditivo
(`base + duration_hours*K + difficulty*K2`) — más simple, pero la suma
de dos lineales sigue sin capturar "las expediciones grandes se sienten
especiales", que es el problema real reportado.

Esto es una propuesta de arquitectura/fórmula, explícitamente **no
implementada** — los valores concretos (`BASE_BUDGET`,
`DURATION_EXPONENT`, `LEVEL_FACTOR`, la tabla de dificultad) son
parámetros de balance a ajustar jugando, no decisiones de arquitectura.

## 11. Checkpoints (diseño detallado)

Confirmado en §3.1: no existen hoy, se diseñan desde cero, tomando como
base razonable lo que el pedido original proponía.

```php
// Estados de un checkpoint
enum CheckpointStatus: string {
    case Pending = 'pending';                    // todavía no llegó scheduled_at
    case AwaitingDecision = 'awaiting_decision';  // vencido, evento elegido, requiere POST decide
    case Resolved = 'resolved';                   // terminado, payload fijo
}

enum CheckpointKind: string {
    case Narrative = 'narrative';  // siempre auto-resuelve, sin decisión
    case Event = 'event';          // respaldado por expedition_event_definitions;
                                    // el type de ESE evento decide si requiere decisión
}
```

`kind` se mantiene deliberadamente en solo 2 valores (no uno por cada
tipo de evento) — quién decide si hace falta interacción es
`expedition_event_definitions.type`/`config_json`, nunca el checkpoint.
Esto es lo que permite agregar tipos de evento nuevos (F22+) sin tocar
`pet_expedition_checkpoints` (cumple el punto 13 explícitamente).

### 11.1 Payload — cuándo es null, cuándo no (Punto 19)

```json
// Pending: SIEMPRE null. Nada se decide antes de tiempo.
{ "payload": null }

// Resolved, kind=narrative:
{ "payload": { "text": "El bosque está extrañamente tranquilo..." } }

// Resolved, kind=event, type=chest, resultado="loot":
{ "payload": { "outcome": "loot", "loot": [{"item_key": "iron_ore", "quantity": 4}] } }

// AwaitingDecision, kind=event, type=enemy (todavía sin decisión):
{ "payload": null, "status": "awaiting_decision", "event": {"title": "...", "options": ["fight", "flee"]} }

// Resolved, kind=event, type=enemy, tras decide(fight), derrota:
{ "payload": { "outcome": "defeat", "decision": "fight", "damage": 18, "loot": [] } }
```

**Confirmación del punto 19:** el enfoque es correcto CON UNA condición
— el payload es el **resultado ya calculado**, nunca el lugar donde vive
la lógica de qué puede pasar. Las probabilidades/reglas de cada `type`
viven en `expedition_event_definitions.config_json` (catálogo,
versionable, auditable) y el CÓDIGO que las interpreta vive en un
resolver PHP tipado — el payload de un checkpoint resuelto es solo un
registro histórico de qué salió, exactamente como pide la restricción de
no poner lógica de negocio importante solo en JSON.

## 12. Idempotencia y concurrencia (Punto 24 + Punto 23)

### 12.1 Estrategia concreta

Extensión directa del patrón ya probado en `resolveIfDue()` — **un solo
lock por operación de resolución, cubriendo todos los checkpoints
vencidos de esa expedición a la vez**, no un lock por checkpoint (evita
que dos checkpoints de la misma expedición se resuelvan fuera de orden
bajo concurrencia):

```php
DB::transaction(function () use ($expeditionId) {
    $expedition = PetExpedition::whereKey($expeditionId)->lockForUpdate()->firstOrFail();

    if ($expedition->status !== Active) return $expedition; // ya terminado, no-op

    foreach ($expedition->checkpoints()->where('status', 'pending')
             ->where('scheduled_at', '<=', now())->orderBy('sequence')->get() as $checkpoint) {
        // resolver, aplicar, guardar — todo dentro del mismo lock
    }
});
```

Esto cubre **doble click, dos pestañas, requests simultáneos y
reconexión** con el mismo mecanismo: el segundo request que llega
mientras el primero tiene el lock simplemente espera, y cuando lo
obtiene, ya no hay nada `pending` vencido que resolver — responde el
mismo resultado ya fijado, nunca vuelve a tirar dados. **Catch-up
offline** (punto 15) es el mismo camino exacto, sin código especial: no
importa si pasaron 5 minutos o 3 horas, el `foreach` resuelve todos los
vencidos en orden, uno por uno.

Para `decide()` (checkpoint `awaiting_decision`): mismo lock sobre la
expedición completa, re-verificación de `status ===
awaiting_decision` DENTRO de la transacción antes de aplicar la
decisión — si ya se resolvió (carrera perdida), responde el resultado
existente en vez de fallar o repetir.

Para `claim()`: el fix concreto del bug real de §3.4 — agregar
exactamente el mismo `lockForUpdate()` + re-chequeo de `status` dentro
de la transacción que `resolveIfDue()` ya demuestra que funciona.

### 12.2 Riesgo Neon / PgBouncer (Punto 23, respuesta directa)

1. **Qué riesgo existe:** confirmado y ya documentado en
   `docs/RENDER_DEPLOYMENT_ANALYSIS.md` §14 — un `SQLSTATE[25P02]` real
   ocurrió durante `migrate --force` contra el endpoint **pooled** de
   Neon (PgBouncer en modo `transaction`), causado por Laravel
   envolviendo TODAS las migraciones en una única transacción DDL larga.
   **Ya resuelto**: las migraciones corren contra un connection string
   **directo** (`DB_URL_MIGRATE`), separado del `DB_URL` pooled que usa
   la app en runtime.
2. **¿Bloqueante para F20?** No. F20 (especies/mascotas/niveles/comida)
   no tiene ningún componente temporal ni de locking bajo concurrencia
   real — es CRUD server-authoritative normal, igual que Inventario o
   Crafting hoy (que ya corren sin problema contra el mismo pooled
   `DB_URL`).
3. **¿Bloqueante para F21?** Potencialmente relevante, no confirmado
   como problema — `DB::transaction()` + `lockForUpdate()` de una sola
   transacción corta (como `resolveIfDue()` ya hace hoy) es
   exactamente el caso de uso que PgBouncer en modo `transaction`
   está diseñado para soportar bien (a diferencia del DDL multi-statement
   que sí falló). No se probó bajo concurrencia REAL en este proyecto
   todavía (ni en MariaDB local ni en Neon).
4. **Recomendación:** no cambiar nada de infraestructura ahora. Antes de
   poner F21 en producción real, correr una prueba dirigida y barata: 2
   requests simultáneos reales resolviendo el mismo checkpoint contra el
   backend de producción, confirmando que el segundo espera el lock y
   responde el mismo resultado en vez de duplicar o fallar. Si sale bien
   (esperado), cero cambios de arquitectura. Si algo falla, ahí recién
   se evalúa `DB_URL_MIGRATE`-como-patrón también para runtime (una
   conexión directa dedicada a operaciones con lock), no antes.
5. **¿Necesita idempotencia/locking la arquitectura de resolución? Sí,
   siempre** — independientemente de si Neon resulta tener alguna
   particularidad extra, el requisito de "doble click no debe duplicar
   loot" existe con cualquier motor/infraestructura. El diseño de §12.1
   ya lo cubre por diseño, no como parche para Neon específicamente.

## 13. API propuesta (contratos, no implementación)

```text
GET    /v1/pet
       → { pet: {...}, expedition: {...} | null }
       → SIEMPRE corre resolveDueCheckpoints() antes de responder

GET    /v1/pet/species                (catálogo, nuevo)
       → [{ key, name, description, rarity }]  — sin modifiers_json crudo expuesto

GET    /v1/pet/expeditions/definitions  (reemplaza /pet/destinations)
       → [{ key, name, description, difficulty, duration_seconds,
            min_pet_level, reward_preview: [{item_name, percent, rarity_tier}] }]
       → reward_preview YA viene con % calculado server-side (weight/SUM*100)

POST   /v1/pet/expeditions/start { expedition_key }
       → 201, expedición + checkpoints planificados (sin payload)
       → 409 si la mascota ya está ocupada / nivel insuficiente

GET    /v1/pet/expeditions/current
       → expedición activa/completada-sin-reclamar + checkpoints
         (resueltos con payload, pending sin payload, awaiting_decision
         con las opciones disponibles)

POST   /v1/pet/expeditions/checkpoints/{id}/decide { decision }
       → 200, checkpoint resuelto (idempotente si ya estaba resuelto)
       → 422 si decision no es una opción válida para ese checkpoint
       → 409 si el checkpoint no está awaiting_decision

POST   /v1/pet/expeditions/{id}/claim
       → 200, { expedition, loot } (idempotente — ver §12.1)

POST   /v1/pet/feed { food_item_key }
       → 200, { pet, exp_gained, leveled_up }

GET    /v1/pet/expeditions/history
       → historial (igual que /pet/events hoy)
```

## 14. Frontend / UX futura (no implementada, orientativa)

- Panel de mascota: igual que hoy (barras salud/energía), + selector de
  especie visible cuando exista adquisición (F25).
- Grid de expediciones: tarjeta con dificultad (estrellas, ya existe),
  duración (ya existe formateo `formatDuration`), **nuevo: botón/
  desplegable "Ver recompensas posibles"** que muestra `reward_preview`
  del punto 13 tal cual — la única pieza nueva de UI que F20/F21 necesitan
  preparar en el backend para que F24 la use directamente sin diseño
  adicional de API.
- Timeline de checkpoints durante una expedición activa: lista vertical
  con los `scheduled_at` ya conocidos (aunque el contenido futuro no se
  revele, la HORA sí puede mostrarse — "próximo evento en 12 min").
- Modal de decisión: cuando `awaiting_decision`, dos botones (texto +
  opciones del `config_json.options`), sin combate visual — mismo
  espíritu de "servidor calcula, cliente muestra" que Arena.
- Reutiliza `refreshInFlight`/`refreshQueued` (patrón ya en `pet.astro`)
  y el guard de renderizado incremental de la bitácora — no hace falta
  un patrón nuevo de polling.

## 15. Activity Feed / bitácora (Punto 20)

Reutilización total de `ActivityLogger` (§3.6), sin tabla nueva.
Vocabulario de `type` propuesto (strings, mismo criterio que
`activity_events.type` ya usa hoy):

```text
expedition_started    { expedition_name, duration_seconds }
expedition_event      { checkpoint_id, kind, summary_text }
expedition_decision   { checkpoint_id, decision, outcome }
expedition_damage     { amount, source }
expedition_reward     { items: [...] }  -- por checkpoint, no solo al final
expedition_completed  { expedition_name }
expedition_claimed    { loot }          -- ya existe hoy
pet_leveled_up        { new_level }
pet_fed               { food_item, exp_gained }
```

## 16. Alertas de mascota (Punto 21, diseño sin implementar)

Requisito explícito: sin polling nuevo. Solución: agregar un campo
liviano al endpoint que YA se pollea en cada página vía
`refreshPlayerState()` (`GET /v1/character`, usado por `Hud.astro` en
TODA página autenticada, no solo `/pet`):

```json
// GET /v1/character, campo nuevo agregado
{ ..., "has_pending_pet_alert": true }
```

Calculado con una query barata e indexada: `EXISTS` de (expedición
`completed` sin reclamar) OR (checkpoint `awaiting_decision`) para la
mascota del personaje. Cero infraestructura nueva — el HUD ya refresca
este endpoint en cada carga de página; F24 solo necesita pintar el punto
`[!]` sobre el ícono de Mascota en `NavBar.astro` cuando ese campo sea
`true`.

## 17. Qué pertenece a F20 / qué queda después / qué NO implementar todavía

### F20 (esta fase, la única a implementar cuando se apruebe)
- `pet_species` + migración de `pets.key` → `pets.species_id`.
- `pet_food_items` + endpoint de alimentación + EXP/subida de nivel.
- `PetModifierResolver` (lee `modifiers_json`/`level_modifiers_json`,
  sin consumidores reales todavía — expeditions es F21).
- Seed de ~20 especies con modificadores simples (2-4 cada una).

### F21+ (fases posteriores, en el orden ya propuesto en §18)
Checkpoints, eventos, loot tables reales, decisiones, balance,
adquisición — exactamente como estaba estructurado el roadmap original,
con los ajustes de §18.

### Explícitamente NO implementar todavía (en ninguna fase próxima)
- Branching narrativo complejo / múltiples decisiones encadenadas por
  expedición (punto 14).
- Huevos/gachapon/duplicados (punto 6, es F25).
- Corrección automática de concurrencia para Neon más allá de lo que
  §12.1 ya cubre (no hay evidencia de que haga falta).
- Un sistema de alertas en tiempo real vía Reverb — el diseño de §16 es
  deliberadamente HTTP/polling existente, no WebSocket.

## 18. Roadmap revisado

El roadmap propuesto (F20-F26) es sólido en su secuencia general; dos
ajustes:

1. **F21 debe incluir explícitamente el fix del bug de `claim()` de
   §3.4** (generalizar el locking, no soltarlo como parche aparte) —
   agregado a la lista de F21.
2. **Mover "loot tables reales + % expuestos" de F23 a F21**, no
   después de F22 (Eventos). Razón: `expedition_rewards` con pesos es
   la base de la que F22 (eventos tipo cofre/enemigo) YA depende para
   calcular sus propios outcomes — construir eventos sobre una loot
   table todavía no normalizada obligaría a tocarla de nuevo en F23.
   F23 pasa a enfocarse en el BALANCE (la fórmula de §10) sobre una
   loot table que ya existe desde F21.

```text
F20 — Base de Mascotas (especies, niveles, EXP, alimentación, modificadores sin consumidor)
F21 — Expediciones definitivas: definiciones, duración/nivel mínimo,
      checkpoints, lazy resolution, catch-up, loot tables normalizadas
      (movido desde F23), fix del bug de claim()
F22 — Eventos de expedición: narrativo/cofre/enemigo/ayuda, decisiones simples
F23 — Balance: fórmula duración+dificultad→recompensa (§10), ajuste de
      probabilidades/pesos sobre las loot tables ya creadas en F21
F24 — UX: timeline de checkpoints, modal de decisión, alertas (§16), bitácora rica
F25 — Adquisición: huevos, gachapon, rarezas, duplicados, colección
F26 — Balance/contenido: 20 mascotas reales, nuevas expediciones/eventos, economía
```

## 19. Riesgos técnicos (consolidado)

1. Bug de concurrencia real en `claim()` — existe HOY, independiente de
   F20 (§3.4). Recomendado fix en F21, no antes (evitar tocar código de
   producción en esta tarea de auditoría).
2. Ambigüedad de backend real (VPS/MariaDB vs Render/Neon/Postgres) —
   confirmar antes de F21 (§3.10).
3. PgBouncer/Neon bajo locking real — no probado, plan de verificación
   barato en §12.2.
4. Migración de `pets.key` (string) → `pets.species_id` (FK) tiene datos
   reales que migrar (mascotas ya existentes con `key='starter'`) — F20
   necesita una migración de datos, no solo de esquema (crear la especie
   "starter" en `pet_species` y re-apuntar las filas existentes).
5. `pet_narrative_events` (108 filas) y `config/pet_events.php` (contenido
   real ya curado) se migran a `expedition_event_definitions` — trabajo
   de migración de datos no trivial pero mecánico, a planificar en F21.

## 20. Recomendaciones de testing

Siguiendo el patrón ya establecido en el proyecto (`DatabaseTransactions`,
overrides deterministas de configuración cuando aplique, nunca `sleep()`
real, timestamps controlados vía `Carbon::parse()`/`now()->subMinutes()`):

- **F20:** creación de especie → mascota con modificadores correctos;
  alimentación → EXP → level up; resolver de modificadores devuelve el
  milestone correcto según nivel (incluida la degradación cuando no hay
  milestone para el nivel exacto).
- **F21:** planificación de checkpoints (cantidad/`scheduled_at`
  correctos, sin payload); resolución de checkpoints vencidos en orden;
  catch-up tras un gap largo (crear checkpoints con `scheduled_at` en el
  pasado directamente en el test, sin `sleep`); **test de concurrencia
  explícito** simulando dos llamadas a `claim()`/`decide()` sobre la
  misma expedición dentro de la misma transacción de test, confirmando
  que el loot se concede una sola vez.
- **F22:** cada `type` de evento con su `config_json` produce el
  resultado esperado; decisión inválida rechazada; checkpoint ya resuelto
  no vuelve a aplicar consecuencias.

---

*Este documento reemplaza cualquier entendimiento previo informal del
sistema de Pets/Expeditions. Toda implementación futura de F20+ debe
referenciarlo explícitamente.*

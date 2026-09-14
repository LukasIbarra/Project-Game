#!/bin/sh
set -e

# Render inyecta $PORT en runtime (no está disponible en build) — Apache
# viene configurado para escuchar en 80 por default, así que se reescribe
# acá, cada vez que arranca el contenedor.
PORT="${PORT:-80}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# ============================================================================
# BLOQUE TEMPORAL DE DIAGNÓSTICO — investigando SQLSTATE[25P02] en
# users_email_unique (ver docs/RENDER_DEPLOYMENT_ANALYSIS.md). Revertir
# quitando todo entre BEGIN/END DIAGNOSTIC (y volver `-vvv` a nada) una vez
# resuelto. Solo lectura -ninguna sentencia acá escribe nada-, y no puede
# tumbar el arranque: cada query va en su propio try/catch, y el bloque
# entero tiene `|| true` al final para que, pase lo que pase acá, se siga
# igual a la migración real de abajo.
# ============================================================================
# BEGIN DIAGNOSTIC
#
# Ronda 3 de diagnóstico. Rondas anteriores ya descartaron: tabla
# duplicada, search_path, conexión/credenciales, y el SQL/Blueprint en sí
# (CREATE TABLE + ALTER TABLE UNIQUE corrido SUELTO -sin transacción
# explícita- funcionó perfecto). El dato nuevo es que el Migrator SIEMPRE
# envuelve `up()` en `Connection->transaction()`
# (Migrator.php:440 en el stack trace real) — así que ahora se reproduce
# ESO puntualmente: la misma secuencia, pero dentro de una transacción
# explícita, en dos variantes (DB::transaction() y beginTransaction()/
# commit() manual), para aislar si el problema es "transacción en general"
# o algo específico de cómo el Migrator la maneja.
#
# Tablas usadas: `_diagnostic_users_transaction_test` y
# `_diagnostic_users_transaction_test2` -nunca `users`-, creadas y
# borradas por este mismo bloque. No se toca ninguna tabla real.
cat > /tmp/diagnose.php <<'PHP'
$queryLog = [];
DB::listen(function ($query) use (&$queryLog) {
    $entry = ['sql' => $query->sql, 'bindings' => $query->bindings, 'time_ms' => $query->time];
    $queryLog[] = $entry;
    echo '[QUERY] ' . $query->time . 'ms | ' . $query->sql . ' | bindings=' . json_encode($query->bindings) . "\n";
});

\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function ($e) {
    echo "[TX EVENT] BEGIN (connection: {$e->connectionName})\n";
});
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($e) {
    echo "[TX EVENT] COMMIT (connection: {$e->connectionName})\n";
});
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function ($e) {
    echo "[TX EVENT] ROLLBACK (connection: {$e->connectionName})\n";
});

function printExceptionChain(\Throwable $e): void {
    $current = $e;
    $level = 0;
    while ($current && $level < 5) {
        $code = $current->getCode();
        echo "  [{$level}] " . get_class($current) . ": " . $current->getMessage() . " (code={$code})\n";
        if ($current instanceof \Illuminate\Database\QueryException) {
            echo "      SQL: " . $current->getSql() . "\n";
            echo "      Bindings: " . json_encode($current->getBindings()) . "\n";
        }
        $current = $current->getPrevious();
        $level++;
    }
}

echo "=== DIAGNOSTIC: estado antes de cualquier transaccion ===\n";
foreach (DB::select('select txid_current() as txid') as $row) {
    echo '  txid_current() = ' . $row->txid . "\n";
}
foreach (DB::select('select current_database() as db, current_user as usr, current_schema() as schema') as $row) {
    echo '  db=' . $row->db . ' user=' . $row->usr . ' schema=' . $row->schema . "\n";
}

echo "\n=== DIAGNOSTIC: TEST 1 -- DB::transaction() + Schema::create con unique (igual que Migrator::runMigration) ===\n";
Schema::dropIfExists('_diagnostic_users_transaction_test');

$test1Ok = false;
try {
    DB::transaction(function () {
        echo "  [dentro de la transaccion] ejecutando Schema::create...\n";
        Schema::create('_diagnostic_users_transaction_test', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        foreach (DB::select('select txid_current() as txid') as $row) {
            echo '  [dentro de la transaccion] txid_current() = ' . $row->txid . "\n";
        }
    });
    $test1Ok = true;
    echo "=== DIAGNOSTIC: TEST 1 EXITO ===\n";
} catch (\Throwable $e) {
    echo "=== DIAGNOSTIC: TEST 1 FALLO -- reproduce fielmente el comportamiento del Migrator real ===\n";
    printExceptionChain($e);
}

echo "  verificando si la tabla quedo creada: ";
foreach (DB::select("select to_regclass('public._diagnostic_users_transaction_test') as t") as $row) {
    echo ($row->t ?? 'NULL') . "\n";
}
try {
    Schema::dropIfExists('_diagnostic_users_transaction_test');
} catch (\Throwable $e) {
    echo "  (no se pudo limpiar _diagnostic_users_transaction_test: " . $e->getMessage() . ")\n";
}

if ($test1Ok) {
    echo "\n=== DIAGNOSTIC: TEST 2 -- beginTransaction()/commit() manual, CREATE y ALTER como statements separados ===\n";
    Schema::dropIfExists('_diagnostic_users_transaction_test2');
    try {
        DB::beginTransaction();
        echo "  [manual] beginTransaction() OK\n";
        Schema::create('_diagnostic_users_transaction_test2', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email'); // sin ->unique() aca -se agrega abajo como ALTER separado, a mano-
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        DB::statement('alter table "_diagnostic_users_transaction_test2" add constraint "_diagnostic_users_transaction_test2_email_unique" unique ("email")');
        DB::commit();
        echo "=== DIAGNOSTIC: TEST 2 EXITO ===\n";
    } catch (\Throwable $e) {
        echo "=== DIAGNOSTIC: TEST 2 FALLO ===\n";
        printExceptionChain($e);
        try {
            DB::rollBack();
            echo "  rollback manual ejecutado\n";
        } catch (\Throwable $e2) {
            echo "  rollback tambien fallo: " . $e2->getMessage() . "\n";
        }
    }
    try {
        Schema::dropIfExists('_diagnostic_users_transaction_test2');
    } catch (\Throwable $e) {
        echo "  (no se pudo limpiar _diagnostic_users_transaction_test2: " . $e->getMessage() . ")\n";
    }
} else {
    echo "\n=== DIAGNOSTIC: TEST 2 omitido -- TEST 1 ya fallo y ya tenemos la reproduccion real ===\n";
}

echo "\n=== DIAGNOSTIC: total de queries capturadas = " . count($queryLog) . " ===\n";
echo "=== DIAGNOSTIC: fin del bloque ===\n";
PHP
php artisan tinker < /tmp/diagnose.php || echo "[DIAGNOSTIC] el bloque de diagnostico fallo al ejecutarse (ver arriba)"
rm -f /tmp/diagnose.php
# END DIAGNOSTIC
# ============================================================================

# Render Free no tiene Pre-Deploy Command ni shell/SSH ni one-off jobs -no
# hay forma de correr `migrate --force` por fuera del arranque del
# contenedor-, así que corre ACÁ, antes de servir tráfico. Usa las mismas
# variables de entorno que ya tiene el proceso (DB_CONNECTION=pgsql,
# DB_URL de Neon vía render.yaml) — no hace falta pasarlas aparte.
#
# Es seguro correrlo en cada arranque (deploy Y restart/spin-up desde
# sleep del free tier): las migraciones ya aplicadas quedan registradas
# en la tabla `migrations`, así que `migrate --force` sin nada pendiente
# es un no-op rápido, nunca las vuelve a correr. `set -e` (arriba) ya hace
# que un fallo acá aborte el entrypoint con su mismo exit code -nunca se
# levanta Apache con una migración fallida a medias-.
#
# -vvv y --path= TEMPORALES (ver bloque de diagnóstico arriba): -vvv para
# capturar el mayor detalle posible en los logs de Render, --path para
# correr SOLO esta migración puntual mientras se investiga el 25P02 -no
# las otras 23-. Revertir a `migrate --force` a secas (sin --path, todas
# las migraciones pendientes) una vez resuelto.
#
# Caveat conocido y aceptado (no hay forma de evitarlo sin Pre-Deploy/
# shell, que Free no ofrece): si Render llegara a solapar brevemente el
# contenedor viejo y el nuevo durante un deploy que agrega migraciones
# reales, ambos podrían intentar correr esa migración nueva al mismo
# tiempo -Postgres rechazaría el segundo CREATE/ALTER duplicado y ese
# contenedor fallaría al arrancar, por diseño (requisito: nunca ocultar un
# fallo de migración)-. Bajo riesgo en Free (una sola instancia, sin
# scaling horizontal), y de todas formas no hay otra opción disponible en
# este plan.
#
# TEMPORALMENTE COMENTADA a pedido explícito: todavía estamos
# diagnosticando (ronda 3, bloque de arriba), no queremos que esta corra
# todavía. Descomentar (quitar el "# " de la línea de abajo) cuando se
# confirme la causa raíz y se quiera reintentar la migración real.
php artisan migrate --path=database/migrations/0001_01_01_000000_create_users_table.php --force -vvv

# config:cache/route:cache leen env() UNA VEZ acá -ya con las variables
# reales de Render disponibles en el proceso-, no en build time (ahí
# todavía no existen). Verificado antes de escribir esto: no hay ningún
# env() fuera de archivos config/ en toda la app, así que cachear config
# no rompe nada en runtime.
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

exec "$@"

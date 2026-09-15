#!/usr/bin/env node
// Genera public/asset-versions.json: un hash corto de CONTENIDO por cada
// archivo real bajo public/assets/ y public/backgrounds/. Objetivo: poder
// dejar esos binarios con cache-control agresivo (immutable, 1 año) en
// Vercel sin que reemplazar un sprite/fondo/tileset deje a los usuarios
// que ya visitaron el sitio con la versión vieja para siempre -la URL
// solo cambia para el archivo que realmente cambió, todo lo demás
// conserva su caché intacto-. Corre solo (hook `prebuild` de npm, ver
// package.json), nunca a mano; el resultado NO se versiona en git (ver
// .gitignore) porque se regenera en cada build.
//
// No incluye manifest.json (vive dentro de assets/ pero se sirve con
// cache corto propio vía vercel.json -no tiene sentido versionar el
// archivo que a su vez contiene las versiones-) ni ningún otro .json.
import { createHash } from "node:crypto";
import { readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
const publicDir = join(__dirname, "..", "public");
const SCAN_DIRS = ["assets", "backgrounds"];
const HASH_LENGTH = 10;

function walk(dir, out) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      walk(full, out);
    } else {
      out.push(full);
    }
  }
}

function toPosixKey(p) {
  return p.split(sep).join("/");
}

const versions = {};

for (const scanDir of SCAN_DIRS) {
  const absoluteDir = join(publicDir, scanDir);
  const files = [];
  try {
    walk(absoluteDir, files);
  } catch {
    continue; // la carpeta no existe todavía -no rompe el build por eso-.
  }

  for (const file of files) {
    if (file.endsWith(".json")) continue; // manifest.json y similares: cache corto propio, no se versionan.

    const bytes = readFileSync(file);
    const hash = createHash("md5").update(bytes).digest("hex").slice(0, HASH_LENGTH);
    versions[toPosixKey(relative(publicDir, file))] = hash;
  }
}

writeFileSync(join(publicDir, "asset-versions.json"), JSON.stringify(versions));
console.log(`[generate-asset-versions] ${Object.keys(versions).length} archivos versionados -> public/asset-versions.json`);

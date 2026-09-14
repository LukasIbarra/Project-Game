import Phaser from "phaser";
import { BootScene } from "./scenes/BootScene";
import { PreloadScene } from "./scenes/PreloadScene";
import { WorldScene } from "./scenes/WorldScene";
import { RoomScene } from "./scenes/RoomScene";
import { BattleScene } from "./scenes/BattleScene";

// Fase 2: BootScene (chequeo de infra) -> PreloadScene (carga de assets)
// -> WorldScene (Mundo), RoomScene (Habitación personal) o BattleScene
// (Fase 10, replay de combate), según qué página llame a startGame. Un
// solo Phaser.Game/config reutilizado por las tres -no un bootstrap
// paralelo-, el registry es el mecanismo para decirle a BootScene/
// PreloadScene a qué escena final ir una vez cargados los assets.
// `sceneData` es el mismo mecanismo pero para datos ADICIONALES que la
// escena final necesita más allá del manifest (BattleScene necesita
// saber quién pelea contra quién y con qué eventos ya calculados por el
// backend) -PreloadScene los reenvía tal cual en su `scene.start()`.
export function startGame(
  parent: string,
  initialScene: "WorldScene" | "RoomScene" | "BattleScene" = "WorldScene",
  sceneData: Record<string, unknown> = {}
) {
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent,
    width: 900,
    height: 700,
    backgroundColor: "#1d1f27",
    pixelArt: true,
    scale: {
      // Fase 2 (fix): Scale.FIT estira el canvas por CSS para llenar el
      // contenedor, lo que casi nunca da un factor entero (ej. 1.105x) y
      // rompe el pixel art aunque tengamos pixelArt:true/image-rendering:
      // pixelated -> se ven filas de píxeles duplicadas/recortadas. Con
      // Scale.NONE el canvas se queda en su tamaño nativo (800x600, 1
      // píxel de fuente = 1 píxel de pantalla), sin estirado.
      mode: Phaser.Scale.NONE,
      autoCenter: Phaser.Scale.CENTER_BOTH,
    },
    scene: [BootScene, PreloadScene, WorldScene, RoomScene, BattleScene],
  });

  game.registry.set("initialScene", initialScene);
  game.registry.set("sceneData", sceneData);

  return game;
}

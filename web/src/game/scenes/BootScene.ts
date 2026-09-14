import Phaser from "phaser";
import { pingApi } from "../net/ApiClient";

/**
 * Fase 0: escena de verificación de infraestructura.
 * Se muestra brevemente y luego pasa el control a PreloadScene. La carga
 * del personaje NO depende de que este ping tenga éxito: el juego debe
 * poder mostrar al jugador incluso con el backend caído.
 */
export class BootScene extends Phaser.Scene {
  constructor() {
    super("BootScene");
  }

  create() {
    this.add
      .text(400, 260, "Fase 0: Phaser está corriendo", {
        fontFamily: "monospace",
        fontSize: "20px",
        color: "#ffffff",
      })
      .setOrigin(0.5);

    const statusText = this.add
      .text(400, 300, "Consultando API Laravel...", {
        fontFamily: "monospace",
        fontSize: "16px",
        color: "#9ad1ff",
      })
      .setOrigin(0.5);

    pingApi()
      .then((data) => {
        statusText.setText(`API OK: ${JSON.stringify(data)}`);
        statusText.setColor("#7CFC7C");
      })
      .catch((err) => {
        statusText.setText(`API sin respuesta: ${err.message}`);
        statusText.setColor("#ff6666");
      });

    this.time.delayedCall(800, () =>
      this.scene.start("PreloadScene", { targetScene: this.registry.get("initialScene") ?? "WorldScene" })
    );
  }
}

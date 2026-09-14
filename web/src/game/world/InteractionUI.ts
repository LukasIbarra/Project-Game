import Phaser from "phaser";

// Fase 5: UI mínima DENTRO del canvas de Phaser -permitida explícitamente
// para elementos propios del mundo (indicación "E", diálogo, nombres de
// NPC). No es HUD de la aplicación: no toca Astro, no usa el sistema de
// paneles de la Fase 4.1B. Fija a la cámara (scrollFactor 0) para que no
// se mueva con el mundo.
export class InteractionUI {
  private readonly promptText: Phaser.GameObjects.Text;
  private readonly dialogueContainer: Phaser.GameObjects.Container;
  private readonly dialogueTitle: Phaser.GameObjects.Text;
  private readonly dialogueBody: Phaser.GameObjects.Text;

  constructor(scene: Phaser.Scene) {
    const width = scene.scale.width;
    const height = scene.scale.height;

    this.promptText = scene.add
      .text(width / 2, height - 90, "", {
        fontFamily: "monospace",
        fontSize: "12px",
        color: "#7fb3b8",
        backgroundColor: "#0b0b0dcc",
        padding: { x: 6, y: 4 },
      })
      .setOrigin(0.5)
      .setScrollFactor(0)
      .setDepth(1000)
      .setVisible(false);

    const boxWidth = width - 80;
    const boxHeight = 90;
    const box = scene.add.rectangle(0, 0, boxWidth, boxHeight, 0x17171c, 0.95);
    box.setStrokeStyle(2, 0x3a3a44);
    box.setOrigin(0, 0);

    this.dialogueTitle = scene.add.text(12, 8, "", {
      fontFamily: "monospace",
      fontSize: "12px",
      color: "#7fb3b8",
    });

    this.dialogueBody = scene.add.text(12, 28, "", {
      fontFamily: "monospace",
      fontSize: "12px",
      color: "#ece8e3",
      wordWrap: { width: boxWidth - 24 },
    });

    this.dialogueContainer = scene.add
      .container(40, height - boxHeight - 24, [box, this.dialogueTitle, this.dialogueBody])
      .setScrollFactor(0)
      .setDepth(1000)
      .setVisible(false);
  }

  showPrompt(text: string): void {
    this.promptText.setText(text).setVisible(true);
  }

  hidePrompt(): void {
    this.promptText.setVisible(false);
  }

  get isDialogueOpen(): boolean {
    return this.dialogueContainer.visible;
  }

  showDialogue(title: string, lines: string[]): void {
    this.dialogueTitle.setText(title.toUpperCase());
    this.dialogueBody.setText(lines.join("\n"));
    this.dialogueContainer.setVisible(true);
    this.hidePrompt();
  }

  closeDialogue(): void {
    this.dialogueContainer.setVisible(false);
  }
}

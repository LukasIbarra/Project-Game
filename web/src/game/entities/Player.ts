import Phaser from "phaser";
import type { Manifest } from "../assets/manifest";
import { CharacterRenderer, type CharacterAppearance } from "./CharacterRenderer";

// Fase 3: Player representa únicamente al jugador (posición + su
// CharacterRenderer). Stats, combate, inventario y personalización
// (elegir apariencia) llegan en fases posteriores — la apariencia por
// defecto ("solo body:base") es un valor fijo temporal, no un sistema
// de personalización.
export const DEFAULT_APPEARANCE: CharacterAppearance = {
  body: "base",
};

const BASE_SCALE = 4;

export class Player {
  private readonly renderer: CharacterRenderer;

  constructor(
    scene: Phaser.Scene,
    x: number,
    y: number,
    manifest: Manifest,
    appearance: CharacterAppearance = DEFAULT_APPEARANCE
  ) {
    this.renderer = new CharacterRenderer(scene, x, y, manifest, appearance);
    this.renderer.setScale(BASE_SCALE);
  }

  setPosition(x: number, y: number): void {
    this.renderer.setPosition(x, y);
  }
}

// Corrección de UX: WASD interfería con el chat global (Phaser escucha el
// teclado a nivel de `window`, así que tipear en el input del chat también
// movía al personaje). En vez de bloquear las letras W/A/S/D a mano dentro
// del chat, cada escena chequea esto en su propio `update()` antes de leer
// el estado de las teclas de movimiento -mientras el foco esté en un
// input/textarea/contenteditable, el frame simplemente no lee WASD-. Al
// perder el foco (click afuera, blur, Escape en el input), el chequeo
// vuelve a dar `false` en el siguiente frame y el movimiento se reanuda
// solo, sin necesidad de un handler de foco/blur aparte.
export function isTypingInFormField(): boolean {
  const el = document.activeElement;
  if (!el) return false;

  const tag = el.tagName;
  return tag === "INPUT" || tag === "TEXTAREA" || (el as HTMLElement).isContentEditable;
}

// Fase 4.1: MOCK explícito y centralizado. Nivel, experiencia, monedas y
// recursos no existen en el backend todavía (Fase 2 dejó pendientes las
// columnas de stats, Fase 6 el inventario/recursos). Usado por el HUD y
// por Home para que ambos muestren el mismo dato de demo sin duplicarlo.
// Reemplazar por datos reales = borrar este archivo y sus imports, sin
// tocar el layout de los componentes que lo consumen.
export const MOCK_PLAYER_PROGRESS = {
  level: 1,
  expPercent: 35,
  coins: 120,
  resources: 8,
};

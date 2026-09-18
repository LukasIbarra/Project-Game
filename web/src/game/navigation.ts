// Fase 4.1B (NavBar): única fuente de verdad de las páginas reales de la
// app -href real, etiqueta amigable e ícono-. Extraída acá en la Fase 18
// para que Presence pueda derivar `current_map` de la ruta actual (y su
// nombre amigable para PlayersOnline.astro) sin inventar una segunda lista
// de mapas por separado; NavBar.astro sigue siendo el único que la
// renderiza como menú.
//
// Cada `href` (sin la barra inicial) debe coincidir exactamente con un
// caso de App\Enums\GameMap del backend -si se agrega una página nueva acá,
// hay que agregar el caso correspondiente ahí también.
import type { IconName } from "./iconNames";

export interface NavItem {
  href: string;
  label: string;
  icon: IconName;
}

export const NAV_ITEMS: NavItem[] = [
  { href: "/home", label: "Inicio", icon: "home" },
  { href: "/house", label: "Casa", icon: "house" },
  { href: "/pet", label: "Mascota", icon: "pet" },
  { href: "/arena", label: "Arena", icon: "arena" },
  { href: "/play", label: "Mundo", icon: "world" },
  { href: "/social", label: "Social", icon: "social" },
  { href: "/inventory", label: "Inventario", icon: "inventory" },
  { href: "/shop", label: "Tienda", icon: "shop" },
  { href: "/crafting", label: "Crafting", icon: "craft" },
  { href: "/ranking", label: "Ranking", icon: "ranking" },
];

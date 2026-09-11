# Testlig UI Design System

## Principles

- Light, airy, calm, modern — not childish, not dark-purple corporate.
- Brand first on the public homepage; one clear job per section.
- Prefer fewer surfaces over dense card stacks, badges, and icon grids.
- Touch targets ≥ 44×44 px on interactive controls.
- WCAG AA contrast for text and primary actions.
- JavaScript enhances navigation; content remains readable without it.

## Color tokens (`assets/styles/app.css`)

| Token | Role |
|-------|------|
| `--tl-ink` | Primary text (navy-gray) |
| `--tl-bg` | Page background (broken white / soft blue wash) |
| `--tl-surface` | Panels and forms |
| `--tl-brand` | Primary blue |
| `--tl-accent` | Soft purple accent (sparing use) |
| `--tl-success` | Mint / confirmation |
| `--tl-cta` | Coral CTA |
| `--tl-border` | Light gray-blue borders |

## Typography

- Display: Georgia / Palatino stack (local/system — no Google Fonts CDN).
- UI: Segoe UI / Candara / Calibri stack.
- Scale via `--tl-fs-*` tokens.

## Spacing, radius, shadow

- Spacing scale `--tl-space-1` … `--tl-space-8`
- Radius `--tl-radius-sm|md|lg`
- Shadows `--tl-shadow-sm|md` (single soft elevation, not multi-layer glow)
- Focus ring via `:focus-visible` and `--tl-focus`
- Motion `--tl-ease` with `prefers-reduced-motion` hard-stop
- Z-index layers: header / sidebar / drawer / overlay / toast

## Responsive breakpoints

- Mobile shell &lt; 1024px: bottom navigation (max 4 items)
- Tablet ≥ 768px: two-column panel content
- Desktop ≥ 1024px: sticky sidebar (collapsible)

## Shared Twig components

Under `templates/components/`: brand, button, card, public header/footer, section heading, progress, stat card, status chip, avatar, empty state, notification indicator, panel sidebar, panel topbar, mobile bottom navigation.

Panel chrome: `templates/layouts/panel_preview.html.twig` (composes the panel partials above).

## Auth `.page` contract

Existing account/auth forms keep `.page` with `box-sizing: border-box` and `max-width: 100%`. Do not “fix” overflow with `overflow-x: hidden` on the design system root.

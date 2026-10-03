---
version: 1
name: famfin
description: Family budget (Telegram Mini App + site). Adapted from the Coinbase DESIGN.md in awesome-design-md, re-mapped onto the "Отчётность" logo — a navy plate with a blue gradient swoosh. Calm, institutional finance — white canvas, one blue accent, navy hero cards, numbers in tabular figures.

colors:
  primary: "#2A62EC"          # logo gradient midpoint — every primary action
  primary-active: "#1F4FD0"
  primary-disabled: "#A9B9D6"
  primary-soft: "#EAF0FE"     # selected chips, soft badges
  gradient-start: "#2134C0"   # logo swoosh, dark end
  gradient-end: "#3688FC"     # logo swoosh, light end
  ink: "#141B29"              # logo plate; headings and dark surfaces
  body: "#4F5666"
  muted: "#6E7584"
  hairline: "#E1E4EA"
  hairline-soft: "#EEF0F4"
  canvas: "#FFFFFF"
  surface-soft: "#F5F6F8"     # page floor behind cards
  surface-strong: "#EEF0F4"   # secondary buttons, segmented controls
  surface-dark: "#141B29"
  surface-dark-elevated: "#1D2536"
  on-dark: "#FFFFFF"
  on-dark-soft: "#A9B0BE"
  semantic-up: "#0A8F5A"      # income, "left" — text colour first
  semantic-down: "#CF2F3F"    # expense, "over" — text colour first
  semantic-warn: "#B26A00"
  fixed: "#2A62EC"            # mandatory categories
  variable: "#E08B3A"         # variable categories

typography:
  family: "Inter, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"   # Coinbase Sans/Display substitute
  display: { size: 40px, weight: 600, letterSpacing: -1.2px, numbers: tabular }
  title: { size: 18px, weight: 600 }
  section: { size: 17px, weight: 600 }
  body: { size: 16px, weight: 400, lineHeight: 1.5 }
  body-sm: { size: 14px, weight: 400 }
  caption: { size: 13px, weight: 500 }
  overline: { size: 12px, weight: 600, letterSpacing: .6px, transform: uppercase }

rounded: { sm: 8px, md: 12px, lg: 16px, xl: 24px, pill: 999px }
spacing: { base: 4px, scale: [4, 8, 12, 16, 20, 24, 32, 48] }
elevation:
  flat: none                               # default
  hairline: "1px solid {colors.hairline}"  # cards on white
  soft: "0 4px 12px rgba(20,27,41,.06)"    # floating only (FAB, toasts, sheets)
---

## Principles

- **One accent.** `primary` blue carries every primary action, selected state and link. Green/red are semantic only (income/expense, left/over) and always come with words, not colour alone.
- **Navy heroes.** The balance and period heroes use `surface-dark` — the logo plate — with a faint gradient swoosh echo. White text, soft secondary text.
- **Pills for actions, 24px for containers.** Buttons, chips, segmented controls and badges are pills; cards are `xl`; inputs are `md`.
- **Hairlines over shadows.** Cards sit on `surface-soft` with a 1px hairline. Shadow only on things that float (FAB, sheets, toasts).
- **Calm numbers.** Large figures at weight 600 with negative tracking and tabular figures; never 800-black.
- **Touch first.** 44px minimum targets, 16px body text, safe-area padding.

## Components

- `button-primary`: primary bg, white text, pill, 48px high in sheets, 44px elsewhere; active → `primary-active`; disabled → `primary-disabled`.
- `button-secondary`: `surface-strong` bg, `ink` text, pill.
- `segmented`: `surface-strong` track, pill; selected segment white with hairline + soft shadow.
- `chip`: white, hairline, pill; selected → `primary-soft` bg + `primary` text + primary border.
- `card`: canvas, hairline, `xl` radius, 16–20px padding.
- `hero-dark`: `surface-dark`, `xl` radius, gradient swoosh decoration at 12% opacity.
- `progress`: 8px track `surface-strong`; fill green → warn amber → red by state, with text label.
- `input`: canvas, hairline, `md` radius, 48px; focus → 2px primary ring.

# Claude Design refinement prompt

> Paste everything below the rule into the **"Oast.sh API design review"** Claude Design
> project (270dd5d9-3d26-415c-b5ab-9be31c60c3fa). It syncs the design system with what
> production actually implements, then asks for the missing surfaces. Keep it with the
> repo so the design project and the codebase never drift silently.

---

The terminal-kiln system from this project is now implemented in production (Laravel
Blade + Tailwind 4). This prompt does two things: (1) reconciles this design system
with decisions made during implementation, so future designs use the shipped
vocabulary; (2) commissions the surfaces the product still needs. Treat the shipped
codebase as ground truth for naming.

## 1. Adopt the production token names

The numbered tokens were renamed by role during implementation. Update `tokens/colors.css`,
every component, and the guidelines to use these names (values unchanged):

- Surfaces: `--bg-0/1/2` → `--color-surface` (#171310) / `--color-raised` (#1e1915) / `--color-sunken` (#12100c)
- Borders: `--border-1/0/row/input` → `--color-edge` / `--color-edge-soft` / `--color-hairline` / `--color-edge-strong`
- Text ladder (brightest→faintest): `--text-1/body/2/3/4` → `--color-ink` / `--color-body` / `--color-muted` / `--color-subtle` / `--color-faint`
- `--neutral-flag` is retired: `--sev-consider` aliases `--color-subtle` directly.
- Unchanged: `--ember`, `--ember-bright`, `--amber`, `--copper`, `--text-on-accent` → `--color-on-accent`, voice tokens, gradients, glows, radii, `--shadow-frame`.

When you emit code with utility classes, use the Tailwind names production generates:
- Color: `bg-surface`, `bg-raised`, `bg-sunken`, `border-edge`, `border-edge-soft`,
  `border-hairline`, `border-edge-strong`, `text-ink`, `text-body`, `text-muted`,
  `text-subtle`, `text-faint`, `text-ember`, `text-amber`, `text-copper`,
  `bg-voice-a-wash`, `bg-voice-b-wash`; states: `text-success`/`border-success`
  (aliases amber), `text-danger` (aliases ember).
- Type roles (size+line-height tokens; compose with `font-serif`/`font-mono` and weight):
  `text-display` (+`text-display-mobile`), `text-display-light`, `text-headline`
  (+`text-headline-mobile`), `text-title`, `text-body-serif`, `text-quote`,
  `text-finding`, `text-mono-ui`, `text-mono-small`, `text-label`, `text-colhead`,
  `text-badge`; tracking: `tracking-label`, `tracking-colhead`, `tracking-badge`,
  `tracking-sev`.
- Structure: `rounded-badge/control/card/frame/pill`, `shadow-frame`, `max-w-page`
  (880px), `max-w-hero` (1160px), `h-control` (46px), `animate-fade-in`.

## 2. Implementation decisions to absorb (production reality)

- **Fonts are self-hosted** (Fontsource: "Newsreader Variable", "IBM Plex Mono").
  Remove the Google Fonts `@import` from `tokens/fonts.css`; reference the families by
  those names. No third-party font hosts in any future output.
- **Accessibility floors now bind the system:** readable copy never sits on `--color-faint`
  (it's ~3.1:1 on surface — decorative labels only; hints/footers use `--color-subtle`).
  Every interactive element has a `:focus-visible` ember outline. Color-only transitions
  respect `prefers-reduced-motion`. Bake these into the guidelines cards.
- **Responsive floors:** display type steps 38→54px and 32→42px at the md breakpoint;
  the findings table scroll-contains below 640px content width; the meta strip is
  2-col below md. Mobile passes are required deliverables, not afterthoughts.
- **Split findings in real data are prose.** Production renders any filled
  `disagreement` as a single-rail quote block (`data-split` when confidence=split).
  The two-voice layout is used only where editorial has extracted voices (currently
  the homepage explainer). Keep both variants in `SplitBlock`, but design the prose
  fallback as a first-class citizen, not a degraded state.
- **The wordmark** is implemented as `oast` in ink + `.sh` in ember, Plex Mono 600 — as specified. Still no logo; still don't draw one.
- **A `/why` essay page shipped** (nav: reviews / why / roadmap / notify me): a long-form
  first-person page using `o-label` + `o-headline` + serif body paragraphs at a ~65ch
  measure. It's prose-only by design; if you propose art direction for it, stay typographic.

## 3. Commission: surfaces the product needs next

Design these with the reconciled tokens, desktop + mobile, using real data shapes from
the repo (Publication/finding JSON as before):

1. **Error/empty surfaces:** 404 page; reviews index with zero publications; a review
   page whose finding list is empty (clean-spec celebration state — "the Council found
   nothing blocking" deserves personality).
2. **Form states:** email input invalid/error (currently just ember text below), the
   in-flight submit state, and the confirmed page (currently minimal — give it the
   amber confirm treatment plus a "what happens next" line).
3. **Mobile navigation:** the nav currently just wraps; decide whether links collapse,
   condense, or stay. Production now has **four** nav items (reviews / why / roadmap /
   notify me) — rule on whether they still fit inline on mobile or need condensing.
4. **M3 preview — the live review stream:** the next product milestone renders a
   running Council review from Server-Sent Events: panelists starting/finishing with
   timings and costs, judge phase, findings arriving. Design the streaming states of
   the DocketPanel (pending/running/done per panelist, the type-in animation policy,
   progress affordance `█░`) — this is the signature M3 surface and it should feel
   like watching the kiln work.
5. **Social/OG image direction:** one 1200×630 template for review pages (headline,
   spec name, severity counts, cost) in the terminal-kiln language.

## 4. Rules that keep the loop tight

- Emit class names from §1's utility vocabulary or the shipped component classes
  (`o-nav`, `o-btn`, `o-card`, `o-table`, `o-finding`, `o-meta`, `o-docket`, `o-sev-*`,
  `o-conf-*`) so translation to Blade is 1:1.
- No inline `style=` attributes in emitted code; no `!important`.
- No new hues: severity stays the heat scale; confidence stays weight/texture.
- Numbers stay verbatim mono (`$2.59 · 3m41s`); no emoji; sentence case everywhere
  except letterspaced mono badges.

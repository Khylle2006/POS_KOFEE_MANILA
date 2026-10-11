# UI consistency review

The interface retained its espresso, caramel, and cream identity, but individual
screens used different fonts, button styles, border colors, and spacing. Several
navigation controls looked interactive but were inaccessible to keyboard users.
The POS also showed workflow steps that did not correspond to its actual actions.

## Implemented improvements

- `css/brand.css` is the common token source for staff, sign-in, public pages, and
  the module preview. Inter is the UI font; Playfair Display remains the brand font.
- `css/workspace.css`, loaded by the shared sidebar, gives staff screens consistent
  headers, readable tables, form controls, primary actions, focus rings, and reduced
  motion support. Existing screen CSS still owns its layout and status colors.
- The sidebar uses real links, announces the current screen, removes closed
  sections from keyboard navigation, and traps focus inside the mobile drawer.
  Escape closes the mobile drawer without changing the desktop collapse preference.
- The POS uses native product buttons, searchable categories, explicit size states,
  recoverable catalog errors, offline feedback, readable order rows, and disabled
  checkout actions for an empty order. Category selection clears a previous search.
  The desktop and mobile layouts share the 1024px breakpoint.
- Sign-in has associated labels, browser autofill, and a password visibility toggle.
  Password recovery pages share its visual styling. Server authentication is unchanged.
- Public pages share brand tokens and touch targets. The storefront menu stays open
  when its icon is clicked and closes on Escape. The module preview has mobile navigation
  and clearly identifies its sample data.

## Maintenance

Reuse existing page, card, field, button, and table classes. Put brand changes in
`brand.css` and common staff rules in `workspace.css`. Avoid new screen-specific
font and color overrides. Use a real link for navigation and a button for actions.
Keep labels visible, name icon controls, and include a useful error recovery action.

## Verification and limits

Run `npm test` and `npm run build` from `POS`. UI regression tests use Node's built-in
test runner and need no new dependency. PHP lint and JavaScript syntax checks cover
the edited source. This PHP/JavaScript project has no lint or typecheck npm scripts.

Browser review covered the real sign-in and storefront, plus the staff navigation
and POS template rendered with isolated sample data at desktop and phone widths,
including the 1024px transition. Authenticated database workflows, real inventory
deduction, payroll, procurement, and payment processing were not executed. Review
those screens with their real roles and records before deploying.

No database schema, API, authentication policy, role access, payment calculation,
or dependency changes are part of this update.

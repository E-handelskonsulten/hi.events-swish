# Localization

## Default locale

| Setting | Where | Effect |
|---|---|---|
| `APP_LOCALE` | `backend/.env` | Fallback locale for API responses, framework validation messages, new accounts registered without a language, and organizer/system mails (new-order notice, Swish review and mass-refund mails, contact-form relay, account-deletion mails). |
| `VITE_DEFAULT_LOCALE` | frontend environment | Locale used by the web app when neither a `locale` cookie nor the browser language matches a supported locale. Also what `Register` sends as the new account's locale. |

Both default to `en` in code; this installation sets both to `se`. Buyers and organizers can still switch to English (or any other locale) with the language switcher, which stores a `locale` cookie that both backend and frontend honour first.

Supported codes: `en, de, fr, it, nl, hu, es, pt, pt-br, zh-cn, se, zh-hk, vi, tr, pl, sk, el`. Swedish is `se`; browsers sending `sv`, `sv-SE` or `sv_SE` are mapped to it on both sides (`LocaleService` / `LocaleHelper::resolveAlias` and `getSupportedLocale`).

## Formatting

- Backend money goes through `Currency::format()`, which now follows the active app locale (`sv_SE` → `1 234,50 kr`). Dates in mails, invoices and Liquid template tokens go through `LocaleHelper` (`3 oktober 2026`, `19:30`, `lör 3 okt 2026 · 19:30`). `Carbon::setLocale` is kept in sync with `App::setLocale` in `AppServiceProvider`, so per-recipient mail locales get Swedish month and day names.
- Frontend money and numbers use `getIntlLocale()` (`se` → `sv-SE`), and dayjs registers the Swedish locale under the `se` code.
- Tests pin `APP_LOCALE=en` in `phpunit.xml` so assertions on English messages are independent of the installation default.

## Catalogs

- Backend: `backend/lang/se.json` (application strings) and `backend/lang/se/*.php` (Laravel validation, auth, password, pagination). Keys are the English source strings; the key must be the runtime string, so keys never contain `\'`.
- Frontend: `frontend/src/locales/se.po`, compiled to `se.js`. Run `npx lingui extract` after adding `t\`\`` / `<Trans>` strings and `npx lingui compile` before building; both run inside the `frontend` container.

Terminology: order = *order* (never *beställning*), event = *evenemang*, attendee = *deltagare*, checkout = *kassan*, check-in = *incheckning*, organizer = *arrangör*, waitlist = *väntelista*, promo code = *kampanjkod*. Swish and BankID are never translated.

## Known gaps

- The embed script `frontend/src/embed/widget.js` (copied to `frontend/public/widget.js`) has no access to the catalogs; it carries a two-language dictionary (English / Swedish, chosen from the host page's `lang` or the browser language) for its few own strings.
- The mail footer `© … Hi.Events | Powered by Hi.Events` in `resources/views/vendor/mail/html/message.blade.php` is upstream branding kept in English (AGPL retention note in that file).
- The superadmin area (`frontend/src/components/routes/admin/**`) and Stripe brand names in the payouts capability list are left in English.

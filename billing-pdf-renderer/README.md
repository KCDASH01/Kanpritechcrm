# Growneq billing PDF renderer

Server-side React-PDF renderer used by the Laravel CRM API. Its five invoice
themes and receipt layouts are adapted from the existing Synq React-PDF
templates. Laravel supplies organization-scoped immutable snapshots through
stdin and receives PDF bytes on stdout.

```bash
npm ci
npm run check
npm run build
```

The Laravel process executes `node dist/render.mjs`. Runtime dependencies remain
external so React-PDF can resolve its packaged color-profile and font assets.
Diagnostic output belongs
on stderr; stdout is reserved exclusively for PDF bytes.

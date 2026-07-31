# Program config — răspunsurile proprietarului (22 iulie 2026)

Răspunsurile lui Andrei la cele 3 întrebări de la startul sesiunii (conform promptului-program v1.1):

## 1. Site-uri pilot (fix-uri AI, webp)

- **notificarialimente.ro**
- **universulsacru.ro** (adăugat la STOP-ul Fazei B, 22 iul)

## 2. Screaming Frog headless

- **Host:** dasher (serverul actual al Manager-ului, 46.225.98.92) — containerizat.
- **Licență:** disponibilă pentru instalare pe server. Termenii de seat se verifică înainte de
  instalare (Faza D2). Cheia de licență intră DOAR în env-ul serverului, niciodată în repo.

## 3. Cheie Anthropic pentru generarea AI

- **Nu există încă în .env-ul de producție.** Andrei o adaugă la Faza D, înainte de testele pe
  site-ul pilot. Devine **precondiție pentru D4** (generarea fix-urilor AI); până atunci,
  dezvoltarea D4 se face cu serviciul mock-uit în teste.

## Decizii deja luate (nu se rediscută — din promptul-program)

- Modulul SEO existent va fi ÎNLOCUIT de modulul unificat SEO/Audit (metodologia celor 82 de verificări).
- Screaming Frog rulează automatizat pe server (headless, licențiat); upload manual = doar fallback.
- Fix-urile AI se aplică DOAR după validare umană per fix (cu bulk-select), prin conector, cu backup + rollback.
- Nu se clonează plugin-uri WPMU DEV Pro — doar inspirație funcțională + orchestrare de plugin-uri gratuite canonice.

Aprobator: Andrei. La fiecare STOP se așteaptă OK explicit în chat.

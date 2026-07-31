# R1 — Analiza suitei WPMU DEV (live, 22 iulie 2026)

Sursă: fetch direct pe `wpmudev.com/plugins/`, paginile individuale de produs, `hub-welcome`,
Terms of Service + căutări de verificare. Comparația pe partea Manager se bazează pe
`docs/plan/inventar.md` (HEAD `0351c29`). Unde o informație nu a putut fi confirmată, e marcat explicit.

---

## 1. Catalogul WPMU DEV la zi

Confirmat pe pagina live: **12 plugin-uri Pro + The Hub Client** (identic cu constatarea anterioară).
Prețuri: model per-plugin (Basic 1 site / Standard 3 / Plus 10 / Premium nelimitat, ~3–20 $/lună),
promoție 40% „lifetime" activă în iulie 2026. Membership-ul complet include toate plugin-urile + CDN + stocare backup.

| # | Plugin | Funcții cheie (verificate pe pagină) | Echivalent gratuit orchestrabil (wordpress.org) |
|---|--------|--------------------------------------|--------------------------------------------------|
| 1 | **Smush Pro** (v4.2.0, iul 2026) | Compresie Ultra 5X, conversie **WebP + AVIF** (v3.18, mai 2025), CDN 119 puncte, lazy load, **preload imagini critice pentru LCP** (v3.19), Directory Smush, bulk în background | `wp-smushit` (free: fără CDN, fără WebP, limită 5MB/50 imagini bulk) |
| 2 | **Hummingbird Pro** (v3.20.0, iul 2026) | Page/browser/Gravatar cache, minify/combine CSS+JS, **Critical CSS + delay JS** (Pro), integrare Cloudflare APO, CDN, uptime, curățare DB, **Safe Mode** pentru testat configurări | `hummingbird-performance` (free include uptime de bază, rapoarte, DB cleanup; fără delay JS/Critical CSS) |
| 3 | **Defender Pro** (v6.1.0, iul 2026) | **Scanare malware** (cod suspect, injecții, pluginuri abandonate), firewall **AntiBot global + Malicious Bot Detector** (nou în 6.1), **geo-blocking pe țară**, 2FA cu **biometrie**, login masking, pwned-password check, audit log, Google Blocklist monitoring | `defender-security` (free: firewall de bază, 2FA, login protection; scanarea malware și vulnerabilități = Pro) |
| 4 | **Snapshot Pro** | Backup incremental programat, 5–50GB inclus (extensibil 1TB), destinații S3/GDrive/Dropbox/OneDrive/**Azure/Linode**, **restore selectiv (doar DB sau doar fișiere)**, retenție 50 zile, restore per-subsite multisite, inspecție resurse pre-backup | **nu există versiune free** (confirmat pe pagină) |
| 5 | **SmartCrawl Pro** | Audituri SEO + rapoarte white-label, sitemap XML/news/imagini, **150+ tipuri schema + builder**, autolinking intern, redirects cu **regex + geolocație**, analiză keyphrase în editor, site crawl, instant indexing. Fără feature-uri AI evidențiate (confirmat) | `smartcrawl-seo` (free: sitemap + title/meta; audit, crawl, autolinking = Pro) |
| 6 | **Branda Pro** (v3.4.31, iun 2026) | White-label complet wp-admin: login personalizat, meniu admin per rol, widget-uri dashboard, **email-uri de sistem rebranduite + SMTP + email log**, pagini maintenance/coming-soon/eroare DB brănduite, branding la nivel de rețea multisite | `branda-white-labeling` pe wordpress.org (versiune free există) |
| 7 | **Beehive Pro** (v3.5.2, apr 2026) | Dashboard-uri **GA4** în wp-admin, management **GTM**, statistici per-subsite multisite, access per rol, anonimizare IP (GDPR), white-label | `beehive-analytics` (free; GA4 API + multisite + GTM = Pro) |
| 8 | **Forminator Pro** | Forms/quiz/polls drag-and-drop, **Stripe subscriptions + PayPal**, **generare PDF din submisii**, e-signature, geolocation/autocomplete adrese, integrări (n8n, HubSpot, Mailchimp, Google Sheets, Slack…). Fără AI menționat | `forminator` (free foarte generos; payments/PDF/e-sign = Pro) |
| 9 | **Hustle Pro** (v7.8.13, mai 2026) | Popups/slide-ins/embeds/social share, triggere (exit-intent, scroll, AdBlock detect), targeting pe locație/device/comportament, integrare Zoho CRM + **Cloudflare Turnstile** (noi 2026) | `wordpress-popup` (Hustle free) |
| 10 | **Shipper Pro** (v1.2.16, **feb 2024 — aparent dormant**) | Migrare site API sau pachet, **extragere subsite din multisite**, pre-flight checks, înlocuire URL-uri, filtrare selectivă (fără spam/revizii) | `shipper` free există pe wp.org dar la fel de vechi; alternativa reală orchestrabilă: backup+restore propriu |
| 11 | **WPMU DEV Dashboard** | Agentul lor „conector": instalare/update plugin-uri 1-click din biblioteca lor, **SSO în wp-admin din Hub**, acces suport temporar, white-label, system info (PHP/MySQL). **Necesită API key** de cont | nu există pe wp.org — livrat doar din contul WPMU DEV |
| 12 | **Video Tutorials Pro** | 45+ tutoriale video WP white-label, embed în wp-admin/pagini/portal, upload video propriu, restricții pe rol, playlisturi | nu există free; API cu limită soft 1.000 domenii |
| 13 | **The Hub Client** | Portal de client white-label **pe domeniul agenției**: billing automatizat (Stripe), self-serve checkout, **suspendare site la neplată**, revânzare domenii (v2.2), live chat/tickets (HubSpot, Tawk.to, LiveChat) | prezența pe wp.org neconfirmată în căutări — livrare practic prin cont WPMU DEV |

Bonus în afara paginii /plugins/, dar relevant: **Broken Link Checker** (wordpress.org, gratuit,
deținut de WPMU DEV) — mod local sau **cloud pe serverele lor, gratuit cu cont Hub free**.
Nu figurează ca plugin Pro, deci constatarea anterioară „broken links = gap" rămâne, dar
echivalentul gratuit orchestrabil există și e chiar al lor.

## 2. The Hub — management centralizat (verificat pe hub-welcome + docs)

- **Updates + Automate**: update-uri automate plugin/temă/core cu **Safe Update** — screenshot
  homepage + până la 5 pagini înainte/după, **comparație vizuală de imagini** și alertă email la diferențe.
- **Backups**: offsite automat, „până la 720 puncte de restore", stocare multi-facility.
- **Uptime**: monitorizare programată + alerte instant.
- **Security**: scanări programate, sugestii, hardening (motorul Defender).
- **Performance**: monitorizare + optimizare site/assets/imagini (motorul Hummingbird/Smush).
- **SEO**: audituri și tooling (motorul SmartCrawl).
- **Plugin & theme management**: instalare/administrare bulk cross-fleet.
- **Client & Billing**: facturare integrată, abonamente client, suspendare automată.
- **White-label reports** programate + **analytics white-label** în timp real.
- **Team**: roluri custom, 2FA, acces granular per client/site.
- **SSO 1-click** în wp-admin-ul oricărui site conectat.
- **Site notes**, etichete, filtrare, activity log; conectează „orice site, găzduit oriunde".

## 3. Matricea: funcționalitate → SimpleAd Manager → oportunitate

| Funcționalitate WPMU DEV | SAM are? | Oportunitate | Argument scurt | Effort |
|---|---|---|---|---|
| Uptime + alerte + incidente | **DA** (praguri per monitor, escaladare; multi-locație pregătit) | Nu | paritate sau peste Hub | — |
| Backup incremental + offsite + restore | **DA** (v3, manifest, verificare A/B, staged restore) | Nu ca feature nou | peste Snapshot pe verificare; sub el la restore selectiv DB-only/files-only | S–M dacă vrem restore selectiv |
| Safe updates cu rollback | **DA** (+ health-check + feed Wordfence) | Parțial | Hub adaugă **diff vizual pe screenshot-uri** — extensie naturală la health-check-ul existent | M |
| Instalare plugin din slug wp.org | **NU** (conectorul n-are install-from-slug) | **DA** | deblocează orchestrarea oricărui plugin free (Smush, BLC etc.); deja țintă în inventar | **S** |
| SSO 1-click în wp-admin | **NU** (avem rute users, nu login token) | **DA** | câștig zilnic mare pentru operator; HMAC-ul existent face token de login semnat trivial | **S–M** |
| Optimizare imagini (WebP/AVIF, lazy) | **NU** (doar măsurare PSI/LCP) | **DA** | gap confirmat; orchestrezi Smush free sau procesare proprie; acceptanță webp deja planificată (Faza E) | **M** |
| Scanare malware pe conținut | **NU** (doar integritate core/teme + vulnerabilități) | **DA** | gap confirmat; scanner de semnături pe fișiere prin conector; marcat „bifabil" în inventar (Faza E) | **M–L** |
| Geo-blocking / WAF | Parțial (IP ban/whitelist, fără geo) | **DA** | via Cloudflare API deja integrat (zone/purge) — reguli WAF/geo fără cod în WP | **S–M** |
| 2FA pe site-urile clienților | **DA** (email 2FA, conector 2.17.0) | Nu (opțional TOTP) | Defender are biometrie/TOTP; email-ul acoperă nevoia de bază | M dacă TOTP |
| Login masking / brute-force | Parțial (captcha, IP ban, hardening) | Posibil | masked login URL e cerere frecventă de client, se pretează la site-tweaks | S |
| Broken link checker | Parțial (`seo_links` în crawlerul vechi, modul în înlocuire) | **DA** | gap confirmat; de inclus în modulul SEO nou (R3) sau orchestrat BLC free (chiar al lor, cloud gratuit) | S (orchestrat) / M (propriu) |
| SEO: audit, sitemap, schema, redirects | Parțial (crawler propriu de înlocuit; redirects DA prin conector; schema NU) | DA — deja planificat | acoperit de autopsia R3 + Faza D; schema builder e diferențiatorul SmartCrawl | (în plan) |
| GSC / keyword rankings | **DA** (OAuth, rankings zilnice) | Nu | SmartCrawl nu are echivalent nativ — avantaj SAM | — |
| Analytics GA4 white-label (Beehive) | **NU** (avem GSC + PSI, nu GA4) | Posibil | secțiune GA4 în rapoartele PDF lunare, nu dashboard live | M |
| White-label wp-admin (Branda) | **NU** (admin-UX tweaks parțial) | **DA** | gap confirmat; branding agenție în wp-admin client = retenție; extindere site-tweaks existente | **M** |
| Rapoarte white-label programate | **DA** (PDF RO/EN Gotenberg) | Nu | paritate; de rescris doar secțiunea SEO (Faza D) | — |
| Portal client | **DA** (token-izat, revocare) | Nu ca gap | Hub Client e peste noi doar la… | — |
| **Billing client + suspendare la neplată** | **NU** (doar profitabilitate + planuri mentenanță interne) | **DA (strategic)** | gap confirmat; Stripe + facturi în portal + suspendare = monetizare directă; fundația (planuri, portal) există | **L** |
| Migrare / clonare site | Parțial (backup+restore staged, nu cross-site) | Posibil | „restore către alt site" = clonare/staging; Shipper e oricum dormant din 2024 | M |
| Site notes / activity log | Parțial (activity există; notes nu) | Nu prioritar | valoare mică | S |
| Formulare / marketing (Forminator, Hustle) | NU — în afara scopului | Nu ca modul; DA ca orchestrare | free-urile lor sunt generoase; SAM doar instalează/actualizează (cere install-from-slug) | — |
| Video tutoriale white-label | NU | Nu | valoare mică pentru fleet-ul actual | — |
| Revânzare domenii | NU (doar monitorizare expirare) | Nu acum | avem deja alerting pe expirare; reselling e alt business | — |

**Concluzie matrice**: paritate sau avantaj pe uptime / backup / safe-updates / rapoarte / GSC
(confirmă constatarea anterioară). Gap-urile reale confirmate: **imagini, malware pe conținut,
geo/WAF, white-label wp-admin, broken links, billing client**. Cele două „chei" ieftine care
deblochează restul: **install-from-slug** și **SSO semnat** în conector.

## 4. Capcane de licențiere (confirmate din Terms of Service, fetch direct)

1. **Codul e GPL** — citat: „All of our plugins and themes are GPL so you can keep using them
   for as long as you like and on as many sites as you like." Plugin-urile continuă să funcționeze
   „as is" și după expirarea membership-ului. Deci codul în sine e legal redistribuibil sub GPL 2.0.
2. **DAR livrarea e doar prin abonament**: plugin-urile Pro nu există pe wordpress.org; se descarcă
   din cont / prin WPMU DEV Dashboard. Fără abonament activ: **fără update-uri** — orchestrarea
   lor de către SAM ar însemna fleet cu plugin-uri Pro înghețate (risc de securitate).
3. **API key**: „You may only use your API key on sites that belong to you or those of your clients.
   You may not resell, share, or publish your API key." Nuanță importantă: folosirea cheii agenției
   **pe site-urile clienților e permisă** — capcana nu e interdicția, ci **dependența**: fiecare site ar
   avea WPMU DEV Dashboard instalat, vizibil în Hub-ul lor, cu limită soft de **1.000 domenii** pe
   API-urile dashboard/video.
4. **Serviciile cloud nu sunt GPL**: partea valoroasă din Pro trăiește pe serverele lor — compresia
   Smush/CDN, Critical CSS Hummingbird, listele AntiBot Defender, stocarea Snapshot, screenshot-urile
   Safe Update. Astea **nu pot fi redistribuite deloc**; un .zip GPL „de pe GPL-club" e o carcasă fără motor.
5. **Ce e liber orchestrabil de SAM** (fără cont WPMU DEV): versiunile de pe wordpress.org —
   Smush free, Hummingbird free, Defender free, SmartCrawl free, Forminator, Hustle, Beehive free,
   Branda free, **Broken Link Checker** (inclusiv modul cloud, gratuit cu cont Hub free — atenție:
   modul cloud reintroduce totuși o dependență de contul lor). Fără versiune free: Snapshot,
   Dashboard, Video Tutorials; Shipper free există dar e neîntreținut (2024).

**Recomandarea R1**: nu construi nimic pe plugin-urile Pro. Strategia corectă: conector propriu
(deja superior pe backup/uptime) + orchestrarea free-urilor wp.org unde umple un gap (Smush free,
BLC) + funcții proprii pentru gap-urile cu valoare de agenție (malware scan, geo/WAF prin
Cloudflare, white-label, billing).

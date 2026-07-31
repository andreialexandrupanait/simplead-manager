# R2 — Fișă tehnică & plan de orchestrare: „Modern Image Formats" (webp-uploads)

Data cercetării: 2026-07-22. Sursa principală: https://wordpress.org/plugins/webp-uploads/ (pagină live) + cod sursă din repo-ul WordPress/performance (GitHub, `plugins/webp-uploads/settings.php`).

---

## 1. Fișă tehnică la zi (iulie 2026)

| Atribut | Valoare |
|---|---|
| Slug wp.org | `webp-uploads` (fișier principal: `webp-uploads/load.php`) |
| Nume canonic | Modern Image Formats — WordPress Performance Team |
| Versiune curentă | **2.7.1** (actualizat cu ~3 săptămâni înainte de 2026-07-22) |
| Cerințe WP | **6.9+** (testat până la 7.0.2) |
| Cerințe PHP | **7.4+** |
| Instalări active | 100.000+ |

**Ce face:** la upload de imagine JPEG/PNG generează automat variante într-un format modern pentru toate mărimile (sizes). Implicit alege **AVIF dacă serverul îl suportă**, altfel **WebP**.

**Cerințe server pentru generare (critice pentru pre-check):**
- **WebP:** GD compilat cu libwebp (PHP 7.1+ pe majoritatea hostingurilor) sau Imagick cu delegat `WEBP`.
- **AVIF:** Imagick pe bază de **ImageMagick 7.0.25+ cu libheif/libavif** SAU **GD pe PHP 8.1+** cu `imageavif()` (libavif compilat). Multe shared hosting-uri încă nu au suport AVIF în 2026 — pluginul dezactivează singur AVIF (cade pe WebP) dacă suportul lipsește (inclusiv dacă lipsește suportul de transparență AVIF).
- WebP nu se generează dacă fișierul rezultat ar fi mai mare decât JPEG-ul original (comportament by design — nu e eroare).

**Setări (Settings → Media) și opțiunile lor `wp_options` (verificate în sursa pluginului):**

| Setare UI | Opțiune | Tip | Default | Valori |
|---|---|---|---|---|
| Format de ieșire | `perflab_modern_image_format` | string | `avif` | `avif` \| `webp` |
| Fallback în formatul original (JPEG + modern) | `perflab_generate_webp_and_jpeg` | bool | depinde de suportul temei pt. `<picture>` | true/false |
| Fallback pentru toate mărimile custom | `perflab_generate_all_fallback_sizes` | bool | false | true/false |
| Output `<picture>` cu fallback | `webp_uploads_use_picture_element` | bool | depinde de temă | true/false |

**Limitare cheie (confirmată pe pagina wp.org):** convertește **doar upload-urile NOI**. Media existentă rămâne în formatul vechi până la regenerare — recomandarea oficială: plugin „Regenerate Thumbnails" sau `wp media regenerate` (WP-CLI). Pe fleet-ul nostru **`shell_exec` e dezactivat pe hosturile țintă**, deci WP-CLI nu e o opțiune — vezi §4.

---

## 2. Pre-check per site — endpoint nou de capabilități media în conector

`class-info-endpoint.php` raportează azi doar WP/PHP/MySQL, memorie, upload max — **nimic despre biblioteci de imagine**. E nevoie de un endpoint nou (sau extindere `/info`), propunere: `GET /sam/v1/media-capabilities`, care să raporteze:

```php
[
  'editor'            => _wp_image_editor_choose(),            // 'WP_Image_Editor_Imagick' | 'WP_Image_Editor_Gd'
  'imagick'           => extension_loaded('imagick'),
  'imagick_version'   => Imagick::getVersion()['versionString'] ?? null,
  'imagick_formats'   => ['WEBP' => in_array('WEBP', Imagick::queryFormats()), 'AVIF' => in_array('AVIF', Imagick::queryFormats()), 'HEIC' => ...],
  'gd'                => extension_loaded('gd'),
  'gd_webp'           => function_exists('imagewebp'),
  'gd_avif'           => function_exists('imageavif'),         // doar PHP 8.1+
  'wp_supports_webp'  => wp_image_editor_supports(['mime_type' => 'image/webp']),
  'wp_supports_avif'  => wp_image_editor_supports(['mime_type' => 'image/avif']),  // testul autoritar — exact ce folosește pluginul
  'php_version'       => PHP_VERSION,
  'wp_version'        => $wp_version,                           // gate: >= 6.9 pentru webp-uploads 2.7.x
  'attachment_count'  => wp_count_attachments('image')->…,      // dimensionarea regenerării (val 2)
  'webp_uploads'      => ['installed' => bool, 'active' => bool, 'version' => ?, 'settings' => [cele 4 opțiuni din §1]],
  'max_execution_time'=> ini_get('max_execution_time'),
  'memory_limit'      => ini_get('memory_limit'),
]
```

**Decizia Manager-ului pe baza răspunsului:**
- `wp_supports_avif = true` → configurează AVIF + fallback WebP/JPEG.
- doar `wp_supports_webp` → configurează `perflab_modern_image_format = webp`.
- niciunul (rar) → site marcat „incompatibil", nu se instalează.
- `wp_version < 6.9` → blocat: webp-uploads 2.7.x nu se poate instala (wp.org va servi eventual o versiune veche compatibilă — de evitat implicit, sau acceptat explicit).

---

## 3. Orchestrare din conector — acțiune nouă „install plugin from wordpress.org slug"

**Stare actuală** (`wordpress-plugin/simplead-manager-connector/includes/endpoints/class-plugins-endpoint.php`): există `GET /plugins`, `POST /plugins/update|activate|deactivate|delete` + `/flush-opcache`. **NU există instalare după slug** — `validate_plugin_path()` cere ca pluginul să existe deja în `get_plugins()`, deci nu se poate „activa ceva neinstalat".

**Endpoint nou semnat: `POST /sam/v1/plugins/install`**
```php
// body: { "slug": "webp-uploads", "activate": true }
require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // plugins_api()
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
// validări: is_wp_error($api), slug sanitizat [a-z0-9-]+, plugin nu e deja instalat (altfel răspuns idempotent 'already_installed')
WP_Filesystem();
$upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$result   = $upgrader->install($api->download_link);
$plugin_file = $upgrader->plugin_info();          // ex. 'webp-uploads/load.php'
if ($activate) { activate_plugin($plugin_file); }
SAM_Audit_Logger::log('plugin_installed', 'plugin', $plugin_file, 'Installed from wp.org via SimpleAd Manager');
```
Securitate: aceeași semnătură HMAC (`check_permission` din `SAM_Endpoint_Base`), whitelist de slug-uri permise opțional pe partea Laravel (Manager-ul decide ce se instalează, conectorul doar execută), răspuns cu `plugin_file`, `version`, `activated`.

**Endpoint de configurare: `POST /sam/v1/plugins/webp-uploads/configure`** (sau generic `POST /options/set` cu whitelist strictă de opțiuni). Payload țintă:
```json
{ "perflab_modern_image_format": "avif",
  "perflab_generate_webp_and_jpeg": true,
  "webp_uploads_use_picture_element": true }
```
Regulă: setează `avif` doar dacă pre-check-ul §2 a confirmat `wp_supports_avif`; altfel `webp`. `perflab_generate_webp_and_jpeg = true` obligatoriu (fallback JPEG pentru clienți email/instrumente vechi), `picture element = on` pentru fallback corect în browser.

**Flux Laravel (Job în coadă, per convenții proiect):**
1. `GET /media-capabilities` → persistă în `sites` (jsonb, ex. `media_capabilities`).
2. `POST /plugins/install {slug: webp-uploads, activate: true}`.
3. `POST /plugins/webp-uploads/configure` cu formatul decis.
4. Re-citește `GET /media-capabilities` → confirmă `webp_uploads.active + settings` → stare vizibilă în Manager.

**Stare vizibilă în Manager:** badge per site pe pagina Site (Livewire): `Neinstalat / Incompatibil (fără AVIF&WebP) / Instalat-WebP / Instalat-AVIF / Eroare`, plus data instalării și formatul activ — sursă: coloana jsonb + audit log-ul conectorului.

---

## 4. Val 2 (opțional): regenerarea mediei istorice — fără shell_exec

`wp media regenerate` e exclus (`shell_exec` dezactivat pe hosturi — regulă de proiect). Alternative:

**Opțiunea recomandată — job batched în conector, orchestrat de Manager:**
- Endpoint nou `POST /sam/v1/media/regenerate` cu `{ "batch_size": 5, "cursor": <last_attachment_id> }`.
- Per batch, în PHP pur: `$file = get_attached_file($id); wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));` — trecerea prin `wp_generate_attachment_metadata()` declanșează filtrele webp-uploads, deci variantele AVIF/WebP se generează exact ca la un upload nou.
- Răspuns: `{ processed, failed[], next_cursor, remaining }`. Manager-ul (Job Laravel cu re-dispatch) apelează în buclă până `remaining = 0`, cu backoff și retry pe eșec — respectă patternul „queue heavy operations".
- Alternativă fără endpoint nou: instalarea pluginului „Regenerate Thumbnails" (are REST API propriu, per-attachment GET/POST) — dar adaugă un plugin în plus pe fiecare site client și tot Manager-ul trebuie să facă bucla; endpointul propriu în conector e mai curat și semnat HMAC.

**Riscuri pe shared hosting (de tratat explicit):**
- **Timeouts:** `max_execution_time` mic + `set_time_limit()` deseori blocat → batch mic (3–5 imagini/request, AVIF e scump la encodare — de ordinul secundelor/imagine mare), buget de timp în endpoint (oprește batch-ul la ~20s și întoarce cursorul).
- **Memorie:** imagini mari + Imagick pot depăși `memory_limit` → skip + raportare per attachment, nu abort total.
- **Spațiu disc:** fiecare imagine capătă un set dublu de fișiere (original + AVIF/WebP pe fiecare size) — pre-check spațiu / avertisment; `attachment_count` din §2 dimensionează impactul.
- **Rate/load:** rulare la ore de trafic mic, un singur site simultan la început (pilot), pauză între batch-uri.
- **Idempotență:** re-rularea e sigură (metadata se regenerează), dar cursor persistat în Manager previne re-procesarea inutilă.

---

## 5. Măsurare before/after — pilot notificarialimente.ro

Se folosește modulul PageSpeed existent: `app/Services/PageSpeedService.php` (extrage `lcp` lab din audit-ul `largest-contentful-paint` și `field_lcp` din CrUX `LARGEST_CONTENTFUL_PAINT_MS`) + `app/Jobs/RunPerformanceTest.php`.

Protocol:
1. **Baseline:** rulează 3 teste PageSpeed (mobile + desktop) pe notificarialimente.ro ÎNAINTE de instalare; reține LCP (lab), scor Performance, byte weight imagini (audit `modern-image-formats` din Lighthouse va confirma direct oportunitatea).
2. Instalare + configurare (§3); pentru pilot, rulează și val 2 (§4) pe media existentă — altfel paginile existente nu se schimbă deloc și diferența va fi ~0 (limitarea „doar upload-uri noi").
3. Purge cache (endpointul `/cache` existent al conectorului + Cloudflare dacă e cazul).
4. **After:** aceleași 3 rulări la 24–48h; compară `lcp` lab imediat, `field_lcp` (CrUX) abia după ~28 de zile de date de teren.
5. Prag de succes propus: reducere byte weight imagini >30% și LCP lab îmbunătățit sau neutru; regresie → dezactivare plugin prin `POST /plugins/deactivate` existent (fișierele generate rămân pe disc, inofensive).

---

## Surse
- https://wordpress.org/plugins/webp-uploads/ (versiune 2.7.1, cerințe WP 6.9 / PHP 7.4, setări, limitarea „doar upload-uri noi", recomandarea Regenerate Thumbnails / `wp media regenerate`)
- https://raw.githubusercontent.com/WordPress/performance/trunk/plugins/webp-uploads/settings.php (numele exacte ale opțiunilor: `perflab_modern_image_format`, `perflab_generate_webp_and_jpeg`, `perflab_generate_all_fallback_sizes`, `webp_uploads_use_picture_element`)
- https://developer.wordpress.org/cli/commands/media/regenerate/ (comportament regenerare — echivalentul PHP: `wp_generate_attachment_metadata()`)
- https://wordpress.org/plugins/regenerate-thumbnails/ (alternativă REST, probleme cunoscute `set_time_limit()` pe shared hosting)
- Cod local: `wordpress-plugin/simplead-manager-connector/includes/endpoints/class-plugins-endpoint.php`, `class-info-endpoint.php`, `app/Services/PageSpeedService.php`

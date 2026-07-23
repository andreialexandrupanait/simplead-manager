<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The page-content collector (C1). Extracts the QUALITATIVE signals the AI
 * evaluation needs (visible text, headings, CTAs, forms, FAQ, prices, social
 * proof, JSON-LD, chat/WhatsApp) from a page's HTML — compact and capped to fit
 * the AI prompt (never the raw HTML). Port of src/lib/collectors/page-content.ts
 * (cheerio → DOMXPath).
 */
final class PageContentCollector
{
    public const MAX_VISIBLE_TEXT = 4000;

    public const MAX_HEADINGS_CONTENT = 40;

    public const MAX_CTAS = 20;

    public const MAX_CTA_TEXT = 80;

    public const MAX_FORMS = 6;

    public const MAX_FORM_FIELDS = 20;

    public const MAX_FAQ_SAMPLE = 15;

    public const MAX_PRICES = 20;

    public const MAX_SOCIAL_SAMPLE = 10;

    public const MAX_JSONLD_TYPES = 20;

    public const DEFAULT_MAX_PAGES = 6;

    private const CONTACT_RE = '/(contact|contacte|despre-noi|about)/i';

    private const ARTICLE_RE = '#(/blog|/articol|/articole|/news|/stiri|/resurse|/ghid|/journal)#i';

    private const PRODUCT_RE = '#(/produs|/product|/products|/p/|/item/)#i';

    private const CATEGORY_RE = '#(/categorie|/categorii|/category|/categories|/colectie|/colectii|/shop|/magazin|/produse|/c/)#i';

    private const SKIP_PROTOCOLS = '/^(mailto:|tel:|javascript:|data:|ftp:)/i';

    private const PRICE_RE = '/(?:\d[\d.\s]*(?:,\d{1,2})?\s*(?:lei|ron|€|\$|eur|usd)|(?:€|\$)\s*\d[\d.,\s]*)/iu';

    private const CTA_WORDS = '/(cump[ăa]r|comand[ăa]|adaug|solicit|cere|contact|rezerv|program|aboneaz|[îi]nscrie|trimite|descarc|afl[ăa]|vezi|apeleaz|sun[ăa]|ofert)/iu';

    private const CHAT_HINTS = '/(intercom|tawk|crisp|zendesk|livechat|drift|hubspot-messages|freshchat|smartsupp|chatwoot|zopim|tidio)/i';

    /** Classify a URL by path (homepage = path "/"). */
    public static function classifyUrl(string $url, ?string $origin = null): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === false) {
            return 'alta';
        }
        $path = (string) $path;
        if ($path === '/' || $path === '') {
            return 'homepage';
        }
        if (preg_match(self::CONTACT_RE, $path) === 1) {
            return 'contact';
        }
        if (preg_match(self::ARTICLE_RE, $path) === 1) {
            return 'articol';
        }
        if (preg_match(self::PRODUCT_RE, $path) === 1) {
            return 'produs';
        }
        if (preg_match(self::CATEGORY_RE, $path) === 1) {
            return 'categorie';
        }

        return 'alta';
    }

    /**
     * Pick a REPRESENTATIVE set: homepage + max 1 of each type
     * (categorie/produs/contact/articol), filled to $maxPages. Filters to 200 +
     * indexable + HTML when the SF data is present. The audit URL guarantees the
     * homepage is included.
     *
     * @param  list<array{url: string, status?: int|null, indexable?: bool, isHtml?: bool}>  $candidates
     * @return list<string>
     */
    public static function selectRepresentativePages(array $candidates, string $auditUrl, int $maxPages = self::DEFAULT_MAX_PAGES): array
    {
        $origin = UrlHelper::originOf($auditUrl);

        $eligible = array_values(array_filter($candidates, static function (array $c): bool {
            if (($c['status'] ?? null) !== null && $c['status'] !== 200) {
                return false;
            }
            if (($c['indexable'] ?? null) === false) {
                return false;
            }
            if (($c['isHtml'] ?? null) === false) {
                return false;
            }

            return preg_match('#^https?://#i', $c['url']) === 1;
        }));

        $seen = [];
        $chosen = [];
        $add = static function (string $url) use (&$seen, &$chosen): void {
            if ($url !== '' && ! isset($seen[$url])) {
                $seen[$url] = true;
                $chosen[] = $url;
            }
        };

        $homepage = null;
        foreach ($eligible as $c) {
            if (self::classifyUrl($c['url'], $origin) === 'homepage') {
                $homepage = $c['url'];
                break;
            }
        }
        $add($homepage ?? ($origin !== '' ? "{$origin}/" : UrlHelper::normalizeUrl($auditUrl)));

        foreach (['categorie', 'produs', 'contact', 'articol'] as $kind) {
            if (count($chosen) >= $maxPages) {
                break;
            }
            foreach ($eligible as $c) {
                if (! isset($seen[$c['url']]) && self::classifyUrl($c['url'], $origin) === $kind) {
                    $add($c['url']);
                    break;
                }
            }
        }

        foreach ($eligible as $c) {
            if (count($chosen) >= $maxPages) {
                break;
            }
            $add($c['url']);
        }

        return array_slice($chosen, 0, $maxPages);
    }

    /**
     * Collect one page's qualitative content. Never throws — a failure yields the
     * empty shape.
     *
     * @return array<string, mixed>
     */
    public function collect(string $url, ?string $classification = null): array
    {
        $requestUrl = UrlHelper::normalizeUrl($url);
        $empty = self::emptyEvidence($requestUrl, $classification ?? self::classifyUrl($requestUrl));

        try {
            $res = Http::get($requestUrl);
        } catch (ConnectionException) {
            return $empty;
        }

        $finalUrl = $res->effectiveUri() !== null && (string) $res->effectiveUri() !== '' ? (string) $res->effectiveUri() : $requestUrl;
        $status = $res->status();
        $html = $res->body();
        if (trim($html) === '') {
            return array_merge($empty, ['status' => $status, 'finalUrl' => $finalUrl]);
        }

        $classification ??= self::classifyUrl($finalUrl);

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $title = self::firstText($xp, '//head/title') ?? self::firstText($xp, '//title');
        $metaDescription = self::metaDescription($xp);
        $headings = self::headings($xp);
        $ctas = self::ctas($xp);
        $forms = self::forms($xp);
        $jsonLdTypes = self::jsonLdTypes($xp);
        [$hasChatWidget, $hasWhatsApp] = self::chatSignals($xp, $html);
        $faqSample = self::faqSample($xp);
        $hasFaq = self::nodeCount($xp, '//*['.self::ciContains('@class', 'faq').' or '.self::ciContains('@id', 'faq').']') > 0 || count($faqSample) >= 2;
        [$testimonials, $socialSample] = self::socialProof($xp);
        $logos = self::nodeCount($xp, self::logoXPath());

        // Visible text: strip noisy nodes, then read the body text.
        foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tag));
            foreach ($nodes as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        $bodyNode = $doc->getElementsByTagName('body')->item(0);
        $fullText = self::cleanText($bodyNode !== null ? $bodyNode->textContent : '');
        $textLength = mb_strlen($fullText);
        $visibleText = mb_substr($fullText, 0, self::MAX_VISIBLE_TEXT);
        $prices = self::prices($fullText);

        return [
            'requestedUrl' => $requestUrl,
            'finalUrl' => $finalUrl,
            'status' => $status,
            'classification' => $classification,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'headings' => $headings,
            'ctas' => $ctas,
            'forms' => $forms,
            'hasFaq' => $hasFaq,
            'faqSample' => $faqSample,
            'prices' => $prices,
            'socialProof' => ['testimonials' => $testimonials, 'logos' => $logos, 'sample' => $socialSample],
            'jsonLdTypes' => $jsonLdTypes,
            'hasChatWidget' => $hasChatWidget,
            'hasWhatsApp' => $hasWhatsApp,
            'visibleText' => $visibleText,
            'textLength' => $textLength,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyEvidence(string $requestUrl, string $classification): array
    {
        return [
            'requestedUrl' => $requestUrl,
            'finalUrl' => $requestUrl,
            'status' => null,
            'classification' => $classification,
            'title' => null,
            'metaDescription' => null,
            'headings' => [],
            'ctas' => [],
            'forms' => [],
            'hasFaq' => false,
            'faqSample' => [],
            'prices' => [],
            'socialProof' => ['testimonials' => 0, 'logos' => 0, 'sample' => []],
            'jsonLdTypes' => [],
            'hasChatWidget' => false,
            'hasWhatsApp' => false,
            'visibleText' => '',
            'textLength' => 0,
        ];
    }

    private static function cleanText(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Case-insensitive substring test on an attribute, as an XPath fragment. */
    private static function ciContains(string $attr, string $needle): string
    {
        return "contains(translate({$attr},'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'{$needle}')";
    }

    private static function firstNode(DOMXPath $xp, string $query, ?DOMNode $ctx = null): ?DOMNode
    {
        $nodes = $ctx !== null ? $xp->query($query, $ctx) : $xp->query($query);

        return $nodes !== false ? $nodes->item(0) : null;
    }

    private static function firstText(DOMXPath $xp, string $query): ?string
    {
        $node = self::firstNode($xp, $query);
        if ($node === null) {
            return null;
        }
        $text = trim($node->textContent);

        return $text !== '' ? $text : null;
    }

    private static function metaDescription(DOMXPath $xp): ?string
    {
        $nodes = $xp->query('//meta[@name]');
        if ($nodes === false) {
            return null;
        }
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->getAttribute('name')) === 'description') {
                $content = trim($node->getAttribute('content'));

                return $content !== '' ? $content : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{tag: string, text: string}>
     */
    private static function headings(DOMXPath $xp): array
    {
        $out = [];
        $nodes = $xp->query('//h1|//h2|//h3');
        if ($nodes === false) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (count($out) >= self::MAX_HEADINGS_CONTENT) {
                break;
            }
            $text = self::cleanText($node->textContent);
            if ($text !== '') {
                $out[] = ['tag' => strtolower($node->nodeName), 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function ctas(DOMXPath $xp): array
    {
        $ctas = [];
        $seen = [];
        $push = static function (string $text) use (&$ctas, &$seen): bool {
            if ($text === '' || mb_strlen($text) > self::MAX_CTA_TEXT) {
                return true;
            }
            $lower = mb_strtolower($text);
            if (isset($seen[$lower])) {
                return true;
            }
            $seen[$lower] = true;
            $ctas[] = $text;

            return count($ctas) < self::MAX_CTAS;
        };

        $btn = "contains(concat(' ',normalize-space(@class),' '),' btn ')";
        $button = "contains(concat(' ',normalize-space(@class),' '),' button ')";
        $cta = "contains(concat(' ',normalize-space(@class),' '),' cta ')";
        $ctaQuery = "//button | //a[{$btn} or {$button}] | //*[@role='button'] | //input[@type='submit'] | //input[@type='button'] | //*[{$cta} or {$btn} or {$button}]";
        $nodes = $xp->query($ctaQuery);
        if ($nodes !== false) {
            foreach ($nodes as $el) {
                if (count($ctas) >= self::MAX_CTAS) {
                    break;
                }
                $text = self::cleanText($el->textContent);
                if ($text === '' && $el instanceof DOMElement) {
                    $text = self::cleanText($el->getAttribute('value')) ?: self::cleanText($el->getAttribute('aria-label'));
                }
                if (! $push($text)) {
                    break;
                }
            }
        }

        // Action links (CTA-word text even without a button class).
        $links = $xp->query('//a[@href]');
        if ($links !== false) {
            foreach ($links as $el) {
                if (! $el instanceof DOMElement || count($ctas) >= self::MAX_CTAS) {
                    if (count($ctas) >= self::MAX_CTAS) {
                        break;
                    }

                    continue;
                }
                $href = trim($el->getAttribute('href'));
                if ($href === '' || preg_match(self::SKIP_PROTOCOLS, $href) === 1) {
                    continue;
                }
                $text = self::cleanText($el->textContent);
                if ($text === '' || preg_match(self::CTA_WORDS, $text) !== 1) {
                    continue;
                }
                if (! $push($text)) {
                    break;
                }
            }
        }

        return $ctas;
    }

    /**
     * @return list<array{fields: list<array{name: ?string, type: ?string, label: ?string, required: bool}>, submitText: ?string}>
     */
    private static function forms(DOMXPath $xp): array
    {
        $forms = [];
        $formNodes = $xp->query('//form');
        if ($formNodes === false) {
            return $forms;
        }
        foreach ($formNodes as $formEl) {
            if (count($forms) >= self::MAX_FORMS || ! $formEl instanceof DOMElement) {
                if (count($forms) >= self::MAX_FORMS) {
                    break;
                }

                continue;
            }
            $fields = [];
            $fieldNodes = $xp->query('.//input|.//textarea|.//select', $formEl);
            if ($fieldNodes !== false) {
                foreach ($fieldNodes as $f) {
                    if (count($fields) >= self::MAX_FORM_FIELDS || ! $f instanceof DOMElement) {
                        if (count($fields) >= self::MAX_FORM_FIELDS) {
                            break;
                        }

                        continue;
                    }
                    $type = strtolower($f->getAttribute('type') !== '' ? $f->getAttribute('type') : $f->nodeName);
                    if (in_array($type, ['hidden', 'submit', 'button'], true)) {
                        continue;
                    }
                    $name = $f->getAttribute('name') !== '' ? $f->getAttribute('name') : null;
                    $id = $f->getAttribute('id');
                    $label = null;
                    if ($id !== '') {
                        $lbl = self::firstNode($xp, './/label[@for='.self::xpathLiteral($id).']', $formEl);
                        if ($lbl !== null) {
                            $label = self::cleanText($lbl->textContent) ?: null;
                        }
                    }
                    if ($label === null) {
                        $label = trim($f->getAttribute('placeholder')) ?: trim($f->getAttribute('aria-label')) ?: null;
                    }
                    $fields[] = [
                        'name' => $name,
                        'type' => $type !== '' ? $type : null,
                        'label' => $label,
                        'required' => $f->hasAttribute('required') || $f->getAttribute('aria-required') === 'true',
                    ];
                }
            }
            $submit = self::firstNode($xp, ".//button[@type='submit']|.//input[@type='submit']|.//button[not(@type)]", $formEl);
            $submitText = null;
            if ($submit !== null) {
                $submitText = self::cleanText($submit->textContent)
                    ?: ($submit instanceof DOMElement ? self::cleanText($submit->getAttribute('value')) : '')
                    ?: null;
            }
            if ($fields !== [] || $submitText !== null) {
                $forms[] = ['fields' => $fields, 'submitText' => $submitText];
            }
        }

        return $forms;
    }

    /**
     * @return list<string>
     */
    private static function faqSample(DOMXPath $xp): array
    {
        $sample = [];
        $seen = [];
        $nodes = $xp->query('//h2|//h3|//h4|//summary|//dt');
        if ($nodes === false) {
            return $sample;
        }
        foreach ($nodes as $el) {
            if (count($sample) >= self::MAX_FAQ_SAMPLE) {
                break;
            }
            $q = self::cleanText($el->textContent);
            $len = mb_strlen($q);
            if ($len < 8 || $len > 200 || ! str_contains($q, '?')) {
                continue;
            }
            $lower = mb_strtolower($q);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $sample[] = $q;
        }

        return $sample;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private static function socialProof(DOMXPath $xp): array
    {
        $terms = ['testimonial', 'review', 'recenzi', 'parere', 'client'];
        $conds = array_map(static fn (string $t): string => self::ciContains('@class', $t), $terms);
        $query = '//*['.implode(' or ', $conds).']';
        $nodes = $xp->query($query);
        $testimonials = $nodes !== false ? $nodes->length : 0;

        $sample = [];
        if ($nodes !== false) {
            foreach ($nodes as $el) {
                if (count($sample) >= self::MAX_SOCIAL_SAMPLE) {
                    break;
                }
                $t = mb_substr(self::cleanText($el->textContent), 0, 160);
                if (mb_strlen($t) >= 15 && ! in_array($t, $sample, true)) {
                    $sample[] = $t;
                }
            }
        }

        return [$testimonials, $sample];
    }

    private static function logoXPath(): string
    {
        $terms = ['logo', 'clients', 'parteneri', 'brands'];
        $conds = array_map(static fn (string $t): string => '//*['.self::ciContains('@class', $t).']//img', $terms);

        return implode('|', $conds);
    }

    /**
     * @return list<string>
     */
    private static function jsonLdTypes(DOMXPath $xp): array
    {
        $set = [];
        $nodes = $xp->query("//script[@type='application/ld+json']");
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $el) {
            if (count($set) >= self::MAX_JSONLD_TYPES) {
                break;
            }
            $raw = trim($el->textContent);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if ($decoded !== null) {
                self::collectJsonLdTypes($decoded, $set);
            }
        }

        return array_slice(array_keys($set), 0, self::MAX_JSONLD_TYPES);
    }

    /**
     * @param  array<string, bool>  $out
     */
    private static function collectJsonLdTypes(mixed $node, array &$out): void
    {
        if (is_array($node) && array_is_list($node)) {
            foreach ($node as $item) {
                self::collectJsonLdTypes($item, $out);
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }
        $type = $node['@type'] ?? null;
        if (is_string($type)) {
            $out[$type] = true;
        } elseif (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t)) {
                    $out[$t] = true;
                }
            }
        }
        if (isset($node['@graph'])) {
            self::collectJsonLdTypes($node['@graph'], $out);
        }
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private static function chatSignals(DOMXPath $xp, string $html): array
    {
        $chatQuery = '//*['.self::ciContains('@class', 'chat').' or '.self::ciContains('@id', 'chat').' or '.self::ciContains('@class', 'messenger').']';
        $hasChat = preg_match(self::CHAT_HINTS, $html) === 1 || self::nodeCount($xp, $chatQuery) > 0;

        $hasWhatsApp = false;
        $links = $xp->query('//a[@href]');
        if ($links !== false) {
            foreach ($links as $el) {
                if (! $el instanceof DOMElement) {
                    continue;
                }
                $href = strtolower($el->getAttribute('href'));
                if (str_contains($href, 'wa.me') || str_contains($href, 'whatsapp') || str_contains($href, 'api.whatsapp')) {
                    $hasWhatsApp = true;
                    break;
                }
            }
        }

        return [$hasChat, $hasWhatsApp];
    }

    /**
     * @return list<string>
     */
    private static function prices(string $fullText): array
    {
        $prices = [];
        $seen = [];
        if (preg_match_all(self::PRICE_RE, $fullText, $matches) === false) {
            return [];
        }
        foreach ($matches[0] as $m) {
            if (count($prices) >= self::MAX_PRICES) {
                break;
            }
            $p = self::cleanText($m);
            $lower = mb_strtolower($p);
            if ($p === '' || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $prices[] = $p;
        }

        return $prices;
    }

    private static function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);

        return $nodes !== false ? $nodes->length : 0;
    }

    /** Safely quote a string for use as an XPath literal. */
    private static function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }
        $parts = explode("'", $value);

        return 'concat('.implode(", \"'\", ", array_map(static fn (string $p): string => "'{$p}'", $parts)).')';
    }
}

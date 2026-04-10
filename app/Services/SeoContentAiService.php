<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeoContentStatus;
use App\Models\SeoContent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeoContentAiService
{
    /**
     * Available providers and their models.
     *
     * @return array<string, array{label: string, models: array<string, string>}>
     */
    public static function availableProviders(): array
    {
        return [
            'anthropic' => [
                'label' => 'Anthropic Claude',
                'models' => [
                    'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
                    'claude-opus-4-20250514' => 'Claude Opus 4',
                    'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
                ],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'models' => [
                    'gpt-4o' => 'GPT-4o',
                    'gpt-4o-mini' => 'GPT-4o mini',
                    'gpt-4.1' => 'GPT-4.1',
                    'gpt-4.1-mini' => 'GPT-4.1 mini',
                    'gpt-4.1-nano' => 'GPT-4.1 nano',
                ],
            ],
        ];
    }

    /**
     * Get only providers that have an API key configured.
     *
     * @return array<string, array{label: string, models: array<string, string>}>
     */
    public static function configuredProviders(): array
    {
        $all = self::availableProviders();
        $configured = [];

        foreach ($all as $key => $provider) {
            if (self::getApiKey($key)) {
                $configured[$key] = $provider;
            }
        }

        return $configured;
    }

    public function generateArticle(SeoContent $content, ?string $trackerKey = null): SeoContent
    {
        $content->update(['status' => SeoContentStatus::Generating]);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 10, 'Building prompt...');
        }

        $systemPrompt = $this->buildSystemPrompt($content);
        $userMessage = $this->buildUserMessage($content);

        $provider = $content->ai_provider ?? 'anthropic';
        $model = $content->ai_model ?? $this->getDefaultModel($provider);

        if ($trackerKey) {
            $providerLabel = self::availableProviders()[$provider]['label'] ?? $provider;
            JobTracker::progress($trackerKey, 20, "Generating with {$providerLabel}...");
        }

        $result = $this->callProvider($provider, $model, $systemPrompt, $userMessage, $this->getMaxTokens($content));

        if (! $result) {
            $content->update(['status' => SeoContentStatus::Failed]);

            return $content;
        }

        // Parse the AI response to extract content and meta
        $parsed = $this->parseAiResponse($result);

        // Calculate SEO score
        $seoData = $this->calculateSeoScore($content, $parsed['content'], $parsed['meta_description']);

        $updateData = [
            'content' => $parsed['content'],
            'meta_description' => $parsed['meta_description'] ?: $content->meta_description,
            'status' => SeoContentStatus::Review,
            'word_count' => $seoData['word_count'],
            'keyword_density' => $seoData['keyword_density'],
            'seo_score' => $seoData['score'],
            'seo_score_data' => $seoData,
        ];

        // Update title if AI generated one and user didn't provide one
        if (! empty($parsed['title']) && (! $content->title || $content->title === $content->target_keyword)) {
            $updateData['title'] = $parsed['title'];
            $updateData['slug'] = Str::slug($parsed['title']);
        }

        $content->update($updateData);

        // Save revision
        $content->revisions()->create([
            'content' => $parsed['content'],
            'meta_description' => $parsed['meta_description'],
            'source' => 'ai',
            'generation_params' => [
                'provider' => $provider,
                'model' => $model,
                'target_word_count' => $content->target_word_count,
                'tone' => $content->tone,
            ],
        ]);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 95, 'Article generated successfully.');
        }

        return $content;
    }

    public function refineArticle(SeoContent $content, string $corrections, ?string $trackerKey = null): SeoContent
    {
        $content->update(['status' => SeoContentStatus::Generating]);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 20, 'Applying corrections...');
        }

        $provider = $content->ai_provider ?? 'anthropic';
        $model = $content->ai_model ?? $this->getDefaultModel($provider);

        $system = $this->buildSystemPrompt($content);

        $userMessage = "Am un articol existent care trebuie corectat/îmbunătățit.\n\n";
        $userMessage .= "## Articolul curent\n{$content->content}\n\n";
        $userMessage .= "## Corecții solicitate\n{$corrections}\n\n";
        $userMessage .= "Aplică corecțiile cerute și returnează articolul complet actualizat. Păstrează structura și stilul, dar aplică modificările solicitate.\n";
        $userMessage .= "La final, adaugă din nou:\n---TITLE---\nTitlul actualizat\n---META---\nMeta description actualizată";

        $result = $this->callProvider($provider, $model, $system, $userMessage, $this->getMaxTokens($content));

        if (! $result) {
            $content->update(['status' => SeoContentStatus::Review]);

            return $content;
        }

        $parsed = $this->parseAiResponse($result);
        $seoData = $this->calculateSeoScore($content, $parsed['content'], $parsed['meta_description']);

        $updateData = [
            'content' => $parsed['content'],
            'meta_description' => $parsed['meta_description'] ?: $content->meta_description,
            'status' => SeoContentStatus::Review,
            'word_count' => $seoData['word_count'],
            'keyword_density' => $seoData['keyword_density'],
            'seo_score' => $seoData['score'],
            'seo_score_data' => $seoData,
        ];

        if (! empty($parsed['title'])) {
            $updateData['title'] = $parsed['title'];
            $updateData['slug'] = Str::slug($parsed['title']);
        }

        $content->update($updateData);

        $content->revisions()->create([
            'content' => $parsed['content'],
            'meta_description' => $parsed['meta_description'],
            'source' => 'ai',
            'generation_params' => [
                'provider' => $provider,
                'model' => $model,
                'corrections' => $corrections,
            ],
        ]);

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 95, 'Corrections applied.');
        }

        return $content;
    }

    /**
     * Calculate on-page SEO score for given content.
     *
     * @return array{score: int, word_count: int, keyword_density: float, checks: array}
     */
    public function calculateSeoScore(SeoContent $content, ?string $htmlContent = null, ?string $metaDesc = null): array
    {
        $html = $htmlContent ?? $content->content ?? '';
        $meta = $metaDesc ?? $content->meta_description ?? '';
        $keyword = mb_strtolower($content->target_keyword ?? '');

        // Strip tags for plain text analysis
        $text = strip_tags($html);
        $textLower = mb_strtolower($text);
        $wordCount = str_word_count($text);

        // Keyword density
        $keywordCount = $keyword ? mb_substr_count($textLower, $keyword) : 0;
        $density = $wordCount > 0 && $keyword ? round(($keywordCount / $wordCount) * 100, 2) : 0.0;

        $checks = [];
        $score = 100;

        // Title contains keyword
        $titleLower = mb_strtolower($content->title ?? '');
        if ($keyword && ! str_contains($titleLower, $keyword)) {
            $checks[] = ['check' => 'keyword_in_title', 'status' => 'fail', 'message' => 'Keyword missing from title'];
            $score -= 10;
        } else {
            $checks[] = ['check' => 'keyword_in_title', 'status' => 'pass', 'message' => 'Keyword found in title'];
        }

        // Meta description length
        $metaLen = mb_strlen($meta);
        if ($metaLen === 0) {
            $checks[] = ['check' => 'meta_description', 'status' => 'fail', 'message' => 'Missing meta description'];
            $score -= 10;
        } elseif ($metaLen > 160) {
            $checks[] = ['check' => 'meta_description', 'status' => 'warn', 'message' => "Meta description too long ({$metaLen}/160)"];
            $score -= 5;
        } elseif ($metaLen < 80) {
            $checks[] = ['check' => 'meta_description', 'status' => 'warn', 'message' => "Meta description too short ({$metaLen}/80)"];
            $score -= 5;
        } else {
            $checks[] = ['check' => 'meta_description', 'status' => 'pass', 'message' => "Meta description OK ({$metaLen} chars)"];
        }

        // Word count
        $targetWords = $content->target_word_count ?? 1000;
        if ($wordCount < $targetWords * 0.7) {
            $checks[] = ['check' => 'word_count', 'status' => 'warn', 'message' => "Content too short ({$wordCount}/{$targetWords})"];
            $score -= 10;
        } else {
            $checks[] = ['check' => 'word_count', 'status' => 'pass', 'message' => "Word count OK ({$wordCount})"];
        }

        // Keyword density (0.5-2.5% is ideal)
        if ($keyword) {
            if ($density < 0.3) {
                $checks[] = ['check' => 'keyword_density', 'status' => 'warn', 'message' => "Keyword density too low ({$density}%)"];
                $score -= 10;
            } elseif ($density > 3.0) {
                $checks[] = ['check' => 'keyword_density', 'status' => 'warn', 'message' => "Keyword density too high ({$density}%)"];
                $score -= 10;
            } else {
                $checks[] = ['check' => 'keyword_density', 'status' => 'pass', 'message' => "Keyword density OK ({$density}%)"];
            }
        }

        // H2 headings presence
        $h2Count = preg_match_all('/<h[23][^>]*>/i', $html);
        if ($h2Count === 0) {
            $checks[] = ['check' => 'headings', 'status' => 'warn', 'message' => 'No H2/H3 headings found'];
            $score -= 10;
        } else {
            $checks[] = ['check' => 'headings', 'status' => 'pass', 'message' => "{$h2Count} heading(s) found"];
        }

        // Internal links (check for <a href>)
        $linkCount = preg_match_all('/<a\s+[^>]*href/i', $html);
        if ($linkCount === 0) {
            $checks[] = ['check' => 'links', 'status' => 'warn', 'message' => 'No links in content'];
            $score -= 5;
        } else {
            $checks[] = ['check' => 'links', 'status' => 'pass', 'message' => "{$linkCount} link(s) found"];
        }

        return [
            'score' => max(0, min(100, $score)),
            'word_count' => $wordCount,
            'keyword_density' => $density,
            'checks' => $checks,
        ];
    }

    public function callProvider(string $provider, string $model, string $system, string $userMessage, int $maxTokens): ?string
    {
        return match ($provider) {
            'openai' => $this->callOpenAi($model, $system, $userMessage, $maxTokens),
            default => $this->callClaude($model, $system, $userMessage, $maxTokens),
        };
    }

    private function getDefaultModel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'gpt-4o-mini',
            default => 'claude-sonnet-4-20250514',
        };
    }

    private static function getApiKey(string $provider): ?string
    {
        $settings = app(SettingsService::class);
        $settingKey = match ($provider) {
            'openai' => 'ai_openai_api_key',
            default => 'ai_anthropic_api_key',
        };

        $encrypted = $settings->get($settingKey);
        if (! $encrypted) {
            // Fallback: for anthropic, also check incident-response config
            if ($provider === 'anthropic') {
                return config('incident-response.ai.api_key');
            }

            return null;
        }

        try {
            return decrypt($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private function buildSystemPrompt(SeoContent $content): string
    {
        $tone = $content->tone ?? 'professional';
        $persona = match ($content->persona ?? 'noi') {
            'eu' => 'persoana I singular (eu/am/meu)',
            'neutru' => 'impersonal, neutru (se recomandă, este important)',
            default => 'persoana I plural (noi/am/nostru)',
        };

        return <<<PROMPT
        Ești un copywriter SEO expert care scrie **în limba română**, nativ și natural.
        Produci conturi de calitate superioară, bine documentate, optimizate pentru motoarele de căutare dar plăcute și captivante pentru cititori reali.

        ## Reguli de scriere

        **Limbă și ton:**
        - Scrie EXCLUSIV în limba română cu diacritice corecte (ă, â, î, ș, ț)
        - Tonul: {$tone}
        - Perspectiva narativă: {$persona}
        - Scrie natural, ca un expert român care explică — NU ca un text tradus din engleză
        - Evită formulările robotice, clișeele goale și umpluturile de cuvinte
        - Fiecare propoziție trebuie să aducă valoare reală cititorului

        **Structura articolului:**
        - Începe cu o introducere captivantă (2-3 paragrafe) care prezintă problema și de ce contează
        - Folosește **<h2>** pentru secțiunile principale (4-7 secțiuni)
        - Folosește **<h3>** pentru subsecțiuni unde e nevoie
        - Paragrafe scurte (2-4 propoziții maxim) — texte scanabile
        - Include **liste** (<ul> sau <ol>) pentru enumerări, pași sau beneficii
        - Adaugă **<blockquote>** pentru citate, statistici sau idei cheie
        - Încheie cu o **concluzie** care rezumă ideile principale și include un call-to-action

        **SEO on-page:**
        - Include keyword-ul principal natural în: primul paragraf, cel puțin 2 heading-uri H2, concluzie
        - Keyword density: 1-2% (nu forța keyword-ul — mai bine variații naturale)
        - Folosește <strong> pentru termenii importanți și keyword-uri (nu exagera, max 5-8 pe articol)
        - Include keyword-urile secundare distribuite natural prin text

        **Format output:**
        - Output DOAR HTML valid pentru body (fără <html>, <body>, <head>, <article>)
        - Începe direct cu primul paragraf sau heading
        - La final, pe linii separate, adaugă:
          ---TITLE---
          Titlul articolului (captivant, include keyword-ul, max 70 caractere)
          ---META---
          Meta description (convingătoare, include keyword-ul, 120-155 caractere)
        PROMPT;
    }

    private function buildUserMessage(SeoContent $content): string
    {
        $keyword = $content->target_keyword ?? 'general topic';
        $secondary = implode(', ', $content->secondary_keywords ?? []);
        $wordCount = $content->target_word_count ?? 1000;
        $audience = $content->target_audience ?? 'general audience';
        $brief = $content->brief ?? '';

        // Include site brand context if available
        $siteContext = $content->site?->ai_context;
        if ($siteContext) {
            $message = "## Context despre brand/companie\n{$siteContext}\n\n";
        } else {
            $message = '';
        }

        $message .= "Scrie un articol SEO-optimizat despre: **{$keyword}**\n\n";
        $message .= "Lungime țintă: aproximativ {$wordCount} cuvinte.\n";

        if ($secondary) {
            $message .= "Keyword-uri secundare de inclus: {$secondary}\n";
        }

        if ($audience) {
            $message .= "Public țintă: {$audience}\n";
        }

        if ($brief) {
            $message .= "\n## Context și instrucțiuni suplimentare\n{$brief}\n";
        }

        if (! empty($content->sections)) {
            $message .= "\nSecțiuni/heading-uri dorite:\n";
            foreach ($content->sections as $section) {
                $message .= "- {$section}\n";
            }
        }

        return $message;
    }

    private function getMaxTokens(SeoContent $content): int
    {
        $targetWords = $content->target_word_count ?? 1000;

        // Rough estimate: 1 word ≈ 1.5 tokens, plus headroom
        return min(16384, (int) ($targetWords * 2.5));
    }

    private function callClaude(string $model, string $system, string $userMessage, int $maxTokens = 8192): ?string
    {
        $apiKey = self::getApiKey('anthropic');
        if (! $apiKey) {
            Log::warning('SeoContentAi: No Anthropic API key configured');

            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(300)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0.7,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['content'][0]['text'] ?? null;
            }

            Log::warning('SeoContentAi: Claude API error', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error("SeoContentAi: Claude API exception: {$e->getMessage()}");

            return null;
        }
    }

    private function callOpenAi(string $model, string $system, string $userMessage, int $maxTokens = 8192): ?string
    {
        $apiKey = self::getApiKey('openai');
        if (! $apiKey) {
            Log::warning('SeoContentAi: No OpenAI API key configured');

            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(300)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => 0.7,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['choices'][0]['message']['content'] ?? null;
            }

            Log::warning('SeoContentAi: OpenAI API error', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error("SeoContentAi: OpenAI API exception: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return array{content: string, title: string|null, meta_description: string|null}
     */
    private function parseAiResponse(string $raw): array
    {
        $title = null;
        $meta = null;

        // Extract title
        $parts = preg_split('/---\s*TITLE\s*---/i', $raw, 2);
        $content = trim($parts[0] ?? $raw);

        if (isset($parts[1])) {
            // Title is between ---TITLE--- and ---META---
            $remainder = $parts[1];
            $metaParts = preg_split('/---\s*META\s*---/i', $remainder, 2);
            $title = trim(strip_tags($metaParts[0]));
            $meta = isset($metaParts[1]) ? trim(strip_tags($metaParts[1])) : null;
        } else {
            // No ---TITLE---, try just ---META---
            $metaParts = preg_split('/---\s*META\s*---/i', $content, 2);
            $content = trim($metaParts[0]);
            $meta = isset($metaParts[1]) ? trim(strip_tags($metaParts[1])) : null;
        }

        // Limit meta to 160 chars
        if ($meta && mb_strlen($meta) > 160) {
            $meta = mb_substr($meta, 0, 157).'...';
        }

        // Limit title to 80 chars
        if ($title && mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 80);
        }

        return [
            'content' => $content,
            'title' => $title,
            'meta_description' => $meta,
        ];
    }
}

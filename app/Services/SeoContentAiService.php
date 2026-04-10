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

        $content->update([
            'content' => $parsed['content'],
            'meta_description' => $parsed['meta_description'] ?: $content->meta_description,
            'status' => SeoContentStatus::Review,
            'word_count' => $seoData['word_count'],
            'keyword_density' => $seoData['keyword_density'],
            'seo_score' => $seoData['score'],
            'seo_score_data' => $seoData,
        ]);

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

    private function callProvider(string $provider, string $model, string $system, string $userMessage, int $maxTokens): ?string
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
        $language = 'Romanian';
        $tone = $content->tone ?? 'professional';
        $persona = $content->persona ?? 'noi';

        return <<<PROMPT
        You are an expert SEO copywriter writing in {$language}. You produce high-quality content
        optimized for search engines while remaining natural and engaging for human readers.

        Rules:
        - Write in {$language}
        - Tone: {$tone}
        - Narrative perspective: {$persona}
        - Use the target keyword naturally throughout the text
        - Include secondary keywords where they fit naturally
        - Structure with H2 and H3 headings using HTML tags
        - Use bold (<strong>) for important terms
        - Use bullet or numbered lists where appropriate
        - Include a compelling introduction and conclusion
        - Output valid HTML (no <html>, <body> or <head> tags — just the article body)
        - At the very end, after a line "---META---", output the meta description (max 160 chars)
        PROMPT;
    }

    private function buildUserMessage(SeoContent $content): string
    {
        $keyword = $content->target_keyword ?? 'general topic';
        $secondary = implode(', ', $content->secondary_keywords ?? []);
        $wordCount = $content->target_word_count ?? 1000;
        $audience = $content->target_audience ?? 'general audience';
        $brief = $content->brief ?? '';

        $message = "Write an SEO-optimized article about: **{$keyword}**\n\n";
        $message .= "Target length: approximately {$wordCount} words.\n";

        if ($secondary) {
            $message .= "Secondary keywords to include: {$secondary}\n";
        }

        if ($audience) {
            $message .= "Target audience: {$audience}\n";
        }

        if ($brief) {
            $message .= "\nAdditional instructions:\n{$brief}\n";
        }

        if (! empty($content->sections)) {
            $message .= "\nDesired sections/headings:\n";
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
     * @return array{content: string, meta_description: string|null}
     */
    private function parseAiResponse(string $raw): array
    {
        // Split on ---META--- marker
        $parts = preg_split('/---\s*META\s*---/i', $raw, 2);

        $content = trim($parts[0] ?? $raw);
        $meta = isset($parts[1]) ? trim(strip_tags($parts[1])) : null;

        // Limit meta to 160 chars
        if ($meta && mb_strlen($meta) > 160) {
            $meta = mb_substr($meta, 0, 157).'...';
        }

        return [
            'content' => $content,
            'meta_description' => $meta,
        ];
    }
}

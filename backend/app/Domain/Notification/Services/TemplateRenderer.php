<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Shared\Enums\NotificationChannel;

/**
 * Renders `{{placeholder}}` templates.
 *
 * Two deliberate restrictions:
 *
 *  - Only placeholders listed in the template's `available_variables` are
 *    substituted. A template edited by a school administrator therefore cannot
 *    be turned into a way to interpolate arbitrary data it was never meant to
 *    see.
 *  - Substitution is a plain string replace with no expression evaluation.
 *    Rendering user-editable templates through Blade would be remote code
 *    execution by design.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: string|null, body: string}
     */
    public function render(NotificationTemplate $template, array $variables): array
    {
        $allowed = $template->available_variables ?? array_keys($variables);

        $replacements = [];

        foreach ($allowed as $key) {
            $replacements['{{'.$key.'}}'] = (string) ($variables[$key] ?? '');
            // Tolerate the spaced form a human is likely to type.
            $replacements['{{ '.$key.' }}'] = (string) ($variables[$key] ?? '');
        }

        return [
            'subject' => $template->subject === null ? null : strtr($template->subject, $replacements),
            'body' => strtr($template->body, $replacements),
        ];
    }

    /**
     * The template a school should use, falling back to the platform default
     * and then to the fallback locale.
     */
    public function resolve(
        ?string $schoolId,
        string $key,
        NotificationChannel $channel,
        string $locale,
    ): ?NotificationTemplate {
        $template = NotificationTemplate::query()
            ->resolveFor($schoolId, $key, $channel, $locale)
            ->first();

        if ($template !== null) {
            return $template;
        }

        $fallback = (string) config('app.fallback_locale', 'en');

        if ($fallback === $locale) {
            return null;
        }

        return NotificationTemplate::query()
            ->resolveFor($schoolId, $key, $channel, $fallback)
            ->first();
    }

    /**
     * Placeholders present in a body that the template does not declare.
     * Surfaced to the settings UI so a typo is caught at edit time rather
     * than showing up as a literal `{{studnet_name}}` in a parent's inbox.
     *
     * @return list<string>
     */
    public function undeclaredPlaceholders(NotificationTemplate $template): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $template->subject.' '.$template->body, $matches);

        $used = array_unique($matches[1]);
        $declared = $template->available_variables ?? [];

        return array_values(array_diff($used, $declared));
    }
}

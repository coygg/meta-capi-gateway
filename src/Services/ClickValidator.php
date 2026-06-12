<?php

declare(strict_types=1);

namespace Gateway\Services;

final class ClickValidator
{
    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $query
     * @return array{valid: bool, reason: string|null}
     */
    public function validate(array $campaign, array $query): array
    {
        $required = $campaign['required_params'] ?? [];

        if (!is_array($required)) {
            return ['valid' => false, 'reason' => 'bad_required_param_config'];
        }

        foreach ($required as $param) {
            if (!is_string($param)) {
                continue;
            }

            $value = trim((string) ($query[$param] ?? ''));

            if ($value === '') {
                return ['valid' => false, 'reason' => 'missing_' . $param];
            }

            if ($this->looksLikeUnexpandedMacro($value)) {
                return ['valid' => false, 'reason' => 'unexpanded_' . $param];
            }
        }

        $sources = $campaign['accepted_utm_sources'] ?? [];
        $utmSource = strtolower(trim((string) ($query['utm_source'] ?? '')));

        if (is_array($sources) && $utmSource !== '') {
            $normalizedSources = array_map(static fn (mixed $source): string => strtolower((string) $source), $sources);

            if (!in_array($utmSource, $normalizedSources, true)) {
                return ['valid' => false, 'reason' => 'unexpected_utm_source'];
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    private function looksLikeUnexpandedMacro(string $value): bool
    {
        if (str_contains($value, '{{') || str_contains($value, '}}')) {
            return true;
        }

        return preg_match('/^\{[A-Za-z0-9_.-]+\}$/', $value) === 1;
    }
}

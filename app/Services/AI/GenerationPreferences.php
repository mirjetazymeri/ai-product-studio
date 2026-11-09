<?php

namespace App\Services\AI;

use App\Enums\ContentLength;
use App\Enums\ContentTone;
use App\Enums\TargetAudience;
use App\Enums\WritingStyle;
use Illuminate\Validation\Rule;

final readonly class GenerationPreferences
{
    public function __construct(
        public ContentTone $tone = ContentTone::Professional,
        public TargetAudience $audience = TargetAudience::GeneralConsumers,
        public ContentLength $length = ContentLength::Standard,
        public WritingStyle $style = WritingStyle::SeoOptimized,
        public ?string $customAudience = null,
        public ?string $customInstructions = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'tone' => ['nullable', Rule::enum(ContentTone::class)],
            'audience' => ['nullable', Rule::enum(TargetAudience::class)],
            'custom_audience' => ['nullable', 'required_if:audience,custom', 'string', 'max:80'],
            'length' => ['nullable', Rule::enum(ContentLength::class)],
            'style' => ['nullable', Rule::enum(WritingStyle::class)],
            'custom_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated  input that passed rules()
     */
    public static function fromValidated(array $validated): self
    {
        $audience = TargetAudience::tryFrom((string) ($validated['audience'] ?? '')) ?? TargetAudience::GeneralConsumers;

        return new self(
            tone: ContentTone::tryFrom((string) ($validated['tone'] ?? '')) ?? ContentTone::Professional,
            audience: $audience,
            length: ContentLength::tryFrom((string) ($validated['length'] ?? '')) ?? ContentLength::Standard,
            style: WritingStyle::tryFrom((string) ($validated['style'] ?? '')) ?? WritingStyle::SeoOptimized,
            customAudience: $audience === TargetAudience::Custom ? ($validated['custom_audience'] ?? null) : null,
            customInstructions: $validated['custom_instructions'] ?? null,
        );
    }

    /**
     * Rebuild from a stored history record, falling back to defaults for anything unrecognized.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromHistory(?array $stored, ?string $customInstructions = null): self
    {
        return new self(
            tone: ContentTone::tryFrom((string) ($stored['tone'] ?? '')) ?? ContentTone::Professional,
            audience: TargetAudience::tryFrom((string) ($stored['audience'] ?? '')) ?? TargetAudience::GeneralConsumers,
            length: ContentLength::tryFrom((string) ($stored['length'] ?? '')) ?? ContentLength::Standard,
            style: WritingStyle::tryFrom((string) ($stored['style'] ?? '')) ?? WritingStyle::SeoOptimized,
            customAudience: $stored['custom_audience'] ?? null,
            customInstructions: $customInstructions,
        );
    }

    /**
     * Custom instructions are free text and aren't needed later, so only their use is recorded.
     *
     * @return array<string, mixed>
     */
    public function toHistory(): array
    {
        return [
            'tone' => $this->tone->value,
            'audience' => $this->audience->value,
            'custom_audience' => $this->customAudience,
            'length' => $this->length->value,
            'style' => $this->style->value,
            'has_custom_instructions' => filled($this->customInstructions),
        ];
    }

    public function summary(): string
    {
        return implode(' · ', [
            $this->tone->label(),
            $this->audienceLabel(),
            $this->length->label(),
            $this->style->label(),
        ]);
    }

    private function audienceLabel(): string
    {
        return $this->audience === TargetAudience::Custom
            ? (string) $this->customAudience
            : $this->audience->label();
    }
}

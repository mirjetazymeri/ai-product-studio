<?php

namespace App\Services\AI;

use App\Enums\AiError;
use App\Enums\BulkItemStatus;
use App\Models\BulkGenerationItem;
use App\Services\AI\Exceptions\AiGenerationException;

/**
 * Approval goes through ProductContentGenerator::save, so bulk review has exactly the
 * same overwrite protection as the single-product preview. It never publishes a product.
 */
class ContentApprovalService
{
    public function __construct(private readonly ProductContentGenerator $generator) {}

    /**
     * @param  array<string, mixed>|null  $fields  reviewed fields, or null to use what's stored
     *
     * @throws AiGenerationException
     */
    public function approve(BulkGenerationItem $item, ?array $fields, bool $confirmOverwrite): void
    {
        if ($item->review_status === BulkGenerationItem::REVIEW_APPROVED) {
            return;
        }

        if ($item->status !== BulkItemStatus::Generated || $item->generation === null) {
            throw new AiGenerationException(AiError::NotSavable);
        }

        $content = $fields ?? $item->reviewableContent();

        $this->generator->save($item->generation, $content, $confirmOverwrite);

        $item->update([
            'edited_content' => $content,
            'review_status' => BulkGenerationItem::REVIEW_APPROVED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * @param  iterable<BulkGenerationItem>  $items
     * @return array{approved: int, conflicts: int, failed: int}
     */
    public function approveMany(iterable $items, bool $confirmOverwrite): array
    {
        $result = ['approved' => 0, 'conflicts' => 0, 'failed' => 0];

        foreach ($items as $item) {
            try {
                $this->approve($item, null, $confirmOverwrite);
                $result['approved']++;
            } catch (AiGenerationException $exception) {
                $result[$exception->error === AiError::ContentExists ? 'conflicts' : 'failed']++;
            }
        }

        return $result;
    }
}

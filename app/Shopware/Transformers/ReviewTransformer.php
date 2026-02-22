<?php

namespace App\Shopware\Transformers;

class ReviewTransformer
{
    public function transform(object $review, int $wooProductId): array
    {
        $authorName = trim(($review->author_first_name ?? '') . ' ' . ($review->author_last_name ?? ''));

        return [
            'product_id' => $wooProductId,
            'reviewer' => $authorName ?: 'Anonymous',
            'reviewer_email' => $review->author_email ?? '',
            'review' => $review->comment ?? '',
            'rating' => max(1, min(5, (int) ($review->rating ?? 5))),
            'status' => ($review->active ?? false) ? 'approved' : 'hold',
            'date_created' => $review->created_at ?? null,
        ];
    }
}

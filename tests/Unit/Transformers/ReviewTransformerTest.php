<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\ReviewTransformer;
use PHPUnit\Framework\TestCase;

class ReviewTransformerTest extends TestCase
{
    private ReviewTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ReviewTransformer;
    }

    public function test_transforms_review(): void
    {
        $review = (object) [
            'author_first_name' => 'Jan',
            'author_last_name' => 'Kowalski',
            'author_email' => 'jan@example.pl',
            'comment' => 'Świetny produkt!',
            'rating' => 5,
            'active' => true,
            'created_at' => '2025-06-15 10:30:00',
        ];

        $result = $this->transformer->transform($review, 42);

        $this->assertEquals(42, $result['product_id']);
        $this->assertEquals('Jan Kowalski', $result['reviewer']);
        $this->assertEquals('jan@example.pl', $result['reviewer_email']);
        $this->assertEquals('Świetny produkt!', $result['review']);
        $this->assertEquals(5, $result['rating']);
        $this->assertEquals('approved', $result['status']);
        $this->assertEquals('2025-06-15 10:30:00', $result['date_created']);
    }

    public function test_inactive_review_is_on_hold(): void
    {
        $review = (object) [
            'author_first_name' => 'Anna',
            'author_last_name' => '',
            'author_email' => 'anna@example.pl',
            'comment' => 'Średni',
            'rating' => 2,
            'active' => false,
            'created_at' => null,
        ];

        $result = $this->transformer->transform($review, 10);

        $this->assertEquals('hold', $result['status']);
    }

    public function test_handles_missing_author_name(): void
    {
        $review = (object) [
            'author_first_name' => '',
            'author_last_name' => '',
            'author_email' => 'anon@example.pl',
            'comment' => 'OK',
            'rating' => 3,
            'active' => true,
            'created_at' => null,
        ];

        $result = $this->transformer->transform($review, 10);

        $this->assertEquals('Anonymous', $result['reviewer']);
    }

    public function test_clamps_rating_to_1_5(): void
    {
        $reviewLow = (object) [
            'author_first_name' => 'Test',
            'author_last_name' => '',
            'author_email' => 'test@example.pl',
            'comment' => '',
            'rating' => 0,
            'active' => true,
            'created_at' => null,
        ];

        $reviewHigh = (object) [
            'author_first_name' => 'Test',
            'author_last_name' => '',
            'author_email' => 'test@example.pl',
            'comment' => '',
            'rating' => 10,
            'active' => true,
            'created_at' => null,
        ];

        $this->assertEquals(1, $this->transformer->transform($reviewLow, 1)['rating']);
        $this->assertEquals(5, $this->transformer->transform($reviewHigh, 1)['rating']);
    }
}

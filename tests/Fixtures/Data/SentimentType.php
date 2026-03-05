<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

enum SentimentType: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
}

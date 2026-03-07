<?php

declare(strict_types=1);

namespace AiWorkflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class ArrayItemType
{
    public function __construct(public readonly string $type) {}
}

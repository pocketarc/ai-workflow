<?php

declare(strict_types=1);

namespace AiWorkflow\Enums;

enum GuardrailDirection: string
{
    case Input = 'input';
    case Output = 'output';
}

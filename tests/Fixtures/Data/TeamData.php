<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures\Data;

use AiWorkflow\Attributes\ArrayItemType;
use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class TeamData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<int>  $scores
     * @param  list<PersonData>  $members
     */
    public function __construct(
        #[Description('Team name')]
        public readonly string $name,
        #[Description('Tag list')]
        public readonly array $tags,
        #[Description('Score list')]
        #[ArrayItemType('int')]
        public readonly array $scores,
        #[Description('Team members')]
        #[ArrayItemType(PersonData::class)]
        public readonly array $members,
    ) {}
}

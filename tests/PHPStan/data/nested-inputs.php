<?php

namespace App\Data\Nested;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

final class TaskData extends Data
{
    public function __construct(
        public string $title,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['title' => ['max:50']];
    }
}

final class NoteData extends Data
{
    public string $body;
}

final class ChecklistData extends Data
{
    /** @param list<TaskData> $tasks */
    public function __construct(
        public array $tasks,
    ) {}
}

final class LabelData extends InputData
{
    public string $name;

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['name' => ['max:20']];
    }
}

final class TreeData extends Data
{
    /** @var list<TreeData> */
    public array $children;
}

final class StoreTaskData extends InputData
{
    public function __construct(
        public TaskData $task,
        public ?NoteData $note,
        public LabelData $label,
        public TreeData $tree,
    ) {}
}

final class StoreTasksData extends InputData
{
    /** @var list<TaskData> */
    public array $tasks;

    /** @var array<string, TaskData>|null */
    public ?array $byKey;

    /** @var DataCollection<int, TaskData> */
    public DataCollection $collection;

    #[DataCollectionOf(TaskData::class)]
    public array $attributed;

    private TaskData $internal;

    public static TaskData $shared;
}

final class StoreChecklistData extends InputData
{
    public function __construct(
        public ChecklistData $checklist,
    ) {}
}

final class TaskFiltersData extends QueryData
{
    public ?TaskData $task = null;
}

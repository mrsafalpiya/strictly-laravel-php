<?php

namespace App\Data;

use Spatie\LaravelData\Data;

final class TeamData extends Data {}

abstract class ListData extends Data {}

final class ProjectFormatter {}

interface HasLabel {}

trait FormatsDates {}

enum Visibility: string
{
    case Public = 'public';
}

namespace App\Data\Issues;

use Spatie\LaravelData\Data;

final class IssueData extends Data {}

final class IssueQuery {}

namespace App\DataExport;

final class Exporter {}

namespace App\Support;

final class Formatter {}

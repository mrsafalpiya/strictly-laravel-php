<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Dto;

final class ProjectData extends Data {}

class IssueData extends Data {}

abstract class SummaryData extends Data {}

class CommentData extends SummaryData {}

class FilterDto extends Dto {}

namespace App\Support;

use Spatie\LaravelData\Data;

class Formatter {}

class ReportData extends Data {}

$anonymous = new class extends Data {};

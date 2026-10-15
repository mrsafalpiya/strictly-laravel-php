<?php

namespace App\Data\RouteModels;

use Attribute;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/** Stands in for an Eloquent model, which implements UrlRoutable. */
final class Comment implements UrlRoutable
{
    public function getRouteKey(): int
    {
        return 1;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}

final class UpdateCommentData extends InputData
{
    public string $body;

    public static function authorize(Request $request, Comment $comment): bool
    {
        return $request->user()?->can('update', $comment) === true;
    }

    /** @return array<string, list<string>> */
    public static function rules(?Comment $comment, ValidationContext $context): array
    {
        return [];
    }
}

final class CommentFiltersData extends QueryData
{
    public ?string $search = null;

    /** @return array<string, list<string>> */
    public static function rules(Comment $comment): array
    {
        return [];
    }
}

final class CommentData extends Data
{
    public string $body;

    public static function authorize(Request $request, ?Comment $comment): bool
    {
        return $request->user() !== null;
    }

    /** @return array<string, list<string>> */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }
}

abstract class BaseCommentData extends Data
{
    /** @return array<string, list<string>> */
    public static function rules(Comment $comment): array
    {
        return [];
    }
}

final class ReplyData extends BaseCommentData
{
    public string $body;
}

/** Stands in for a contextual attribute such as #[CurrentUser], which the container resolves instead of the route. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CurrentComment implements ContextualAttribute {}

final class CommentSearchData extends QueryData
{
    public ?string $search = null;

    /** @return array<string, list<string>> */
    public static function rules(#[CurrentComment] Comment $comment): array
    {
        return [];
    }

    public static function authorize(#[CurrentComment] ?Comment $comment): bool
    {
        return $comment !== null;
    }
}

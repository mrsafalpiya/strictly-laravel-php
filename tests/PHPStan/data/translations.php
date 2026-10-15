<?php

namespace App\Support;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Lang;

final class Messages
{
    /**
     * @param  array<string, string>  $params
     */
    public function keys(string $runtime, bool $archiving, array $params): void
    {
        // A nested key of a PHP lang file, one in a subdirectory, a JSON key and a framework key are defined.
        __('projects.flash.saved', ['key' => 'TL']);
        __('admin/users.title');
        __('Welcome back, :name!', ['name' => 'Ada']);
        __('validation.required', ['attribute' => 'name']);
        __('projects.flash.saevd', ['key' => 'TL']);
        trans('validation.requird');
        // Text that no lang file defines renders itself, as Laravel's translator does.
        __('Profile updated.');
        // A package's namespaced key isn't read.
        __('billing::invoices.title');
        // A union of literal keys checks each one.
        __($archiving ? 'projects.title' : 'projects.titel');
        __($runtime);
        __('projects.flash.saved', $params);
    }

    public function params(): void
    {
        __('projects.flash.saved');
        __('projects.flash.saved', ['key' => 'TL', 'name' => 'Tracklane']);
        __('projects.flash.saved', ['kye' => 'TL']);
        // :Key is filled by the param key, and a closure fills a <b> tag, not a :param.
        __('projects.flash.renamed', ['key' => 'tl', 'name' => 'Tracklane', 'b' => fn (string $text): string => "<b>{$text}</b>"]);
        __('Welcome back, :name!');
        __(key: 'projects.flash.saved', replace: ['key' => 'TL']);
    }

    public function plurals(Translator $translator, int $count): void
    {
        // trans_choice() fills :count from the number.
        trans_choice('issues.count', $count, ['project' => 'TL']);
        trans_choice('issues.count', $count);
        __('issues.count', ['count' => $count, 'project' => 'TL']);
        trans_choice('projects.title', $count);
        Lang::get('issues.count', ['count' => $count, 'project' => 'TL']);
        Lang::choice('projects.titel', $count);
        $translator->get('projects.flash.saved', ['key' => 'TL']);
        $translator->choice('projects.title', $count);
    }
}

<?php

namespace App\Support;

/**
 * Breadcrumb trails for the public website's inner pages, built from the
 * current route name alone. Query strings (pagination, search, filters)
 * never add a level. Detail pages pass their record's title as the current
 * item, so a slug is never shown.
 */
class Breadcrumbs
{
    /**
     * Public route name => its label and the route name of its parent.
     * A parent without a route of its own (Membership has no landing page)
     * is listed under GROUPS and shown as plain text.
     *
     * @var array<string, array{label: string, parent?: string}>
     */
    public const PAGES = [
        'about' => ['label' => 'About'],
        'contact' => ['label' => 'Contact'],
        'faq' => ['label' => 'FAQ'],
        'privacy' => ['label' => 'Privacy Policy'],
        'terms' => ['label' => 'Terms & Conditions'],

        'membership.benefits' => ['label' => 'Benefits', 'parent' => 'membership'],
        'membership.apply' => ['label' => 'Become a Member', 'parent' => 'membership'],
        'applications.show' => ['label' => 'Application Status', 'parent' => 'membership'],
        'rules' => ['label' => 'Club Rules', 'parent' => 'membership'],
        'commercial-partners' => ['label' => 'Commercial Partners', 'parent' => 'membership'],

        'jobs' => ['label' => 'Jobs'],
        'jobs.show' => ['label' => 'Job', 'parent' => 'jobs'],
        'education' => ['label' => 'Education'],
        'education.show' => ['label' => 'Article', 'parent' => 'education'],
        'blog.index' => ['label' => 'Blog'],
        'blog.show' => ['label' => 'Article', 'parent' => 'blog.index'],
        'news-events' => ['label' => 'News & Events'],
        'news.index' => ['label' => 'News'],
        'news.show' => ['label' => 'News Item', 'parent' => 'news.index'],
        'events.index' => ['label' => 'Events'],
        'events.show' => ['label' => 'Event', 'parent' => 'events.index'],
        'csr.index' => ['label' => 'CSR'],
        'csr.show' => ['label' => 'Project', 'parent' => 'csr.index'],
        'eshop.index' => ['label' => 'E-Shop'],
        'eshop.show' => ['label' => 'Product', 'parent' => 'eshop.index'],
    ];

    /**
     * Parents that group pages in the navigation but have no page of their own.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'membership' => 'Membership',
    ];

    /**
     * The trail for a route, starting at Home. Only the ancestors carry a URL;
     * the last item is the current page. Empty for the homepage and for any
     * route without a breadcrumb entry.
     *
     * @return list<array{label: string, url: string|null}>
     */
    public static function trail(?string $routeName, ?string $currentTitle = null): array
    {
        if ($routeName === null || ! isset(self::PAGES[$routeName])) {
            return [];
        }

        $items = [['label' => filled($currentTitle) ? $currentTitle : self::PAGES[$routeName]['label'], 'url' => null]];

        $parent = self::PAGES[$routeName]['parent'] ?? null;

        while ($parent !== null) {
            if (isset(self::GROUPS[$parent])) {
                array_unshift($items, ['label' => self::GROUPS[$parent], 'url' => null]);

                break;
            }

            array_unshift($items, ['label' => self::PAGES[$parent]['label'], 'url' => route($parent)]);
            $parent = self::PAGES[$parent]['parent'] ?? null;
        }

        array_unshift($items, ['label' => 'Home', 'url' => route('home')]);

        return $items;
    }
}

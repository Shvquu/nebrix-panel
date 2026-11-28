<?php

declare(strict_types=1);

/*
 * Settings of the marketing area (Admin → Configuration → Marketing):
 * search engines and social networks and the blog. Format as in
 * config/settings.php.
 */

return [
    // ------------------------------------------------------------ SEO & sharing
    'seo.og_image' => ['group' => 'seo_files', 'type' => 'string', 'default' => '', 'label' => 'Preview image for social networks'],
    'seo.home_description' => ['group' => 'seo', 'type' => 'text', 'default' => '', 'label' => 'Meta description of the start page (empty = default text)', 'rules' => 'nullable|string|max:320', 'help' => 'Shown by search engines below the title; about 150 characters.'],
    'seo.same_as' => ['group' => 'seo', 'type' => 'text', 'default' => '', 'label' => 'Profiles of your company (one https:// address per line)', 'rules' => 'nullable|string|max:2000|regex:#^(https://[^\s<>"]{3,300}\s*)*$#', 'help' => 'E.g. your pages on LinkedIn, X or Trustpilot; included in the structured data of the start page.'],

    // ------------------------------------------------------------------- blog
    'blog.enabled' => ['group' => 'blog', 'type' => 'bool', 'default' => false, 'label' => 'Show the blog on the website', 'help' => 'Adds /blog, the news feed /blog/feed.xml and a link in the navigation. Posts are written in Marketing → Blog.'],
    'blog.per_page' => ['group' => 'blog', 'type' => 'int', 'default' => 10, 'label' => 'Posts per page', 'rules' => 'required|int|min:3|max:50'],
    'blog.home_posts' => ['group' => 'blog', 'type' => 'int', 'default' => 3, 'label' => 'Latest posts on the start page (0 = none)', 'rules' => 'required|int|min:0|max:6'],
];

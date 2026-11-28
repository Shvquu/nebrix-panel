<?php

declare(strict_types=1);

/*
 * Settings of the marketing area (Admin → Configuration → Marketing):
 * search engines and social networks, blog, newsletter and review
 * invitations. Format as in config/settings.php.
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

    // ------------------------------------------------------------- newsletter
    'newsletter.enabled' => ['group' => 'newsletter', 'type' => 'bool', 'default' => false, 'label' => 'Offer the newsletter', 'help' => 'Sign-up on the website (/newsletter) and in the customer account with double opt-in. Only confirmed addresses receive campaigns.'],
    'newsletter.confirm_hours' => ['group' => 'newsletter', 'type' => 'int', 'default' => 48, 'label' => 'Confirmation link valid for … hours', 'rules' => 'required|int|min:1|max:168'],
    'newsletter.batch_size' => ['group' => 'newsletter', 'type' => 'int', 'default' => 50, 'label' => 'E-mails per batch', 'rules' => 'required|int|min:1|max:1000', 'help' => 'Campaigns are sent in batches through the job queue, so the mail server is not flooded.'],
    'newsletter.batch_interval' => ['group' => 'newsletter', 'type' => 'int', 'default' => 60, 'label' => 'Seconds between two batches', 'rules' => 'required|int|min:10|max:3600'],

    // ---------------------------------------------------- review invitations
    'reviews.enabled' => ['group' => 'reviews', 'type' => 'bool', 'default' => false, 'label' => 'Invite customers to write a review', 'help' => 'Only customers who agreed (checkbox at registration or order, or in their account) receive one e-mail, a few days after their first service became active. Review requests by e-mail are advertising (§ 7 UWG): without consent nothing is sent.'],
    'reviews.delay_days' => ['group' => 'reviews', 'type' => 'int', 'default' => 14, 'label' => 'Send the invitation … days after the first service became active', 'rules' => 'required|int|min:1|max:180'],
    'reviews.review_url' => ['group' => 'reviews', 'type' => 'url', 'default' => '', 'label' => 'Review link (Trustpilot)', 'rules' => 'nullable|max:300|regex:#^https://([a-z]{2,3}\.)?trustpilot\.com/[A-Za-z0-9._~/?=&%+-]*$#', 'help' => 'E.g. https://de.trustpilot.com/evaluate/example.com – without a link no invitations are sent.'],
];

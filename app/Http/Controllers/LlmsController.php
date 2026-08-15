<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves /llms.txt — a machine-readable summary of the site for large language models,
 * following the llms.txt convention (https://llmstxt.org). Markdown, one H1 + a summary
 * blockquote + link sections.
 */
class LlmsController extends Controller
{
    public function __invoke(): Response
    {
        $home = route('home');
        $how = route('marketing.how');
        $pricing = route('marketing.pricing');
        $register = route('register');
        $login = route('login');

        $body = <<<MARKDOWN
        # Worldbuilder

        > Worldbuilder is a worldbuilding and virtual-tabletop platform for tabletop role-playing games. Build a world — its lore, people, locations, timelines, compendium and maps — publish it as a shareable public reader, run campaigns with your players, and play at a live virtual tabletop with AI-assisted session recaps.

        ## About
        - [Home]($home): The Worldbuilder homepage.
        - [How it works]($how): A tour of worldbuilding, publishing and play.
        - [Pricing]($pricing): Plans and quotas (Free, Basic and Pro tiers).

        ## Get started
        - [Sign up]($register): Create an account and your first world.
        - [Log in]($login): Access your dashboard.

        ## Notes
        - Each published world is readable at `/w/{world-slug}`, with its own machine-readable index at `/w/{world-slug}/llms.txt`; owners may also serve a world on their own custom domain.
        - Worlds can be private, unlisted (link-only) or public; only public worlds are listed and indexable.
        MARKDOWN;

        return response($body."\n", 200)
            ->header('Content-Type', 'text/markdown; charset=UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverCampaign;
use App\Models\EmailCampaign;
use App\Models\EmailSubscriber;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MailCampaignsController extends Controller
{
    public function index()
    {
        $campaigns = EmailCampaign::with('template:id,display_name,reference')
            ->withCount('messages')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EmailCampaign $campaign) => [
                'id' => $campaign->id,
                'subject' => $campaign->subject,
                'status' => $campaign->status,
                'recipient_count' => $campaign->recipient_count,
                'messages_count' => $campaign->messages_count,
                'scheduled_at' => $campaign->scheduled_at?->toIso8601ZuluString(),
                'sent_at' => $campaign->sent_at?->toIso8601ZuluString(),
                'created_at' => $campaign->created_at?->toFormattedDateString(),
                'template' => $campaign->template ? [
                    'display_name' => $campaign->template->display_name,
                ] : null,
            ]);

        return Inertia::render('Admin/Mail/Campaigns/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    public function create()
    {
        $templates = EmailTemplate::orderBy('display_name')
            ->get()
            ->map(fn (EmailTemplate $template) => [
                'id' => $template->id,
                'display_name' => $template->display_name,
                'subject' => $template->subject,
                'has_html' => filled($template->html),
            ]);

        return Inertia::render('Admin/Mail/Campaigns/Create', [
            'templates' => $templates,
            'subscriberCount' => EmailSubscriber::where('subscribed', true)->count(),
        ]);
    }

    public function previewAudience()
    {
        return response()->json([
            'count' => EmailSubscriber::where('subscribed', true)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'subject' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'send_now' => ['sometimes', 'boolean'],
        ]);

        $template = EmailTemplate::findOrFail($data['email_template_id']);

        if (blank($template->html)) {
            return back()->withErrors(['email_template_id' => 'This template has no compiled HTML. Please edit and save the template first.']);
        }

        $campaign = DB::transaction(function () use ($data, $template): EmailCampaign {
            $scheduledAt = filled($data['scheduled_at'] ?? '') ? $data['scheduled_at'] : null;
            $sendNow = (bool) ($data['send_now'] ?? false);

            return EmailCampaign::create([
                'email_template_id' => $template->id,
                'subject' => $data['subject'],
                'status' => ($sendNow || $scheduledAt === null) ? 'queued' : 'scheduled',
                'scheduled_at' => $scheduledAt,
            ]);
        });

        if ($campaign->status === 'queued') {
            dispatch(new DeliverCampaign($campaign));
        }

        return redirect()->route('admin.mail.campaigns.show', $campaign);
    }

    public function show(EmailCampaign $emailCampaign)
    {
        $emailCampaign->load('template:id,display_name,reference');

        $messages = $emailCampaign->messages()
            ->orderByDesc('sent_at')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'recipient_email' => $message->recipient_email,
                'status' => $message->status,
                'sent_at' => $message->sent_at?->toIso8601ZuluString(),
                'opened_at' => $message->opened_at?->toIso8601ZuluString(),
                'clicked_at' => $message->clicked_at?->toIso8601ZuluString(),
                'bounced_at' => $message->bounced_at?->toIso8601ZuluString(),
            ]);

        $total = $messages->count();
        $sent = $messages->where('status', 'sent')->count();
        $opened = $messages->whereNotNull('opened_at')->count();
        $clicked = $messages->whereNotNull('clicked_at')->count();
        $bounced = $messages->whereNotNull('bounced_at')->count();

        return Inertia::render('Admin/Mail/Campaigns/Show', [
            'campaign' => [
                'id' => $emailCampaign->id,
                'subject' => $emailCampaign->subject,
                'status' => $emailCampaign->status,
                'recipient_count' => $emailCampaign->recipient_count,
                'scheduled_at' => $emailCampaign->scheduled_at?->toIso8601ZuluString(),
                'sent_at' => $emailCampaign->sent_at?->toIso8601ZuluString(),
                'created_at' => $emailCampaign->created_at?->toFormattedDateString(),
                'template' => $emailCampaign->template ? [
                    'id' => $emailCampaign->template->id,
                    'display_name' => $emailCampaign->template->display_name,
                ] : null,
            ],
            'stats' => [
                'total' => $total,
                'sent' => $sent,
                'opened' => $opened,
                'clicked' => $clicked,
                'bounced' => $bounced,
            ],
            'messages' => $messages,
        ]);
    }
}

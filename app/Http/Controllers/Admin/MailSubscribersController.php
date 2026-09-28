<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailSubscriber;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MailSubscribersController extends Controller
{
    public function index()
    {
        $subscribers = EmailSubscriber::orderByDesc('created_at')
            ->get()
            ->map(fn (EmailSubscriber $subscriber) => [
                'id' => $subscriber->id,
                'email' => $subscriber->email,
                'name' => $subscriber->name,
                'subscribed' => $subscriber->subscribed,
                'unsubscribed_at' => $subscriber->unsubscribed_at?->toFormattedDateString(),
                'created_at' => $subscriber->created_at?->toFormattedDateString(),
            ]);

        return Inertia::render('Admin/Mail/Subscribers/Index', [
            'subscribers' => $subscribers,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:email_subscribers,email'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        EmailSubscriber::create([
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'subscribed' => true,
        ]);

        return back()->with('success', 'Subscriber added.');
    }

    public function destroy(EmailSubscriber $emailSubscriber)
    {
        $emailSubscriber->delete();

        return back()->with('success', 'Subscriber removed.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatedEmail;
use App\Models\EmailTemplate;
use App\Services\Mail\EmailSender;
use App\Services\AnthropicClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MailTemplatesController extends Controller
{
    public function index()
    {
        $templates = EmailTemplate::orderBy('display_name')->get()
            ->map(fn (EmailTemplate $template) => [
                'id' => $template->id,
                'reference' => $template->reference,
                'display_name' => $template->display_name,
                'subject' => $template->subject,
                'has_html' => filled($template->html),
                'updated_at' => $template->updated_at?->toFormattedDateString(),
            ]);

        return Inertia::render('Admin/Mail/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
        ]);

        $template = EmailTemplate::create([
            'display_name' => $data['display_name'],
            'subject' => $data['display_name'],
            'mjml' => $this->starterMjml($data['display_name']),
        ]);

        return redirect()->route('admin.mail.templates.edit', $template);
    }

    public function edit(EmailTemplate $emailTemplate)
    {
        return Inertia::render('Admin/Mail/Templates/Edit', [
            'template' => [
                'id' => $emailTemplate->id,
                'reference' => $emailTemplate->reference,
                'display_name' => $emailTemplate->display_name,
                'subject' => $emailTemplate->subject,
                'description' => $emailTemplate->description,
                'mjml' => $emailTemplate->mjml,
                'html' => $emailTemplate->html,
                'from_name' => $emailTemplate->from_name,
                'from_mailbox' => $emailTemplate->from_mailbox,
                'reply_to_name' => $emailTemplate->reply_to_name,
                'reply_to_mailbox' => $emailTemplate->reply_to_mailbox,
            ],
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'mjml' => ['nullable', 'string'],
            'html' => ['nullable', 'string'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'from_mailbox' => ['nullable', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:120'],
            'reply_to_mailbox' => ['nullable', 'email', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $reference = Str::slug(filled($data['reference'] ?? '') ? $data['reference'] : $data['display_name']) ?: $emailTemplate->reference;

        $referenceConflicts = EmailTemplate::where('reference', $reference)
            ->where('id', '!=', $emailTemplate->id)
            ->exists();

        if ($referenceConflicts) {
            return back()->withErrors(['reference' => 'That reference slug is already taken.']);
        }

        $emailTemplate->update(array_merge($data, ['reference' => $reference]));

        return back()->with('success', 'Template saved.');
    }

    public function destroy(EmailTemplate $emailTemplate)
    {
        $emailTemplate->delete();

        return redirect()->route('admin.mail.templates.index');
    }

    public function sendTest(Request $request, EmailTemplate $emailTemplate, EmailSender $sender)
    {
        $data = $request->validate([
            'address' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html' => ['nullable', 'string'],
        ]);

        $subject = filled($data['subject'] ?? '') ? $data['subject'] : (string) $emailTemplate->subject;
        $html = filled($data['html'] ?? '') ? $data['html'] : (string) $emailTemplate->html;
        $variables = $sender->sampleVariables();

        $subject = $sender->substitute($subject, $variables, escape: false);
        $html = $sender->substitute($html, $variables, escape: true);

        $fromAddress = filled($emailTemplate->from_mailbox) ? (string) $emailTemplate->from_mailbox : (string) config('mail.from.address');
        $fromName = filled($emailTemplate->from_name) ? (string) $emailTemplate->from_name : (string) config('mail.from.name');

        Mail::to($data['address'])->send(new TemplatedEmail(
            subjectLine: $subject,
            body: $html,
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyToAddress: filled($emailTemplate->reply_to_mailbox) ? (string) $emailTemplate->reply_to_mailbox : null,
            replyToName: filled($emailTemplate->reply_to_name) ? (string) $emailTemplate->reply_to_name : null,
        ));

        return back()->with('success', "Test email sent to {$data['address']}.");
    }

    public function generate(Request $request, EmailTemplate $emailTemplate, AnthropicClient $ai)
    {
        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't configured on this server."], 422);
        }

        $data = $request->validate([
            'brief' => ['required', 'string', 'max:2000'],
        ]);

        $systemPrompt = <<<'PROMPT'
You are an expert email designer specialising in MJML (the responsive email framework).
Generate a complete, production-ready MJML email template and a subject line from the brief given.

Rules:
- Return ONLY valid JSON with two fields: "mjml" (string) and "subject" (string).
- The MJML must be a complete document starting with <mjml> and ending with </mjml>.
- Use mj-section, mj-column, mj-text, mj-button, mj-image as appropriate.
- Include an unsubscribe link at the bottom using {{ unsubscribe_url }} merge variable.
- Use {{ name }} for personalisation where appropriate.
- Keep the design clean, mobile-responsive, and professional.
- Do not include any explanation — only the JSON.
PROMPT;

        $response = $ai->message($systemPrompt, $data['brief'], maxTokens: 4000);

        $decoded = json_decode($response, true);

        if (! is_array($decoded) || ! isset($decoded['mjml'], $decoded['subject'])) {
            return response()->json(['message' => 'AI returned an unexpected response. Please try again.'], 422);
        }

        return response()->json([
            'mjml' => $decoded['mjml'],
            'subject' => $decoded['subject'],
        ]);
    }

    private function starterMjml(string $name): string
    {
        $escaped = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<MJML
<mjml>
  <mj-body>
    <mj-section background-color="#ffffff" padding="20px 0">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#111827" padding="0 30px 16px">
          {$escaped}
        </mj-text>
        <mj-text font-size="16px" color="#374151" line-height="1.6" padding="0 30px 24px">
          Hi {{ name }},
        </mj-text>
        <mj-text font-size="16px" color="#374151" line-height="1.6" padding="0 30px 24px">
          Your message here.
        </mj-text>
        <mj-divider border-color="#e5e7eb" padding="0 30px" />
        <mj-text font-size="12px" color="#9ca3af" padding="16px 30px 0" align="center">
          <a href="{{ unsubscribe_url }}" style="color:#9ca3af">Unsubscribe</a>
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
MJML;
    }
}

<x-mail::message>
# A world awaits

You've been invited to join **{{ $campaignName }}** on Worldbuilder.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

Or paste this link into your browser:
{{ $acceptUrl }}

Thanks,<br>
Worldbuilder
</x-mail::message>

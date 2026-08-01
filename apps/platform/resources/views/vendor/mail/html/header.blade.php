@props(['url'])
{{-- Always show the Qompose mark + app name (slot). PNG is more reliable in email clients than SVG. --}}
<tr>
    <td class="header">
        <a href="{{ $url }}" style="display: inline-block;">
            <img src="{{ asset('images/brand/mark.png') }}" class="logo" alt="{{ config('app.name') }}">
            <span class="brand-name">{{ $slot }}</span>
        </a>
    </td>
</tr>